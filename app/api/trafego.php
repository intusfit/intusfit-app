<?php
/**
 * Analytics de captação (visitas e cliques das páginas públicas). Regra e SQL em _trafego.php.
 *
 * PÚBLICO (sem login, chamado pelo trafego.js das páginas; aceita corpo text/plain para o sendBeacon):
 *   POST ?action=visita   {vid, sid, pagina, referrer, utm_source/medium/campaign/content/term, ref, w}
 *   POST ?action=evento   {vid, sid, pagina, evento, rotulo?, valor?}
 * ADMINISTRADOR (Bearer):
 *   GET    ?action=relatorio&de=AAAA-MM-DD&ate=AAAA-MM-DD[&pagina=/teste-gratis.html]
 *   GET    ?action=agora            (visitantes dos últimos 30 min e últimas visitas, para atualizar a cada poucos segundos)
 *   GET    ?action=limpar_previa&dias=N   (só conta o que a limpeza apagaria; não apaga nada)
 *   DELETE ?action=limpar&dias=N&confirmo=APAGAR&esperadas=T   (apaga o que tem mais de N dias, mínimo 365, e só se o total T for o da prévia; ação do painel, com várias confirmações)
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
header('Cache-Control: no-store');

// Detalhe de erro vai para o log do servidor, nunca para o cliente.
function _trErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus trafego ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

$cfg = @include __DIR__ . '/../config/db.php';
$pdo = null;
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) {
    // A medição nunca deve atrapalhar a página: erro de banco responde 200 sem corpo útil para quem só envia.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') { echo json_encode(['ok' => false]); exit; }
    http_response_code(500); echo json_encode(['error' => 'falha conexao', 'detalhe' => _trErro($e)]); exit;
}

require_once __DIR__ . '/_ip.php';
require_once __DIR__ . '/_trafego.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// ── PÚBLICO: só grava ──
if ($method === 'POST' && ($action === 'visita' || $action === 'evento')) {
    $raw = (string)file_get_contents('php://input', false, null, 0, 8192);   // payload minúsculo: 8 KB é de sobra
    $b = json_decode($raw, true);
    if (!is_array($b)) { echo json_encode(['ok' => false]); exit; }
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $r = $action === 'visita'
        ? trRegistrarVisita($pdo, $b, $ua, intusClientGeo(), null, (string)($_SERVER['HTTP_HOST'] ?? ''))
        : trRegistrarEvento($pdo, $b, $ua);
    echo json_encode(['ok' => $r['ok']]);   // não devolve o id nem o motivo: ninguém precisa saber
    exit;
}

// ── ADMINISTRADOR ──
if (!in_array($action, ['relatorio', 'agora', 'limpar', 'limpar_previa'], true)) { http_response_code(400); echo json_encode(['error' => 'acao invalida']); exit; }
$h = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
elseif (function_exists('apache_request_headers')) { foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; } }
$tok = preg_match('/Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : '';
if ($tok === '') { http_response_code(401); echo json_encode(['error' => 'token ausente']); exit; }
require_once __DIR__ . '/_auth_context.php';
$ctx = getAuthContext($pdo, $tok);
if ((int)($ctx['idusuario'] ?? 0) <= 0) { http_response_code(401); echo json_encode(['error' => 'token invalido']); exit; }
if (!empty($ctx['is_aluno']) || empty($ctx['admin'])) { http_response_code(403); echo json_encode(['error' => 'acesso restrito ao administrador']); exit; }

try {
    if (!trGarantirTabelas($pdo)) { http_response_code(500); echo json_encode(['error' => 'tabelas indisponiveis']); exit; }
    if ($action === 'limpar') {
        if ($method !== 'DELETE') { http_response_code(405); echo json_encode(['error' => 'use DELETE']); exit; }
        $dias = (int)($_GET['dias'] ?? 0);
        if ($dias < TR_LIMPEZA_MIN_DIAS) { http_response_code(400); echo json_encode(['error' => 'minimo 365 dias']); exit; }
        if (($_GET['confirmo'] ?? '') !== 'APAGAR') { http_response_code(400); echo json_encode(['error' => 'confirmacao ausente']); exit; }
        if (!isset($_GET['esperadas']) || !ctype_digit((string)$_GET['esperadas'])) { http_response_code(400); echo json_encode(['error' => 'total esperado ausente']); exit; }
        $n = trLimparAntigos($pdo, $dias, (int)$_GET['esperadas']);
        if ($n === null) { http_response_code(409); echo json_encode(['error' => 'o total mudou desde a previa: veja de novo antes de apagar']); exit; }
        @error_log('[intus trafego] limpeza: ' . $n . ' linhas apagadas (mais de ' . $dias . ' dias) por usuario #' . (int)$ctx['idusuario']);
        echo json_encode(['ok' => true, 'apagadas' => $n]);
        exit;
    }
    if ($action === 'limpar_previa') {
        if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'use GET']); exit; }
        echo json_encode(trPreviaLimpeza($pdo, (int)($_GET['dias'] ?? 0)));
        exit;
    }
    if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'use GET']); exit; }
    $tbl = null;
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tbl = $t; break; } catch (Throwable $e) {} }
    $hoje = trAgora()[1];
    $de = trDataValida($_GET['de'] ?? '') ? $_GET['de'] : date('Y-m-d', strtotime($hoje . ' -29 days'));
    $ate = trDataValida($_GET['ate'] ?? '') ? $_GET['ate'] : $hoje;
    if ($ate < $de) { [$de, $ate] = [$ate, $de]; }
    if ((strtotime($ate) - strtotime($de)) / 86400 > 366) $de = date('Y-m-d', strtotime($ate . ' -366 days'));
    $pagina = isset($_GET['pagina']) && $_GET['pagina'] !== '' ? trPagina($_GET['pagina']) : null;
    if ($action === 'agora') {
        $rel = trRelatorio($pdo, $hoje, $hoje, null, $tbl);
        echo json_encode(['agora' => $rel['agora'], 'hoje' => $rel['kpis']]);
        exit;
    }
    echo json_encode(trRelatorio($pdo, $de, $ate, $pagina, $tbl));
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error' => 'falha no relatorio', 'detalhe' => _trErro($e)]);
}
