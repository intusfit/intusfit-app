<?php
/**
 * Entrada no editor da página /black usando o login do painel da Intus, sem a senha do /admin.
 *
 *   POST (Authorization: Bearer <sessão do painel>)  ->  { ok, url: '/admin/black.php' } e a sessão PHP no cookie.
 *
 * Só a conta principal (usuário 1 da equipe) entra. A sessão criada aqui NÃO marca 'auth': o resto do /admin (chaves do Drive, WhatsApp,
 * Asaas, e-mail, páginas do site) continua exigindo o login próprio. Ela só abre o editor e o envio de fotos dele, por 2 horas.
 * O pedido precisa do cabeçalho Authorization, que outro site não consegue mandar em nome de ninguém.
 */
require_once __DIR__ . '/_cors.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _bsResp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') _bsResp(405, ['ok' => false, 'erro' => 'Use POST.']);
$cfg = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) { _bsResp(500, ['ok' => false, 'erro' => 'Falha na conexão com o banco.']); }

require_once __DIR__ . '/_sessions.php';
$h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
$tok = preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';

$sessao = validateSession($pdo, $tok);   // confere validade e se o usuário não foi inativado
if (!$sessao) _bsResp(401, ['ok' => false, 'erro' => 'Entre no painel da Intus primeiro.']);
if ($sessao['user_type'] !== 'prof' || (int)$sessao['user_id'] !== 1) _bsResp(403, ['ok' => false, 'erro' => 'Só a conta principal da Intus abre o editor por aqui.']);

require_once __DIR__ . '/../../admin/config.php';
require_once __DIR__ . '/../../admin/_black_cfg.php';
session_name(SESSION_KEY);
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
session_regenerate_id(true);
$_SESSION['black_ate'] = time() + BLACK_PAINEL_SEG;
_bsResp(200, ['ok' => true, 'url' => '/admin/black.php']);
