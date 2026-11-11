<?php
/**
 * Central de atendimento: API da tela /atendimento/ (somente administrador).
 *
 * GET  ?action=resumo                       prontidão + funis + conceitos + referências + testes
 * GET  ?action=dashboard&dias=30            métricas do WhatsApp, resultado dos testes A/B, captação (Tráfego) e resumo de leads
 * GET  ?action=leads&dias=30                 leads do período (Clientes + Tráfego e Captação + WhatsApp), com a mensagem sugerida
 * GET  ?action=perfil&chave=a12|l3        perfil de atendimento de uma pessoa (etapa, temperatura, contatos, mensagens prontas)
 * GET  ?action=manual_estado               fila de hoje do atendimento manual (sem a Meta): configuração, o que enviar agora e quem aguarda resposta
 * POST ?action=manual_config | manual_acao   salvar origem e data de início; marcar enviada, pular, respondeu ou encerrar
 * GET  ?action=inbox_lista | inbox_conversa&id=N   caixa de entrada (sem a Meta): conversas, e uma conversa inteira com o rascunho da Cora
 * POST ?action=inbox_nova | inbox_lead | inbox_registrar | inbox_sugerir | inbox_enviei | inbox_resolver
 *        criar conversa por telefone ou a partir de um lead da fila; colar o que a pessoa escreveu; pedir a sugestão da Cora; marcar enviada; resolver
 * GET  ?action=exportar                     pacote JSON para a automação
 * POST ?action=funil_salvar | funil_excluir | funil_restaurar | funil_duplicar
 * POST ?action=conceito_salvar | conceito_excluir | conceito_restaurar
 * POST ?action=ref_link | ref_arquivo | ref_atualizar | ref_ler | ref_excluir
 * POST ?action=teste_salvar | teste_excluir
 * POST ?action=analisar | gerar
 *
 * Toda regra mora em _atendimento.php. Aqui só autenticação, entrada e saída.
 * Conversa de lead é dado pessoal: só o administrador chega aqui. A caixa de entrada (inbox_*) devolve o texto das conversas que a equipe
 * mesma colou ou enviou; os outros endpoints continuam sem texto de conversa.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/_atendimento.php';
require_once __DIR__ . '/_atd_manual.php';
require_once __DIR__ . '/_atd_inbox.php';

if (!function_exists('bearerToken')) {
    function bearerToken() {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
        return preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';
    }
}

function atd_resp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function atd_entrada(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > 6 * 1024 * 1024) atd_resp(413, ['ok' => false, 'erro' => 'Envio grande demais.']);
    $a = json_decode($raw, true);
    return is_array($a) ? $a : [];
}

$pdo = wa_conectar();
if (!$pdo) atd_resp(503, ['ok' => false, 'erro' => 'Banco indisponível.']);

try {
    require_once __DIR__ . '/_auth_context.php';
    $ctx = getAuthContext($pdo, bearerToken());
    if ((int)($ctx['idusuario'] ?? 0) <= 0 || !empty($ctx['is_aluno'])) atd_resp(401, ['ok' => false, 'erro' => 'Sessão inválida. Entre de novo.']);
    if (empty($ctx['admin'])) atd_resp(403, ['ok' => false, 'erro' => 'Esta área é só do administrador.']);

    wa_garantir_tabelas($pdo);
    atd_garantir_config($pdo);

    $action = (string)($_GET['action'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $soPost = ['funil_salvar', 'funil_excluir', 'funil_restaurar', 'funil_duplicar', 'conceito_salvar', 'conceito_excluir', 'conceito_restaurar',
               'manual_config', 'manual_acao', 'inbox_nova', 'inbox_lead', 'inbox_registrar', 'inbox_sugerir', 'inbox_enviei', 'inbox_resolver', 'ref_link', 'ref_arquivo', 'ref_atualizar', 'ref_ler', 'ref_excluir', 'teste_salvar', 'teste_excluir', 'analisar', 'gerar'];
    if (in_array($action, $soPost, true) && $metodo !== 'POST') atd_resp(405, ['ok' => false, 'erro' => 'Use POST.']);

    $d = $metodo === 'POST' ? atd_entrada() : [];

    switch ($action) {
        case 'resumo': {
            $funis = atd_funis($pdo);
            $analises = [];
            foreach ($funis as $f) $analises[$f['id']] = atd_analisar_funil($f);
            atd_resp(200, [
                'ok' => true, 'prontidao' => atd_prontidao($pdo), 'funis' => $funis, 'analises' => $analises,
                'funis_padrao' => array_column(atd_funis_padrao(), 'id'),
                'conceitos' => atd_conceitos($pdo), 'conceitos_padrao' => array_column(atd_conceitos_padrao(), 'id'),
                'refs' => atd_refs_resumo(atd_refs($pdo)), 'tags' => ATD_TAGS_REF,
                'testes' => atd_testes($pdo), 'planos' => atd_planos_contexto($pdo),
            ]);
        }
        case 'dashboard': {
            $dias = max(1, min(365, (int)($_GET['dias'] ?? 30)));
            [$de, $ate] = atd_periodo($dias);
            $captacao = null; $leads = null;
            try { $captacao = atd_captacao($pdo, $de, $ate); } catch (Throwable $e) { wa_log_erro($e); }
            try { $l = atd_leads($pdo, $de, $ate); unset($l['pessoas']); $leads = $l; } catch (Throwable $e) { wa_log_erro($e); }
            atd_resp(200, ['ok' => true, 'metricas' => atd_metricas($pdo, $dias), 'testes' => atd_testes($pdo), 'resultados' => atd_resultados_testes($pdo), 'captacao' => $captacao, 'leads' => $leads]);
        }
        case 'leads': {
            $dias = max(1, min(365, (int)($_GET['dias'] ?? 30)));
            [$de, $ate] = atd_periodo($dias);
            $L = atd_leads($pdo, $de, $ate);
            $L['pessoas'] = atd_manual_enriquecer_leads($pdo, $L['pessoas']);
            atd_resp(200, ['ok' => true] + $L);
        }
        case 'perfil': {
            $r = atd_manual_perfil($pdo, (string)($_GET['chave'] ?? ''));
            if (!$r) atd_resp(404, ['ok' => false, 'erro' => 'Pessoa não encontrada.']);
            atd_resp(200, $r);
        }
        case 'manual_estado':
            atd_resp(200, ['ok' => true] + atd_manual_fila($pdo));
        case 'manual_config': {
            $cfg = atd_manual_cfg_gravar($pdo, $d);
            atd_resp(200, ['ok' => true, 'cfg' => $cfg]);
        }
        case 'manual_acao': {
            $r = atd_manual_acao($pdo, $d, (int)$ctx['idusuario'], (string)($ctx['nmusuario'] ?? ''));
            atd_resp($r['ok'] ? 200 : 422, ['ok' => $r['ok'], 'erro' => $r['erro'], 'ja' => $r['ja']]);
        }
        case 'inbox_lista':
            atd_resp(200, ['ok' => true] + inbox_lista($pdo));
        case 'inbox_conversa': {
            $r = inbox_conversa($pdo, (int)($_GET['id'] ?? 0));
            if (!$r) atd_resp(404, ['ok' => false, 'erro' => 'Conversa não encontrada.']);
            atd_resp(200, $r);
        }
        case 'inbox_nova': {
            $c = inbox_contato_novo($pdo, (string)($d['telefone'] ?? ''), (string)($d['nome'] ?? ''));
            if (trim((string)($d['texto'] ?? '')) !== '') inbox_registrar_lead($pdo, (int)$c['idcontato'], (string)$d['texto']);
            atd_resp(200, ['ok' => true, 'id' => (int)$c['idcontato']]);
        }
        case 'inbox_lead': {
            $c = inbox_contato_do_lead($pdo, (string)($d['chave'] ?? ''));
            atd_resp(200, ['ok' => true, 'id' => (int)$c['idcontato']]);
        }
        case 'inbox_registrar': {
            $r = inbox_registrar_lead($pdo, (int)($d['id'] ?? 0), (string)($d['texto'] ?? ''));
            atd_resp(200, ['ok' => true] + $r);
        }
        case 'inbox_sugerir': {
            $r = inbox_sugerir($pdo, (int)($d['id'] ?? 0));
            atd_resp($r['ok'] ? 200 : 422, $r);
        }
        case 'inbox_enviei': {
            $r = inbox_enviei($pdo, (int)($d['id'] ?? 0), (string)($d['texto'] ?? ''));
            atd_resp(200, ['ok' => true] + $r);
        }
        case 'inbox_resolver':
            inbox_resolver($pdo, (int)($d['id'] ?? 0));
            atd_resp(200, ['ok' => true]);

        case 'exportar':
            atd_resp(200, ['ok' => true, 'pacote' => atd_exportar($pdo)]);

        case 'funil_salvar':
            $f = atd_funil_salvar($pdo, (array)($d['funil'] ?? []));
            atd_resp(200, ['ok' => true, 'funil' => $f, 'analise' => atd_analisar_funil($f)]);
        case 'funil_excluir':
            atd_resp(200, ['ok' => atd_funil_excluir($pdo, (string)($d['id'] ?? ''))]);
        case 'funil_restaurar': {
            $f = atd_funil_restaurar($pdo, (string)($d['id'] ?? ''));
            if (!$f) atd_resp(404, ['ok' => false, 'erro' => 'Só funis de fábrica podem ser restaurados.']);
            atd_resp(200, ['ok' => true, 'funil' => $f, 'analise' => atd_analisar_funil($f)]);
        }
        case 'funil_duplicar': {
            $f = atd_funil_duplicar($pdo, (string)($d['id'] ?? ''), (string)($d['nome'] ?? ''));
            atd_resp(200, ['ok' => true, 'funil' => $f, 'analise' => atd_analisar_funil($f)]);
        }

        case 'conceito_salvar':
            atd_resp(200, ['ok' => true, 'conceito' => atd_conceito_salvar($pdo, (array)($d['conceito'] ?? []))]);
        case 'conceito_excluir':
            atd_resp(200, ['ok' => atd_conceito_excluir($pdo, (string)($d['id'] ?? ''))]);
        case 'conceito_restaurar': {
            $c = atd_conceito_restaurar($pdo, (string)($d['id'] ?? ''));
            if (!$c) atd_resp(404, ['ok' => false, 'erro' => 'Só conceitos de fábrica podem ser restaurados.']);
            atd_resp(200, ['ok' => true, 'conceito' => $c]);
        }

        case 'ref_link': {
            $r = atd_ref_link_adicionar($pdo, $d);
            unset($r['texto']);
            atd_resp(200, ['ok' => true, 'ref' => $r + ['tem_texto' => $r['lido_em'] !== null]]);
        }
        case 'ref_arquivo': {
            $r = atd_ref_arquivo_adicionar($pdo, $d);
            unset($r['texto']);
            atd_resp(200, ['ok' => true, 'ref' => $r]);
        }
        case 'ref_atualizar': {
            $r = atd_ref_atualizar($pdo, (string)($d['id'] ?? ''), $d);
            if (!$r) atd_resp(404, ['ok' => false, 'erro' => 'Referência não encontrada.']);
            unset($r['texto']);
            atd_resp(200, ['ok' => true, 'ref' => $r]);
        }
        case 'ref_ler': {
            $r = atd_ref_ler_de_novo($pdo, (string)($d['id'] ?? ''));
            if (!$r) atd_resp(404, ['ok' => false, 'erro' => 'Link não encontrado.']);
            unset($r['texto']);
            atd_resp(200, ['ok' => true, 'ref' => $r]);
        }
        case 'ref_excluir':
            atd_resp(200, ['ok' => atd_ref_excluir($pdo, (string)($d['id'] ?? ''))]);

        case 'teste_salvar':
            atd_resp(200, ['ok' => true, 'teste' => atd_teste_salvar($pdo, (array)($d['teste'] ?? []))]);
        case 'teste_excluir':
            atd_resp(200, ['ok' => atd_teste_excluir($pdo, (string)($d['id'] ?? ''))]);

        case 'analisar':
            atd_resp(200, ['ok' => true, 'analise' => atd_analisar_copy((string)($d['texto'] ?? ''), (array)($d['opcoes'] ?? []))]);
        case 'gerar': {
            $r = atd_gerar($pdo, $d);
            atd_resp($r['ok'] ? 200 : 422, $r);
        }
        default:
            atd_resp(400, ['ok' => false, 'erro' => 'Ação desconhecida.']);
    }
} catch (InvalidArgumentException $e) {
    atd_resp(422, ['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    atd_resp(500, ['ok' => false, 'erro' => 'Erro interno (' . wa_log_erro($e) . ').']);
}
