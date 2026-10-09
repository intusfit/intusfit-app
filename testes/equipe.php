<?php
// "Meus alimentos" compartilhado pela equipe e medidas caseiras da equipe (catalogo.php, rotas meus_alimentos e alimento_medidas), em PHP de verdade com SQLite.
// Cada caso roda num processo próprio (o endpoint termina com exit) e escolhe QUEM está logado: profissional 1, profissional 2, admin ou aluno.
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/equipe.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = file_get_contents($SRC);
$i = strpos($cod, '// ═══════════════ MEUS ALIMENTOS');
$j = strpos($cod, '// ═══════════════ CAIXA (financeiro)', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $num = ''; foreach (['energy_kcal','protein_g','carbohydrate_g','lipid_g','fiber_g','sodium_mg','calcium_mg','iron_mg','potassium_mg','magnesium_mg','phosphorus_mg','zinc_mg','vitaminC_mg','cholesterol_mg','saturated_g','monounsaturated_g','polyunsaturated_g','thiamine_mg','riboflavin_mg','niacin_mg'] as $k) $num .= "$k REAL NOT NULL DEFAULT 0, ";
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_alimento_personalizado (id INTEGER PRIMARY KEY AUTOINCREMENT, idprofessor INTEGER NOT NULL, description TEXT NOT NULL, category TEXT NULL, $num porcao_padrao REAL NOT NULL DEFAULT 100, medida_padrao TEXT NOT NULL DEFAULT 'g')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_alimento_medida (idmedida INTEGER PRIMARY KEY AUTOINCREMENT, food_id INTEGER NOT NULL, nome TEXT NOT NULL, gramas REAL NOT NULL, idprofessor INTEGER NOT NULL, created_at TEXT NULL)");
    $action = $caso['rota'];
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_ehAluno = !empty($caso['aluno']);
    $_ctx = ['admin' => !empty($caso['admin']), 'idusuario' => (int)($caso['quem'] ?? 1), 'is_aluno' => $_ehAluno];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    $_negar = function ($c, $m) { echo json_encode(['error' => $m]); exit; };
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_equipe_' . getmypid() . '.sqlite';
@unlink($tmp);
$ok = 0; $ruim = 0;
function rodar($caso) {
    global $tmp, $ini;
    $caso['db'] = $tmp;
    $env = ['CASO_JSON' => json_encode($caso), 'PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot')];
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' 2>&1';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['_cru' => $out];
}
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido == $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function alim($quem, $metodo, $corpo = [], $get = [], $extra = []) { return rodar(array_merge(['rota' => 'meus_alimentos', 'metodo' => $metodo, 'quem' => $quem, 'corpo' => $corpo, 'get' => $get], $extra)); }
function med($quem, $metodo, $corpo = [], $get = [], $extra = []) { return rodar(array_merge(['rota' => 'alimento_medidas', 'metodo' => $metodo, 'quem' => $quem, 'corpo' => $corpo, 'get' => $get], $extra)); }

echo "\n── Meus alimentos é da equipe ──\n";
$a = alim(1, 'POST', ['description' => 'Whey da Ana', 'energy_kcal' => 400, 'protein_g' => 80]);
$b = alim(2, 'POST', ['description' => 'Barra do Bruno', 'energy_kcal' => 380, 'protein_g' => 20]);
eq('profissional 1 cria', [$a['ok'] ?? null, $a['meu'] ?? null], [true, true]);
$l1 = alim(1, 'GET'); $l2 = alim(2, 'GET');
eq('profissional 1 vê os dois alimentos', array_map(fn($x) => $x['description'], $l1), ['Barra do Bruno', 'Whey da Ana']);
eq('profissional 2 também vê os dois', count($l2), 2);
$flags = []; foreach ($l1 as $x) $flags[$x['description']] = $x['meu'];
eq('cada um sabe quais são seus (meu)', $flags, ['Barra do Bruno' => false, 'Whey da Ana' => true]);
$idBarra = null; foreach ($l1 as $x) if ($x['description'] === 'Barra do Bruno') $idBarra = $x['id'];
$r = alim(1, 'PUT', ['id' => $idBarra, 'energy_kcal' => 999]);
eq('profissional 1 NÃO edita alimento do 2', $r['error'] ?? null, 'alimento criado por outro profissional: so ele ou o admin altera');
$r = alim(2, 'PUT', ['id' => $idBarra, 'energy_kcal' => 390]);
eq('o dono edita', $r['ok'] ?? null, true);
$r = alim(1, 'PUT', ['id' => $idBarra, 'energy_kcal' => 395], [], ['admin' => true]);
eq('o admin edita qualquer um', $r['ok'] ?? null, true);
$r = alim(1, 'DELETE', [], ['id' => $idBarra]);
eq('profissional 1 NÃO apaga alimento do 2', $r['error'] ?? null, 'alimento criado por outro profissional: so ele ou o admin apaga');
$r = alim(1, 'GET', [], [], ['aluno' => true]);
eq('aluno não usa o catálogo', $r['error'] ?? null, 'apenas o profissional usa o catalogo de alimentos');

echo "\n── Medidas caseiras da equipe ──\n";
$m1 = med(1, 'POST', ['food_id' => 489, 'nome' => 'Ovo grande', 'gramas' => 60]);
eq('cria medida para um alimento da TACO', [$m1['ok'] ?? null, $m1['food_id'] ?? null, $m1['gramas'] ?? null, $m1['meu'] ?? null], [true, 489, 60.0, true]);
$m2 = med(2, 'POST', ['food_id' => 489, 'nome' => 'ovo GRANDE', 'gramas' => 62]);
eq('mesmo nome (sem diferenciar maiúscula) atualiza o peso e não duplica', [$m2['idmedida'] ?? null, $m2['gramas'] ?? null], [$m1['idmedida'] ?? 0, 62.0]);
$m3 = med(2, 'POST', ['food_id' => 1000001, 'nome' => 'Scoop', 'gramas' => 30]);
$m4 = med(2, 'POST', ['food_id' => 1000001, 'nome' => 'Colher medida', 'gramas' => 10]);
$todas = med(1, 'GET');
eq('lista traz as 3 medidas, de todos', array_map(fn($x) => $x['food_id'] . ':' . $x['nome'], $todas), ['489:Ovo grande', '1000001:Colher medida', '1000001:Scoop']);
eq('gramas inválidas', (med(1, 'POST', ['food_id' => 489, 'nome' => 'x', 'gramas' => 0]))['error'] ?? null, 'informe alimento, nome e gramas (maior que zero)');
eq('nome em branco', (med(1, 'POST', ['food_id' => 489, 'nome' => '  ', 'gramas' => 5]))['error'] ?? null, 'informe alimento, nome e gramas (maior que zero)');
eq('alimento ausente', (med(1, 'POST', ['nome' => 'x', 'gramas' => 5]))['error'] ?? null, 'informe alimento, nome e gramas (maior que zero)');
eq('peso absurdo', (med(1, 'POST', ['food_id' => 489, 'nome' => 'x', 'gramas' => 99999]))['error'] ?? null, 'informe alimento, nome e gramas (maior que zero)');
$r = med(1, 'DELETE', [], ['id' => $m3['idmedida']]);
eq('outro profissional NÃO apaga medida alheia', $r['error'] ?? null, 'medida criada por outro profissional: so ele ou o admin apaga');
$r = med(2, 'DELETE', [], ['id' => $m3['idmedida']]);
eq('o dono apaga a própria', $r['ok'] ?? null, true);
$r = med(1, 'DELETE', [], ['id' => $m4['idmedida']], ['admin' => true]);
eq('o admin apaga qualquer uma', $r['ok'] ?? null, true);
eq('aluno não vê medidas da equipe', (med(1, 'GET', [], [], ['aluno' => true]))['error'] ?? null, 'apenas a equipe');

echo "\n── Apagar o alimento leva as medidas dele ──\n";
$novo = alim(1, 'POST', ['description' => 'Pasta teste']);
$idNovo = $novo['id'] ?? 0;
med(1, 'POST', ['food_id' => 1000000 + $idNovo, 'nome' => 'Colher', 'gramas' => 15]);
alim(1, 'DELETE', [], ['id' => $idNovo]);
eq('medida do alimento apagado some junto', count(array_filter(med(1, 'GET'), fn($x) => $x['food_id'] === 1000000 + $idNovo)), 0);

@unlink($tmp);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
