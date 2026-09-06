<?php
/**
 * Auxiliary endpoint: returns professor profile (admin flag + permissions)
 * given a valid email. Used by login.html after successful auth.php login.
 *
 * GET /api/profile_intus.php?email=...
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';

$email = trim($_GET['email'] ?? '');
if ($email === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'email obrigatorio']);
    exit;
}

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
    echo json_encode(['ok' => false, 'erro' => 'falha conexao']);
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

// Este endpoint devolve id, nome, e-mail, se e admin e o mapa de permissoes de
// um professor — a partir de um e-mail na URL, sem token nenhum. Servia de
// reconhecimento pronto: descobre quais e-mails existem e qual deles e o admin.
// O comentario do topo dizia que era "usado apos login bem-sucedido", mas nada
// verificava isso.
$_sessProf = _intusExigeSessao($pdo, true);

// Detectar tabela de professores/usuarios
$tabela = null;
$col_id = $col_nome = $col_email = $col_admin = $col_perms = null;
foreach (['professor', 'professores', 'usuario', 'usuarios'] as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        $tabela = $t;
        break;
    } catch (Throwable $e) { continue; }
}
if (!$tabela) {
    echo json_encode(['ok' => false, 'erro' => 'tabela nao encontrada']);
    exit;
}

// Mapear colunas
$cols = $pdo->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_COLUMN);
$colSet = array_flip($cols);
$col_id = isset($colSet['idprofessor']) ? 'idprofessor' : (isset($colSet['idusuario']) ? 'idusuario' : $cols[0]);
$col_nome = isset($colSet['nmprofessor']) ? 'nmprofessor' : (isset($colSet['nome']) ? 'nome' : null);
$col_email = isset($colSet['email']) ? 'email' : null;
$col_admin = isset($colSet['tpacesso']) ? 'tpacesso' : (isset($colSet['admin']) ? 'admin' : null);
$col_perms = isset($colSet['permissoes']) ? 'permissoes' : null;

if (!$col_email) {
    echo json_encode(['ok' => false, 'erro' => 'schema incompativel']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
$stmt->execute([strtolower($email)]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo json_encode(['ok' => false, 'erro' => 'nao encontrado']);
    exit;
}

$is_admin = false;
if ($col_admin) {
    $val = $row[$col_admin] ?? '';
    $is_admin = ($val === 'A' || $val === 'S' || $val === '1' || $val === 1 || $val === true);
}
// Master account (ID 1) is always admin
if ((int)$row[$col_id] === 1) $is_admin = true;

// Permissões granulares (formato nt_incluir, ex_editar, etc.)
$perms = null;
if ($col_perms && !empty($row[$col_perms])) {
    $tmp = json_decode($row[$col_perms], true);
    if (is_array($tmp)) $perms = $tmp;
}

if ($is_admin || !$perms) {
    // Admin recebe tudo; sem permissões definidas: todas true para admin, null para outros
    if ($is_admin) {
        $perms = null; // frontend dará _permsAllTrue() para admin
    }
}

$result = [
    'ok' => true,
    'id' => (int)$row[$col_id],
    'nome' => $col_nome ? ($row[$col_nome] ?? '') : '',
    'email' => $row[$col_email],
    'admin' => $is_admin ? 'S' : 'N',
    'tpacesso' => $col_admin ? ($row[$col_admin] ?? 'P') : 'P',
];
if ($perms !== null) {
    $result['permissoes'] = $perms;
}
echo json_encode($result);
