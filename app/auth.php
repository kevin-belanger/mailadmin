<?php

function is_authed(): bool {
    return !empty($_SESSION['is_auth']);
}

function require_auth(): void {
    if (!is_authed()) {
        header('Location: login.php');
        exit;
    }
}

function login_with_password(string $username, string $password): bool {
    if (!ADMIN_PASSWORD_HASH) return false;
    if ($username !== ADMIN_USERNAME) return false;
    if (!password_verify($password, ADMIN_PASSWORD_HASH)) return false;
    $_SESSION['is_auth'] = true;
    $_SESSION['admin_user'] = $username;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function check_csrf(?string $t): bool {
    return $t && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function sanitize_localpart(string $s): string {
    $s = trim($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9._-]/', '', $s) ?? '';
    $s = preg_replace('/^[._-]+|[._-]+$/', '', $s) ?? '';
    return $s;
}

function generate_password(int $len = 16): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()-_=+';
    $bytes = random_bytes($len);
    $out = '';
    for ($i=0; $i<$len; $i++) $out .= $alphabet[ord($bytes[$i]) % strlen($alphabet)];
    return $out;
}
