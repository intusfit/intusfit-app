<?php
// Endpoint catalogo.php?action=figurinhas_config rodando de verdade em PHP, com SQLite.
// Garante: corpo vazio ou sem a lista é recusado (antes virava "[]" e apagava as figurinhas), a lista de antes fica guardada
// a cada gravação e só o administrador lê essa cópia. Extrai o bloco do endpoint sem mexer no arquivo; cada caso roda num processo próprio.
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/figurinhas_servidor.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = file_get_contents($SRC);
$i = strpos($cod, "if (\$action === 'figurinhas_config') {");
$j = strpos($cod, '// ═══════════════ CONQUISTAS', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco do endpoint nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_config (chave TEXT PRIMARY KEY, valor TEXT)");
    $method = $caso['metodo'];
    $action = 'figurinhas_config';
    $_GET = $caso['get'] ?? [];
    $_ctx = ['admin' => !empty($caso['admin']), 'idusuario' => 1];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    $_negar = function ($cod, $msg) { http_response_code($cod); echo json_encode(['error' => $msg, 'status' => $cod]); exit; };
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_fig_' . getmypid() . '.sqlite';
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
function valor($chave) {
    global $tmp;
    $pdo = new PDO('sqlite:' . $tmp);
    $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = ?"); $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? null : json_decode($v, true);
}
$it = function ($n) { return array_map(function ($k) { return ['tipo' => 'img', 'src' => "/app/img/uploads/fig_$k.jpg", 'legenda' => '']; }, range(1, $n)); };

echo "\n══ Gravar ══\n";
eq('lista nova é gravada', rodar(['metodo' => 'PUT', 'admin' => true, 'corpo' => ['itens' => $it(5)]]), ['ok' => true]);
eq('e lida de volta com as 5', count(valor('figurinhas_config')['itens']), 5);
eq('1ª gravação: ainda não há lista anterior', valor('figurinhas_config_anterior'), null);

foreach (['corpo vazio' => [], 'sem "itens"' => ['ok' => true], '"itens" que não é lista' => ['itens' => 'x']] as $rot => $corpo) {
    $r = rodar(['metodo' => 'PUT', 'admin' => true, 'corpo' => $corpo]);
    eq("$rot é recusado (422)", isset($r['ok']) && $r['ok'] === false, true);
    eq("$rot não mexe na lista salva", count(valor('figurinhas_config')['itens']), 5);
}

echo "\n══ A lista de antes fica guardada ══\n";
rodar(['metodo' => 'PUT', 'admin' => true, 'corpo' => ['itens' => $it(2)]]);
eq('a nova vale', count(valor('figurinhas_config')['itens']), 2);
eq('a anterior (5) ficou guardada', count(valor('figurinhas_config_anterior')['itens']), 5);
rodar(['metodo' => 'PUT', 'admin' => true, 'corpo' => ['itens' => $it(2)]]);
eq('gravar a mesma lista de novo não perde a anterior', count(valor('figurinhas_config_anterior')['itens']), 5);
rodar(['metodo' => 'PUT', 'admin' => true, 'corpo' => ['itens' => []]]);
eq('lista vazia de propósito é válida e guarda a de 2 como anterior', [count(valor('figurinhas_config')['itens']), count(valor('figurinhas_config_anterior')['itens'])], [0, 2]);

echo "\n══ Ler a cópia anterior ══\n";
rodar(['metodo' => 'PUT', 'admin' => true, 'corpo' => ['itens' => $it(7)]]);
rodar(['metodo' => 'PUT', 'admin' => true, 'corpo' => ['itens' => $it(4)]]);
eq('administrador lê a anterior (7)', count(rodar(['metodo' => 'GET', 'admin' => true, 'get' => ['anterior' => '1']])['itens']), 7);
$r = rodar(['metodo' => 'GET', 'admin' => false, 'get' => ['anterior' => '1']]);
eq('quem não é administrador recebe 403', $r['status'] ?? null, 403);
eq('a leitura normal segue devolvendo a lista atual (4)', count(rodar(['metodo' => 'GET', 'admin' => false])['itens']), 4);

@unlink($tmp);
echo "\n$ok ok, $ruim falhas\n";
exit($ruim ? 1 : 0);
