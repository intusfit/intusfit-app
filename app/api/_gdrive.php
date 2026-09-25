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
    // A ordem importa: db.php DEFINE as constantes como efeito colateral mas
    // nao faz `return` de array nenhum, entao "defined('DB_HOST')" so vira
    // verdade DEPOIS do include rodar — checar antes (como esta funcao fazia)
    // sempre caia no ramo errado na primeira chamada do processo.
    //
    // BUG real (achado testando o Drive pela primeira vez com chave de
    // verdade, 14/09/2026): upload-drive.php e catalogo.php ja incluem este
    // MESMO db.php no topo do arquivo antes de chamar qualquer funcao daqui.
    // Com `include` puro, essa segunda inclusao tentava redeclarar a funcao
    // getDB() que db.php define — fatal error de verdade, do tipo que nem
    // try/catch pega, derrubando a requisicao inteira (post do feed, upload
    // de avaliacao) sem log nenhum, so um 500 em branco. `include_once`
    // resolve: na segunda vez so devolve true (sem rodar o arquivo de novo),
    // e as constantes ja definidas na primeira inclusao continuam valendo —
    // o ramo `defined('DB_HOST')` abaixo cobre exatamente esse caso.
    $cfg = @include_once __DIR__ . '/../config/db.php';
    try {
        if (is_array($cfg) && isset($cfg['host'])) {
            $pdo = new PDO('mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['database'] . ';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } elseif (defined('DB_HOST')) {
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } else {
            $pdo = null;
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
        // drive.file só enxerga arquivos que a PRÓPRIA conta de serviço criou —
        // não uma pasta que o Luiz compartilhou com ela depois. Precisa do
        // escopo cheio para conseguir escrever dentro de uma pasta existente
        // compartilhada por outra pessoa. Verificado em 06/09/2026: era a causa
        // exata do "sobe local, nunca aparece no Drive" (404 "File not found"
        // na pasta, mesmo com a chave e o folder_id certos).
        'scope' => 'https://www.googleapis.com/auth/drive',
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

        // supportsAllDrives=true e obrigatorio pra gravar num Drive
        // Compartilhado — sem isso a API trata a pasta como se nao existisse
        // (o mesmo 403 "Service Accounts do not have storage quota" que
        // apareceu antes, so que agora seria um 404 se a pasta de destino
        // for um Drive Compartilhado de verdade).
        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink&supportsAllDrives=true');
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

// ─────────────────────────────────────────────────────────────────────────────
// Arquivos GRANDES (vídeo de feedback) — upload em partes e leitura por faixa.
//
// Diferente do gdriveBackup() acima (best-effort, devolve null calado), estas
// funções LANÇAM Exception com o motivo: quem chama (feedbacks.php) precisa
// mostrar o erro ao professor em vez de perder a gravação em silêncio.
//
// O vídeo nunca passa inteiro pelo PHP: o navegador manda pedaços de poucos MB,
// cada pedaço é repassado na hora pra uma "sessão de upload retomável" do Drive
// (a URL da sessão fica guardada no banco), e nada fica no disco da KingHost.
// ─────────────────────────────────────────────────────────────────────────────

// Token de acesso reaproveitado por ~50 min (evita assinar um JWT novo a cada
// pedaço de vídeo). Guardado na mesma tabela da chave, que já é protegida.
function gdriveTokenAtual() {
    $pdo = _gdriveConn();
    if ($pdo) {
        try {
            _gdriveEnsureTable($pdo);
            $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'gdrive_token_cache' LIMIT 1");
            $st->execute();
            $c = json_decode((string)$st->fetchColumn(), true);
            if (is_array($c) && !empty($c['t']) && (int)($c['exp'] ?? 0) > time() + 120) return $c['t'];
        } catch (Throwable $e) { /* segue e gera um novo */ }
    }
    $key = _gdriveKey();
    if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) throw new Exception('Google Drive não configurado');
    $t = _gdriveToken($key);
    if (!$t) throw new Exception('Google Drive recusou a chave (token)');
    if ($pdo) {
        try {
            $pdo->prepare("REPLACE INTO intus_secrets (chave, valor) VALUES ('gdrive_token_cache', ?)")
                ->execute([json_encode(['t' => $t, 'exp' => time() + 3000])]);
        } catch (Throwable $e) {}
    }
    return $t;
}

// Abre a sessão de upload e devolve a URL dela (válida por ~1 semana no Google).
function gdriveIniciarUploadGrande($nome, $mime, $tamanho) {
    $key = _gdriveKey();
    if (!is_array($key) || empty($key['folder_id'])) throw new Exception('Google Drive não configurado');
    $token = gdriveTokenAtual();
    $meta = json_encode(['name' => $nome, 'parents' => [$key['folder_id']], 'mimeType' => $mime]);
    $loc = '';
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . $mime,
            'X-Upload-Content-Length: ' . (int)$tamanho,
        ],
        CURLOPT_POSTFIELDS => $meta,
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$loc) {
            if (stripos($linha, 'Location:') === 0) $loc = trim(substr($linha, 9));
            return strlen($linha);
        },
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$loc) throw new Exception('Drive não abriu o upload (HTTP ' . $code . ') ' . substr((string)$resp, 0, 200));
    return $loc;
}

// Envia um pedaço. $inicio = byte onde o pedaço começa; $total = tamanho do vídeo.
// Devolve ['recebido' => próximo byte esperado, 'file_id' => id quando terminou].
function gdriveEnviarParte($sessao, $bin, $inicio, $total) {
    $fim = $inicio + strlen($bin) - 1;
    $range = '';
    $ch = curl_init($sessao);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => [
            'Content-Length: ' . strlen($bin),
            'Content-Range: bytes ' . $inicio . '-' . $fim . '/' . (int)$total,
        ],
        CURLOPT_POSTFIELDS => $bin,
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$range) {
            if (stripos($linha, 'Range:') === 0) $range = trim(substr($linha, 6));
            return strlen($linha);
        },
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200 || $code === 201) {
        $j = json_decode((string)$resp, true);
        if (empty($j['id'])) throw new Exception('Drive terminou o upload sem devolver o id');
        return ['recebido' => (int)$total, 'file_id' => $j['id']];
    }
    if ($code === 308) {
        // "Range: bytes=0-N" = o Drive já tem até o byte N. Sem Range = não tem nada.
        $recebido = preg_match('/bytes=0-(\d+)/', $range, $m) ? ((int)$m[1] + 1) : 0;
        return ['recebido' => $recebido, 'file_id' => null];
    }
    throw new Exception('Drive recusou o pedaço (HTTP ' . $code . ') ' . substr((string)$resp, 0, 200));
}

// Lê uma faixa de bytes do arquivo (para tocar o vídeo sem baixar tudo de uma vez).
// Devolve ['codigo', 'corpo', 'content_range', 'tamanho_total'].
function gdriveLerFaixa($fileId, $inicio, $fim) {
    $token = gdriveTokenAtual();
    $contentRange = '';
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Range: bytes=' . (int)$inicio . '-' . (int)$fim],
        CURLOPT_HEADERFUNCTION => function ($c, $linha) use (&$contentRange) {
            if (stripos($linha, 'Content-Range:') === 0) $contentRange = trim(substr($linha, 14));
            return strlen($linha);
        },
    ]);
    $corpo = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 && $code !== 206) throw new Exception('Drive não entregou o vídeo (HTTP ' . $code . ')');
    return ['codigo' => $code, 'corpo' => (string)$corpo, 'content_range' => $contentRange];
}

// Apaga de vez; se a conta de serviço não tiver permissão pra apagar no Drive
// compartilhado, tenta mandar pra lixeira. Devolve true se um dos dois deu certo.
function gdriveExcluirArquivo($fileId) {
    $token = gdriveTokenAtual();
    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?supportsAllDrives=true';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 204 || $code === 200 || $code === 404) return true;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['trashed' => true])]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}
