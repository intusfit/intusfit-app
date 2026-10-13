<?php
// Mural da Nutrição (dicas e vídeos) em catalogo.php?action=comentarios_pub, rodando de verdade em PHP com SQLite:
// quem pode escrever (aluno com plano alimentar ativo; equipe sempre), alvo que tem de existir, avisos (resposta ao autor do pai e
// aviso à equipe) e leitura aberta. Extrai o bloco do endpoint do catalogo.php e roda cada caso num processo próprio (o endpoint termina com exit).
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/mural_nutri.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = str_replace("
", "
", file_get_contents($SRC));   // o arquivo pode estar com fim de linha do Windows
$i = strpos($cod, "if (\$action === 'comentarios_pub') {");
$j = strpos($cod, '// ═══════════════ PESSOAS QUE PODEM SER MENCIONADAS', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco do endpoint nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);
$f1 = strpos($cod, 'function _nutriAlunoTemPlano');
$f2 = strpos($cod, "\n}\n", $f1);
$funcPlano = substr($cod, $f1, $f2 - $f1 + 3);

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_comentario_pub (idcomentario INTEGER PRIMARY KEY AUTOINCREMENT, alvo_tipo TEXT, alvo_id INTEGER, resposta_a INTEGER NULL, autor_tipo TEXT, autor_id INTEGER, autor_nome TEXT, texto TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_reacao_pub (alvo_tipo TEXT, alvo_id INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_nutri_conteudo (idconteudo INTEGER PRIMARY KEY, tipo TEXT, ativo INTEGER DEFAULT 1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_plano_nutricional (idplano INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, ativo INTEGER)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aviso (destino_tipo TEXT, destino_id INTEGER, tipo TEXT, alvo_tipo TEXT, alvo_id INTEGER)");
    if (!$pdo->query("SELECT COUNT(*) FROM intus_nutri_conteudo")->fetchColumn()) {
        $pdo->exec("INSERT INTO intus_nutri_conteudo VALUES (10,'video',1),(11,'video',0),(12,'receita',1)");
        $pdo->exec("INSERT INTO intus_plano_nutricional (idatleta, ativo) VALUES (7,1),(9,1)");
    }
    $action = 'comentarios_pub';
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $_ehAluno = !empty($caso['aluno']);
    $_ctx = ['idatleta' => $caso['eu'], 'idusuario' => $caso['eu']];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    function _resolverAutorPub($pdo, $_ctx, $_ehAluno) { return [$_ehAluno ? 'aluno' : 'prof', (int)$_ctx['idatleta'], $_ehAluno ? 'Aluno ' . $_ctx['idatleta'] : 'Nutri']; }
    function _mencoesLimitar($t, $n) { return mb_substr($t, 0, $n); }
    function _nomePublicoProf($id, $n) { return $n; }
    function _donoDoAlvoPub($pdo, $tipo, $alvo) { return 0; }
    function _notificarMencoes() {}
    function _resultadoDestinatariosProf($pdo, $id) { return [1, 5]; }
    function _criarNotificacao($pdo, $dt, $di, $tipo, $at, $ai, $an, $alvoTipo, $alvoId, $texto = '') {
        $pdo->prepare("INSERT INTO aviso VALUES (?,?,?,?,?)")->execute([$dt, $di, $tipo, $alvoTipo, $alvoId]);
    }
    eval($funcPlano);
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_mural_' . getmypid() . '.sqlite';
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
function escrever($eu, $aluno, $tipo, $alvo, $texto, $resp = 0) {
    $c = ['alvo_tipo' => $tipo, 'alvo_id' => $alvo, 'texto' => $texto]; if ($resp) $c['resposta_a'] = $resp;
    return rodar(['metodo' => 'POST', 'aluno' => $aluno, 'eu' => $eu, 'corpo' => $c]);
}
function avisos() {
    global $tmp;
    $pdo = new PDO('sqlite:' . $tmp);
    return $pdo->query("SELECT destino_tipo, destino_id, tipo, alvo_tipo FROM aviso ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC);
}

echo "\n── Quem escreve ──\n";
eq('aluno sem plano alimentar não escreve no mural de dicas', escrever(8, true, 'nutri_dica', 1, 'Minha dica')['error'] ?? null, 'mural do plano alimentar');
eq('aluno sem plano não escreve no mural do vídeo', escrever(8, true, 'nutri_video', 10, 'Dúvida')['error'] ?? null, 'mural do plano alimentar');
$r = escrever(7, true, 'nutri_dica', 1, 'Beber água antes das refeições ajuda.');
eq('aluno com plano escreve no mural de dicas', $r['ok'] ?? null, true);
$raiz = $r['idcomentario'] ?? 0;
eq('mural de dicas só existe com id 1', escrever(7, true, 'nutri_dica', 2, 'x')['error'] ?? null, 'mural nao encontrado');
eq('vídeo que não existe', escrever(7, true, 'nutri_video', 99, 'x')['error'] ?? null, 'video nao encontrado');
eq('vídeo inativo', escrever(7, true, 'nutri_video', 11, 'x')['error'] ?? null, 'video nao encontrado');
eq('receita não tem mural de vídeo', escrever(7, true, 'nutri_video', 12, 'x')['error'] ?? null, 'video nao encontrado');
eq('aluno com plano escreve no vídeo', escrever(7, true, 'nutri_video', 10, 'Quanto tempo na airfryer?')['ok'] ?? null, true);
$rp = escrever(1, false, 'nutri_dica', 1, 'Ótima dica! Obrigada por compartilhar.', $raiz);
eq('a equipe escreve sem precisar de plano', $rp['ok'] ?? null, true);

echo "\n── Avisos ──\n";
$av = avisos();
eq('o que o aluno escreve avisa a equipe (2 comentários x 2 pessoas)', count(array_filter($av, fn($a) => $a['destino_tipo'] === 'prof' && $a['tipo'] === 'comentario')), 4);
eq('a resposta da equipe avisa o autor do comentário', array_values(array_filter($av, fn($a) => $a['destino_tipo'] === 'aluno')), [['destino_tipo' => 'aluno', 'destino_id' => 7, 'tipo' => 'resposta', 'alvo_tipo' => 'nutri_dica']]);

echo "\n── Leitura e exclusão ──\n";
$g = rodar(['metodo' => 'GET', 'aluno' => true, 'eu' => 8, 'get' => ['alvo_tipo' => 'nutri_dica', 'ids' => '1']]);
eq('aluno sem plano lê o mural (2 comentários)', count($g['1'] ?? []), 2);
eq('resposta aponta para o comentário original', $g['1'][1]['resposta_a'] ?? null, $raiz);
eq('outro aluno não apaga comentário alheio', rodar(['metodo' => 'DELETE', 'aluno' => true, 'eu' => 9, 'get' => ['id' => $raiz]])['error'] ?? null, 'sem permissao');
eq('o autor apaga o próprio', rodar(['metodo' => 'DELETE', 'aluno' => true, 'eu' => 7, 'get' => ['id' => $raiz]])['ok'] ?? null, true);
$g = rodar(['metodo' => 'GET', 'aluno' => true, 'eu' => 8, 'get' => ['alvo_tipo' => 'nutri_dica', 'ids' => '1']]);
eq('apagar o comentário leva as respostas', count($g['1'] ?? []), 0);

@unlink($tmp);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
