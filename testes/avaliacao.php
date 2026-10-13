<?php
// Endpoint catalogo.php?action=nutri_avaliacao (favorito, nota e "eu fiz" das receitas) rodando de verdade em PHP, com SQLite.
// Extrai do catalogo.php o bloco do endpoint (sem mexer no arquivo) e executa cada caso num processo próprio, porque o endpoint termina com exit.
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/avaliacao.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = file_get_contents($SRC);
$i = strpos($cod, '// ═══════════════ NUTRIÇÃO: avaliações das receitas');
$j = strpos($cod, '// Painel da nutri: planos ativos', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco do endpoint nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_nutri_conteudo (idconteudo INTEGER PRIMARY KEY, tipo TEXT, slug TEXT, titulo TEXT, ativo INTEGER DEFAULT 1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_nutri_avaliacao (idconteudo INTEGER NOT NULL, idatleta INTEGER NOT NULL, nota INTEGER NULL, fez INTEGER NOT NULL DEFAULT 0, fav INTEGER NOT NULL DEFAULT 0, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (idconteudo, idatleta))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_plano_nutricional (idplano INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, ativo INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_feed_pref (idatleta INTEGER, idalvo INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, feed_optin TEXT, stbloqueio TEXT)");
    if (!$pdo->query("SELECT COUNT(*) FROM atleta")->fetchColumn()) {
        $pdo->exec("INSERT INTO intus_nutri_conteudo (idconteudo, tipo, slug, titulo, ativo) VALUES (1,'receita','r1','Receita 1',1),(2,'receita','r2','Receita inativa',0),(3,'dica','d1','Dica',1)");
        $pdo->exec("INSERT INTO atleta VALUES (7,'Ana','S',NULL),(8,'Sem Plano','S',NULL),(9,'Bia','S',NULL),(10,'Caio Bloqueado','S','S'),(11,'Dani Fora do Feed','N',NULL),(12,'Eva','S',NULL)");
        $pdo->exec("INSERT INTO intus_plano_nutricional (idatleta, ativo) VALUES (7,1),(9,1),(10,1),(11,1),(12,1)");
        $pdo->exec("INSERT INTO intus_feed_pref VALUES (12, 9)");   // Eva ocultou a Bia
    }
    $action = 'nutri_avaliacao';
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $_ehAluno = !empty($caso['aluno']);
    $_ctx = ['idatleta' => $caso['eu'], 'idusuario' => 1];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    function _resolverAutorPub($pdo, $_ctx, $_ehAluno) { return [$_ehAluno ? 'aluno' : 'prof', (int)$_ctx['idatleta'], 'x']; }
    function _feedDetectarTabelaAtleta($pdo) { return 'atleta'; }
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_avaliacao_' . getmypid() . '.sqlite';
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
function post($eu, $corpo) { return rodar(['metodo' => 'POST', 'aluno' => true, 'eu' => $eu, 'corpo' => $corpo]); }
function ver($eu, $get = [], $aluno = true) { return rodar(['metodo' => 'GET', 'aluno' => $aluno, 'eu' => $eu, 'get' => $get]); }

echo "\n── Quem pode avaliar ──\n";
eq('aluno sem plano alimentar não avalia', post(8, ['idconteudo' => 1, 'fav' => 1])['error'] ?? null, 'recurso do plano alimentar');
eq('equipe não avalia', rodar(['metodo' => 'POST', 'aluno' => false, 'eu' => 1, 'corpo' => ['idconteudo' => 1, 'nota' => 5]])['error'] ?? null, 'somente o aluno avalia');
eq('conteúdo inexistente', post(7, ['idconteudo' => 99, 'nota' => 5])['error'] ?? null, 'conteudo nao encontrado');
eq('conteúdo inativo não recebe avaliação', post(7, ['idconteudo' => 2, 'nota' => 5])['error'] ?? null, 'conteudo nao encontrado');
eq('dica não é avaliável', post(7, ['idconteudo' => 3, 'nota' => 5])['error'] ?? null, 'conteudo nao encontrado');
eq('sem idconteudo', post(7, ['nota' => 5])['error'] ?? null, 'idconteudo obrigatorio');

echo "\n── Favorito, nota e eu fiz ──\n";
$r = post(7, ['idconteudo' => 1, 'fav' => 1]);
eq('favorita', $r['minha'] ?? null, ['nota' => null, 'fez' => 0, 'fav' => 1]);
$r = post(7, ['idconteudo' => 1, 'nota' => 5]);
eq('dá nota 5 sem perder o favorito', $r['minha'] ?? null, ['nota' => 5, 'fez' => 0, 'fav' => 1]);
$r = post(7, ['idconteudo' => 1, 'nota' => 9]);
eq('nota fora de 1 a 5 limpa a nota', array_key_exists('nota', $r['minha'] ?? []) ? $r['minha']['nota'] : 'x', null);
post(7, ['idconteudo' => 1, 'nota' => 5]);
post(7, ['idconteudo' => 1, 'fez' => 1]);
post(9, ['idconteudo' => 1, 'nota' => 3, 'fez' => 1]);
post(10, ['idconteudo' => 1, 'fez' => 1]);
post(11, ['idconteudo' => 1, 'fez' => 1]);
$g = ver(12, ['idconteudo' => 1]);
eq('média, total de notas e quantos fizeram', $g['stats']['1'] ?? null, ['n' => 2, 'media' => 4.0, 'fizeram' => 4]);
eq('não traz as marcações de outra pessoa', $g['minhas'] ?? null, []);
eq('nomes: só quem está no Feed, não bloqueado e não ocultado por mim', array_map(fn($x) => $x['nome'], $g['nomes'] ?? []), ['Ana']);
$g7 = ver(7, ['idconteudo' => 1]);
eq('minhas marcações voltam para a própria pessoa', $g7['minhas']['1'] ?? null, ['nota' => 5, 'fez' => 1, 'fav' => 1]);
$n7 = array_map(fn($x) => $x['nome'], ver(7, ['idconteudo' => 1])['nomes'] ?? []); sort($n7);
eq('quem não ocultou ninguém vê Ana e Bia (o próprio nome aparece: é público)', $n7, ['Ana', 'Bia']);
eq('equipe lê as estatísticas', (ver(1, [], false)['stats']['1']['fizeram'] ?? null), 4);

echo "\n── Remover ──\n";
post(9, ['idconteudo' => 1, 'nota' => 0, 'fez' => 0]);
$g = ver(12, ['idconteudo' => 1]);
eq('tirar nota e fiz apaga a linha (sai da conta)', [$g['stats']['1']['n'] ?? null, $g['stats']['1']['fizeram'] ?? null], [1, 3]);

@unlink($tmp);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
