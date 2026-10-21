<?php
/**
 * Extras: conteúdos bônus do app do aluno (aulas gravadas, lives, treinos em casa, módulos...). Regra em _extras.php.
 *
 * ALUNO (Bearer de aluno):
 *   GET  ?action=lista                      só os módulos e conteúdos que ESTE aluno pode ver (sem configuração de acesso)
 * ADMINISTRADOR (Bearer):
 *   GET  ?action=admin                      catálogo inteiro com as regras de acesso e quantos alunos cada regra alcança
 *   POST ?action=categoria_salvar           {categoria:{id?, titulo, descricao, emoji, cor, capa, publicado, visibilidade:{modo, ids[]}}}
 *   POST ?action=categoria_excluir          {id}
 *   POST ?action=item_salvar                {categoria, item:{id?, titulo, descricao, tipo, url, duracao, data, publicado, visibilidade|null}}
 *   POST ?action=item_excluir               {categoria, id}
 *   POST ?action=reordenar                  {tipo:'categoria'|'item', categoria?, ids:[...]}
 *   POST ?action=importar_cdd               traz do CDD os módulos que ainda não estão no catálogo (idempotente; nunca sobrescreve o que tem conteúdo)
 * Excluir é ação do painel, com confirmação na tela. Nenhum outro perfil (professor, aluno) escreve aqui.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _exResp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function _exErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus extras ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

$cfg = @include __DIR__ . '/../config/db.php';
$pdo = null;
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) {
    _exResp(500, ['ok' => false, 'error' => 'falha conexao', 'detalhe' => _exErro($e)]);
}

require_once __DIR__ . '/_auth_context.php';
require_once __DIR__ . '/_extras.php';

if (!function_exists('bearerToken')) {
    function bearerToken() {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
        return preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';
    }
}

try {
    $ctx = getAuthContext($pdo, bearerToken());
    if ((int)($ctx['idusuario'] ?? 0) <= 0) _exResp(401, ['ok' => false, 'error' => 'token ausente ou invalido']);
    $ehAluno = !empty($ctx['is_aluno']);
    $admin = !empty($ctx['admin']);
    $action = (string)($_GET['action'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'lista') {
        if (!$ehAluno) _exResp(403, ['ok' => false, 'error' => 'esta rota é do aluno']);
        $id = (int)($ctx['idatleta'] ?? $ctx['idusuario']);
        $dados = extras_ler($pdo);
        _exResp(200, ['ok' => true, 'categorias' => extras_para_aluno($dados, extras_perfil_aluno($pdo, $id), $id)]);
    }

    // daqui para baixo: só administrador
    if ($ehAluno || !$admin) _exResp(403, ['ok' => false, 'error' => 'somente administrador']);

    if ($action === 'admin' && $metodo === 'GET') {
        $dados = extras_ler($pdo);
        _exResp(200, ['ok' => true, 'categorias' => $dados['categorias'], 'salvo' => !empty($dados['salvo']), 'alcance' => extras_alcance($pdo),
                      'modos' => EXTRAS_ROTULO_MODO, 'limites' => ['modulos' => EXTRAS_MAX_MODULOS, 'itens' => EXTRAS_MAX_ITENS]]);
    }

    if ($metodo !== 'POST') _exResp(405, ['ok' => false, 'error' => 'use POST']);
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen($raw) > 512 * 1024) _exResp(413, ['ok' => false, 'error' => 'envio grande demais']);
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) $d = [];

    switch ($action) {
        case 'categoria_salvar':
            _exResp(200, ['ok' => true, 'categoria' => extras_salvar_categoria($pdo, (array)($d['categoria'] ?? []))]);
        case 'categoria_excluir':
            _exResp(200, ['ok' => extras_excluir_categoria($pdo, (string)($d['id'] ?? ''))]);
        case 'item_salvar':
            _exResp(200, ['ok' => true, 'item' => extras_salvar_item($pdo, (string)($d['categoria'] ?? ''), (array)($d['item'] ?? []))]);
        case 'item_excluir':
            _exResp(200, ['ok' => extras_excluir_item($pdo, (string)($d['categoria'] ?? ''), (string)($d['id'] ?? ''))]);
        case 'importar_cdd':
            _exResp(200, ['ok' => true] + extras_importar_cdd($pdo));
        case 'reordenar':
            extras_reordenar($pdo, (string)($d['tipo'] ?? ''), (string)($d['categoria'] ?? ''), (array)($d['ids'] ?? []));
            _exResp(200, ['ok' => true]);
        default:
            _exResp(400, ['ok' => false, 'error' => 'acao desconhecida']);
    }
} catch (InvalidArgumentException $e) {
    _exResp(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    _exResp(500, ['ok' => false, 'error' => 'erro interno (' . _exErro($e) . ')']);
}
