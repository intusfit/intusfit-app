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

const ATD_M_ORIGENS = ['organico', 'pago', 'todos'];
const ATD_M_ULTIMO_PASSO_DIAS = 2;       // quanto tempo o último passo do funil continua valendo
const ATD_M_ESPERA_RESPOSTA_DIAS = 3;
const ATD_M_RECUP_FUNIL = 'recuperacao-teste';   // funil de quem fez o teste, nunca recebeu mensagem e já passou da hora
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
    foreach ($funil['mensagens'] ?? [] as $m) { if (($m['id'] ?? '') === $msgId) return $m; }
    return null;
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
function atd_manual_biblioteca(array $funis): array {
    $out = [];
    foreach ($funis as $f) {
        foreach ($f['mensagens'] ?? [] as $m) {
            if (trim((string)($m['texto'] ?? '')) === '') continue;
            $out[] = [
                'passo' => atd_passo((string)$f['id'], (string)$m['id']), 'funil' => $f['id'], 'funil_nome' => $f['nome'], 'funil_status' => $f['status'] ?? 'rascunho',
                'mensagem' => $m['id'], 'nome' => $m['nome'], 'aprovada' => !empty($m['aprovada']), 'modelo' => $m,
            ];
        }
    }
    return $out;
}

/** Texto de cada mensagem da biblioteca já com o nome da pessoa: [passo => texto]. */
function atd_manual_opcoes(array $biblioteca, string $nome, string $indicador): array {
    $o = [];
    foreach ($biblioteca as $b) $o[$b['passo']] = atd_render_mensagem($b['modelo'], $nome, $indicador);
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

/** Dias inteiros desde o fim do teste (negativo se ainda não acabou), ou null se a pessoa não tem teste. */
function atd_manual_dias_do_fim_do_teste(array $p, DateTimeImmutable $agora): ?int {
    $vence = (string)($p['teste']['vence'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $vence)) return null;
    try { $v = new DateTimeImmutable(substr($vence, 0, 10) . ' 00:00:00', wa_tz()); } catch (Throwable $e) { return null; }
    return (int)floor(($agora->setTime(0, 0, 0)->getTimestamp() - $v->getTimestamp()) / 86400);
}

/**
 * Recuperação: conta cujo teste grátis terminou há ATD_M_RECUP_MIN_DIAS dias ou mais, que não virou cliente e a quem NADA foi enviado
 * (nenhuma mensagem marcada aqui, nenhum contato em Pessoas Captadas, nenhuma mensagem pelo WhatsApp da Intus). Quem já começou a receber
 * a recuperação continua nela até o fim da sequência.
 */
function atd_manual_em_recuperacao(array $p, array $reg, DateTimeImmutable $agora): bool {
    if (($p['tipo'] ?? '') !== 'conta' || ($p['etapa'] ?? '') !== 'teste_expirado') return false;
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
    $vence = (string)($p['teste']['vence'] ?? '');
    try { $base = new DateTimeImmutable(substr($vence, 0, 10) . ' 00:00:00', wa_tz()); } catch (Throwable $e) { return null; }
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
    $L = atd_leads($pdo, $cfg['desde'], $agora->format('Y-m-d'), $agora);
    $funis = atd_funis($pdo);
    $porId = [];
    foreach ($funis as $f) $porId[$f['id']] = $f;

    $candidatos = []; $res = ['leads_no_periodo' => count($L['pessoas']), 'fora_da_origem' => 0, 'sem_telefone' => 0, 'pediram_para_sair' => 0, 'encerrados' => 0, 'conversa_de_pessoa' => 0, 'sem_passo_agora' => 0, 'em_recuperacao' => 0];
    foreach ($L['pessoas'] as $p) {
        $org = atd_manual_origem($p);
        if ($cfg['origem'] !== 'todos' && $org !== $cfg['origem']) { $res['fora_da_origem']++; continue; }
        if (!($p['wa']['telefone_valido'] ?? false)) { $res['sem_telefone']++; continue; }
        if (!empty($p['wa']['saiu'])) { $res['pediram_para_sair']++; continue; }
        if (($p['grupo_contato'] ?? '') === 'encerrado') { $res['encerrados']++; continue; }
        if (in_array($p['etapa'], ['cliente', 'negociando'], true)) { $res['conversa_de_pessoa']++; continue; }
        $p['origem_tipo'] = $org;
        $candidatos[] = $p;
    }
    $regs = atd_manual_registros($pdo, array_column($candidatos, 'chave'));
    $biblioteca = atd_manual_biblioteca($funis);

    $paraEnviar = []; $aguardando = []; $resultado = []; $enviadasHoje = 0; $hoje = $agora->format('Y-m-d');
    foreach ($candidatos as $p) {
        $reg = $regs[$p['chave']] ?? ['passos' => []];
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

        $frec = $porId[ATD_M_RECUP_FUNIL] ?? null;
        $emRec = $frec && atd_manual_em_recuperacao($p, $reg, $agora);
        $a = $emRec ? atd_manual_passo_recuperacao($frec, $reg, $p, $agora, (int)$aj['horario_ini'], (int)$aj['horario_fim']) : atd_manual_passo_agora($agenda, $reg, $p, $agora);
        if ($emRec) $res['em_recuperacao']++;
        if ($a) {
            $f = $porId[$a[3]] ?? null; $m = $f ? atd_manual_mensagem($f, $a[4]) : null;
            if ($f && $m && trim((string)$m['texto']) !== '') {
                $texto = atd_render_mensagem($m, (string)$p['nome'], (string)($p['indicado_por'] ?? ''));
                $paraEnviar[] = [
                    'chave' => $p['chave'], 'tipo' => $p['tipo'], 'id' => $p['id'], 'nome' => $p['nome'], 'whatsapp' => $p['whatsapp'], 'telefone' => $p['telefone'],
                    'etapa' => $p['etapa'], 'etapa_rotulo' => $p['etapa_rotulo'], 'canal' => $p['canal'], 'campanha' => $p['utm']['campaign'] ?? null, 'origem_tipo' => $p['origem_tipo'],
                    'treinos' => $p['treinos'] ?? 0, 'criado_em' => $p['criado_em'],
                    'funil' => $f['id'], 'funil_nome' => $f['nome'], 'funil_status' => $f['status'], 'passo' => $a[0], 'mensagem' => $m['id'], 'mensagem_nome' => $m['nome'],
                    'aprovada' => !empty($m['aprovada']), 'lado' => $a[5] ?? null, 'texto' => $texto, 'devida_em' => wa_fmt($a[1]),
                    'atraso_horas' => max(0, (int)floor(($agora->getTimestamp() - $a[1]->getTimestamp()) / 3600)),
                    'ja_contatado' => ($p['grupo_contato'] ?? '') === 'contatado' || ($p['grupo_contato'] ?? '') === 'retorno',
                    'recuperacao' => $emRec ? ['dias' => atd_manual_dias_do_fim_do_teste($p, $agora)] : null,
                    'opcoes' => atd_manual_opcoes($biblioteca, (string)$p['nome'], (string)($p['indicado_por'] ?? '')),
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
                    'passo' => $ult['passo'], 'mensagem_nome' => $m['nome'] ?? $mid, 'funil_nome' => $f['nome'] ?? $fid, 'enviada_em' => $ult['em'], 'por' => $ult['por'] ?? '',
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
        $placar[] = $r + ['funil_nome' => $f['nome'] ?? $fid, 'mensagem_nome' => $m['nome'] ?? $mid, 'taxa' => $r['enviadas'] ? round($r['responderam'] / $r['enviadas'] * 100, 1) : null];
    }
    usort($placar, fn($a, $b) => strcmp($a['passo'], $b['passo']));

    $mestre = false;
    $auto = atd_cfg_ler($pdo, 'atd_automacao', []);
    if (is_array($auto)) $mestre = !empty($auto['mestre']);
    return [
        'cfg' => $cfg, 'agora' => wa_fmt($agora), 'no_horario' => wa_hora_permitida($agora, (int)$aj['horario_ini'], (int)$aj['horario_fim']),
        'horario' => [(int)$aj['horario_ini'], (int)$aj['horario_fim']],
        'para_enviar' => $paraEnviar, 'aguardando' => $aguardando, 'placar' => $placar,
        'biblioteca' => array_map(fn($b) => array_diff_key($b, ['modelo' => 1]), $biblioteca),
        'resumo' => $res + ['para_enviar' => count($paraEnviar), 'aguardando' => count($aguardando), 'enviadas_hoje' => $enviadasHoje],
        'meta' => ['usada' => false, 'configurada' => wa_config_whatsapp($pdo) !== null, 'automacao_ligada' => $mestre],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Ações da equipe sobre uma pessoa da fila
// ─────────────────────────────────────────────────────────────────────────────

const ATD_M_ACOES = ['enviada', 'pular', 'respondeu', 'encerrar'];

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

    if ($acao === 'enviada' || $acao === 'pular') {
        $passo = (string)($b['passo'] ?? '');
        [$fid, $mid] = atd_passo_decodificar($passo);
        $f = atd_funil_por_id($pdo, $fid);
        $m = $f ? atd_manual_mensagem($f, $mid) : null;
        if (!$f || !$m || atd_passo($fid, $mid) !== $passo) return ['ok' => false, 'erro' => 'Mensagem do funil não encontrada.', 'ja' => false];
        $antes = $reg['passos'][$passo]['estado'] ?? null;
        // repetir o mesmo toque não duplica; marcar como enviada um passo que tinha sido pulado vale
        if ($antes === 'enviada' || ($antes !== null && $acao === 'pular')) return ['ok' => true, 'erro' => null, 'ja' => true];
        $reg['passos'][$passo] = ['estado' => $acao === 'enviada' ? 'enviada' : 'pulada', 'em' => wa_fmt($agora), 'por' => $por];
        // enviou outra mensagem no lugar da sugerida: a sugerida fica resolvida (não conta como enviada nem volta para a fila)
        $sug = (string)($b['passo_sugerido'] ?? '');
        if ($acao === 'enviada' && $sug !== '' && $sug !== $passo && !isset($reg['passos'][$sug])) {
            [$sf, $sm] = atd_passo_decodificar($sug);
            $sfun = atd_funil_por_id($pdo, $sf);
            if ($sfun && atd_manual_mensagem($sfun, $sm) && atd_passo($sf, $sm) === $sug) {
                $reg['passos'][$sug] = ['estado' => 'substituida', 'por_passo' => $passo, 'em' => wa_fmt($agora), 'por' => $por];
            }
        }
        atd_manual_registro_gravar($pdo, $chave, $reg);
        if ($acao === 'enviada') {
            $r = trContatoRegistrar($pdo, ['chave' => $chave, 'status' => 'chamado', 'canal' => 'whatsapp_link', 'telefone' => $tel, 'nota' => 'Central: ' . $f['nome'] . ' · ' . $m['nome']], $idusuario, $usuarioNome, $ts);
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
