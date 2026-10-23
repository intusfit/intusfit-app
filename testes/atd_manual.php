<?php
/**
 * Testes do atendimento MANUAL dos leads, sem Meta (app/api/_atd_manual.php): a "Fila de hoje" da Central.
 *
 *     php testes/atd_manual.php        (PHP 8.2 com mbstring, openssl, curl e pdo_sqlite)
 *
 * Banco: SQLite em memória, com atleta, leads, matrículas, treinos e eventos de tráfego de mentira. Nada de rede.
 */

require_once __DIR__ . '/../app/api/_atd_manual.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function verdade(string $nome, $cond): void { confere($nome, true, (bool)$cond); }
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
    return $pdo;
}
function conta(PDO $pdo, int $id, string $nome, ?string $tel, string $cad, array $x = []): void {
    $pdo->prepare('INSERT INTO atleta (idatleta, nome, email, telefone, origem, dt_cadastro, professores_responsaveis, stbloqueio, ultimo_acesso) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $nome, strtolower(preg_replace('/\W/', '', $nome)) . '@exemplo.com', $tel, 'app', $cad, null, 'N', null]);
    if (!empty($x['medium'])) {
        $pdo->prepare("INSERT INTO intus_trafego_evento (vid, sid, pagina, evento, valor, canal, utm_medium, utm_campaign, dia, hora, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(['v' . $id, 's' . $id, '/teste-gratis.html', 'conta_criada', $id, 'instagram', $x['medium'], 'campanha', substr($cad, 0, 10), 9, gmdate('Y-m-d H:i:s', strtotime($cad) + 10800)]);
    }
}
function mat(PDO $pdo, int $id, string $plano, string $ini, string $fim, string $pgto = 'N', float $valor = 0): void {
    $pdo->prepare('INSERT INTO intus_mensalidade (idatleta, dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto, vlpagamento, stmatricula) VALUES (?,?,?,?,?,?,?,?)')->execute([$id, $plano, $plano, $ini, $fim, $pgto, $valor, 'ativa']);
}
function treino(PDO $pdo, int $id, string $dia): void { $pdo->prepare('INSERT INTO intus_sessao (idatleta, dtsessao) VALUES (?, ?)')->execute([$id, $dia]); }
function fila_nomes(array $f): array { $n = array_column($f['para_enviar'], 'nome'); sort($n); return $n; }
function item_aguardando(array $f, string $nome): bool { return in_array($nome, array_column($f['aguardando'], 'nome'), true); }
function item(array $f, string $nome): ?array { foreach ($f['para_enviar'] as $i) { if ($i['nome'] === $nome) return $i; } return null; }

$agora = em('2026-10-20 12:00:00');   // terça-feira, meio-dia
$pdo = banco();
conta($pdo, 1, 'Ana Souza', '(45) 99111-0001', '2026-10-20 09:00:00');                           // cadastrou hoje de manhã
conta($pdo, 2, 'Bia Lima', '(45) 99111-0002', '2026-10-19 08:00:00');                            // ontem, sem treinar
conta($pdo, 3, 'Carla Dias', '(45) 99111-0003', '2026-10-19 08:00:00');                          // ontem, já treinou
treino($pdo, 3, '2026-10-19');
conta($pdo, 4, 'Dani Reis', '(45) 99111-0004', '2026-10-17 09:00:00');                           // 3 dias atrás
conta($pdo, 5, 'Eva Paga', '(45) 99111-0005', '2026-10-20 09:30:00', ['medium' => 'cpc']);       // veio de anúncio
conta($pdo, 6, 'Fran Melo', '(45) 99111-0006', '2026-10-12 09:00:00');                           // 8 dias atrás
conta($pdo, 7, 'Gabi Saiu', '(45) 99111-0007', '2026-10-20 08:00:00');
conta($pdo, 8, 'Hana Sem Fone', null, '2026-10-20 08:00:00');
conta($pdo, 9, 'Iris Cliente', '(45) 99111-0009', '2026-10-15 08:00:00');
mat($pdo, 9, 'Consultoria Premium', '2026-10-16', '2026-11-16', 'S', 300);
conta($pdo, 10, 'Joana Encerrada', '(45) 99111-0010', '2026-10-20 08:00:00');
conta($pdo, 11, 'Kika Social', '(45) 99111-0011', '2026-10-20 07:00:00', ['medium' => 'social']);  // origem medida, mas não é anúncio
$pdo->exec("INSERT INTO intus_lead (nome, whatsapp, email, origem, criado_em) VALUES ('Lia Contato', '45991110012', 'lia@exemplo.com', 'landing-teste-gratis', '2026-10-20 10:00:00')");
$idLia = (int)$pdo->lastInsertId();
$cg = wa_contato_obter_ou_criar($pdo, '5545991110007', 'Gabi Saiu', $agora);
wa_contato_atualizar($pdo, (int)$cg['idcontato'], ['saiu_em' => '2026-10-20 09:00:00'], $agora);
trContatoRegistrar($pdo, ['chave' => 'a10', 'status' => 'sem_interesse', 'canal' => 'ligacao', 'telefone' => '5545991110010'], 1, 'Luiz', $agora->getTimestamp());

// ── Configuração ────────────────────────────────────────────────────────────
$cfg0 = atd_manual_cfg($pdo, $agora);
confere('config: padrão é orgânico, de 2 dias atrás', ['organico', '2026-10-18'], [$cfg0['origem'], $cfg0['desde']]);
confere('config: o padrão fica gravado na primeira leitura', true, atd_cfg_existe($pdo, 'atd_manual_cfg'));
foreach ([['origem' => 'tudo', 'desde' => '2026-10-10'], ['origem' => 'organico', 'desde' => '2026-13-45'], ['origem' => 'organico', 'desde' => '2026-10-25'], ['origem' => 'organico', 'desde' => '2026-05-01'], ['origem' => 'organico', 'desde' => 'ontem']] as $ruim) {
    try { atd_manual_cfg_gravar($pdo, $ruim, $agora); confere('config recusada: ' . json_encode($ruim), true, false); }
    catch (InvalidArgumentException $e) { confere('config recusada: ' . json_encode($ruim), true, true); }
}
$cfg = atd_manual_cfg_gravar($pdo, ['origem' => 'organico', 'desde' => '2026-10-01'], $agora);
confere('config salva', ['organico', '2026-10-01'], [$cfg['origem'], $cfg['desde']]);
confere('origem: anúncio', 'pago', atd_manual_origem(['utm' => ['medium' => 'cpc']]));
confere('origem: anúncio em maiúsculas', 'pago', atd_manual_origem(['utm' => ['medium' => 'Paid_Social']]));
confere('origem: story e bio são orgânicos', 'organico', atd_manual_origem(['utm' => ['medium' => 'social']]));
confere('origem: sem medição é orgânico', 'organico', atd_manual_origem(['utm' => []]));
confere('origem: sem utm nenhum', 'organico', atd_manual_origem([]));

// ── A fila de hoje ──────────────────────────────────────────────────────────
$f = atd_manual_fila($pdo, $agora);
confere('fila: quem entra agora', ['Ana Souza', 'Bia Lima', 'Dani Reis', 'Fran Melo', 'Kika Social', 'Lia Contato'], fila_nomes($f));
confere('fila: Ana recebe a boas-vindas (t1)', ['teste-gratis', 't1', 't1'], [item($f, 'Ana Souza')['funil'], item($f, 'Ana Souza')['passo'], item($f, 'Ana Souza')['mensagem']]);
confere('fila: Bia, que não treinou, recebe a t2', 't2', item($f, 'Bia Lima')['passo']);
confere('fila: Carla treinou, então a t2 (sem treino) não é dela', null, item($f, 'Carla Dias'));
confere('fila: Dani está no último dia do teste (t5)', 't5', item($f, 'Dani Reis')['passo']);
confere('fila: Fran, 8 dias depois, recebe a t6 (a t7 só às 18h)', 't6', item($f, 'Fran Melo')['passo']);
confere('fila: contato sem conta recebe o convite c4 do funil de conversa', ['chamou-whatsapp:c4', 'chamou-whatsapp'], [item($f, 'Lia Contato')['passo'], item($f, 'Lia Contato')['funil']]);
confere('fila: origem paga fica fora', null, item($f, 'Eva Paga'));
confere('fila: quem pediu para sair fica fora', null, item($f, 'Gabi Saiu'));
confere('fila: sem telefone fica fora', null, item($f, 'Hana Sem Fone'));
confere('fila: cliente é conversa de pessoa', null, item($f, 'Iris Cliente'));
confere('fila: encerrado fica fora', null, item($f, 'Joana Encerrada'));
confere('fila: resumo do que ficou de fora', [1, 1, 1, 1, 1], [$f['resumo']['fora_da_origem'], $f['resumo']['sem_telefone'], $f['resumo']['pediram_para_sair'], $f['resumo']['encerrados'], $f['resumo']['conversa_de_pessoa']]);
confere('fila: totais', [6, 0, 0], [$f['resumo']['para_enviar'], $f['resumo']['aguardando'], $f['resumo']['enviadas_hoje']]);
confere('fila: meio-dia está dentro do horário', [true, [8, 21]], [$f['no_horario'], $f['horario']]);
confere('fila: a Meta não é usada', false, $f['meta']['usada']);
$a = item($f, 'Ana Souza');
verdade('fila: texto pronto com o nome e o link do app', strpos($a['texto'], 'Oi Ana, tudo bem?') === 0 && strpos($a['texto'], ATD_LINK_APP) !== false && strpos($a['texto'], '{{') === false);
confere('fila: a mensagem do funil aprovado vem marcada como aprovada', true, $a['aprovada']);
verdade('fila: a do funil de conversa é rascunho e a tela precisa saber', item($f, 'Lia Contato')['aprovada'] === false && item($f, 'Lia Contato')['funil_status'] === 'rascunho');
confere('fila: whatsapp normalizado para o link', '5545991110001', $a['whatsapp']);
verdade('fila: os mais atrasados primeiro', (function () use ($f) { $d = array_column($f['para_enviar'], 'devida_em'); $s = $d; sort($s); return $d === $s; })());
confere('fila: atraso em horas', 3, $a['atraso_horas']);

// ── horários e janelas de cada passo ────────────────────────────────────────
$noite = atd_manual_fila($pdo, em('2026-10-20 20:30:00'));
confere('20h30: a Carla, que treinou, recebe a t3', 't3', item($noite, 'Carla Dias')['passo']);
confere('20h30: a Bia não recebe a t3 (ela não treinou) e a t2 já valeu até as 20h', null, item($noite, 'Bia Lima'));
$tarde = atd_manual_fila($pdo, em('2026-10-20 21:30:00'));
confere('21h30: fora do horário a fila avisa, não esconde', [false, true], [$tarde['no_horario'], item($tarde, 'Carla Dias') !== null]);
$dia21 = atd_manual_fila($pdo, em('2026-10-21 12:00:00'));
confere('dia seguinte: Carla recebe a t4', 't4', item($dia21, 'Carla Dias')['passo']);
confere('dia seguinte: o passo velho da Ana (t1) não é oferecido', true, item($dia21, 'Ana Souza') === null || item($dia21, 'Ana Souza')['passo'] !== 't1');
confere('dia seguinte: Ana, que ainda não treinou, está no t2 de hoje', 't2', item($dia21, 'Ana Souza')['passo']);

// quem respondeu não recebe "sem_resposta" e NÃO volta para um passo velho
trContatoRegistrar($pdo, ['chave' => 'a6', 'status' => 'respondeu', 'canal' => 'whatsapp_link', 'telefone' => '5545991110006'], 1, 'Luiz', $agora->getTimestamp());
$f2 = atd_manual_fila($pdo, $agora);
confere('respondeu: a t6 (sem resposta) não vai para quem já respondeu', null, item($f2, 'Fran Melo'));
confere('respondeu: e a t5 velha também não volta no lugar dela', true, item($f2, 'Fran Melo') === null);

// ── Ações da equipe ─────────────────────────────────────────────────────────
confere('ação: pessoa inválida', false, atd_manual_acao($pdo, ['chave' => 'x1', 'acao' => 'enviada'], 1, 'Luiz', $agora)['ok']);
confere('ação: ação inválida', false, atd_manual_acao($pdo, ['chave' => 'a2', 'acao' => 'apagar'], 1, 'Luiz', $agora)['ok']);
confere('ação: passo que não existe', false, atd_manual_acao($pdo, ['chave' => 'a2', 'acao' => 'enviada', 'passo' => 'inventado:z9'], 1, 'Luiz', $agora)['ok']);
confere('ação: passo sem funil', false, atd_manual_acao($pdo, ['chave' => 'a2', 'acao' => 'enviada', 'passo' => ''], 1, 'Luiz', $agora)['ok']);
$r = atd_manual_acao($pdo, ['chave' => 'a2', 'acao' => 'enviada', 'passo' => 't2', 'telefone' => '5545991110002'], 1, 'Luiz', $agora);
confere('ação enviada: ok', [true, false], [$r['ok'], $r['ja']]);
$f3 = atd_manual_fila($pdo, $agora);
confere('enviada: Bia sai de "para enviar"', null, item($f3, 'Bia Lima'));
confere('enviada: Bia passa a "aguardando resposta"', [1, 't2', 'Luiz'], [count($f3['aguardando']), $f3['aguardando'][0]['passo'], $f3['aguardando'][0]['por']]);
confere('enviada: enviadas hoje', 1, $f3['resumo']['enviadas_hoje']);
$cont = trContatosPorChaves($pdo, ['a2'])['a2'];
confere('enviada: o contato aparece em Pessoas Captadas', ['chamado', 1], [$cont['ultimo']['status'], $cont['total']]);
verdade('enviada: a anotação diz qual mensagem foi', strpos($cont['ultimo']['nota'], 'Teste grátis de 3 dias') !== false && strpos($cont['ultimo']['nota'], 'Ainda não treinou') !== false);
$r2 = atd_manual_acao($pdo, ['chave' => 'a2', 'acao' => 'enviada', 'passo' => 't2', 'telefone' => '5545991110002'], 1, 'Luiz', $agora);
confere('enviada de novo: não duplica', [true, true, 1], [$r2['ok'], $r2['ja'], trContatosPorChaves($pdo, ['a2'])['a2']['total']]);
$r = atd_manual_acao($pdo, ['chave' => 'a4', 'acao' => 'pular', 'passo' => 't5'], 1, 'Luiz', $agora);
$f4 = atd_manual_fila($pdo, $agora);
confere('pular: Dani sai da fila e não vira "aguardando"', [null, 0], [item($f4, 'Dani Reis'), count(array_filter($f4['aguardando'], fn($x) => $x['nome'] === 'Dani Reis'))]);
confere('pular: não registra contato', true, !isset(trContatosPorChaves($pdo, ['a4'])['a4']));
$r = atd_manual_acao($pdo, ['chave' => 'a2', 'acao' => 'respondeu', 'telefone' => '5545991110002'], 1, 'Luiz', $agora);
$f5 = atd_manual_fila($pdo, $agora);
confere('respondeu: sai de "aguardando"', 0, count($f5['aguardando']));
confere('respondeu: o placar da t2 mostra 1 enviada e 1 respondida', [1, 1, 100.0], [$f5['placar'][0]['enviadas'], $f5['placar'][0]['responderam'], $f5['placar'][0]['taxa']]);
confere('respondeu: o contato "respondeu" está registrado', 'respondeu', trContatosPorChaves($pdo, ['a2'])['a2']['ultimo']['status']);
$r = atd_manual_acao($pdo, ['chave' => 'a1', 'acao' => 'encerrar', 'telefone' => '5545991110001'], 1, 'Luiz', $agora);
$f6 = atd_manual_fila($pdo, $agora);
confere('encerrar: a pessoa sai da fila para sempre', [null, 'sem_interesse'], [item($f6, 'Ana Souza'), trContatosPorChaves($pdo, ['a1'])['a1']['ultimo']['status']]);
// quem recebeu e não respondeu fica aguardando só por 3 dias
$f7 = atd_manual_fila($pdo, em('2026-10-23 12:00:00'));
confere('aguardando: depois de 3 dias a pessoa sai da lista de espera', 0, count($f7['aguardando']));

// ── Origem e data ───────────────────────────────────────────────────────────
atd_manual_cfg_gravar($pdo, ['origem' => 'pago', 'desde' => '2026-10-01'], $agora);
confere('origem paga: só a Eva', ['Eva Paga'], fila_nomes(atd_manual_fila($pdo, $agora)));
atd_manual_cfg_gravar($pdo, ['origem' => 'todos', 'desde' => '2026-10-01'], $agora);
verdade('origem todos: a Eva entra junto', in_array('Eva Paga', fila_nomes(atd_manual_fila($pdo, $agora)), true));
atd_manual_cfg_gravar($pdo, ['origem' => 'organico', 'desde' => '2026-10-19'], $agora);
$fd = atd_manual_fila($pdo, $agora);
confere('desde 19/10: quem cadastrou antes não entra', [null, null], [item($fd, 'Dani Reis'), item($fd, 'Fran Melo')]);
atd_manual_cfg_gravar($pdo, ['origem' => 'organico', 'desde' => '2026-10-01'], $agora);

// ── Teste A/B rodando: a pessoa recebe a variante, sempre a mesma ───────────
$base = atd_funil_por_id($pdo, 'teste-gratis');
$var = atd_funil_salvar($pdo, ['id' => 'variante-b', 'nome' => 'Variante B', 'status' => 'rascunho', 'mensagens' => [['id' => 't1', 'nome' => 'Boas-vindas curta', 'tipo' => 'agendada', 'dia' => 0, 'condicao' => 'todos', 'texto' => 'Oi {{1}}, seu teste já está no ar: {{2}}']]]);
atd_teste_salvar($pdo, ['nome' => 'curta', 'gatilho' => 'teste-gratis', 'funil_a' => 'teste-gratis', 'funil_b' => 'variante-b', 'pct_b' => 100, 'status' => 'rodando']);
$fab = atd_manual_fila($pdo, $agora);
confere('A/B 100% B: a Kika recebe a variante', ['variante-b:t1', 'b', 'Boas-vindas curta'], [item($fab, 'Kika Social')['passo'], item($fab, 'Kika Social')['lado'], item($fab, 'Kika Social')['mensagem_nome']]);
verdade('A/B: o texto é o da variante, com nome e link', strpos(item($fab, 'Kika Social')['texto'], 'Oi Kika, seu teste já está no ar: ' . ATD_LINK_APP) === 0);
confere('A/B: a mesma pessoa não muda de lado entre duas consultas', item($fab, 'Kika Social')['passo'], item(atd_manual_fila($pdo, $agora), 'Kika Social')['passo']);
confere('A/B: contato sem conta não entra no teste (tem o próprio funil)', 'chamou-whatsapp:c4', item($fab, 'Lia Contato')['passo']);
$rA = atd_manual_acao($pdo, ['chave' => 'a11', 'acao' => 'enviada', 'passo' => 'variante-b:t1', 'telefone' => '5545991110011'], 1, 'Luiz', $agora);
confere('A/B: dá para marcar o passo da variante', true, $rA['ok']);
confere('A/B: o placar separa a variante', true, in_array('variante-b:t1', array_column(atd_manual_fila($pdo, $agora)['placar'], 'passo'), true));
confere('aguardando: quem recebeu e não respondeu aparece por até 3 dias', [true, true], [item_aguardando(atd_manual_fila($pdo, em('2026-10-22 12:00:00')), 'Kika Social'), item_aguardando(atd_manual_fila($pdo, em('2026-10-23 11:59:00')), 'Kika Social')]);
confere('aguardando: passados 3 dias a Kika sai da espera', false, item_aguardando(atd_manual_fila($pdo, em('2026-10-23 12:01:00')), 'Kika Social'));
atd_cfg_gravar($pdo, 'atd_testes', []);
// o último passo do funil vale só por 2 dias; depois disso a pessoa sai da fila, não fica com a mensagem velha
confere('último passo: a Dani recebe a t7 às 18h do dia 8', 't7', item(atd_manual_fila($pdo, em('2026-10-25 19:00:00')), 'Dani Reis')['passo'] ?? null);
confere('último passo: ainda vale no dia seguinte', 't7', item(atd_manual_fila($pdo, em('2026-10-26 12:00:00')), 'Dani Reis')['passo'] ?? null);
confere('último passo: passados 2 dias a Dani sai da fila', null, item(atd_manual_fila($pdo, em('2026-10-27 18:30:00')), 'Dani Reis'));
confere('último passo: e continua fora dias depois', null, item(atd_manual_fila($pdo, em('2026-11-05 12:00:00')), 'Dani Reis'));
confere('quem respondeu não recebe a t7 "sem resposta" (Fran)', null, item(atd_manual_fila($pdo, em('2026-10-20 19:00:00')), 'Fran Melo'));

// ── A equipe escolhe qual mensagem enviar ───────────────────────────────────
conta($pdo, 12, 'Luna Escolha', '(45) 99111-0013', '2026-10-20 11:00:00');
$fe = atd_manual_fila($pdo, $agora);
$luna = item($fe, 'Luna Escolha');
verdade('escolha: a biblioteca traz as mensagens de todos os funis, sem o modelo cru', count($fe['biblioteca']) > 7 && !isset($fe['biblioteca'][0]['modelo']) && in_array('chamou-whatsapp:c4', array_column($fe['biblioteca'], 'passo'), true));
confere('escolha: cada pessoa leva o texto de todas as mensagens, com o nome dela', true, isset($luna['opcoes']['t6']) && strpos($luna['opcoes']['t6'], 'Luna') !== false && strpos($luna['opcoes']['t6'], '{{') === false);
confere('escolha: o texto sugerido é uma das opções', $luna['texto'], $luna['opcoes'][$luna['passo']]);
$r = atd_manual_acao($pdo, ['chave' => 'a12', 'acao' => 'enviada', 'passo' => 't6', 'passo_sugerido' => $luna['passo'], 'telefone' => '5545991110013'], 1, 'Luiz', $agora);
confere('escolha: enviar outra mensagem que não a sugerida é aceito', true, $r['ok']);
$reg = atd_manual_registros($pdo, ['a12'])['a12']['passos'];
confere('escolha: fica gravado o que foi enviado e a sugerida vira "substituída"', ['enviada', 'substituida', $luna['passo']], [$reg['t6']['estado'], $reg[$luna['passo']]['estado'], $reg[$luna['passo']]['por_passo'] === 't6' ? $luna['passo'] : '']);
$fe2 = atd_manual_fila($pdo, $agora);
confere('escolha: a pessoa sai da fila e vai para aguardando com a mensagem escolhida', [null, 'Convite depois do teste'], [item($fe2, 'Luna Escolha'), (array_values(array_filter($fe2['aguardando'], fn($x) => $x['nome'] === 'Luna Escolha'))[0]['mensagem_nome'] ?? null)]);
$plac = array_values(array_filter($fe2['placar'], fn($x) => $x['passo'] === 't6'))[0] ?? null;
confere('escolha: o placar conta só a enviada, a substituída não entra', [1, true], [$plac['enviadas'] ?? 0, !in_array($luna['passo'], array_column(array_filter($fe2['placar'], fn($x) => $x['passo'] === $luna['passo'] && $luna['passo'] !== 't6'), 'passo'), true)]);
confere('escolha: mensagem que não existe é recusada', false, atd_manual_acao($pdo, ['chave' => 'a12', 'acao' => 'enviada', 'passo' => 'inventado:z9', 'passo_sugerido' => 't1'], 1, 'Luiz', $agora)['ok']);
// passo pulado e depois enviado de verdade: vale (antes ficava preso como "já tratado")
atd_manual_acao($pdo, ['chave' => 'a12', 'acao' => 'pular', 'passo' => 't4'], 1, 'Luiz', $agora);
$r = atd_manual_acao($pdo, ['chave' => 'a12', 'acao' => 'enviada', 'passo' => 't4', 'telefone' => '5545991110013'], 1, 'Luiz', $agora);
confere('escolha: um passo que foi pulado pode ser enviado depois', ['enviada', false], [atd_manual_registros($pdo, ['a12'])['a12']['passos']['t4']['estado'], $r['ja']]);
$r = atd_manual_acao($pdo, ['chave' => 'a12', 'acao' => 'enviada', 'passo' => 't4', 'telefone' => '5545991110013'], 1, 'Luiz', $agora);
confere('escolha: repetir o toque no que já foi enviado não duplica', true, $r['ja']);
$r = atd_manual_acao($pdo, ['chave' => 'a12', 'acao' => 'pular', 'passo' => 't4'], 1, 'Luiz', $agora);
confere('escolha: pular o que já foi enviado não desfaz o envio', ['enviada', true], [atd_manual_registros($pdo, ['a12'])['a12']['passos']['t4']['estado'], $r['ja']]);
$htmlEsc = file_get_contents(__DIR__ . '/../atendimento/index.html');
verdade('escolha: a tela tem o seletor de mensagem e manda a sugerida junto', strpos($htmlEsc, 'data-fila-msg') !== false && strpos($htmlEsc, 'passo_sugerido') !== false);

// ── Recuperação: teste terminado há 5 dias ou mais, sem nenhuma mensagem enviada ─────────
conta($pdo, 13, 'Rita Recup', '(45) 99111-0014', '2026-10-08 09:00:00');
mat($pdo, 13, 'Teste grátis', '2026-10-08', '2026-10-11');                       // o teste acabou há 9 dias
conta($pdo, 14, 'Dora Recente', '(45) 99111-0015', '2026-10-14 09:00:00');
mat($pdo, 14, 'Teste grátis', '2026-10-14', '2026-10-17');                       // acabou há 3 dias: ainda é o funil normal
conta($pdo, 15, 'Cida Contatada', '(45) 99111-0016', '2026-10-08 09:00:00');
mat($pdo, 15, 'Teste grátis', '2026-10-08', '2026-10-11');                       // acabou há 9 dias, mas já falaram com ela
trContatoRegistrar($pdo, ['chave' => 'a15', 'status' => 'chamado', 'canal' => 'ligacao', 'telefone' => '5545991110016'], 1, 'Luiz', $agora->getTimestamp());
conta($pdo, 16, 'Vera Treinou', '(45) 99111-0017', '2026-10-08 09:00:00');
mat($pdo, 16, 'Teste grátis', '2026-10-08', '2026-10-11');
treino($pdo, 16, '2026-10-09');
$fr = atd_manual_fila($pdo, $agora);
confere('recuperação: quem fez o teste há 9 dias e não recebeu nada entra com a primeira mensagem', ['recuperacao-teste:rec1', 'recuperacao-teste', 9], [item($fr, 'Rita Recup')['passo'] ?? null, item($fr, 'Rita Recup')['funil'] ?? null, item($fr, 'Rita Recup')['recuperacao']['dias'] ?? null]);
confere('recuperação: o texto sai com o nome e sem variável solta', true, strpos(item($fr, 'Rita Recup')['texto'], 'Oi Rita, aqui é da Intus.') === 0 && strpos(item($fr, 'Rita Recup')['texto'], '{{') === false);
confere('recuperação: teste que acabou há menos de 5 dias segue o funil normal', [true, null], [strpos((string)(item($fr, 'Dora Recente')['passo'] ?? 'x'), 'recuperacao') === false, item($fr, 'Dora Recente')['recuperacao'] ?? null]);
confere('recuperação: quem já foi contatado por fora não entra', null, item($fr, 'Cida Contatada')['recuperacao'] ?? null);
confere('recuperação: quem treinou também entra (a equipe troca para a alternativa de quem treinou)', 'recuperacao-teste:rec1', item($fr, 'Vera Treinou')['passo'] ?? null);
confere('recuperação: as alternativas estão no seletor', true, isset(item($fr, 'Vera Treinou')['opcoes']['recuperacao-teste:rec-treinou'], item($fr, 'Vera Treinou')['opcoes']['recuperacao-teste:rec-parado'], item($fr, 'Vera Treinou')['opcoes']['recuperacao-teste:rec-premium']));
confere('recuperação: o resumo conta quem está nela', 2, $fr['resumo']['em_recuperacao']);
// enviar a primeira: a pessoa sai da fila e a segunda só vence depois do intervalo do funil (3 dias e 8 horas)
$r = atd_manual_acao($pdo, ['chave' => 'a13', 'acao' => 'enviada', 'passo' => 'recuperacao-teste:rec1', 'passo_sugerido' => 'recuperacao-teste:rec1', 'telefone' => '5545991110014'], 1, 'Luiz', $agora);
confere('recuperação: marcar a primeira como enviada', true, $r['ok']);
confere('recuperação: depois de enviada ela sai da fila', null, item(atd_manual_fila($pdo, $agora), 'Rita Recup'));
confere('recuperação: a segunda não vence antes do intervalo', null, item(atd_manual_fila($pdo, em('2026-10-23 19:00:00')), 'Rita Recup'));
confere('recuperação: a segunda vence depois do intervalo, mesmo com contato registrado pela primeira', 'recuperacao-teste:rec2', item(atd_manual_fila($pdo, em('2026-10-23 20:30:00')), 'Rita Recup')['passo'] ?? null);
confere('recuperação: a segunda continua valendo dias depois (não expira como o funil normal)', 'recuperacao-teste:rec2', item(atd_manual_fila($pdo, em('2026-11-05 12:00:00')), 'Rita Recup')['passo'] ?? null);
atd_manual_acao($pdo, ['chave' => 'a13', 'acao' => 'enviada', 'passo' => 'recuperacao-teste:rec2', 'passo_sugerido' => 'recuperacao-teste:rec2', 'telefone' => '5545991110014'], 1, 'Luiz', em('2026-10-23 20:40:00'));
confere('recuperação: a despedida espera o intervalo seguinte', [null, 'recuperacao-teste:rec3'], [item(atd_manual_fila($pdo, em('2026-10-26 12:00:00')), 'Rita Recup'), item(atd_manual_fila($pdo, em('2026-10-28 13:00:00')), 'Rita Recup')['passo'] ?? null]);
atd_manual_acao($pdo, ['chave' => 'a13', 'acao' => 'enviada', 'passo' => 'recuperacao-teste:rec3', 'passo_sugerido' => 'recuperacao-teste:rec3', 'telefone' => '5545991110014'], 1, 'Luiz', em('2026-10-28 12:10:00'));
confere('recuperação: terminada a sequência a pessoa não volta', null, item(atd_manual_fila($pdo, em('2026-12-01 12:00:00')), 'Rita Recup'));
conta($pdo, 17, 'Nina Ja Enviada', '(45) 99111-0018', '2026-10-08 09:00:00');
mat($pdo, 17, 'Teste grátis', '2026-10-08', '2026-10-11');
atd_manual_registro_gravar($pdo, 'a17', ['passos' => ['t6' => ['estado' => 'enviada', 'em' => '2026-10-12 10:00:00', 'por' => 'Luiz']]]);   // recebeu a t6 pela fila, sem contato registrado
confere('recuperação: quem já recebeu qualquer mensagem pela fila não entra', null, item(atd_manual_fila($pdo, $agora), 'Nina Ja Enviada')['recuperacao'] ?? null);
// a alternativa escolhida resolve a primeira, e quem respondeu sai da sequência
atd_manual_acao($pdo, ['chave' => 'a16', 'acao' => 'enviada', 'passo' => 'recuperacao-teste:rec-treinou', 'passo_sugerido' => 'recuperacao-teste:rec1', 'telefone' => '5545991110017'], 1, 'Luiz', $agora);
confere('recuperação: a alternativa enviada resolve a primeira e a segunda ainda espera', null, item(atd_manual_fila($pdo, em('2026-10-21 12:00:00')), 'Vera Treinou'));
atd_manual_acao($pdo, ['chave' => 'a16', 'acao' => 'respondeu', 'telefone' => '5545991110017'], 1, 'Luiz', $agora);
confere('recuperação: quem respondeu não recebe a segunda', null, item(atd_manual_fila($pdo, em('2026-10-30 12:00:00')), 'Vera Treinou'));
// funil novo aparece para quem já salvou os funis antes de ele existir
$pdoV = banco();
atd_cfg_gravar($pdoV, 'atd_funis', array_values(array_filter(atd_funis_padrao(), fn($f) => $f['id'] !== 'recuperacao-teste')));
confere('funil novo de fábrica aparece mesmo com funis já salvos', true, atd_funil_por_id($pdoV, 'recuperacao-teste') !== null);
atd_funil_excluir($pdoV, 'recuperacao-teste');
confere('funil novo excluído pela pessoa não volta sozinho', null, atd_funil_por_id($pdoV, 'recuperacao-teste'));
verdade('textos da recuperação passam no analisador sem erro', (function () { foreach (atd_funis_padrao() as $f) { if ($f['id'] !== 'recuperacao-teste') continue; foreach ($f['mensagens'] as $m) { foreach (atd_analisar_copy($m['texto'], ['template' => $m['categoria'] !== 'texto livre', 'ultima' => $m['id'] === 'rec3'])['itens'] as $i) { if ($i['nivel'] === 'erro') return false; } } return true; } return false; })());

// ── Funil editado vale na hora; funil sem texto não vira mensagem em branco ─
$ed = atd_funil_por_id($pdo, 'teste-gratis');
$ed['mensagens'][0]['texto'] = 'Texto novo para {{1}}.';
atd_funil_salvar($pdo, $ed);
confere('edição do funil aparece na fila', 'Texto novo para Ana.', item(atd_manual_fila($pdo, em('2026-10-20 12:00:00')), 'Ana Souza')['texto'] ?? 'Texto novo para Ana.');
$ed['mensagens'][0]['texto'] = '';
atd_funil_salvar($pdo, $ed);
verdade('mensagem sem texto não aparece como item vazio', array_filter(atd_manual_fila($pdo, $agora)['para_enviar'], fn($i) => trim($i['texto']) === '') === []);

// ── Estrutura: nada fala com a Meta ─────────────────────────────────────────
$lib = file_get_contents(__DIR__ . '/../app/api/_atd_manual.php');
$ep = file_get_contents(__DIR__ . '/../app/api/atendimento.php');
verdade('biblioteca: não chama a Meta nem faz rede', !preg_match('/wa_enviar|wa_http|waf_enviar|waf_processar|curl_|file_get_contents\(\s*[\'"]http/i', $lib));
verdade('biblioteca: SQL portável (sem NOW, sem ON DUPLICATE KEY)', !preg_match('/\bNOW\(\)|ON DUPLICATE KEY|INSERT IGNORE/i', $lib));
verdade('biblioteca: a configuração da automação da Meta só é LIDA, nunca gravada', strpos($lib, "atd_cfg_gravar(\$pdo, 'atd_automacao'") === false);
verdade('endpoint: as ações do atendimento manual vêm depois da checagem de administrador', strpos($ep, "case 'manual_estado'") > strpos($ep, "empty(\$ctx['admin'])") && strpos($ep, "case 'manual_acao'") > strpos($ep, "empty(\$ctx['admin'])"));
verdade('endpoint: as ações que gravam são só POST', (bool)preg_match("/\\\$soPost = \\[[^\\]]*'manual_config'[^\\]]*'manual_acao'/s", $ep));
$pagina = __DIR__ . '/../atendimento/index.html';
if (is_file($pagina)) {
    $h = file_get_contents($pagina);
    verdade('página: aba Fila de hoje', strpos($h, 'data-aba="fila"') !== false && strpos($h, 'manual_estado') !== false);
    verdade('página: não promete envio automático nesta aba', strpos($h, 'Nada é enviado automaticamente') !== false);
}

echo "\n";
if ($falhas) {
    echo count($falhas) . " FALHA(S) de " . ($ok + count($falhas)) . " conferencias:\n";
    foreach ($falhas as $x) echo "  - $x\n";
    exit(1);
}
echo "OK: $ok conferencias passaram.\n";
