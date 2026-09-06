<?php
/**
 * Backup de imagens no Google Drive — PLUGÁVEL.
 *
 * A credencial mora no BANCO DE DADOS (tabela intus_secrets), não em arquivo.
 * Motivo: em 06/09/2026 o arquivo app/config/gdrive.json (onde a chave ficava
 * antes) desapareceu do servidor sozinho, sem log, causa não confirmada
 * (suspeita: o próprio deploy via Git limpando arquivo fora do repositório,
 * ou um scanner de segurança da hospedagem reagindo a uma chave privada
 * dentro da pasta pública do site). Banco de dados não é tocado por nenhum
 * dos dois. Quem preenche a chave: admin/gdrive-key.php (área logada).
 *
 * gdriveDisponivel(): bool         — há configuração válida?
 * gdriveBackup($bin,$nome,$mime): ?string  — sobe e devolve o link, ou null se falhar/inativo.
 *
 * Tudo é best-effort: qualquer falha retorna null e NUNCA quebra o salvamento.
 */

function _gdriveConn() {
    static $pdo = 'unset';
    if ($pdo !== 'unset') return $pdo;
    try {
        if (defined('DB_HOST')) {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            $cfg = @include __DIR__ . '/../config/db.php';
            $pdo = (is_array($cfg) && isset($cfg['host']))
                ? new PDO('mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['database'] . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])
                : null;
        }
    } catch (Throwable $e) { $pdo = null; }
    return $pdo;
}

function _gdriveEnsureTable(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_secrets (
        chave VARCHAR(80) PRIMARY KEY,
        valor LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function _gdriveKey() {
    $pdo = _gdriveConn();
    if (!$pdo) return null;
    try {
        _gdriveEnsureTable($pdo);
        $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'gdrive_config' LIMIT 1");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $k = json_decode($row['valor'], true);
        return is_array($k) ? $k : null;
    } catch (Throwable $e) { return null; }
}

function gdriveDisponivel() {
    if (!function_exists('curl_init') || !function_exists('openssl_sign')) return false;
    $k = _gdriveKey();
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
        $key = _gdriveKey();
        if (!is_array($key) || empty($key['client_email']) || empty($key['private_key']) || empty($key['folder_id'])) return null;
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
