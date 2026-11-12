<?php
// healthcheck.php (app/api) é SOMENTE LEITURA: roda o arquivo de verdade (só a conexão MySQL é trocada por SQLite) contra um banco com
// exercícios de nome repetido, fichas sem aluno e treinos sem ficha, e confere que NENHUMA tabela muda (linhas e conteúdo), que os
// problemas são só relatados, e que a porta de entrada continua só para administrador. Também varre o código: sem ->exec() e sem
// comando de escrita (DELETE, UPDATE, INSERT, DROP...) em nenhum texto de SQL. Antes (até 11/11/2026) o arquivo apagava dados
// a cada chamada de administrador.
//   php [-c <php.ini com pdo_sqlite>] testes/healthcheck.php     (INTUS_HC_PATH=<arquivo> confere outra cópia, para quebrar de propósito)
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }

$SRC = getenv('INTUS_HC_PATH') ?: realpath(__DIR__ . '/../app/api/healthcheck.php');
if (!$SRC || !is_file($SRC)) { fwrite(STDERR, "healthcheck.php nao encontrado\n"); exit(1); }
$cod = file_get_contents($SRC);

/** Varredura do código: textos de SQL com comando de escrita e chamadas de ->exec(). Devolve a lista de achados. */
function achadosEscrita($codigo) {
    $a = []; $t = token_get_all($codigo);
    foreach ($t as $k => $tok) {
        if (!is_array($tok)) continue;
        if (in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/\b(DELETE|UPDATE|INSERT|REPLACE|DROP|ALTER|TRUNCATE|RENAME|GRANT)\b/i', $tok[1], $m)) $a[] = 'texto com ' . strtoupper($m[1]);
        if ($tok[0] === T_STRING && strtolower($tok[1]) === 'exec' && isset($t[$k - 1]) && is_array($t[$k - 1]) && $t[$k - 1][0] === T_OBJECT_OPERATOR) $a[] = '->exec()';
    }
    return array_values(array_unique($a));
}

// Troca só a conexão (MySQL) por SQLite; o resto do arquivo, inclusive a guarda de administrador, é o de produção.
$ini = strpos($cod, '// Config DB (mesma do sistema)');
$fim = strpos($cod, '// ── PORTA DE ENTRADA');
if ($ini === false || $fim === false || $fim < $ini) { fwrite(STDERR, "estrutura do healthcheck.php mudou: marcadores da conexao nao encontrados\n"); exit(1); }
$conexao = "// (teste) conexao trocada por SQLite\n\$pdo = new PDO('sqlite:' . getenv('HC_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);\n\n";
$teste = substr($cod, 0, $ini) . $conexao . substr($cod, $fim);

$raiz = sys_get_temp_dir() . '/intus_hc_' . getmypid();
@mkdir("$raiz/app/api", 0777, true);
register_shutdown_function(function () use ($raiz) {
    foreach (['app/api/healthcheck.php', 'app/api/_sessions.php', 'run.php'] as $f) @unlink("$raiz/$f");
    foreach (glob("$raiz/app/logs/*") ?: [] as $f) @unlink($f);
    foreach (glob("$raiz/*.sqlite") ?: [] as $f) @unlink($f);
    @rmdir("$raiz/app/logs"); @rmdir("$raiz/app/api"); @rmdir("$raiz/app"); @rmdir($raiz);
});
// Sessões de mentira, no mesmo formato que validateSession() devolve.
file_put_contents("$raiz/app/api/_sessions.php", "<?php\nfunction validateSession(PDO \$pdo, string \$t) {\n \$m = ['tok-admin' => ['user_id' => 1, 'user_type' => 'professor', 'admin' => true, 'legacy' => false],\n  'tok-prof' => ['user_id' => 7, 'user_type' => 'professor', 'admin' => false, 'legacy' => false],\n  'tok-aluno' => ['user_id' => 3, 'user_type' => 'aluno', 'admin' => false, 'legacy' => false],\n  'tok-legado' => ['user_id' => 1, 'user_type' => 'professor', 'admin' => true, 'legacy' => true]];\n return \$m[\$t] ?? null;\n}\n");
file_put_contents("$raiz/run.php", "<?php\nif ((string)getenv('HC_AUTH') !== '') \$_SERVER['HTTP_AUTHORIZATION'] = getenv('HC_AUTH');\nregister_shutdown_function(function () { fwrite(STDERR, '__STATUS__=' . (http_response_code() ?: 200)); });\nrequire __DIR__ . '/app/api/healthcheck.php';\n");
$phpIni = php_ini_loaded_file();
file_put_contents("$raiz/app/api/healthcheck.php", $teste);

function rodar($raiz, $db, $token) {
    global $phpIni;
    $env = ['HC_DB' => $db, 'HC_AUTH' => $token === null ? '' : "Bearer $token", 'PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot')];
    $cmd = [PHP_BINARY]; if ($phpIni) { $cmd[] = '-c'; $cmd[] = $phpIni; } $cmd[] = "$raiz/run.php";
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    preg_match('/__STATUS__=(\d+)/', $err, $m);
    return ['status' => (int)($m[1] ?? 0), 'json' => json_decode($out, true), 'cru' => $out . $err];
}
/** Impressão digital de TODAS as tabelas: nome, linhas e o conteúdo (qualquer mudança altera o texto). */
function digital($db) {
    $pdo = new PDO('sqlite:' . $db); $r = [];
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $linhas = $pdo->query("SELECT * FROM \"$t\" ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        $r[$t] = count($linhas) . ':' . md5(serialize($linhas));
    }
    return $r;
}
function banco($raiz, $nome, $sujo) {
    $db = "$raiz/$nome.sqlite"; @unlink($db);
    $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY, nome TEXT)");
    $pdo->exec("CREATE TABLE intus_exercicio (idexercicio INTEGER PRIMARY KEY, nmexercicio TEXT)");
    $pdo->exec("CREATE TABLE intus_ficha (idficha INTEGER PRIMARY KEY, idatleta INTEGER)");
    $pdo->exec("CREATE TABLE intus_treino (idtreino INTEGER PRIMARY KEY, idficha INTEGER, nmexercicio TEXT)");
    $pdo->exec("CREATE TABLE intus_sessao (idsessao INTEGER PRIMARY KEY, idatleta INTEGER, duracao_seg INTEGER)");
    $pdo->exec("INSERT INTO atleta VALUES (1,'Ana'),(2,'Bia')");
    $pdo->exec("INSERT INTO intus_ficha VALUES (1,1),(2,2)");
    $pdo->exec("INSERT INTO intus_treino VALUES (1,1,'Supino'),(2,2,'Remada')");
    $pdo->exec("INSERT INTO intus_sessao VALUES (1,1,3000),(2,2,2400)");
    if ($sujo) {
        // "Supino reto" 3x (caixa e espaços diferentes), "Agachamento" 2x; NULL e vazio não contam como duplicata
        $pdo->exec("INSERT INTO intus_exercicio VALUES (1,'Supino reto'),(2,'supino reto '),(3,'SUPINO RETO'),(4,'Agachamento'),(5,'Agachamento'),(6,'Remada'),(7,NULL),(8,'')");
        $pdo->exec("INSERT INTO intus_ficha VALUES (3,99)");                          // ficha sem aluno
        $pdo->exec("INSERT INTO intus_treino VALUES (3,50,'Orfao A'),(4,51,'Orfao B')"); // treinos sem ficha (o arquivo antigo os apagava)
    } else {
        $pdo->exec("INSERT INTO intus_exercicio VALUES (1,'Supino reto'),(2,'Agachamento'),(3,'Remada')");
    }
    return $db;
}
function acao($j, $check) { foreach (($j['actions'] ?? []) as $a) if (($a['check'] ?? '') === $check) return $a; return null; }

echo "\n══ Porta de entrada (nada disso toca o banco) ══\n";
$db = banco($raiz, 'sujo', true); $antes = digital($db);
$r = rodar($raiz, $db, null);       eq('sem token: 401', $r['status'] === 401 && ($r['json']['error'] ?? '') === 'token ausente', $r['cru']);
$r = rodar($raiz, $db, 'qualquer'); eq('token que não é sessão: 401', $r['status'] === 401, $r['cru']);
$r = rodar($raiz, $db, 'tok-legado'); eq('token legado: 401', $r['status'] === 401, $r['cru']);
$r = rodar($raiz, $db, 'tok-prof'); eq('professor que não é administrador: 403', $r['status'] === 403, $r['cru']);
$r = rodar($raiz, $db, 'tok-aluno'); eq('aluno: 403', $r['status'] === 403, $r['cru']);
eq('nenhuma dessas chamadas mexeu no banco', digital($db) === $antes);
eq('e nenhuma gravou linha de log', !glob("$raiz/app/logs/*"));

echo "\n══ Administrador: só relata, não apaga ══\n";
$r = rodar($raiz, $db, 'tok-admin'); $j = $r['json'];
eq('200 e JSON válido', $r['status'] === 200 && is_array($j), $r['cru']);
eq('marcado como somente leitura', ($j['modo'] ?? '') === 'somente_leitura');
$d = acao($j, 'exercicios_duplicados');
eq('relata 2 grupos de exercício repetido (NULL e vazio não contam) e 3 linhas excedentes', $d && $d['grupos'] === 2 && $d['excedentes'] === 3, json_encode($d));
eq('a amostra traz o nome e as cópias', $d && ($d['amostra'][0]['nome'] ?? '') === 'supino reto' && ($d['amostra'][0]['copias'] ?? 0) === 3, json_encode($d));
eq('relata 1 ficha sem aluno', (acao($j, 'fichas_orfas')['count'] ?? null) === 1);
eq('relata 2 treinos sem ficha (sem apagar)', (acao($j, 'treinos_orfaos')['count'] ?? null) === 2, json_encode(acao($j, 'treinos_orfaos')));
eq('os totais são os de ANTES: 8 exercícios, 3 fichas, 4 treinos, 2 alunos', ($j['totals'] ?? null) === ['exercicios' => 8, 'fichas' => 3, 'treinos' => 4, 'atletas' => 2], json_encode($j['totals'] ?? null));
eq('com problema a vista, o status é issues_found', ($j['status'] ?? '') === 'issues_found');
eq('NENHUMA tabela mudou (linhas e conteúdo)', digital($db) === $antes, json_encode(array_diff_assoc(digital($db), $antes)));
$r2 = rodar($raiz, $db, 'tok-admin'); $r3 = rodar($raiz, $db, 'tok-admin');
eq('chamar de novo não muda nada e dá o mesmo resultado', digital($db) === $antes && $r2['json']['totals'] === $j['totals'] && $r3['json']['totals'] === $j['totals']);
$logs = glob("$raiz/app/logs/healthcheck-*.log"); $linhas = $logs ? file($logs[0], FILE_IGNORE_NEW_LINES) : [];
eq('cada chamada de administrador gravou uma linha de log (só contagens)', count($linhas) === 3 && is_array(json_decode(substr(strstr($linhas[0], ' | '), 3), true)));

echo "\n══ Banco sem problemas ══\n";
$db2 = banco($raiz, 'limpo', false); $a2 = digital($db2);
$r = rodar($raiz, $db2, 'tok-admin');
eq('status ok e nada relatado', ($r['json']['status'] ?? '') === 'ok' && acao($r['json'], 'exercicios_duplicados')['grupos'] === 0 && acao($r['json'], 'treinos_orfaos')['count'] === 0, $r['cru']);
eq('banco limpo também fica idêntico', digital($db2) === $a2);

echo "\n══ Varredura do código ══\n";
$ach = achadosEscrita($cod);
eq('healthcheck.php não tem ->exec() nem comando de escrita em texto de SQL', !$ach, implode(', ', $ach));

echo "\n══ O próprio teste acusa uma regressão ══\n";
// Reintroduz uma limpeza escondida no código de teste e confere que a impressão digital e a varredura percebem.
$mut = str_replace('// 4. CONTAGENS GERAIS', "\$pdo->exec(\"DELETE FROM intus_treino WHERE idficha NOT IN (SELECT idficha FROM intus_ficha)\");\n// 4. CONTAGENS GERAIS", $teste);
eq('(mutação) o texto injetado de fato entrou', $mut !== $teste);
file_put_contents("$raiz/app/api/healthcheck.php", $mut);
$db3 = banco($raiz, 'mut', true); $a3 = digital($db3);
rodar($raiz, $db3, 'tok-admin');
eq('(mutação) a impressão digital percebe que a tabela de treinos mudou', digital($db3) !== $a3);
eq('(mutação) a varredura do código percebe o DELETE e o ->exec()', in_array('texto com DELETE', achadosEscrita($mut), true) && in_array('->exec()', achadosEscrita($mut), true));

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
