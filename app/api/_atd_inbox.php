<?php
/**
 * Caixa de entrada da Central de atendimento, SEM a Meta (orgânico, manual).
 *
 * A equipe conversa pelo WhatsApp da Intus como sempre. Aqui ela cola o que a pessoa escreveu, a Cora (o mesmo robô de _atd_robo.php,
 * com as mesmas regras) sugere a resposta ou avisa que a conversa é para uma pessoa, e quem manda é a equipe, pelo link wa.me, marcando
 * "Já enviei". Nada chama a API da Meta e nada é enviado sozinho.
 *
 * Onde fica cada coisa (nenhuma tabela nova):
 *  - Mensagens de verdade (o que a pessoa escreveu e o que a equipe enviou) em intus_wa_mensagem, no mesmo contato (intus_wa_contato) que a Meta
 *    usará depois, então a conversa continua inteira quando a API for ligada. wa_id nulo, processada = 1 (o robô da Meta nunca as reprocessa).
 *    Quem escreveu: entrada origem 'lead'; saída origem 'robo' (enviada como a Cora sugeriu, sem mudar nada) ou 'pessoa' (escrita ou editada pela equipe).
 *  - Rascunho da resposta e "resolvida sem resposta" em intus_config, chave atd_inbox_<idcontato>. Rascunho não é mensagem: as métricas
 *    contam toda linha de saída como enviada, então só o que foi mesmo enviado entra na tabela.
 *  - Custo das sugestões: atd_uso_somar (aba Automação da Central), como o laboratório de copy.
 *
 * Quem entra na caixa: qualquer contato com mensagem. Conversas novas nascem de um lead (chave a<idatleta> ou l<idlead> da Fila de hoje,
 * orgânico por padrão) ou de um telefone digitado.
 */

require_once __DIR__ . '/_ensaio_modelos.php';   // preços por modelo (e _atd_robo.php, _wa.php, _atendimento.php)
require_once __DIR__ . '/_atd_manual.php';

const INBOX_MAX_TEXTO = 3000;
const INBOX_MAX_SUGESTOES_DIA = 200;     // trava de custo: sugestões da Cora por dia, somando todas as conversas
const INBOX_MENSAGENS = 150;             // mensagens mostradas por conversa

function inbox_chave(int $idcontato): string { return 'atd_inbox_' . $idcontato; }
function inbox_estado(PDO $pdo, int $idcontato): array {
    $e = atd_cfg_ler($pdo, inbox_chave($idcontato), []);
    return is_array($e) ? $e : [];
}
function inbox_gravar_estado(PDO $pdo, int $idcontato, array $e): void {
    $e = array_filter($e, fn($v) => $v !== null);
    if ($e) atd_cfg_gravar($pdo, inbox_chave($idcontato), $e); else atd_cfg_apagar($pdo, inbox_chave($idcontato));
}

/** Telefone válido de WhatsApp do Brasil ou erro para a tela. */
function inbox_telefone($raw): string {
    $t = wa_normalizar_telefone($raw);
    if ($t === '' || strlen($t) < 12) throw new InvalidArgumentException('Telefone inválido. Use o DDD e o número, por exemplo 45 99999-9999.');
    return $t;
}

/** Contato de uma conversa nova (ou o que já existe com o mesmo telefone). */
function inbox_contato_novo(PDO $pdo, string $telefone, string $nome, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $tel = inbox_telefone($telefone);
    $nome = atd_texto_limpo($nome, 150);
    return wa_contato_obter_ou_criar($pdo, $tel, $nome !== '' ? $nome : null, $agora);
}

/** Contato de um lead da Fila de hoje (chave a<idatleta> ou l<idlead>). Liga o contato ao lead para a Central mostrar a mesma pessoa nos dois lugares. */
function inbox_contato_do_lead(PDO $pdo, string $chave, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $r = atd_manual_perfil($pdo, $chave, $agora);
    if (!$r) throw new InvalidArgumentException('Pessoa não encontrada.');
    $p = $r['pessoa'];
    $c = inbox_contato_novo($pdo, (string)($p['whatsapp'] ?? $p['telefone'] ?? ''), (string)($p['nome'] ?? ''), $agora);
    $upd = [];
    $id = (int)substr($chave, 1);
    if ($chave[0] === 'a' && empty($c['idatleta'])) $upd['idatleta'] = $id;
    if ($chave[0] === 'l' && empty($c['idlead'])) $upd['idlead'] = $id;
    if (!empty($p['objetivo']) && empty($c['objetivo'])) $upd['objetivo'] = mb_substr((string)$p['objetivo'], 0, 100);
    if ($upd) { wa_contato_atualizar($pdo, (int)$c['idcontato'], $upd, $agora); $c = wa_contato_por_id($pdo, (int)$c['idcontato']); }
    return $c;
}

/** Grava o que a pessoa escreveu (colado pela equipe). Trata "SAIR" como o webhook: a pessoa não recebe mais nada daqui. */
function inbox_registrar_lead(PDO $pdo, int $idcontato, string $texto, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $c = wa_contato_por_id($pdo, $idcontato);
    if (!$c) throw new InvalidArgumentException('Conversa não encontrada.');
    $texto = atd_texto_limpo($texto, INBOX_MAX_TEXTO);
    if ($texto === '') throw new InvalidArgumentException('Cole o que a pessoa escreveu.');
    $id = wa_msg_registrar($pdo, ['idcontato' => $idcontato, 'direcao' => 'E', 'origem' => 'lead', 'tipo' => 'text', 'texto' => $texto, 'status' => 'recebida', 'processada' => 1, 'criado_em' => wa_fmt($agora)], $agora);
    $upd = ['ultima_entrada_em' => wa_fmt($agora)];
    $saiu = wa_eh_pedido_de_saida($texto);
    if ($saiu) {
        $upd += ['saiu_em' => wa_fmt($agora), 'etapa' => 'saiu', 'modo' => 'pausado'];
        wa_agenda_cancelar_pendentes($pdo, $idcontato, 'saiu');
    }
    wa_contato_atualizar($pdo, $idcontato, $upd, $agora);
    $e = inbox_estado($pdo, $idcontato);
    unset($e['rascunho'], $e['sugestao']);   // o rascunho era para a mensagem anterior
    inbox_gravar_estado($pdo, $idcontato, $e);
    return ['idmensagem' => $id, 'saiu' => $saiu];
}

/** Mensagens do lead desde a última resposta da equipe (as que ainda esperam resposta). */
function inbox_pendentes(PDO $pdo, int $idcontato): array {
    $st = $pdo->prepare("SELECT idmensagem, tipo, texto, midia_id, criado_em FROM intus_wa_mensagem WHERE idcontato = ? AND direcao = 'E'
                         AND idmensagem > COALESCE((SELECT MAX(s.idmensagem) FROM intus_wa_mensagem s WHERE s.idcontato = ? AND s.direcao = 'S' AND s.status <> 'falhou'), 0) ORDER BY idmensagem");
    $st->execute([$idcontato, $idcontato]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * A Cora sugere a resposta para o que está pendente. Não envia nada. Devolve
 * ['ok' => true, 'acao' => responder|passar_para_humano, 'motivo', 'texto', 'resumo', 'avisos' => [...], 'custo_usd', 'modelo'] ou ['ok' => false, 'erro'].
 * Foto, vídeo e áudio, e os casos de regra fixa (saúde, reclamação, preço, pedido de pessoa), voltam como "para você" sem gastar chamada.
 */
function inbox_sugerir(PDO $pdo, int $idcontato, ?callable $http = null, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $c = wa_contato_por_id($pdo, $idcontato);
    if (!$c) throw new InvalidArgumentException('Conversa não encontrada.');
    if (!empty($c['saiu_em'])) return ['ok' => false, 'erro' => 'Esta pessoa pediu para não receber mais mensagens.'];
    $pend = inbox_pendentes($pdo, $idcontato);
    if (!$pend) return ['ok' => false, 'erro' => 'Não há mensagem da pessoa esperando resposta. Cole o que ela escreveu primeiro.'];
    $e = inbox_estado($pdo, $idcontato);
    $junto = trim(implode("\n", array_map(fn($m) => (string)$m['texto'], $pend)));
    $temMidia = (bool)array_filter($pend, fn($m) => !empty($m['midia_id']) || !in_array($m['tipo'], ['text', 'button', 'interactive'], true));
    $motivo = $temMidia ? 'midia' : robo_motivo_obvio($junto);
    if ($motivo !== null) {
        $e['sugestao'] = ['acao' => 'passar_para_humano', 'motivo' => $motivo, 'resumo' => '', 'em' => wa_fmt($agora)];
        unset($e['rascunho']);
        inbox_gravar_estado($pdo, $idcontato, $e);
        return ['ok' => true, 'acao' => 'passar_para_humano', 'motivo' => $motivo, 'texto' => '', 'resumo' => '', 'avisos' => [], 'custo_usd' => 0.0, 'modelo' => null, 'regra_fixa' => true];
    }
    if ((int)(atd_uso_hoje($pdo)['chamadas'] ?? 0) >= INBOX_MAX_SUGESTOES_DIA + ATD_MAX_CHAMADAS_DIA) {
        return ['ok' => false, 'erro' => 'Limite diário de sugestões da Cora atingido. Responda à mão ou volte amanhã.'];
    }
    $d = robo_decidir($pdo, $c, robo_historico($pdo, $idcontato), $http);
    if (!empty($d['tin']) || !empty($d['tout'])) atd_uso_somar($pdo, (int)$d['tin'], (int)$d['tout']);
    if (!$d['ok']) return ['ok' => false, 'erro' => $d['erro']];
    $avisos = [];
    if ($d['acao'] === 'responder') {
        foreach (atd_analisar_copy($d['texto'])['itens'] as $i) { if ($i['nivel'] === 'erro' && in_array($i['regra'], ['nao_e_x_e_y', 'promessa'], true)) $avisos[] = $i['msg']; }
        if (robo_promete_pessoa($d['texto'])) $avisos[] = 'A Cora prometeu chamar ou pedir confirmação a uma pessoa da equipe. Isso é com você: responda por conta própria ou ajuste o texto.';
    }
    $custo = ensaio_custo_usd((string)$d['modelo'], (int)$d['tin'], (int)$d['tout']);
    if ($d['resumo'] !== '') wa_contato_atualizar($pdo, $idcontato, ['resumo' => $d['resumo']], $agora);
    if ($d['acao'] === 'passar_para_humano') {
        $e['sugestao'] = ['acao' => 'passar_para_humano', 'motivo' => $d['motivo'], 'resumo' => $d['resumo'], 'em' => wa_fmt($agora)];
        unset($e['rascunho']);
    } else {
        $e['rascunho'] = ['texto' => $d['texto'], 'original' => $d['texto'], 'resumo' => $d['resumo'], 'avisos' => $avisos, 'em' => wa_fmt($agora)];
        unset($e['sugestao']);
    }
    inbox_gravar_estado($pdo, $idcontato, $e);
    return ['ok' => true, 'acao' => $d['acao'], 'motivo' => $d['motivo'], 'texto' => $d['acao'] === 'responder' ? $d['texto'] : '', 'resumo' => $d['resumo'], 'avisos' => $avisos, 'custo_usd' => $custo, 'modelo' => $d['modelo'], 'regra_fixa' => false];
}

/**
 * A equipe enviou (pelo WhatsApp da Intus): grava a mensagem como enviada. origem 'robo' se o texto é o que a Cora sugeriu, sem mudar nada;
 * 'pessoa' em qualquer outro caso. Limpa o rascunho e a marca de "resolvida".
 */
function inbox_enviei(PDO $pdo, int $idcontato, string $texto, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $c = wa_contato_por_id($pdo, $idcontato);
    if (!$c) throw new InvalidArgumentException('Conversa não encontrada.');
    $texto = atd_texto_limpo($texto, INBOX_MAX_TEXTO);
    if ($texto === '') throw new InvalidArgumentException('Escreva a resposta que você enviou.');
    $e = inbox_estado($pdo, $idcontato);
    $origem = (isset($e['rascunho']['original']) && trim((string)$e['rascunho']['original']) === $texto) ? 'robo' : 'pessoa';
    $id = wa_msg_registrar($pdo, ['idcontato' => $idcontato, 'direcao' => 'S', 'origem' => $origem, 'tipo' => 'text', 'texto' => $texto, 'status' => 'enviada', 'processada' => 1, 'criado_em' => wa_fmt($agora)], $agora);
    wa_contato_atualizar($pdo, $idcontato, [], $agora);
    unset($e['rascunho'], $e['sugestao'], $e['resolvido_em']);
    inbox_gravar_estado($pdo, $idcontato, $e);
    return ['idmensagem' => $id, 'origem' => $origem];
}

/** A conversa não precisa de resposta (agradecimento, assunto resolvido): sai de "esperando você" até a pessoa escrever de novo. */
function inbox_resolver(PDO $pdo, int $idcontato, ?DateTimeImmutable $agora = null): void {
    $agora = $agora ?: wa_agora();
    if (!wa_contato_por_id($pdo, $idcontato)) throw new InvalidArgumentException('Conversa não encontrada.');
    $e = inbox_estado($pdo, $idcontato);
    unset($e['rascunho'], $e['sugestao']);
    $e['resolvido_em'] = wa_fmt($agora);
    inbox_gravar_estado($pdo, $idcontato, $e);
}

/** Lista da caixa: contatos com mensagem, quem espera resposta primeiro (o mais antigo no topo), depois os demais pela atividade mais recente. */
function inbox_lista(PDO $pdo, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $ult = $pdo->query('SELECT idcontato, MAX(idmensagem) AS ult FROM intus_wa_mensagem GROUP BY idcontato ORDER BY ult DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    if (!$ult) return ['conversas' => [], 'aguardando' => 0];
    $ids = array_map(fn($r) => (int)$r['idcontato'], $ult);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM intus_wa_contato WHERE idcontato IN ($ph)");
    $st->execute($ids);
    $contatos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $contatos[(int)$c['idcontato']] = $c;
    $mids = array_map(fn($r) => (int)$r['ult'], $ult);
    $ph2 = implode(',', array_fill(0, count($mids), '?'));
    $st = $pdo->prepare("SELECT idmensagem, idcontato, direcao, origem, tipo, texto, criado_em FROM intus_wa_mensagem WHERE idmensagem IN ($ph2)");
    $st->execute($mids);
    $ultimas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $ultimas[(int)$m['idcontato']] = $m;
    $st = $pdo->prepare("SELECT m.idcontato, COUNT(*) AS n, MIN(m.criado_em) AS desde FROM intus_wa_mensagem m WHERE m.direcao = 'E' AND m.idcontato IN ($ph)
                         AND m.idmensagem > COALESCE((SELECT MAX(s.idmensagem) FROM intus_wa_mensagem s WHERE s.idcontato = m.idcontato AND s.direcao = 'S' AND s.status <> 'falhou'), 0) GROUP BY m.idcontato");
    $st->execute($ids);
    $espera = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $espera[(int)$r['idcontato']] = $r;
    $conversas = []; $aguardando = 0;
    foreach ($ids as $id) {
        $c = $contatos[$id] ?? null; $m = $ultimas[$id] ?? null;
        if (!$c || !$m) continue;
        $e = inbox_estado($pdo, $id);
        $n = (int)($espera[$id]['n'] ?? 0);
        $resolvida = !empty($e['resolvido_em']) && (string)$e['resolvido_em'] >= (string)$m['criado_em'];
        $espera_voce = $n > 0 && empty($c['saiu_em']) && !$resolvida;
        if ($espera_voce) $aguardando++;
        $txt = trim((string)$m['texto']);
        $conversas[] = [
            'id' => $id, 'nome' => $c['nome'] !== null && $c['nome'] !== '' ? $c['nome'] : $c['telefone'], 'telefone' => $c['telefone'],
            'ultima_em' => $m['criado_em'], 'ultima_direcao' => $m['direcao'], 'trecho' => mb_substr($txt !== '' ? $txt : '[' . $m['tipo'] . ']', 0, 90),
            'espera_voce' => $espera_voce, 'sem_resposta' => $n, 'desde' => $espera[$id]['desde'] ?? null,
            'para_voce' => $espera_voce && ((($e['sugestao']['acao'] ?? '') === 'passar_para_humano') || !empty($c['precisa_humano'])),
            'motivo' => $e['sugestao']['motivo'] ?? ($c['motivo_humano'] ?? null), 'tem_rascunho' => !empty($e['rascunho']['texto']),
            'saiu' => !empty($c['saiu_em']), 'resolvida' => $resolvida,
        ];
    }
    usort($conversas, function ($a, $b) {
        if ($a['espera_voce'] !== $b['espera_voce']) return $a['espera_voce'] ? -1 : 1;
        if ($a['espera_voce']) return strcmp((string)$a['desde'], (string)$b['desde']);
        return strcmp((string)$b['ultima_em'], (string)$a['ultima_em']);
    });
    return ['conversas' => $conversas, 'aguardando' => $aguardando];
}

/** Uma conversa inteira: contato, mensagens (as últimas INBOX_MENSAGENS), rascunho e situação. */
function inbox_conversa(PDO $pdo, int $idcontato, ?DateTimeImmutable $agora = null): ?array {
    $agora = $agora ?: wa_agora();
    $c = wa_contato_por_id($pdo, $idcontato);
    if (!$c) return null;
    $st = $pdo->prepare('SELECT idmensagem, direcao, origem, tipo, texto, template, status, criado_em FROM intus_wa_mensagem WHERE idcontato = ? ORDER BY idmensagem DESC LIMIT ' . (int)INBOX_MENSAGENS);
    $st->execute([$idcontato]);
    $msgs = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    $e = inbox_estado($pdo, $idcontato);
    $pend = inbox_pendentes($pdo, $idcontato);
    return ['ok' => true,
        'contato' => ['id' => (int)$c['idcontato'], 'nome' => $c['nome'] ?? '', 'telefone' => $c['telefone'], 'whatsapp' => $c['telefone'], 'idatleta' => $c['idatleta'] ? (int)$c['idatleta'] : null,
            'idlead' => $c['idlead'] ? (int)$c['idlead'] : null, 'objetivo' => $c['objetivo'] ?? '', 'resumo' => $c['resumo'] ?? '', 'saiu' => !empty($c['saiu_em'])],
        'mensagens' => $msgs, 'rascunho' => $e['rascunho'] ?? null, 'sugestao' => $e['sugestao'] ?? null, 'resolvido_em' => $e['resolvido_em'] ?? null,
        'esperando_resposta' => count($pend), 'agora' => wa_fmt($agora)];
}
