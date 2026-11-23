<?php
// Pagamento dividido em varias formas (app/api/_pgto_partes.php) e a regra do PUT em mensalidades.php, em PHP de verdade com SQLite.
//   php -c <php.ini com pdo_sqlite, sqlite3> testes/pgto_partes.php
require __DIR__ . '/../app/api/_pgto_partes.php';
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if (json_encode($obtido) === json_encode($esperado)) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado) . "\n          obtido   " . json_encode($obtido) . "\n"; }
}
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
_pgtoPartesTabela($pdo); _pgtoPartesTabela($pdo);   // idempotente

echo "── normalizar\n";
$n = _pgtoPartesNormalizar([['forma' => 'dinheiro', 'valor' => 300], ['forma' => 'pix', 'valor' => '700,00']], 1000);
eq('300 dinheiro + 700 pix fecha com 1000 e a principal é o Pix', [$n['ok'], $n['principal'], count($n['partes'])], [true, 'pix', 2]);
$n = _pgtoPartesNormalizar([['forma' => 'dinheiro', 'valor' => 300], ['forma' => 'pix', 'valor' => 600]], 1000);
eq('soma que não fecha é recusada com a diferença no texto', [$n['ok'], strpos($n['erro'], '900,00') !== false && strpos($n['erro'], '1.000,00') !== false], [false, true]);
$n = _pgtoPartesNormalizar([['forma' => 'cartao', 'valor' => 333.33], ['forma' => 'pix', 'valor' => 333.33], ['forma' => 'dinheiro', 'valor' => 333.34]], 1000);
eq('três formas com centavos fecham', [$n['ok'], $n['principal']], [true, 'dinheiro']);
$n = _pgtoPartesNormalizar([['forma' => 'cartao', 'valor' => 500], ['forma' => 'pix', 'valor' => 500]], 1000);
eq('empate fica com a primeira digitada', $n['principal'], 'cartao');
foreach ([[['forma' => 'eduzz', 'valor' => 100], ['forma' => 'pix', 'valor' => 900]], [['forma' => 'pix', 'valor' => 0], ['forma' => 'dinheiro', 'valor' => 1000]], [['forma' => 'pix', 'valor' => -5], ['forma' => 'dinheiro', 'valor' => 1005]], [['forma' => 'x', 'valor' => 1000]]] as $i => $ruins) {
    $n = _pgtoPartesNormalizar($ruins, 1000);
    eq('parte inválida #' . ($i + 1) . ' (gateway, zero, negativa ou forma desconhecida) é recusada', $n['ok'], false);
}
eq('mais de 8 formas é recusado', _pgtoPartesNormalizar(array_fill(0, 9, ['forma' => 'pix', 'valor' => 1]), 9)['ok'], false);
eq('lista vazia é aceita (volta a forma única)', _pgtoPartesNormalizar([], 1000), ['ok' => true, 'partes' => [], 'principal' => null]);

echo "── gravar, ler e apagar\n";
$pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY, idatleta INT)");
$pdo->exec("INSERT INTO intus_mensalidade VALUES (1, 7), (2, 7), (3, 8)");
$n = _pgtoPartesNormalizar([['forma' => 'dinheiro', 'valor' => 300], ['forma' => 'pix', 'valor' => 700]], 1000);
_pgtoPartesGravar($pdo, 1, $n['partes']);
_pgtoPartesGravar($pdo, 2, [['forma' => 'pix', 'valor' => 1000]]);   // uma só forma não vira divisão
$rows = $pdo->query("SELECT idmensalidade FROM intus_mensalidade ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
_pgtoPartesAnexar($pdo, $rows);
eq('a lista traz as partes só de quem foi dividido, na ordem digitada', array_map(function ($r) { return [$r['idmensalidade'], $r['partes'] ?? null]; }, $rows),
   [[1, [['forma' => 'dinheiro', 'valor' => 300.0], ['forma' => 'pix', 'valor' => 700.0]]], [2, null], [3, null]]);
_pgtoPartesGravar($pdo, 1, [['forma' => 'cartao', 'valor' => 400], ['forma' => 'pix', 'valor' => 600]]);
eq('gravar de novo substitui (não soma)', (int)$pdo->query("SELECT COUNT(*) FROM intus_mensalidade_pgto WHERE idmensalidade = 1")->fetchColumn(), 2);
_pgtoPartesGravar($pdo, 1, []);
eq('gravar vazio desfaz a divisão', (int)$pdo->query("SELECT COUNT(*) FROM intus_mensalidade_pgto WHERE idmensalidade = 1")->fetchColumn(), 0);
_pgtoPartesGravar($pdo, 1, $n['partes']); _pgtoPartesApagar($pdo, 1);
eq('apagar a mensalidade leva as partes', (int)$pdo->query("SELECT COUNT(*) FROM intus_mensalidade_pgto")->fetchColumn(), 0);

echo "── regra do PUT em mensalidades.php\n";
$cod = file_get_contents(__DIR__ . '/../app/api/mensalidades.php');
$i = strpos($cod, '    $partesAplicar = null;'); $j = strpos($cod, '    $sets = [];', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco do PUT nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);
$antes = substr_count($bloco, "http_response_code(400);\n            echo json_encode(['error' => \$n['erro']]);\n            exit;");
if ($antes !== 1) { fwrite(STDERR, "formato do erro mudou\n"); exit(1); }
$bloco = str_replace("http_response_code(400);\n            echo json_encode(['error' => \$n['erro']]);\n            exit;", "return ['erro' => \$n['erro']];", $bloco);
$regra = eval('return function ($d, $rowBefore) { ' . $bloco . ' return [$partesAplicar, $d]; };');
$linha = ['forma_pgto' => 'pix', 'vlpagamento' => 1000];
[$ap, $dd] = $regra(['partes' => [['forma' => 'dinheiro', 'valor' => 300], ['forma' => 'pix', 'valor' => 700]], 'vlpagamento' => 1000, 'stpgto' => 'S'], $linha);
eq('baixa dividida: aplica as partes e a forma única vira a maior (pix)', [count($ap), $dd['forma_pgto']], [2, 'pix']);
[$ap, $dd] = $regra(['partes' => [['forma' => 'cartao', 'valor' => 800], ['forma' => 'dinheiro', 'valor' => 200]], 'vlpagamento' => 1000], $linha);
eq('com cartão maior, a forma única vira cartão', $dd['forma_pgto'], 'cartao');
$r = $regra(['partes' => [['forma' => 'dinheiro', 'valor' => 300], ['forma' => 'pix', 'valor' => 600]], 'vlpagamento' => 1000], $linha);
eq('soma errada no PUT é recusada antes de gravar qualquer coisa', isset($r['erro']), true);
$r = $regra(['partes' => [['forma' => 'dinheiro', 'valor' => 300], ['forma' => 'pix', 'valor' => 700]]], ['forma_pgto' => 'pix', 'vlpagamento' => 1000]);
eq('sem vlpagamento no corpo, confere com o valor já gravado', is_array($r[0]), true);
[$ap, $dd] = $regra(['forma_pgto' => 'dinheiro'], $linha);
eq('trocar só a forma desfaz a divisão', $ap, []);
[$ap, $dd] = $regra(['forma_pgto' => 'pix', 'dshistorico' => 'x'], $linha);
eq('reenviar a mesma forma (editar outro campo) não mexe nas partes', $ap, null);
[$ap, $dd] = $regra(['partes' => [], 'forma_pgto' => 'pix'], $linha);
eq('partes vazias pedem para limpar', $ap, []);

echo "\n$ok ok" . ($ruim ? ", $ruim FALHA(S)" : '') . "\n";
exit($ruim ? 1 : 0);
