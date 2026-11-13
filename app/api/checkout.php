<?php
/**
 * Checkout próprio da Intus (público, sem login). Toda a regra está em _ck_intus.php.
 *
 *   GET  ?action=info               {produtos[{produto, nome, periodos[{periodo, nome, meses, valor, parcelas_max}]}]}
 *   POST ?action=criar              {produto, periodo, nome, email, telefone, cpf, forma, parcelas} -> Pix {pix{imagem, copia}} ou cartão {url}
 *   GET  ?action=status&t=<token>   {status: aguardando|pago}
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _ckResp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/_ck_intus.php';
$acao = (string)($_GET['action'] ?? '');
if ($acao === 'info') _ckResp(200, ['ok' => true] + ick_info(($_GET['teste'] ?? '') === '1'));

$cfgBanco = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfgBanco) && isset($cfgBanco['host'])) {
        $pdo = new PDO("mysql:host={$cfgBanco['host']};dbname={$cfgBanco['database']};charset=utf8mb4", $cfgBanco['username'], $cfgBanco['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) { _ckResp(500, ['ok' => false, 'erro' => 'Falha na conexão com o banco.']); }

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($acao === 'criar') {
    if ($metodo !== 'POST') _ckResp(405, ['ok' => false, 'erro' => 'Use POST.']);
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 20000) _ckResp(413, ['ok' => false, 'erro' => 'corpo_grande']);
    $j = json_decode($raw, true);
    $ip = substr((string)intusClientIp(), 0, 45);
    [$st, $corpo] = ick_criar($pdo, is_array($j) ? $j : [], $ip);
    _ckResp($st, $corpo);
}
if ($acao === 'confirmar_info' || $acao === 'confirmar') {
    $tk = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
    if ($acao === 'confirmar_info') { [$st, $corpo] = ick_conf_info($pdo, $tk); _ckResp($st, $corpo); }
    if ($metodo !== 'POST') _ckResp(405, ['ok' => false, 'erro' => 'Use POST.']);
    $j = json_decode((string)file_get_contents('php://input'), true);
    [$st, $corpo] = ick_conf_escolher($pdo, $tk, (string)(is_array($j) ? ($j['manter'] ?? '') : ''));
    _ckResp($st, $corpo);
}
if ($acao === 'status') {
    [$st, $corpo] = ick_status($pdo, preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? '')));
    _ckResp($st, $corpo);
}
_ckResp(404, ['ok' => false, 'erro' => 'rota_desconhecida']);
