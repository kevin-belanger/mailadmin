<?php
// bin/cron_cleanup.php
require_once __DIR__ . '/../app/app.php';

// 1) sync cPanel -> DB (ajouts/purge)
$sync = sync_mailbox_expiry_with_cpanel();

// 2) suppression des expirés
$purge = delete_expired_mailboxes();

// (facultatif) un petit output texte pour les logs cron
echo "[evomail] sync: +{$sync['added']}/-{$sync['deleted']} ; purge: deleted={$purge['deleted']} failed={$purge['failed']}\n";
if ($purge['failed'] > 0) {
    foreach ($purge['failures'] as $f) {
        echo " - {$f['email']} : {$f['error']}\n";
    }
}
