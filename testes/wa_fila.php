<?php
/**
 * Laboratório do WhatsApp (app/api/_wa_fila.php, wa_teste.php, wa_cron.php), em SQLite na memória e com a Meta de mentira.
 *   php -c <ini com pdo_sqlite> testes/wa_fila.php
 * Nunca fala com a Meta de verdade e nunca precisa de token.
 */
require_once __DIR__ . '/../app/api/_wa_fila.php';
require_once __DIR__ . '/../app/api/_atendimento_conteudo.php';

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
    $pdo->exec('CREATE TABLE intus_sessao (idsessao INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, dtsessao TEXT, nao_contar INT DEFAULT 0)');
    return $pdo;
}
/** Meta de mentira: guarda o que recebeu e responde como configurado. */
class MetaFalsa {
    public array $pedidos = []; public string $modo = 'ok'; private int $n = 0;
    public function __invoke(string $url, array $corpo, string $token): array {
        $this->pedidos[] = ['url' => $url, 'corpo' => $corpo, 'token' => $token];
        if ($this->modo === 'ok') return ['status' => 200, 'json' => ['messages' => [['id' => 'wamid.T' . (++$this->n)]]], 'erro' => ''];
        if ($this->modo === 'template') return ['status' => 400, 'json' => ['error' => ['message' => '(#132001) Template name does not exist in the translation']], 'erro' => ''];
        if ($this->modo === 'rede') return ['status' => 0, 'json' => null, 'erro' => 'timeout'];
        return ['status' => 401, 'json' => ['error' => ['message' => 'Invalid OAuth access token']], 'erro' => ''];
    }
}
$CFG = ['phone_number_id' => '111', 'access_token' => 'TOKEN-FALSO', 'api_version' => 'v23.0', 'verify_token' => 'v', 'app_secret' => 's'];
$agora = em('2026-10-21 12:00:00');   // quarta, meio-dia
$funil = atd_funil_por_id(banco(), 'teste-gratis');
$t1 = waf_achar_mensagem($funil, 't1'); $t2 = waf_achar_mensagem($funil, 't2');
$msgs = fn($pdo, $id) => waf_mensagens($pdo, $id);

echo "\n══ Contato de teste ══\n";
$pdo = banco();
eq('antes de criar, não existe', waf_contato_teste_existente($pdo), null);
$c = waf_contato_teste($pdo, $agora);
eq('telefone normalizado com DDI e 9', $c['telefone'], '5545999574107');
eq('nome Fulano Teste', $c['nome'], 'Fulano Teste');
eq('consentimento registrado como teste-admin', $c['optin_origem'], 'teste-admin');
eq('com data de consentimento', !empty($c['optin_em']), true);
$c2 = waf_contato_teste($pdo, em('2026-10-22 09:00:00'));
eq('criar de novo não duplica nem muda o consentimento', [$c2['idcontato'], $c2['optin_em']], [$c['idcontato'], $c['optin_em']]);
eq('só existe um contato', (int)$pdo->query('SELECT COUNT(*) FROM intus_wa_contato')->fetchColumn(), 1);
// contato que já existia como lead real com o mesmo telefone vira de teste sem perder o histórico
$pdoB = banco();
wa_contato_obter_ou_criar($pdoB, '5545999574107', null, $agora);
$cb = waf_contato_teste($pdoB, $agora);
eq('contato já existente é aproveitado (nome preenchido)', [$cb['nome'], waf_eh_teste($cb)], ['Fulano Teste', true]);

echo "\n══ Chave geral e modo por passo ══\n";
eq('nasce desligada', waf_config($pdo)['mestre'], false);
eq('todo passo nasce manual', waf_modo_passo(waf_config($pdo), 'teste-gratis', 't1'), 'manual');
waf_config_gravar($pdo, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto', 'teste-gratis/t2' => 'manual', 'lixo!!' => 'auto', 'teste-gratis/t3' => 'qualquer']]);
$cf = waf_config($pdo);
eq('liga a chave geral', $cf['mestre'], true);
eq('só "auto" válido é guardado', $cf['passos'], ['teste-gratis/t1' => 'auto']);
eq('t1 auto, t2 manual', [waf_modo_passo($cf, 'teste-gratis', 't1'), waf_modo_passo($cf, 'teste-gratis', 't2')], ['auto', 'manual']);
waf_config_gravar($pdo, ['mestre' => false]);
eq('desligar a chave geral não perde a escolha por passo', [waf_config($pdo)['mestre'], waf_config($pdo)['passos']], [false, ['teste-gratis/t1' => 'auto']]);
waf_config_gravar($pdo, ['mestre' => false, 'passos' => []]);

echo "\n══ Envio manual de uma mensagem do funil ══\n";
$meta = new MetaFalsa();
$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'auto', true, $meta, $agora);
eq('t1 manual por template: ok', [$r['ok'], $r['via']], [true, 'template']);
$p = $meta->pedidos[0]['corpo'];
eq('vai para o número de teste', $p['to'], '5545999574107');
eq('é um template com o nome cadastrado e idioma pt_BR', [$p['type'], $p['template']['name'], $p['template']['language']['code']], ['template', 'intus_t1_boas_vindas', 'pt_BR']);
eq('parâmetros na ordem {{1}} nome e {{2}} link', array_column($p['template']['components'][0]['parameters'], 'text'), ['Fulano', ATD_LINK_APP]);
eq('o token vai no cabeçalho, nunca no corpo', [$meta->pedidos[0]['token'], strpos(json_encode($p), 'TOKEN-FALSO')], ['TOKEN-FALSO', false]);
$h = $msgs($pdo, (int)$c['idcontato']);
eq('fica no histórico como enviada, com o texto que a pessoa lê', [$h[0]['status'], $h[0]['direcao'], strpos($h[0]['texto'], 'Oi Fulano, tudo bem?') === 0], ['enviada', 'S', true]);
eq('guarda o id da Meta para casar com o status de entrega', $h[0]['wa_id'], 'wamid.T1');
eq('o rótulo diz "Aceita pela Meta" (ainda não é entrega)', $h[0]['rotulo'], 'Aceita pela Meta');
eq('registra a mensagem proativa no contato', !empty(wa_contato_por_id($pdo, (int)$c['idcontato'])['ultima_proativa_em']), true);

$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t2, 'auto', true, $meta, $agora);
eq('segundo envio no mesmo dia: manual ignora o limite diário', $r['ok'], true);
$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'auto', true, $meta, em('2026-10-21 23:30:00'));
eq('manual ignora o horário comercial', $r['ok'], true);

$meta->modo = 'template';
$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'template', true, $meta, $agora);
eq('template recusado pela Meta: mostra o erro da Meta', [$r['ok'], strpos((string)$r['erro'], '132001') !== false], [false, true]);
$h = $msgs($pdo, (int)$c['idcontato']);
eq('a falha fica no histórico com o erro', [end($h)['status'], strpos((string)end($h)['erro'], 'Template') !== false], ['falhou', true]);
$meta->modo = 'rede';
$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'template', true, $meta, $agora);
eq('falha de rede é explicada', strpos((string)$r['erro'], 'timeout') !== false, true);
$meta->modo = 'token';
$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'template', true, $meta, $agora);
eq('token inválido é explicado e não vaza o token', [$r['ok'], strpos(json_encode($r), 'TOKEN-FALSO'), strpos((string)$r['erro'], 'Invalid OAuth') !== false], [false, false, true]);
$meta->modo = 'ok';

echo "\n══ Texto livre e janela de 24 h ══\n";
$antes = count($meta->pedidos);
$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'texto', true, $meta, $agora);
eq('texto fora da janela é recusado antes de ir à Meta', [$r['ok'], $r['motivo'], count($meta->pedidos)], [false, 'fora_da_janela', $antes]);
$r = waf_enviar_texto_livre($pdo, $CFG, $c, 'oi', $meta, $agora);
eq('texto avulso fora da janela também', [$r['ok'], $r['motivo']], [false, 'fora_da_janela']);
wa_contato_atualizar($pdo, (int)$c['idcontato'], ['ultima_entrada_em' => wa_fmt(em('2026-10-21 11:30:00'))], $agora);
$r = waf_enviar_texto_livre($pdo, $CFG, $c, 'Oi, Fulano! Chegou aí?', $meta, $agora);
eq('com a janela aberta o texto livre sai', $r['ok'], true);
eq('o corpo é texto simples', end($meta->pedidos)['corpo']['type'], 'text');
$r = waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'texto', true, $meta, $agora);
eq('mensagem do funil por texto livre dentro da janela', [$r['ok'], $r['via']], [true, 'texto']);
eq('texto vazio é recusado', waf_enviar_texto_livre($pdo, $CFG, $c, '   ', $meta, $agora)['motivo'], 'sem_texto');

echo "\n══ Quem pediu para sair nunca recebe ══\n";
wa_contato_atualizar($pdo, (int)$c['idcontato'], ['saiu_em' => wa_fmt($agora)], $agora);
$antes = count($meta->pedidos);
foreach ([['manual forçado', fn() => waf_enviar_mensagem($pdo, $CFG, $c, $funil, $t1, 'auto', true, $meta, $agora)],
          ['texto livre', fn() => waf_enviar_texto_livre($pdo, $CFG, $c, 'oi', $meta, $agora)],
          ['hello_world', fn() => waf_enviar_hello($pdo, $CFG, $c, $meta, $agora)]] as [$rot, $fn]) {
    $r = $fn();
    eq("$rot: recusado por 'saiu'", [$r['ok'], $r['motivo']], [false, 'saiu']);
}
eq('nada foi à Meta', count($meta->pedidos), $antes);

echo "\n══ Reiniciar o teste ══\n";
waf_agendar($pdo, $c, $funil, $agora, 5);
$n = waf_reiniciar_teste($pdo, $agora);
$c = wa_contato_por_id($pdo, (int)$c['idcontato']);
eq('apaga a agenda do contato de teste', [$n['agenda_apagada'], count(waf_agenda_do_contato($pdo, (int)$c['idcontato']))], [7, 0]);
eq('libera o "saiu" e o robô', [$c['saiu_em'], $c['modo']], [null, 'robo']);
eq('o histórico de mensagens fica', count($msgs($pdo, (int)$c['idcontato'])) > 0, true);
$pdoX = banco();
try { waf_reiniciar_teste($pdoX); $lancou = false; } catch (InvalidArgumentException $e) { $lancou = true; }
eq('sem contato de teste, reiniciar recusa', $lancou, true);
$real = wa_contato_obter_ou_criar($pdoX, '5511988887777', 'Maria Real', $agora);
$pdoX->prepare("INSERT INTO intus_wa_agenda (idcontato, passo, enviar_em) VALUES (?, 't1', ?)")->execute([(int)$real['idcontato'], wa_fmt($agora)]);
try { waf_reiniciar_teste($pdoX); } catch (InvalidArgumentException $e) {}
eq('a agenda de um lead real nunca é tocada por reiniciar', (int)$pdoX->query('SELECT COUNT(*) FROM intus_wa_agenda')->fetchColumn(), 1);

echo "\n══ Agenda ══\n";
$pdo = banco(); $c = waf_contato_teste($pdo, $agora);
$a = waf_agendar($pdo, $c, $funil, $agora, 10);
eq('teste rápido cria os 7 passos', [$a['criados'], $a['ja_existiam']], [7, 0]);
$ag = waf_agenda_do_contato($pdo, (int)$c['idcontato']);
eq('o 1º sai agora e os outros a cada 10 minutos', [$ag[0]['passo'], $ag[0]['enviar_em'], $ag[1]['enviar_em'], $ag[6]['enviar_em']], ['t1', '2026-10-21 12:00:00', '2026-10-21 12:10:00', '2026-10-21 13:00:00']);
eq('no teste rápido a condição não trava ninguém', array_unique(array_column($ag, 'condicao')), ['todos']);
$a = waf_agendar($pdo, $c, $funil, $agora, 10);
eq('agendar de novo não repete passo', [$a['criados'], $a['ja_existiam']], [0, 7]);
$pdoR = banco(); $cR = waf_contato_teste($pdoR, $agora);
waf_agendar($pdoR, $cR, $funil, $agora, 0);
$agR = waf_agenda_do_contato($pdoR, (int)$cR['idcontato']);
eq('agenda de verdade: t1 agora, t2 amanhã às 10h, t5 no 3º dia às 9h', [$agR[0]['enviar_em'], $agR[1]['enviar_em'], $agR[4]['enviar_em']], ['2026-10-21 12:00:00', '2026-10-22 10:00:00', '2026-10-24 09:00:00']);
eq('agenda de verdade guarda as condições do funil', array_column($agR, 'condicao'), ['todos', 'sem_treino', 'com_treino', 'todos', 'todos', 'sem_resposta', 'sem_resposta']);
eq('cancelar só mexe no que está pendente', [waf_agenda_cancelar($pdoR, (int)$cR['idcontato']), waf_agenda_cancelar($pdoR, (int)$cR['idcontato'])], [7, 0]);

echo "\n══ Processador ══\n";
// 1. chave geral desligada: nada sai
$pdo = banco(); $c = waf_contato_teste($pdo, $agora); waf_agendar($pdo, $c, $funil, $agora, 10);
waf_config_gravar($pdo, ['mestre' => false, 'passos' => ['teste-gratis/t1' => 'auto']]);
$meta = new MetaFalsa();
$r = waf_processar($pdo, $CFG, $agora, $meta);
eq('chave geral desligada: não envia nada, mesmo com passo em auto', [$r['mestre'], $r['enviadas'], count($meta->pedidos)], [false, 0, 0]);
// 2. ligada, t1 auto e t2 manual
waf_config_gravar($pdo, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto']]);
$r = waf_processar($pdo, $CFG, em('2026-10-21 12:15:00'), $meta);
eq('t1 (auto) sai; t2 (manual, também vencido) espera o clique', [$r['enviadas'], count($meta->pedidos), $r['manuais_vencidas']], [1, 1, 1]);
$ag = array_column(waf_agenda_do_contato($pdo, (int)$c['idcontato']), 'status', 'passo');
eq('t1 enviada, t2 continua pendente', [$ag['t1'], $ag['t2']], ['enviada', 'pendente']);
$r = waf_processar($pdo, $CFG, em('2026-10-21 12:16:00'), $meta);
eq('rodar de novo não repete o envio', [$r['enviadas'], count($meta->pedidos)], [0, 1]);
// 3. todos auto: avança conforme o relógio
waf_config_gravar($pdo, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto', 'teste-gratis/t2' => 'auto', 'teste-gratis/t3' => 'auto', 'teste-gratis/t4' => 'auto', 'teste-gratis/t5' => 'auto', 'teste-gratis/t6' => 'auto', 'teste-gratis/t7' => 'auto']]);
$r = waf_processar($pdo, $CFG, em('2026-10-21 12:35:00'), $meta);
eq('às 12:35 saem t2 e t3 (12:10 e 12:20) e t4 (12:30)', $r['enviadas'], 3);
$r = waf_processar($pdo, $CFG, em('2026-10-21 14:00:00'), $meta);
eq('às 14:00 saem os 3 que faltam', $r['enviadas'], 3);
eq('no total 7 mensagens, uma de cada passo', array_column(waf_agenda_do_contato($pdo, (int)$c['idcontato']), 'status', 'passo'), array_fill_keys(['t1', 't2', 't3', 't4', 't5', 't6', 't7'], 'enviada'));
// 4. contato de teste ignora horário
$pdoN = banco(); $cN = waf_contato_teste($pdoN, em('2026-10-21 23:00:00')); waf_agendar($pdoN, $cN, $funil, em('2026-10-21 23:00:00'), 10);
waf_config_gravar($pdoN, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto']]);
$metaN = new MetaFalsa();
eq('contato de teste recebe à noite (ignora horário comercial)', waf_processar($pdoN, $CFG, em('2026-10-21 23:05:00'), $metaN)['enviadas'], 1);
// 5. falha da Meta marca "falhou" e não trava a fila
$pdoF = banco(); $cF = waf_contato_teste($pdoF, $agora); waf_agendar($pdoF, $cF, $funil, $agora, 10);
waf_config_gravar($pdoF, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto', 'teste-gratis/t2' => 'auto']]);
$metaF = new MetaFalsa(); $metaF->modo = 'template';
$r = waf_processar($pdoF, $CFG, em('2026-10-21 12:15:00'), $metaF);
$agF = array_column(waf_agenda_do_contato($pdoF, (int)$cF['idcontato']), null, 'passo');
eq('Meta recusou: t1 e t2 ficam "falhou" com o motivo', [$agF['t1']['status'], $agF['t2']['status'], strpos((string)$agF['t1']['motivo'], '132001') !== false], ['falhou', 'falhou', true]);
// 6. sem WhatsApp configurado
$pdoS = banco(); $cS = waf_contato_teste($pdoS, $agora); waf_agendar($pdoS, $cS, $funil, $agora, 10);
waf_config_gravar($pdoS, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto']]);
$r = waf_processar($pdoS, null, em('2026-10-21 12:15:00'), new MetaFalsa());
eq('sem WhatsApp configurado: não envia e avisa', [$r['enviadas'], $r['erro'] ?? null], [0, 'WhatsApp não configurado.']);
// 7. pessoa do teste que pediu para sair: a agenda é cancelada
$pdoQ = banco(); $cQ = waf_contato_teste($pdoQ, $agora); waf_agendar($pdoQ, $cQ, $funil, $agora, 10);
waf_config_gravar($pdoQ, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto']]);
wa_contato_atualizar($pdoQ, (int)$cQ['idcontato'], ['saiu_em' => wa_fmt($agora)], $agora);
$metaQ = new MetaFalsa();
waf_processar($pdoQ, $CFG, em('2026-10-21 12:15:00'), $metaQ);
eq('quem saiu: nada enviado e o passo é cancelado', [count($metaQ->pedidos), array_column(waf_agenda_do_contato($pdoQ, (int)$cQ['idcontato']), 'status', 'passo')['t1']], [0, 'cancelada']);
// 8. reserva: linha já "enviando" não é pega de novo
$pdoD = banco(); $cD = waf_contato_teste($pdoD, $agora); waf_agendar($pdoD, $cD, $funil, $agora, 10);
waf_config_gravar($pdoD, ['mestre' => true, 'passos' => ['teste-gratis/t1' => 'auto']]);
$pdoD->exec("UPDATE intus_wa_agenda SET status = 'enviando' WHERE passo = 't1'");
$metaD = new MetaFalsa();
eq('linha reservada por outra execução não é enviada em duplicidade', [waf_processar($pdoD, $CFG, em('2026-10-21 12:15:00'), $metaD)['enviadas'], count($metaD->pedidos)], [0, 0]);

echo "\n══ Lead de verdade: as regras de sempre valem ══\n";
function leadReal(PDO $pdo, array $campos, DateTimeImmutable $agora): array {
    $c = wa_contato_obter_ou_criar($pdo, '5511988887777', 'Maria Souza', $agora);
    wa_contato_atualizar($pdo, (int)$c['idcontato'], $campos, $agora);
    return wa_contato_por_id($pdo, (int)$c['idcontato']);
}
function prepararReal(array $campos, string $passo = 't1', string $cond = 'todos', array $passosAuto = ['teste-gratis/t1', 'teste-gratis/t2', 'teste-gratis/t3', 'teste-gratis/t6'], ?callable $antes = null): array {
    global $agora, $CFG;
    $pdo = banco();
    $c = leadReal($pdo, $campos, $agora);
    $pdo->prepare("INSERT INTO intus_wa_agenda (idcontato, passo, enviar_em, condicao) VALUES (?, ?, ?, ?)")->execute([(int)$c['idcontato'], $passo, wa_fmt(em('2026-10-21 07:00:00')), $cond]);
    waf_config_gravar($pdo, ['mestre' => true, 'passos' => array_fill_keys($passosAuto, 'auto')]);
    if ($antes) $antes($pdo, $c);
    return [$pdo, $c];
}
$opt = ['optin_em' => '2026-10-20 10:00:00', 'optin_origem' => 'landing'];
[$pdoL, $cL] = prepararReal($opt); $m = new MetaFalsa();
$r = waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
eq('lead com consentimento, em horário: sai por template', [$r['enviadas'], $m->pedidos[0]['corpo']['type'] ?? null], [1, 'template']);
[$pdoL, $cL] = prepararReal($opt); $m = new MetaFalsa();
$r = waf_processar($pdoL, $CFG, em('2026-10-21 07:30:00'), $m);
$st = waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0];
eq('às 7h30 (antes das 8h): nada sai e o passo continua pendente (tenta depois)', [count($m->pedidos), $st['status'], $st['motivo']], [0, 'pendente', 'fora_do_horario']);
// agenda vence 07:00; processa às 22h
[$pdoL, $cL] = prepararReal($opt); $m = new MetaFalsa();
waf_processar($pdoL, $CFG, em('2026-10-21 22:00:00'), $m);
eq('depois das 21h: nada sai', count($m->pedidos), 0);
[$pdoL, $cL] = prepararReal([]); $m = new MetaFalsa();
waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
$st = waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0];
eq('sem consentimento: nada sai e o passo é cancelado', [count($m->pedidos), $st['status'], $st['motivo']], [0, 'cancelada', 'sem_consentimento']);
[$pdoL, $cL] = prepararReal($opt + ['saiu_em' => '2026-10-21 09:00:00']); $m = new MetaFalsa();
waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
eq('quem pediu para sair: cancelado', [count($m->pedidos), waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0]['motivo']], [0, 'saiu']);
[$pdoL, $cL] = prepararReal($opt + ['modo' => 'humano']); $m = new MetaFalsa();
waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
eq('conversa assumida por uma pessoa: o automático para', [count($m->pedidos), waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0]['status']], [0, 'cancelada']);
[$pdoL, $cL] = prepararReal($opt + ['ultima_proativa_em' => '2026-10-21 09:00:00']); $m = new MetaFalsa();
waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
$st = waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0];
eq('já recebeu uma proativa hoje: espera o dia seguinte', [count($m->pedidos), $st['status'], $st['motivo']], [0, 'pendente', 'limite_diario']);
[$pdoL, $cL] = prepararReal($opt, 't2', 'sem_treino', ['teste-gratis/t2'], function ($pdo, $c) { wa_contato_atualizar($pdo, (int)$c['idcontato'], ['idatleta' => 9], em('2026-10-21 08:00:00')); $pdo->exec("INSERT INTO intus_sessao (idatleta, dtsessao) VALUES (9, '2026-10-21')"); });
$m = new MetaFalsa(); waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
$st = waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0];
eq('"sem treino" mas a pessoa já treinou: pulada', [count($m->pedidos), $st['status'], $st['motivo']], [0, 'pulada', 'condicao_sem_treino']);
[$pdoL, $cL] = prepararReal($opt, 't3', 'com_treino', ['teste-gratis/t3'], function ($pdo, $c) { wa_contato_atualizar($pdo, (int)$c['idcontato'], ['idatleta' => 9], em('2026-10-21 08:00:00')); $pdo->exec("INSERT INTO intus_sessao (idatleta, dtsessao) VALUES (9, '2026-10-21')"); });
$m = new MetaFalsa();
eq('"com treino" e treinou: envia', waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m)['enviadas'], 1);
[$pdoL, $cL] = prepararReal($opt, 't6', 'sem_resposta', ['teste-gratis/t6'], function ($pdo, $c) { wa_msg_registrar($pdo, ['idcontato' => (int)$c['idcontato'], 'direcao' => 'S', 'origem' => 'sequencia', 'texto' => 'oi', 'status' => 'enviada', 'wa_id' => 'w1', 'processada' => 1], em('2026-10-21 08:00:00')); wa_msg_registrar($pdo, ['idcontato' => (int)$c['idcontato'], 'direcao' => 'E', 'origem' => 'lead', 'texto' => 'quero saber mais', 'wa_id' => 'w2'], em('2026-10-21 09:00:00')); });
$m = new MetaFalsa(); waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
eq('"sem resposta" mas a pessoa respondeu: pulada', [count($m->pedidos), waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0]['status']], [0, 'pulada']);
// funil em rascunho não sai para lead real; template não aprovado também não
[$pdoL, $cL] = prepararReal($opt, 'chamou-whatsapp:c1', 'todos', ['chamou-whatsapp/c1']);
$m = new MetaFalsa(); waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
eq('funil em rascunho: lead real não recebe', [count($m->pedidos), waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0]['status']], [0, 'pendente']);
[$pdoL, $cL] = prepararReal($opt);
$f2 = $funil; foreach ($f2['mensagens'] as &$mm) { if ($mm['id'] === 't1') $mm['aprovada'] = false; } unset($mm);
atd_funil_salvar($pdoL, $f2);
$m = new MetaFalsa(); waf_processar($pdoL, $CFG, em('2026-10-21 12:00:00'), $m);
$st = waf_agenda_do_contato($pdoL, (int)$cL['idcontato'])[0];
eq('template não aprovado na Meta: não sai para lead real', [count($m->pedidos), $st['motivo']], [0, 'sem_template_aprovada']);

echo "\n══ Estado para a tela ══\n";
$pdoE = banco();
$e = waf_estado($pdoE, $agora);
eq('sem WhatsApp configurado a tela sabe', [$e['whatsapp_configurado'], $e['contato']], [false, null]);
eq('lista os funis com o modo de cada passo', [count($e['funis']) >= 4, $e['funis'][0]['mensagens'][0]['modo']], [true, 'manual']);
wa_segredo_gravar($pdoE, 'whatsapp_config', ['phone_number_id' => '1', 'access_token' => 'SEGREDO-NAO-VAZAR', 'verify_token' => 'v', 'app_secret' => 'ss']);
$cE = waf_contato_teste($pdoE, $agora);
$e = waf_estado($pdoE, $agora);
eq('com WhatsApp e webhook configurados', [$e['whatsapp_configurado'], $e['webhook_configurado']], [true, true]);
eq('o estado nunca carrega token nem segredo', [strpos(json_encode($e), 'SEGREDO-NAO-VAZAR'), strpos(json_encode($e), '"ss"')], [false, false]);
eq('mostra o contato de teste', [$e['contato']['telefone'], $e['telefone_teste']], ['5545999574107', '5545999574107']);

echo "\n══ Estrutura dos endpoints ══\n";
$wt = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/wa_teste.php'));
$wc = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/wa_cron.php'));
eq('wa_teste.php exige administrador (401 sem sessão, 403 sem admin)', strpos($wt, "empty(\$ctx['admin'])") !== false && strpos($wt, 'is_aluno') !== false && strpos($wt, '401') !== false && strpos($wt, '403') !== false, true);
eq('wa_teste.php não aceita telefone nem id de contato vindo da tela', preg_match('/\$d\[\'(telefone|idcontato|tel|whatsapp)\'\]/', $wt), 0);
eq('wa_teste.php: só "estado" aceita GET', strpos($wt, "if (\$action !== 'estado' && \$metodo !== 'POST')") !== false, true);
eq('reiniciar exige digitar REINICIAR', strpos($wt, "'REINICIAR'") !== false, true);
eq('wa_cron.php compara o token com hash_equals e exige o cabeçalho', strpos($wc, 'hash_equals') !== false && strpos($wc, 'HTTP_X_CRON_AUTH') !== false, true);
eq('wa_cron.php sem token cadastrado responde 503', strpos($wc, '503') !== false && strpos($wc, "\$esperado === ''") !== false, true);
eq('wa_cron.php não devolve telefone nem nome', preg_match('/telefone|nome/', substr($wc, strpos($wc, 'waf_processar'))), 0);
$cod = $wt . $wc . file_get_contents(__DIR__ . '/../app/api/_wa_fila.php');
eq('nenhum arquivo novo tem travessão nos textos', preg_match('/[—–]/u', $cod), 0);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
