<?php
/**
 * Central de conteúdo (tela em /conteudo/): API. Toda a regra mora em _conteudo.php.
 *
 * Só equipe com login: administrador ou quem tem a chave cut_ver (a mesma do Intus Cut). Aluno recebe 403.
 *   GET  ?action=inicio                  tudo que a tela precisa: configuração, peças (resumo), eventos, ofertas, links, Intus Cut, painel, reciclagem e negócio
 *   GET  ?action=peca&id=ID              a peça inteira (roteiro, legenda, histórico) e o que falta para a etapa dela
 *   GET  ?action=painel                  só os números (para atualizar o topo)
 *   GET  ?action=exportar                fila "pronto para postar" em JSON (o que a automação da 2ª etapa vai ler)
 *   POST ?action=peca_salvar   {peca}    cria ou atualiza; mudar a etapa confere as exigências (422 com a lista); {forcar:true} só administrador
 *   POST ?action=peca_excluir  {id}      só administrador
 *   POST ?action=peca_duplicar {id}
 *   POST ?action=reciclar      {id}      nova peça em "ideia" a partir de uma publicada
 *   POST ?action=publicar      {id, link_publicado}
 *   POST ?action=evento_salvar {evento} | evento_excluir {id}
 *   POST ?action=cfg_salvar    {cfg}     marcas, formatos, tipos, etapas do funil, pessoas e prazos (só administrador)
 *   POST ?action=cfg_restaurar           volta ao padrão (só administrador)
 *   POST ?action=ofertas_salvar {itens} | links_salvar {itens}   (só administrador)
 *   POST ?action=analisar      {texto}   confere um texto com as regras da Intus
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _contResp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function _contErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus conteudo ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

$cfgDb = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfgDb) && isset($cfgDb['host'])) {
        $pdo = new PDO("mysql:host={$cfgDb['host']};dbname={$cfgDb['database']};charset=utf8mb4", $cfgDb['username'], $cfgDb['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) {
    _contResp(500, ['ok' => false, 'erro' => 'falha conexao', 'detalhe' => _contErro($e)]);
}

require_once __DIR__ . '/_auth_context.php';
require_once __DIR__ . '/_conteudo.php';
if (!function_exists('bearerToken')) {
    function bearerToken() {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
        return preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';
    }
}

function _contInicio(PDO $pdo, array $ctx): array {
    $hoje = cont_hoje();
    $cfg = cont_cfg($pdo); $ofertas = cont_ofertas($pdo); $pecas = cont_pecas($pdo); $eventos = cont_eventos($pdo);
    $resumo = [];
    foreach ($pecas as $p) $resumo[] = cont_peca_resumo($p, $cfg, $ofertas, $hoje);
    usort($resumo, fn($a, $b) => strcmp((string)($b['atualizado_em'] ?? ''), (string)($a['atualizado_em'] ?? '')));
    return ['ok' => true, 'hoje' => $hoje, 'status' => CONT_STATUS, 'canais' => CONT_CANAIS, 'papeis' => CONT_PAPEIS, 'tipos_evento' => CONT_TIPOS_EVENTO,
            'cfg' => $cfg, 'pecas' => $resumo, 'eventos' => array_values($eventos), 'ofertas' => $ofertas, 'links' => cont_links($pdo), 'cut' => cont_do_cut($pdo),
            'painel' => cont_painel($pecas, $eventos, $cfg, $ofertas, $hoje), 'reciclagem' => cont_reciclagem($pecas, $cfg, $hoje), 'negocio' => cont_negocio($pdo, $hoje, 30),
            'eu' => ['id' => (int)$ctx['idusuario'], 'nome' => (string)($ctx['nmusuario'] ?? ''), 'admin' => !empty($ctx['admin'])]];
}

try {
    $ctx = getAuthContext($pdo, bearerToken());
    if ((int)($ctx['idusuario'] ?? 0) <= 0 || !empty($ctx['is_aluno'])) _contResp(401, ['ok' => false, 'erro' => 'Entre no painel da Intus para usar a Central de conteúdo.']);
    if (!cont_pode($pdo, $ctx)) _contResp(403, ['ok' => false, 'erro' => 'Seu usuário não tem acesso à Central de conteúdo. Peça ao administrador a permissão "Usar o Intus Cut".']);
    $admin = !empty($ctx['admin']);
    $action = (string)($_GET['action'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $soAdmin = fn() => $admin ?: _contResp(403, ['ok' => false, 'erro' => 'Só o administrador faz isso.']);

    if ($metodo === 'GET') {
        if ($action === 'inicio') _contResp(200, _contInicio($pdo, $ctx));
        if ($action === 'painel') {
            $hoje = cont_hoje(); $cfg = cont_cfg($pdo); $ofertas = cont_ofertas($pdo); $pecas = cont_pecas($pdo);
            _contResp(200, ['ok' => true, 'painel' => cont_painel($pecas, cont_eventos($pdo), $cfg, $ofertas, $hoje), 'reciclagem' => cont_reciclagem($pecas, $cfg, $hoje), 'negocio' => cont_negocio($pdo, $hoje, 30)]);
        }
        if ($action === 'peca') {
            $pecas = cont_pecas($pdo); $id = (string)($_GET['id'] ?? '');
            if (!isset($pecas[$id])) _contResp(404, ['ok' => false, 'erro' => 'peça não encontrada']);
            $p = $pecas[$id]; $hoje = cont_hoje();
            _contResp(200, ['ok' => true, 'peca' => $p, 'pendencias' => cont_pendencias($p, cont_cfg($pdo), cont_ofertas($pdo), (string)$p['status'], $hoje)]);
        }
        if ($action === 'exportar') _contResp(200, ['ok' => true] + cont_exportar($pdo));
        _contResp(404, ['ok' => false, 'erro' => 'ação desconhecida']);
    }
    if ($metodo !== 'POST') _contResp(405, ['ok' => false, 'erro' => 'método inválido']);
    $corpo = json_decode(file_get_contents('php://input'), true);
    if (!is_array($corpo)) $corpo = [];

    switch ($action) {
        case 'peca_salvar': {
            $in = $corpo['peca'] ?? null;
            if (!is_array($in)) _contResp(400, ['ok' => false, 'erro' => 'peça inválida']);
            $r = cont_salvar_peca($pdo, $in, $ctx, $admin && !empty($corpo['forcar']));
            _contResp($r['ok'] ? 200 : ($r['codigo'] ?? 400), $r);
        }
        case 'peca_excluir': {
            $soAdmin();
            _contResp(200, ['ok' => cont_excluir_peca($pdo, (string)($corpo['id'] ?? ''))]);
        }
        case 'peca_duplicar': { $r = cont_duplicar_peca($pdo, (string)($corpo['id'] ?? ''), $ctx); _contResp($r['ok'] ? 200 : ($r['codigo'] ?? 400), $r); }
        case 'reciclar':       { $r = cont_reciclar_peca($pdo, (string)($corpo['id'] ?? ''), $ctx); _contResp($r['ok'] ? 200 : ($r['codigo'] ?? 400), $r); }
        case 'publicar':       { $r = cont_publicar_peca($pdo, (string)($corpo['id'] ?? ''), $corpo, $ctx); _contResp($r['ok'] ? 200 : ($r['codigo'] ?? 400), $r); }
        case 'evento_salvar': {
            $in = $corpo['evento'] ?? null;
            if (!is_array($in)) _contResp(400, ['ok' => false, 'erro' => 'evento inválido']);
            $r = cont_salvar_evento($pdo, $in, $ctx);
            _contResp($r['ok'] ? 200 : ($r['codigo'] ?? 400), $r);
        }
        case 'evento_excluir': _contResp(200, ['ok' => cont_excluir_evento($pdo, (string)($corpo['id'] ?? ''))]);
        case 'cfg_salvar': {
            $soAdmin();
            $c = cont_cfg_limpar($corpo['cfg'] ?? null);
            if (isset($c['erro'])) _contResp(400, ['ok' => false, 'erro' => $c['erro']]);
            cut_gravar($pdo, 'cont_cfg', $c);
            _contResp(200, ['ok' => true, 'cfg' => cont_cfg($pdo)]);
        }
        case 'cfg_restaurar': { $soAdmin(); cut_apagar_chave($pdo, 'cont_cfg'); _contResp(200, ['ok' => true, 'cfg' => cont_cfg($pdo)]); }
        case 'ofertas_salvar': {
            $soAdmin();
            $r = cont_ofertas_limpar($corpo['itens'] ?? null, cont_cfg($pdo));
            if (isset($r['erro'])) _contResp(400, ['ok' => false, 'erro' => $r['erro']]);
            cut_gravar($pdo, 'cont_ofertas', $r['ok']);
            _contResp(200, ['ok' => true, 'ofertas' => cont_ofertas($pdo)]);
        }
        case 'links_salvar': {
            $soAdmin();
            $r = cont_links_limpar($corpo['itens'] ?? null);
            if (isset($r['erro'])) _contResp(400, ['ok' => false, 'erro' => $r['erro']]);
            cut_gravar($pdo, 'cont_links', $r['ok']);
            _contResp(200, ['ok' => true, 'links' => cont_links($pdo)]);
        }
        case 'analisar': {
            $p = ['gancho' => '', 'legenda' => cont_txt($corpo['texto'] ?? '', 4000), 'cta' => ''];
            _contResp(200, ['ok' => true, 'itens' => cont_copy($p)]);
        }
        default: _contResp(404, ['ok' => false, 'erro' => 'ação desconhecida']);
    }
} catch (Throwable $e) {
    _contResp(500, ['ok' => false, 'erro' => 'erro no servidor', 'detalhe' => _contErro($e)]);
}
