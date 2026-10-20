<?php
/**
 * Laboratório do WhatsApp: API da tela /atendimento/teste.html (somente administrador).
 *
 * GET  ?action=estado                 contato de teste, mensagens com status de entrega, agenda, funis e modo (auto/manual) de cada passo
 * POST ?action=conexao                pergunta à Meta se o token e o número funcionam (não mostra nem grava o token)
 * POST ?action=contato_criar          cria o contato Fulano Teste (45 99957-4107) com consentimento "teste-admin"
 * POST ?action=enviar_hello           manda o template hello_world da Meta (prova de que o número chega ao aparelho)
 * POST ?action=enviar_passo           {funil, msg, via: auto|template|texto} envia uma mensagem do funil agora (manual)
 * POST ?action=enviar_texto           {texto} texto livre, só dentro da janela de 24 h
 * POST ?action=config_salvar          {mestre, passos:{"funil/msg":"auto"}} liga/desliga a automação e escolhe auto ou manual por passo
 * POST ?action=agendar                {funil, intervalo_min} coloca o funil na agenda do contato de teste (intervalo 0 = agenda de verdade)
 * POST ?action=agenda_cancelar        cancela o que está pendente na agenda do contato de teste
 * POST ?action=processar              roda a fila agora (só envia o que está em modo automático e com a chave geral ligada)
 * POST ?action=reiniciar              apaga a agenda do contato de teste e libera o "saiu", para repetir o teste
 *
 * Toda regra mora em _wa_fila.php e _wa.php. Aqui só autenticação, entrada e saída.
 * Este endpoint só opera no contato de teste; nenhuma ação aceita telefone ou id de outro contato.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/_wa_fila.php';

if (!function_exists('bearerToken')) {
    function bearerToken() {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
        return preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';
    }
}

function wat_resp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function wat_entrada(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > 200 * 1024) wat_resp(413, ['ok' => false, 'erro' => 'Envio grande demais.']);
    $a = json_decode($raw, true);
    return is_array($a) ? $a : [];
}

$pdo = wa_conectar();
if (!$pdo) wat_resp(503, ['ok' => false, 'erro' => 'Banco indisponível.']);

try {
    require_once __DIR__ . '/_auth_context.php';
    $ctx = getAuthContext($pdo, bearerToken());
    if ((int)($ctx['idusuario'] ?? 0) <= 0 || !empty($ctx['is_aluno'])) wat_resp(401, ['ok' => false, 'erro' => 'Sessão inválida. Entre de novo no painel.']);
    if (empty($ctx['admin'])) wat_resp(403, ['ok' => false, 'erro' => 'Esta área é só do administrador.']);

    wa_garantir_tabelas($pdo);
    atd_garantir_config($pdo);

    $action = (string)($_GET['action'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($action !== 'estado' && $metodo !== 'POST') wat_resp(405, ['ok' => false, 'erro' => 'Use POST.']);
    $d = $metodo === 'POST' ? wat_entrada() : [];

    $cfgWa = wa_config_whatsapp($pdo);
    $exigirMeta = function () use ($cfgWa) {
        if (!$cfgWa) wat_resp(422, ['ok' => false, 'erro' => 'O WhatsApp ainda não está configurado. Falta cadastrar o token e o número em admin/whatsapp-keys.php.']);
        return $cfgWa;
    };
    $exigirContato = function () use ($pdo) {
        $c = waf_contato_teste_existente($pdo);
        if (!$c) wat_resp(422, ['ok' => false, 'erro' => 'Crie primeiro o contato de teste.']);
        return $c;
    };

    switch ($action) {
        case 'estado':
            wat_resp(200, ['ok' => true] + waf_estado($pdo));

        case 'conexao': {
            $cfg = $exigirMeta();
            $r = wa_testar_conexao($cfg);
            wat_resp(200, ['ok' => true, 'conexao' => $r]);
        }
        case 'contato_criar': {
            $c = waf_contato_teste($pdo);
            wat_resp(200, ['ok' => true, 'contato' => ['idcontato' => (int)$c['idcontato'], 'nome' => $c['nome'], 'telefone' => $c['telefone']]]);
        }
        case 'enviar_hello': {
            $cfg = $exigirMeta(); $c = $exigirContato();
            $r = waf_enviar_hello($pdo, $cfg, $c);
            wat_resp($r['ok'] ? 200 : 422, ['ok' => $r['ok'], 'resultado' => $r, 'erro' => $r['erro'] ?? null]);
        }
        case 'enviar_passo': {
            $cfg = $exigirMeta(); $c = $exigirContato();
            $funil = atd_funil_por_id($pdo, (string)($d['funil'] ?? ''));
            $msg = $funil ? waf_achar_mensagem($funil, (string)($d['msg'] ?? '')) : null;
            if (!$funil || !$msg) wat_resp(404, ['ok' => false, 'erro' => 'Mensagem do funil não encontrada.']);
            $via = in_array($d['via'] ?? 'auto', ['auto', 'template', 'texto'], true) ? $d['via'] : 'auto';
            $r = waf_enviar_mensagem($pdo, $cfg, $c, $funil, $msg, $via, true);
            wat_resp($r['ok'] ? 200 : 422, ['ok' => $r['ok'], 'resultado' => $r, 'erro' => $r['erro']]);
        }
        case 'enviar_texto': {
            $cfg = $exigirMeta(); $c = $exigirContato();
            $r = waf_enviar_texto_livre($pdo, $cfg, $c, (string)($d['texto'] ?? ''));
            wat_resp($r['ok'] ? 200 : 422, ['ok' => $r['ok'], 'resultado' => $r, 'erro' => $r['erro'] ?? null]);
        }
        case 'config_salvar': {
            $cfg = waf_config_gravar($pdo, $d);
            wat_resp(200, ['ok' => true, 'automacao' => $cfg]);
        }
        case 'agendar': {
            $c = $exigirContato();
            $funil = atd_funil_por_id($pdo, (string)($d['funil'] ?? ''));
            if (!$funil) wat_resp(404, ['ok' => false, 'erro' => 'Funil não encontrado.']);
            $intervalo = max(0, min(1440, (int)($d['intervalo_min'] ?? 0)));
            wat_resp(200, ['ok' => true] + waf_agendar($pdo, $c, $funil, null, $intervalo));
        }
        case 'agenda_cancelar': {
            $c = $exigirContato();
            wat_resp(200, ['ok' => true, 'canceladas' => waf_agenda_cancelar($pdo, (int)$c['idcontato'])]);
        }
        case 'processar': {
            $r = waf_processar($pdo, $cfgWa);
            wat_resp(200, ['ok' => true, 'resultado' => $r]);
        }
        case 'reiniciar': {
            if (($d['confirmo'] ?? '') !== 'REINICIAR') wat_resp(422, ['ok' => false, 'erro' => 'Digite REINICIAR para confirmar.']);
            wat_resp(200, ['ok' => true] + waf_reiniciar_teste($pdo));
        }
        default:
            wat_resp(400, ['ok' => false, 'erro' => 'Ação desconhecida.']);
    }
} catch (InvalidArgumentException $e) {
    wat_resp(422, ['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    wat_resp(500, ['ok' => false, 'erro' => 'Erro interno (' . wa_log_erro($e) . ').']);
}
