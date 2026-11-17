<?php
// Endpoint catalogo.php?action=nutri_biblioteca (grupos alimentares e refeições modelo da equipe) rodando de verdade em PHP, com SQLite.
// Extrai do catalogo.php o bloco do endpoint (sem mexer no arquivo) e executa cada caso num processo próprio, porque o endpoint termina com exit.
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/biblioteca.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = file_get_contents($SRC);
$i = strpos($cod, '// ═══════════════ NUTRIÇÃO: biblioteca da equipe');
$j = strpos($cod, '// ═══════════════ NUTRIÇÃO: receitas, vídeos e dicas', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco do endpoint nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);

if (getenv('CASO_JSON') !== false) {
    // ── processo de um caso ──
    $caso = json_decode(file_get_contents(getenv('CASO_JSON')), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_nutri_biblioteca (idbib INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL, titulo TEXT NOT NULL, ativo INTEGER NOT NULL DEFAULT 1, dados TEXT NULL, idprofessor INTEGER NOT NULL DEFAULT 0, created_at TEXT NULL, updated_at TEXT NULL)");
    $action = 'nutri_biblioteca';
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $_ehAluno = !empty($caso['aluno']);
    $_ctx = ['admin' => !empty($caso['admin']), 'idusuario' => $caso['prof'] ?? 1, 'is_aluno' => $_ehAluno];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    $_negar = function ($c, $m) { http_response_code($c); echo json_encode(['error' => $m]); exit; };
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_biblioteca_' . getmypid() . '.sqlite';
@unlink($tmp);
$ok = 0; $ruim = 0;
function rodar($caso) {
    global $tmp, $ini;
    $caso['db'] = $tmp;
    // o caso vai por arquivo: variável de ambiente no Windows passa de 32 mil caracteres e o teste da refeição gigante estoura
    $arq = sys_get_temp_dir() . '/intus_biblioteca_caso_' . getmypid() . '.json';
    file_put_contents($arq, json_encode($caso));
    $env = ['CASO_JSON' => $arq, 'PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot')];
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' 2>&1';
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    @unlink($arq);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['_cru' => $out];
}
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido == $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}

echo "\n── Grupo alimentar ──\n";
$r = rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'grupo', 'titulo' => 'Carboidratos do café', 'dados' => ['funcao' => 'cho', 'lixo' => 'x',
    'itens' => [['food_id' => 12, 'nome' => 'Pão francês'], ['food_id' => 0, 'nome' => 'sem id'], ['food_id' => 15, 'nome' => ''], ['food_id' => 100050, 'nome' => 'Granola'], 'texto solto']]]]);
eq('cria o grupo', $r['ok'] ?? null, true);
$id1 = $r['idbib'] ?? 0;
$g = rodar(['metodo' => 'GET']);
$it = $g['itens'][0] ?? [];
eq('volta com a função em maiúsculas e só os alimentos válidos', [$it['dados']['funcao'] ?? null, count($it['dados']['itens'] ?? []), array_key_exists('lixo', $it['dados'] ?? [])], ['CHO', 2, false]);
eq('quem criou vê meu = true', $it['meu'] ?? null, true);
$r = rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'grupo', 'titulo' => 'Grupo sem função', 'dados' => ['funcao' => 'QUALQUER', 'itens' => [['food_id' => 1, 'nome' => 'Arroz']]]]]);
$g = rodar(['metodo' => 'GET', 'get' => ['tipo' => 'grupo']]);
$semf = null; foreach ($g['itens'] as $x) if ($x['titulo'] === 'Grupo sem função') $semf = $x;
eq('função desconhecida vira MISTO', $semf['dados']['funcao'] ?? null, 'MISTO');
eq('grupo sem nenhum alimento é recusado', (rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'grupo', 'titulo' => 'Vazio', 'dados' => ['itens' => []]]]))['error'] ?? null, 'o grupo precisa de pelo menos um alimento');

echo "\n── Refeição modelo ──\n";
$r = rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'refeicao', 'titulo' => 'Café proteico', 'dados' => ['nome' => 'Café da manhã', 'horario' => '07:30', 'nota' => 'sem açúcar',
    'itens_estruturados' => [['nome' => 'Ovo cozido', 'quantidade' => 2, 'gramas' => 100, 'nutrientes' => ['energy_kcal' => 156]], 'solto']]]]);
eq('cria a refeição modelo', $r['ok'] ?? null, true);
$g = rodar(['metodo' => 'GET', 'get' => ['tipo' => 'refeicao']]);
$rf = $g['itens'][0] ?? [];
eq('só entram itens em formato de objeto e o conteúdo volta igual', [count($rf['dados']['itens_estruturados'] ?? []), $rf['dados']['itens_estruturados'][0]['nutrientes']['energy_kcal'] ?? null, $rf['dados']['horario'] ?? null], [1, 156, '07:30']);
eq('o filtro ?tipo=refeicao traz só ela', count($g['itens'] ?? []), 1);

echo "\n── Validações ──\n";
eq('tipo inválido', (rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'banner', 'titulo' => 'x']]))['error'] ?? null, 'tipo invalido');
eq('título obrigatório', (rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'refeicao', 'titulo' => '  ']]))['error'] ?? null, 'titulo obrigatorio');
$grande = []; for ($k = 0; $k < 40; $k++) $grande[] = ['nome' => str_repeat('a', 3000)];
eq('refeição grande demais é recusada', (rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'refeicao', 'titulo' => 'Gigante', 'dados' => ['itens_estruturados' => $grande]]]))['error'] ?? null, 'item grande demais');
eq('PUT de id que não existe', (rodar(['metodo' => 'PUT', 'corpo' => ['idbib' => 9999, 'titulo' => 'x']]))['error'] ?? null, 'nao encontrado');
eq('aluno não lê', (rodar(['metodo' => 'GET', 'aluno' => true]))['error'] ?? null, 'apenas a equipe');
eq('aluno não grava', (rodar(['metodo' => 'POST', 'aluno' => true, 'corpo' => ['tipo' => 'grupo', 'titulo' => 'x']]))['error'] ?? null, 'apenas a equipe');

echo "\n── Quem pode mudar ──\n";
$r = rodar(['metodo' => 'PUT', 'prof' => 2, 'corpo' => ['idbib' => $id1, 'titulo' => 'Roubado']]);
eq('outro profissional não edita o item do colega', $r['error'] ?? null, 'item criado por outro profissional: so ele ou o admin altera');
$r = rodar(['metodo' => 'DELETE', 'prof' => 2, 'get' => ['id' => $id1]]);
eq('outro profissional não apaga o item do colega', $r['error'] ?? null, 'item criado por outro profissional: so ele ou o admin altera');
$g = rodar(['metodo' => 'GET', 'prof' => 2, 'get' => ['tipo' => 'grupo']]);
$visto = null; foreach ($g['itens'] as $x) if ((int)$x['idbib'] === (int)$id1) $visto = $x;
eq('o colega vê o item (equipe toda enxerga) com meu = false', [$visto['titulo'] ?? null, $visto['meu'] ?? null], ['Carboidratos do café', false]);
$r = rodar(['metodo' => 'PUT', 'admin' => true, 'prof' => 9, 'corpo' => ['idbib' => $id1, 'titulo' => 'Carboidratos do café (revisado)', 'ativo' => 0]]);
eq('o admin edita', $r['ok'] ?? null, true);
$r = rodar(['metodo' => 'PUT', 'corpo' => ['idbib' => $id1, 'dados' => ['funcao' => 'PRO', 'itens' => []]]]);
eq('editar o grupo deixando-o vazio é recusado', $r['error'] ?? null, 'o grupo precisa de pelo menos um alimento');
$r = rodar(['metodo' => 'PUT', 'corpo' => ['idbib' => $id1, 'dados' => ['funcao' => 'PRO', 'itens' => [['food_id' => 5, 'nome' => 'Frango']]]]]);
$g = rodar(['metodo' => 'GET', 'get' => ['tipo' => 'grupo']]);
$ed = null; foreach ($g['itens'] as $x) if ((int)$x['idbib'] === (int)$id1) $ed = $x;
eq('o dono troca os alimentos; título e ativo do admin continuam', [$ed['titulo'] ?? null, $ed['ativo'] ?? null, $ed['dados']['funcao'] ?? null, count($ed['dados']['itens'] ?? [])], ['Carboidratos do café (revisado)', 0, 'PRO', 1]);
$r = rodar(['metodo' => 'DELETE', 'get' => ['id' => $id1]]);
$g = rodar(['metodo' => 'GET', 'get' => ['tipo' => 'grupo']]);
eq('o dono apaga', array_map(fn($x) => $x['titulo'], $g['itens'] ?? []), ['Grupo sem função']);
eq('apagar um id que já não existe não dá erro', (rodar(['metodo' => 'DELETE', 'get' => ['id' => $id1]]))['ok'] ?? null, true);

@unlink($tmp);
echo "\n$ok ok, $ruim falha(s)\n";
exit($ruim ? 1 : 0);
