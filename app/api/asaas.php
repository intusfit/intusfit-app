<?php
/**
 * Cobrança pelo Asaas (Pix, boleto e cartão) para as mensalidades da Intus. Toda a regra está em _asaas.php.
 *
 *   POST ?action=webhook            aviso do Asaas (sem login; cabeçalho asaas-access-token com o token de admin/asaas-keys.php)
 *   GET  ?action=status             {configurado}  (equipe)
 *   POST ?action=cobrar             {idmensalidade} -> {url, valor, vencimento, nova}  (administrador ou quem edita matrículas, só dos seus alunos)
 *   GET  ?action=pendentes          {recebidas[], estornos[], abertas{idmensalidade: {url,...}}}
 *   POST ?action=baixado            {idmensalidade}  depois que a equipe dá a baixa no painel
 *
 * Nada aqui altera mensalidade ou dado de aluno: o webhook só registra o recebimento e a baixa continua sendo feita pelo painel.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, asaas-access-token');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _asaasResp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/_asaas.php';
$cfgBanco = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfgBanco) && isset($cfgBanco['host'])) {
        $pdo = new PDO("mysql:host={$cfgBanco['host']};dbname={$cfgBanco['database']};charset=utf8mb4", $cfgBanco['username'], $cfgBanco['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) { _asaasResp(500, ['ok' => false, 'erro' => 'Falha na conexão com o banco.']); }

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$acao = (string)($_GET['action'] ?? '');

// ── aviso do Asaas (público, autenticado pelo token) ──
if ($acao === 'webhook') {
    if ($metodo !== 'POST') _asaasResp(405, ['ok' => false, 'erro' => 'Use POST.']);
    $tokenAviso = (string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '');
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 400000) _asaasResp(413, ['ok' => false, 'erro' => 'corpo_grande']);
    $j = json_decode($raw, true);
    try { [$st, $corpo] = asaas_webhook($pdo, is_array($j) ? $j : [], $tokenAviso); }
    catch (Throwable $e) { @error_log('[asaas webhook] ' . $e->getMessage()); _asaasResp(500, ['ok' => false, 'erro' => 'falha_ao_processar']); }   // 500: o Asaas tenta de novo
    _asaasResp($st, $corpo);
}

// ── equipe ──
require_once __DIR__ . '/_auth_context.php';
$h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
$tok = preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';
if ($tok === '') _asaasResp(401, ['ok' => false, 'erro' => 'token ausente']);
try {
    $ctx = getAuthContext($pdo, $tok);
    $idu = (int)($ctx['idusuario'] ?? 0);
    if ($idu <= 0 || !empty($ctx['is_aluno'])) _asaasResp(401, ['ok' => false, 'erro' => 'Entre no painel primeiro.']);
    $admin = !empty($ctx['admin']);
    $pode = $admin || (function_exists('usuarioTemPermissao') && usuarioTemPermissao($pdo, $idu, 'mt_editar'));
    if (!$pode) _asaasResp(403, ['ok' => false, 'erro' => 'Seu usuário não pode cobrar matrículas.']);
    $permitidos = $admin ? null : getAtletasDoUsuario($pdo, $idu);

    // Enquanto o Asaas não foi configurado em admin/asaas-keys.php, nada aqui toca o banco (nem cria a tabela): o painel segue como sempre.
    $c = asaas_cfg($pdo);
    if ($c['apikey'] === '') {
        if ($acao === 'status') _asaasResp(200, ['ok' => true, 'configurado' => false, 'ambiente' => $c['ambiente']]);
        if ($acao === 'pendentes') _asaasResp(200, ['ok' => true, 'recebidas' => [], 'estornos' => [], 'abertas' => new stdClass()]);
        if ($acao === 'baixado') _asaasResp(200, ['ok' => true, 'atualizadas' => 0]);
        _asaasResp(503, ['ok' => false, 'erro' => 'O Asaas ainda não foi configurado. Peça ao administrador (admin/asaas-keys.php).']);
    }
    asaas_garantir_tabelas($pdo);

    if ($acao === 'status' && $metodo === 'GET') _asaasResp(200, ['ok' => true, 'configurado' => $c['token'] !== '', 'ambiente' => $c['ambiente']]);
    if ($acao === 'pendentes' && $metodo === 'GET') _asaasResp(200, ['ok' => true] + asaas_para_painel($pdo, $permitidos));

    $b = json_decode((string)file_get_contents('php://input'), true);
    $b = is_array($b) ? $b : [];
    $idm = (int)($b['idmensalidade'] ?? 0);
    if ($metodo !== 'POST' || $idm <= 0 || !in_array($acao, ['cobrar', 'baixado'], true)) _asaasResp(404, ['ok' => false, 'erro' => 'Ação desconhecida.']);

    $st = $pdo->prepare('SELECT * FROM intus_mensalidade WHERE idmensalidade = ?'); $st->execute([$idm]);
    $mens = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mens) _asaasResp(404, ['ok' => false, 'erro' => 'Mensalidade não encontrada.']);
    if ($permitidos !== null && !in_array((int)$mens['idatleta'], array_map('intval', $permitidos), true)) _asaasResp(403, ['ok' => false, 'erro' => 'Este cliente não é seu.']);

    if ($acao === 'baixado') _asaasResp(200, ['ok' => true, 'atualizadas' => asaas_marcar_baixado($pdo, $idm)]);

    // cobrar: dados do aluno (os nomes de coluna variam entre instalações)
    $tab = null;
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tab = $t; break; } catch (Throwable $e) {} }
    if (!$tab) _asaasResp(500, ['ok' => false, 'erro' => 'Tabela de clientes não encontrada.']);
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$tab`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $pick = function (array $c) use ($cols) { foreach ($c as $x) if (in_array($x, $cols, true)) return $x; return null; };
    $cId = $pick(['idatleta', 'idaluno', 'id']); $cNome = $pick(['nome', 'nmatleta', 'nmaluno', 'name']); $cEmail = $pick(['email', 'dsemail']);
    $cTel = $pick(['telefone', 'nrtelefone', 'dstelefone', 'tel', 'celular']); $cCpf = $pick(['cpf', 'nrcpf', 'ds_cpf']);
    if (!$cId || !$cNome) _asaasResp(500, ['ok' => false, 'erro' => 'Não encontrei os dados do cliente.']);
    $sel = "`$cId` AS idatleta, `$cNome` AS nome, " . ($cEmail ? "`$cEmail`" : "''") . " AS email, " . ($cTel ? "`$cTel`" : "''") . " AS tel, " . ($cCpf ? "`$cCpf`" : "''") . " AS cpf";
    $sa = $pdo->prepare("SELECT $sel FROM `$tab` WHERE `$cId` = ? LIMIT 1"); $sa->execute([(int)$mens['idatleta']]);
    $atleta = $sa->fetch(PDO::FETCH_ASSOC);
    if (!$atleta) _asaasResp(404, ['ok' => false, 'erro' => 'Cliente não encontrado.']);

    try { $r = asaas_criar_cobranca($pdo, $mens, $atleta, $idu); }
    catch (RuntimeException $e) {
        $c = (int)$e->getCode();
        if ($e->getMessage() === 'chave_api_ausente') _asaasResp(503, ['ok' => false, 'erro' => 'O Asaas ainda não foi configurado. Peça ao administrador (admin/asaas-keys.php).']);
        if ($e->getMessage() === 'asaas_fora_do_ar' || $e->getMessage() === 'asaas_sem_curl') _asaasResp(502, ['ok' => false, 'erro' => 'Não consegui falar com o Asaas agora. Tente de novo em instantes.']);
        _asaasResp($c >= 400 && $c < 600 ? $c : 422, ['ok' => false, 'erro' => $e->getMessage()]);
    }
    _asaasResp(200, ['ok' => true] + $r);
} catch (Throwable $e) {
    @error_log('[asaas] ' . $e->getMessage());
    _asaasResp(500, ['ok' => false, 'erro' => 'Algo deu errado. Tente de novo.']);
}
