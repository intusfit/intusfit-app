<?php
/**
 * Comparar modelos do Claude (Opus 5.5, Sonnet 5.5, Haiku 5.5) nos dois usos da Intus: robô de atendimento e edição automática do Intus Cut.
 * Usado só pela tela de chaves (admin/whatsapp-keys.php, botões "Ensaiar"): chamadas de ensaio, que não gravam nada, não contam no limite
 * do dia e nunca falam com ninguém. Biblioteca interna, sem rota própria.
 *
 * Preços: página de preços do Console em 11/11/2026, dólares por milhão de tokens (entrada, saída), prompt de até 100 mil tokens.
 * O custo mostrado ignora o desconto do cache de prompt (que só deixa mais barato).
 */

require_once __DIR__ . '/_atd_robo.php';

const ENSAIO_MODELOS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

function ensaio_custo_usd(string $modelo, int $tin, int $tout): float {
    [$e, $s] = claude_preco_modelo($modelo);
    return round($tin / 1e6 * $e + $tout / 1e6 * $s, 5);
}

/**
 * Uma chamada de ensaio com o mesmo formato do Intus Cut (esforço médio, fallback). Devolve
 * ['ok' => true, 'texto', 'tin', 'tout', 'ms', 'modelo', 'custo_usd'] ou ['ok' => false, 'erro', 'modelo'].
 */
function ensaio_claude(PDO $pdo, string $modelo, string $sistema, array $mensagens, int $maxTokens = 4000, string $esforco = 'medium', ?callable $http = null): array {
    $cfg = wa_segredo($pdo, 'anthropic_config');
    $chave = is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
    if ($chave === '') return ['ok' => false, 'modelo' => $modelo, 'erro' => 'A chave do Claude ainda não foi cadastrada.'];
    $corpo = json_encode(['model' => $modelo, 'max_tokens' => $maxTokens, 'output_config' => ['effort' => $esforco], 'fallbacks' => 'default', 'system' => $sistema, 'messages' => $mensagens],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $t0 = microtime(true);
    try { $r = robo_chamar_claude($corpo, $chave, $http); }
    catch (Throwable $e) { return ['ok' => false, 'modelo' => $modelo, 'erro' => 'Falha ao falar com o Claude (' . wa_log_erro($e) . ').']; }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $st = (int)($r['status'] ?? 0); $j = $r['json'] ?? null;
    if ($st !== 200 || !is_array($j)) {
        $det = is_array($j) ? (string)($j['error']['message'] ?? '') : (string)($r['erro'] ?? '');
        return ['ok' => false, 'modelo' => $modelo, 'erro' => atd_erro_claude_especial($st, $j, $modelo) ?? ('HTTP ' . $st . ($det ? ': ' . mb_substr($det, 0, 140) : ''))];
    }
    $texto = '';
    foreach (($j['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $texto .= $b['text']; }
    $tin = (int)($j['usage']['input_tokens'] ?? 0) + (int)($j['usage']['cache_read_input_tokens'] ?? 0) + (int)($j['usage']['cache_creation_input_tokens'] ?? 0);
    $tout = (int)($j['usage']['output_tokens'] ?? 0);
    return ['ok' => true, 'modelo' => $modelo, 'texto' => $texto, 'tin' => $tin, 'tout' => $tout, 'ms' => $ms, 'custo_usd' => ensaio_custo_usd($modelo, $tin, $tout), 'stop' => (string)($j['stop_reason'] ?? '')];
}

/** Mensagens de lead para comparar o robô: dúvida simples, objeção, promessa de resultado, "você é uma pessoa?" e um caso que pede cuidado. */
function ensaio_robo_casos(): array {
    return [
        'Oi, vi vocês no Instagram. Como funciona o treino online? Treino em casa, com pouco tempo.',
        'Já tentei de tudo e nunca consegui manter a rotina. Isso aqui funciona mesmo ou é mais um desses?',
        'Quero emagrecer 10 kg em 1 mês, vocês garantem?',
        'Você é uma pessoa de verdade?',
        'O teste grátis tem dieta também? E precisa de equipamento?',
    ];
}

/** Pedido de edição de vídeo parecido com o do Intus Cut, com uma entrevista inventada (92 s, com hesitações e partes fracas) para comparar os modelos. */
function ensaio_cut_pedido(): string {
    $falas = [[0.4, 3.1, 'Então, é, gravando... pode ir?'], [3.6, 9.8, 'Oi, eu sou a Débora e hoje eu queria contar como eu voltei a treinar em casa sendo mãe de duas crianças.'],
        [10.5, 14.2, 'Eu ficava sempre sem tempo, achava que treino tinha que ser de uma hora na academia.'], [14.9, 17.0, 'Aham, e aí, bom.'],
        [17.8, 26.5, 'O que mudou foi que o professor montou treinos de vinte minutos, no meu horário, e eu fui ajustando com ele toda semana.'],
        [27.2, 31.0, 'Deixa eu pensar como falar isso... assim.'], [31.8, 41.9, 'A primeira semana eu só queria provar que dava. Fiz três treinos, e a sensação de ter cumprido me deu vontade de continuar.'],
        [42.6, 45.0, 'Ai, o cachorro latiu, desculpa.'], [46.0, 55.8, 'Hoje eu treino quatro vezes por semana, e o que mais me surpreende é que eu não preciso de motivação, virou parte do dia.'],
        [56.5, 63.0, 'Se você acha que não tem tempo, começa pequeno. Dez minutos já contam.'], [63.8, 68.0, 'É isso? Tá bom assim?'],
        [69.0, 80.4, 'E o melhor é ter alguém olhando o seu treino, corrigindo, ajustando quando a semana aperta. Isso me manteve firme.'],
        [81.2, 88.6, 'Se quiser testar, tem o teste grátis de três dias no app da Intus, é só treino, sem compromisso.'], [89.4, 91.8, 'Tchau, pessoal!']];
    $fontes = [['id' => 'principal', 'nome' => 'Entrevista Débora', 'duracao' => 92.0, 'falas' => $falas]];
    return implode("\n\n", [
        'Você edita vídeos curtos da Intus Fit, consultoria online de treino e nutrição do Luiz Nunes (é consultoria online, não academia).',
        'Monte um vídeo do tipo "Depoimento de aluna", formato Reels 9:16, com 30 a 45 segundos no total.',
        'Regras: quando houver fala, escolha trechos de fala completos, com começo, meio e fim claros; corte em pausas, nunca no meio de palavra; pode mudar a ordem se a história ficar melhor; os 3 primeiros segundos precisam prender; não prometa resultado, prazo ou número; não use travessão nem a construção "não é X, é Y"; português do Brasil.',
        'Orientações do Luiz: começar pela história da Débora, terminar chamando para o teste grátis, sem as partes de hesitação.',
        'Vídeos disponíveis, com as falas no formato [início_s, fim_s, "texto"] no tempo da gravação de cada um:',
        json_encode($fontes, JSON_UNESCAPED_UNICODE),
        'Responda só com JSON: {"nome":"título curto do projeto","gancho":"frase de até 8 palavras para a tela nos primeiros segundos","destaque":["1 ou 2 palavras do gancho"],"trechos":[{"fonte":"id do vídeo","ini":segundos,"fim":segundos}],"textos":[{"texto":"texto curto na tela","ini":segundos_no_video_final,"fim":segundos_no_video_final,"posicao":"topo|meio|baixo"}],"explicacao":"uma frase dizendo a lógica da montagem"}. Os trechos ficam na ordem em que vão aparecer.',
    ]);
}

/**
 * Confere o que o modelo devolveu para o pedido acima: JSON legível, trechos dentro do vídeo e sem cortar fala ao meio, duração dentro do pedido,
 * gancho curto, sem travessão, sem as hesitações do vídeo. Devolve ['ok' => bool, 'itens' => [[bool, frase]], 'nota' => 0..n de conferências ok, 'de' => n, 'resumo' => ...].
 */
function ensaio_cut_avaliar(string $texto): array {
    $j = atd_extrair_json($texto);
    $itens = [];
    $c = function (bool $ok, string $frase) use (&$itens) { $itens[] = [$ok, $frase]; };
    $c(is_array($j), 'respondeu um JSON legível');
    if (!is_array($j)) return ['ok' => false, 'itens' => $itens, 'nota' => 0, 'de' => 1, 'resumo' => []];
    $tr = is_array($j['trechos'] ?? null) ? $j['trechos'] : [];
    $c(count($tr) >= 2, 'tem 2 ou mais trechos (' . count($tr) . ')');
    $dentro = true; $total = 0.0; $ruins = [];
    foreach ($tr as $t) {
        $ini = (float)($t['ini'] ?? -1); $fim = (float)($t['fim'] ?? -1);
        if (($t['fonte'] ?? '') !== 'principal' || $ini < 0 || $fim > 92.0 || $fim - $ini < 0.4) $dentro = false; else $total += $fim - $ini;
        foreach ([[0.4, 3.1], [14.9, 17.0], [27.2, 31.0], [42.6, 45.0], [63.8, 68.0], [89.4, 91.8]] as [$a, $b]) {
            if ($ini <= $a + 0.05 && $fim >= $b - 0.05) $ruins[] = "$a-$b";
        }
    }
    $c($dentro, 'todos os trechos existem no vídeo (fonte e tempos válidos)');
    $c($total >= 27 && $total <= 50, 'soma dos trechos perto do pedido de 30 a 45 s (' . round($total, 1) . ' s)');
    $c(!$ruins, 'deixou de fora as partes de hesitação (' . ($ruins ? 'entrou: ' . implode(', ', array_unique($ruins)) : 'nenhuma entrou') . ')');
    $gancho = trim((string)($j['gancho'] ?? ''));
    $c($gancho !== '' && count(preg_split('/\s+/u', $gancho)) <= 9, 'gancho de até 8 palavras ("' . mb_substr($gancho, 0, 60) . '")');
    $c(!preg_match('/[\x{2014}\x{2013}]/u', $texto), 'sem travessão');
    $c(!preg_match('/n[ãa]o é[^.]{0,60},\s*é /iu', $texto), 'sem "não é X, é Y"');
    $c(is_array($j['textos'] ?? null) && count($j['textos']) >= 1, 'sugeriu textos na tela');
    $ok = count(array_filter($itens, fn($i) => $i[0]));
    return ['ok' => $ok === count($itens), 'itens' => $itens, 'nota' => $ok, 'de' => count($itens), 'resumo' => ['gancho' => $gancho, 'explicacao' => (string)($j['explicacao'] ?? ''), 'trechos' => count($tr), 'segundos' => round($total, 1)]];
}
