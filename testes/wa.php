<?php
/**
 * Testes das regras do atendimento por WhatsApp (app/api/_wa.php).
 *
 * Rodar (precisa de PHP 8.2 com as extensoes mbstring, openssl, curl e pdo_sqlite):
 *     php testes/wa.php
 * No Windows, sem php.ini, passe um ini com as extensoes: php -c php-teste.ini testes/wa.php
 *
 * O banco dos testes e SQLite em memoria. A biblioteca usa SQL portavel de proposito
 * para que o mesmo fluxo rode aqui e no MySQL de producao.
 */

require_once __DIR__ . '/../app/api/_wa.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function em(string $s): DateTimeImmutable { return new DateTimeImmutable($s, wa_tz()); }
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    wa_garantir_tabelas($pdo);
    return $pdo;
}

// ── Telefone ────────────────────────────────────────────────────────────────
confere('tel: com DDI e 9', '5545991156006', wa_normalizar_telefone('5545991156006'));
confere('tel: formatado', '5545991156006', wa_normalizar_telefone('(45) 99115-6006'));
confere('tel: so DDD+numero', '5545991156006', wa_normalizar_telefone('45991156006'));
confere('tel: com +', '5545991156006', wa_normalizar_telefone('+55 45 99115-6006'));
confere('tel: celular antigo sem 9 vira com 9', '5545991156006', wa_normalizar_telefone('554591156006'));
confere('tel: fixo nao ganha 9', '554532231234', wa_normalizar_telefone('554532231234'));
confere('tel: zero de tronco', '5545991156006', wa_normalizar_telefone('045991156006'));
confere('tel: lixo', '', wa_normalizar_telefone('abc'));
confere('tel: curto demais', '', wa_normalizar_telefone('1234'));
confere('tel: vazio', '', wa_normalizar_telefone(''));

// ── Pedido de saida ─────────────────────────────────────────────────────────
foreach (['SAIR', 'sair', 'Sair.', ' parar ', 'PARE', 'Stop', 'cancelar', 'Não quero mais receber', 'nao quero receber mensagens',
          'quero sair', 'Parar de receber', 'por favor, parar de receber mensagens', 'descadastrar', 'Me tire da lista'] as $t) {
    confere("saida: '$t'", true, wa_eh_pedido_de_saida($t));
}
foreach (['oi', 'vou parar de treinar', 'quero sair do plano', 'sair de casa é difícil', 'quero cancelar meu treino de hoje', '', 'quanto custa?', 'não consegui sair do app'] as $t) {
    confere("nao-saida: '$t'", false, wa_eh_pedido_de_saida($t));
}

// ── Horario ─────────────────────────────────────────────────────────────────
confere('hora: 07:59 fora', false, wa_hora_permitida(em('2026-10-08 07:59:00')));
confere('hora: 08:00 dentro', true, wa_hora_permitida(em('2026-10-08 08:00:00')));
confere('hora: 20:59 dentro', true, wa_hora_permitida(em('2026-10-08 20:59:59')));
confere('hora: 21:00 fora', false, wa_hora_permitida(em('2026-10-08 21:00:00')));
confere('proximo: 23h vai para as 8h do dia seguinte', '2026-10-09 08:00:00', wa_fmt(wa_proximo_horario_valido(em('2026-10-08 23:10:00'))));
confere('proximo: 5h vai para as 8h do mesmo dia', '2026-10-08 08:00:00', wa_fmt(wa_proximo_horario_valido(em('2026-10-08 05:10:00'))));
confere('proximo: 14h fica', '2026-10-08 14:10:00', wa_fmt(wa_proximo_horario_valido(em('2026-10-08 14:10:00'))));
confere('hora: converte UTC para Sao Paulo', true, wa_hora_permitida(new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone('UTC')))); // 09h em SP

// ── Janela de 24h ───────────────────────────────────────────────────────────
confere('janela: nunca escreveu', false, wa_janela_aberta(null, em('2026-10-08 10:00:00')));
confere('janela: ha 23h59', true, wa_janela_aberta('2026-10-07 10:01:00', em('2026-10-08 10:00:00')));
confere('janela: ha 24h01', false, wa_janela_aberta('2026-10-07 09:59:00', em('2026-10-08 10:00:00')));

// ── Decisao de envio ────────────────────────────────────────────────────────
$base = ['saiu_em' => null, 'modo' => 'robo', 'optin_em' => '2026-10-08 09:00:00', 'ultima_entrada_em' => null, 'ultima_proativa_em' => null];
$tarde = em('2026-10-08 15:00:00');
confere('envio: template proativo ok', [true, 'ok'], wa_decidir_envio($base, $tarde, ['tipo' => 'template']));
confere('envio: saiu bloqueia tudo', [false, 'saiu'], wa_decidir_envio(['saiu_em' => '2026-10-08 10:00:00'] + $base, $tarde, ['tipo' => 'template']));
confere('envio: saiu bloqueia ate resposta', [false, 'saiu'], wa_decidir_envio(['saiu_em' => '2026-10-08 10:00:00', 'ultima_entrada_em' => '2026-10-08 14:00:00'] + $base, $tarde, ['tipo' => 'texto', 'proativa' => false]));
confere('envio: sem consentimento', [false, 'sem_consentimento'], wa_decidir_envio(['optin_em' => null] + $base, $tarde, ['tipo' => 'template']));
confere('envio: fora do horario', [false, 'fora_do_horario'], wa_decidir_envio($base, em('2026-10-08 22:00:00'), ['tipo' => 'template']));
confere('envio: limite diario', [false, 'limite_diario'], wa_decidir_envio(['ultima_proativa_em' => '2026-10-08 09:05:00'] + $base, $tarde, ['tipo' => 'template']));
confere('envio: ontem nao conta', [true, 'ok'], wa_decidir_envio(['ultima_proativa_em' => '2026-10-07 18:00:00'] + $base, $tarde, ['tipo' => 'template']));
confere('envio: texto fora da janela', [false, 'fora_da_janela'], wa_decidir_envio($base, $tarde, ['tipo' => 'texto', 'proativa' => false]));
confere('envio: texto dentro da janela, a noite, sem optin', [true, 'ok'], wa_decidir_envio(['optin_em' => null, 'ultima_entrada_em' => '2026-10-08 21:30:00'] + $base, em('2026-10-08 22:30:00'), ['tipo' => 'texto', 'proativa' => false]));
confere('envio: modo humano bloqueia automatico', [false, 'modo_humano'], wa_decidir_envio(['modo' => 'humano'] + $base, $tarde, ['tipo' => 'template']));
confere('envio: modo humano nao bloqueia envio manual', [true, 'ok'], wa_decidir_envio(['modo' => 'humano', 'ultima_entrada_em' => '2026-10-08 14:00:00'] + $base, $tarde, ['tipo' => 'texto', 'proativa' => false, 'automatica' => false]));

// ── Agenda do teste ─────────────────────────────────────────────────────────
$ag = wa_agenda_do_teste(em('2026-10-08 15:30:00'), 3);
$mapa = [];
foreach ($ag as [$p, $t, $c]) $mapa[$p] = [wa_fmt($t), $c];
confere('agenda: t1 sai na hora', ['2026-10-08 15:30:00', 'todos'], $mapa['t1']);
confere('agenda: t2 dia 1 10h', ['2026-10-09 10:00:00', 'sem_treino'], $mapa['t2']);
confere('agenda: t3 dia 1 20h', ['2026-10-09 20:00:00', 'com_treino'], $mapa['t3']);
confere('agenda: t4 dia 2 9h', ['2026-10-10 09:00:00', 'todos'], $mapa['t4']);
confere('agenda: t5 dia 3 9h', ['2026-10-11 09:00:00', 'todos'], $mapa['t5']);
confere('agenda: t6 dia 4 18h', ['2026-10-12 18:00:00', 'sem_resposta'], $mapa['t6']);
confere('agenda: t7 dia 8 18h', ['2026-10-16 18:00:00', 'sem_resposta'], $mapa['t7']);
confere('agenda: sao sete passos', 7, count($ag));
$ag2 = wa_agenda_do_teste(em('2026-10-08 23:40:00'), 3);
confere('agenda: cadastro de madrugada empurra t1 para as 8h', '2026-10-09 08:00:00', wa_fmt($ag2[0][1]));
$ag3 = wa_agenda_do_teste(em('2026-10-08 15:30:00'), 5);
confere('agenda: teste de 5 dias move o ultimo dia', '2026-10-13 09:00:00', wa_fmt($ag3[4][1]));
foreach ($ag as [$p, $t, $c]) {
    confere("agenda: $p dentro do horario", true, wa_hora_permitida($t));
}

// ── Assinatura da Meta ──────────────────────────────────────────────────────
$corpo = '{"entry":[]}';
$seg = 'segredo-de-teste-123456';
$boa = 'sha256=' . hash_hmac('sha256', $corpo, $seg);
confere('assinatura: valida', true, wa_assinatura_valida($corpo, $boa, $seg));
confere('assinatura: corpo adulterado', false, wa_assinatura_valida($corpo . ' ', $boa, $seg));
confere('assinatura: segredo errado', false, wa_assinatura_valida($corpo, $boa, 'outro-segredo-123456'));
confere('assinatura: sem prefixo', false, wa_assinatura_valida($corpo, hash_hmac('sha256', $corpo, $seg), $seg));
confere('assinatura: cabecalho vazio', false, wa_assinatura_valida($corpo, '', $seg));
confere('assinatura: segredo vazio', false, wa_assinatura_valida($corpo, $boa, ''));

// ── Fluxo do webhook (SQLite em memoria) ────────────────────────────────────
function entrada(string $de, string $id, string $texto, int $ts, string $nome = 'Maria'): array {
    return ['entry' => [['changes' => [['value' => [
        'contacts' => [['wa_id' => $de, 'profile' => ['name' => $nome]]],
        'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string)$ts, 'type' => 'text', 'text' => ['body' => $texto]]],
    ]]]]]];
}
$agora = em('2026-10-08 15:00:00');
$ts = $agora->getTimestamp();

$pdo = banco();
$r = wa_processar_payload($pdo, entrada('554591156006', 'wamid.A1', 'oi, quero saber do teste', $ts), ['agora' => $agora]);
confere('fluxo: 1 mensagem nova', 1, $r['mensagens']);
$c = wa_contato_por_telefone($pdo, '5545991156006');
confere('fluxo: contato criado com telefone normalizado', true, $c !== null);
confere('fluxo: nome vem do perfil', 'Maria', $c['nome']);
confere('fluxo: abre a janela', true, wa_janela_aberta($c['ultima_entrada_em'], $agora));
confere('fluxo: sem consentimento de marketing', null, $c['optin_em']);

$r = wa_processar_payload($pdo, entrada('554591156006', 'wamid.A1', 'oi, quero saber do teste', $ts), ['agora' => $agora]);
confere('fluxo: reentrega da Meta nao duplica', 1, $r['duplicadas']);
confere('fluxo: continua com 1 mensagem', '1', (string)$pdo->query('SELECT COUNT(*) FROM intus_wa_mensagem')->fetchColumn());
confere('fluxo: continua com 1 contato', '1', (string)$pdo->query('SELECT COUNT(*) FROM intus_wa_contato')->fetchColumn());

// SAIR: cancela a agenda, marca saida, confirma
$pdo->prepare("INSERT INTO intus_wa_agenda (idcontato, passo, enviar_em, condicao, status) VALUES (?, 't2', '2026-10-09 10:00:00', 'sem_treino', 'pendente')")->execute([$c['idcontato']]);
$pdo->prepare("INSERT INTO intus_wa_agenda (idcontato, passo, enviar_em, condicao, status) VALUES (?, 't1', '2026-10-08 09:00:00', 'todos', 'enviada')")->execute([$c['idcontato']]);
$enviados = [];
$r = wa_processar_payload($pdo, entrada('554591156006', 'wamid.A2', 'SAIR', $ts + 60), [
    'agora' => $agora->modify('+1 minute'),
    'enviar' => function ($contato, $texto) use (&$enviados) { $enviados[] = [$contato['telefone'], $texto]; },
]);
$c = wa_contato_por_telefone($pdo, '5545991156006');
confere('sair: contado como saida', 1, $r['saidas']);
confere('sair: saiu_em preenchido', true, !empty($c['saiu_em']));
confere('sair: etapa saiu', 'saiu', $c['etapa']);
confere('sair: modo pausado', 'pausado', $c['modo']);
confere('sair: confirmacao enviada uma vez', 1, count($enviados));
confere('sair: confirmacao vai para o telefone certo', '5545991156006', $enviados[0][0] ?? null);
confere('sair: texto da confirmacao', WA_TEXTO_SAIDA_CONFIRMADA, $enviados[0][1] ?? null);
confere('sair: agenda pendente cancelada', 'cancelada', $pdo->query("SELECT status FROM intus_wa_agenda WHERE passo = 't2'")->fetchColumn());
confere('sair: agenda ja enviada nao muda', 'enviada', $pdo->query("SELECT status FROM intus_wa_agenda WHERE passo = 't1'")->fetchColumn());
confere('sair: mensagem de saida ja nasce processada', '1', (string)$pdo->query("SELECT processada FROM intus_wa_mensagem WHERE wa_id = 'wamid.A2'")->fetchColumn());
confere('sair: nenhum envio permitido depois', [false, 'saiu'], wa_decidir_envio($c, $agora, ['tipo' => 'template']));

// "vou parar de treinar" nao e saida
$pdo2 = banco();
$r = wa_processar_payload($pdo2, entrada('5545988887777', 'wamid.B1', 'vou parar de treinar uns dias', $ts), ['agora' => $agora]);
confere('nao sair: frase com parar nao descadastra', 0, $r['saidas']);

// Midia (video) chama uma pessoa
$vid = ['entry' => [['changes' => [['value' => ['messages' => [['from' => '5545988887777', 'id' => 'wamid.B2', 'timestamp' => (string)$ts, 'type' => 'video', 'video' => ['id' => 'MID123', 'caption' => 'agachamento']]]]]]]]];
$r = wa_processar_payload($pdo2, $vid, ['agora' => $agora]);
$c2 = wa_contato_por_telefone($pdo2, '5545988887777');
confere('midia: contada', 1, $r['midias']);
confere('midia: pede pessoa', '1', (string)$c2['precisa_humano']);
confere('midia: motivo', 'midia_recebida', $c2['motivo_humano']);
confere('midia: guarda o id da midia', 'MID123', $pdo2->query("SELECT midia_id FROM intus_wa_mensagem WHERE wa_id = 'wamid.B2'")->fetchColumn());
confere('midia: guarda a legenda', 'agachamento', $pdo2->query("SELECT texto FROM intus_wa_mensagem WHERE wa_id = 'wamid.B2'")->fetchColumn());

// Botao de resposta rapida do template
$btn = ['entry' => [['changes' => [['value' => ['messages' => [['from' => '5545977776666', 'id' => 'wamid.C1', 'timestamp' => (string)$ts, 'type' => 'button', 'button' => ['text' => 'Parar de receber', 'payload' => 'x']]]]]]]]];
$r = wa_processar_payload(banco(), $btn, ['agora' => $agora]);
confere('botao: "Parar de receber" tambem sai', 1, $r['saidas']);

// Status de entrega: so avanca
$pdo3 = banco();
$c3 = wa_contato_obter_ou_criar($pdo3, '5545991156006', 'Maria', $agora);
wa_msg_registrar($pdo3, ['idcontato' => $c3['idcontato'], 'direcao' => 'S', 'origem' => 'sequencia', 'texto' => 'oi', 'wa_id' => 'wamid.S1', 'status' => 'enviada', 'processada' => 1], $agora);
$st = fn(string $s, string $id = 'wamid.S1') => ['entry' => [['changes' => [['value' => ['statuses' => [['id' => $id, 'status' => $s]]]]]]]];
wa_processar_payload($pdo3, $st('delivered'), ['agora' => $agora]);
confere('status: entregue', 'entregue', $pdo3->query("SELECT status FROM intus_wa_mensagem WHERE wa_id = 'wamid.S1'")->fetchColumn());
wa_processar_payload($pdo3, $st('read'), ['agora' => $agora]);
confere('status: lida', 'lida', $pdo3->query("SELECT status FROM intus_wa_mensagem WHERE wa_id = 'wamid.S1'")->fetchColumn());
wa_processar_payload($pdo3, $st('sent'), ['agora' => $agora]);
confere('status: reentrega atrasada de sent nao volta atras', 'lida', $pdo3->query("SELECT status FROM intus_wa_mensagem WHERE wa_id = 'wamid.S1'")->fetchColumn());
$falha = ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.S1', 'status' => 'failed', 'errors' => [['title' => 'Mensagem fora da janela', 'message' => 'Re-engagement']]]]]]]]]];
wa_processar_payload($pdo3, $falha, ['agora' => $agora]);
confere('status: falha vale sempre', 'falhou', $pdo3->query("SELECT status FROM intus_wa_mensagem WHERE wa_id = 'wamid.S1'")->fetchColumn());
confere('status: guarda o motivo da falha', true, strpos((string)$pdo3->query("SELECT erro FROM intus_wa_mensagem WHERE wa_id = 'wamid.S1'")->fetchColumn(), 'fora da janela') !== false);
confere('status: id desconhecido e ignorado', 1, wa_processar_payload($pdo3, $st('read', 'wamid.NAOEXISTE'), ['agora' => $agora])['status']);

// ── Envio (HTTP simulado) ───────────────────────────────────────────────────
$pdo4 = banco();
$c4 = wa_contato_obter_ou_criar($pdo4, '5545991156006', 'Maria', $agora);
$cfg = ['phone_number_id' => '1234567890', 'access_token' => 'TOKEN-FALSO', 'api_version' => 'v23.0'];
$chamadas = [];
$httpOk = function (string $url, array $corpo, string $token) use (&$chamadas) {
    $chamadas[] = [$url, $corpo, $token];
    return ['status' => 200, 'json' => ['messages' => [['id' => 'wamid.OUT1']]], 'erro' => ''];
};
$r = wa_enviar_texto($pdo4, $cfg, $c4, 'Oi, Maria!', 'robo', $httpOk, $agora);
confere('envio: texto ok', true, $r['ok']);
confere('envio: url da API', 'https://graph.facebook.com/v23.0/1234567890/messages', $chamadas[0][0]);
confere('envio: destinatario', '5545991156006', $chamadas[0][1]['to']);
confere('envio: tipo texto', 'text', $chamadas[0][1]['type']);
confere('envio: token vai no cabecalho, nao no corpo', false, strpos(json_encode($chamadas[0][1]), 'TOKEN-FALSO') !== false);
confere('envio: historico guardou', 'enviada', $pdo4->query("SELECT status FROM intus_wa_mensagem WHERE wa_id = 'wamid.OUT1'")->fetchColumn());

$r = wa_enviar_template($pdo4, $cfg, $c4, 'boas_vindas_teste', ['Maria', 'https://intusfit.com.br/app/painel/login.html'], 'Oi Maria...', 'sequencia', 'pt_BR', function ($u, $b, $t) use (&$chamadas) { $chamadas[] = [$u, $b, $t]; return ['status' => 200, 'json' => ['messages' => [['id' => 'wamid.OUT2']]], 'erro' => '']; }, $agora);
$corpoTpl = end($chamadas)[1];
confere('template: ok', true, $r['ok']);
confere('template: nome', 'boas_vindas_teste', $corpoTpl['template']['name']);
confere('template: idioma', 'pt_BR', $corpoTpl['template']['language']['code']);
confere('template: variaveis na ordem', ['Maria', 'https://intusfit.com.br/app/painel/login.html'], array_column($corpoTpl['template']['components'][0]['parameters'], 'text'));
confere('template: guarda o nome do template', 'boas_vindas_teste', $pdo4->query("SELECT template FROM intus_wa_mensagem WHERE wa_id = 'wamid.OUT2'")->fetchColumn());

$httpErro = fn($u, $b, $t) => ['status' => 400, 'json' => ['error' => ['message' => 'Invalid parameter']], 'erro' => ''];
$r = wa_enviar_texto($pdo4, $cfg, $c4, 'falha', 'robo', $httpErro, $agora);
confere('envio: falha da Meta devolve erro', false, $r['ok']);
confere('envio: erro legivel', 'Meta 400: Invalid parameter', $r['erro']);
confere('envio: falha tambem fica no historico', 'falhou', $pdo4->query("SELECT status FROM intus_wa_mensagem WHERE texto = 'falha'")->fetchColumn());
$httpRede = fn($u, $b, $t) => ['status' => 0, 'json' => null, 'erro' => 'timeout'];
$r = wa_enviar_texto($pdo4, $cfg, $c4, 'rede', 'robo', $httpRede, $agora);
confere('envio: falha de rede', 'rede: timeout', $r['erro']);

$t = wa_testar_conexao($cfg, fn($u, $t) => ['status' => 200, 'json' => ['display_phone_number' => '+55 45 99115-6006', 'verified_name' => 'Intus Fit', 'quality_rating' => 'GREEN'], 'erro' => '']);
confere('conexao: ok devolve numero e nome', ['+55 45 99115-6006', 'Intus Fit'], [$t['numero'], $t['nome']]);
$t = wa_testar_conexao($cfg, fn($u, $t) => ['status' => 401, 'json' => ['error' => ['message' => 'Invalid OAuth access token']], 'erro' => '']);
confere('conexao: token ruim', false, $t['ok']);

// ── Ajustes e segredos ──────────────────────────────────────────────────────
$pdo5 = banco();
confere('ajustes: padrao tem o nome Cora', 'Cora', wa_ajustes($pdo5)['nome_atendente']);
wa_segredo_gravar($pdo5, 'wa_ajustes', ['nome_atendente' => 'Outra', 'campo_estranho' => 'x']);
$aj = wa_ajustes($pdo5);
confere('ajustes: nome salvo vale', 'Outra', $aj['nome_atendente']);
confere('ajustes: campo desconhecido e descartado', false, array_key_exists('campo_estranho', $aj));
confere('ajustes: o que nao foi salvo fica no padrao', 'claude-opus-5-5', $aj['modelo']);
confere('config: sem chaves devolve null', null, wa_config_whatsapp($pdo5));
wa_segredo_gravar($pdo5, 'whatsapp_config', ['phone_number_id' => '999', 'access_token' => 'abc']);
$cw = wa_config_whatsapp($pdo5);
confere('config: completa com padroes', ['999', 'v23.0'], [$cw['phone_number_id'], $cw['api_version']]);
wa_segredo_gravar($pdo5, 'whatsapp_config', ['phone_number_id' => '999', 'access_token' => 'novo']);
confere('config: gravar de novo atualiza', 'novo', wa_config_whatsapp($pdo5)['access_token']);

// ── Resultado ───────────────────────────────────────────────────────────────
echo "\n";
if ($falhas) {
    echo "FALHOU (" . count($falhas) . "):\n";
    foreach ($falhas as $f) echo "  - $f\n";
    echo "\n$ok conferencias passaram, " . count($falhas) . " falharam.\n";
    exit(1);
}
echo "OK: $ok conferencias passaram.\n";
