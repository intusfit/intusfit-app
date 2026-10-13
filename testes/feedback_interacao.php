<?php
// Reação e comentário nos feedbacks em vídeo (feedbacks.php): o aluno reage e comenta pelo link OU pelo app (mesmo token, um reflete no outro),
// a equipe responde, o sino do outro lado é avisado, comentário alheio não se apaga e o limite de reações vale. Extrai o bloco das rotas
// públicas do arquivo (sem mexer nele) e roda cada caso num processo próprio (as rotas terminam com exit).
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/feedback_interacao.php
$SRC = realpath(__DIR__ . '/../app/api/feedbacks.php');
$cod = str_replace("\r\n", "\n", file_get_contents($SRC));
function trecho($cod, $ini, $fim) {
    $i = strpos($cod, $ini); $j = $i === false ? false : strpos($cod, $fim, $i);
    if ($i === false || $j === false) { fwrite(STDERR, "trecho nao encontrado: $ini\n"); exit(1); }
    return substr($cod, $i, $j - $i);
}
$bloco = trecho($cod, "if (\$action === 'interacoes') {", '// ======================= COM LOGIN');
$ator = trecho($cod, 'function _fbAtor(', '// Reação e nº de comentários');

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_feedback_interacao (id INTEGER PRIMARY KEY AUTOINCREMENT, idfeedback INT, tipo TEXT, autor_tipo TEXT, autor_nome TEXT, texto TEXT, criado_em TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aviso (destino_tipo TEXT, destino_id INT, tipo TEXT, ator_tipo TEXT, alvo_tipo TEXT, alvo_id INT)");
    define('FB_REACOES', ['❤️', '🔥', '👏', '💪', '🙏', '👍']);
    $GLOBALS['__bearer'] = $caso['equipe'] ? 'tok' : '';
    function bearerToken() { return $GLOBALS['__bearer']; }
    function getAuthContext($pdo, $t) { return ['idusuario' => 3, 'nmusuario' => 'Luiz', 'is_aluno' => false]; }
    function _fbResponder($c, $d) { http_response_code($c); echo json_encode($d); exit; }
    function _fbPorToken($pdo, $t) { return $t === 'ok' ? ['id' => 10, 'idprofessor' => 3, 'idatleta' => 7, 'aluno_nome' => 'Ana Souza'] : null; }
    function checkRateLimit() { return true; }
    function _criarNotificacao($pdo, $dt, $di, $tipo, $at, $ai, $an, $alvoTipo, $alvoId, $texto = '') {
        $pdo->prepare("INSERT INTO aviso VALUES (?,?,?,?,?,?)")->execute([$dt, $di, $tipo, $at, $alvoTipo, $alvoId]);
    }
    $ator = str_replace("require_once __DIR__ . '/_auth_context.php';", '', $ator);
    $bloco = str_replace("file_get_contents('php://input')", "\$GLOBALS['__corpo']", $bloco);
    eval($ator);
    $_SERVER['REQUEST_METHOD'] = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $action = $caso['action'];
    $GLOBALS['__corpo'] = json_encode($caso['corpo'] ?? []);
    ob_start();
    try { eval($bloco); } catch (Throwable $e) { echo json_encode(['_erro' => $e->getMessage()]); }
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_fbint_' . getmypid() . '.sqlite';
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
function ler($v = 'ok', $equipe = false) { return rodar(['action' => 'interacoes', 'metodo' => 'GET', 'equipe' => $equipe, 'get' => ['v' => $v]]); }
function agir($corpo, $equipe = false, $acao = 'interagir') { return rodar(['action' => $acao, 'metodo' => 'POST', 'equipe' => $equipe, 'corpo' => $corpo]); }
function avisos() { global $tmp; $p = new PDO('sqlite:' . $tmp); return $p->query("SELECT destino_tipo, destino_id, tipo, ator_tipo FROM aviso ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC); }

echo "\n── Link e app veem a mesma coisa ──\n";
eq('link inválido', ler('xx')['erro'] ?? null, 'Este feedback não existe mais ou o link está incompleto.');
eq('começa sem reação nem comentários', [ler()['reacao'] ?? 'x', count(ler()['comentarios'] ?? [])], ['', 0]);
eq('o aluno reage (pelo link ou pelo app)', agir(['v' => 'ok', 'tipo' => 'reacao', 'emoji' => '🔥'])['reacao'] ?? null, '🔥');
eq('a reação aparece para quem abrir depois', ler()['reacao'] ?? null, '🔥');
eq('trocar a reação substitui a anterior', [agir(['v' => 'ok', 'tipo' => 'reacao', 'emoji' => '👏'])['reacao'] ?? null, ler()['reacao'] ?? null], ['👏', '👏']);
eq('emoji fora da lista é recusado', agir(['v' => 'ok', 'tipo' => 'reacao', 'emoji' => '💩'])['erro'] ?? null, 'reacao invalida');
eq('emoji vazio tira a reação', [agir(['v' => 'ok', 'tipo' => 'reacao', 'emoji' => ''])['ok'] ?? null, ler()['reacao'] ?? null], [true, '']);
eq('a equipe não reage no lugar do aluno', agir(['v' => 'ok', 'tipo' => 'reacao', 'emoji' => '👍'], true)['erro'] ?? null, 'so o aluno reage');

echo "\n── Comentários ──\n";
eq('o aluno comenta', agir(['v' => 'ok', 'tipo' => 'comentario', 'texto' => 'Obrigada, professor! Vou corrigir a postura.'])['ok'] ?? null, true);
eq('comentário vazio é recusado', agir(['v' => 'ok', 'tipo' => 'comentario', 'texto' => '   '])['erro'] ?? null, 'texto obrigatorio');
eq('a equipe responde', agir(['v' => 'ok', 'tipo' => 'comentario', 'texto' => 'Isso aí, manda o vídeo na quinta.'], true)['ok'] ?? null, true);
$l = ler();
eq('os dois comentários aparecem, só o primeiro tem o primeiro nome do aluno', [array_map(fn($c) => [$c['autor_tipo'], $c['autor_nome']], $l['comentarios']), $l['sou_equipe']], [[['aluno', 'Ana'], ['prof', 'Luiz']], false]);
eq('"meu" marca só o do aluno para quem abre pelo link', array_map(fn($c) => $c['meu'], $l['comentarios']), [true, false]);
eq('para a equipe é o contrário', array_map(fn($c) => $c['meu'], ler('ok', true)['comentarios']), [false, true]);
eq('o professor é avisado do comentário do aluno e o aluno, da resposta', array_map(fn($a) => [$a['destino_tipo'], $a['destino_id'], $a['tipo']], avisos()), [['prof', 3, 'comentario'], ['aluno', 7, 'resposta']]);
eq('reagir não gera aviso (só comentário)', count(avisos()), 2);

echo "\n── Apagar ──\n";
$idAluno = $l['comentarios'][0]['id']; $idEquipe = $l['comentarios'][1]['id'];
eq('o aluno não apaga o comentário da equipe', agir(['v' => 'ok', 'id' => $idEquipe], false, 'apagar_interacao')['erro'] ?? null, 'sem permissao');
eq('o aluno apaga o próprio', agir(['v' => 'ok', 'id' => $idAluno], false, 'apagar_interacao')['ok'] ?? null, true);
eq('a equipe apaga qualquer um', agir(['v' => 'ok', 'id' => $idEquipe], true, 'apagar_interacao')['ok'] ?? null, true);
eq('comentário que não existe', agir(['v' => 'ok', 'id' => 999], false, 'apagar_interacao')['erro'] ?? null, 'comentario nao encontrado');
eq('link inválido ao comentar', agir(['v' => 'zz', 'tipo' => 'comentario', 'texto' => 'oi'])['erro'] ?? null, 'Este feedback não existe mais ou o link está incompleto.');

@unlink($tmp);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
