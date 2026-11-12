<?php
// Escopo por professor no catalogo.php (achado S1, 11/11/2026). Roda o catalogo.php REAL, ponta a ponta, num processo por pedido, com SQLite:
// só a conexão com o banco, a leitura do corpo, o e-mail e o marcador de bootstrap são trocados (o texto do arquivo é adaptado em memória).
// Professor A não lê nem grava aluno do professor B; listas vêm filtradas à carteira; administrador e aluno seguem como eram.
// A última seção QUEBRA DE PROPÓSITO a guarda (arquivo adaptado sem ela) e confere que os mesmos pedidos passam a vazar: prova que o teste acusa.
//   php -c <ini com pdo_sqlite e mbstring> testes/escopo_professor.php
class PdoTeste extends PDO {
    // SQLite não tem SHOW COLUMNS (o getAuthContext e o getAtletasDoUsuario reais usam): vira pragma_table_info, com a coluna Field.
    #[\ReturnTypeWillChange]
    public function query(string $q, ?int $fetchMode = null, mixed ...$args) {
        if (preg_match('/^\s*SHOW\s+COLUMNS\s+FROM\s+`?(\w+)`?\s*$/i', $q, $m)) return parent::query("SELECT name AS Field FROM pragma_table_info('" . $m[1] . "')");
        return $fetchMode === null ? parent::query($q) : parent::query($q, $fetchMode, ...$args);
    }
}
$API = realpath(__DIR__ . '/../app/api');

// ─────────────── modo "um pedido": executa o catalogo.php real e imprime {status, corpo} ───────────────
if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $src = str_replace("\r\n", "\n", file_get_contents($API . '/catalogo.php'));
    $src = preg_replace('/^<\?php/', '', $src);
    $i = strpos($src, '// ---------- Conexao ----------'); $j = strpos($src, '// ---------- Bootstrap ----------');
    if ($i === false || $j === false) { fwrite(STDERR, "marcador de conexao nao encontrado\n"); exit(2); }
    $src = substr($src, 0, $i) . '$pdo = $GLOBALS["__pdo"];' . "\n" . substr($src, $j);
    $trocas = [
        "file_get_contents('php://input')" => "(\$GLOBALS['__corpo_cru'] ?? '')",
        '__DIR__' => var_export($API, true),
        'if (!is_file($_bootMarca)) {' => 'if (false) {',
        'function _catalogoSendEmail(string $to, string $subject, string $body) {' => 'function _catalogoSendEmail_orig(string $to, string $subject, string $body) {',
    ];
    foreach ($trocas as $de => $para) { if (strpos($src, $de) === false) { fwrite(STDERR, "trecho nao encontrado: $de\n"); exit(2); } $src = str_replace($de, $para, $src); }
    if (($caso['mutacao'] ?? '') === 'sem_guarda') {
        // Estado ANTERIOR à correção: sem a guarda de pedido, sem filtro de lista, sem conferir o dono do registro.
        $g = 'if ($_tokValido && !$_ehAluno && empty($_ctx[\'admin\']) && !in_array($action, INTUS_ESCOPO_ISENTAS, true)) {';
        if (strpos($src, $g) === false) { fwrite(STDERR, "guarda nao encontrada\n"); exit(2); }
        $src = str_replace($g, 'if (false) {', $src);
        $src = str_replace('intusEscopoCarteira($pdo, $_ctx)', 'null', $src);
    }
    $pdo = new PdoTeste('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
    $GLOBALS['__pdo'] = $pdo;
    $GLOBALS['__corpo_cru'] = $caso['corpo'] === null ? '' : json_encode($caso['corpo']);
    $GLOBALS['__emails'] = [];
    function _catalogoSendEmail(string $to, string $subject, string $body) { $GLOBALS['__emails'][] = $to; return true; }
    $_SERVER['REQUEST_METHOD'] = $caso['metodo'];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $caso['token'];
    $_GET = $caso['get'];
    ob_start();
    register_shutdown_function(function () { $corpo = ob_get_clean(); echo json_encode(['status' => http_response_code() ?: 200, 'corpo' => $corpo]); });
    $tmp = tempnam(sys_get_temp_dir(), 'esc') . '.php';
    file_put_contents($tmp, '<?php' . $src);
    require $tmp;
    exit;
}

// ─────────────── modo teste ───────────────
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido == $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$dir = sys_get_temp_dir() . '/intus_escopo_' . getmypid();
@mkdir($dir);
$seed = $dir . '/seed.sqlite';
$TOK = [];
(function () use ($seed, &$TOK) {
    $pdo = new PDO('sqlite:' . $seed);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([
        "CREATE TABLE professor (idusuario INTEGER PRIMARY KEY, nome TEXT, email TEXT, tpacesso TEXT, ativo TEXT DEFAULT 'S', permissoes TEXT)",
        "CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, professores_responsaveis TEXT, stbloqueio TEXT)",
        "CREATE TABLE intus_sessions (token_hash TEXT, user_id INTEGER, user_type TEXT, admin INTEGER DEFAULT 0, expires_at TEXT)",
        "CREATE TABLE intus_avaliacao (idavaliacao INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, dtavaliacao TEXT NOT NULL, peso REAL, altura REAL, percgordura REAL, observacao TEXT, medidas TEXT, metodo TEXT, fotos TEXT, created_at TEXT, updated_at TEXT)",
        "CREATE TABLE intus_plano_nutricional (idplano INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, titulo TEXT, objetivo TEXT, calorias INTEGER, proteina REAL, carboidrato REAL, gordura REAL, refeicoes TEXT, observacao TEXT, comentario_inicio TEXT, comentario_fim TEXT, ativo INTEGER DEFAULT 1, dtinicio TEXT, dtfim TEXT)",
        "CREATE TABLE intus_anamnese (idatleta INTEGER PRIMARY KEY, dados TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE intus_profile (idatleta INTEGER PRIMARY KEY, avatar TEXT, prefs TEXT)",
        "CREATE TABLE intus_avisos (idaviso INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, tipo TEXT, titulo TEXT, detalhe TEXT, lido INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE intus_nutri_registro (idregistro INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, idplano INTEGER, data TEXT, refeicao TEXT, status TEXT, obs TEXT, foto TEXT, resposta_nutri TEXT, respondido_em TEXT)",
        "CREATE TABLE intus_nutri_dia (idatleta INTEGER, data TEXT, agua_ml INTEGER)",
    ] as $q) $pdo->exec($q);
    $P = '{"av_incluir":true,"av_editar":true,"av_excluir":true,"nt_incluir":true,"nt_editar":true,"nt_excluir":true}';
    $pdo->exec("INSERT INTO professor (idusuario, nome, email, tpacesso, ativo, permissoes) VALUES
        (1,'Luiz Admin','luiz@x','A','S','{}'),(3,'Prof A','a@x','P','S','$P'),(4,'Prof B','b@x','P','S','$P'),(5,'Nutri C','c@x','P','S','$P'),(6,'Prof sem carteira','f@x','P','S','$P')");
    // 11 e 12 são do professor A (12 também da nutri C); 21 e 22 do B; 31 e 32 sem responsável; 41 do administrador.
    $pdo->exec("INSERT INTO atleta (idatleta, nome, professores_responsaveis) VALUES
        (11,'Ana','[3]'),(12,'Bia','[3,5]'),(21,'Caio','[4]'),(22,'Dani','[4]'),(31,'Eva','[]'),(32,'Fabi',NULL),(41,'Gil','[1]')");
    foreach ([11, 12, 21, 22, 31, 32, 41] as $a) {
        $pdo->exec("INSERT INTO intus_avaliacao (idatleta, dtavaliacao, peso, observacao) VALUES ($a,'2026-10-0" . ($a % 9 + 1) . "',60,'obs $a')");
        $pdo->exec("INSERT INTO intus_plano_nutricional (idatleta, titulo, ativo) VALUES ($a,'plano $a',1)");
        $pdo->exec("INSERT INTO intus_anamnese (idatleta, dados) VALUES ($a,'{\"doencas\":\"d$a\"}')");
        $pdo->exec("INSERT INTO intus_avisos (idatleta, tipo, titulo) VALUES ($a,'avaliacao','Aluno $a preencheu: a anamnese')");
    }
    foreach ([11, 21] as $a) $pdo->exec("INSERT INTO intus_profile (idatleta, avatar, prefs) VALUES ($a,'/img/$a.png','{}')");
    $pdo->exec("INSERT INTO intus_nutri_registro (idatleta, data, refeicao, status) VALUES (21,'" . date('Y-m-d') . "','cafe','feito')");
    $st = $pdo->prepare("INSERT INTO intus_sessions (token_hash, user_id, user_type, admin, expires_at) VALUES (?,?,?,?,?)");
    foreach (['admin' => [1, 'prof'], 'A' => [3, 'prof'], 'B' => [4, 'prof'], 'C' => [5, 'prof'], 'F' => [6, 'prof'], 'aluno11' => [11, 'aluno'], 'aluno21' => [21, 'aluno']] as $nome => [$uid, $tipo]) {
        $t = substr(hash('sha256', 'esc-' . $nome) . hash('sha256', 'esc2-' . $nome), 0, 64);
        $st->execute([hash('sha256', $t), $uid, $tipo, $uid === 1 ? 1 : 0, date('Y-m-d H:i:s', time() + 86400)]);
        $TOK[$nome] = $t;
    }
})();

$ini = php_ini_loaded_file();
$n = 0;
// Cada pedido roda com um banco novo (cópia da semente). Devolve [status, corpo decodificado, caminho do banco para conferir o que ficou gravado].
function rodar(string $quem, string $metodo, array $get, $corpo = null, string $mutacao = ''): array {
    global $dir, $seed, $TOK, $ini, $n;
    $db = $dir . '/caso' . (++$n) . '.sqlite';
    copy($seed, $db);
    $caso = ['db' => $db, 'metodo' => $metodo, 'get' => $get, 'token' => $TOK[$quem] ?? 'invalido', 'corpo' => $corpo, 'mutacao' => $mutacao];
    $env = ['CASO_JSON' => json_encode($caso), 'PATH' => getenv('PATH')];
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -d display_errors=stderr -d log_errors=0 ' . escapeshellarg(__FILE__);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode($out, true);
    if (!is_array($j)) return [0, ['_cru' => $out, '_err' => $err], $db];
    return [(int)$j['status'], json_decode($j['corpo'], true) ?? ['_cru' => $j['corpo']], $db];
}
function ids($lista, $campo = 'idatleta') { $r = array_map(fn($x) => (int)$x[$campo], is_array($lista) ? $lista : []); sort($r); return $r; }
function banco($db) { $p = new PDO('sqlite:' . $db); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); return $p; }
function col($db, $sql) { return banco($db)->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
$av21 = (int)col($seed, "SELECT idavaliacao FROM intus_avaliacao WHERE idatleta = 21")[0];

echo "\n══ Funções puras (_escopo_atleta.php) ══\n";
require $API . '/_escopo_atleta.php';
$pu = new PdoTeste('sqlite:' . $seed);
$pu->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pu->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
eq('carteira do professor A = alunos em que ele é responsável (11, 12)', intusEscopoCarteira($pu, ['idusuario' => 3]), [11, 12]);
eq('carteira da nutri C = só a aluna 12', intusEscopoCarteira($pu, ['idusuario' => 5]), [12]);
eq('professor sem aluno: carteira vazia', intusEscopoCarteira($pu, ['idusuario' => 6]), []);
eq('administrador: null (sem restrição)', intusEscopoCarteira($pu, ['idusuario' => 1, 'admin' => true]), null);
eq('aluno: só o próprio id', intusEscopoCarteira($pu, ['idusuario' => 11, 'is_aluno' => true, 'idatleta' => 11]), [11]);
eq('sem identidade: nada', intusEscopoCarteira($pu, ['idusuario' => 0]), []);
$ped = fn($g, $b = []) => intusEscopoIdsDoPedido($g, $b);
eq('ids da URL e do corpo, todos', $ped(['atleta' => '5'], ['idatleta' => 7, '_idatleta' => '9'])['ids'], [5, 7, 9]);
eq('"abc", 0 e negativo não contam como id (mesmo cast das rotas)', $ped(['atleta' => 'abc', 'idatleta' => '-4'], ['_idatleta' => 0])['ids'], []);
eq('lista no lugar do id é inválida (cast de lista vale 1)', $ped(['atleta' => ['9']])['invalido'], true);
eq('lista no corpo também', $ped([], ['idatleta' => [1, 2]])['invalido'], true);
eq('avaliar: tudo na carteira passa', intusEscopoAvaliar($ped(['atleta' => 11], ['idatleta' => 12]), [11, 12])['ok'], true);
eq('avaliar: UM id fora derruba (URL boa, corpo ruim)', intusEscopoAvaliar($ped(['atleta' => 11], ['idatleta' => 21]), [11, 12]), ['ok' => false, 'codigo' => 403, 'erro' => 'aluno fora da sua carteira', 'fora' => [21]]);
eq('avaliar: carteira vazia nega qualquer id', intusEscopoAvaliar($ped(['atleta' => 11]), [])['codigo'], 403);
eq('avaliar: inválido dá 400', intusEscopoAvaliar($ped(['atleta' => [1]]), [11])['codigo'], 400);
eq('where: administrador sem filtro', intusEscopoWhere(null), ['', []]);
eq('where: carteira vazia não devolve nada', intusEscopoWhere([]), ['1 = 0', []]);
eq('where: carteira vira IN', intusEscopoWhere([11, 12]), ['idatleta IN (?,?)', [11, 12]]);
eq('dono: avaliação do aluno da carteira', intusEscopoDono($pu, [11, 12], 'intus_avaliacao', 'idavaliacao', 1), true);
eq('dono: avaliação de aluno de fora', intusEscopoDono($pu, [11, 12], 'intus_avaliacao', 'idavaliacao', 3), false);
eq('dono: registro inexistente não bloqueia (nada a proteger)', intusEscopoDono($pu, [11], 'intus_avaliacao', 'idavaliacao', 999), true);
eq('dono: administrador sempre', intusEscopoDono($pu, null, 'intus_avaliacao', 'idavaliacao', 3), true);
eq('dono: nome de tabela suspeito é recusado', intusEscopoDono($pu, [11], 'intus_avaliacao; DROP', 'idavaliacao', 1), false);

echo "\n══ Professor A (alunos 11 e 12) NÃO lê aluno de fora ══\n";
foreach (['avaliacoes', 'nutricao', 'anamnese', 'profile'] as $ac) {
    [$s, $c] = rodar('A', 'GET', ['action' => $ac, 'atleta' => '21']);
    eq("GET $ac?atleta=21 (aluno do professor B): 403", $s, 403);
    eq("GET $ac?atleta=21: nenhum dado no corpo", isset($c['error']) && count($c) === 1, true);
}
[$s] = rodar('A', 'GET', ['action' => 'avaliacoes', 'atleta' => '31']);
eq('aluno SEM responsável marcado: só o administrador (403 para o professor)', $s, 403);
[$s, $c] = rodar('A', 'GET', ['action' => 'avaliacoes', 'atleta' => '11']);
eq('aluno da própria carteira: 200 e só os dados dele', [$s, ids($c)], [200, [11]]);
[$s, $c] = rodar('A', 'GET', ['action' => 'nutricao', 'atleta' => '12']);
eq('nutricao do aluno 12 (carteira compartilhada com a nutri): 200', [$s, ids($c)], [200, [12]]);
[$s, $c] = rodar('A', 'GET', ['action' => 'anamnese', 'atleta' => '11']);
eq('anamnese do aluno 11: 200', [$s, $c['doencas'] ?? null], [200, 'd11']);
[$s, $c] = rodar('A', 'GET', ['action' => 'profile', 'atleta' => '11']);
eq('profile do aluno 11: 200', [$s, $c['avatar'] ?? null], [200, '/img/11.png']);
[$s] = rodar('A', 'GET', ['action' => 'nutri_registro', 'atleta' => '21']);
eq('nutri_registro de aluno de fora segue 403 (já tinha escopo)', $s, 403);
[$s] = rodar('A', 'GET', ['action' => 'avaliacoes', 'atleta' => ['11']]);
eq('atleta[]= (lista) é recusado com 400, não vira aluno 1', $s, 400);

echo "\n══ Listas sem aluno informado vêm filtradas à carteira ══\n";
[$s, $c] = rodar('A', 'GET', ['action' => 'avaliacoes']);
eq('avaliacoes sem ?atleta=: só 11 e 12', [$s, ids($c)], [200, [11, 12]]);
[$s, $c] = rodar('A', 'GET', ['action' => 'avaliacoes', 'atleta' => '0']);
eq('avaliacoes com atleta=0 (truque de "sem aluno"): só 11 e 12', [$s, ids($c)], [200, [11, 12]]);
[$s, $c] = rodar('A', 'GET', ['action' => 'nutricao']);
eq('nutricao sem ?atleta=: só 11 e 12', [$s, ids($c)], [200, [11, 12]]);
[$s, $c] = rodar('A', 'GET', ['action' => 'anamneses']);
eq('anamneses: só 11 e 12', [$s, ids($c)], [200, [11, 12]]);
[$s, $c] = rodar('A', 'GET', ['action' => 'avisos']);
eq('avisos: só os dos alunos 11 e 12', [$s, ids($c)], [200, [11, 12]]);
[$s, $c] = rodar('C', 'GET', ['action' => 'anamneses']);
eq('nutricionista C: só a aluna 12', [$s, ids($c)], [200, [12]]);
foreach (['avaliacoes', 'nutricao', 'anamneses', 'avisos'] as $ac) {
    [$s, $c] = rodar('F', 'GET', ['action' => $ac]);
    eq("professor sem carteira: $ac vem vazio", [$s, $c], [200, []]);
}

echo "\n══ Professor A NÃO grava em aluno de fora ══\n";
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'avaliacoes'], ['idatleta' => 21, 'peso' => 99, 'observacao' => 'forjada']);
eq('POST avaliacoes com idatleta de fora: 403 e nada gravado', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE observacao = 'forjada'")[0]], [403, 0]);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'avaliacoes', 'atleta' => '11'], ['idatleta' => 21, 'observacao' => 'forjada']);
eq('URL com aluno da carteira + corpo com aluno de fora: 403 (todos os ids contam)', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE observacao = 'forjada'")[0]], [403, 0]);
[$s, $c, $db] = rodar('A', 'PUT', ['action' => 'avaliacoes'], ['idavaliacao' => $av21, 'observacao' => 'trocada']);
eq('PUT avaliacao de aluno de fora, só pelo id do registro: 403 e intacta', [$s, col($db, "SELECT observacao FROM intus_avaliacao WHERE idavaliacao = $av21")[0]], [403, 'obs 21']);
$av11 = col($seed, "SELECT idavaliacao FROM intus_avaliacao WHERE idatleta = 11")[0];
[$s, $c, $db] = rodar('A', 'PUT', ['action' => 'avaliacoes'], ['idavaliacao' => (int)$av11, 'idatleta' => 21]);
eq('PUT de avaliacao PRÓPRIA passando para aluno de fora: 403 (não dá para despachar dado para fora da carteira)', [$s, (int)col($db, "SELECT idatleta FROM intus_avaliacao WHERE idavaliacao = $av11")[0]], [403, 11]);
[$s, $c, $db] = rodar('A', 'DELETE', ['action' => 'avaliacoes', 'id' => (string)$av21]);
eq('DELETE avaliacao de aluno de fora: 403 e continua lá', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE idavaliacao = $av21")[0]], [403, 1]);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'nutricao'], ['idatleta' => 21, 'titulo' => 'forjado']);
eq('POST nutricao em aluno de fora: 403 e nada gravado', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_plano_nutricional WHERE titulo = 'forjado'")[0]], [403, 0]);
$pl21 = (int)col($seed, "SELECT idplano FROM intus_plano_nutricional WHERE idatleta = 21")[0];
[$s, $c, $db] = rodar('A', 'PUT', ['action' => 'nutricao'], ['idplano' => $pl21, 'titulo' => 'trocado']);
eq('PUT plano de aluno de fora, só pelo id: 403 e intacto', [$s, col($db, "SELECT titulo FROM intus_plano_nutricional WHERE idplano = $pl21")[0]], [403, 'plano 21']);
[$s, $c, $db] = rodar('A', 'DELETE', ['action' => 'nutricao', 'id' => (string)$pl21]);
eq('DELETE plano de aluno de fora: 403 e continua lá', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_plano_nutricional WHERE idplano = $pl21")[0]], [403, 1]);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'anamnese', 'atleta' => '21'], ['doencas' => 'forjada']);
eq('POST anamnese de aluno de fora (URL): 403 e intacta', [$s, col($db, "SELECT dados FROM intus_anamnese WHERE idatleta = 21")[0]], [403, '{"doencas":"d21"}']);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'anamnese'], ['_idatleta' => 21, 'doencas' => 'forjada']);
eq('POST anamnese com _idatleta no corpo: 403 e intacta', [$s, col($db, "SELECT dados FROM intus_anamnese WHERE idatleta = 21")[0]], [403, '{"doencas":"d21"}']);
[$s, $c, $db] = rodar('A', 'PUT', ['action' => 'profile', 'atleta' => '21'], ['idatleta' => 21, 'avatar' => '/img/hack.png']);
eq('PUT profile de aluno de fora: 403 e foto intacta', [$s, col($db, "SELECT avatar FROM intus_profile WHERE idatleta = 21")[0]], [403, '/img/21.png']);
$av5 = col($seed, "SELECT idaviso FROM intus_avisos WHERE idatleta = 21")[0];
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'avisos'], ['idaviso' => (int)$av5]);
eq('marcar como lido o aviso de aluno de fora: 403 e continua não lido', [$s, (int)col($db, "SELECT lido FROM intus_avisos WHERE idaviso = $av5")[0]], [403, 0]);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'avisos'], ['marcar_todos' => true]);
eq('"marcar todos" do professor A só marca os avisos dos alunos dele', [$s, col($db, "SELECT GROUP_CONCAT(idatleta) FROM (SELECT idatleta FROM intus_avisos WHERE lido = 1 ORDER BY idatleta)")[0]], [200, '11,12']);

echo "\n══ Professor A segue trabalhando nos alunos dele ══\n";
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'avaliacoes'], ['idatleta' => 11, 'peso' => 70, 'observacao' => 'nova']);
eq('POST avaliacao no aluno da carteira: 200 e gravada', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE observacao = 'nova' AND idatleta = 11")[0]], [200, 1]);
[$s, $c, $db] = rodar('A', 'PUT', ['action' => 'avaliacoes'], ['idavaliacao' => (int)$av11, 'observacao' => 'editada']);
eq('PUT da avaliacao do aluno da carteira: 200', [$s, col($db, "SELECT observacao FROM intus_avaliacao WHERE idavaliacao = $av11")[0]], [200, 'editada']);
[$s, $c, $db] = rodar('A', 'DELETE', ['action' => 'avaliacoes', 'id' => (string)$av11]);
eq('DELETE da avaliacao do aluno da carteira: 200', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE idavaliacao = $av11")[0]], [200, 0]);
$pl11 = (int)col($seed, "SELECT idplano FROM intus_plano_nutricional WHERE idatleta = 11")[0];
[$s, $c, $db] = rodar('A', 'PUT', ['action' => 'nutricao'], ['idplano' => $pl11, 'titulo' => 'ajustado']);
eq('PUT do plano do aluno da carteira: 200', [$s, col($db, "SELECT titulo FROM intus_plano_nutricional WHERE idplano = $pl11")[0]], [200, 'ajustado']);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'nutricao'], ['idatleta' => 12, 'titulo' => 'novo plano']);
eq('POST de plano no aluno da carteira: 200', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_plano_nutricional WHERE titulo = 'novo plano'")[0]], [200, 1]);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'anamnese', 'atleta' => '11'], ['doencas' => 'atualizada']);
eq('POST anamnese do aluno da carteira: 200', [$s, col($db, "SELECT dados FROM intus_anamnese WHERE idatleta = 11")[0] !== '{"doencas":"d11"}'], [200, true]);
[$s, $c, $db] = rodar('A', 'PUT', ['action' => 'profile', 'atleta' => '11'], ['idatleta' => 11, 'avatar' => '/img/novo.png']);
eq('PUT profile do aluno da carteira: 200', [$s, col($db, "SELECT avatar FROM intus_profile WHERE idatleta = 11")[0]], [200, '/img/novo.png']);
[$s] = rodar('B', 'GET', ['action' => 'avaliacoes', 'atleta' => '21']);
eq('professor B lê o aluno dele (21): 200', $s, 200);
[$s] = rodar('B', 'GET', ['action' => 'avaliacoes', 'atleta' => '11']);
eq('...e não lê o aluno do A (11): 403', $s, 403);

echo "\n══ Ações isentas não passam pela guarda de pedido ══\n";
foreach (['feed_posts' => ['idatleta' => '21'], 'perfil_config' => ['idatleta' => '21'], 'resultados' => ['idatleta' => '21'], 'atleta_existe' => ['atleta' => '21']] as $ac => $q) {
    [$s, $c] = rodar('A', 'GET', ['action' => $ac] + $q);
    eq("$ac com aluno de fora: a guarda não barra (nenhum 'fora da sua carteira')", ($c['error'] ?? '') !== 'aluno fora da sua carteira', true);
}
eq('as isenções são exatamente estas quatro (mudar a lista exige mudar o teste e justificar)', INTUS_ESCOPO_ISENTAS, ['atleta_existe', 'feed_posts', 'perfil_config', 'resultados']);

echo "\n══ Administrador segue vendo e gravando tudo ══\n";
foreach (['avaliacoes', 'nutricao', 'anamneses', 'avisos'] as $ac) {
    [$s, $c] = rodar('admin', 'GET', ['action' => $ac]);
    eq("admin: $ac devolve os 7 alunos", [$s, ids($c)], [200, [11, 12, 21, 22, 31, 32, 41]]);
}
[$s, $c] = rodar('admin', 'GET', ['action' => 'avaliacoes', 'atleta' => '31']);
eq('admin lê aluno sem responsável', [$s, ids($c)], [200, [31]]);
[$s, $c, $db] = rodar('admin', 'POST', ['action' => 'avaliacoes'], ['idatleta' => 21, 'observacao' => 'do admin']);
eq('admin grava em qualquer aluno', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE observacao = 'do admin'")[0]], [200, 1]);
[$s, $c, $db] = rodar('admin', 'DELETE', ['action' => 'avaliacoes', 'id' => (string)$av21]);
eq('admin apaga avaliação de qualquer aluno', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE idavaliacao = $av21")[0]], [200, 0]);

echo "\n══ Aluno segue como era (e não escreve mais plano alimentar) ══\n";
[$s, $c] = rodar('aluno11', 'GET', ['action' => 'avaliacoes', 'atleta' => '11']);
eq('aluno lê as próprias avaliações', [$s, ids($c)], [200, [11]]);
[$s] = rodar('aluno11', 'GET', ['action' => 'avaliacoes', 'atleta' => '21']);
eq('aluno pedindo dados de outro: 403 (trava de antes)', $s, 403);
[$s] = rodar('aluno11', 'GET', ['action' => 'avaliacoes']);
eq('aluno sem ?atleta=: 400 (informe o atleta), não a base', $s, 400);
[$s, $c, $db] = rodar('aluno11', 'POST', ['action' => 'avaliacoes'], ['idatleta' => 11, 'observacao' => 'autoavaliacao']);
eq('aluno registra a própria avaliação: 200', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE observacao = 'autoavaliacao' AND idatleta = 11")[0]], [200, 1]);
[$s] = rodar('aluno11', 'POST', ['action' => 'avaliacoes'], ['idatleta' => 21]);
eq('aluno gravando avaliação de outro: 403', $s, 403);
[$s, $c] = rodar('aluno11', 'GET', ['action' => 'nutricao', 'atleta' => '11']);
eq('aluno lê o próprio plano alimentar: 200', [$s, ids($c)], [200, [11]]);
[$s, $c, $db] = rodar('aluno11', 'POST', ['action' => 'nutricao'], ['idatleta' => 21, 'titulo' => 'forjado pelo aluno']);
eq('aluno NÃO cria plano alimentar (antes criava em qualquer aluno): 403 e nada gravado', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_plano_nutricional WHERE titulo = 'forjado pelo aluno'")[0]], [403, 0]);
[$s, $c, $db] = rodar('aluno11', 'DELETE', ['action' => 'nutricao', 'id' => (string)$pl21]);
eq('aluno NÃO apaga plano alimentar de ninguém: 403 e continua lá', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_plano_nutricional WHERE idplano = $pl21")[0]], [403, 1]);
[$s, $c, $db] = rodar('aluno11', 'PUT', ['action' => 'nutricao'], ['idplano' => $pl21, 'titulo' => 'trocado pelo aluno']);
eq('aluno NÃO edita plano alimentar de ninguém: 403 e intacto', [$s, col($db, "SELECT titulo FROM intus_plano_nutricional WHERE idplano = $pl21")[0]], [403, 'plano 21']);
[$s, $c, $db] = rodar('aluno11', 'POST', ['action' => 'anamnese'], ['_idatleta' => 21, 'doencas' => 'enviada pelo app']);
eq('anamnese do aluno: o id do token vence o do corpo (grava no 11, o 21 fica intacto)', [$s, col($db, "SELECT dados FROM intus_anamnese WHERE idatleta = 21")[0], strpos(col($db, "SELECT dados FROM intus_anamnese WHERE idatleta = 11")[0], 'enviada pelo app') !== false], [200, '{"doencas":"d21"}', true]);
[$s] = rodar('aluno11', 'GET', ['action' => 'anamnese', 'atleta' => '21']);
eq('aluno pedindo a anamnese de outro pela URL: 403 (trava de antes)', $s, 403);
[$s] = rodar('aluno11', 'GET', ['action' => 'anamneses']);
eq('aluno não lista anamneses', $s, 403);
[$s] = rodar('aluno11', 'GET', ['action' => 'avisos']);
eq('aluno não lê avisos', $s, 403);
[$s] = rodar('semtoken', 'GET', ['action' => 'avaliacoes', 'atleta' => '11']);
eq('sem sessão válida: 401', $s, 401);

echo "\n══ QUEBRA DE PROPÓSITO: o mesmo catalogo.php sem a guarda (estado anterior) VAZA, então o teste acusa ══\n";
[$s, $c] = rodar('A', 'GET', ['action' => 'avaliacoes', 'atleta' => '21'], null, 'sem_guarda');
eq('sem a guarda, o professor A lê o aluno 21 (vazamento reproduzido)', [$s, ids($c)], [200, [21]]);
[$s, $c] = rodar('A', 'GET', ['action' => 'anamneses'], null, 'sem_guarda');
eq('sem a guarda, anamneses devolve a base inteira (7 alunos)', [$s, count($c)], [200, 7]);
[$s, $c, $db] = rodar('A', 'DELETE', ['action' => 'avaliacoes', 'id' => (string)$av21], null, 'sem_guarda');
eq('sem a guarda, o professor A apaga avaliação de outro professor', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE idavaliacao = $av21")[0]], [200, 0]);
[$s, $c, $db] = rodar('A', 'POST', ['action' => 'avaliacoes'], ['idatleta' => 21, 'observacao' => 'forjada'], 'sem_guarda');
eq('sem a guarda, o professor A grava em aluno de outro', [$s, (int)col($db, "SELECT COUNT(*) FROM intus_avaliacao WHERE observacao = 'forjada'")[0]], [200, 1]);

echo "\n══ Estrutura: onde a guarda está ══\n";
$cat = str_replace("\r\n", "\n", file_get_contents($API . '/catalogo.php'));
$g = strpos($cat, "if (\$_tokValido && !\$_ehAluno && empty(\$_ctx['admin']) && !in_array(\$action, INTUS_ESCOPO_ISENTAS, true)) {");
eq('a guarda existe e vem ANTES da primeira rota (alongamentos)', $g !== false && $g < strpos($cat, "if (\$action === 'alongamentos')"), true);
eq('a guarda só vale para equipe não administradora (aluno e admin não passam por ela)', $g !== false && strpos(substr($cat, $g, 140), '!$_ehAluno') !== false && strpos(substr($cat, $g, 140), "empty(\$_ctx['admin'])") !== false, true);
eq('catalogo.php carrega _escopo_atleta.php', strpos($cat, "require_once __DIR__ . '/_escopo_atleta.php';") !== false, true);
$rota = function ($ini, $fim) use ($cat) { $a = strpos($cat, $ini); return substr($cat, $a, strpos($cat, $fim, $a + 1) - $a); };
$av = $rota("if (\$action === 'avaliacoes') {", "if (\$action === 'nutricao') {");
$nu = $rota("if (\$action === 'nutricao') {", "if (\$action === 'meus_alimentos') {");
eq('avaliacoes: lista filtrada, PUT e DELETE conferem o dono', substr_count($av, 'intusEscopoWhere(') === 1 && substr_count($av, "intusEscopoDono(\$pdo, intusEscopoCarteira(\$pdo, \$_ctx), 'intus_avaliacao'") === 2, true);
eq('nutricao: lista filtrada, PUT e DELETE conferem o dono, aluno não escreve', substr_count($nu, 'intusEscopoWhere(') === 1 && substr_count($nu, "intusEscopoDono(\$pdo, intusEscopoCarteira(\$pdo, \$_ctx), 'intus_plano_nutricional'") === 2 && strpos($nu, "\$_ehAluno && \$method !== 'GET'") !== false, true);
$an = $rota("if (\$action === 'anamneses') {", "if (\$action === 'avatars') {");
eq('anamneses: lista filtrada pela carteira', strpos($an, 'intusEscopoWhere(intusEscopoCarteira($pdo, $_ctx))') !== false, true);
$avs = $rota("if (\$action === 'avisos') {", '// ═══════════════ CONTA AINDA EXISTE?');
eq('avisos: lista, "marcar todos" e marcar um conferem a carteira', substr_count($avs, 'intusEscopoWhere(') === 2 && strpos($avs, "intusEscopoDono(\$pdo, intusEscopoCarteira(\$pdo, \$_ctx), 'intus_avisos'") !== false, true);
eq('jsonBody() é lido uma vez só (a guarda e a rota veem o mesmo corpo)', strpos($cat, 'static $memo = null;   // o corpo é lido') !== false, true);

// limpeza
foreach (glob($dir . '/*') as $f) @unlink($f);
@rmdir($dir);
echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
