<?php
// Histórico de acessos (app/api/_acesso.php e _acesso_rotas.php) num SQLite em memória: registro, limite de aberturas, fuso do aluno, conversão de horário
// (Brasília e fuso local), escopo por professor, IP só para o administrador e recusa de aluno.
//   php -c <php.ini com pdo_sqlite> testes/acessos.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }

date_default_timezone_set('America/Sao_Paulo');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE intus_acesso (idacesso INTEGER PRIMARY KEY AUTOINCREMENT, user_tipo TEXT, user_id INT, user_nome TEXT, evento TEXT, ref_id INT, ip TEXT, pais TEXT, regiao TEXT, cidade TEXT, fuso_cf TEXT, fuso_local TEXT, aparelho TEXT, criado_em TEXT)");
$pdo->exec("CREATE TABLE atleta (idatleta INT, nome TEXT)");
$pdo->exec("INSERT INTO atleta VALUES (1,'Ana Souza'),(2,'Bia Lima'),(3,'Caio Reis')");
function getAtletasDoUsuario($pdo, $id) { return $id === 10 ? [1, 2] : []; }   // professor 10 só tem os alunos 1 e 2
require __DIR__ . '/../app/api/_ip.php';
require __DIR__ . '/../app/api/_acesso.php';
require __DIR__ . '/../app/api/_acesso_rotas.php';
function rota($pdo, $ctx, $aluno, $acao, $get = []) { $_GET = $get; ob_start(); http_response_code(200); intusAcessoRota($pdo, $ctx, $aluno, $acao, 'atleta'); $o = ob_get_clean(); return [http_response_code(), json_decode($o, true)]; }
function limpar($pdo) { $pdo->exec("DELETE FROM intus_acesso"); }

// 1) registro e limite de aberturas
$_SERVER = ['REMOTE_ADDR' => '200.1.2.3', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit Chrome/120 Mobile Safari/537.36'];
intusRegistrarAcesso($pdo, 'aluno', 1, 'Ana Souza', 'login');
intusRegistrarAcesso($pdo, 'aluno', 1, 'Ana Souza', 'abertura', null, 'Europe/Lisbon');
intusRegistrarAcesso($pdo, 'aluno', 1, 'Ana Souza', 'abertura', null, 'Europe/Lisbon');
eq('login grava; duas aberturas seguidas viram uma só (limite de 20 min)', (int)$pdo->query("SELECT COUNT(*) FROM intus_acesso")->fetchColumn() === 2);
intusRegistrarAcesso($pdo, 'aluno', 1, '', 'treino', 55);
$r = $pdo->query("SELECT * FROM intus_acesso WHERE evento='treino'")->fetch(PDO::FETCH_ASSOC);
eq('treino herda o fuso do aparelho informado na última abertura', $r && $r['fuso_local'] === 'Europe/Lisbon' && (int)$r['ref_id'] === 55, json_encode($r));
eq('aparelho vem resumido (Chrome em Android)', $r && strpos((string)$r['aparelho'], 'Android') !== false && strlen($r['aparelho']) < 40, (string)($r['aparelho'] ?? ''));
eq('IP gravado', $r && $r['ip'] === '200.1.2.3');
intusRegistrarAcesso($pdo, 'aluno', 2, 'Bia', 'abertura', null, '<script>x</script>');
$f = $pdo->query("SELECT fuso_local FROM intus_acesso WHERE user_id=2")->fetchColumn();
eq('fuso inválido não é guardado', $f === null || $f === '', var_export($f, true));
$antes = (int)$pdo->query("SELECT COUNT(*) FROM intus_acesso")->fetchColumn();
intusRegistrarAcesso($pdo, 'aluno', 0, 'x', 'login');
eq('usuário 0 é ignorado', (int)$pdo->query("SELECT COUNT(*) FROM intus_acesso")->fetchColumn() === $antes);

// 2) horário: 15:00 UTC = 12:00 em Brasília; em Lisboa (UTC+1 em outubro) = 16:00
limpar($pdo);
$hoje = gmdate('Y-m-d');
$pdo->exec("INSERT INTO intus_acesso (user_tipo,user_id,user_nome,evento,ip,pais,cidade,fuso_local,criado_em) VALUES ('aluno',1,'','treino','9.9.9.9','PT','Lisboa','Europe/Lisbon','$hoje 15:00:00')");
$pdo->exec("INSERT INTO intus_acesso (user_tipo,user_id,user_nome,evento,ip,pais,cidade,fuso_local,criado_em) VALUES ('aluno',2,'Bia','abertura','8.8.8.8','BR','Cascavel','','$hoje 15:30:00')");
[$cod, $j] = rota($pdo, ['admin' => true, 'idusuario' => 1], false, 'acessos_painel');
eq('quadro responde 200', $cod === 200 && isset($j['hora_br']), json_encode($j));
eq('treino às 15:00 UTC conta na hora 12 de Brasília', ($j['hora_br']['treino'][12] ?? 0) === 1, json_encode($j['hora_br']['treino'] ?? null));
eq('o mesmo treino conta na hora 16 do fuso do aluno (Lisboa)', ($j['hora_local']['treino'][16] ?? 0) === 1, json_encode($j['hora_local']['treino'] ?? null));
eq('acesso sem fuso do aparelho usa o horário de Brasília nos dois quadros', ($j['hora_br']['abertura'][12] ?? 0) === 1 && ($j['hora_local']['abertura'][12] ?? 0) === 1);
eq('fuso diferente de Brasília aparece na lista de fusos', ($j['fusos'][0]['fuso'] ?? '') === 'Europe/Lisbon');
$loc = array_column($j['locais'], 'n', 'local');
eq('locais com mais acessos (cidade, país)', ($loc['Lisboa, PT'] ?? 0) === 1 && ($loc['Cascavel, BR'] ?? 0) === 1, json_encode($loc));
$nomes = array_column($j['recentes'], 'nome', 'user_id');
eq('nome do aluno vem do cadastro quando o registro não tem nome', ($nomes[1] ?? '') === 'Ana Souza', json_encode($nomes));
eq('administrador vê o IP', isset($j['recentes'][0]['ip']) && $j['recentes'][0]['ip'] !== null);
$lis = null; foreach ($j['recentes'] as $x) if ($x['user_id'] === 1) $lis = $x;
eq('mostra o horário local do aluno quando difere de Brasília (16:00)', $lis && $lis['hora_local'] === '16:00', json_encode($lis));

// 3) escopo do professor: só os alunos dele, sem IP
$pdo->exec("INSERT INTO intus_acesso (user_tipo,user_id,user_nome,evento,ip,criado_em) VALUES ('aluno',3,'Caio','treino','7.7.7.7','$hoje 14:00:00')");
[$cod, $jp] = rota($pdo, ['admin' => false, 'idusuario' => 10], false, 'acessos_painel');
$ids = array_values(array_unique(array_column($jp['recentes'], 'user_id')));
sort($ids);
eq('professor vê só os alunos dele (1 e 2, não o 3)', $ids === [1, 2], json_encode($ids));
eq('professor NÃO recebe o IP', $jp['recentes'][0]['ip'] === null);
[$cod, $jx] = rota($pdo, ['admin' => false, 'idusuario' => 11], false, 'acessos_painel');
eq('professor sem alunos recebe quadro vazio', !empty($jx['vazio']) && $jx['recentes'] === []);

// 4) rota do administrador
[$cod, $ja] = rota($pdo, ['admin' => true, 'idusuario' => 1], false, 'acessos', ['dias' => 7]);
eq('administrador lista os acessos com IP e resumo por pessoa', $cod === 200 && $ja['total'] === 3 && count($ja['pessoas']) === 3 && $ja['linhas'][0]['ip'] !== '', json_encode($ja['total'] ?? null));
[$cod, $jb] = rota($pdo, ['admin' => true, 'idusuario' => 1], false, 'acessos', ['q' => 'caio']);
eq('busca por nome filtra', $jb['total'] === 1, (string)$jb['total']);
[$cod, $jc] = rota($pdo, ['admin' => false, 'idusuario' => 10], false, 'acessos');
eq('professor NÃO pode ver a lista de acessos (403)', $cod === 403, (string)$cod);
[$cod, $jd] = rota($pdo, ['admin' => false, 'idusuario' => 5], true, 'acessos_painel');
eq('aluno NÃO pode ver o quadro (403)', $cod === 403, (string)$cod);

// 5) limpeza do histórico: só administrador, mínimo 30 dias, apaga só o antigo
$pdo->exec("INSERT INTO intus_acesso (user_tipo,user_id,user_nome,evento,criado_em) VALUES ('aluno',1,'Ana','abertura','" . gmdate('Y-m-d H:i:s', time() - 200 * 86400) . "')");
$antes = (int)$pdo->query("SELECT COUNT(*) FROM intus_acesso")->fetchColumn();
$_SERVER['REQUEST_METHOD'] = 'DELETE';
[$cod, $jl] = rota($pdo, ['admin' => false, 'idusuario' => 10], false, 'acessos', ['dias' => 180]);
eq('professor NÃO pode limpar o histórico (403)', $cod === 403 && (int)$pdo->query("SELECT COUNT(*) FROM intus_acesso")->fetchColumn() === $antes, (string)$cod);
[$cod, $jl] = rota($pdo, ['admin' => true, 'idusuario' => 1], false, 'acessos', ['dias' => 5]);
eq('limpar com menos de 30 dias é recusado (400) e não apaga nada', $cod === 400 && (int)$pdo->query("SELECT COUNT(*) FROM intus_acesso")->fetchColumn() === $antes, (string)$cod);
[$cod, $jl] = rota($pdo, ['admin' => true, 'idusuario' => 1], false, 'acessos', ['dias' => 180]);
$depois = (int)$pdo->query("SELECT COUNT(*) FROM intus_acesso")->fetchColumn();
eq('administrador apaga só os com mais de 180 dias (1 registro)', $cod === 200 && ($jl['apagados'] ?? -1) === 1 && $depois === $antes - 1, json_encode($jl));
$_SERVER['REQUEST_METHOD'] = 'GET';
[$cod, $jr] = rota($pdo, ['admin' => true, 'idusuario' => 1], false, 'acessos', ['dias' => 7]);
eq('a tela recebe quantos registros estão guardados', isset($jr['guardados']) && $jr['guardados'] === $depois, json_encode($jr['guardados'] ?? null));

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
