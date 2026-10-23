<?php
/**
 * Atendimento MANUAL dos leads (sem a API da Meta): "Fila de hoje" da Central.
 *
 * A pessoa da equipe abre o WhatsApp com a mensagem pronta do funil e marca como enviada. Nada aqui chama a Meta,
 * não depende de template aprovado nem de consentimento gravado (o lead escreveu ou cadastrou o próprio telefone e
 * quem manda é uma pessoa, pelo WhatsApp da Intus).
 *
 * Regras (todas testadas em testes/atd_manual.php):
 *  - Quem entra: leads de atd_leads (Clientes + Tráfego e Captação) cadastrados desde a data configurada, só da origem
 *    escolhida (orgânico por padrão), com telefone válido, que não pediram para sair, não estão encerrados, não são
 *    cliente nem têm matrícula a pagar (esses são conversa de uma pessoa, fora da fila).
 *  - Qual mensagem: a agenda do funil (atd_agenda_do_funil) a partir do cadastro. Cada passo vale da hora dele até a
 *    hora do passo seguinte (o último vale 2 dias), então a fila nunca oferece uma mensagem velha ("hoje é o último dia
 *    do teste" três dias depois). Passo já enviado ou pulado não volta. Condição não satisfeita (sem_treino, com_treino,
 *    sem_resposta) e passo vencido sem ação simplesmente não aparecem.
 *  - Contato sem conta recebe só a mensagem de convite (chamou-whatsapp, c4).
 *  - Teste A/B rodando: o lado vem do telefone (atd_funil_para_contato), então a pessoa recebe sempre o mesmo texto.
 *  - Onde fica o registro: um JSON por pessoa em intus_config (chave atd_m_<chave>), mais o contato em
 *    intus_lead_contato (a mesma "situação de contato" de Pessoas Captadas). Nenhuma tabela nova.
 */

require_once __DIR__ . '/_atendimento.php';
require_once __DIR__ . '/_provas.php';

const ATD_M_ORIGENS = ['organico', 'pago', 'todos'];
const ATD_M_ULTIMO_PASSO_DIAS = 2;       // quanto tempo o último passo do funil continua valendo
const ATD_M_ESPERA_RESPOSTA_DIAS = 3;
const ATD_M_RECUP_FUNIL = 'recuperacao-teste';   // funil de quem fez o teste, nunca recebeu mensagem e já passou da hora
const ATD_M_TESTE_DIAS = 3;                      // duração do teste grátis, usada quando a conta não tem matrícula de teste registrada
const ATD_M_RECUP_JANELA_DIAS = 30;              // a recuperação alcança quem se cadastrou até tantos dias atrás, mesmo antes da data inicial da fila
const ATD_M_TEMPERATURAS = ['quente', 'morno', 'frio'];
const ATD_M_RECUP_MIN_DIAS = 5;                  // dias depois do fim do teste para a pessoa entrar na recuperação    // quanto tempo uma pessoa fica em "aguardando resposta"
const ATD_M_MEDIUM_PAGO = '/^(cpc|ppc|paid|paidsocial|paid_social|paid-social|ads|anuncio|anúncio|trafego_pago|trafego-pago|pago|display)$/iu';

/** Configuração. Na primeira leitura grava o padrão: orgânico, leads dos últimos 2 dias. */
function atd_manual_cfg(PDO $pdo, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $c = atd_cfg_ler($pdo, 'atd_manual_cfg', null);
    if (!is_array($c)) {
        $c = ['origem' => 'organico', 'desde' => $agora->modify('-2 day')->format('Y-m-d')];
        atd_cfg_gravar($pdo, 'atd_manual_cfg', $c);
    }
    return [
        'origem' => in_array($c['origem'] ?? '', ATD_M_ORIGENS, true) ? $c['origem'] : 'organico',
        'desde' => (is_string($c['desde'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $c['desde'])) ? $c['desde'] : $agora->modify('-2 day')->format('Y-m-d'),
    ];
}

function atd_manual_cfg_gravar(PDO $pdo, array $d, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $origem = (string)($d['origem'] ?? '');
    if (!in_array($origem, ATD_M_ORIGENS, true)) throw new InvalidArgumentException('Escolha orgânico, pago ou todos.');
    $desde = (string)($d['desde'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $desde, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new InvalidArgumentException('Data inválida.');
    if ($desde > $agora->format('Y-m-d')) throw new InvalidArgumentException('A data não pode ser no futuro.');
    if ($desde < $agora->modify('-90 day')->format('Y-m-d')) throw new InvalidArgumentException('Volte no máximo 90 dias.');
    atd_cfg_gravar($pdo, 'atd_manual_cfg', ['origem' => $origem, 'desde' => $desde]);
    return atd_manual_cfg($pdo, $agora);
}

/** 'pago' quando o lead veio de uma campanha com utm_medium de anúncio; todo o resto (inclusive sem medição) é orgânico. */
function atd_manual_origem(array $p): string {
    $medium = trim((string)($p['utm']['medium'] ?? ''));
    return ($medium !== '' && preg_match(ATD_M_MEDIUM_PAGO, $medium)) ? 'pago' : 'organico';
}

function atd_manual_respondeu(array $p): bool {
    if (!empty($p['wa']['respondeu'])) return true;
    foreach (($p['controle']['historico'] ?? []) as $h) { if (in_array($h['status'] ?? '', ['respondeu', 'fechou'], true)) return true; }
    return false;
}

function atd_manual_condicao_ok(string $condicao, array $p): bool {
    switch ($condicao) {
        case 'sem_treino': return (int)($p['treinos'] ?? 0) === 0;
        case 'com_treino': return (int)($p['treinos'] ?? 0) > 0;
        case 'sem_resposta': return !atd_manual_respondeu($p);
        default: return true;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Registro por pessoa (intus_config, chave atd_m_<chave>)
// ─────────────────────────────────────────────────────────────────────────────

function atd_manual_chave_valida(string $chave): bool { return (bool)preg_match('/^[al][0-9]{1,10}$/', $chave); }

function atd_manual_registros(PDO $pdo, array $chaves): array {
    atd_garantir_config($pdo);
    $chaves = array_values(array_unique(array_filter($chaves, 'atd_manual_chave_valida')));
    $out = [];
    foreach (array_chunk($chaves, 400) as $lote) {
        $ks = array_map(fn($c) => 'atd_m_' . $c, $lote);
        $st = $pdo->prepare('SELECT chave, valor FROM intus_config WHERE chave IN (' . implode(',', array_fill(0, count($ks), '?')) . ')');
        $st->execute($ks);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a = json_decode((string)$r['valor'], true);
            if (is_array($a)) $out[substr($r['chave'], 6)] = $a;
        }
    }
    return $out;
}

function atd_manual_registro_gravar(PDO $pdo, string $chave, array $reg): void {
    atd_cfg_gravar($pdo, 'atd_m_' . $chave, $reg);
}

// ─────────────────────────────────────────────────────────────────────────────
// A fila
// ─────────────────────────────────────────────────────────────────────────────

function atd_manual_mensagem(array $funil, string $msgId): ?array {
    $msgId = explode('@', $msgId, 2)[0];   // "f5@combo-tri": a mensagem f5 para o plano combo-tri
    foreach ($funil['mensagens'] ?? [] as $m) { if (($m['id'] ?? '') === $msgId) return $m; }
    return null;
}

/** Nome para mostrar: nas mensagens "uma opção por plano" (id@plano) acrescenta o plano. */
function atd_manual_nome_msg(?array $m, string $mid, array $planosPorId): string {
    $partes = explode('@', $mid, 2);
    $nome = $m['nome'] ?? $partes[0];
    if (isset($partes[1], $planosPorId[$partes[1]])) $nome .= ' · ' . $planosPorId[$partes[1]]['nome'];
    return $nome;
}

/** O que as mensagens de fechamento precisam além do nome: planos e valores (Gestão de Planos) e as provas sociais liberadas para o robô. */
function atd_manual_ctx_base(PDO $pdo): array {
    return ['planos' => atd_planos_contexto($pdo), 'provas' => function_exists('provas_carregar_robo') ? provas_carregar_robo($pdo) : []];
}
/** Contexto de UMA pessoa: {{4}} os planos e {{8}} a prova social que mais combina com ela. */
function atd_manual_ctx(array $base, array $pessoa): array {
    $ctx = ['planos_bloco' => $base['planos']['bloco']];
    if (function_exists('provas_escolher')) { $pv = provas_escolher($base['provas'], $pessoa); $ctx['prova'] = $pv ? provas_texto($pv) : ''; }
    return $ctx;
}

/** Plano de um passo "id@plano" tem de existir e a mensagem tem de ser "uma opção por plano". */
function atd_manual_plano_do_passo(PDO $pdo, array $funil, string $mid): bool {
    if (strpos($mid, '@') === false) return true;
    $m = atd_manual_mensagem($funil, $mid);
    if (!$m || empty($m['por_plano'])) return false;
    return in_array(explode('@', $mid, 2)[1], array_column(atd_planos($pdo), 'id'), true);
}

/**
 * Agenda de uma pessoa: lista de [passoKey, DateTimeImmutable, condicao, funilId, msgId] em ordem de horário.
 * Conta cadastrada segue o funil do teste grátis (ou a variante do teste A/B que estiver rodando); contato sem conta
 * recebe só o convite do funil "chamou-whatsapp".
 */
function atd_manual_agenda(PDO $pdo, array $p, array $funisPorId, DateTimeImmutable $criado): array {
    if (($p['tipo'] ?? '') === 'contato') {
        $f = $funisPorId['chamou-whatsapp'] ?? null;
        if (!$f || !atd_manual_mensagem($f, 'c4')) return [];
        return [[atd_passo('chamou-whatsapp', 'c4'), $criado, 'todos', 'chamou-whatsapp', 'c4']];
    }
    $esc = atd_funil_para_contato($pdo, 'teste-gratis', (string)($p['whatsapp'] ?? ''), 'teste-gratis');
    $f = $funisPorId[$esc['funil']] ?? ($funisPorId['teste-gratis'] ?? null);
    if (!$f) return [];
    $ag = [];
    foreach (atd_agenda_do_funil($f, $criado) as [$passo, $quando, $cond]) {
        [, $mid] = atd_passo_decodificar($passo);
        $ag[] = [$passo, $quando, $cond, $f['id'], $mid, $esc['lado']];
    }
    usort($ag, fn($a, $b) => $a[1] <=> $b[1]);
    return $ag;
}

/**
 * Todas as mensagens prontas da Central (de todos os funis) com texto, para a equipe escolher qual enviar.
 * Cada item: passo, funil, funil_nome, funil_status, mensagem, nome, aprovada e o modelo (texto com {{1}}).
 */
function atd_manual_biblioteca(array $funis, array $planos = []): array {
    $out = [];
    foreach ($funis as $f) {
        foreach ($f['mensagens'] ?? [] as $m) {
            if (trim((string)($m['texto'] ?? '')) === '') continue;
            $base = ['funil' => $f['id'], 'funil_nome' => $f['nome'], 'funil_status' => $f['status'] ?? 'rascunho', 'aprovada' => !empty($m['aprovada']), 'modelo' => $m];
            if (!empty($m['por_plano']) && $planos) {
                $usaLink = strpos((string)$m['texto'], '{{5}}') !== false;
                foreach ($planos as $pl) {   // uma opção para cada plano de Gestão de Planos
                    $id = $m['id'] . '@' . $pl['id'];
                    $out[] = ['passo' => atd_passo((string)$f['id'], $id), 'mensagem' => $id, 'nome' => $m['nome'] . ' · ' . $pl['nome'], 'plano' => $pl, 'plano_id' => $pl['id'], 'falta_link' => $usaLink && $pl['link'] === ''] + $base;
                }
                continue;
            }
            $out[] = ['passo' => atd_passo((string)$f['id'], (string)$m['id']), 'mensagem' => $m['id'], 'nome' => $m['nome'], 'plano' => null, 'plano_id' => null, 'falta_link' => false] + $base;
        }
    }
    return $out;
}

/** A biblioteca como vai para a tela: sem o modelo cru nem o plano inteiro. */
function atd_manual_biblioteca_publica(array $bib): array {
    return array_map(fn($b) => array_diff_key($b, ['modelo' => 1, 'plano' => 1]), $bib);
}

/** Texto de cada mensagem da biblioteca já com o nome da pessoa: [passo => texto]. */
function atd_manual_opcoes(array $biblioteca, string $nome, string $indicador, array $ctx = []): array {
    $o = [];
    foreach ($biblioteca as $b) $o[$b['passo']] = atd_render_mensagem($b['modelo'], $nome, $indicador, $ctx + ['plano' => $b['plano'] ?? null]);
    return $o;
}

/** Passo que vale agora para a pessoa, ou null. Cada passo vale da hora dele até a hora do próximo. */
function atd_manual_passo_agora(array $agenda, array $registro, array $p, DateTimeImmutable $agora): ?array {
    $k = -1;
    foreach ($agenda as $i => $a) { if ($a[1] <= $agora) $k = $i; }
    if ($k < 0) return null;
    $fim = isset($agenda[$k + 1]) ? $agenda[$k + 1][1] : $agenda[$k][1]->modify('+' . ATD_M_ULTIMO_PASSO_DIAS . ' day');
    if ($agora >= $fim) return null;
    $a = $agenda[$k];
    if (isset($registro['passos'][$a[0]])) return null;
    if (!atd_manual_condicao_ok($a[2], $p)) return null;
    return $a;
}

/**
 * Temperatura do lead (quente, morno ou frio), calculada do comportamento; a equipe pode fixar outra à mão.
 * Pontos: respondeu +3, pediu atendimento humano +2, treinou +2 (3 treinos ou mais +1), abriu o app +1, retorno marcado +1,
 * matrícula criada e a pagar +4, teste acabou sem treinar -1, cadastro de mais de 21 dias sem nenhum uso -1.
 * 4 ou mais = quente, 2 ou 3 = morno, menos = frio. Cliente não tem temperatura; encerrado é frio.
 */
function atd_manual_temperatura_auto(array $p): array {
    if (($p['etapa'] ?? '') === 'cliente') return ['nivel' => 'cliente', 'pontos' => null, 'motivos' => ['já é cliente']];
    if (($p['grupo_contato'] ?? '') === 'encerrado') return ['nivel' => 'frio', 'pontos' => 0, 'motivos' => ['pediu para sair ou foi encerrado']];
    $pts = 0; $m = [];
    $treinos = (int)($p['treinos'] ?? 0);
    $respondeu = atd_manual_respondeu($p);
    if (($p['etapa'] ?? '') === 'negociando') { $pts += 4; $m[] = 'tem matrícula criada, falta pagar'; }
    if ($respondeu) { $pts += 3; $m[] = 'respondeu'; }
    if (!empty($p['wa']['precisa_humano'])) { $pts += 2; $m[] = 'pediu atendimento humano'; }
    if ($treinos >= 1) { $pts += 2; $m[] = 'treinou no teste'; }
    if ($treinos >= 3) { $pts += 1; $m[] = '3 treinos ou mais'; }
    if (!empty($p['abriu_app'])) { $pts += 1; $m[] = 'abriu o app'; }
    if (($p['grupo_contato'] ?? '') === 'retorno') { $pts += 1; $m[] = 'retorno marcado'; }
    if (($p['etapa'] ?? '') === 'teste_expirado' && $treinos === 0) { $pts -= 1; $m[] = 'teste acabou sem treinar'; }
    $cad = substr((string)($p['criado_em'] ?? ''), 0, 10);
    if ($cad !== '' && $treinos === 0 && !$respondeu && empty($p['abriu_app']) && (int)floor((time() - strtotime($cad)) / 86400) > 21) { $pts -= 1; $m[] = 'cadastro antigo sem uso'; }
    return ['nivel' => $pts >= 4 ? 'quente' : ($pts >= 2 ? 'morno' : 'frio'), 'pontos' => $pts, 'motivos' => $m];
}

/** Temperatura que vale: a fixada pela equipe, se houver, senão a calculada. */
function atd_manual_temperatura(array $p, array $reg): array {
    $auto = atd_manual_temperatura_auto($p);
    $man = $reg['temperatura']['nivel'] ?? null;
    if ($auto['nivel'] !== 'cliente' && in_array($man, ATD_M_TEMPERATURAS, true)) {
        return ['nivel' => $man, 'manual' => true, 'auto' => $auto['nivel'], 'motivos' => $auto['motivos'], 'em' => $reg['temperatura']['em'] ?? null, 'por' => $reg['temperatura']['por'] ?? ''];
    }
    return ['nivel' => $auto['nivel'], 'manual' => false, 'auto' => $auto['nivel'], 'motivos' => $auto['motivos'], 'em' => null, 'por' => ''];
}

/** Acrescenta `temperatura` a cada pessoa de atd_leads (usa o registro guardado de cada uma). */
function atd_manual_enriquecer_leads(PDO $pdo, array $pessoas): array {
    $regs = atd_manual_registros($pdo, array_column($pessoas, 'chave'));
    foreach ($pessoas as $i => $p) $pessoas[$i]['temperatura'] = atd_manual_temperatura($p, $regs[$p['chave']] ?? ['passos' => []]);
    return $pessoas;
}

/**
 * Fim do teste grátis: [data, estimado]. Vem da matrícula de teste; conta do teste grátis SEM matrícula de teste registrada
 * (cadastrou pela página e nunca ganhou a matrícula) usa o cadastro mais ATD_M_TESTE_DIAS dias, marcado como estimado.
 */
function atd_manual_fim_do_teste(array $p): ?array {
    $vence = (string)($p['teste']['vence'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $vence)) {
        try { return [new DateTimeImmutable(substr($vence, 0, 10) . ' 00:00:00', wa_tz()), false]; } catch (Throwable $e) {}
    }
    if (($p['tipo'] ?? '') === 'conta' && in_array($p['etapa'] ?? '', ['so_conta', 'abriu_app', 'treinando'], true) && !empty($p['criado_em'])) {
        try { return [(new DateTimeImmutable(substr((string)$p['criado_em'], 0, 10) . ' 00:00:00', wa_tz()))->modify('+' . ATD_M_TESTE_DIAS . ' day'), true]; } catch (Throwable $e) {}
    }
    return null;
}

/** Dias inteiros desde o fim do teste (negativo se ainda não acabou), ou null se não dá para saber. */
function atd_manual_dias_do_fim_do_teste(array $p, DateTimeImmutable $agora): ?int {
    $f = atd_manual_fim_do_teste($p);
    if (!$f) return null;
    return (int)floor(($agora->setTime(0, 0, 0)->getTimestamp() - $f[0]->getTimestamp()) / 86400);
}

/**
 * Recuperação: conta cujo teste grátis terminou há ATD_M_RECUP_MIN_DIAS dias ou mais, que não virou cliente e a quem NADA foi enviado
 * (nenhuma mensagem marcada aqui, nenhum contato em Pessoas Captadas, nenhuma mensagem pelo WhatsApp da Intus). Quem já começou a receber
 * a recuperação continua nela até o fim da sequência.
 */
function atd_manual_em_recuperacao(array $p, array $reg, DateTimeImmutable $agora): bool {
    if (($p['tipo'] ?? '') !== 'conta' || !in_array($p['etapa'] ?? '', ['teste_expirado', 'so_conta', 'abriu_app', 'treinando'], true)) return false;
    foreach (($reg['passos'] ?? []) as $passo => $info) {
        if (strpos((string)$passo, ATD_M_RECUP_FUNIL . ':') === 0) return true;
    }
    $dias = atd_manual_dias_do_fim_do_teste($p, $agora);
    if ($dias === null || $dias < ATD_M_RECUP_MIN_DIAS) return false;
    foreach (($reg['passos'] ?? []) as $info) { if (($info['estado'] ?? '') === 'enviada') return false; }
    if (($p['grupo_contato'] ?? 'nunca') !== 'nunca') return false;
    return (int)($p['wa']['enviadas'] ?? 0) === 0;
}

/**
 * Passo da recuperação que vale agora. O primeiro vale desde o dia marcado no funil (contado do fim do teste); cada um dos seguintes
 * espera, depois do envio do anterior, o mesmo intervalo que o funil tem entre eles. Quem respondeu sai da sequência.
 */
function atd_manual_passo_recuperacao(array $funil, array $reg, array $p, DateTimeImmutable $agora, int $ini, int $fim): ?array {
    $fimTeste = atd_manual_fim_do_teste($p);
    if (!$fimTeste) return null;
    $base = $fimTeste[0];
    $prevEnvio = null; $prevQuando = null;
    foreach (atd_agenda_do_funil($funil, $base, $ini, $fim) as [$passo, $quando, $cond]) {
        $estado = $reg['passos'][$passo]['estado'] ?? null;
        if ($estado === 'enviada' || $estado === 'substituida') {   // trocada por outra mensagem também conta como contato feito
            try { $prevEnvio = new DateTimeImmutable((string)$reg['passos'][$passo]['em'], wa_tz()); } catch (Throwable $e) { return null; }
            $prevQuando = $quando;
            continue;
        }
        if ($estado !== null) { $prevQuando = $quando; continue; }   // pulada ou trocada por outra: segue para o próximo
        if (!atd_manual_condicao_ok($cond, $p)) return null;
        $due = $quando;
        if ($prevEnvio && $prevQuando) {
            $espera = max(0, $quando->getTimestamp() - $prevQuando->getTimestamp());
            $apos = wa_proximo_horario_valido($prevEnvio->modify('+' . $espera . ' seconds'), $ini, $fim);
            if ($apos > $due) $due = $apos;
        }
        [, $mid] = atd_passo_decodificar($passo);
        return $agora >= $due ? [$passo, $due, $cond, $funil['id'], $mid, null] : null;
    }
    return null;
}

/**
 * Monta a fila de hoje. Devolve:
 *  cfg, agora, no_horario, horario, para_enviar[], aguardando[], resumo{}, resultado{}, meta{}.
 */
function atd_manual_fila(PDO $pdo, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $cfg = atd_manual_cfg($pdo, $agora);
    $aj = wa_ajustes($pdo);
    // a recuperação alcança quem se cadastrou até ATD_M_RECUP_JANELA_DIAS dias atrás, mesmo antes da data inicial; o resto respeita a data
    $busca = min($cfg['desde'], $agora->modify('-' . ATD_M_RECUP_JANELA_DIAS . ' day')->format('Y-m-d'));
    $L = atd_leads($pdo, $busca, $agora->format('Y-m-d'), $agora);
    $funis = atd_funis($pdo);
    $porId = [];
    foreach ($funis as $f) $porId[$f['id']] = $f;

    $noPeriodo = 0;
    foreach ($L['pessoas'] as $p) { if (substr((string)$p['criado_em'], 0, 10) >= $cfg['desde']) $noPeriodo++; }
    $candidatos = []; $res = ['leads_no_periodo' => $noPeriodo, 'antes_da_data' => 0, 'fora_da_origem' => 0, 'sem_telefone' => 0, 'pediram_para_sair' => 0, 'encerrados' => 0, 'conversa_de_pessoa' => 0, 'sem_passo_agora' => 0, 'em_recuperacao' => 0];
    foreach ($L['pessoas'] as $p) {
        $antiga = substr((string)$p['criado_em'], 0, 10) < $cfg['desde'];   // antes da data inicial: só entra se for caso de recuperação
        if ($antiga && atd_manual_fim_do_teste($p) === null) { $res['antes_da_data']++; continue; }
        $conta = function (string $k) use (&$res, $antiga) { if (!$antiga) $res[$k]++; };
        $org = atd_manual_origem($p);
        if ($cfg['origem'] !== 'todos' && $org !== $cfg['origem']) { $conta('fora_da_origem'); continue; }
        if (!($p['wa']['telefone_valido'] ?? false)) { $conta('sem_telefone'); continue; }
        if (!empty($p['wa']['saiu'])) { $conta('pediram_para_sair'); continue; }
        if (($p['grupo_contato'] ?? '') === 'encerrado') { $conta('encerrados'); continue; }
        if (in_array($p['etapa'], ['cliente', 'negociando'], true)) { $conta('conversa_de_pessoa'); continue; }
        $p['origem_tipo'] = $org;
        $candidatos[] = $p;
    }
    $regs = atd_manual_registros($pdo, array_column($candidatos, 'chave'));
    $ctxBase = atd_manual_ctx_base($pdo);
    $planosPorId = array_column($ctxBase['planos']['lista'], null, 'id');
    $biblioteca = atd_manual_biblioteca($funis, $ctxBase['planos']['lista']);

    $paraEnviar = []; $aguardando = []; $resultado = []; $enviadasHoje = 0; $hoje = $agora->format('Y-m-d');
    foreach ($candidatos as $p) {
        $reg = $regs[$p['chave']] ?? ['passos' => []];
        $frec = $porId[ATD_M_RECUP_FUNIL] ?? null;
        $emRec = $frec && atd_manual_em_recuperacao($p, $reg, $agora);
        if (!$emRec && substr((string)$p['criado_em'], 0, 10) < $cfg['desde']) { $res['antes_da_data']++; continue; }
        try { $criado = new DateTimeImmutable((string)$p['criado_em'], wa_tz()); } catch (Throwable $e) { continue; }
        $agenda = atd_manual_agenda($pdo, $p, $porId, $criado);

        // placar do teste das mensagens: enviadas e quantas a pessoa respondeu depois
        foreach (($reg['passos'] ?? []) as $passo => $info) {
            if (($info['estado'] ?? '') !== 'enviada') continue;
            if (substr((string)($info['em'] ?? ''), 0, 10) === $hoje) $enviadasHoje++;
            $r = &$resultado[$passo];
            if (!isset($r)) $r = ['passo' => $passo, 'enviadas' => 0, 'responderam' => 0];
            $r['enviadas']++;
            $respondeu = !empty($p['wa']['respondeu']);
            foreach (($p['controle']['historico'] ?? []) as $h) {
                if (in_array($h['status'] ?? '', ['respondeu', 'fechou'], true) && (string)($h['criado_em'] ?? '') >= (string)($info['em'] ?? '')) $respondeu = true;
            }
            if ($respondeu) $r['responderam']++;
            unset($r);
        }

        $a = $emRec ? atd_manual_passo_recuperacao($frec, $reg, $p, $agora, (int)$aj['horario_ini'], (int)$aj['horario_fim']) : atd_manual_passo_agora($agenda, $reg, $p, $agora);
        if ($emRec) $res['em_recuperacao']++;
        if ($a) {
            $f = $porId[$a[3]] ?? null; $m = $f ? atd_manual_mensagem($f, $a[4]) : null;
            if ($f && $m && trim((string)$m['texto']) !== '') {
                $ctxP = atd_manual_ctx($ctxBase, $p);
                $texto = atd_render_mensagem($m, (string)$p['nome'], (string)($p['indicado_por'] ?? ''), $ctxP);
                $paraEnviar[] = [
                    'chave' => $p['chave'], 'tipo' => $p['tipo'], 'id' => $p['id'], 'nome' => $p['nome'], 'whatsapp' => $p['whatsapp'], 'telefone' => $p['telefone'],
                    'etapa' => $p['etapa'], 'etapa_rotulo' => $p['etapa_rotulo'], 'canal' => $p['canal'], 'campanha' => $p['utm']['campaign'] ?? null, 'origem_tipo' => $p['origem_tipo'],
                    'treinos' => $p['treinos'] ?? 0, 'criado_em' => $p['criado_em'],
                    'funil' => $f['id'], 'funil_nome' => $f['nome'], 'funil_status' => $f['status'], 'passo' => $a[0], 'mensagem' => $m['id'], 'mensagem_nome' => atd_manual_nome_msg($m, $a[4], $planosPorId),
                    'aprovada' => !empty($m['aprovada']), 'lado' => $a[5] ?? null, 'texto' => $texto, 'devida_em' => wa_fmt($a[1]),
                    'atraso_horas' => max(0, (int)floor(($agora->getTimestamp() - $a[1]->getTimestamp()) / 3600)),
                    'ja_contatado' => ($p['grupo_contato'] ?? '') === 'contatado' || ($p['grupo_contato'] ?? '') === 'retorno',
                    'recuperacao' => $emRec ? ['dias' => atd_manual_dias_do_fim_do_teste($p, $agora), 'estimado' => (atd_manual_fim_do_teste($p)[1] ?? false)] : null,
                    'temperatura' => atd_manual_temperatura($p, $reg),
                    'opcoes' => atd_manual_opcoes($biblioteca, (string)$p['nome'], (string)($p['indicado_por'] ?? ''), $ctxP),
                ];
                continue;
            }
        }
        $res['sem_passo_agora']++;

        // aguardando resposta: já enviou algum passo recentemente e a pessoa ainda não respondeu
        $ult = null;
        foreach (($reg['passos'] ?? []) as $passo => $info) {
            if (($info['estado'] ?? '') === 'enviada' && ($ult === null || (string)$info['em'] > (string)$ult['em'])) $ult = $info + ['passo' => $passo];
        }
        if ($ult && !atd_manual_respondeu($p)) {
            try { $envio = new DateTimeImmutable((string)$ult['em'], wa_tz()); } catch (Throwable $e) { continue; }
            if ($envio >= $agora->modify('-' . ATD_M_ESPERA_RESPOSTA_DIAS . ' day')) {
                [$fid, $mid] = atd_passo_decodificar($ult['passo']);
                $f = $porId[$fid] ?? null; $m = $f ? atd_manual_mensagem($f, $mid) : null;
                $aguardando[] = [
                    'chave' => $p['chave'], 'nome' => $p['nome'], 'whatsapp' => $p['whatsapp'], 'telefone' => $p['telefone'], 'etapa_rotulo' => $p['etapa_rotulo'], 'canal' => $p['canal'],
                    'passo' => $ult['passo'], 'mensagem_nome' => atd_manual_nome_msg($m, $mid, $planosPorId), 'funil_nome' => $f['nome'] ?? $fid, 'enviada_em' => $ult['em'], 'por' => $ult['por'] ?? '',
                ];
            }
        }
    }
    // quem está mais atrasado e quem nunca recebeu nada primeiro
    usort($paraEnviar, fn($a, $b) => [$a['ja_contatado'], $a['devida_em']] <=> [$b['ja_contatado'], $b['devida_em']]);
    usort($aguardando, fn($a, $b) => strcmp((string)$a['enviada_em'], (string)$b['enviada_em']));

    // rótulos do placar (nome da mensagem)
    $placar = [];
    foreach ($resultado as $passo => $r) {
        [$fid, $mid] = atd_passo_decodificar($passo);
        $f = $porId[$fid] ?? null; $m = $f ? atd_manual_mensagem($f, $mid) : null;
        $placar[] = $r + ['funil_nome' => $f['nome'] ?? $fid, 'mensagem_nome' => atd_manual_nome_msg($m, $mid, $planosPorId), 'taxa' => $r['enviadas'] ? round($r['responderam'] / $r['enviadas'] * 100, 1) : null];
    }
    usort($placar, fn($a, $b) => strcmp($a['passo'], $b['passo']));

    $mestre = false;
    $auto = atd_cfg_ler($pdo, 'atd_automacao', []);
    if (is_array($auto)) $mestre = !empty($auto['mestre']);
    return [
        'cfg' => $cfg, 'agora' => wa_fmt($agora), 'no_horario' => wa_hora_permitida($agora, (int)$aj['horario_ini'], (int)$aj['horario_fim']),
        'horario' => [(int)$aj['horario_ini'], (int)$aj['horario_fim']],
        'para_enviar' => $paraEnviar, 'aguardando' => $aguardando, 'placar' => $placar,
        'biblioteca' => atd_manual_biblioteca_publica($biblioteca),
        'planos' => ['valores_padrao' => $ctxBase['planos']['valores_padrao'], 'sem_link' => $ctxBase['planos']['sem_link'], 'provas_liberadas' => count($ctxBase['provas'])],
        'resumo' => $res + ['para_enviar' => count($paraEnviar), 'aguardando' => count($aguardando), 'enviadas_hoje' => $enviadasHoje],
        'meta' => ['usada' => false, 'configurada' => wa_config_whatsapp($pdo) !== null, 'automacao_ligada' => $mestre],
    ];
}

/**
 * Perfil de atendimento de UMA pessoa (conta `a<id>` ou contato sem conta `l<id>`), em qualquer data de cadastro: etapa, origem,
 * temperatura (calculada e fixada), estado no WhatsApp, histórico de contatos, passos já tratados e as mensagens prontas já com o
 * nome dela. Usa as mesmas regras da fila e da aba Leads; não decide nada novo. null se a pessoa não existe.
 */
function atd_manual_perfil(PDO $pdo, string $chave, ?DateTimeImmutable $agora = null): ?array {
    $agora = $agora ?: wa_agora();
    if (!atd_manual_chave_valida($chave)) return null;
    $id = (int)substr($chave, 1);
    if ($chave[0] === 'a') {
        $L = atd_leads($pdo, '2000-01-01', '2000-01-01', $agora, [$id]);
    } else {
        try { $st = $pdo->prepare('SELECT criado_em FROM intus_lead WHERE idlead = ?'); $st->execute([$id]); $d = (string)$st->fetchColumn(); } catch (Throwable $e) { $d = ''; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $d)) return null;
        $L = atd_leads($pdo, substr($d, 0, 10), substr($d, 0, 10), $agora, []);
    }
    $p = null;
    foreach ($L['pessoas'] as $x) { if ($x['chave'] === $chave) { $p = $x; break; } }
    if (!$p) return null;
    $reg = atd_manual_registros($pdo, [$chave])[$chave] ?? ['passos' => []];
    $funis = atd_funis($pdo);
    $ctxBase = atd_manual_ctx_base($pdo);
    $planosPorId = array_column($ctxBase['planos']['lista'], null, 'id');
    $bib = atd_manual_biblioteca($funis, $ctxBase['planos']['lista']);
    $porId = [];
    foreach ($funis as $f) $porId[$f['id']] = $f;
    $p['temperatura'] = atd_manual_temperatura($p, $reg);
    $p['opcoes'] = atd_manual_opcoes($bib, (string)$p['nome'], (string)($p['indicado_por'] ?? ''), atd_manual_ctx($ctxBase, $p));
    // mensagem que a Central sugere agora: a da recuperação, se for o caso, senão a da etapa
    $sug = isset($p['sugestao']['funil'], $p['sugestao']['mensagem']) ? atd_passo((string)$p['sugestao']['funil'], (string)$p['sugestao']['mensagem']) : null;
    $rec = null;
    $frec = $porId[ATD_M_RECUP_FUNIL] ?? null;
    if ($frec && atd_manual_em_recuperacao($p, $reg, $agora)) {
        $aj = wa_ajustes($pdo);
        $a = atd_manual_passo_recuperacao($frec, $reg, $p, $agora, (int)$aj['horario_ini'], (int)$aj['horario_fim']);
        if ($a) $sug = $a[0];
        $f = atd_manual_fim_do_teste($p);
        $rec = ['dias' => atd_manual_dias_do_fim_do_teste($p, $agora), 'estimado' => $f ? $f[1] : false, 'passo' => $a ? $a[0] : null];
    }
    $p['passo_sugerido'] = ($sug !== null && isset($p['opcoes'][$sug])) ? $sug : null;
    $p['recuperacao'] = $rec;
    $tratados = [];
    foreach (($reg['passos'] ?? []) as $passo => $info) {
        [$fid, $mid] = atd_passo_decodificar((string)$passo);
        $f = $porId[$fid] ?? null; $m = $f ? atd_manual_mensagem($f, $mid) : null;
        $tratados[] = ['passo' => $passo, 'estado' => $info['estado'] ?? '', 'em' => $info['em'] ?? '', 'por' => $info['por'] ?? '', 'funil_nome' => $f['nome'] ?? $fid, 'mensagem_nome' => atd_manual_nome_msg($m, $mid, $planosPorId)];
    }
    usort($tratados, fn($a, $b) => strcmp((string)$b['em'], (string)$a['em']));
    $p['passos_tratados'] = $tratados;
    $p['origem_tipo'] = atd_manual_origem($p);
    return ['ok' => true, 'pessoa' => $p, 'biblioteca' => atd_manual_biblioteca_publica($bib), 'planos' => ['valores_padrao' => $ctxBase['planos']['valores_padrao'], 'sem_link' => $ctxBase['planos']['sem_link'], 'provas_liberadas' => count($ctxBase['provas'])], 'agora' => wa_fmt($agora)];
}

// ─────────────────────────────────────────────────────────────────────────────
// Ações da equipe sobre uma pessoa da fila
// ─────────────────────────────────────────────────────────────────────────────

const ATD_M_ACOES = ['enviada', 'pular', 'respondeu', 'encerrar', 'temperatura'];

/**
 * Registra o que a pessoa da equipe fez. 'enviada' e 'pular' guardam o passo (e não voltam); 'enviada' também registra o
 * contato em Pessoas Captadas ("chamado", WhatsApp). 'respondeu' e 'encerrar' registram o contato com a situação.
 * Devolve ['ok' => bool, 'erro' => ?string, 'ja' => bool].
 */
function atd_manual_acao(PDO $pdo, array $b, int $idusuario, string $usuarioNome, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $chave = is_string($b['chave'] ?? null) ? $b['chave'] : '';
    if (!atd_manual_chave_valida($chave)) return ['ok' => false, 'erro' => 'Pessoa inválida.', 'ja' => false];
    $acao = (string)($b['acao'] ?? '');
    if (!in_array($acao, ATD_M_ACOES, true)) return ['ok' => false, 'erro' => 'Ação inválida.', 'ja' => false];
    $tel = preg_replace('/\D/', '', (string)($b['telefone'] ?? ''));
    $ts = $agora->getTimestamp();
    $reg = atd_manual_registros($pdo, [$chave])[$chave] ?? ['passos' => []];
    $por = mb_substr(trim($usuarioNome), 0, 60);

    if ($acao === 'temperatura') {
        $v = (string)($b['valor'] ?? '');
        if ($v === 'auto') unset($reg['temperatura']);
        elseif (in_array($v, ATD_M_TEMPERATURAS, true)) $reg['temperatura'] = ['nivel' => $v, 'em' => wa_fmt($agora), 'por' => $por];
        else return ['ok' => false, 'erro' => 'Escolha quente, morno, frio ou automática.', 'ja' => false];
        atd_manual_registro_gravar($pdo, $chave, $reg);
        return ['ok' => true, 'erro' => null, 'ja' => false];
    }
    if ($acao === 'enviada' || $acao === 'pular') {
        $passo = (string)($b['passo'] ?? '');
        [$fid, $mid] = atd_passo_decodificar($passo);
        $f = atd_funil_por_id($pdo, $fid);
        $m = $f ? atd_manual_mensagem($f, $mid) : null;
        if (!$f || !$m || atd_passo($fid, $mid) !== $passo || !atd_manual_plano_do_passo($pdo, $f, $mid)) return ['ok' => false, 'erro' => 'Mensagem do funil não encontrada.', 'ja' => false];
        $antes = $reg['passos'][$passo]['estado'] ?? null;
        // repetir o mesmo toque não duplica; marcar como enviada um passo que tinha sido pulado vale
        if ($antes === 'enviada' || ($antes !== null && $acao === 'pular')) return ['ok' => true, 'erro' => null, 'ja' => true];
        $reg['passos'][$passo] = ['estado' => $acao === 'enviada' ? 'enviada' : 'pulada', 'em' => wa_fmt($agora), 'por' => $por];
        // enviou outra mensagem no lugar da sugerida: a sugerida fica resolvida (não conta como enviada nem volta para a fila)
        $sug = (string)($b['passo_sugerido'] ?? '');
        if ($acao === 'enviada' && $sug !== '' && $sug !== $passo && !isset($reg['passos'][$sug])) {
            [$sf, $sm] = atd_passo_decodificar($sug);
            $sfun = atd_funil_por_id($pdo, $sf);
            if ($sfun && atd_manual_mensagem($sfun, $sm) && atd_passo($sf, $sm) === $sug && atd_manual_plano_do_passo($pdo, $sfun, $sm)) {
                $reg['passos'][$sug] = ['estado' => 'substituida', 'por_passo' => $passo, 'em' => wa_fmt($agora), 'por' => $por];
            }
        }
        atd_manual_registro_gravar($pdo, $chave, $reg);
        if ($acao === 'enviada') {
            $r = trContatoRegistrar($pdo, ['chave' => $chave, 'status' => 'chamado', 'canal' => 'whatsapp_link', 'telefone' => $tel, 'nota' => 'Central: ' . $f['nome'] . ' · ' . atd_manual_nome_msg($m, $mid, array_column(atd_planos($pdo), null, 'id'))], $idusuario, $usuarioNome, $ts);
            if (!$r['ok']) return ['ok' => true, 'erro' => 'Marquei a mensagem como enviada, mas não consegui registrar o contato: ' . $r['erro'], 'ja' => false];
        }
        return ['ok' => true, 'erro' => null, 'ja' => false];
    }
    $status = $acao === 'respondeu' ? 'respondeu' : 'sem_interesse';
    $nota = $acao === 'respondeu' ? 'Central: a pessoa respondeu' : 'Central: encerrado pela fila de hoje';
    $r = trContatoRegistrar($pdo, ['chave' => $chave, 'status' => $status, 'canal' => 'whatsapp_link', 'telefone' => $tel, 'nota' => $nota], $idusuario, $usuarioNome, $ts);
    if (!$r['ok']) return ['ok' => false, 'erro' => 'Não consegui registrar: ' . $r['erro'], 'ja' => false];
    $reg[$acao === 'respondeu' ? 'respondeu_em' : 'encerrado_em'] = wa_fmt($agora);
    atd_manual_registro_gravar($pdo, $chave, $reg);
    return ['ok' => true, 'erro' => null, 'ja' => false];
}
