<?php
/**
 * Caixa de entrada da Central, sem a Meta (app/api/_atd_inbox.php), em SQLite na memória e com o Claude de mentira.
 *   php -c <ini com pdo_sqlite> testes/inbox.php
 * Nunca fala com a Meta nem com a Anthropic e nunca precisa de chave.
 */
require_once __DIR__ . '/../app/api/_atd_inbox.php';

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function erro(string $rot, callable $f, string $trecho): void {
    try { $f(); eq($rot, 'não recusou', 'recusou'); }
    catch (InvalidArgumentException $e) { eq($rot, strpos($e->getMessage(), $trecho) !== false, true); }
}
function em(string $s): DateTimeImmutable { return new DateTimeImmutable($s, wa_tz()); }
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    wa_garantir_tabelas($pdo);
    atd_garantir_config($pdo);
    $pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, origem TEXT, dt_cadastro TEXT, professores_responsaveis TEXT, stbloqueio TEXT, ultimo_acesso TEXT, indicado_por_idatleta INT)");
    $pdo->exec("CREATE TABLE intus_lead (idlead INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, whatsapp TEXT, email TEXT, objetivo TEXT, origem TEXT, status TEXT, ip TEXT, indicado_por_idatleta INT, criado_em TEXT)");
    $pdo->exec("CREATE TABLE intus_sessao (idsessao INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, dtsessao TEXT, nao_contar INT DEFAULT 0)");
    $pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, dshistorico TEXT, tipo_plano TEXT, dtinicio TEXT, dtvencimento TEXT, stpgto TEXT, vlpagamento REAL, stmatricula TEXT)");
    $pdo->exec("CREATE TABLE intus_acesso (id INTEGER PRIMARY KEY AUTOINCREMENT, user_tipo TEXT, user_id INT, evento TEXT, criado_em TEXT)");
    trGarantirTabelas($pdo);
    wa_segredo_gravar($pdo, 'anthropic_config', ['api_key' => 'sk-ant-TESTE-NAO-VALE']);
    $GLOBALS['__pdos'][] = $pdo;   // trGarantirTabelas guarda por spl_object_id: um PDO liberado e reaproveitado enganaria o cache
    return $pdo;
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
function msgs(PDO $pdo, int $id): array {
    return $pdo->query("SELECT direcao, origem, texto, status, wa_id, processada FROM intus_wa_mensagem WHERE idcontato = $id ORDER BY idmensagem")->fetchAll();
}
function nova(PDO $pdo, string $tel = '45 99111-0001', string $nome = 'Ana Lead', string $texto = '', ?DateTimeImmutable $q = null): int {
    $c = inbox_contato_novo($pdo, $tel, $nome, $q);
    if ($texto !== '') inbox_registrar_lead($pdo, (int)$c['idcontato'], $texto, $q);
    return (int)$c['idcontato'];
}
$t0 = em('2026-11-11 10:00:00');

echo "\n══ Nova conversa ══\n";
$pdo = banco();
erro('telefone inválido é recusado', fn() => inbox_contato_novo($pdo, '123', 'X'), 'Telefone inválido');
erro('telefone em branco é recusado', fn() => inbox_contato_novo($pdo, '', 'X'), 'Telefone inválido');
$c = inbox_contato_novo($pdo, '(45) 99111-0001', 'Ana Lead', $t0);
eq('telefone normalizado com o DDI', $c['telefone'], '5545991110001');
eq('nome guardado', $c['nome'], 'Ana Lead');
eq('o mesmo telefone não duplica o contato', [inbox_contato_novo($pdo, '45991110001', 'Outro nome', $t0)['idcontato'], (int)$pdo->query('SELECT COUNT(*) FROM intus_wa_contato')->fetchColumn()], [$c['idcontato'], 1]);
eq('conversa sem mensagem ainda não aparece na lista', inbox_lista($pdo, $t0)['conversas'], []);

echo "\n══ Colar o que a pessoa escreveu ══\n";
$id = (int)$c['idcontato'];
erro('texto vazio é recusado', fn() => inbox_registrar_lead($pdo, $id, '   ', $t0), 'Cole o que a pessoa escreveu');
erro('conversa que não existe', fn() => inbox_registrar_lead($pdo, 999, 'oi', $t0), 'não encontrada');
$r = inbox_registrar_lead($pdo, $id, 'Oi, vi o Instagram de vocês. Como funciona o teste?', $t0);
$m = msgs($pdo, $id);
eq('gravada como entrada do lead, sem id da Meta, já tratada', [$m[0]['direcao'], $m[0]['origem'], $m[0]['wa_id'], (int)$m[0]['processada'], $m[0]['status']], ['E', 'lead', null, 1, 'recebida']);
eq('janela de 24h passa a contar desta mensagem', wa_contato_por_id($pdo, $id)['ultima_entrada_em'], wa_fmt($t0));
$l = inbox_lista($pdo, $t0);
eq('a conversa aparece esperando resposta', [count($l['conversas']), $l['aguardando'], $l['conversas'][0]['espera_voce'], $l['conversas'][0]['sem_resposta']], [1, 1, true, 1]);
eq('o trecho é o começo da mensagem', substr($l['conversas'][0]['trecho'], 0, 17), 'Oi, vi o Instagra');
$r = inbox_registrar_lead($pdo, nova($pdo, '45 99111-0002', 'Bia Sai'), 'SAIR', $t0);
eq('"SAIR" marca que a pessoa não recebe mais nada', $r['saiu'], true);
$idBia = (int)$pdo->query("SELECT idcontato FROM intus_wa_contato WHERE nome = 'Bia Sai'")->fetchColumn();
$cb = wa_contato_por_id($pdo, $idBia);
eq('contato da pessoa que saiu', [!empty($cb['saiu_em']), $cb['etapa'], $cb['modo']], [true, 'saiu', 'pausado']);
eq('quem saiu não conta como esperando você', inbox_lista($pdo, $t0)['aguardando'], 1);
eq('e a Cora não sugere resposta para quem saiu', inbox_sugerir($pdo, $idBia, new ClaudeFalso(r_ok('responder', 'x')), $t0)['ok'], false);

echo "\n══ A Cora sugere a resposta ══\n";
$pdo = banco();
$id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Oi, como funciona o teste grátis?', $t0);
$cl = new ClaudeFalso(r_ok('responder', 'Oi, Ana! O teste grátis é só de treino, por 3 dias. Qual é o seu objetivo hoje?', '', 'Ana quer conhecer o teste.'));
$usoAntes = (int)atd_uso_hoje($pdo)['chamadas'];
$s = inbox_sugerir($pdo, $id, $cl, $t0);
eq('sugere responder com o texto do Claude', [$s['ok'], $s['acao'], $s['texto']], [true, 'responder', 'Oi, Ana! O teste grátis é só de treino, por 3 dias. Qual é o seu objetivo hoje?']);
eq('o Claude recebeu o histórico da conversa', [$cl->corpos[0]['messages'][0]['role'], $cl->corpos[0]['messages'][0]['content']], ['user', 'Oi, como funciona o teste grátis?']);
eq('custo da sugestão calculado e somado ao uso do dia', [$s['custo_usd'] > 0, (int)atd_uso_hoje($pdo)['chamadas'] - $usoAntes], [true, 1]);
$e = inbox_estado($pdo, $id);
eq('rascunho guardado (não é mensagem enviada)', [$e['rascunho']['texto'], $e['rascunho']['original'] === $e['rascunho']['texto'], count(msgs($pdo, $id))], ['Oi, Ana! O teste grátis é só de treino, por 3 dias. Qual é o seu objetivo hoje?', true, 1]);
eq('resumo do robô vai para o contato', wa_contato_por_id($pdo, $id)['resumo'], 'Ana quer conhecer o teste.');
$conv = inbox_conversa($pdo, $id, $t0);
eq('a conversa traz o rascunho e quantas mensagens esperam', [$conv['rascunho']['texto'] !== '', $conv['esperando_resposta'], count($conv['mensagens'])], [true, 1, 1]);
eq('a lista marca que já tem rascunho', inbox_lista($pdo, $t0)['conversas'][0]['tem_rascunho'], true);

foreach ([['posso treinar com dor no joelho?', 'saude'], ['quanto custa a consultoria?', 'preco'], ['isso é um golpe', 'reclamacao'], ['quero falar com uma pessoa', 'pediu_pessoa']] as [$t, $mot]) {
    $pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', $t, $t0);
    $cl = new ClaudeFalso(r_ok('responder', 'NÃO DEVIA SER CHAMADO'));
    $s = inbox_sugerir($pdo, $id, $cl, $t0);
    eq("\"$t\": é para você, por regra fixa, sem gastar chamada", [$s['acao'], $s['motivo'], $s['regra_fixa'], count($cl->corpos), $s['custo_usd']], ['passar_para_humano', $mot, true, 0, 0.0]);
    $l = inbox_lista($pdo, $t0)['conversas'][0];
    eq("\"$t\": a lista marca 'para você'", [$l['para_voce'], $l['motivo']], [true, $mot]);
}
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', '', $t0);
$pdo->prepare("INSERT INTO intus_wa_mensagem (idcontato, direcao, origem, tipo, texto, midia_id, status, processada, criado_em) VALUES (?, 'E', 'lead', 'video', NULL, 'MID1', 'recebida', 1, ?)")->execute([$id, wa_fmt($t0)]);
$cl = new ClaudeFalso(r_ok('responder', 'x'));
$s = inbox_sugerir($pdo, $id, $cl, $t0);
eq('vídeo da pessoa: é para você e não chama o Claude', [$s['acao'], $s['motivo'], count($cl->corpos)], ['passar_para_humano', 'midia', 0]);

$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Vocês atendem pelo convênio da minha empresa?', $t0);
$s = inbox_sugerir($pdo, $id, new ClaudeFalso(r_ok('passar_para_humano', '', 'duvida', 'Pergunta sobre convênio.')), $t0);
eq('o Claude decide passar: sem rascunho, com o resumo', [$s['acao'], $s['motivo'], $s['texto'], inbox_estado($pdo, $id)['sugestao']['resumo'], isset(inbox_estado($pdo, $id)['rascunho'])], ['passar_para_humano', 'duvida', '', 'Pergunta sobre convênio.', false]);
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Tem treino em casa?', $t0);
$s = inbox_sugerir($pdo, $id, new ClaudeFalso(r_ok('responder', 'Você vai perder 5 kg em 30 dias, garantido!')), $t0);
eq('texto que quebra a regra de escrita vem com aviso para a equipe editar', [$s['ok'], count($s['avisos']) >= 1, inbox_estado($pdo, $id)['rascunho']['avisos'] === $s['avisos']], [true, true, true]);
$s = inbox_sugerir($pdo, nova($pdo, '45 99111-0003', 'Cris', 'Tem treino em casa?', $t0), new ClaudeFalso(r_ok('responder', 'Treino em casa — sem equipamento.')), $t0);
eq('travessão do Claude é trocado por vírgula', $s['texto'], 'Treino em casa, sem equipamento.');

$semSaldo = ['status' => 400, 'erro' => '', 'json' => ['error' => ['message' => 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.']]];
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Tem treino em casa?', $t0);
$s = inbox_sugerir($pdo, $id, new ClaudeFalso($semSaldo), $t0);
eq('Claude sem saldo: erro claro e nada gravado', [$s['ok'], strpos($s['erro'], 'sem saldo') !== false, inbox_estado($pdo, $id)], [false, true, []]);
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', '', $t0);
eq('sem mensagem esperando resposta, pede para colar primeiro', [inbox_sugerir($pdo, $id, new ClaudeFalso(r_ok('responder', 'x')), $t0)['ok']], [false]);
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Tem treino em casa?', $t0);
atd_cfg_gravar($pdo, 'atd_uso', [wa_agora()->format('Y-m-d') => ['chamadas' => INBOX_MAX_SUGESTOES_DIA + ATD_MAX_CHAMADAS_DIA, 'tin' => 0, 'tout' => 0]]);
$cl = new ClaudeFalso(r_ok('responder', 'x'));
$s = inbox_sugerir($pdo, $id, $cl, $t0);
eq('trava de custo do dia: não chama o Claude', [$s['ok'], count($cl->corpos)], [false, 0]);

echo "\n══ Enviei ══\n";
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Oi, como funciona o teste grátis?', $t0);
inbox_sugerir($pdo, $id, new ClaudeFalso(r_ok('responder', 'Oi, Ana! Qual é o seu objetivo hoje?')), $t0);
erro('resposta vazia é recusada', fn() => inbox_enviei($pdo, $id, ' ', $t0), 'Escreva a resposta');
$t1 = em('2026-11-11 10:05:00');
$r = inbox_enviei($pdo, $id, 'Oi, Ana! Qual é o seu objetivo hoje?', $t1);
$m = msgs($pdo, $id);
eq('enviada como a Cora sugeriu, sem mudar nada: origem robo', $r['origem'], 'robo');
eq('gravada como saída enviada, sem id da Meta', [$m[1]['direcao'], $m[1]['status'], $m[1]['wa_id'], (int)$m[1]['processada']], ['S', 'enviada', null, 1]);
eq('rascunho e sugestão somem', array_keys(inbox_estado($pdo, $id)), []);
$l = inbox_lista($pdo, $t1);
eq('depois de responder, a conversa não espera mais', [$l['aguardando'], $l['conversas'][0]['espera_voce'], $l['conversas'][0]['ultima_direcao']], [0, false, 'S']);
eq('nada sobra pendente', inbox_pendentes($pdo, $id), []);
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Oi', $t0);
inbox_sugerir($pdo, $id, new ClaudeFalso(r_ok('responder', 'Oi, Ana! Tudo bem?')), $t0);
eq('texto editado pela equipe: origem pessoa', inbox_enviei($pdo, $id, 'Oi, Ana! Tudo bem? Me conta o que você procura.', $t0)['origem'], 'pessoa');
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'quanto custa?', $t0);
eq('resposta escrita do zero (conversa para você): origem pessoa', inbox_enviei($pdo, $id, 'Oi, Ana! Vou te passar os valores.', $t0)['origem'], 'pessoa');
inbox_registrar_lead($pdo, $id, 'Ok, aguardo', $t0);
eq('a pessoa escreve de novo: volta a esperar você', inbox_lista($pdo, $t0)['aguardando'], 1);

echo "\n══ Resolver sem responder ══\n";
$pdo = banco(); $id = nova($pdo, '45 99111-0001', 'Ana Lead', 'Obrigada!', $t0);
inbox_resolver($pdo, $id, em('2026-11-11 10:01:00'));
$l = inbox_lista($pdo, $t0);
eq('resolvida sai de esperando você', [$l['aguardando'], $l['conversas'][0]['resolvida']], [0, true]);
inbox_registrar_lead($pdo, $id, 'Mais uma pergunta: tem treino aos domingos?', em('2026-11-11 10:30:00'));
eq('nova mensagem depois de resolvida volta a esperar', inbox_lista($pdo, em('2026-11-11 10:30:00'))['aguardando'], 1);
erro('resolver conversa que não existe', fn() => inbox_resolver($pdo, 999, $t0), 'não encontrada');

echo "\n══ Ordem da lista ══\n";
$pdo = banco();
$a = nova($pdo, '45 99111-0001', 'Antiga', 'oi', em('2026-11-11 08:00:00'));
$b = nova($pdo, '45 99111-0002', 'Recente', 'oi', em('2026-11-11 09:30:00'));
$c = nova($pdo, '45 99111-0003', 'Respondida', 'oi', em('2026-11-11 09:00:00'));
inbox_enviei($pdo, $c, 'Oi!', em('2026-11-11 09:10:00'));
eq('quem espera há mais tempo fica no topo, as respondidas depois', array_column(inbox_lista($pdo, $t0)['conversas'], 'nome'), ['Antiga', 'Recente', 'Respondida']);

echo "\n══ A partir de um lead da fila ══\n";
$pdo = banco();
$pdo->exec("INSERT INTO intus_lead (nome, whatsapp, email, origem, criado_em) VALUES ('Lia Contato', '45991110012', 'lia@exemplo.com', 'landing-teste-gratis', '2026-11-10 10:00:00')");
$c = inbox_contato_do_lead($pdo, 'l1', $t0);
eq('contato criado com o telefone e o nome do lead, ligado a ele', [$c['telefone'], $c['nome'], (int)$c['idlead']], ['5545991110012', 'Lia Contato', 1]);
eq('abrir de novo não duplica', [inbox_contato_do_lead($pdo, 'l1', $t0)['idcontato'], (int)$pdo->query('SELECT COUNT(*) FROM intus_wa_contato')->fetchColumn()], [$c['idcontato'], 1]);
erro('lead que não existe', fn() => inbox_contato_do_lead($pdo, 'l99', $t0), 'não encontrada');
erro('chave inválida', fn() => inbox_contato_do_lead($pdo, 'xyz', $t0), 'não encontrada');
$pdo->exec("INSERT INTO intus_lead (nome, whatsapp, email, origem, criado_em) VALUES ('Sem Fone', '', 'sf@exemplo.com', 'landing', '2026-11-10 10:00:00')");
erro('lead sem telefone', fn() => inbox_contato_do_lead($pdo, 'l2', $t0), 'Telefone inválido');

echo "\n══ Estrutura ══\n";
$fonte = file_get_contents(__DIR__ . '/../app/api/_atd_inbox.php');
eq('a caixa de entrada nunca chama a Meta', [strpos($fonte, 'wa_enviar_texto') === false, strpos($fonte, 'wa_enviar_template') === false, strpos($fonte, 'graph.facebook') === false, strpos($fonte, 'wa_http_post') === false], [true, true, true, true]);
eq('endpoint: toda ação nova que muda dado só aceita POST', preg_match("/\\\$soPost = \\[[^\\]]*'inbox_nova'[^\\]]*'inbox_lead'[^\\]]*'inbox_registrar'[^\\]]*'inbox_sugerir'[^\\]]*'inbox_enviei'[^\\]]*'inbox_resolver'/s", file_get_contents(__DIR__ . '/../app/api/atendimento.php')), 1);

echo "\n" . ($ruim ? "$ruim FALHA(S) de " . ($ok + $ruim) . " conferencias:\n" : "OK: $ok conferencias passaram.\n");
exit($ruim ? 1 : 0);
