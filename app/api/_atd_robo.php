<?php
/**
 * Robô de conversa do WhatsApp (a "Cora"): responde sozinho ao que o lead escreve e passa para uma pessoa nos casos combinados.
 *
 * Decisões do Luiz (08/10/2026): autonomia híbrida. O robô responde dúvidas e qualifica; passa para uma pessoa quando falam de preço da
 * Premium, lesão/dor/doença, reclamação, pedem uma pessoa ou mandam vídeo/foto de exercício. Modelo e nome vêm dos ajustes
 * (admin/whatsapp-keys.php), nada fixo aqui.
 *
 * Como funciona (uma "rodada" por contato, ver robo_processar_contato):
 *  1. O webhook grava a mensagem recebida (processada = 0) e responde à Meta. Depois, com a resposta já entregue, chama o robô.
 *  2. O robô reivindica as mensagens pendentes do contato com UPDATE atômico (processada 0 -> 1): a reentrega da Meta e duas
 *     requisições ao mesmo tempo nunca respondem a mesma mensagem duas vezes.
 *  3. Antes de gastar com o Claude, regras fixas passam para uma pessoa o que é óbvio (mídia, saúde, reclamação, preço, "quero falar
 *     com uma pessoa"). O resto vai ao Claude com o histórico da conversa e devolve JSON: responder ou passar para uma pessoa.
 *  4. O texto que o Claude devolve é conferido (sem travessão, sem "não é X, é Y", sem promessa de resultado). Se não passar, o robô
 *     não manda: passa para uma pessoa.
 *  5. Passar para uma pessoa = mensagem fixa e curta ao lead (texto neste arquivo, não do Claude), contato marcado precisa_humano,
 *     modo 'humano' (pausa a automação) e aviso por e-mail com o resumo e as últimas mensagens.
 *
 * Travas: ajuste robo = desligado (nasce assim) | teste (só o contato de teste, origem de consentimento "teste-admin") | liberadas (só as conversas
 * liberadas, ver robo_liberacao: o que chega de anúncio e spam fica guardado e não gasta nenhum token) | todos;
 * mensagem com mais de ROBO_VELHA_MIN minutos não é respondida (sobrou de quando o robô estava desligado); limite de respostas por
 * contato e por dia; contato que saiu (SAIR), em modo humano ou já marcado precisa_humano nunca recebe resposta do robô.
 * Falha do Claude (sem saldo, chave recusada, fora do ar) nunca vira mensagem ao lead: o contato vai para uma pessoa e o aviso diz o motivo.
 *
 * SQL portável (roda em SQLite nos testes e no MySQL 5.6 de produção). Nenhuma tabela ou coluna nova.
 */

require_once __DIR__ . '/_wa.php';
require_once __DIR__ . '/_atendimento.php';

const ROBO_MODOS = ['desligado', 'teste', 'liberadas', 'todos'];

/** O robô está ligado em algum modo? (desligado é o único que nunca responde nem consulta contatos.) */
function robo_ligado($modo): bool { return in_array((string)$modo, ['teste', 'liberadas', 'todos'], true); }

/** Chave em intus_config do estado de uma conversa (rascunho, resolvida, liberação da Cora): compartilhada com a caixa de entrada. */
function robo_chave_estado(int $idcontato): string { return 'atd_inbox_' . $idcontato; }

/**
 * Esta conversa está liberada para o robô? Devolve o motivo ou null. No modo "liberadas" o robô só responde conversa liberada; o resto
 * (anúncio, spam, número desconhecido) é guardado e lido por uma pessoa, sem gastar token. Liberam: o contato de teste ('teste'), a decisão da
 * equipe pelo botão da caixa de entrada ('manual', e a decisão explícita de TIRAR vale mais que as automáticas), quem se cadastrou pelo nosso
 * site e aceitou receber mensagens ('cadastro') e quem já está ligado a um lead ou cliente ('lead').
 */
function robo_liberacao(PDO $pdo, array $c): ?string {
    if (($c['optin_origem'] ?? '') === ROBO_ORIGEM_TESTE) return 'teste';
    $e = atd_cfg_ler($pdo, robo_chave_estado((int)$c['idcontato']), []);
    if (is_array($e) && array_key_exists('cora_liberada', $e)) return $e['cora_liberada'] ? 'manual' : null;
    if (!empty($c['optin_em'])) return 'cadastro';
    if (!empty($c['idlead']) || !empty($c['idatleta'])) return 'lead';
    return null;
}
const ROBO_ORIGEM_TESTE = 'teste-admin';
const ROBO_VELHA_MIN = 30;        // minutos: mensagem mais antiga que isto não é respondida
const ROBO_HISTORICO = 24;        // mensagens anteriores enviadas ao Claude
const ROBO_MAX_TEXTO = 900;       // caracteres da resposta ao lead
const ROBO_MOTIVOS = ['pediu_pessoa', 'saude', 'reclamacao', 'preco', 'midia', 'duvida', 'copy', 'limite', 'limite_dia', 'falha', 'envio', 'outro'];

/** Texto fixo ao lead quando o robô passa a conversa. Fica aqui (aprovável e testável), nunca vem do Claude. */
function robo_texto_passagem(string $motivo, string $nomeAtendente = 'Cora'): string {
    switch ($motivo) {
        case 'saude':
            return 'Sobre isso, o melhor é um professor da Intus olhar com atenção. Já avisei a equipe, e ele fala com você por aqui.';
        case 'preco':
            return 'Os valores e as condições eu passo com uma pessoa da equipe, para te explicar direito. Já avisei, e ela te responde por aqui.';
        case 'reclamacao':
            return 'Sinto muito por isso. Já chamei uma pessoa da equipe para cuidar disso com você por aqui.';
        case 'midia':
            return 'Recebi, obrigada! Um professor da Intus vai olhar e te responde por aqui.';
        default:
            return 'Vou chamar uma pessoa da equipe da Intus para continuar essa conversa com você. Ela responde por aqui assim que puder.';
    }
}

/**
 * Casos em que a regra fixa já decide, sem gastar chamada: devolve o motivo ou null. Erra para o lado de passar para uma pessoa
 * (uma pessoa a mais atendendo custa pouco; o robô opinando sobre dor ou dizendo preço errado custa caro).
 * $texto é o que o lead escreveu (várias mensagens seguidas podem vir juntas).
 */
function robo_motivo_obvio(string $texto): ?string {
    $t = wa_normalizar_texto($texto);
    if ($t === '') return null;
    if (preg_match('/\b(dor|dores|doi|doendo|dolorido|dolorida|lesao|lesionado|lesionada|machuquei|machucado|machucada|hernia|cirurgia|operei|operado|operada|tendinite|artrose|bursite|fratura|fraturei|diabetes|diabetica|diabetico|hipertensao|cardiaco|cardiaca|gestante|gravida|gravidez|medico|medica|medicamento|medicamentos|remedio|remedios|laudo|ansiedade|depressao|anorexia|bulimia)\b|pressao alta|problema (no|na|de) (joelho|coluna|ombro|quadril|coracao)/', $t)) return 'saude';
    if (preg_match('/\b(reclama\w*|pessimo|pessima|horrivel|absurdo|golpe|golpista|procon|engan\w+|reembolso|estorno|advogado|palhacada|vergonha|desrespeit\w+)\b|cobranca (indevida|errada)|nao (recebi|funciona|consigo entrar|consigo acessar)|processo contra/', $t)) return 'reclamacao';
    if (preg_match('/\b(atendente|humano|gerente|supervisor)\b|falar com (uma |um |a |o )?(pessoa|alguem|humano|professor|professora|consultor|consultora|responsavel|dono|dona)|(quero|preciso|posso|gostaria de) (falar|conversar) com (uma |um )?(pessoa|alguem|humano)|nao quero (falar com )?(robo|maquina|bot)\b/', $t)) return 'pediu_pessoa';
    if (preg_match('/\b(preco|precos|valor|valores|mensalidade|investimento|desconto|parcel\w*|pix|cartao|boleto|promocao)\b|quanto (custa|fica|sai|cobra|e)\b|qual (o )?(preco|valor)/', $t)) return 'preco';
    return null;
}

/**
 * A resposta promete chamar, avisar ou pedir confirmação a uma pessoa da equipe? Se veio como "responder", ninguém seria avisado e a pessoa
 * ficaria esperando. (Achado na comparação de modelos de 11/11/2026: o Sonnet respondeu "vou pedir para uma pessoa da equipe confirmar".)
 * Conferência de texto, independente do modelo.
 */
function robo_promete_pessoa(string $texto): bool {
    $t = wa_normalizar_texto($texto);
    return (bool)preg_match('/\b(vou|vamos|irei) (pedir|chamar|avisar|passar|encaminhar|confirmar|perguntar)\b[^.?!]{0,80}\b(equipe|pessoa|professor|professora|time|responsavel)\b|\b(equipe|professor|professora) (vai|ira|entra|entrara) (te |entrar |responder|falar|confirmar|retornar)/', $t);
}

/** Remove o que a regra de escrita do Luiz proíbe e que dá para consertar sozinho (travessão). */
function robo_limpar_texto(string $t): string {
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $t);
    $t = preg_replace('/[ \t]*[\x{2014}\x{2013}][ \t]*/u', ', ', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    return mb_substr(trim($t), 0, ROBO_MAX_TEXTO);
}

/**
 * Histórico no formato do Claude (papéis alternados, começando e terminando no lead). Quem escreveu primeiro foi a Intus (sequência):
 * entra uma abertura sintética de lead, porque a API exige que a primeira mensagem seja do usuário.
 */
function robo_historico(PDO $pdo, int $idcontato): array {
    $st = $pdo->prepare('SELECT direcao, tipo, texto, status FROM intus_wa_mensagem WHERE idcontato = ? ORDER BY idmensagem DESC LIMIT ' . (int)ROBO_HISTORICO);
    $st->execute([$idcontato]);
    $linhas = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    $out = [];
    foreach ($linhas as $l) {
        $texto = trim((string)($l['texto'] ?? ''));
        if ($l['direcao'] === 'S') {
            if ($texto === '' || ($l['status'] ?? '') === 'falhou') continue;
            $papel = 'assistant';
        } else {
            if ($texto === '') $texto = '[o lead enviou ' . ($l['tipo'] === 'image' ? 'uma imagem' : ($l['tipo'] === 'video' ? 'um vídeo' : ($l['tipo'] === 'audio' ? 'um áudio' : 'um arquivo'))) . ']';
            $papel = 'user';
        }
        if ($out && $out[count($out) - 1]['role'] === $papel) $out[count($out) - 1]['content'] .= "\n" . $texto;
        else $out[] = ['role' => $papel, 'content' => $texto];
    }
    if ($out && $out[0]['role'] === 'assistant') array_unshift($out, ['role' => 'user', 'content' => '[a conversa começou com uma mensagem da Intus]']);
    while ($out && $out[count($out) - 1]['role'] === 'assistant') array_pop($out);
    return $out;
}

function robo_prompt_sistema(PDO $pdo, array $c): string {
    $aj = wa_ajustes($pdo);
    $nome = $aj['nome_atendente'] ?? 'Cora';
    $s = "Você é $nome, assistente virtual da Intus Fit, e conversa por WhatsApp com pessoas que se interessaram pela Intus. A Intus é uma consultoria online de treino e nutrição (não é academia, não tem unidade física). Escreva em português do Brasil.\n"
       . "Seu papel: ser simpática e útil, responder dúvidas sobre como a Intus funciona, entender o objetivo da pessoa e levar a conversa ao próximo passo. Hoje todo lead segue para a Consultoria Premium, e quem conversa sobre valores e fecha é uma pessoa da equipe.\n\n"
       . "COMO ESCREVER:\n"
       . "- Mensagens curtas de WhatsApp: de 1 a 3 frases, uma ideia e no máximo uma pergunta por vez. No máximo um emoji. Sem caixa alta.\n"
       . "- Nunca use travessão (— ou –). Nunca use o padrão \"não é X, é Y\" nem \"não é sobre X, é sobre Y\".\n"
       . "- Não prometa resultado, prazo ou número (nada de quilos, dias para ver mudança, \"garantido\"). Não crie urgência falsa. Não invente depoimento, número de alunos, desconto, vaga limitada ou benefício.\n"
       . "- O teste grátis é só de treino. Não inclui dieta.\n"
       . "- Se perguntarem, diga a verdade: você é a assistente virtual da Intus, não uma pessoa. Nunca diga que é humana.\n"
       . "- Se a pessoa pedir para parar de receber mensagens, não argumente: responda que respeita e que ela pode voltar quando quiser.\n"
       . "- Só afirme o que está nos FATOS abaixo. Se não souber, diga que vai confirmar com a equipe e passe para uma pessoa.\n\n"
       . "QUANDO PASSAR PARA UMA PESSOA (acao = passar_para_humano): preço, valor, desconto, forma de pagamento ou parcelamento; dor, lesão, doença, remédio, suplemento ou gravidez; reclamação ou insatisfação; pedido para falar com uma pessoa; vídeo ou foto de exercício; qualquer dúvida em que você não tenha certeza do fato. Se você for dizer que vai confirmar algo com a equipe ou chamar uma pessoa, use acao = passar_para_humano: numa resposta normal ninguém é avisado e a pessoa ficaria esperando.\n\n";
    try {
        $ps = function_exists('provas_para_ia') ? array_values(array_filter(provas_para_ia($pdo, 12), fn($x) => !empty($x['pode_divulgar']))) : [];
        if ($ps) {
            $s .= "PROVAS SOCIAIS REAIS E AUTORIZADAS (só estas podem ser citadas; nunca invente outra e nunca cite sobrenome):\n";
            foreach (array_slice($ps, 0, 6) as $x) $s .= "- " . $x['texto_pronto'] . "\n";
            $s .= "\n";
        }
    } catch (Throwable $e) {}
    $s .= "FATOS DOS PRODUTOS (não invente nada além disto):\n";
    try {
        foreach (atd_conceitos($pdo) as $k) { if (($k['id'] ?? '') === 'produtos') $s .= $k['resumo'] . "\n"; }
    } catch (Throwable $e) {}
    $conhece = [];
    if (!empty($c['nome'])) $conhece[] = 'nome: ' . atd_texto_limpo($c['nome'], 80);
    if (!empty($c['genero'])) $conhece[] = 'gênero: ' . ($c['genero'] === 'F' ? 'feminino' : ($c['genero'] === 'M' ? 'masculino' : atd_texto_limpo($c['genero'], 10)));
    if (!empty($c['objetivo'])) $conhece[] = 'objetivo: ' . atd_texto_limpo($c['objetivo'], 100);
    if (!empty($c['resumo'])) $conhece[] = 'o que já se sabe: ' . atd_texto_limpo($c['resumo'], 500);
    if ($conhece) $s .= "\nDESTA PESSOA: " . implode('; ', $conhece) . ".\n";
    $s .= "\nResponda SOMENTE com um objeto JSON válido, sem texto antes nem depois, neste formato:\n"
        . "{\"acao\":\"responder\" ou \"passar_para_humano\",\"motivo\":\"pediu_pessoa|saude|reclamacao|preco|duvida|outro (só quando passar)\",\"texto\":\"a mensagem para a pessoa (vazio se passar)\",\"resumo\":\"duas frases: quem é, o que quer e em que ponto da conversa está\"}\n";
    return $s;
}

/** Uma chamada ao Claude com o mesmo cuidado das outras telas: tenta de novo sem o parâmetro beta se a API recusar por causa dele. */
function robo_chamar_claude(string $corpo, string $chave, ?callable $http): array {
    $chamar = $http ?: 'atd_http_claude';
    $r = $chamar($corpo, $chave, true);
    if (($r['status'] ?? 0) === 400 && preg_match('/fallback|beta/i', json_encode($r['json'] ?? '') ?: '')) {
        $sem = json_decode($corpo, true); unset($sem['fallbacks']);
        $r = $chamar(json_encode($sem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $chave, false);
    }
    return $r;
}

/**
 * Pergunta ao Claude o que fazer com a conversa. Não grava nada e nunca lança.
 * $modelo: só para ensaio (comparar modelos); sem ele vale o dos ajustes.
 * Devolve ['ok' => true, 'acao', 'motivo', 'texto', 'resumo', 'tin', 'tout', 'modelo', 'ms'] ou ['ok' => false, 'erro' => frase para o Luiz].
 */
function robo_decidir(PDO $pdo, array $contato, array $mensagens, ?callable $http = null, ?string $modelo = null): array {
    $cfg = wa_segredo($pdo, 'anthropic_config');
    $chave = is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
    if ($chave === '') return ['ok' => false, 'erro' => 'A chave do Claude ainda não foi cadastrada em admin/whatsapp-keys.php.'];
    if (!$mensagens || $mensagens[count($mensagens) - 1]['role'] !== 'user') return ['ok' => false, 'erro' => 'Não há mensagem do lead para responder.'];
    if ($modelo === null || !preg_match('/^claude-[a-z0-9.\-]{2,60}$/', $modelo)) $modelo = wa_ajustes($pdo)['modelo'] ?? 'claude-opus-5-5';
    $corpo = json_encode([
        'model' => $modelo,
        'max_tokens' => 900,
        // O prompt é igual a cada resposta: o cache de prompt da API cobra uma fração para repetir esse trecho.
        'system' => [['type' => 'text', 'text' => robo_prompt_sistema($pdo, $contato), 'cache_control' => ['type' => 'ephemeral']]],
        'output_config' => ['effort' => 'low'],
        'fallbacks' => 'default',
        'messages' => $mensagens,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $t0 = microtime(true);
    try {
        $r = robo_chamar_claude($corpo, $chave, $http);
    } catch (Throwable $e) {
        return ['ok' => false, 'erro' => 'Falha ao falar com o Claude (' . wa_log_erro($e) . ').'];
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $st = (int)($r['status'] ?? 0);
    $j = $r['json'] ?? null;
    if ($st !== 200 || !is_array($j)) {
        $det = is_array($j) ? (string)($j['error']['message'] ?? '') : (string)($r['erro'] ?? '');
        return ['ok' => false, 'erro' => atd_erro_claude_especial($st, $j, $modelo)
            ?? ($st === 401 ? 'A chave do Claude foi recusada.' : ($st === 429 ? 'Limite de uso do Claude atingido agora.'
            : 'O Claude não respondeu como esperado' . ($st ? " (HTTP $st)" : '') . ($det ? ': ' . mb_substr($det, 0, 120) : '.')))];
    }
    if (($j['stop_reason'] ?? '') === 'refusal') return ['ok' => false, 'erro' => 'O Claude recusou responder a esta conversa.'];
    $texto = '';
    foreach (($j['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $texto .= $b['text']; }
    $tin = (int)($j['usage']['input_tokens'] ?? 0) + (int)($j['usage']['cache_read_input_tokens'] ?? 0) + (int)($j['usage']['cache_creation_input_tokens'] ?? 0);
    $tout = (int)($j['usage']['output_tokens'] ?? 0);
    $d = atd_extrair_json($texto);
    if (!is_array($d) || !in_array($d['acao'] ?? '', ['responder', 'passar_para_humano'], true)) {
        return ['ok' => false, 'erro' => 'O Claude respondeu num formato que não deu para ler.', 'tin' => $tin, 'tout' => $tout];
    }
    $motivo = (string)($d['motivo'] ?? '');
    return ['ok' => true, 'acao' => $d['acao'], 'motivo' => in_array($motivo, ROBO_MOTIVOS, true) ? $motivo : 'duvida',
        'texto' => robo_limpar_texto((string)($d['texto'] ?? '')), 'resumo' => atd_texto_limpo($d['resumo'] ?? '', 500),
        'tin' => $tin, 'tout' => $tout, 'modelo' => $modelo, 'ms' => $ms];
}

/** O robô pode responder a este contato agora? Devolve [bool, motivo]. */
function robo_pode_responder(array $c, array $aj, DateTimeImmutable $agora, ?string $liberacao = null): array {
    $modo = (string)($aj['robo'] ?? 'desligado');
    if (!robo_ligado($modo)) return [false, 'robo_desligado'];
    if ($modo === 'teste' && ($c['optin_origem'] ?? '') !== ROBO_ORIGEM_TESTE) return [false, 'fora_do_teste'];
    if ($modo === 'liberadas' && $liberacao === null) return [false, 'nao_liberada'];
    // precisa_humano com motivo 'midia_recebida' é só a marca que o webhook põe ao receber foto/vídeo: o robô ainda deve avisar a equipe.
    if (!empty($c['precisa_humano']) && ($c['motivo_humano'] ?? '') !== 'midia_recebida') return [false, 'com_pessoa'];
    // saiu, modo diferente de robô e janela de 24h fechada: a mesma regra de todo envio automático
    [$ok, $motivo] = wa_decidir_envio($c, $agora, ['tipo' => 'texto', 'proativa' => false, 'automatica' => true]);
    return $ok ? [true, 'ok'] : [false, $motivo];
}

function robo_respostas_hoje(PDO $pdo, ?int $idcontato, DateTimeImmutable $agora): int {
    $ini = wa_fmt($agora->setTimezone(wa_tz())->setTime(0, 0, 0));
    if ($idcontato === null) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_wa_mensagem WHERE direcao = 'S' AND origem = 'robo' AND criado_em >= ?");
        $st->execute([$ini]);
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_wa_mensagem WHERE idcontato = ? AND direcao = 'S' AND origem = 'robo' AND criado_em >= ?");
        $st->execute([$idcontato, $ini]);
    }
    return (int)$st->fetchColumn();
}

/** Trava por contato (arquivo temporário): duas requisições ao mesmo tempo não conversam com a mesma pessoa. Devolve o recurso, ou null se ocupado. */
function robo_trava(int $idcontato) {
    $f = @fopen(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'intus_robo_' . $idcontato . '.lock', 'c');
    if (!$f) return true;   // sem como travar: segue (a reivindicação atômica das mensagens continua valendo)
    if (!flock($f, LOCK_EX | LOCK_NB)) { fclose($f); return null; }
    return $f;
}
function robo_destrava($t): void { if (is_resource($t)) { flock($t, LOCK_UN); fclose($t); } }

/** Avisa a equipe (e-mail). $ctx['avisar'](assunto, texto). Nunca lança. */
function robo_avisar(array $ctx, string $assunto, string $texto): void {
    if (empty($ctx['avisar']) || !is_callable($ctx['avisar'])) return;
    try { $ctx['avisar']($assunto, $texto); } catch (Throwable $e) { wa_log_erro($e); }
}

function robo_ultimas_para_aviso(PDO $pdo, int $idcontato, int $n = 6): string {
    $st = $pdo->prepare('SELECT direcao, texto, tipo FROM intus_wa_mensagem WHERE idcontato = ? ORDER BY idmensagem DESC LIMIT ' . (int)$n);
    $st->execute([$idcontato]);
    $l = [];
    foreach (array_reverse($st->fetchAll(PDO::FETCH_ASSOC)) as $m) {
        $t = trim((string)$m['texto']) !== '' ? trim((string)$m['texto']) : '[' . $m['tipo'] . ']';
        $l[] = ($m['direcao'] === 'E' ? 'Lead: ' : 'Intus: ') . mb_substr($t, 0, 300);
    }
    return implode("\n", $l);
}

/**
 * Passa o contato para uma pessoa: mensagem fixa ao lead (a menos que $silencioso), marca precisa_humano, pausa a automação (modo humano) e avisa por e-mail.
 * Só o motivo 'falha' (Claude fora) e 'envio' (Meta recusou) não mandam texto ao lead.
 */
function robo_passar_para_humano(PDO $pdo, array $cfg, array $c, string $motivo, string $resumo, array $ctx, DateTimeImmutable $agora, string $detalhe = ''): array {
    $aj = wa_ajustes($pdo);
    $silencioso = in_array($motivo, ['falha', 'envio', 'limite_dia'], true);
    $enviado = null;
    if (!$silencioso) {
        $texto = robo_texto_passagem($motivo, (string)($aj['nome_atendente'] ?? 'Cora'));
        $enviado = wa_enviar_texto($pdo, $cfg, $c, $texto, 'robo', $ctx['http_meta'] ?? null, $agora);
    }
    $upd = ['precisa_humano' => 1, 'motivo_humano' => mb_substr($motivo, 0, 100), 'modo' => 'humano'];
    if ($resumo !== '') $upd['resumo'] = $resumo;
    wa_contato_atualizar($pdo, (int)$c['idcontato'], $upd, $agora);
    $rot = ['pediu_pessoa' => 'pediu uma pessoa', 'saude' => 'falou de saúde, dor ou lesão', 'reclamacao' => 'reclamação ou problema', 'preco' => 'quer falar de valores',
            'midia' => 'mandou foto, vídeo ou áudio', 'duvida' => 'dúvida que o robô não soube responder', 'copy' => 'a resposta do robô não passou na conferência de escrita',
            'limite' => 'conversa longa (limite de respostas do dia)', 'limite_dia' => 'limite diário do robô atingido', 'falha' => 'o Claude falhou', 'envio' => 'a Meta recusou o envio', 'outro' => 'motivo não classificado'][$motivo] ?? $motivo;
    $nome = trim((string)($c['nome'] ?? '')) !== '' ? $c['nome'] : 'sem nome';
    robo_avisar($ctx, 'WhatsApp: ' . $nome . ' precisa de uma pessoa', "Contato: $nome (" . $c['telefone'] . ")\nMotivo: $rot" . ($detalhe !== '' ? "\nDetalhe: $detalhe" : '')
        . ($resumo !== '' ? "\nResumo do robô: $resumo" : '') . "\n\nÚltimas mensagens:\n" . robo_ultimas_para_aviso($pdo, (int)$c['idcontato'])
        . "\n\nResponda pela Central de atendimento (intusfit.com.br/atendimento). O robô fica parado nesta conversa até você devolvê-la.");
    return ['acao' => 'passou', 'motivo' => $motivo, 'enviada' => $enviado ? $enviado['ok'] : null];
}

/** Uma rodada: reivindica as mensagens pendentes do contato e responde (ou passa). */
function robo_uma_rodada(PDO $pdo, array $cfg, int $idcontato, array $aj, array $ctx, DateTimeImmutable $agora): array {
    $c = wa_contato_por_id($pdo, $idcontato);
    if (!$c) return ['acao' => 'nada', 'motivo' => 'contato_inexistente'];
    $st = $pdo->prepare("SELECT idmensagem, tipo, texto, midia_id, criado_em FROM intus_wa_mensagem WHERE idcontato = ? AND direcao = 'E' AND processada = 0 ORDER BY idmensagem");
    $st->execute([$idcontato]);
    $pend = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pend) return ['acao' => 'nada', 'motivo' => 'sem_pendentes'];

    [$pode, $motivoNao] = robo_pode_responder($c, $aj, $agora, ($aj['robo'] ?? '') === 'liberadas' ? robo_liberacao($pdo, $c) : null);
    if (!$pode) return ['acao' => 'nada', 'motivo' => $motivoNao];

    // Mensagens velhas (ficaram de quando o robô estava desligado ou parado) são dadas como vistas e não respondidas.
    $limite = wa_fmt($agora->modify('-' . ROBO_VELHA_MIN . ' minutes'));
    $marca = $pdo->prepare('UPDATE intus_wa_mensagem SET processada = 1 WHERE idmensagem = ? AND processada = 0');
    $frescas = [];
    foreach ($pend as $m) {
        if ((string)$m['criado_em'] < $limite) $marca->execute([$m['idmensagem']]);
        else $frescas[] = $m;
    }
    if (!$frescas) return ['acao' => 'nada', 'motivo' => 'antigas'];

    // Reivindicação atômica: só responde às que conseguiu marcar.
    $minhas = [];
    foreach ($frescas as $m) { $marca->execute([$m['idmensagem']]); if ($marca->rowCount() === 1) $minhas[] = $m; }
    if (!$minhas) return ['acao' => 'nada', 'motivo' => 'ja_processada'];

    // Reação (emoji sobre uma mensagem) e tipos que a API não detalha não pedem resposta.
    $minhas = array_values(array_filter($minhas, fn($m) => !in_array($m['tipo'], ['reaction', 'unsupported', 'system', 'ephemeral'], true)));
    if (!$minhas) return ['acao' => 'nada', 'motivo' => 'sem_texto'];

    $textos = array_values(array_filter(array_map(fn($m) => trim((string)$m['texto']), $minhas), fn($t) => $t !== ''));
    $temMidia = (bool)array_filter($minhas, fn($m) => !empty($m['midia_id']) || !in_array($m['tipo'], ['text', 'button', 'interactive'], true));
    $junto = implode("\n", $textos);

    if ($temMidia) return robo_passar_para_humano($pdo, $cfg, $c, 'midia', '', $ctx, $agora);
    $obvio = robo_motivo_obvio($junto);
    if ($obvio !== null) return robo_passar_para_humano($pdo, $cfg, $c, $obvio, '', $ctx, $agora);

    $maxContato = max(1, (int)($aj['robo_max_respostas_dia'] ?? 15));
    $maxTotal = max(1, (int)($aj['robo_max_dia_total'] ?? 200));
    if (robo_respostas_hoje($pdo, null, $agora) >= $maxTotal) return robo_passar_para_humano($pdo, $cfg, $c, 'limite_dia', '', $ctx, $agora, "mais de $maxTotal respostas hoje");
    if (robo_respostas_hoje($pdo, $idcontato, $agora) >= $maxContato) return robo_passar_para_humano($pdo, $cfg, $c, 'limite', '', $ctx, $agora);

    $d = robo_decidir($pdo, $c, robo_historico($pdo, $idcontato), $ctx['http_claude'] ?? null);
    if (!$d['ok']) return robo_passar_para_humano($pdo, $cfg, $c, 'falha', '', $ctx, $agora, (string)$d['erro']) + ['erro' => $d['erro']];

    if ($d['acao'] === 'passar_para_humano') {
        $r = robo_passar_para_humano($pdo, $cfg, $c, $d['motivo'] === 'copy' ? 'outro' : $d['motivo'], $d['resumo'], $ctx, $agora);
        robo_gravar_tokens($pdo, $idcontato, $d);
        return $r;
    }
    $texto = $d['texto'];
    if ($texto === '') return robo_passar_para_humano($pdo, $cfg, $c, 'falha', $d['resumo'], $ctx, $agora, 'o Claude respondeu sem texto');
    foreach (atd_analisar_copy($texto)['itens'] as $i) {
        if ($i['nivel'] === 'erro' && in_array($i['regra'], ['nao_e_x_e_y', 'promessa'], true)) {
            return robo_passar_para_humano($pdo, $cfg, $c, 'copy', $d['resumo'], $ctx, $agora, 'regra: ' . $i['regra'] . ' · texto: ' . mb_substr($texto, 0, 200));
        }
    }
    if (robo_promete_pessoa($texto)) return robo_passar_para_humano($pdo, $cfg, $c, 'duvida', $d['resumo'], $ctx, $agora, 'a Cora prometeu chamar uma pessoa: ' . mb_substr($texto, 0, 200));
    $env = wa_enviar_texto($pdo, $cfg, $c, $texto, 'robo', $ctx['http_meta'] ?? null, $agora);
    robo_gravar_tokens($pdo, $idcontato, $d, $env['wa_id'] ?? null);
    if (!$env['ok']) return robo_passar_para_humano($pdo, $cfg, $c, 'envio', $d['resumo'], $ctx, $agora, (string)($env['erro'] ?? '')) + ['erro' => $env['erro'] ?? ''];
    if ($d['resumo'] !== '') wa_contato_atualizar($pdo, $idcontato, ['resumo' => $d['resumo']], $agora);
    return ['acao' => 'respondeu', 'motivo' => 'ok', 'texto' => $texto];
}

/** Guarda os tokens gastos na última mensagem que o robô mandou (o painel soma por aqui) ou, sem envio, na última do contato. */
function robo_gravar_tokens(PDO $pdo, int $idcontato, array $d, ?string $waId = null): void {
    if (empty($d['tin']) && empty($d['tout'])) return;
    try {
        if ($waId !== null && $waId !== '') {
            $pdo->prepare('UPDATE intus_wa_mensagem SET tokens_in = ?, tokens_out = ? WHERE wa_id = ?')->execute([(int)$d['tin'], (int)$d['tout'], $waId]);
        } else {
            $st = $pdo->prepare("SELECT MAX(idmensagem) FROM intus_wa_mensagem WHERE idcontato = ? AND direcao = 'S'");
            $st->execute([$idcontato]);
            $id = (int)$st->fetchColumn();
            if ($id) $pdo->prepare('UPDATE intus_wa_mensagem SET tokens_in = ?, tokens_out = ? WHERE idmensagem = ?')->execute([(int)$d['tin'], (int)$d['tout'], $id]);
        }
    } catch (Throwable $e) { wa_log_erro($e); }
}

/**
 * Responde ao que está pendente deste contato. Até 3 rodadas (chegou mensagem enquanto o Claude pensava).
 * $ctx: agora, http_claude, http_meta (para testes), avisar (callable(assunto, texto)).
 * Devolve o resultado da última rodada que fez alguma coisa: ['acao' => nada|respondeu|passou, 'motivo' => ...].
 */
function robo_processar_contato(PDO $pdo, array $cfg, int $idcontato, array $ctx = []): array {
    $agora = $ctx['agora'] ?? wa_agora();
    $aj = wa_ajustes($pdo);
    if (!robo_ligado($aj['robo'] ?? 'desligado')) return ['acao' => 'nada', 'motivo' => 'robo_desligado'];
    $trava = robo_trava($idcontato);
    if ($trava === null) return ['acao' => 'nada', 'motivo' => 'ocupado'];
    $ultimo = ['acao' => 'nada', 'motivo' => 'sem_pendentes'];
    try {
        for ($volta = 0; $volta < 3; $volta++) {
            $r = robo_uma_rodada($pdo, $cfg, $idcontato, $aj, $ctx, $agora);
            if ($r['acao'] === 'nada') { if ($volta === 0) $ultimo = $r; break; }
            $ultimo = $r;
            if ($r['acao'] === 'passou') break;
        }
    } catch (Throwable $e) {
        wa_log_erro($e);
        $ultimo = ['acao' => 'nada', 'motivo' => 'erro_interno'];
    } finally {
        robo_destrava($trava);
    }
    return $ultimo;
}

/** Contatos com mensagem recebida ainda não tratada, das últimas ROBO_VELHA_MIN minutos. */
function robo_contatos_pendentes(PDO $pdo, DateTimeImmutable $agora): array {
    $st = $pdo->prepare("SELECT DISTINCT idcontato FROM intus_wa_mensagem WHERE direcao = 'E' AND processada = 0 AND criado_em >= ?");
    $st->execute([wa_fmt($agora->modify('-' . ROBO_VELHA_MIN . ' minutes'))]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Chamado pelo webhook depois de gravar as mensagens: trata todos os contatos com algo pendente. */
function robo_processar_pendentes(PDO $pdo, array $cfg, array $ctx = []): array {
    $agora = $ctx['agora'] ?? wa_agora();
    if (!robo_ligado(wa_ajustes($pdo)['robo'] ?? 'desligado')) return [];
    $res = [];
    foreach (robo_contatos_pendentes($pdo, $agora) as $id) $res[$id] = robo_processar_contato($pdo, $cfg, $id, $ctx);
    return $res;
}
