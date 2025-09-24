<?php
require_once __DIR__ . '/app/app.php';

require_auth();

$flash_msg = null;
$flash_err = null;
$success = null;
$passwordShown = null;
$passwordContext = null;
$passwordEmail = null;

// ---- Actions POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!check_csrf($_POST['csrf'] ?? null)) {
        $flash_err = "Session expirée. Réessaie.";
    } else {
        try {
            switch ($action) {
                case 'create_mailbox': {

                    $local  = sanitize_localpart((string)($_POST['localpart'] ?? ''));
                    $domain = strtolower(trim((string)($_POST['domain'] ?? '')));
                    $quota  = (int)($_POST['quota'] ?? DEFAULT_QUOTA_MIB);
                    $pwd    = (string)($_POST['password'] ?? '');

                    $expiry_mode = (string)($_POST['expiry_mode'] ?? 'days');
                    $expiry_days = null;
                    if ($expiry_mode === 'days') {
                        $expiry_days = (int)($_POST['expiry_days'] ?? DEFAULT_EXPIRY_DAYS);
                        if ($expiry_days < 0) $expiry_days = DEFAULT_EXPIRY_DAYS;
                    } // else NULL = jamais

                    if ($local === '') throw new RuntimeException("Le nom d’utilisateur est requis.");
                    if (!in_array($domain, ALLOWED_DOMAINS, true)) throw new RuntimeException("Domaine non autorisé.");
                    if (in_array($local, RESTRICTED_LOCALPARTS, true)) throw new RuntimeException("Nom réservé, choisis-en un autre.");
                    if ($quota < 0) $quota = 0;
                    if ($pwd === '') $pwd = generate_password(16);

                    $api = cpanel_add_pop($local, $domain, $pwd, $quota);
                    $email = "{$local}@{$domain}";
                    upsert_mailbox_expiry($email, $expiry_days);

                    $passwordShown = $pwd;
                    $passwordContext = 'create';
                    $passwordEmail = $email;
                    $success = [
                        'email'=>$email,
                        'quota'=>$quota,
                        'expiry'=>$expiry_days,
                        'data'=>$api['data'] ?? null
                    ];
                    $flash_msg = "Boîte créée : {$email}";
                    break;
                }
                case 'reset_password': {
                    $email = trim((string)($_POST['email'] ?? ''));
                    $mode = (string)($_POST['pwd_mode'] ?? 'manual');
                    $password = (string)($_POST['password'] ?? '');

                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException("Adresse courriel invalide.");
                    }
                    [$local, $domain] = explode('@', $email, 2);
                    if ($local === '' || $domain === '') {
                        throw new RuntimeException("Adresse courriel invalide.");
                    }
                    $domain = strtolower($domain);
                    if (!in_array($domain, ALLOWED_DOMAINS, true)) {
                        throw new RuntimeException("Domaine non autorisé.");
                    }

                    if ($mode === 'auto') {
                        $password = generate_password(16);
                    } else {
                        if ($password === '') {
                            throw new RuntimeException("Le mot de passe est requis.");
                        }
                    }

                    cpanel_passwd_pop($local, $domain, $password);

                    $passwordShown = $password;
                    $passwordContext = 'reset';
                    $passwordEmail = $email;
                    $flash_msg = "Mot de passe mis à jour pour {$email}.";
                    break;
                }
                case 'update_expiry': {
                    $email   = trim((string)($_POST['email'] ?? ''));
                    $never   = isset($_POST['never']);
                    $ymd     = trim((string)($_POST['expiry_date'] ?? ''));

                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException("Adresse courriel invalide.");
                    }

                    if ($never) {
                        set_mailbox_expiry_iso($email, null);
                    } else {
                        if ($ymd === '') throw new RuntimeException("Date manquante (ou coche 'jamais').");
                        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
                        if ($dt === false) throw new RuntimeException("Format de date invalide.");
                        set_mailbox_expiry_iso($email, $dt->format('c'));
                    }

                    $flash_msg = "Expiration mise à jour pour {$email}.";
                    break;
                }
                case 'save_note': {
                    $email = trim((string)($_POST['email'] ?? ''));
                    $noteRaw = (string)($_POST['note'] ?? '');

                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException("Adresse courriel invalide.");
                    }

                    $note = trim($noteRaw);
                    if (mb_strlen($note) > 255) {
                        throw new RuntimeException("La note doit contenir au plus 255 caractères.");
                    }

                    $noteValue = ($note === '') ? null : $note;
                    set_mailbox_note($email, $noteValue);

                    $flash_msg = $noteValue === null
                        ? "Note supprimée pour {$email}."
                        : "Note enregistrée pour {$email}.";
                    break;
                }
                default:
                    throw new RuntimeException("Action inconnue.");
            }
        } catch (Throwable $e) {
            $flash_err = $e->getMessage();
        }
    }
}

// ---- Export CSV (GET) ----
if (isset($_GET['export_expiry'])) {
    $pdo = db();
    $nowDT = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $rows = $pdo->query("SELECT email, expires_at FROM mailbox_expiry ORDER BY expires_at IS NULL, expires_at")->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mailbox_expirations.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email','expires_at','status','days_left']);
    foreach ($rows as $r) {
        $expiresAt = $r['expires_at'];
        if ($expiresAt === null) {
            $status = 'jamais'; $daysLeft = null;
        } else {
            try {
                $expDT = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
                $d = (int)$nowDT->diff($expDT)->format('%r%a');
                $status = $d < 0 ? 'expiré' : ($d === 0 ? 'aujourd’hui' : "dans {$d} jours");
                $daysLeft = $d;
            } catch (Throwable $e) { $status = 'invalide'; $daysLeft = null; }
        }
        fputcsv($out, [$r['email'], $r['expires_at'], $status, $daysLeft]);
    }
    fclose($out);
    exit;
}


// ---- Sync auto avec cPanel (throttlé) ----
$sync_stats = null;
$minInterval = defined('SYNC_MIN_INTERVAL_SEC') ? (int)SYNC_MIN_INTERVAL_SEC : 60;
$lastSyncTs  = (int)($_SESSION['last_sync_ts'] ?? 0);

// On sync si:
// - aucune sync récente (>= intervalle), OU
// - la requête demande explicitement une sync (optionnel via ?force_sync=1)
$forceSync = isset($_GET['force_sync']);
if ($forceSync || (time() - $lastSyncTs) >= $minInterval) {
    try {
        $sync_stats = sync_mailbox_expiry_with_cpanel();
        $_SESSION['last_sync_ts'] = time();
        // On prépare un petit message discret que l’on affichera dans l’UI
        /* On affiche pas l'état de la synchro si elle a réussi.
			$flash_msg = $flash_msg
            ? $flash_msg
            : "Synchronisation automatique : ajoutés {$sync_stats['added']}, supprimés {$sync_stats['deleted']}.";
		*/
    } catch (Throwable $e) {
        // On ne bloque pas la page pour une erreur de sync ; on affiche juste un message
        $flash_err = $flash_err ?: "Synchronisation automatique échouée : " . $e->getMessage();
    }
}


// ---- Liste pour affichage ----
$csrf = csrf_token();
$qexp = trim((string)($_GET['qexp'] ?? ''));
$pdo = db();
$params = [':now'=>(new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c')];
$where  = $qexp !== '' ? "WHERE email LIKE :qexp" : '';
if ($where) $params[':qexp'] = "%{$qexp}%";
$stmt = $pdo->prepare("
    SELECT email, expires_at, note
    FROM mailbox_expiry
    {$where}
    ORDER BY
      CASE WHEN expires_at IS NULL THEN 2
           WHEN expires_at <= :now THEN 0
           ELSE 1 END,
      expires_at ASC
");
$stmt->execute($params);
$expData = $stmt->fetchAll();

function renderExpiryBadge(?string $expires_at): string {
    $nowDT = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($expires_at === null) return '<span class="badge text-bg-secondary">jamais</span>';
    try {
        $expDT = new DateTimeImmutable($expires_at, new DateTimeZone('UTC'));
        $diff = (int)$nowDT->diff($expDT)->format('%r%a');
        if ($diff < 0)   return '<span class="badge text-bg-danger">expiré</span>';
        if ($diff === 0) return '<span class="badge text-bg-warning">aujourd’hui</span>';
        $class = ($diff <= 7) ? 'text-bg-warning' : 'text-bg-success';
        return '<span class="badge '.$class.'">dans '.htmlspecialchars((string)$diff).' j</span>';
    } catch (Throwable $e) {
        return '<span class="badge text-bg-dark">invalide</span>';
    }
}


?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title><?= APP_TITLE ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui/dist/fancybox.css">
</head>
<body class="bg-dark text-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-black">
  <div class="container">
    <span class="navbar-brand"><?= APP_TITLE ?></span>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-light btn-sm" href="?export_expiry=1">export CSV</a>
      <a class="btn btn-outline-light btn-sm" href="login.php?logout=1">se déconnecter</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <?php if ($flash_msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_msg, ENT_QUOTES) ?></div>
  <?php elseif ($flash_err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_err, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <?php if ($passwordShown && $passwordContext === 'reset' && $passwordEmail): ?>
    <div class="alert alert-warning">
      <strong>Nouveau mot de passe pour <code><?= htmlspecialchars($passwordEmail, ENT_QUOTES) ?></code> :</strong>
      <code><?= htmlspecialchars($passwordShown, ENT_QUOTES) ?></code>
    </div>
  <?php endif; ?>

  <!-- Création -->
  <div class="card shadow mb-4">
    <div class="card-body">
      <h1 class="h5 mb-3">Créer une boîte courriel</h1>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <strong>Succès!</strong> <code><?= htmlspecialchars($success['email'], ENT_QUOTES) ?></code> — quota <code><?= (int)$success['quota'] ?></code> MiB — expiration:
          <code><?= $success['expiry'] === null ? 'jamais' : ((int)$success['expiry'].' jours') ?></code>
        </div>
        <?php if ($passwordShown && $passwordContext === 'create'): ?>
          <div class="alert alert-warning mb-0"><strong>Mot de passe (affiché une seule fois):</strong> <code><?= htmlspecialchars($passwordShown, ENT_QUOTES) ?></code></div>
        <?php endif; ?>
        <hr>
      <?php endif; ?>

      <form method="post" autocomplete="off" class="row g-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="action" value="create_mailbox">
        <div class="col-md-3">
          <label class="form-label" for="localpart">Nom d’utilisateur <span class="text-secondary small">(avant le @)</span></label>
          <input id="localpart" name="localpart" required placeholder="Ex: prenom.nom"
                 class="form-control"
                 value="<?= isset($_POST['localpart'])?htmlspecialchars((string)$_POST['localpart'],ENT_QUOTES):'' ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label" for="domain">Domaine</label>
          <select id="domain" name="domain" class="form-select" required>
            <?php foreach (ALLOWED_DOMAINS as $d): ?>
              <option value="<?= htmlspecialchars($d, ENT_QUOTES) ?>" <?= (($_POST['domain'] ?? '')===$d)?'selected':'' ?>>@<?= htmlspecialchars($d) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label" for="quota">Quota
          <span class="text-secondary small">(0 = illimité)</span>
		  </label>
		  <div class="input-group">
			<input id="quota" name="quota" type="number" min="0" step="1" class="form-control"
                 value="<?= isset($_POST['quota'])?(int)$_POST['quota']:DEFAULT_QUOTA_MIB ?>">
			 <span class="input-group-text">MB</span>
		 </div>
        </div>

        <div class="col-md-6">
			
			  <label class="form-label" for="password">Mot de passe <span class="text-secondary small">(Laisser vide pour générer automatiquement)</span></label>
			  <input id="password" name="password" type="text" class="form-control">
			
        </div>
        
        <div class="col-12 border p-3">
          <label class="form-label d-block">Expiration</label>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="expiry_mode" id="expiry_days_mode" value="days"
                   <?= (($_POST['expiry_mode'] ?? 'days') === 'days') ? 'checked' : '' ?>>
            <label class="form-check-label" for="expiry_days_mode">expire dans</label>
          </div>
          <input type="number" min="1" step="1" class="form-control d-inline-block"
                 style="width:120px" name="expiry_days" id="expiry_days"
                 value="<?= isset($_POST['expiry_days']) ? (int)$_POST['expiry_days'] : (int)DEFAULT_EXPIRY_DAYS ?>"
                 <?= (($_POST['expiry_mode'] ?? 'days') === 'never') ? 'disabled' : '' ?>>
          <span class="ms-1">jours</span>
          <div class="form-check form-check-inline ms-3">
            <input class="form-check-input" type="radio" name="expiry_mode" id="expiry_never_mode" value="never"
                   <?= (($_POST['expiry_mode'] ?? 'days') === 'never') ? 'checked' : '' ?>>
            <label class="form-check-label" for="expiry_never_mode">jamais</label>
          </div>
        </div>

        <script>
          document.addEventListener('DOMContentLoaded', function () {
            const rDays  = document.getElementById('expiry_days_mode');
            const rNever = document.getElementById('expiry_never_mode');
            const inputD = document.getElementById('expiry_days');
            function sync(){ inputD.disabled = rNever.checked; }
            rDays.addEventListener('change', sync);
            rNever.addEventListener('change', sync);
            sync();
          });
        </script>

        <div class="col-12 d-grid d-md-flex gap-2">
          <button class="btn btn-primary" type="submit">Créer la boite courriel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Expirations -->
  <div class="card shadow">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Liste des boites courriel</h2>
        </div>

      <form class="row g-2 mb-3" method="get">
        <div class="col-md-6">
          <input class="form-control" name="qexp" placeholder="recherche (email)"
                 value="<?= htmlspecialchars($qexp, ENT_QUOTES) ?>">
        </div>
        <div class="col-md-6 d-grid d-md-flex gap-2">
          <button class="btn btn-primary" type="submit">filtrer</button>
          <a class="btn btn-outline-light" href="?export_expiry=1">export CSV</a>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle mb-0">
          <thead class="border">
            <tr>
              <th>Boite courriel</th>
              <th>Expiration</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if ($expData): foreach ($expData as $r):
              $badge = renderExpiryBadge($r['expires_at']);
              $ymd=''; $neverChecked='';
              if ($r['expires_at'] === null) { $neverChecked='checked'; }
              else { try { $ymd=(new DateTimeImmutable($r['expires_at'], new DateTimeZone('UTC')))->format('Y-m-d'); } catch(Throwable $e){ $ymd=''; } }
              $hash = md5($r['email']);
              $modalId = 'pwdModal_' . $hash;
              $noteModalId = 'noteModal_' . $hash;
              $noteTextareaId = 'noteTextarea_' . $hash;
              $noteRaw = $r['note'] ?? null;
              $noteHasText = is_string($noteRaw) && trim((string)$noteRaw) !== '';
              $noteBtnClass = $noteHasText ? 'btn-success' : 'btn-secondary';
              $noteBtnLabel = $noteHasText ? 'note (1)' : 'note';
              $noteValue = is_string($noteRaw) ? (string)$noteRaw : '';
              $noteInitialLength = mb_strlen($noteValue);
          ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['email']) ?></strong></td>
              <td>
                <form method="post" class="d-flex align-items-center gap-2">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                  <input type="hidden" name="action" value="update_expiry">
                  <input type="hidden" name="email" value="<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>">
                  <input style="max-width:200px" type="date" name="expiry_date" class="form-control form-control-sm"
                         value="<?= htmlspecialchars($ymd, ENT_QUOTES) ?>" <?= $neverChecked?'disabled':'' ?>
                         style="min-width:150px;">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="never_<?= htmlspecialchars($hash, ENT_QUOTES) ?>"
                           name="never" <?= $neverChecked ?>>
                    <label class="form-check-label small" for="never_<?= htmlspecialchars($hash, ENT_QUOTES) ?>">jamais</label>
                  </div>
                  <button class="btn btn-sm btn-primary" type="submit">enregistrer</button>
                </form>
              </td>
              <td><?= $badge ?></td>
              <td>
                <button type="button"
                        class="btn <?= $noteBtnClass ?> rounded-pill btn-sm me-2"
                        data-fancybox
                        data-src="#<?= htmlspecialchars($noteModalId, ENT_QUOTES) ?>">
                  <?= htmlspecialchars($noteBtnLabel, ENT_QUOTES) ?>
                </button>
                <div style="display:none;" id="<?= htmlspecialchars($noteModalId, ENT_QUOTES) ?>">
                  <div class="card shadow-sm">
                    <div class="card-body">
                      <h2 class="h6 mb-3">Note pour <span class="font-monospace"><?= htmlspecialchars($r['email'], ENT_QUOTES) ?></span></h2>
                      <form method="post" class="d-flex flex-column gap-3" data-note-form>
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <input type="hidden" name="action" value="save_note">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>">
                        <div>
                          <label class="form-label" for="<?= htmlspecialchars($noteTextareaId, ENT_QUOTES) ?>">Note</label>
                          <textarea class="form-control"
                                    id="<?= htmlspecialchars($noteTextareaId, ENT_QUOTES) ?>"
                                    name="note"
                                    rows="6"
                                    maxlength="255"
                                    data-note-input
                                    data-note-original="<?= htmlspecialchars($noteValue, ENT_QUOTES) ?>"><?= htmlspecialchars($noteValue, ENT_QUOTES) ?></textarea>
                          <div class="form-text text-end"><span data-note-counter-for="<?= htmlspecialchars($noteTextareaId, ENT_QUOTES) ?>"><?= (int)$noteInitialLength ?></span>/255</div>
                        </div>
                        <div class="d-flex justify-content-between">
                          <button type="button" class="btn btn-outline-secondary" data-fancybox-close data-note-cancel>Annuler</button>
                          <button type="submit" class="btn btn-primary">Enregistrer</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
                <button type="button" class="btn btn-primary rounded-pill btn-sm" data-bs-toggle="modal" data-bs-target="#<?= htmlspecialchars($modalId, ENT_QUOTES) ?>">
                  mot de passe
                </button>
                <div class="modal fade text-dark" id="<?= htmlspecialchars($modalId, ENT_QUOTES) ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="post" autocomplete="off">
                        <div class="modal-header">
                          <h1 class="modal-title fs-6">Réinitialiser le mot de passe</h1>
                          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                        </div>
                        <div class="modal-body">
                          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                          <input type="hidden" name="action" value="reset_password">
                          <input type="hidden" name="email" value="<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>">
                          <p class="mb-2"><span class="fw-semibold">Boîte :</span> <span class="font-monospace"><?= htmlspecialchars($r['email']) ?></span></p>
                          <p class="small text-secondary mb-3">Choisis d’entrer un mot de passe manuellement ou laisse l’application en générer un automatiquement.</p>
                          <div class="form-check">
                            <input class="form-check-input" type="radio" name="pwd_mode" id="pwd_manual_<?= htmlspecialchars($hash, ENT_QUOTES) ?>" value="manual" checked data-password-input="#pwd_input_<?= htmlspecialchars($hash, ENT_QUOTES) ?>">
                            <label class="form-check-label" for="pwd_manual_<?= htmlspecialchars($hash, ENT_QUOTES) ?>">Définir manuellement</label>
                          </div>
                          <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="pwd_mode" id="pwd_auto_<?= htmlspecialchars($hash, ENT_QUOTES) ?>" value="auto" data-password-input="#pwd_input_<?= htmlspecialchars($hash, ENT_QUOTES) ?>">
                            <label class="form-check-label" for="pwd_auto_<?= htmlspecialchars($hash, ENT_QUOTES) ?>">Générer automatiquement</label>
                          </div>
                          <label class="form-label" for="pwd_input_<?= htmlspecialchars($hash, ENT_QUOTES) ?>">Nouveau mot de passe</label>
                          <input type="text" class="form-control" id="pwd_input_<?= htmlspecialchars($hash, ENT_QUOTES) ?>" name="password" placeholder="Entrez le nouveau mot de passe">
                          <div class="form-text">Ce champ est ignoré si « générer automatiquement » est sélectionné.</div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                          <button type="submit" class="btn btn-primary">Mettre à jour</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4" class="text-center text-secondary">Aucune expiration enregistrée.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
	  
	  <p class="text-secondary small mt-2 mb-0">
        Une tâche automatisée supprime les boites expirés à toutes les 30 minutes.
      </p>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui/dist/fancybox.umd.js"></script>
<script>
// Active/désactive l'input date quand on coche "jamais"
document.addEventListener('change', function (e) {
  if (e.target.matches('input[type="checkbox"][name="never"]')) {
    const form = e.target.closest('form');
    const dateInput = form.querySelector('input[type="date"][name="expiry_date"]');
    if (dateInput) dateInput.disabled = e.target.checked;
  }
});

function syncPasswordMode(radio) {
  if (!radio || !radio.checked) return;
  const selector = radio.getAttribute('data-password-input');
  if (!selector) return;
  const input = document.querySelector(selector);
  if (!input) return;
  if (radio.value === 'auto') {
    input.disabled = true;
    input.value = '';
  } else {
    input.disabled = false;
  }
}

document.addEventListener('change', function (e) {
  if (e.target.matches('input[type="radio"][name="pwd_mode"]')) {
    syncPasswordMode(e.target);
  }
});

document.querySelectorAll('input[type="radio"][name="pwd_mode"]:checked').forEach(function (el) {
  syncPasswordMode(el);
});

if (window.Fancybox && typeof Fancybox.bind === 'function') {
  Fancybox.bind('[data-fancybox]', {
    dragToClose: false
  });
}

function updateNoteCounter(textarea) {
  if (!textarea || !textarea.id) return;
  const selector = '[data-note-counter-for="' + textarea.id + '"]';
  const counter = document.querySelector(selector);
  if (!counter) return;
  const length = Array.from(textarea.value || '').length;
  counter.textContent = length.toString();
}

document.querySelectorAll('[data-note-input]').forEach(function (textarea) {
  textarea.addEventListener('input', function () {
    updateNoteCounter(textarea);
  });
  updateNoteCounter(textarea);
});

document.querySelectorAll('[data-note-form]').forEach(function (form) {
  const textarea = form.querySelector('[data-note-input]');
  if (!textarea) return;

  function restoreOriginalNote() {
    const original = textarea.getAttribute('data-note-original');
    textarea.value = original !== null ? original : '';
    updateNoteCounter(textarea);
  }

  const cancelBtn = form.querySelector('[data-note-cancel]');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      restoreOriginalNote();
    });
  }

  form.addEventListener('reset', function () {
    window.setTimeout(function () {
      restoreOriginalNote();
    }, 0);
  });
});
</script>

</body>
</html>
