<?php
// CORS da API (app/api/_cors.php) rodando de verdade: sobe o servidor embutido do PHP numa porta livre, com uma página que só inclui o
// _cors.php de produção, e confere o cabeçalho Access-Control-Allow-Origin para cada Origin.
// Garante: só o site oficial e as WebViews do app (Capacitor) são aceitos; as origens de desenvolvimento (http://localhost:3000 e :5500,
// retiradas em 11/11/2026) e qualquer outra NÃO são aceitas (a resposta volta com o domínio oficial, e o navegador bloqueia a leitura);
// nunca coringa.
//   php [-c <php.ini>] testes/cors.php        (INTUS_CORS_PATH=<arquivo> confere outra cópia do _cors.php, útil para quebrar de propósito)
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }

$cors = getenv('INTUS_CORS_PATH') ?: realpath(__DIR__ . '/../app/api/_cors.php');
if (!$cors || !is_file($cors)) { fwrite(STDERR, "_cors.php nao encontrado\n"); exit(1); }

// Pasta temporária com a página que só inclui o _cors.php (o mesmo arquivo de produção, sem cópia).
$dir = sys_get_temp_dir() . '/intus_cors_' . getmypid();
@mkdir($dir, 0777, true);
file_put_contents("$dir/index.php", "<?php\nrequire " . var_export($cors, true) . ";\necho 'ok';\n");

// Porta livre (o servidor embutido não aceita porta 0).
$s = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
if (!$s) { fwrite(STDERR, "sem porta livre em 127.0.0.1 ($en $es)\n"); exit(1); }
$porta = (int)substr(strrchr((string)stream_socket_get_name($s, false), ':'), 1);
fclose($s);

$ini = php_ini_loaded_file();
$cmd = [PHP_BINARY];
if ($ini) { $cmd[] = '-c'; $cmd[] = $ini; }
$cmd = array_merge($cmd, ['-S', "127.0.0.1:$porta", '-t', $dir]);
$nulo = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$proc = proc_open($cmd, [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $pipes);
register_shutdown_function(function () use ($proc, $dir) {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    @unlink("$dir/index.php"); @rmdir($dir);
});
$pronto = false;
for ($i = 0; $i < 40 && !$pronto; $i++) {
    $c = @stream_socket_client("tcp://127.0.0.1:$porta", $en, $es, 0.2);
    if ($c) { fclose($c); $pronto = true; } else { usleep(100000); }
}
if (!$pronto) { fwrite(STDERR, "o servidor embutido do PHP nao subiu na porta $porta\n"); exit(1); }

/** GET / com o cabeçalho Origin pedido (null = sem Origin). Devolve os cabeçalhos da resposta (minúsculos, em listas). */
function pedir($porta, $origem) {
    $f = @stream_socket_client("tcp://127.0.0.1:$porta", $en, $es, 3);
    if (!$f) return [];
    fwrite($f, "GET / HTTP/1.0\r\nHost: 127.0.0.1\r\n" . ($origem !== null ? "Origin: $origem\r\n" : '') . "\r\n");
    stream_set_timeout($f, 5);
    $resp = stream_get_contents($f);
    fclose($f);
    $cab = explode("\r\n\r\n", $resp, 2)[0];
    $h = [];
    foreach (explode("\r\n", $cab) as $l) {
        if (strpos($l, ':') === false) continue;
        [$k, $v] = explode(':', $l, 2);
        $h[strtolower(trim($k))][] = trim($v);
    }
    return $h;
}
$permitida = function ($h) { return $h['access-control-allow-origin'][0] ?? ''; };
$OFICIAL = 'https://intusfit.com.br';
$todas = [];

echo "\n══ Origens aceitas (site oficial e WebViews do app) ══\n";
foreach (['https://intusfit.com.br', 'https://localhost', 'capacitor://localhost', 'http://localhost'] as $o) {
    $h = pedir($porta, $o); $todas[] = $h;
    eq("$o é devolvida como está", $permitida($h) === $o, 'veio: ' . json_encode($h['access-control-allow-origin'] ?? null));
}

echo "\n══ Origens que NÃO podem ser aceitas ══\n";
foreach (['http://localhost:3000', 'http://localhost:5500', 'http://localhost:8099', 'http://127.0.0.1:5500', 'https://evil.example',
          'http://intusfit.com.br', 'https://intusfit.com.br.evil.example', 'https://evil-intusfit.com.br', 'http://localhost.evil.example', 'null'] as $o) {
    $h = pedir($porta, $o); $todas[] = $h;
    eq("$o não é devolvida (volta o domínio oficial)", $permitida($h) === $OFICIAL, 'veio: ' . json_encode($h['access-control-allow-origin'] ?? null));
}
$h = pedir($porta, null); $todas[] = $h;
eq('sem Origin: volta o domínio oficial', $permitida($h) === $OFICIAL, 'veio: ' . json_encode($h['access-control-allow-origin'] ?? null));

echo "\n══ O que vale para toda resposta ══\n";
$coringa = false; $semVary = false; $semNoStore = false;
foreach ($todas as $h) {
    if (in_array('*', $h['access-control-allow-origin'] ?? [], true)) $coringa = true;
    if (stripos(implode(',', $h['vary'] ?? []), 'origin') === false) $semVary = true;
    if (stripos(implode(',', $h['cache-control'] ?? []), 'no-store') === false) $semNoStore = true;
}
eq('nunca devolve coringa (*)', !$coringa && count($todas) > 10);
eq('toda resposta traz Vary: Origin (o navegador não reaproveita a resposta de outra origem)', !$semVary);
eq('toda resposta é no-store (nada de token ou dado em cache)', !$semNoStore);

echo "\n══ Guarda no próprio arquivo ══\n";
// Só os textos entre aspas contam: o comentário que explica a retirada cita as portas de propósito.
$textos = [];
foreach (token_get_all(file_get_contents($cors)) as $t) { if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) $textos[] = trim($t[1], "'\""); }
$comPorta = array_values(array_filter($textos, function ($x) { return preg_match('#^https?://(localhost|127\.0\.0\.1):\d+#', $x); }));
eq('nenhuma origem de desenvolvimento com porta na lista de produção', !$comPorta, implode(', ', $comPorta));
eq('a lista de produção ainda tem o site oficial e o app (Capacitor)', in_array('https://intusfit.com.br', $textos, true) && in_array('https://localhost', $textos, true) && in_array('capacitor://localhost', $textos, true));

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
