<?php
/**
 * Análise das fotos de avaliação postural (qual das 18 posições do Protocolo INTUS é cada foto e onde a
 * pessoa está na foto). A detecção é feita no navegador do professor; aqui só se guarda o resultado.
 * Regras e formato: app/api/_fotos_analise.php.
 *
 *   GET  ?action=listar&idatleta=N                       -> {ok, itens:[{chave,pose,conf,origem,w,h,bb,lm,...}]}
 *   POST ?action=gravar {idatleta, itens:[{chave,idavaliacao,pose,conf,origem,w,h,bb,lm}]}  -> {ok, gravadas}
 *   POST ?action=pose   {idatleta, chave, pose|null, idavaliacao?}   -> {ok}   (correção manual, vale para sempre)
 *   POST ?action=remover {idatleta, chave}                            -> {ok}   (a foto saiu da avaliação)
 *
 * Só professor logado, e (se não for admin) só aluno da própria carteira, como em atletas.php/feedbacks.php.
 */
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_fotos_analise.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _faResponder(int $code, array $dados): void { http_response_code($code); echo json_encode($dados); exit; }

function _faBearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    return preg_match('/Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : '';
}

$cfg = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new Exception('sem credenciais');
    }
} catch (Throwable $e) {
    @error_log('[intus fotos_analise] conexao: ' . $e->getMessage());
    _faResponder(500, ['ok' => false, 'erro' => 'falha conexao']);
}

require_once __DIR__ . '/_auth_context.php';
$ctx = getAuthContext($pdo, _faBearer());
if ((int)($ctx['idusuario'] ?? 0) <= 0 || !empty($ctx['is_aluno'])) _faResponder(401, ['ok' => false, 'erro' => 'sem sessao valida de professor']);
$idprofessor = (int)$ctx['idusuario'];
$ehAdmin = !empty($ctx['admin']);

$action = $_GET['action'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$idatleta = (int)($body['idatleta'] ?? $_GET['idatleta'] ?? 0);
if ($idatleta <= 0) _faResponder(400, ['ok' => false, 'erro' => 'idatleta obrigatorio']);
if (!$ehAdmin && !in_array($idatleta, getAtletasDoUsuario($pdo, $idprofessor), true)) _faResponder(403, ['ok' => false, 'erro' => 'aluno fora da sua carteira']);

try {
    if ($action === 'listar' && $metodo === 'GET') {
        _faResponder(200, ['ok' => true, 'itens' => fotoAnaliseListar($pdo, $idatleta)]);
    }
    if ($metodo !== 'POST') _faResponder(405, ['ok' => false, 'erro' => 'metodo invalido']);
    if ($action === 'gravar') {
        $itens = is_array($body['itens'] ?? null) ? $body['itens'] : [];
        _faResponder(200, ['ok' => true, 'gravadas' => fotoAnaliseGravar($pdo, $idatleta, $itens)]);
    }
    if ($action === 'pose') {
        $pose = isset($body['pose']) && $body['pose'] !== null && $body['pose'] !== '' ? (int)$body['pose'] : null;
        $ok = fotoAnaliseDefinirPose($pdo, $idatleta, (string)($body['chave'] ?? ''), $pose, isset($body['idavaliacao']) ? (int)$body['idavaliacao'] : null);
        _faResponder($ok ? 200 : 400, $ok ? ['ok' => true] : ['ok' => false, 'erro' => 'foto ou posicao invalida']);
    }
    if ($action === 'remover') {
        fotoAnaliseRemover($pdo, $idatleta, (string)($body['chave'] ?? ''));
        _faResponder(200, ['ok' => true]);
    }
    _faResponder(400, ['ok' => false, 'erro' => 'acao desconhecida']);
} catch (Throwable $e) {
    $ref = substr(hash('crc32b', $e->getMessage() . $e->getFile() . $e->getLine()), 0, 8);
    @error_log('[intus fotos_analise ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    _faResponder(500, ['ok' => false, 'erro' => 'falha interna', 'detalhe' => 'ref ' . $ref]);
}
