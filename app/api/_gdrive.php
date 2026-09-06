<?php
/**
 * Backup de imagens no Google Drive — PLUGÁVEL.
 *
 * Fica INATIVO até você colocar a chave da conta de serviço em:
 *     /app/config/gdrive.json
 *
 * Formato esperado do gdrive.json (a chave JSON da conta de serviço do Google,
 * com um campo extra "folder_id" apontando para a sua pasta do Drive):
 * {
 *   "type": "service_account",
 *   "client_email": "...@....iam.gserviceaccount.com",
 *   "private_key": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n",
 *   "folder_id": "ID_DA_SUA_PASTA_NO_DRIVE"
 * }
 *
 * Lembre de COMPARTILHAR a pasta do Drive com o client_email (permissão Editor).
 *
 * gdriveDisponivel(): bool         — há configuração válida?
 * gdriveBackup($bin,$nome,$mime): ?string  — sobe e devolve o link, ou null se falhar/inativo.
 *
 * Tudo é best-effort: qualquer falha retorna null e NUNCA quebra o salvamento.
 */

function _gdriveConfigPath() { return __DIR__ . '/../config/gdrive.json'; }

function gdriveDisponivel() {
    if (!function_exists('curl_init') || !function_exists('openssl_sign')) return false;
    $p = _gdriveConfigPath();
    if (!is_file($p)) return false;
    $k = json_decode(@file_get_contents($p), true);
    return is_array($k) && !empty($k['client_email']) && !empty($k['private_key']) && !empty($k['folder_id']);
}

function _gdriveToken($key) {
    $b64 = function ($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); };
    $now = time();
    $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim  = $b64(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $unsigned = $header . '.' . $claim;
    $sig = '';
    if (!openssl_sign($unsigned, $sig, $key['private_key'], 'SHA256')) return null;
    $jwt = $unsigned . '.' . $b64($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) return null;
    $j = json_decode($resp, true);
    return $j['access_token'] ?? null;
}

function gdriveBackup($bin, $nome, $mime = 'image/jpeg') {
    try {
        if (!gdriveDisponivel()) return null;
        $key = json_decode(@file_get_contents(_gdriveConfigPath()), true);
        $token = _gdriveToken($key);
        if (!$token) return null;

        $meta = json_encode(['name' => $nome, 'parents' => [$key['folder_id']]]);
        $boundary = '----intus' . bin2hex(random_bytes(8));
        $body  = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n";
        $body .= "--$boundary\r\nContent-Type: $mime\r\n\r\n$bin\r\n--$boundary--";

        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: multipart/related; boundary=' . $boundary,
            ],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300 || !$resp) return null;
        $j = json_decode($resp, true);
        return $j['webViewLink'] ?? ($j['id'] ? ('drive:' . $j['id']) : null);
    } catch (Throwable $e) {
        return null;
    }
}
