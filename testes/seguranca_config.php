<?php
// Travas opcionais de seguranca (app/api/_auth_context.php): nascem desligadas, ligam e desligam sem mexer na outra, e a permissao de mensagens so nega quando a chave
// esta EXPLICITAMENTE desligada (quem nunca teve a chave continua com o chat). Os endpoints continuam chamando as travas.
//   php -c <php.ini com pdo_sqlite> testes/seguranca_config.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }
$cod = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/_auth_context.php'));
foreach (['function intusSegConfig', 'function intusSegGravar', 'function usuarioPermissaoNegada'] as $f) { $i = strpos($cod, $f); $j = strpos($cod, "\n}\n", $i); eval(str_replace("require_once __DIR__ . '/_gdrive.php';", '', substr($cod, $i, $j - $i + 3))); }
// _gdrive.php e o MySQL de verdade nao existem aqui: a tabela e criada na mao e a lista de equipe e simulada
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE intus_secrets (chave TEXT PRIMARY KEY, valor TEXT, updated_at TEXT)");
function _gdriveEnsureTable($pdo) {}
function intusUsuariosPermissoes($pdo) { return $GLOBALS['equipe']; }
$GLOBALS['equipe'] = [];

$c = intusSegConfig($pdo, true);
eq('sem nada gravado, as duas travas nascem DESLIGADAS', $c['mensagens'] === false && $c['desafios_admin'] === false, json_encode($c));
eq('ligar só as mensagens não liga os desafios', intusSegGravar($pdo, ['mensagens' => true]) && ($c = intusSegConfig($pdo, true)) && $c['mensagens'] === true && $c['desafios_admin'] === false, json_encode($c));
intusSegGravar($pdo, ['desafios_admin' => true]);
$c = intusSegConfig($pdo, true);
eq('ligar os desafios depois não desliga as mensagens', $c['mensagens'] === true && $c['desafios_admin'] === true, json_encode($c));
intusSegGravar($pdo, ['mensagens' => false]);
$c = intusSegConfig($pdo, true);
eq('desligar as mensagens mantém os desafios ligados', $c['mensagens'] === false && $c['desafios_admin'] === true, json_encode($c));
eq('uma linha só no banco (atualiza, não duplica)', (int)$pdo->query("SELECT COUNT(*) FROM intus_secrets WHERE chave='seg_config'")->fetchColumn() === 1);
$pdo->exec("UPDATE intus_secrets SET valor = 'isso nao e json' WHERE chave = 'seg_config'");
$c = intusSegConfig($pdo, true);
eq('valor corrompido no banco cai em tudo DESLIGADO (nunca trava por engano)', $c['mensagens'] === false && $c['desafios_admin'] === false);

$GLOBALS['equipe'] = [
  ['id' => 7, 'nome' => 'A', 'admin' => false, 'ativo' => true, 'perms' => ['ms_incluir' => false, 'at_ver' => true]],
  ['id' => 8, 'nome' => 'B', 'admin' => false, 'ativo' => true, 'perms' => ['at_ver' => true]],
  ['id' => 9, 'nome' => 'C', 'admin' => false, 'ativo' => true, 'perms' => ['ms_ver' => true, 'ms_incluir' => '1']],
];
eq('chave explicitamente DESLIGADA nega', usuarioPermissaoNegada($pdo, 7, 'ms_incluir'));
eq('chave que o usuário nunca teve NÃO nega (o chat de hoje continua)', !usuarioPermissaoNegada($pdo, 8, 'ms_incluir') && !usuarioPermissaoNegada($pdo, 8, 'ms_ver'));
eq('chave ligada (true ou "1") não nega', !usuarioPermissaoNegada($pdo, 9, 'ms_ver') && !usuarioPermissaoNegada($pdo, 9, 'ms_incluir'));
eq('usuário desconhecido não nega', !usuarioPermissaoNegada($pdo, 99, 'ms_ver'));

$ar = fn($p) => str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/' . $p));
eq('mensagens.php consulta a trava e a chave antes de listar, enviar e excluir', strpos($ar('mensagens.php'), "intusSegConfig(\$pdo)['mensagens']") !== false && strpos($ar('mensagens.php'), "'enviar' => 'ms_incluir'") !== false);
eq('desafios.php exige administrador nas escritas quando a trava está ligada', strpos($ar('desafios.php'), "intusSegConfig(\$pdo)['desafios_admin']") !== false && strpos($ar('desafios.php'), '_intusExigeSessao($pdo, true, true)') !== false);
eq('a rota de configuração é só do administrador', preg_match("/\\$_SO_ADMIN = \[[^\]]*'seguranca_config'/", $ar('catalogo.php')) === 1);
echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
