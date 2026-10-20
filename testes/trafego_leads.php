<?php
// Pessoas captadas (app/api/_trafego.php, trLeads), em SQLite na memória: quem são, contato, origem e a etapa do funil de cada uma.
//   php -c <ini com pdo_sqlite e mbstring> testes/trafego_leads.php
require __DIR__ . '/../app/api/_trafego.php';
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, email TEXT, telefone TEXT, origem TEXT, dt_cadastro TEXT, ultimo_acesso TEXT, indicado_por_idatleta INT, stbloqueio TEXT)");
$pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, dshistorico TEXT, tipo_plano TEXT, dtinicio TEXT, dtvencimento TEXT, stpgto TEXT, vlpagamento REAL, stmatricula TEXT)");
$pdo->exec("CREATE TABLE intus_sessao (idsessao INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, dtsessao TEXT, nao_contar INT DEFAULT 0, tipo TEXT)");
$pdo->exec("CREATE TABLE intus_acesso (idacesso INTEGER PRIMARY KEY AUTOINCREMENT, user_tipo TEXT, user_id INT, evento TEXT, criado_em TEXT)");
$pdo->exec("CREATE TABLE intus_lead (idlead INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, whatsapp TEXT, email TEXT, objetivo TEXT, origem TEXT, status TEXT, indicado_por_idatleta INT, criado_em TEXT)");
$t0 = strtotime('2026-10-19 15:00:00 UTC');   // hoje = 2026-10-19 em Brasília
$at = fn($id, $nome, $tel, $quando = '2026-10-18 10:00:00', $origem = 'landing-teste-gratis', $ref = null) => $pdo->prepare("INSERT INTO atleta (idatleta, nome, email, telefone, origem, dt_cadastro, indicado_por_idatleta) VALUES (?,?,?,?,?,?,?)")->execute([$id, $nome, strtolower(explode(' ', $nome)[0]) . '@t.com', $tel, $origem, $quando, $ref]);
$teste = fn($id, $inicio, $vence) => $pdo->prepare("INSERT INTO intus_mensalidade (idatleta, dshistorico, dtinicio, dtvencimento, stpgto, vlpagamento, stmatricula) VALUES (?,?,?,?,?,?,?)")->execute([$id, 'Teste Grátis (3 dias)', $inicio, $vence, 'S', 0, 'ativa']);
$conta = function ($vid, $sid, $id, $utm = 'instagram', $ref = null) use ($pdo, $UA, $t0) {
    trRegistrarVisita($pdo, ['vid' => $vid, 'sid' => $sid, 'pagina' => '/teste-gratis.html', 'utm_source' => $utm, 'utm_campaign' => 'outubro', 'ref' => $ref], $UA, [], $t0 - 3000);
    trRegistrarEvento($pdo, ['vid' => $vid, 'sid' => $sid, 'pagina' => '/teste-gratis.html', 'evento' => 'conta_criada', 'valor' => $id], $UA, $t0 - 2900);
};
// A: só criou a conta
$at(1, 'Ana Souza', '(45) 99999-1111'); $teste(1, '2026-10-18', '2026-10-21'); $conta('aaaaaaaa01', 'sssssss001', 1);
// B: abriu o app, não treinou
$at(2, 'Bruno Lima', '45988882222'); $teste(2, '2026-10-18', '2026-10-21'); $conta('aaaaaaaa02', 'sssssss002', 2);
$pdo->exec("INSERT INTO intus_acesso (user_tipo, user_id, evento, criado_em) VALUES ('aluno',2,'abertura','2026-10-18 10:30:00')");
// C: treinou em teste
$at(3, 'Carla Dias', '+55 45 97777-3333'); $teste(3, '2026-10-18', '2026-10-21'); $conta('aaaaaaaa03', 'sssssss003', 3, 'instagram', 42);
$pdo->exec("INSERT INTO intus_sessao (idatleta, dtsessao, tipo) VALUES (3,'2026-10-18','musculacao'),(3,'2026-10-19','musculacao')");
$pdo->exec("INSERT INTO intus_acesso (user_tipo, user_id, evento, criado_em) VALUES ('aluno',3,'abertura','2026-10-18 11:00:00')");
// D: teste acabou, treinou, não comprou
$at(4, 'Daniel Rocha', '45966664444', '2026-10-10 10:00:00'); $teste(4, '2026-10-10', '2026-10-13');
$pdo->exec("INSERT INTO intus_sessao (idatleta, dtsessao, tipo) VALUES (4,'2026-10-11','musculacao')");
$conta('aaaaaaaa04', 'sssssss004', 4);
// E: virou cliente (matrícula paga)
$at(5, 'Elisa Prado', '45955555555', '2026-10-05 10:00:00'); $teste(5, '2026-10-05', '2026-10-08');
$pdo->exec("INSERT INTO intus_mensalidade (idatleta, dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto, vlpagamento, stmatricula) VALUES (5,'Trimestral','Trimestral','2026-10-09','2027-01-09','S',450,'ativa')");
$conta('aaaaaaaa05', 'sssssss005', 5);
// F: matrícula criada, falta pagar
$at(6, 'Fábio Neto', '45944446666', '2026-10-12 10:00:00'); $teste(6, '2026-10-12', '2026-10-15');
$pdo->exec("INSERT INTO intus_mensalidade (idatleta, dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto, vlpagamento, stmatricula) VALUES (6,'Mensal','Mensal','2026-10-16','2026-11-16','N',NULL,'ativa')");
$conta('aaaaaaaa06', 'sssssss006', 6);
// G: contato sem conta (lead), com id no evento
$pdo->exec("INSERT INTO intus_lead (nome, whatsapp, email, objetivo, origem, status, criado_em) VALUES ('Gabriela Alves','45933337777','g@t.com','Emagrecer','landing-teste-gratis','novo','2026-10-18 20:00:00')");
trRegistrarVisita($pdo, ['vid' => 'aaaaaaaa07', 'sid' => 'sssssss007', 'pagina' => '/teste-gratis.html'], $UA, [], $t0 - 3000);
trRegistrarEvento($pdo, ['vid' => 'aaaaaaaa07', 'sid' => 'sssssss007', 'pagina' => '/teste-gratis.html', 'evento' => 'lead_enviado', 'valor' => 1], $UA, $t0 - 2900);
// H: conta do teste grátis que a medição NÃO viu
$at(8, 'Heitor Cruz', '45922228888', '2026-10-17 09:00:00');
// I: evento sem identificação
trRegistrarEvento($pdo, ['vid' => 'aaaaaaaa09', 'sid' => 'sssssss009', 'pagina' => '/teste-gratis.html', 'evento' => 'conta_criada'], $UA, $t0 - 2000);
// J: conta de outra origem (não é do teste grátis e não tem evento): não entra
$at(10, 'Joana Fora', '45911110000', '2026-10-17 09:00:00', 'professor');

$r = trLeads($pdo, '2026-10-01', '2026-10-19', 'atleta', $t0);
$por = []; foreach ($r['pessoas'] as $p) $por[$p['nome']] = $p;

echo "\n══ Etapas do funil ══\n";
eq('só criou a conta', $por['Ana Souza']['etapa'], 'so_conta');
eq('abriu o app (acesso registrado) e não treinou', $por['Bruno Lima']['etapa'], 'abriu_app');
eq('treinou durante o teste', [$por['Carla Dias']['etapa'], $por['Carla Dias']['treinos'], $por['Carla Dias']['primeiro_treino'], $por['Carla Dias']['ultimo_treino']], ['treinando', 2, '2026-10-18', '2026-10-19']);
eq('teste acabou sem comprar (mesmo tendo treinado)', [$por['Daniel Rocha']['etapa'], $por['Daniel Rocha']['teste']['expirou'], $por['Daniel Rocha']['teste']['dias_restantes']], ['teste_expirado', true, -6]);
eq('matrícula paga: virou cliente, sem mensagem de venda', [$por['Elisa Prado']['etapa'], $por['Elisa Prado']['matricula']['pago'], $por['Elisa Prado']['mensagem']], ['cliente', true, '']);
eq('matrícula criada e não paga: negociando', [$por['Fábio Neto']['etapa'], $por['Fábio Neto']['matricula']['pago']], ['negociando', false]);
eq('contato sem conta', [$por['Gabriela Alves']['etapa'], $por['Gabriela Alves']['tipo'], $por['Gabriela Alves']['objetivo']], ['contato', 'contato', 'Emagrecer']);
eq('o plano de teste não conta como matrícula paga', $por['Ana Souza']['matricula'], null);
eq('o teste em andamento mostra os dias restantes', $por['Ana Souza']['teste']['dias_restantes'], 2);

echo "\n══ Quem entra na lista ══\n";
eq('conta do teste grátis que a medição não viu entra, marcada como "sem medição"', [$por['Heitor Cruz']['medido'], $por['Heitor Cruz']['etapa']], [false, 'so_conta']);
eq('conta de outra origem e sem medição não entra', isset($por['Joana Fora']), false);
eq('evento sem identificação aparece como pessoa anônima', count(array_filter($r['pessoas'], fn($p) => $p['tipo'] === 'anonimo')), 1);
eq('captadas medidas = eventos de conta criada e contato do período', $r['captadas_medidas'], 8);
eq('quantas não foram medidas', $r['sem_medicao'], 1);
eq('o resumo soma todas as pessoas', array_sum($r['resumo']), count($r['pessoas']));
eq('resumo por etapa', [$r['resumo']['cliente'], $r['resumo']['negociando'], $r['resumo']['teste_expirado'], $r['resumo']['treinando'], $r['resumo']['abriu_app'], $r['resumo']['contato']], [1, 1, 1, 1, 1, 1]);
eq('período sem ninguém devolve lista vazia', trLeads($pdo, '2025-01-01', '2025-01-31', 'atleta', $t0)['pessoas'], []);

echo "\n══ Contato e origem ══\n";
eq('telefone vira número do WhatsApp com DDI (com máscara, sem DDD de país e com +55)', [$por['Ana Souza']['whatsapp'], $por['Bruno Lima']['whatsapp'], $por['Carla Dias']['whatsapp']], ['5545999991111', '5545988882222', '5545977773333']);
eq('telefone inválido vira vazio', trWhatsapp('123'), '');
eq('origem medida: canal e campanha', [$por['Ana Souza']['canal'], $por['Ana Souza']['utm']['campaign']], ['instagram', 'outubro']);
eq('indicação mostra o nome de quem indicou (aluna 42 não existe: cai no número)', [$por['Carla Dias']['canal'], $por['Carla Dias']['indicado_por']], ['indicacao', 'Aluno #42']);
$pdo->exec("INSERT INTO atleta (idatleta, nome, origem, dt_cadastro) VALUES (42, 'Indicadora Oficial', 'professor', '2026-01-01 10:00:00')");
$r2 = trLeads($pdo, '2026-10-01', '2026-10-19', 'atleta', $t0); $p3 = null; foreach ($r2['pessoas'] as $p) if ($p['nome'] === 'Carla Dias') $p3 = $p;
eq('com a indicadora cadastrada, mostra o nome dela', $p3['indicado_por'], 'Indicadora Oficial');
eq('mais novos primeiro', $r['pessoas'][0]['criado_em'] >= $r['pessoas'][count($r['pessoas']) - 1]['criado_em'], true);

echo "\n══ Mensagem de abordagem ══\n";
foreach (['so_conta', 'abriu_app', 'treinando', 'teste_expirado', 'negociando', 'contato'] as $e) {
    $m = trMensagem($e, 'Maria Clara');
    eq("mensagem de $e: chama pelo primeiro nome, é da Intus Fit e não usa travessão", [strpos($m, 'Oi Maria,') === 0, strpos($m, 'Intus Fit') !== false, strpos($m, '—') === false && strpos($m, '–') === false], [true, true, true]);
}
eq('cada etapa tem uma ação sugerida', count(array_filter(TR_ETAPAS, fn($e) => $e[2] !== '')), 7);


echo "\n══ Controle de contato (tabela intus_lead_contato) ══\n";
$u = fn($b) => trContatoRegistrar($pdo, $b, 1, 'Luiz Nunes', $t0);
$r0 = trLeads($pdo, '2026-10-01', '2026-10-19', 'atleta', $t0);
eq('antes de qualquer contato: ninguém contatado, todos (menos clientes) na fila', [$r0['resumo_contato']['nao_contatados'], $r0['resumo_contato']['contatados'], $r0['pessoas'][0]['controle']['total']], [count(array_filter($r0['pessoas'], fn($p) => $p['etapa'] !== 'cliente')), 0, 0]);
$ok1 = $u(['chave' => 'a1', 'status' => 'chamado', 'canal' => 'whatsapp_link', 'telefone' => '5545999991111']);
eq('registrar contato grava e devolve o registro', [$ok1['ok'], $ok1['registro']['status'], $ok1['registro']['usuario']], [true, 'chamado', 'Luiz Nunes']);
eq('horário do contato volta em Brasília', $ok1['registro']['criado_em'], '2026-10-19 12:00:00');
$u(['chave' => 'a1', 'status' => 'respondeu', 'canal' => 'whatsapp_link', 'nota' => 'Quer saber o preço do trimestral']);
$u(['chave' => 'a2', 'status' => 'retornar', 'canal' => 'ligacao', 'retorno_em' => '2026-10-19', 'nota' => 'Ligar à tarde']);
$u(['chave' => 'a3', 'status' => 'sem_interesse', 'canal' => 'whatsapp_link']);
$r1 = trLeads($pdo, '2026-10-01', '2026-10-19', 'atleta', $t0);
$por1 = []; foreach ($r1['pessoas'] as $p) $por1[$p['nome']] = $p;
eq('a situação atual é o último registro', [$por1['Ana Souza']['controle']['ultimo']['status'], $por1['Ana Souza']['controle']['total'], $por1['Ana Souza']['controle']['ultimo']['nota']], ['respondeu', 2, 'Quer saber o preço do trimestral']);
eq('o histórico vem do mais novo para o mais antigo', array_column($por1['Ana Souza']['controle']['historico'], 'status'), ['respondeu', 'chamado']);
eq('quem nunca foi contatado continua sem registro', $por1['Fábio Neto']['controle']['ultimo'], null);
eq('resumo do contato: não contatados, contatados, retornos de hoje e encerrados', [$r1['resumo_contato']['nao_contatados'], $r1['resumo_contato']['contatados'], $r1['resumo_contato']['retornos_pendentes'], $r1['resumo_contato']['encerrados']], [$r0['resumo_contato']['nao_contatados'] - 3, 2, 1, 1]);
eq('cliente não entra na conta de contato', $por1['Elisa Prado']['controle']['ultimo'], null);
eq('retorno agendado para depois de hoje não conta como pendente', (function () use ($pdo, $t0) { trContatoRegistrar($pdo, ['chave' => 'a4', 'status' => 'retornar', 'canal' => 'outro', 'retorno_em' => '2026-10-25'], 1, 'L', $t0); return trLeads($pdo, '2026-10-01', '2026-10-19', 'atleta', $t0)['resumo_contato']['retornos_pendentes']; })(), 1);
echo "\n══ Validação do que vem da tela ══\n";
eq('pessoa inválida é recusada', [$u(['chave' => "a1'; DROP", 'status' => 'chamado', 'canal' => 'outro'])['ok'], $u(['chave' => 'x1', 'status' => 'chamado', 'canal' => 'outro'])['ok']], [false, false]);
eq('situação inválida é recusada', $u(['chave' => 'a1', 'status' => 'hackeado', 'canal' => 'outro'])['ok'], false);
eq('canal inválido é recusado', $u(['chave' => 'a1', 'status' => 'chamado', 'canal' => 'pombo'])['ok'], false);
eq('"retornar" exige a data e a data precisa ser real', [$u(['chave' => 'a1', 'status' => 'retornar', 'canal' => 'outro'])['ok'], $u(['chave' => 'a1', 'status' => 'retornar', 'canal' => 'outro', 'retorno_em' => '2026-13-45'])['ok']], [false, false]);
eq('anotação vazia é recusada', $u(['chave' => 'a1', 'status' => 'nota', 'canal' => 'outro', 'nota' => '  '])['ok'], false);
$n = $u(['chave' => 'a1', 'status' => 'nota', 'canal' => 'outro', 'nota' => '<script>alert(1)</script> ' . str_repeat('x', 800)]);
eq('anotação perde marcação de código e é limitada a 500 caracteres', [strpos($n['registro']['nota'], '<') === false, mb_strlen($n['registro']['nota'])], [true, 500]);
eq('telefone inválido vira vazio em vez de lixo', (function () use ($pdo, $t0) { trContatoRegistrar($pdo, ['chave' => 'a5', 'status' => 'nota', 'canal' => 'outro', 'nota' => 'x', 'telefone' => 'abc'], 1, 'L', $t0); return $pdo->query("SELECT COALESCE(telefone,'-') FROM intus_lead_contato WHERE chave='a5' ORDER BY idlc DESC LIMIT 1")->fetchColumn(); })(), '-');
eq('a tabela só ganha linhas (nenhum apagar no código)', preg_match('/DELETE\s+FROM\s+intus_lead_contato|UPDATE\s+intus_lead_contato|DROP\s+TABLE\s+intus_lead_contato/i', file_get_contents(__DIR__ . '/../app/api/_trafego.php')) === 0, true);

echo "\n══ WhatsApp da Intus (tabelas da Meta) ══\n";
wa_garantir_tabelas($pdo);
$agoraWa = new DateTimeImmutable('2026-10-19 12:00:00', new DateTimeZone('America/Sao_Paulo'));
$tw = $agoraWa->getTimestamp();
$pdo->exec("INSERT INTO intus_wa_contato (telefone, nome, etapa, modo, optin_em, saiu_em, ultima_entrada_em, precisa_humano, criado_em, atualizado_em) VALUES
  ('5545999991111','Ana','novo','robo','2026-10-18 10:00:00',NULL,'" . gmdate('Y-m-d H:i:s', $tw - 3600) . "',1,'2026-10-18 10:00:00','2026-10-18 10:00:00'),
  ('5545988882222','Bruno','novo','robo',NULL,'2026-10-18 12:00:00','" . gmdate('Y-m-d H:i:s', $tw - 7200) . "',0,'2026-10-18 10:00:00','2026-10-18 10:00:00'),
  ('5545977773333','Carla','novo','robo','2026-10-18 10:00:00',NULL,'" . gmdate('Y-m-d H:i:s', $tw - 3 * 86400) . "',0,'2026-10-18 10:00:00','2026-10-18 10:00:00')");
$pdo->exec("INSERT INTO intus_wa_mensagem (idcontato, direcao, origem, tipo, texto, status, processada, criado_em) VALUES
  (1,'S','sequencia','template','oi','enviada',1,'2026-10-18 10:05:00'),(1,'E','cliente','text','quero saber','recebida',1,'2026-10-19 11:00:00'),
  (3,'S','sequencia','template','oi','enviada',1,'2026-10-16 10:05:00')");
$est = trWaEstados($pdo, ['5545999991111', '(45) 98888-2222', '45977773333', '45900000000'], $agoraWa);
eq('Ana: já conversou, respondeu, janela aberta, precisa de humano', [$est['5545999991111']['enviadas'], $est['5545999991111']['recebidas'], $est['5545999991111']['respondeu'], $est['5545999991111']['janela_aberta'], $est['5545999991111']['precisa_humano']], [1, 1, true, true, true]);
eq('Bruno pediu para sair', [$est['5545988882222']['saiu'], $est['5545988882222']['pode_responder']], [true, false]);
eq('Carla escreveu há 3 dias: janela fechada, não dá para responder por texto livre', [$est['5545977773333']['janela_aberta'], $est['5545977773333']['pode_responder']], [false, false]);
eq('número que nunca falou com a Intus não aparece', isset($est['5545900000000']), false);
eq('sem a Meta configurada ninguém pode ser respondido pelo painel', $est['5545999991111']['pode_responder'], false);
eq('e o estado diz que a API não está configurada', $est['5545999991111']['api_configurada'], false);
wa_segredo_gravar($pdo, 'whatsapp_config', ['phone_number_id' => '123', 'access_token' => 'tok-de-teste']);
$est2 = trWaEstados($pdo, ['5545999991111', '5545977773333'], $agoraWa);
eq('com a Meta configurada, quem escreveu nas últimas 24 h pode ser respondido', [$est2['5545999991111']['pode_responder'], $est2['5545977773333']['pode_responder']], [true, false]);
$r2 = trLeads($pdo, '2026-10-01', '2026-10-19', 'atleta', $t0);
$ana = null; foreach ($r2['pessoas'] as $p) if ($p['nome'] === 'Ana Souza') $ana = $p;
eq('a lista de leads traz o estado do WhatsApp de cada pessoa', [$ana['wa']['contato'], $ana['wa']['respondeu'], $r2['wa_configurado']], [true, true, true]);
$chamadas = [];
$http = function ($url, $corpo, $token) use (&$chamadas) { $chamadas[] = [$url, $corpo, $token]; return ['status' => 200, 'json' => ['messages' => [['id' => 'wamid.TESTE1']]], 'corpo' => '', 'erro' => '']; };
$rr = trWaResponder($pdo, 'a1', '5545999991111', 'Oi Ana, sou da Intus. Posso te ajudar com o trimestral?', 1, 'Luiz Nunes', $http, $agoraWa);
eq('responder pelo painel envia à Meta com o texto, o número e o token', [$rr['ok'], count($chamadas), $chamadas[0][1]['to'] ?? null, $chamadas[0][1]['text']['body'] ?? null, $chamadas[0][2] ?? null], [true, 1, '5545999991111', 'Oi Ana, sou da Intus. Posso te ajudar com o trimestral?', 'tok-de-teste']);
eq('a resposta fica na conversa com origem "humano"', $pdo->query("SELECT origem || '/' || direcao || '/' || status FROM intus_wa_mensagem WHERE wa_id = 'wamid.TESTE1'")->fetchColumn(), 'humano/S/enviada');
eq('o atendimento passa para uma pessoa (o robô para) e sai de "precisa de humano"', [$pdo->query("SELECT modo FROM intus_wa_contato WHERE telefone='5545999991111'")->fetchColumn(), (int)$pdo->query("SELECT precisa_humano FROM intus_wa_contato WHERE telefone='5545999991111'")->fetchColumn()], ['humano', 0]);
eq('o contato fica registrado no controle como WhatsApp pelo painel', $pdo->query("SELECT canal || '/' || status FROM intus_lead_contato WHERE chave='a1' ORDER BY idlc DESC LIMIT 1")->fetchColumn(), 'whatsapp_api/chamado');
$n0 = count($chamadas);
eq('quem pediu para sair NÃO recebe', [trWaResponder($pdo, 'a2', '5545988882222', 'oi', 1, 'L', $http, $agoraWa)['ok'], count($chamadas)], [false, $n0]);
eq('janela de 24 h fechada NÃO envia', [trWaResponder($pdo, 'a3', '5545977773333', 'oi', 1, 'L', $http, $agoraWa)['erro'] !== null, count($chamadas)], [true, $n0]);
eq('quem nunca escreveu NÃO recebe', [trWaResponder($pdo, 'a4', '5545900000000', 'oi', 1, 'L', $http, $agoraWa)['ok'], count($chamadas)], [false, $n0]);
eq('mensagem vazia ou gigante é recusada', [trWaResponder($pdo, 'a1', '5545999991111', '   ', 1, 'L', $http, $agoraWa)['ok'], trWaResponder($pdo, 'a1', '5545999991111', str_repeat('a', 1001), 1, 'L', $http, $agoraWa)['ok']], [false, false]);
$httpFalha = fn($u, $c, $t) => ['status' => 400, 'json' => ['error' => ['message' => 'Re-engagement message']], 'corpo' => '', 'erro' => ''];
$pdo->exec("UPDATE intus_wa_contato SET modo='robo' WHERE telefone='5545999991111'");
$rf = trWaResponder($pdo, 'a1', '5545999991111', 'teste', 1, 'L', $httpFalha, $agoraWa);
eq('se a Meta recusar, o erro volta e o robô NÃO é desligado nem o contato registrado', [$rf['ok'], $pdo->query("SELECT modo FROM intus_wa_contato WHERE telefone='5545999991111'")->fetchColumn(), (int)$pdo->query("SELECT COUNT(*) FROM intus_lead_contato WHERE chave='a1' AND canal='whatsapp_api'")->fetchColumn()], [false, 'robo', 1]);

echo "\n══ Estrutura do endpoint ══\n";
$php = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/trafego.php'));
eq('leads, contato e responder_wa passam pela mesma porta de administrador do relatório', preg_match("/'leads', 'contato', 'responder_wa'\], true\)/", $php) === 1 && strpos($php, "empty(\$ctx['admin'])") !== false && strpos($php, "if (\$action === 'leads')") > strpos($php, "empty(\$ctx['admin'])") && strpos($php, "if (\$action === 'contato' || \$action === 'responder_wa')") > strpos($php, "empty(\$ctx['admin'])"), true);
eq('registrar contato e responder pelo WhatsApp só aceitam POST', substr_count($php, "'use POST'") >= 1, true);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
