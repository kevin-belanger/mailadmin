<?php

/** Crée une boîte POP via UAPI. */
function cpanel_add_pop(string $local, string $domain, string $password, int $quotaMiB): array {


    if (!CPANEL_TOKEN) throw new RuntimeException('CPANEL_TOKEN manquant.');
	$url  = "https://" . CPANEL_HOST . ":2083/execute/Email/add_pop";
    $post = ['email'=>$local, 'domain'=>$domain, 'password'=>$password, 'quota'=>$quotaMiB];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: cpanel ".CPANEL_USER.":".CPANEL_TOKEN],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("Erreur cURL: $e"); }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code !== 200) throw new RuntimeException("UAPI HTTP {$code}");
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException("Réponse UAPI non-JSON");
    if ((int)($json['status'] ?? 0) !== 1) {
        $errors = implode(' | ', (array)($json['errors'] ?? ['Échec inconnu']));
        throw new RuntimeException($errors);
    }
return ['ok'=>true, 'data'=>$json['data'] ?? null, 'raw'=>$json];
}

/** Met à jour le mot de passe d’une boîte POP via UAPI. */
function cpanel_passwd_pop(string $local, string $domain, string $password): array {
    if (!CPANEL_TOKEN) throw new RuntimeException('CPANEL_TOKEN manquant.');
    $url  = "https://" . CPANEL_HOST . ":2083/execute/Email/passwd_pop";
    $post = ['email'=>$local, 'domain'=>$domain, 'password'=>$password];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: cpanel ".CPANEL_USER.":".CPANEL_TOKEN],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("Erreur cURL: $e"); }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code !== 200) throw new RuntimeException("UAPI HTTP {$code}");
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException("Réponse UAPI non-JSON");
    if ((int)($json['status'] ?? 0) !== 1) {
        $errors = implode(' | ', (array)($json['errors'] ?? ['Échec inconnu']));
        throw new RuntimeException($errors);
    }
    return ['ok'=>true, 'data'=>$json['data'] ?? null, 'raw'=>$json];
}

/** Liste les boîtes d’un domaine. Ignore les comptes sans '@' (compte système). */
function cpanel_list_pops(string $domain): array {

    $url = "https://".CPANEL_HOST.":2083/execute/Email/list_pops?domain=" . rawurlencode($domain);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: cpanel " . CPANEL_USER . ":" . CPANEL_TOKEN],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("Erreur cURL: $e"); }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code !== 200) throw new RuntimeException("UAPI HTTP {$code}");
    $json = json_decode($raw, true);
    if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
        $errs = isset($json['errors']) ? implode(' | ', (array)$json['errors']) : 'Réponse invalide';
        throw new RuntimeException($errs);
    }
    $out = [];
    foreach (($json['data'] ?? []) as $row) {
        $email = strtolower((string)($row['email'] ?? ''));
        if ($email === '' || strpos($email, '@') === false) continue; // ignore le système
        $out[] = $email;
    }
    return $out;
}

/** Agrège toutes les boîtes sur tous les domaines autorisés. */
function cpanel_list_all_emails(): array {
    $all = [];
    foreach (ALLOWED_DOMAINS as $d) {
        try {
            foreach (cpanel_list_pops($d) as $e) $all[$e] = true;
        } catch (Throwable $e) {
            // on ignore les erreurs par domaine
        }
    }
    return array_keys($all);
}



function cpanel_delete_pop(string $email, ?string $flags = null): array {
    // UAPI: Email::delete_pop — param "email" peut être l'adresse complète
    $url  = "https://" . CPANEL_HOST . ":2083/execute/Email/delete_pop";
    $post = ['email' => $email];
    if ($flags !== null) $post['flags'] = $flags;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: cpanel " . CPANEL_USER . ":" . CPANEL_TOKEN],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { $e=curl_error($ch); curl_close($ch); throw new RuntimeException("Erreur cURL: $e"); }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($code !== 200) throw new RuntimeException("UAPI HTTP {$code}");
    $json = json_decode($raw, true);
    if (!is_array($json) || (int)($json['status'] ?? 0) !== 1) {
        $errs = implode(' | ', (array)($json['errors'] ?? ['Échec inconnu']));
        throw new RuntimeException($errs);
    }
    return ['ok'=>true, 'data'=>$json['data'] ?? null];
}

/** Appel générique API 2 (Cron) */
function cpanel_api2(string $module, string $func, array $params = []): array {
    $base = "https://" . CPANEL_HOST . ":2083/json-api/cpanel";
    $payload = array_merge([
        'cpanel_jsonapi_user'       => CPANEL_USER,
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module'     => $module,
        'cpanel_jsonapi_func'       => $func,
    ], $params);
	

    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: cpanel " . CPANEL_USER . ":" . CPANEL_TOKEN],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
	
    if ($raw === false) { $e=curl_error($ch); curl_close($ch); throw new RuntimeException("Erreur cURL: $e"); }
	
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code !== 200) throw new RuntimeException("API2 HTTP {$code}");
    $json = json_decode($raw, true);
    if (!isset($json['cpanelresult'])) throw new RuntimeException('Réponse API2 invalide');
    return $json['cpanelresult'];
}

function cpanel_cron_list(): array {
    $res = cpanel_api2('Cron', 'listcron'); // ou fetchcron
    return (array)($res['data'] ?? []);
}

function cpanel_cron_add_line(string $minute, string $hour, string $day, string $month, string $weekday, string $command): bool {
    $res = cpanel_api2('Cron', 'add_line', [
        'minute'  => $minute,
        'hour'    => $hour,
        'day'     => $day,
        'month'   => $month,
        'weekday' => $weekday,
        'command' => $command,
    ]);
    return !empty($res['event']['result']);
}

/** Vérifie si notre cron existe (par la commande exacte), sinon la crée. */
function ensure_evocron_installed(): array {
    if (!CRON_ENABLED) return ['enabled'=>false];

    $cmd = trim(CRON_PHP_BIN . ' ' . CRON_SCRIPT);
    $exists = false;
	
    foreach (cpanel_cron_list() as $row) {
        // Selon l’API, la clé peut s’appeler 'command'
        if (isset($row['command']) && trim((string)$row['command']) === $cmd) {
            $exists = true; break;
        }
    }
	
    if (!$exists) {
        $ok = cpanel_cron_add_line(CRON_MINUTE, CRON_HOUR, CRON_DAY, CRON_MONTH, CRON_WEEKDAY, $cmd);
        if (!$ok) throw new RuntimeException('Impossible de créer la tâche cron.');
        return ['enabled'=>true, 'created'=>true, 'command'=>$cmd];
    }
    return ['enabled'=>true, 'created'=>false, 'command'=>$cmd];
}