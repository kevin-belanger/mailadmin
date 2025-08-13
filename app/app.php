<?php
if (!defined('EVOMAIL_APP_LOADED')) {
    define('EVOMAIL_APP_LOADED', true);

    // 1) Config (constantes : CPANEL_HOST, SQLITE_PATH, etc.)
    require_once __DIR__ . '/../config.php';

    // 2) Runtime minimal
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    date_default_timezone_set('UTC');
    mb_internal_encoding('UTF-8');
    // error_reporting(E_ALL); ini_set('display_errors','0'); // prod conseillée

    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/cpanel.php';
    require_once __DIR__ . '/expiry.php';
	
}

// --- Auto-installation de la tâche cron si absente ---
if (defined('CRON_ENABLED') && CRON_ENABLED && PHP_SAPI !== 'cli') {
    // on ne tente que si l'admin est connecté
    if (function_exists('is_authed') && is_authed()) {
        try {
            // nécessite ensure_evocron_installed() dans cpanel.php
            ensure_evocron_installed();
        } catch (Throwable $e) {
            // on ne casse pas l'UI ; on log juste l'erreur
            error_log('[evomail] cron install failed: ' . $e->getMessage());
        }
    }
}