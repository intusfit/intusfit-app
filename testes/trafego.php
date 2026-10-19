<?php
// Analytics de captação (app/api/_trafego.php), em SQLite na memória: classificação, registro, repetição, limites e o relatório.
//   php -c <ini com pdo_sqlite e mbstring> testes/trafego.php
require __DIR__ . '/../app/api/_trafego.php';
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
$UA_INSTA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Instagram 300.0.0.0';
$UA_DESK = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
$UA_BOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$novoBanco = function () { $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); return $p; };

echo "\n══ Classificação ══\n";
eq('iPhone no Safari', array_values(array_diff_key(trClassificarUA($UA_IPHONE), ['bot' => 1])), ['celular', 'Safari', 'iOS']);
eq('Instagram app é reconhecido', trClassificarUA($UA_INSTA)['navegador'], 'Instagram');
eq('desktop Windows Chrome', array_values(array_diff_key(trClassificarUA($UA_DESK), ['bot' => 1])), ['desktop', 'Chrome', 'Windows']);
eq('Googlebot é robô', trClassificarUA($UA_BOT)['bot'], true);
eq('sem User-Agent é robô', trClassificarUA('')['bot'], true);
eq('pessoa de verdade não é robô', trClassificarUA($UA_IPHONE)['bot'], false);
eq('página: tira parâmetros e normaliza /index.html', [trPagina('/index.html?x=1'), trPagina('https://intusfit.com.br/Teste-Gratis.html#a'), trPagina('')], ['/', '/teste-gratis.html', '/']);
eq('página: lixo e script saem', trPagina('/a b/<script>x'), '/ab/scriptx');
eq('host de origem sem www', trHost('https://www.google.com.br/search?q=a'), 'google.com.br');
eq('canal: indicação vence tudo', trCanal(5, 'instagram', 'google.com'), 'indicacao');
eq('canal: UTM vence o site de origem', trCanal(null, 'IG', 'google.com'), 'instagram');
eq('canal: site de origem', [trCanal(null, '', 'l.instagram.com'), trCanal(null, '', 'www.google.com.br'), trCanal(null, '', 'l.facebook.com'), trCanal(null, '', 'blog.exemplo.com'), trCanal(null, '', '')], ['instagram', 'google', 'facebook', 'outros sites', 'direto']);

echo "\n══ Registro de visitas e cliques ══\n";
$pdo = $novoBanco();
$t0 = strtotime('2026-10-19 15:00:00 UTC');   // 12h em Brasília
$v = fn($b, $ua = null, $ts = null) => trRegistrarVisita($pdo, $b, $ua ?? $UA_IPHONE, ['pais' => 'BR', 'regiao' => 'PR', 'cidade' => 'Cascavel'], $ts ?? $t0, 'intusfit.com.br');
$r = $v(['vid' => 'aaaaaaaa11', 'sid' => 'ssssssss11', 'pagina' => '/teste-gratis.html', 'utm_source' => 'instagram', 'utm_medium' => 'stories', 'utm_campaign' => 'outubro', 'utm_content' => 'story-a5', 'w' => 390]);
eq('1ª visita grava', [$r['ok'], $r['id'] > 0], [true, true]);
$l = $pdo->query('SELECT * FROM intus_trafego_visita')->fetch();
eq('guarda dia e hora em Brasília (12h)', [$l['dia'], (int)$l['hora']], ['2026-10-19', 12]);
eq('guarda canal, UTM, aparelho e local', [$l['canal'], $l['utm_campaign'], $l['utm_content'], $l['aparelho'], $l['cidade'], (int)$l['novo']], ['instagram', 'outubro', 'story-a5', 'celular', 'Cascavel', 1]);
eq('NÃO guarda o IP (a tabela nem tem a coluna)', array_key_exists('ip', $l), false);
$r2 = $v(['vid' => 'aaaaaaaa11', 'sid' => 'ssssssss11', 'pagina' => '/teste-gratis.html'], null, $t0 + 1);
eq('mesma pessoa, mesma página, 1 s depois: não duplica', [$r2['motivo'], (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn()], ['duplicada', 1]);
$v(['vid' => 'aaaaaaaa11', 'sid' => 'ssssssss11', 'pagina' => '/'], null, $t0 + 60);
eq('outra página da mesma sessão conta', (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn(), 2);
eq('só a 1ª visita do visitante é "novo"', array_map('intval', array_column($pdo->query('SELECT novo FROM intus_trafego_visita ORDER BY idvisita')->fetchAll(), 'novo')), [1, 0]);
$rb = $v(['vid' => 'bbbbbbbb22', 'sid' => 'tttttttt22', 'pagina' => '/'], $UA_BOT);
eq('robô é ignorado (sem erro)', [$rb['ok'], $rb['motivo'], (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn()], [true, 'bot', 2]);
eq('visita sem identificador válido é recusada', $v(['vid' => 'x', 'sid' => 'y', 'pagina' => '/'])['ok'], false);
$v(['vid' => 'cccccccc33', 'sid' => 'uuuuuuuu33', 'pagina' => '/teste-gratis.html', 'ref' => '42', 'referrer' => 'https://l.instagram.com/']);
eq('link de indicação vira canal "indicacao" com o aluno', $pdo->query("SELECT canal || '/' || ref_idatleta FROM intus_trafego_visita WHERE vid='cccccccc33'")->fetchColumn(), 'indicacao/42');
$v(['vid' => 'cccccccc33', 'sid' => 'vvvvvvvv33', 'pagina' => '/', 'ref' => 'abc; DROP TABLE x', 'referrer' => 'https://intusfit.com.br/teste-gratis.html'], null, $t0 + 3600);
eq('ref inválido vira nada e navegação interna não é origem', $pdo->query("SELECT COALESCE(ref_idatleta,'-') || '/' || COALESCE(referrer_host,'-') || '/' || canal FROM intus_trafego_visita WHERE sid='vvvvvvvv33'")->fetchColumn(), '-/-/direto');
for ($i = 0; $i < 205; $i++) $v(['vid' => 'dddddddd44', 'sid' => 'wwwwwwww44', 'pagina' => '/p' . $i], null, $t0 + 100);
eq('limite de 200 visitas por hora por visitante', (int)$pdo->query("SELECT COUNT(*) FROM intus_trafego_visita WHERE vid='dddddddd44'")->fetchColumn(), 200);

$e = fn($b, $ts = null) => trRegistrarEvento($pdo, $b, $UA_IPHONE, $ts ?? $t0 + 5);
$e(['vid' => 'aaaaaaaa11', 'sid' => 'ssssssss11', 'pagina' => '/teste-gratis.html', 'evento' => 'form_inicio']);
$e(['vid' => 'aaaaaaaa11', 'sid' => 'ssssssss11', 'pagina' => '/teste-gratis.html', 'evento' => 'conta_criada', 'rotulo' => 'direto', 'valor' => 77]);
$e(['vid' => 'cccccccc33', 'sid' => 'uuuuuuuu33', 'pagina' => '/teste-gratis.html', 'evento' => 'lead_enviado']);
$e(['vid' => 'aaaaaaaa11', 'sid' => 'ssssssss11', 'pagina' => '/', 'evento' => 'whatsapp', 'rotulo' => 'Quero contratar o plano de Treino']);
$ev = $pdo->query("SELECT * FROM intus_trafego_evento WHERE evento='conta_criada'")->fetch();
eq('o evento herda a origem da 1ª visita da sessão', [$ev['canal'], $ev['utm_campaign'], (int)$ev['valor']], ['instagram', 'outubro', 77]);
eq('evento sem sessão conhecida entra sem origem', ($e(['vid' => 'eeeeeeee55', 'sid' => 'xxxxxxxx55', 'pagina' => '/', 'evento' => 'cta'])['ok'] && $pdo->query("SELECT canal IS NULL FROM intus_trafego_evento WHERE vid='eeeeeeee55'")->fetchColumn() == 1), true);
eq('nome de evento inválido é recusado', $e(['vid' => 'aaaaaaaa11', 'sid' => 'ssssssss11', 'pagina' => '/', 'evento' => 'x; drop'])['ok'], false);

echo "\n══ Relatório ══\n";
$rel = trRelatorio($pdo, '2026-10-13', '2026-10-19', null, null, $t0 + 600);
eq('visitantes únicos (sem robô, sem duplicata)', $rel['kpis']['visitantes'], 3);
eq('pessoas captadas = conta_criada + lead_enviado', $rel['kpis']['conversoes'], 2);
eq('cliques de interesse (whatsapp + cta)', $rel['kpis']['cliques'], 2);
eq('taxa = captadas ÷ visitantes', $rel['kpis']['taxa'], round(2 * 100 / 3, 1));
eq('a série cobre todos os 7 dias, com zero nos vazios', [count($rel['serie']), $rel['serie'][0]['visitas'], $rel['serie'][6]['conversoes']], [7, 0, 2]);
$canais = array_column($rel['canais'], 'conversoes', 'canal');
eq('captadas por canal', [$canais['instagram'] ?? null, $canais['indicacao'] ?? null], [1, 1]);
eq('campanha com UTM aparece com a captação', [$rel['campanhas'][0]['utm_campaign'], $rel['campanhas'][0]['utm_content'], $rel['campanhas'][0]['conversoes']], ['outubro', 'story-a5', 1]);
eq('indicações por aluno', [$rel['indicacoes'][0]['ref_idatleta'], $rel['indicacoes'][0]['visitantes'], $rel['indicacoes'][0]['conversoes']], [42, 1, 1]);
$f = array_column($rel['funil'], 'n', 'etapa');
eq('funil do teste grátis', [$f['visita'], $f['form_inicio'], $f['conta_criada'], $f['lead_enviado']], [2, 1, 1, 1]);
eq('filtro por página', trRelatorio($pdo, '2026-10-13', '2026-10-19', '/teste-gratis.html', null, $t0 + 600)['kpis']['conversoes'], 2);
eq('filtro por página: o site não tem captação', trRelatorio($pdo, '2026-10-13', '2026-10-19', '/', null, $t0 + 600)['kpis']['conversoes'], 0);
eq('período anterior é calculado (7 dias antes)', [$rel['periodo']['anterior_de'], $rel['periodo']['anterior_ate'], $rel['kpis_anterior']['visitas']], ['2026-10-06', '2026-10-12', 0]);
eq('horário de pico em Brasília', $rel['horas'][12] > 0, true);
eq('pessoas agora (últimos 30 min)', $rel['agora']['ativos'] >= 1, true);
eq('sem tabela de leads o relatório não quebra', $rel['banco']['leads'], null);
$pdo->exec("CREATE TABLE intus_lead (idlead INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, origem TEXT, indicado_por_idatleta INT, criado_em TEXT)");
$pdo->exec("INSERT INTO intus_lead (nome, origem, indicado_por_idatleta, criado_em) VALUES ('A','landing-teste-gratis',42,'2026-10-18 10:00:00'),('B','landing-teste-gratis',NULL,'2026-10-18 11:00:00'),('C','app',NULL,'2026-09-01 11:00:00')");
$rel = trRelatorio($pdo, '2026-10-13', '2026-10-19', null, null, $t0 + 600);
eq('conferência com a tabela de leads (só do período)', [$rel['banco']['leads'], $rel['banco']['leads_indicados']], [2, 1]);

echo "\n══ Limpeza (cuidadosa) ══\n";
$pdo->exec("INSERT INTO intus_trafego_visita (vid, sid, pagina, dia, hora, criado_em) VALUES ('ffffffff66','yyyyyyyy66','/','2020-01-01',3,'2020-01-01 03:00:00'),('ffffffff67','yyyyyyyy67','/','2026-08-01',3,'2026-08-01 03:00:00')");
$pdo->exec("INSERT INTO intus_trafego_evento (vid, sid, pagina, evento, dia, hora, criado_em) VALUES ('ffffffff66','yyyyyyyy66','/','whatsapp','2020-01-01',3,'2020-01-01 03:00:00')");
$totalAntes = (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn();
$pre = trPreviaLimpeza($pdo, 30, $t0);   // 30 dias sobe para o mínimo de 365
eq('a prévia só conta e respeita o mínimo de 365 dias', [$pre['dias'], $pre['visitas'], $pre['eventos'], $pre['total'], $pre['mais_antiga']], [365, 1, 1, 2, '2020-01-01']);
eq('a prévia não apagou nada', (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn(), $totalAntes);
eq('total diferente do que a pessoa viu: não apaga nada', [trLimparAntigos($pdo, 30, 99, $t0), (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn()], [null, $totalAntes]);
eq('visita de 80 dias atrás (agosto) sobrevive mesmo pedindo "30 dias"', trPreviaLimpeza($pdo, 30, $t0)['visitas'], 1);
$apagadas = trLimparAntigos($pdo, 30, $pre['total'], $t0);
eq('com o total certo, apaga só o que tem mais de 12 meses', [$apagadas, (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn(), (int)$pdo->query("SELECT COUNT(*) FROM intus_trafego_visita WHERE vid='ffffffff67'")->fetchColumn(), (int)$pdo->query("SELECT COUNT(*) FROM intus_trafego_evento WHERE dia < '2021-01-01'")->fetchColumn()], [2, $totalAntes - 1, 1, 0]);
eq('não tocou nas visitas recentes', (int)$pdo->query("SELECT COUNT(*) FROM intus_trafego_visita WHERE vid='aaaaaaaa11'")->fetchColumn(), 2);
$rel = trRelatorio($pdo, '2026-10-13', '2026-10-19', null, null, $t0 + 600);
eq('o relatório mostra o que está guardado', $rel['armazenado']['visitas'] > 0 && $rel['armazenado']['desde'] !== null, true);
eq('o relatório separa contas criadas e contatos sem conta (para a conferência com o banco)', [$rel['kpis']['contas_criadas'], $rel['kpis']['contatos_sem_conta']], [1, 1]);

echo "\n══ Estrutura do endpoint ══\n";
$php = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/trafego.php'));
eq('o relatório exige administrador', strpos($php, "empty(\$ctx['admin'])") !== false && strpos($php, "'acesso restrito ao administrador'") !== false, true);
eq('quem só envia não recebe resposta com dados', strpos($php, "echo json_encode(['ok' => \$r['ok']]);") !== false, true);
eq('limpar exige DELETE, mínimo de 365 dias, confirmação e o total visto na prévia', strpos($php, "'use DELETE'") !== false && strpos($php, "'minimo 365 dias'") !== false && strpos($php, "'confirmacao ausente'") !== false && strpos($php, "'total esperado ausente'") !== false, true);
eq('o total que mudou desde a prévia devolve 409 sem apagar', strpos($php, '409') !== false && strpos($php, 'o total mudou desde a previa') !== false, true);
eq('a biblioteca não grava IP', preg_match('/intusClientIp|REMOTE_ADDR/', file_get_contents(__DIR__ . '/../app/api/_trafego.php')) === 0, true);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
