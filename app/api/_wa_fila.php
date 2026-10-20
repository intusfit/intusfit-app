<?php
/**
 * Laboratório do WhatsApp: envio manual de cada mensagem dos funis, automação por passo e fila de envio (biblioteca).
 *
 * O "_" no nome faz o .htaccess bloquear o acesso direto. Quem expõe isto é wa_teste.php (administrador) e wa_cron.php (cabeçalho X-Cron-Auth).
 *
 * Decisões que não são óbvias:
 *  - A automação nasce DESLIGADA. Existe uma chave geral (mestre) e, por passo de cada funil, "auto" ou "manual". Passo manual nunca sai sozinho:
 *    a fila só mostra que ele venceu e espera o clique do Luiz.
 *  - O contato de teste (Fulano Teste, 45 99957-4107, origem de consentimento "teste-admin") é o único que ignora horário comercial, limite diário
 *    e condições de funil. Para qualquer outra pessoa valem as regras de wa_decidir_envio, sem exceção.
 *  - Mensagem proativa (fora da janela de 24h) só sai por template aprovado na Meta. Sem template, só sai texto livre se a janela estiver aberta.
 *  - O processador "reserva" a linha da agenda (pendente -> enviando) antes de chamar a Meta, então duas execuções ao mesmo tempo
 *    (página aberta, CronJob, botão) nunca mandam a mesma mensagem duas vezes.
 *  - Nada aqui lê ou mostra token, segredo do app ou chave: só recebe a configuração já pronta de wa_config_whatsapp().
 *  - SQL portável (roda em SQLite nos testes e no MySQL da produção).
 */

require_once __DIR__ . '/_atendimento.php';

const WAF_TEL_TESTE = '5545999574107';
const WAF_NOME_TESTE = 'Fulano Teste';
const WAF_ORIGEM_TESTE = 'teste-admin';
const WAF_LOTE = 20;

// ─────────────────────────────────────────────────────────────────────────────
// Configuração: chave geral + automático/manual por passo (intus_config: atd_automacao)
// ─────────────────────────────────────────────────────────────────────────────

function waf_config(PDO $pdo): array {
    $c = atd_cfg_ler($pdo, 'atd_automacao', []);
    $passos = [];
    foreach ((array)($c['passos'] ?? []) as $k => $v) {
        if (is_string($k) && preg_match('#^[a-z0-9\-]{1,24}/[a-z0-9]{1,12}$#', $k) && $v === 'auto') $passos[$k] = 'auto';
    }
    return ['mestre' => !empty($c['mestre']), 'passos' => $passos, 'atualizado_em' => (string)($c['atualizado_em'] ?? '')];
}

function waf_config_gravar(PDO $pdo, array $d, ?DateTimeImmutable $agora = null): array {
    $atual = waf_config($pdo);
    $novo = ['mestre' => array_key_exists('mestre', $d) ? !empty($d['mestre']) : $atual['mestre'], 'passos' => $atual['passos']];
    if (isset($d['passos']) && is_array($d['passos'])) {
        $novo['passos'] = [];
        foreach ($d['passos'] as $k => $v) {
            if (is_string($k) && preg_match('#^[a-z0-9\-]{1,24}/[a-z0-9]{1,12}$#', $k) && $v === 'auto') $novo['passos'][$k] = 'auto';
        }
    }
    $novo['atualizado_em'] = wa_fmt($agora ?? wa_agora());
    atd_cfg_gravar($pdo, 'atd_automacao', $novo);
    return waf_config($pdo);
}

function waf_modo_passo(array $cfg, string $funilId, string $msgId): string {
    return (($cfg['passos'][$funilId . '/' . $msgId] ?? '') === 'auto') ? 'auto' : 'manual';
}

// ─────────────────────────────────────────────────────────────────────────────
// Contato de teste
// ─────────────────────────────────────────────────────────────────────────────

function waf_eh_teste(array $c): bool {
    return ($c['optin_origem'] ?? '') === WAF_ORIGEM_TESTE;
}

/** Cria (ou completa) o contato Fulano Teste. O consentimento é do próprio Luiz, dono do número de teste, e fica registrado como "teste-admin". */
function waf_contato_teste(PDO $pdo, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?? wa_agora();
    $tel = wa_normalizar_telefone(WAF_TEL_TESTE);
    $c = wa_contato_obter_ou_criar($pdo, $tel, WAF_NOME_TESTE, $agora);
    $campos = [];
    if (empty($c['optin_em'])) { $campos['optin_em'] = wa_fmt($agora); }
    if (($c['optin_origem'] ?? '') !== WAF_ORIGEM_TESTE) { $campos['optin_origem'] = WAF_ORIGEM_TESTE; }
    if ($campos) wa_contato_atualizar($pdo, (int)$c['idcontato'], $campos, $agora);
    return wa_contato_por_id($pdo, (int)$c['idcontato']);
}

function waf_contato_teste_existente(PDO $pdo): ?array {
    $c = wa_contato_por_telefone($pdo, wa_normalizar_telefone(WAF_TEL_TESTE));
    return ($c && waf_eh_teste($c)) ? $c : null;
}

/**
 * Volta o contato de teste ao zero para repetir o teste: apaga SÓ a agenda dele, tira o "saiu" e libera o robô.
 * O histórico de mensagens fica (é a prova do que foi enviado). Recusa qualquer contato que não seja o de teste.
 */
function waf_reiniciar_teste(PDO $pdo, ?DateTimeImmutable $agora = null): array {
    $c = waf_contato_teste_existente($pdo);
    if (!$c) throw new InvalidArgumentException('O contato de teste ainda não foi criado.');
    $agora = $agora ?? wa_agora();
    $st = $pdo->prepare('DELETE FROM intus_wa_agenda WHERE idcontato = ?');
    $st->execute([(int)$c['idcontato']]);
    wa_contato_atualizar($pdo, (int)$c['idcontato'], ['saiu_em' => null, 'modo' => 'robo', 'ultima_proativa_em' => null, 'precisa_humano' => 0, 'motivo_humano' => null, 'etapa' => 'novo'], $agora);
    return ['agenda_apagada' => (int)$st->rowCount()];
}

// ─────────────────────────────────────────────────────────────────────────────
// Texto e variáveis de uma mensagem de funil
// ─────────────────────────────────────────────────────────────────────────────

/** Variáveis {{1}}.. de um funil para um contato. {{1}} nome, {{2}} link do app, {{3}} quem indicou. */
function waf_variaveis(array $contato): array {
    return ['1' => atd_primeiro_nome((string)($contato['nome'] ?? '')) ?: 'tudo bem', '2' => ATD_LINK_APP, '3' => 'um amigo'];
}

/** Parâmetros do template, na ordem {{1}}, {{2}}... até a maior variável usada no texto. */
function waf_parametros_do_texto(string $textoModelo, array $vars): array {
    preg_match_all('/\{\{(\d)\}\}/', $textoModelo, $m);
    $max = $m[1] ? max(array_map('intval', $m[1])) : 0;
    $out = [];
    for ($i = 1; $i <= $max; $i++) $out[] = (string)($vars[(string)$i] ?? '');
    return $out;
}

function waf_achar_mensagem(array $funil, string $msgId): ?array {
    foreach ($funil['mensagens'] ?? [] as $m) { if (($m['id'] ?? '') === $msgId) return $m; }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Envio
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Envia UMA mensagem de um funil para um contato.
 * $via: 'auto' (template se a mensagem tem template, senão texto livre), 'template' ou 'texto'.
 * $forcar = true é o envio manual do administrador: ignora horário, limite do dia, condições e modo humano/robô;
 * NUNCA ignora pedido de saída (saiu_em), que é do lead.
 * Devolve ['ok','via','wa_id','erro','motivo','texto'].
 */
function waf_enviar_mensagem(PDO $pdo, array $cfgWa, array $contato, array $funil, array $msg, string $via = 'auto', bool $forcar = false, ?callable $http = null, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?? wa_agora();
    $contato = wa_contato_por_id($pdo, (int)$contato['idcontato']) ?: $contato;
    $vars = waf_variaveis($contato);
    $texto = atd_renderizar((string)$msg['texto'], $vars);
    $r = ['ok' => false, 'via' => null, 'wa_id' => null, 'erro' => null, 'motivo' => null, 'texto' => $texto];

    if (!empty($contato['saiu_em'])) { $r['motivo'] = 'saiu'; $r['erro'] = 'Esta pessoa pediu para não receber mais mensagens.'; return $r; }
    if (trim($texto) === '') { $r['motivo'] = 'sem_texto'; $r['erro'] = 'A mensagem está sem texto.'; return $r; }

    $janela = wa_janela_aberta($contato['ultima_entrada_em'] ?? null, $agora);
    $template = (string)($msg['template'] ?? '');
    if ($via === 'auto') $via = $template !== '' ? 'template' : 'texto';
    if ($via === 'template' && $template === '') { $r['motivo'] = 'sem_template'; $r['erro'] = 'Esta mensagem não tem template cadastrado.'; return $r; }
    if ($via === 'texto' && !$janela) { $r['motivo'] = 'fora_da_janela'; $r['erro'] = 'Texto livre só vale até 24 h depois da última mensagem da pessoa. Use o template.'; return $r; }

    if (!$forcar) {
        $aj = wa_ajustes($pdo);
        [$pode, $motivo] = wa_decidir_envio($contato, $agora, [
            'tipo' => $via === 'texto' ? 'texto' : 'template', 'proativa' => $via === 'template', 'automatica' => true,
            'horario_ini' => (int)$aj['horario_ini'], 'horario_fim' => (int)$aj['horario_fim'], 'max_proativas_dia' => (int)$aj['max_proativas_dia'],
        ]);
        if (!$pode) { $r['motivo'] = $motivo; $r['erro'] = $motivo; return $r; }
    }

    if ($via === 'template') {
        $res = wa_enviar_template($pdo, $cfgWa, $contato, $template, waf_parametros_do_texto((string)$msg['texto'], $vars), $texto, 'sequencia', 'pt_BR', $http, $agora);
    } else {
        $res = wa_enviar_texto($pdo, $cfgWa, $contato, $texto, 'humano', $http, $agora);
    }
    $r['via'] = $via; $r['ok'] = $res['ok']; $r['wa_id'] = $res['wa_id']; $r['erro'] = $res['erro'];
    if ($res['ok'] && $via === 'template') {
        wa_contato_atualizar($pdo, (int)$contato['idcontato'], ['ultima_proativa_em' => wa_fmt($agora)], $agora);
    }
    return $r;
}

/** Texto livre avulso (resposta do administrador dentro da janela de 24 h). */
function waf_enviar_texto_livre(PDO $pdo, array $cfgWa, array $contato, string $texto, ?callable $http = null, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?? wa_agora();
    $contato = wa_contato_por_id($pdo, (int)$contato['idcontato']) ?: $contato;
    $texto = atd_texto_limpo($texto, 1024);
    if ($texto === '') return ['ok' => false, 'erro' => 'Escreva a mensagem.', 'motivo' => 'sem_texto'];
    if (!empty($contato['saiu_em'])) return ['ok' => false, 'erro' => 'Esta pessoa pediu para não receber mais mensagens.', 'motivo' => 'saiu'];
    if (!wa_janela_aberta($contato['ultima_entrada_em'] ?? null, $agora)) return ['ok' => false, 'erro' => 'Fora da janela de 24 h: a pessoa precisa escrever primeiro, ou use um template.', 'motivo' => 'fora_da_janela'];
    $res = wa_enviar_texto($pdo, $cfgWa, $contato, $texto, 'humano', $http, $agora);
    return ['ok' => $res['ok'], 'wa_id' => $res['wa_id'], 'erro' => $res['erro'], 'motivo' => $res['ok'] ? null : 'meta'];
}

/** Template "hello_world", que toda conta nova da Meta já tem aprovado: prova de que o número alcança o aparelho. */
function waf_enviar_hello(PDO $pdo, array $cfgWa, array $contato, ?callable $http = null, ?DateTimeImmutable $agora = null): array {
    $contato = wa_contato_por_id($pdo, (int)$contato['idcontato']) ?: $contato;
    if (!empty($contato['saiu_em'])) return ['ok' => false, 'erro' => 'Esta pessoa pediu para não receber mais mensagens.', 'motivo' => 'saiu'];
    $res = wa_enviar_template($pdo, $cfgWa, $contato, 'hello_world', [], 'Mensagem de teste da Meta (hello_world)', 'humano', 'en_US', $http, $agora);
    return ['ok' => $res['ok'], 'wa_id' => $res['wa_id'], 'erro' => $res['erro'], 'motivo' => $res['ok'] ? null : 'meta'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Agenda
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Coloca na agenda as mensagens "agendadas" de um funil para um contato.
 * $intervaloMin > 0 é o teste rápido: a 1ª sai agora e as seguintes a cada N minutos, sem olhar dia, hora nem condição.
 * Sem intervalo vale a agenda de verdade do funil (dias e horas, dentro do horário comercial).
 * Passo que já existe na agenda do contato não é repetido (a chave é contato + passo). Devolve ['criados','ja_existiam','itens'].
 */
function waf_agendar(PDO $pdo, array $contato, array $funil, ?DateTimeImmutable $base = null, int $intervaloMin = 0): array {
    $base = $base ?? wa_agora();
    $aj = wa_ajustes($pdo);
    $itens = [];
    if ($intervaloMin > 0) {
        $i = 0;
        foreach ($funil['mensagens'] ?? [] as $m) {
            if (($m['tipo'] ?? 'agendada') !== 'agendada') continue;
            $itens[] = [atd_passo((string)$funil['id'], (string)$m['id']), $base->modify('+' . ($i * $intervaloMin) . ' minutes'), 'todos'];
            $i++;
        }
    } else {
        $itens = atd_agenda_do_funil($funil, $base, (int)$aj['horario_ini'], (int)$aj['horario_fim']);
    }
    $criados = 0; $ja = 0;
    foreach ($itens as [$passo, $quando, $cond]) {
        $st = $pdo->prepare('SELECT 1 FROM intus_wa_agenda WHERE idcontato = ? AND passo = ? LIMIT 1');
        $st->execute([(int)$contato['idcontato'], $passo]);
        if ($st->fetchColumn()) { $ja++; continue; }
        $pdo->prepare("INSERT INTO intus_wa_agenda (idcontato, passo, enviar_em, condicao, status) VALUES (?, ?, ?, ?, 'pendente')")
            ->execute([(int)$contato['idcontato'], $passo, wa_fmt($quando), $cond]);
        $criados++;
    }
    return ['criados' => $criados, 'ja_existiam' => $ja, 'itens' => array_map(fn($x) => ['passo' => $x[0], 'enviar_em' => wa_fmt($x[1]), 'condicao' => $x[2]], $itens)];
}

function waf_agenda_do_contato(PDO $pdo, int $idcontato): array {
    $st = $pdo->prepare('SELECT idagenda, passo, enviar_em, condicao, status, motivo, enviada_em FROM intus_wa_agenda WHERE idcontato = ? ORDER BY enviar_em ASC, idagenda ASC');
    $st->execute([$idcontato]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Cancela o que ainda está pendente na agenda do contato. */
function waf_agenda_cancelar(PDO $pdo, int $idcontato): int {
    $st = $pdo->prepare("UPDATE intus_wa_agenda SET status = 'cancelada', motivo = 'cancelada pelo administrador' WHERE idcontato = ? AND status = 'pendente'");
    $st->execute([$idcontato]);
    return (int)$st->rowCount();
}

// ─────────────────────────────────────────────────────────────────────────────
// Condições de funil
// ─────────────────────────────────────────────────────────────────────────────

function waf_treinou(PDO $pdo, array $contato): bool {
    $id = (int)($contato['idatleta'] ?? 0);
    if ($id <= 0) return false;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM intus_sessao WHERE idatleta = ? AND (nao_contar IS NULL OR nao_contar = 0)');
        $st->execute([$id]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/** "Sem resposta" = a pessoa não escreveu nada depois da primeira mensagem do funil que foi enviada a ela. */
function waf_sem_resposta(PDO $pdo, array $contato): bool {
    $st = $pdo->prepare("SELECT MIN(criado_em) FROM intus_wa_mensagem WHERE idcontato = ? AND direcao = 'S' AND status <> 'falhou'");
    $st->execute([(int)$contato['idcontato']]);
    $primeira = $st->fetchColumn();
    if (!$primeira) return true;
    $st = $pdo->prepare("SELECT COUNT(*) FROM intus_wa_mensagem WHERE idcontato = ? AND direcao = 'E' AND criado_em > ?");
    $st->execute([(int)$contato['idcontato'], $primeira]);
    return (int)$st->fetchColumn() === 0;
}

function waf_condicao_ok(PDO $pdo, array $contato, string $condicao): bool {
    switch ($condicao) {
        case 'sem_treino': return !waf_treinou($pdo, $contato);
        case 'com_treino': return waf_treinou($pdo, $contato);
        case 'sem_resposta': return waf_sem_resposta($pdo, $contato);
        default: return true;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Processador da fila
// ─────────────────────────────────────────────────────────────────────────────

function waf_agenda_marcar(PDO $pdo, int $idagenda, string $status, ?string $motivo, ?string $enviadaEm = null): void {
    $pdo->prepare('UPDATE intus_wa_agenda SET status = ?, motivo = ?, enviada_em = ? WHERE idagenda = ?')
        ->execute([$status, $motivo !== null ? mb_substr($motivo, 0, 100) : null, $enviadaEm, $idagenda]);
}

function waf_agenda_reter(PDO $pdo, int $idagenda, string $motivo): void {
    $pdo->prepare("UPDATE intus_wa_agenda SET motivo = ? WHERE idagenda = ? AND status = 'pendente'")->execute([mb_substr($motivo, 0, 100), $idagenda]);
}

/**
 * Manda o que venceu e está em modo automático. Só age com a chave geral ligada.
 * Devolve ['mestre','processadas','enviadas','itens'=>[{idagenda,passo,resultado,motivo}],'manuais_vencidas'].
 */
function waf_processar(PDO $pdo, ?array $cfgWa, ?DateTimeImmutable $agora = null, ?callable $http = null, int $limite = WAF_LOTE): array {
    $agora = $agora ?? wa_agora();
    $cfg = waf_config($pdo);
    $saida = ['mestre' => $cfg['mestre'], 'processadas' => 0, 'enviadas' => 0, 'itens' => [], 'manuais_vencidas' => 0];

    $st = $pdo->prepare("SELECT * FROM intus_wa_agenda WHERE status = 'pendente' AND enviar_em <= ? ORDER BY enviar_em ASC, idagenda ASC LIMIT " . max(1, min(100, $limite * 3)));
    $st->execute([wa_fmt($agora)]);
    $vencidas = $st->fetchAll(PDO::FETCH_ASSOC);

    if (!$cfg['mestre'] || !$cfgWa) {
        foreach ($vencidas as $a) { [$f, $m] = atd_passo_decodificar((string)$a['passo']); if (waf_modo_passo($cfg, $f, $m) !== 'auto' || !$cfg['mestre']) $saida['manuais_vencidas']++; }
        if ($cfg['mestre'] && !$cfgWa) $saida['erro'] = 'WhatsApp não configurado.';
        return $saida;
    }

    $aj = wa_ajustes($pdo);
    foreach ($vencidas as $a) {
        if ($saida['processadas'] >= $limite) break;
        [$fid, $mid] = atd_passo_decodificar((string)$a['passo']);
        $item = ['idagenda' => (int)$a['idagenda'], 'passo' => $a['passo'], 'resultado' => 'mantida', 'motivo' => null];

        if (waf_modo_passo($cfg, $fid, $mid) !== 'auto') { $saida['manuais_vencidas']++; $item['motivo'] = 'manual'; continue; }

        $contato = wa_contato_por_id($pdo, (int)$a['idcontato']);
        if (!$contato) { waf_agenda_marcar($pdo, (int)$a['idagenda'], 'cancelada', 'contato inexistente'); $item['resultado'] = 'cancelada'; $item['motivo'] = 'contato inexistente'; $saida['itens'][] = $item; continue; }
        $teste = waf_eh_teste($contato);

        $funil = atd_funil_por_id($pdo, $fid);
        $msg = $funil ? waf_achar_mensagem($funil, $mid) : null;
        if (!$funil || !$msg) { waf_agenda_marcar($pdo, (int)$a['idagenda'], 'cancelada', 'mensagem do funil não existe mais'); $item['resultado'] = 'cancelada'; $item['motivo'] = 'mensagem do funil não existe mais'; $saida['itens'][] = $item; continue; }
        if (($funil['status'] ?? '') === 'pausado' || (($funil['status'] ?? '') === 'rascunho' && !$teste)) {
            waf_agenda_reter($pdo, (int)$a['idagenda'], 'funil_' . $funil['status']); $item['motivo'] = 'funil_' . $funil['status']; $saida['itens'][] = $item; continue;
        }

        if (!$teste) {
            [$pode, $motivo] = wa_decidir_envio($contato, $agora, [
                'tipo' => 'template', 'proativa' => true, 'automatica' => true,
                'horario_ini' => (int)$aj['horario_ini'], 'horario_fim' => (int)$aj['horario_fim'], 'max_proativas_dia' => (int)$aj['max_proativas_dia'],
            ]);
            if (!$pode) {
                if (in_array($motivo, ['fora_do_horario', 'limite_diario'], true)) { waf_agenda_reter($pdo, (int)$a['idagenda'], $motivo); $item['motivo'] = $motivo; }
                else { waf_agenda_marcar($pdo, (int)$a['idagenda'], 'cancelada', $motivo); $item['resultado'] = 'cancelada'; $item['motivo'] = $motivo; $saida['processadas']++; }
                $saida['itens'][] = $item; continue;
            }
            if (!waf_condicao_ok($pdo, $contato, (string)$a['condicao'])) {
                waf_agenda_marcar($pdo, (int)$a['idagenda'], 'pulada', 'condicao_' . $a['condicao']); $item['resultado'] = 'pulada'; $item['motivo'] = 'condicao_' . $a['condicao']; $saida['processadas']++; $saida['itens'][] = $item; continue;
            }
            if (($msg['template'] ?? '') === '' || empty($msg['aprovada'])) {
                waf_agenda_reter($pdo, (int)$a['idagenda'], 'sem_template_aprovada'); $item['motivo'] = 'sem_template_aprovada'; $saida['itens'][] = $item; continue;
            }
        } elseif (!empty($contato['saiu_em'])) {
            waf_agenda_marcar($pdo, (int)$a['idagenda'], 'cancelada', 'saiu'); $item['resultado'] = 'cancelada'; $item['motivo'] = 'saiu'; $saida['processadas']++; $saida['itens'][] = $item; continue;
        }

        // reserva a linha: só quem conseguir trocar pendente -> enviando manda
        $res = $pdo->prepare("UPDATE intus_wa_agenda SET status = 'enviando' WHERE idagenda = ? AND status = 'pendente'");
        $res->execute([(int)$a['idagenda']]);
        if ($res->rowCount() !== 1) continue;

        $saida['processadas']++;
        try {
            $env = waf_enviar_mensagem($pdo, $cfgWa, $contato, $funil, $msg, 'auto', true, $http, $agora);
        } catch (Throwable $e) {
            $env = ['ok' => false, 'erro' => 'erro interno (' . wa_log_erro($e) . ')', 'motivo' => 'excecao'];
        }
        if ($env['ok']) { waf_agenda_marcar($pdo, (int)$a['idagenda'], 'enviada', null, wa_fmt($agora)); $item['resultado'] = 'enviada'; $saida['enviadas']++; }
        else { waf_agenda_marcar($pdo, (int)$a['idagenda'], 'falhou', (string)($env['erro'] ?? 'falhou')); $item['resultado'] = 'falhou'; $item['motivo'] = (string)($env['erro'] ?? 'falhou'); }
        $saida['itens'][] = $item;
    }
    return $saida;
}

// ─────────────────────────────────────────────────────────────────────────────
// Estado para a tela
// ─────────────────────────────────────────────────────────────────────────────

const WAF_STATUS_ROTULO = ['enviando' => 'Enviando', 'enviada' => 'Aceita pela Meta', 'entregue' => 'Entregue no aparelho', 'lida' => 'Lida', 'falhou' => 'Falhou', 'recebida' => 'Recebida'];

function waf_mensagens(PDO $pdo, int $idcontato, int $limite = 60): array {
    $st = $pdo->prepare('SELECT idmensagem, direcao, origem, tipo, texto, template, wa_id, status, erro, criado_em FROM intus_wa_mensagem WHERE idcontato = ? ORDER BY idmensagem DESC LIMIT ' . max(1, min(200, $limite)));
    $st->execute([$idcontato]);
    $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    foreach ($rows as &$r) { $r['rotulo'] = $r['direcao'] === 'E' ? 'Recebida' : (WAF_STATUS_ROTULO[$r['status']] ?? $r['status']); }
    unset($r);
    return $rows;
}

/** Tudo que a tela precisa, sem nenhum segredo. */
function waf_estado(PDO $pdo, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?? wa_agora();
    $cfgWa = wa_config_whatsapp($pdo);
    $cfg = waf_config($pdo);
    $c = waf_contato_teste_existente($pdo);
    $funis = [];
    foreach (atd_funis($pdo) as $f) {
        $msgs = [];
        foreach ($f['mensagens'] ?? [] as $m) {
            $msgs[] = ['id' => $m['id'], 'nome' => $m['nome'], 'tipo' => $m['tipo'] ?? 'agendada', 'dia' => $m['dia'] ?? null, 'hora' => $m['hora'] ?? null, 'condicao' => $m['condicao'] ?? 'todos',
                       'template' => $m['template'] ?? '', 'aprovada' => !empty($m['aprovada']), 'texto' => $m['texto'] ?? '', 'modo' => waf_modo_passo($cfg, $f['id'], $m['id'])];
        }
        $funis[] = ['id' => $f['id'], 'nome' => $f['nome'], 'status' => $f['status'], 'mensagens' => $msgs];
    }
    $out = [
        'whatsapp_configurado' => $cfgWa !== null,
        'webhook_configurado' => $cfgWa !== null && !empty($cfgWa['verify_token']) && !empty($cfgWa['app_secret']),
        'automacao' => $cfg, 'funis' => $funis, 'agora' => wa_fmt($agora),
        'telefone_teste' => WAF_TEL_TESTE, 'nome_teste' => WAF_NOME_TESTE, 'contato' => null, 'mensagens' => [], 'agenda' => [], 'janela_aberta' => false,
    ];
    if ($c) {
        $out['contato'] = ['idcontato' => (int)$c['idcontato'], 'nome' => $c['nome'], 'telefone' => $c['telefone'], 'optin_em' => $c['optin_em'], 'saiu_em' => $c['saiu_em'],
                           'modo' => $c['modo'], 'ultima_entrada_em' => $c['ultima_entrada_em'], 'ultima_proativa_em' => $c['ultima_proativa_em']];
        $out['mensagens'] = waf_mensagens($pdo, (int)$c['idcontato']);
        $out['agenda'] = waf_agenda_do_contato($pdo, (int)$c['idcontato']);
        $out['janela_aberta'] = wa_janela_aberta($c['ultima_entrada_em'] ?? null, $agora);
    }
    return $out;
}
