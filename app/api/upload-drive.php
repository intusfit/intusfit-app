<?php
/**
 * Recebe upload de foto de avaliacao fisica e envia ao Google Drive via _gdrive.php.
 *
 * Este endpoint nao existia — o front-end (avaliacoes.html) sempre chamou este
 * caminho, recebia 404 e descartava a foto em silencio (ver CLAUDE.md, secao 11.4).
 * Por isso toda resposta de erro aqui e explicita: e melhor a avaliacao falhar
 * visivelmente do que a foto sumir sem ninguem notar.
 *
 * Simplificacao conhecida: gdriveBackup() grava tudo direto na pasta configurada
 * em gdrive.json (sem subpasta por aluno/mes). O nome do arquivo carrega esse
 * contexto como prefixo (ex: "JoaoSilva_setembro_2026_foto1.jpg") em vez de path
 * real no Drive — criar subpastas de verdade fica para uma proxima rodada, se
 * o volume de fotos justificar.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

// ---------- Conexao (mesmo padrao de catalogo.php) ----------
$cfg = @include __DIR__ . '/../config/db.php';
$pdo = null;
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new Exception('sem credenciais');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha de conexão com o banco']);
    exit;
}

// ---------- Autenticacao (mesmo padrao de catalogo.php) ----------
function _uploadDriveBearerToken() {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}
$_tok = _uploadDriveBearerToken();
$_ctx = ['admin' => false, 'idusuario' => 0, 'is_aluno' => false];
if (@file_exists(__DIR__ . '/_auth_context.php')) {
    @require_once __DIR__ . '/_auth_context.php';
    if (function_exists('getAuthContext')) {
        try { $c = getAuthContext($pdo, $_tok); if (is_array($c)) $_ctx = array_merge($_ctx, $c); }
        catch (Throwable $e) { /* mantem contexto vazio */ }
    }
}
if ((int)($_ctx['idusuario'] ?? 0) <= 0 && empty($_ctx['is_aluno'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

// ---------- Validacao do arquivo ----------
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Arquivo não recebido']);
    exit;
}
$tmp  = $_FILES['file']['tmp_name'];
$mime = @mime_content_type($tmp) ?: ($_FILES['file']['type'] ?? '');
if (!preg_match('#^image/(png|jpe?g|webp|gif)$#i', $mime)) {
    http_response_code(400);
    echo json_encode(['error' => 'Só imagens são aceitas']);
    exit;
}
if (($_FILES['file']['size'] ?? 0) > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['error' => 'Imagem maior que 8MB']);
    exit;
}

require_once __DIR__ . '/_gdrive.php';
if (!gdriveDisponivel()) {
    http_response_code(503);
    echo json_encode(['error' => 'Backup no Google Drive não está configurado neste servidor']);
    exit;
}

$bin  = file_get_contents($tmp);
$nome = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($_FILES['file']['name'] ?: 'foto.jpg'));
$prefixo = '';
if (!empty($_POST['path'])) {
    $prefixo = str_replace('/', '_', trim(preg_replace('/[^A-Za-z0-9._ \/-]/', '', $_POST['path']))) . '_';
}

$url = gdriveBackup($bin, $prefixo . $nome, $mime);

if ($url === null) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha ao enviar a imagem para o Google Drive']);
    exit;
}

echo json_encode(['url' => $url]);
