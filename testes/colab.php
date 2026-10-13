<?php
// Posts colaborativos em catalogo.php (feed_posts + post_colab) rodando de verdade em PHP, com SQLite: só amigos são convidados, o convite
// é uma solicitação (aceitar ou recusar), o post só vira colaborativo depois do aceite e aparece no perfil do colaborador, a privacidade do
// post vale para os dois perfis, o autor pode tirar um colaborador e o colaborador pode sair. Extrai os blocos do catalogo.php (sem mexer no
// arquivo) e roda cada caso num processo próprio (os endpoints terminam com exit).
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/colab.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = str_replace("\r\n", "\n", file_get_contents($SRC));
function trecho($cod, $ini, $fim) {
    $i = strpos($cod, $ini); $j = $i === false ? false : strpos($cod, $fim, $i);
    if ($i === false || $j === false) { fwrite(STDERR, "trecho nao encontrado: $ini\n"); exit(1); }
    return substr($cod, $i, $j - $i);
}
$blocoFeed = trecho($cod, "if (\$action === 'feed_posts') {", '// ═══════════════ POSTS COLABORATIVOS');
$blocoColab = trecho($cod, "if (\$action === 'post_colab') {", '// ── Privacidade do perfil social');
$funcs = trecho($cod, '// Convida amigos para um post', '// Parceiros de treino de');
$f1 = strpos($cod, 'function _amigosDe'); $f2 = strpos($cod, "\n}\n", $f1);
$funcAmigos = substr($cod, $f1, $f2 - $f1 + 3);

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach ([
        "CREATE TABLE IF NOT EXISTS intus_feed_post (idpost INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, imagem TEXT, legenda TEXT, destino TEXT DEFAULT 'feed', localizacao TEXT, ativo INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_post_visib (idpost INTEGER PRIMARY KEY, visib TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_perfil_config (idatleta INTEGER PRIMARY KEY, fotos_visib TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_post_colab (idpost INTEGER NOT NULL, idatleta_convidado INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pendente', criado_em TEXT DEFAULT CURRENT_TIMESTAMP, respondido_em TEXT NULL, PRIMARY KEY (idpost, idatleta_convidado))",
        "CREATE TABLE IF NOT EXISTS atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, feed_optin TEXT, stbloqueio TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_profile (idatleta INTEGER, avatar TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_feed_pref (idatleta INTEGER, idalvo INTEGER, tipo TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_amizade (de_id INTEGER, para_id INTEGER, status TEXT)",
        "CREATE TABLE IF NOT EXISTS aviso (destino_id INTEGER, tipo TEXT, ator_id INTEGER, alvo_id INTEGER)",
    ] as $q) $pdo->exec($q);
    if (!$pdo->query("SELECT COUNT(*) FROM atleta")->fetchColumn()) {
        $pdo->exec("INSERT INTO atleta VALUES (1,'Ana','S',NULL),(2,'Bia','S',NULL),(3,'Caio','S',NULL),(4,'Dani','S',NULL)");
        $pdo->exec("INSERT INTO intus_amizade VALUES (1,2,'aceita'),(3,1,'aceita')");   // Ana e Bia, Caio e Ana (nos dois sentidos)
    }
    define('INTUS_CONEXOES_ATIVO', true);
    $action = $caso['action'];
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $_ehAluno = true;
    $_ctx = ['idatleta' => $caso['eu']];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    function _resolverAutorPub($pdo, $_ctx, $_ehAluno) { $n = $pdo->query("SELECT nome FROM atleta WHERE idatleta = " . (int)$_ctx['idatleta'])->fetchColumn(); return ['aluno', (int)$_ctx['idatleta'], (string)$n]; }
    function _feedDetectarTabelaAtleta($pdo) { return 'atleta'; }
    function _midiasDosPosts($pdo, $t, $ids) { return []; }
    function _mencoesLimitar($t, $n) { return mb_substr($t, 0, $n); }
    function _notificarMencoes() {}
    function _appBaseUrl() { return 'https://site'; }
    function _intusLogErro($e) { return 'erro'; }
    function _perfilConfig($pdo, $id) { return ['fotos_visib' => 'todos']; }
    function _criarNotificacao($pdo, $dt, $di, $tipo, $at, $ai, $an, $alvoTipo, $alvoId, $texto = '', $dedupe = false) {
        if ($di === $ai) return;
        if ($dedupe) { $q = $pdo->prepare("SELECT 1 FROM aviso WHERE destino_id = ? AND tipo = ? AND ator_id = ? AND alvo_id = ?"); $q->execute([$di, $tipo, $ai, $alvoId]); if ($q->fetchColumn()) return; }
        $pdo->prepare("INSERT INTO aviso VALUES (?,?,?,?)")->execute([$di, $tipo, $ai, $alvoId]);
    }
    eval($funcAmigos);
    eval($funcs);
    ob_start();
    eval($action === 'feed_posts' ? $blocoFeed : $blocoColab);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_colab_' . getmypid() . '.sqlite';
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
function publicar($eu, $corpo) { return rodar(['action' => 'feed_posts', 'metodo' => 'POST', 'eu' => $eu, 'corpo' => array_merge(['imagem' => 'https://x/a.jpg'], $corpo)]); }
function feed($eu, $get = []) { return rodar(['action' => 'feed_posts', 'metodo' => 'GET', 'eu' => $eu, 'get' => $get]); }
function convites($eu) { return rodar(['action' => 'post_colab', 'metodo' => 'GET', 'eu' => $eu]); }
function responder($eu, $idpost, $aceitar) { return rodar(['action' => 'post_colab', 'metodo' => 'PUT', 'eu' => $eu, 'corpo' => ['idpost' => $idpost, 'aceitar' => $aceitar]]); }
function sair($eu, $idpost, $alvo = 0) { return rodar(['action' => 'post_colab', 'metodo' => 'DELETE', 'eu' => $eu, 'get' => $alvo ? ['idpost' => $idpost, 'idatleta' => $alvo] : ['idpost' => $idpost]]); }
function avisos() { global $tmp; $p = new PDO('sqlite:' . $tmp); return $p->query("SELECT destino_id, tipo, ator_id FROM aviso ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC); }
function ids($r) { return array_map(fn($p) => $p['idpost'], $r['posts'] ?? []); }

echo "\n── Convidar ──\n";
$r = publicar(1, ['legenda' => 'Treino de perna em dupla', 'colaboradores' => [2, 3, 4, 1, 99, 2]]);
$p1 = $r['idpost'] ?? 0;
eq('só amigos entram: Bia e Caio (Dani não é amiga, Ana é a autora, 99 não existe, repetido some)', $r['convidados'] ?? null, 2);
eq('os dois recebem o convite (e mais ninguém)', array_map(fn($a) => [$a['destino_id'], $a['tipo']], avisos()), [[2, 'colab_convite'], [3, 'colab_convite']]);
eq('Bia vê 1 convite pendente', count(convites(2)['convites'] ?? []), 1);
eq('Dani não recebeu nada', count(convites(4)['convites'] ?? []), 0);
eq('antes do aceite o post não é colaborativo para quem vê', (feed(4)['posts'][0]['colaboradores'] ?? null), []);
eq('a autora vê os convites pendentes do próprio post', array_map(fn($x) => $x['nome'], feed(1)['posts'][0]['colab_pendentes'] ?? []), ['Bia', 'Caio']);
eq('antes do aceite o post não aparece no perfil da Bia', ids(feed(4, ['idatleta' => 2])), []);

echo "\n── Aceitar e recusar ──\n";
eq('Bia aceita', responder(2, $p1, true)['status'] ?? null, 'aceita');
eq('a autora é avisada do aceite', array_slice(array_map(fn($a) => [$a['destino_id'], $a['tipo'], $a['ator_id']], avisos()), -1), [[1, 'colab_aceito', 2]]);
eq('Caio recusa', responder(3, $p1, false)['status'] ?? null, 'recusada');
eq('recusar não avisa a autora de novo (só o aceite avisa)', count(array_filter(avisos(), fn($a) => $a['tipo'] === 'colab_aceito')), 1);
eq('responder de novo é idempotente', responder(2, $p1, false)['status'] ?? null, 'aceita');
eq('quem não foi convidado não aceita', responder(4, $p1, true)['error'] ?? null, 'convite nao encontrado');
eq('o convite sai da lista de pendentes', count(convites(2)['convites'] ?? []), 0);

echo "\n── Onde aparece ──\n";
$g = feed(4);
eq('no Feed geral aparece uma vez só', ids($g), [$p1]);
eq('com a lista de colaboradores aceitos', array_map(fn($x) => $x['nome'], $g['posts'][0]['colaboradores'] ?? []), ['Bia']);
eq('no perfil da Bia (colaboradora) aparece para outra pessoa', ids(feed(4, ['idatleta' => 2])), [$p1]);
eq('no perfil da autora aparece', ids(feed(4, ['idatleta' => 1])), [$p1]);
eq('no perfil do Caio (recusou) não aparece', ids(feed(4, ['idatleta' => 3])), []);
eq('a autora não vê mais pendentes', array_key_exists('colab_pendentes', feed(1)['posts'][0] ?? []), false);

echo "\n── Privacidade vale para os dois perfis ──\n";
$r2 = publicar(1, ['legenda' => 'só amigos', 'visib' => 'amigos', 'colaboradores' => [2]]);
$p2 = $r2['idpost'] ?? 0;
// o servidor grava a privacidade com ON DUPLICATE KEY (MySQL); no SQLite do teste a gravação direta faz o mesmo papel
$pp = new PDO('sqlite:' . $tmp); $pp->exec("INSERT OR REPLACE INTO intus_post_visib VALUES ($p2, 'amigos')");
responder(2, $p2, true);
eq('post só para amigos: a Bia (colaboradora) vê no perfil dela', in_array($p2, ids(feed(2, ['idatleta' => 2])), true), true);
eq('post só para amigos: a Dani (sem amizade com a autora) não vê no perfil da Bia', in_array($p2, ids(feed(4, ['idatleta' => 2])), true), false);
eq('post só para amigos: a Dani não vê no Feed', in_array($p2, ids(feed(4)), true), false);

echo "\n── Sair e remover ──\n";
eq('quem não é autora nem colaboradora não remove ninguém', sair(4, $p1, 2)['error'] ?? null, 'sem permissao');
eq('a autora tira a Bia', sair(1, $p1, 2)['ok'] ?? null, true);
eq('sem a Bia o post deixa de aparecer no perfil dela', in_array($p1, ids(feed(4, ['idatleta' => 2])), true), false);
eq('a Bia sai sozinha do outro post', sair(2, $p2)['ok'] ?? null, true);
eq('depois de sair, o post some do perfil dela', in_array($p2, ids(feed(2, ['idatleta' => 2])), true), false);
eq('post inexistente', sair(1, 999)['error'] ?? null, 'post nao encontrado');

@unlink($tmp);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
