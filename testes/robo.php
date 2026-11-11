<?php
/**
 * Robô de conversa do WhatsApp (app/api/_atd_robo.php), em SQLite na memória, com a Meta e o Claude de mentira.
 *   php -c <ini com pdo_sqlite> testes/robo.php
 * Nunca fala com a Meta nem com a Anthropic de verdade e nunca precisa de chave.
 */
require_once __DIR__ . '/../app/api/_atd_robo.php';

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function em(string $s): DateTimeImmutable { return new DateTimeImmutable($s, wa_tz()); }
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    wa_garantir_tabelas($pdo);
    atd_garantir_config($pdo);
    return $pdo;
}
class MetaFalsa {
    public array $pedidos = []; public string $modo = 'ok'; private int $n = 0;
    public function __invoke(string $url, array $corpo, string $token): array {
        $this->pedidos[] = $corpo;
        if ($this->modo === 'ok') return ['status' => 200, 'json' => ['messages' => [['id' => 'wamid.S' . (++$this->n)]]], 'erro' => ''];
        return ['status' => 401, 'json' => ['error' => ['message' => 'Invalid OAuth access token']], 'erro' => ''];
    }
}
class ClaudeFalso {
    public array $corpos = []; public $resp;
    public function __construct($resp) { $this->resp = $resp; }
    public function __invoke(string $corpo, string $chave, bool $fb): array { $this->corpos[] = json_decode($corpo, true); return $this->resp; }
}
function r_ok(string $acao, string $texto = '', string $motivo = '', string $resumo = 'Quer começar a treinar em casa.'): array {
    return ['status' => 200, 'erro' => '', 'json' => ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 2500, 'output_tokens' => 120],
        'content' => [['type' => 'text', 'text' => json_encode(['acao' => $acao, 'motivo' => $motivo, 'texto' => $texto, 'resumo' => $resumo], JSON_UNESCAPED_UNICODE)]]]];
}
$CFG = ['phone_number_id' => '111', 'access_token' => 'TOKEN-FALSO', 'api_version' => 'v23.0', 'verify_token' => 'v', 'app_secret' => 's'];
$agora = em('2026-11-11 12:00:00');

/** Cenário: banco com chave do Claude, ajustes do robô e um contato que acabou de escrever. */
function cenario(string $modoRobo = 'todos', array $contato = [], string $texto = 'Oi, como funciona o teste grátis?', ?DateTimeImmutable $quando = null, string $tipo = 'text', ?string $midia = null): array {
    global $agora;
    $pdo = banco();
    wa_segredo_gravar($pdo, 'anthropic_config', ['api_key' => 'sk-ant-TESTE-NAO-VALE']);
    $aj = wa_ajustes($pdo); $aj['robo'] = $modoRobo; wa_segredo_gravar($pdo, 'wa_ajustes', $aj);
    $c = wa_contato_obter_ou_criar($pdo, '5545991110000', 'Ana Lead', $agora);
    $quando = $quando ?? $agora;
    $campos = ['ultima_entrada_em' => wa_fmt($quando)] + $contato;
    wa_contato_atualizar($pdo, (int)$c['idcontato'], $campos, $agora);
    static $n = 0; $n++;
    wa_msg_registrar($pdo, ['idcontato' => (int)$c['idcontato'], 'direcao' => 'E', 'origem' => 'lead', 'tipo' => $tipo, 'texto' => $texto === '' ? null : $texto,
        'wa_id' => 'wamid.E' . $n . mt_rand(), 'status' => 'recebida', 'midia_id' => $midia, 'processada' => 0, 'criado_em' => wa_fmt($quando)], $agora);
    return [$pdo, (int)$c['idcontato']];
}
function mais_msg(PDO $pdo, int $id, string $texto, DateTimeImmutable $quando): void {
    global $agora;
    wa_msg_registrar($pdo, ['idcontato' => $id, 'direcao' => 'E', 'origem' => 'lead', 'tipo' => 'text', 'texto' => $texto, 'wa_id' => 'wamid.M' . mt_rand(), 'processada' => 0, 'criado_em' => wa_fmt($quando)], $agora);
    wa_contato_atualizar($pdo, $id, ['ultima_entrada_em' => wa_fmt($quando)], $agora);
}
function saidas(PDO $pdo, int $id): array {
    return $pdo->query("SELECT origem, texto, tokens_in, tokens_out, status FROM intus_wa_mensagem WHERE idcontato = $id AND direcao = 'S' ORDER BY idmensagem")->fetchAll();
}
function rodar(PDO $pdo, int $id, $claude, ?MetaFalsa $meta = null, array &$avisos = null): array {
    global $CFG, $agora;
    $meta = $meta ?? new MetaFalsa();
    $av = [];
    $r = robo_processar_contato($pdo, $CFG, $id, ['agora' => $agora, 'http_claude' => $claude, 'http_meta' => $meta,
        'avisar' => function ($a, $t) use (&$av) { $av[] = [$a, $t]; }]);
    $avisos = $av;
    return $r;
}

echo "\n══ Regras fixas: o que passa para uma pessoa sem chamar o Claude ══\n";
foreach ([
    ['posso treinar com dor no joelho?', 'saude'], ['tenho uma hérnia de disco', 'saude'], ['estou grávida, pode?', 'saude'], ['tomo remédio para pressão alta', 'saude'],
    ['isso foi uma palhacada', 'reclamacao'], ['vocês me enganaram', 'reclamacao'], ['quero meu reembolso', 'reclamacao'], ['o app não funciona', 'reclamacao'],
    ['quero falar com uma pessoa', 'pediu_pessoa'], ['tem algum atendente aí?', 'pediu_pessoa'], ['não quero falar com robô', 'pediu_pessoa'],
    ['quanto custa a consultoria?', 'preco'], ['qual o valor?', 'preco'], ['tem desconto no pix?', 'preco'], ['dá pra parcelar?', 'preco'],
] as [$t, $m]) eq("passa para pessoa: \"$t\"", robo_motivo_obvio($t), $m);
foreach (['Oi, como funciona o teste grátis?', 'Quero treinar em casa 3 vezes por semana', 'quanto tempo dura o teste?', 'Meu objetivo é ganhar massa', 'ok, obrigada!', '👍', ''] as $t) {
    eq("não passa por regra fixa: \"$t\"", robo_motivo_obvio($t), null);
}

echo "\n══ Texto da resposta ══\n";
eq('travessão vira vírgula', robo_limpar_texto('Treino em casa — sem equipamento'), 'Treino em casa, sem equipamento');
eq('traço curto (en dash) também', robo_limpar_texto('de 3–4 vezes'), 'de 3, 4 vezes');
eq('limite de caracteres', mb_strlen(robo_limpar_texto(str_repeat('a', 2000))), ROBO_MAX_TEXTO);
foreach (['passagem pessoa' => 'pediu_pessoa', 'saúde' => 'saude', 'preço' => 'preco', 'reclamação' => 'reclamacao', 'mídia' => 'midia', 'limite' => 'limite'] as $rot => $m) {
    $t = robo_texto_passagem($m);
    eq("mensagem fixa de $rot sem travessão e sem promessa", [!preg_match('/[\x{2014}\x{2013}]/u', $t), !preg_match('/garant/i', $t), mb_strlen($t) < 260], [true, true, true]);
    $bad = array_filter(atd_analisar_copy($t)['itens'], fn($i) => $i['nivel'] === 'erro');
    eq("mensagem fixa de $rot passa na conferência de escrita", array_values($bad), []);
}

echo "\n══ Histórico para o Claude ══\n";
[$pdo, $id] = cenario('todos', [], 'Oi');
$pdo->exec("DELETE FROM intus_wa_mensagem");
foreach ([['S', 'Oi, Ana! Aqui é a Cora.', 'enviada'], ['S', 'falhou esta', 'falhou'], ['E', 'Oi', 'recebida'], ['E', 'Quero saber do teste', 'recebida'], ['S', 'Claro! Qual seu objetivo?', 'enviada'], ['E', 'Emagrecer', 'recebida']] as $i => [$d, $t, $s]) {
    wa_msg_registrar($pdo, ['idcontato' => $id, 'direcao' => $d, 'origem' => $d === 'E' ? 'lead' : 'robo', 'texto' => $t, 'wa_id' => 'h' . $i, 'status' => $s, 'processada' => 1, 'criado_em' => wa_fmt($agora)], $agora);
}
$h = robo_historico($pdo, $id);
eq('começa no lead (abertura sintética porque a Intus falou primeiro)', $h[0]['role'], 'user');
eq('papéis alternam e a mensagem falhada não entra', array_column($h, 'role'), ['user', 'assistant', 'user', 'assistant', 'user']);
eq('mensagens seguidas do lead viram uma', $h[2]['content'], "Oi\nQuero saber do teste");
eq('termina no lead', $h[count($h) - 1]['content'], 'Emagrecer');

echo "\n══ Travas: quando o robô NÃO responde ══\n";
$claude = new ClaudeFalso(r_ok('responder', 'Oi, Ana! Tudo bem?'));
[$pdo, $id] = cenario('desligado');
$r = rodar($pdo, $id, $claude);
eq('desligado: não faz nada', [$r['acao'], $r['motivo'], count($claude->corpos), count(saidas($pdo, $id))], ['nada', 'robo_desligado', 0, 0]);
eq('desligado: a mensagem continua pendente', (int)$pdo->query("SELECT COUNT(*) FROM intus_wa_mensagem WHERE processada = 0")->fetchColumn(), 1);
eq('o ajuste nasce desligado', wa_ajustes(banco())['robo'], 'desligado');

[$pdo, $id] = cenario('teste');
$r = rodar($pdo, $id, $claude);
eq('só teste: lead real não recebe', [$r['acao'], $r['motivo'], count($claude->corpos)], ['nada', 'fora_do_teste', 0]);
[$pdo, $id] = cenario('teste', ['optin_origem' => 'teste-admin']);
$meta = new MetaFalsa();
$r = rodar($pdo, $id, $claude, $meta);
eq('só teste: contato de teste recebe', [$r['acao'], count($meta->pedidos)], ['respondeu', 1]);

foreach ([['saiu_em' => '2026-11-11 10:00:00'], ['modo' => 'humano'], ['modo' => 'pausado'], ['precisa_humano' => 1, 'motivo_humano' => 'saude']] as $campos) {
    [$pdo, $id] = cenario('todos', $campos);
    $c0 = count($claude->corpos);
    $r = rodar($pdo, $id, $claude);
    eq('não responde a ' . json_encode($campos), [$r['acao'], count($claude->corpos) - $c0, count(saidas($pdo, $id))], ['nada', 0, 0]);
}
[$pdo, $id] = cenario('todos', [], 'Oi, tudo bem?', em('2026-11-10 09:00:00'));
$c0 = count($claude->corpos);
$r = rodar($pdo, $id, $claude);
eq('janela de 24h fechada: não responde', [$r['acao'], count($claude->corpos) - $c0], ['nada', 0]);
[$pdo, $id] = cenario('todos', [], 'Oi, tudo bem?', em('2026-11-11 11:15:00'));
$c0 = count($claude->corpos);
$r = rodar($pdo, $id, $claude);
eq('mensagem de 45 minutos atrás (de quando o robô estava desligado): não responde, só dá como vista', [$r['acao'], $r['motivo'], count($claude->corpos) - $c0, (int)$pdo->query("SELECT COUNT(*) FROM intus_wa_mensagem WHERE processada = 0")->fetchColumn()], ['nada', 'antigas', 0, 0]);
[$pdo, $id] = cenario('todos', [], '', null, 'reaction');
$r = rodar($pdo, $id, $claude);
eq('reação com emoji não pede resposta', [$r['acao'], count(saidas($pdo, $id))], ['nada', 0]);

echo "\n══ Resposta normal ══\n";
$claude = new ClaudeFalso(r_ok('responder', 'Oi, Ana! O teste grátis é só de treino, por 3 dias. Qual é o seu objetivo hoje?', '', 'Ana quer conhecer o teste.'));
[$pdo, $id] = cenario('todos');
$meta = new MetaFalsa();
$r = rodar($pdo, $id, $claude, $meta, $av);
$s = saidas($pdo, $id);
eq('responde', [$r['acao'], $r['motivo']], ['respondeu', 'ok']);
eq('uma mensagem de texto saiu para o número do lead', [count($meta->pedidos), $meta->pedidos[0]['to'], $meta->pedidos[0]['type']], [1, '5545991110000', 'text']);
eq('texto enviado é o do Claude', $meta->pedidos[0]['text']['body'], 'Oi, Ana! O teste grátis é só de treino, por 3 dias. Qual é o seu objetivo hoje?');
eq('registrada como origem robo, com os tokens gastos', [$s[0]['origem'], (int)$s[0]['tokens_in'], (int)$s[0]['tokens_out'], $s[0]['status']], ['robo', 2500, 120, 'enviada']);
eq('mensagem do lead marcada como tratada', (int)$pdo->query("SELECT COUNT(*) FROM intus_wa_mensagem WHERE direcao = 'E' AND processada = 0")->fetchColumn(), 0);
eq('resumo do robô guardado no contato', wa_contato_por_id($pdo, $id)['resumo'], 'Ana quer conhecer o teste.');
eq('ninguém é avisado quando o robô só responde', $av, []);
$corpo = $claude->corpos[0];
eq('usa o modelo dos ajustes, esforço baixo e fallback', [$corpo['model'], $corpo['output_config']['effort'], $corpo['fallbacks']], ['claude-opus-5-5', 'low', 'default']);
eq('prompt de sistema com cache de prompt', $corpo['system'][0]['cache_control']['type'], 'ephemeral');
eq('o prompt tem as regras de escrita e as de passar para uma pessoa', [strpos($corpo['system'][0]['text'], 'travessão') !== false, strpos($corpo['system'][0]['text'], 'passar_para_humano') !== false, strpos($corpo['system'][0]['text'], 'assistente virtual') !== false], [true, true, true]);
eq('a conversa vai como mensagens do usuário', [$corpo['messages'][0]['role'], $corpo['messages'][0]['content']], ['user', 'Oi, como funciona o teste grátis?']);
$r2 = rodar($pdo, $id, $claude, $meta);
eq('rodar de novo sem mensagem nova não responde outra vez', [$r2['acao'], count($meta->pedidos)], ['nada', 1]);

echo "\n══ Várias mensagens seguidas viram uma resposta ══\n";
[$pdo, $id] = cenario('todos', [], 'Oi');
mais_msg($pdo, $id, 'Queria saber do teste', $agora); mais_msg($pdo, $id, 'Vocês têm treino em casa?', $agora);
$claude = new ClaudeFalso(r_ok('responder', 'Oi! Tem sim, o treino pode ser em casa.'));
$meta = new MetaFalsa();
rodar($pdo, $id, $claude, $meta);
eq('uma chamada ao Claude e uma mensagem enviada', [count($claude->corpos), count($meta->pedidos)], [1, 1]);
eq('o Claude recebeu as três mensagens juntas', $claude->corpos[0]['messages'][0]['content'], "Oi\nQueria saber do teste\nVocês têm treino em casa?");

echo "\n══ Passa para uma pessoa ══\n";
foreach ([['posso treinar com dor no joelho?', 'saude'], ['quanto custa?', 'preco'], ['isso é um golpe', 'reclamacao'], ['quero falar com uma pessoa', 'pediu_pessoa']] as [$t, $m]) {
    $claude = new ClaudeFalso(r_ok('responder', 'NÃO DEVIA SER CHAMADO'));
    [$pdo, $id] = cenario('todos', [], $t);
    $meta = new MetaFalsa();
    $r = rodar($pdo, $id, $claude, $meta, $av);
    $c = wa_contato_por_id($pdo, $id);
    eq("\"$t\": passa por regra fixa, sem chamar o Claude", [$r['acao'], $r['motivo'], count($claude->corpos)], ['passou', $m, 0]);
    eq("\"$t\": a mensagem ao lead é a fixa", $meta->pedidos[0]['text']['body'], robo_texto_passagem($m));
    eq("\"$t\": contato marcado para pessoa e automação pausada", [(int)$c['precisa_humano'], $c['motivo_humano'], $c['modo']], [1, $m, 'humano']);
    eq("\"$t\": um aviso por e-mail com o telefone e a última mensagem", [count($av), strpos($av[0][0], 'Ana Lead') !== false, strpos($av[0][1], '5545991110000') !== false, strpos($av[0][1], $t) !== false], [1, true, true, true]);
}
// depois de passar, o robô fica quieto naquela conversa
mais_msg($pdo, $id, 'alô?', $agora);
$c0 = count($claude->corpos); $p0 = count($meta->pedidos);
rodar($pdo, $id, $claude, $meta);
eq('conversa com pessoa: o robô não volta a responder', [count($claude->corpos) - $c0, count($meta->pedidos) - $p0], [0, 0]);

$claude = new ClaudeFalso(r_ok('passar_para_humano', '', 'duvida', 'Pergunta sobre um convênio, que o robô não sabe.'));
[$pdo, $id] = cenario('todos', [], 'Vocês atendem pelo convênio da minha empresa?');
$meta = new MetaFalsa();
$r = rodar($pdo, $id, $claude, $meta, $av);
$c = wa_contato_por_id($pdo, $id);
eq('o Claude decide passar: mensagem fixa, resumo guardado e aviso', [$r['acao'], $r['motivo'], $meta->pedidos[0]['text']['body'] === robo_texto_passagem('duvida'), $c['resumo'], count($av)], ['passou', 'duvida', true, 'Pergunta sobre um convênio, que o robô não sabe.', 1]);
eq('o texto que o Claude pôs junto é ignorado (vale a mensagem fixa)', saidas($pdo, $id)[0]['texto'], robo_texto_passagem('duvida'));

[$pdo, $id] = cenario('todos', [], 'Segue o vídeo', null, 'video', 'MID1');
$claude = new ClaudeFalso(r_ok('responder', 'x'));
$meta = new MetaFalsa();
$r = rodar($pdo, $id, $claude, $meta, $av);
eq('vídeo do lead: avisa a equipe e agradece, sem chamar o Claude', [$r['acao'], $r['motivo'], count($claude->corpos), $meta->pedidos[0]['text']['body'] === robo_texto_passagem('midia'), count($av)], ['passou', 'midia', 0, true, 1]);
// o webhook já marca precisa_humano ao receber mídia; o robô ainda assim avisa a equipe
[$pdo, $id] = cenario('todos', ['precisa_humano' => 1, 'motivo_humano' => 'midia_recebida'], 'Segue a foto', null, 'image', 'MID2');
$meta = new MetaFalsa();
$r = rodar($pdo, $id, new ClaudeFalso(r_ok('responder', 'x')), $meta, $av);
eq('mídia já marcada pelo webhook: o robô ainda responde e avisa', [$r['acao'], count($meta->pedidos), count($av), wa_contato_por_id($pdo, $id)['motivo_humano']], ['passou', 1, 1, 'midia']);

echo "\n══ Conferência do texto do Claude ══\n";
$claude = new ClaudeFalso(r_ok('responder', 'Treino em casa — sem equipamento. Quer começar?'));
[$pdo, $id] = cenario('todos', [], 'Tem treino em casa?');
$meta = new MetaFalsa();
rodar($pdo, $id, $claude, $meta);
eq('travessão do Claude é trocado antes de enviar', $meta->pedidos[0]['text']['body'], 'Treino em casa, sem equipamento. Quer começar?');
foreach (['Você vai perder 5 kg em 30 dias, garantido!' => 'promessa', 'Não é sobre treino, é sobre constância.' => 'nao_e_x_e_y'] as $textoRuim => $regra) {
    $claude = new ClaudeFalso(r_ok('responder', $textoRuim));
    [$pdo, $id] = cenario('todos', [], 'Tem treino em casa?');
    $meta = new MetaFalsa();
    $r = rodar($pdo, $id, $claude, $meta, $av);
    eq("texto que quebra a regra ($regra) não é enviado: vai para uma pessoa", [$r['acao'], $r['motivo'], $meta->pedidos[0]['text']['body'] === robo_texto_passagem('outro'), strpos($av[0][1], $regra) !== false], ['passou', 'copy', true, true]);
}

echo "\n══ Falhas ══\n";
$semSaldo = ['status' => 400, 'erro' => '', 'json' => ['error' => ['message' => 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.']]];
[$pdo, $id] = cenario('todos');
$meta = new MetaFalsa();
$r = rodar($pdo, $id, new ClaudeFalso($semSaldo), $meta, $av);
$c = wa_contato_por_id($pdo, $id);
eq('Claude sem saldo: nada vai ao lead', [$r['acao'], $r['motivo'], count($meta->pedidos)], ['passou', 'falha', 0]);
eq('contato vai para uma pessoa, com o motivo', [(int)$c['precisa_humano'], $c['motivo_humano'], $c['modo']], [1, 'falha', 'humano']);
eq('o aviso explica que falta saldo', [count($av), strpos($av[0][1], 'sem saldo') !== false], [1, true]);
foreach ([['chave recusada', ['status' => 401, 'erro' => '', 'json' => ['error' => ['message' => 'invalid x-api-key']]]], ['429', ['status' => 429, 'erro' => '', 'json' => []]], ['fora do ar', ['status' => 0, 'erro' => 'timeout', 'json' => null]],
          ['JSON ilegível', ['status' => 200, 'erro' => '', 'json' => ['content' => [['type' => 'text', 'text' => 'oi, tudo bem']], 'usage' => []]]], ['recusou', ['status' => 200, 'erro' => '', 'json' => ['stop_reason' => 'refusal', 'content' => []]]]] as [$rot, $resp]) {
    [$pdo, $id] = cenario('todos'); $meta = new MetaFalsa();
    $r = rodar($pdo, $id, new ClaudeFalso($resp), $meta);
    eq("falha ($rot): silêncio para o lead e conversa vai para uma pessoa", [$r['motivo'], count($meta->pedidos), (int)wa_contato_por_id($pdo, $id)['precisa_humano']], ['falha', 0, 1]);
}
[$pdo, $id] = cenario('todos');
$r = rodar($pdo, $id, function () { throw new RuntimeException('boom'); });
eq('exceção no Claude não derruba nem fala com o lead', [$r['motivo'], count(saidas($pdo, $id))], ['falha', 0]);
[$pdo, $id] = cenario('todos'); $pdo->exec("DELETE FROM intus_secrets WHERE chave = 'anthropic_config'");
$r = rodar($pdo, $id, new ClaudeFalso(r_ok('responder', 'x')), null, $av);
eq('sem chave do Claude: vai para uma pessoa e o aviso diz onde cadastrar', [$r['motivo'], strpos($av[0][1], 'whatsapp-keys') !== false], ['falha', true]);
[$pdo, $id] = cenario('todos');
$meta = new MetaFalsa(); $meta->modo = 'recusa';
$r = rodar($pdo, $id, new ClaudeFalso(r_ok('responder', 'Oi, Ana!')), $meta, $av);
eq('Meta recusou o envio: registrado como falhou, pessoa avisada, sem segunda mensagem', [$r['motivo'], saidas($pdo, $id)[0]['status'], count($meta->pedidos), count($av)], ['envio', 'falhou', 1, 1]);

echo "\n══ Limites ══\n";
[$pdo, $id] = cenario('todos');
$aj = wa_ajustes($pdo); $aj['robo_max_respostas_dia'] = 2; wa_segredo_gravar($pdo, 'wa_ajustes', $aj);
$claude = new ClaudeFalso(r_ok('responder', 'Certo!'));
$meta = new MetaFalsa();
for ($i = 1; $i <= 3; $i++) { if ($i > 1) mais_msg($pdo, $id, "mensagem $i", $agora); $r = rodar($pdo, $id, $claude, $meta); }
eq('2 respostas por contato ao dia; a 3ª passa para uma pessoa', [count($claude->corpos), $r['motivo']], [2, 'limite']);
eq('na passagem por limite o lead recebe o aviso de pessoa', end($meta->pedidos)['text']['body'], robo_texto_passagem('limite'));

[$pdo, $id] = cenario('todos');
$aj = wa_ajustes($pdo); $aj['robo_max_dia_total'] = 1; wa_segredo_gravar($pdo, 'wa_ajustes', $aj);
$outro = wa_contato_obter_ou_criar($pdo, '5545992220000', 'Outro', $agora);
wa_msg_registrar($pdo, ['idcontato' => (int)$outro['idcontato'], 'direcao' => 'S', 'origem' => 'robo', 'texto' => 'oi', 'wa_id' => 'x1', 'status' => 'enviada', 'processada' => 1, 'criado_em' => wa_fmt($agora)], $agora);
$claude = new ClaudeFalso(r_ok('responder', 'Certo!')); $meta = new MetaFalsa();
$r = rodar($pdo, $id, $claude, $meta, $av);
eq('trava de custo do dia: sem resposta ao lead, pessoa avisada', [$r['motivo'], count($claude->corpos), count($meta->pedidos), count($av)], ['limite_dia', 0, 0, 1]);

echo "\n══ Concorrência ══\n";
[$pdo, $id] = cenario('todos');
$claude = new ClaudeFalso(r_ok('responder', 'Oi!')); $meta = new MetaFalsa();
$trava = robo_trava($id);
$r = rodar($pdo, $id, $claude, $meta);
robo_destrava($trava);
eq('contato ocupado (outra requisição na mesma conversa): não responde em dobro', [$r['motivo'], count($claude->corpos)], ['ocupado', 0]);
$r = rodar($pdo, $id, $claude, $meta);
eq('liberado: responde', [$r['acao'], count($meta->pedidos)], ['respondeu', 1]);

echo "\n══ Webhook: pendentes ══\n";
[$pdo, $id] = cenario('todos');
$aj = wa_ajustes($pdo);
$meta = new MetaFalsa(); $claude = new ClaudeFalso(r_ok('responder', 'Oi!'));
$res = robo_processar_pendentes($pdo, $CFG, ['agora' => $agora, 'http_claude' => $claude, 'http_meta' => $meta]);
eq('trata todos os contatos com mensagem pendente', [array_keys($res), $res[$id]['acao']], [[$id], 'respondeu']);
$aj['robo'] = 'desligado'; wa_segredo_gravar($pdo, 'wa_ajustes', $aj);
mais_msg($pdo, $id, 'oi de novo', $agora);
eq('desligado: nem consulta os contatos', robo_processar_pendentes($pdo, $CFG, ['agora' => $agora, 'http_claude' => $claude, 'http_meta' => $meta]), []);

echo "\n══ Comparar modelos (ensaio) ══\n";
require_once __DIR__ . '/../app/api/_ensaio_modelos.php';
eq('custo Opus 5.5 (US$ 4 entrada, 20 saída por milhão)', ensaio_custo_usd('claude-opus-5-5', 1000000, 100000), 6.0);
eq('custo Sonnet 5.5 é metade do Opus', ensaio_custo_usd('claude-sonnet-5-5', 1000000, 100000), 3.0);
eq('custo Haiku 5.5', ensaio_custo_usd('claude-haiku-5-5', 1000000, 100000), 0.15);
eq('modelo desconhecido cobra como Opus (não subestima)', ensaio_custo_usd('claude-x', 1000000, 0), 4.0);
[$pdo, $id] = cenario('todos');
$cl = new ClaudeFalso(r_ok('responder', 'Oi!'));
$d = robo_decidir($pdo, ['nome' => 'Ana'], [['role' => 'user', 'content' => 'oi']], $cl, 'claude-sonnet-5-5');
eq('ensaio usa o modelo pedido e informa o tempo', [$cl->corpos[0]['model'], $d['modelo'], is_int($d['ms'])], ['claude-sonnet-5-5', 'claude-sonnet-5-5', true]);
$cl = new ClaudeFalso(r_ok('responder', 'Oi!'));
robo_decidir($pdo, ['nome' => 'Ana'], [['role' => 'user', 'content' => 'oi']], $cl, 'nome inválido; drop');
eq('nome de modelo inválido cai no dos ajustes', $cl->corpos[0]['model'], 'claude-opus-5-5');
$bom = json_encode(['nome' => 'Débora volta a treinar', 'gancho' => 'Mãe de duas, treinando em casa', 'destaque' => ['em casa'],
    'trechos' => [['fonte' => 'principal', 'ini' => 3.6, 'fim' => 9.8], ['fonte' => 'principal', 'ini' => 17.8, 'fim' => 26.5], ['fonte' => 'principal', 'ini' => 56.5, 'fim' => 63.0], ['fonte' => 'principal', 'ini' => 81.2, 'fim' => 88.6]],
    'textos' => [['texto' => 'Treino de 20 minutos', 'ini' => 3, 'fim' => 6, 'posicao' => 'baixo']], 'explicacao' => 'História e chamada para o teste.'], JSON_UNESCAPED_UNICODE);
$a = ensaio_cut_avaliar($bom);
eq('edição boa passa em todas as conferências', [$a['ok'], $a['nota'] === $a['de'], $a['resumo']['segundos']], [true, true, 28.8]);
$ruimJ = json_encode(['gancho' => 'Uma frase muito longa demais para aparecer na tela do vídeo agora', 'trechos' => [['fonte' => 'principal', 'ini' => 0, 'fim' => 4], ['fonte' => 'outro', 'ini' => 1, 'fim' => 5]], 'explicacao' => 'Monta — assim'], JSON_UNESCAPED_UNICODE);
$a = ensaio_cut_avaliar($ruimJ);
eq('edição ruim reprova em fonte inexistente, hesitação, duração, gancho longo e travessão', [$a['ok'], array_column(array_filter($a['itens'], fn($i) => !$i[0]), 1) !== []], [false, true]);
eq('texto que não é JSON reprova', ensaio_cut_avaliar('desculpe, não consegui')['ok'], false);

echo "\n" . ($ruim ? "$ruim FALHA(S) de " . ($ok + $ruim) . " conferencias:\n" : "OK: $ok conferencias passaram.\n");
exit($ruim ? 1 : 0);
