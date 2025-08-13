<?php
define('APP_TITLE',    getenv('APP_TITLE')  ?: 'evomail - admin');

// cPanel / UAPI
define('CPANEL_HOST',  getenv('CPANEL_HOST')  ?: 'domain.com');
define('CPANEL_USER',  getenv('CPANEL_USER')  ?: 'cpanel_admin_user');
define('CPANEL_TOKEN', getenv('CPANEL_TOKEN') ?: 'COPY_CPANEL_TOKEN_HERE'); // API Token créé dans Cpanel

// Auth admin
define('ADMIN_USERNAME',       'admin');
define('ADMIN_PASSWORD_HASH',  '$2y$10$0mU5OqSXl3cUta4/EtrjCebDhOcz31l9PPXHa.kFsh8OLQMis4yW6');

// Domaines & quotas
define('ALLOWED_DOMAINS',        ['domain.com']);
define('RESTRICTED_LOCALPARTS',  ['admin','postmaster','abuse','root','mail','webmaster']);
define('DEFAULT_QUOTA_MIB',      100);
define('DEFAULT_EXPIRY_DAYS',    60);

// SQLite (chemin absolu recommandé; protège-le par .htaccess si sous webroot)
define('SQLITE_PATH', __DIR__ . '/data/app.sqlite');

define('SYNC_MIN_INTERVAL_SEC', 60); // 1 minute; mets 300 si tu préfères 5 min


// --- CRON (horaire cPanel API2) ---
define('CRON_ENABLED', true);
define('CRON_MINUTE',  '*/30'); // toutes les 30 min
define('CRON_HOUR',    '*');
define('CRON_DAY',     '*');
define('CRON_MONTH',   '*');
define('CRON_WEEKDAY', '*');

// Binaire PHP CLI et script appelé
define('CRON_PHP_BIN', '/usr/local/bin/php');
define('CRON_SCRIPT',  __DIR__ . '/bin/cron_cleanup.php');