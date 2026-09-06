<?php
/**
 * One-shot script: remove atletas duplicados (mesmo nome) mantendo o de menor ID.
 * Acesso protegido por token Bearer (aceita qualquer token válido, como os demais endpoints).
 *
 * GET /api/dedup_atletas.php          -> lista duplicados (dry-run)
 * POST /api/dedup_atletas.php         -> exclui duplicados
 */

header('Content-Type: application/json; charset=utf-8');
// CORS aberto ('*') permitia que qualquer site na internet disparasse esta
// exclusao em massa pelo navegador de um visitante. Lista fixa agora.
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// Auth
function bearerToken() {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}
if (bearerToken() === '') {
    http_response_code(401);
    echo json_encode(['error' => 'token ausente']);
    exit;
}

// Conexão
$cfg = @include __DIR__ . '/../config/db.php';
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
    echo json_encode(['error' => 'falha conexao']);
    exit;
}

// ── PORTA DE ENTRADA ────────────────────────────────────────────────────────
// Este arquivo nao exigia autenticacao NENHUMA. Verificado no ar em 20/08/2026.
// A identidade agora vem da sessao real gravada em intus_sessions — nunca do
// formato do texto do token, nunca do corpo da requisicao.
require_once __DIR__ . '/_sessions.php';
function _intusExigeSessao(PDO $pdo, bool $soProfessor = true, bool $soAdmin = false): array {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    $tok = preg_match('/Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : '';
    if ($tok === '') { http_response_code(401); echo json_encode(['error' => 'token ausente']); exit; }

    $sess = function_exists('validateSession') ? validateSession($pdo, $tok) : null;
    if (!$sess || !empty($sess['legacy'])) {
        http_response_code(401);
        echo json_encode(['error' => 'token invalido']);
        exit;
    }
    if ($soProfessor && ($sess['user_type'] ?? '') === 'aluno') {
        http_response_code(403);
        echo json_encode(['error' => 'restrito ao professor']);
        exit;
    }
    if ($soAdmin && empty($sess['admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'restrito ao administrador']);
        exit;
    }
    return $sess;
}

// Este script apaga atletas em massa e o proprio cabecalho admitia aceitar
// "qualquer token valido" — na pratica, qualquer string servia. Ele agrupa por
// nome igual, entao dois clientes homonimos de verdade viravam perda
// permanente. Restrito ao administrador. Sendo script de uso unico, o certo e
// apagar o arquivo do servidor quando a limpeza terminar.
$_sessDedup = _intusExigeSessao($pdo, true, true);


// Detectar tabela
$tabela = null;
foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tabela = $t; break; } catch (Throwable $e) {}
}
if (!$tabela) { http_response_code(500); echo json_encode(['error' => 'tabela nao encontrada']); exit; }

// Detectar colunas
$cols = [];
$st = $pdo->query("SHOW COLUMNS FROM `$tabela`");
while ($r = $st->fetch(PDO::FETCH_ASSOC)) $cols[] = $r['Field'];

$col_id = null;
foreach (['idatleta','idaluno','id'] as $c) { if (in_array($c, $cols)) { $col_id = $c; break; } }
$col_nome = null;
foreach (['nome','nmatleta','nmaluno','name'] as $c) { if (in_array($c, $cols)) { $col_nome = $c; break; } }

if (!$col_id || !$col_nome) {
    http_response_code(500);
    echo json_encode(['error' => 'colunas id/nome nao encontradas']);
    exit;
}

// Encontrar duplicados: agrupa por LOWER(TRIM(nome)), mantém menor ID
$sql = "SELECT LOWER(TRIM(`$col_nome`)) as nome_norm, MIN(`$col_id`) as keep_id, GROUP_CONCAT(`$col_id` ORDER BY `$col_id`) as all_ids, COUNT(*) as total
        FROM `$tabela`
        GROUP BY nome_norm
        HAVING total > 1";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$duplicados = [];
$idsToDelete = [];

foreach ($rows as $r) {
    $allIds = array_map('intval', explode(',', $r['all_ids']));
    $keepId = (int)$r['keep_id'];
    $removeIds = array_filter($allIds, fn($id) => $id !== $keepId);
    $duplicados[] = [
        'nome' => $r['nome_norm'],
        'manter_id' => $keepId,
        'remover_ids' => array_values($removeIds),
        'total' => (int)$r['total'],
    ];
    $idsToDelete = array_merge($idsToDelete, $removeIds);
}

$action = $_GET['action'] ?? 'dryrun';

if ($action === 'dryrun') {
    echo json_encode([
        'dry_run' => true,
        'duplicados' => $duplicados,
        'total_a_remover' => count($idsToDelete),
    ]);
    exit;
}

if ($action === 'executar') {
    if (empty($idsToDelete)) {
        echo json_encode(['ok' => true, 'removidos' => 0, 'mensagem' => 'Nenhum duplicado encontrado']);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
    $del = $pdo->prepare("DELETE FROM `$tabela` WHERE `$col_id` IN ($placeholders)");
    $del->execute($idsToDelete);
    $removed = $del->rowCount();
    echo json_encode([
        'ok' => true,
        'removidos' => $removed,
        'ids_removidos' => $idsToDelete,
        'duplicados_resolvidos' => $duplicados,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'use ?action=dryrun ou ?action=executar']);
