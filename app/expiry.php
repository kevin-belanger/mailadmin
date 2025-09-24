<?php

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!defined('SQLITE_PATH') || !SQLITE_PATH) {
        throw new RuntimeException('SQLITE_PATH non défini dans config.php');
    }

    // Crée le dossier si besoin + protège via .htaccess
    $dir = dirname(SQLITE_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n");
    }

    $pdo = new PDO('sqlite:' . SQLITE_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("PRAGMA journal_mode = WAL");
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA busy_timeout = 5000");
    $pdo->exec("PRAGMA synchronous = NORMAL");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mailbox_expiry (
          email TEXT PRIMARY KEY,
          expires_at TEXT,  -- ISO8601 UTC ou NULL (jamais)
          note TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_mailbox_expiry_expires ON mailbox_expiry(expires_at);
    ");

    // Ajout rétrocompatible de la colonne note si elle n'existe pas encore.
    $cols = $pdo->query('PRAGMA table_info(mailbox_expiry)')->fetchAll(PDO::FETCH_ASSOC);
    $hasNote = false;
    foreach ($cols as $col) {
        if (($col['name'] ?? '') === 'note') {
            $hasNote = true;
            break;
        }
    }
    if (!$hasNote) {
        $pdo->exec('ALTER TABLE mailbox_expiry ADD COLUMN note TEXT');
    }
    return $pdo;
}

/** Calcule une date d’expiration ISO8601 UTC à partir d’un nombre de jours. NULL = jamais. */
function compute_expiry_iso(?int $days): ?string {
    if ($days === null) return null;
    if ($days < 0) return null;
    $dt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$days} days");
    return $dt->format('c');
}

/** Insère/MAJ l’expiration d’une boîte. $days=null => jamais. */
function upsert_mailbox_expiry(string $email, ?int $days): void {
    $pdo = db();
    $expires = compute_expiry_iso($days);
    $stmt = $pdo->prepare("
        INSERT INTO mailbox_expiry (email, expires_at)
        VALUES (:email, :expires_at)
        ON CONFLICT(email) DO UPDATE SET expires_at = excluded.expires_at
    ");
    $stmt->execute([':email'=>$email, ':expires_at'=>$expires]);
}

/** Synchronise la table avec cPanel : ajoute manquantes (jamais) et supprime orphelines. */
function sync_mailbox_expiry_with_cpanel(): array {
    require_once __DIR__ . '/cpanel.php';

    $cpEmails = array_values(array_filter(cpanel_list_all_emails(), fn($e)=>strpos($e,'@')!==false));
    $cpLowerToOrig = [];
    foreach ($cpEmails as $e) $cpLowerToOrig[strtolower($e)] = $e;

    $pdo = db();
    $dbRows = $pdo->query("SELECT email FROM mailbox_expiry")->fetchAll(PDO::FETCH_COLUMN);
    $dbCount = count($dbRows);
    $dbLowerToOrig = [];
    foreach ($dbRows as $e) $dbLowerToOrig[strtolower((string)$e)] = (string)$e;

    $toAdd = [];
    foreach ($cpLowerToOrig as $lower=>$orig) if (!isset($dbLowerToOrig[$lower])) $toAdd[] = $orig;

    $toDelete = [];
    foreach ($dbLowerToOrig as $lower=>$orig) if (!isset($cpLowerToOrig[$lower])) $toDelete[] = $orig;

    $added=0; $deleted=0;
    $pdo->beginTransaction();
    try {
        if ($toAdd) {
            $ins = $pdo->prepare("INSERT INTO mailbox_expiry (email, expires_at) VALUES (:email, NULL) ON CONFLICT(email) DO NOTHING");
            foreach ($toAdd as $email) { $ins->execute([':email'=>$email]); $added += $ins->rowCount(); }
        }
        if ($toDelete) {
            $chunks = array_chunk($toDelete, 500);
            foreach ($chunks as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $del = $pdo->prepare("DELETE FROM mailbox_expiry WHERE email IN ($ph)");
                $del->execute($chunk);
                $deleted += $del->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'db_count'=>$dbCount, 'cp_count'=>count($cpEmails),
        'added'=>$added, 'added_list'=>$toAdd,
        'deleted'=>$deleted, 'deleted_list'=>$toDelete,
    ];
}


function set_mailbox_expiry_iso(string $email, ?string $iso8601): void {
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO mailbox_expiry (email, expires_at)
        VALUES (:email, :expires_at)
        ON CONFLICT(email) DO UPDATE SET expires_at = excluded.expires_at
    ");
    $stmt->execute([':email' => $email, ':expires_at' => $iso8601]);
}



function list_expired_mailboxes(): array {
    $pdo = db();
    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
    $stmt = $pdo->prepare("SELECT email FROM mailbox_expiry WHERE expires_at IS NOT NULL AND expires_at <= :now");
    $stmt->execute([':now' => $now]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}



/**
 * Supprime côté cPanel toutes les boîtes expirées, puis les enlève de la table.
 * @return array{deleted:int, failed:int, failures:array}
 */
function delete_expired_mailboxes(): array {
    $emails = list_expired_mailboxes();
    if (!$emails) return ['deleted'=>0, 'failed'=>0, 'failures'=>[]];

    $deleted = 0; $failed = 0; $failures = [];
    foreach ($emails as $email) {
        try {
            cpanel_delete_pop($email); // supprime l’adresse + home mail dir (par défaut)
            $deleted++;
        } catch (Throwable $e) {
            $failed++;
            $failures[] = ['email'=>$email, 'error'=>$e->getMessage()];
        }
    }

    if ($deleted) {
        $pdo = db();
        $ph = implode(',', array_fill(0, $deleted, '?'));
        $del = $pdo->prepare("DELETE FROM mailbox_expiry WHERE email IN ($ph)");
        // On ne supprime que ceux qui ont réussi
        $onlyDeleted = array_slice($emails, 0, $deleted);
        $del->execute($onlyDeleted);
    }

    return ['deleted'=>$deleted, 'failed'=>$failed, 'failures'=>$failures];
}


function set_mailbox_note(string $email, ?string $note): void {
    $pdo = db();
    $stmt = $pdo->prepare("
        INSERT INTO mailbox_expiry (email, note)
        VALUES (:email, :note)
        ON CONFLICT(email) DO UPDATE SET note = excluded.note
    ");
    $stmt->execute([':email' => $email, ':note' => $note]);
}
