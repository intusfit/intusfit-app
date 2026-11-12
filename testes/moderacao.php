<?php
// Moderação do que os alunos publicam (exigência das lojas: Apple 1.2 e conteúdo gerado por usuário do Google Play), rodando de verdade em PHP com SQLite:
// filtro de termos graves, bloqueio (nos dois sentidos), denúncia (o autor vem do servidor), suspensão, fila e decisão da equipe, e os ganchos nos
// endpoints reais (Feed, comentários, reações, mensagens, amizade, resultados, turmas, mural do ranking, sino). A biblioteca (_moderacao.php) roda em
// processo; os endpoints são extraídos de catalogo.php e treinos.php (sem mexer nos arquivos) e cada chamada roda num processo próprio (terminam com exit).
//   php testes/moderacao.php
$API = getenv('INTUS_API') ?: realpath(__DIR__ . '/../app/api');   // INTUS_API aponta para uma cópia quebrada de propósito (teste do teste)
require_once $API . '/_moderacao.php';

function lf($s) { return str_replace("\r\n", "\n", $s); }
$cod = lf(file_get_contents($API . '/catalogo.php'));
$codTr = lf(file_get_contents($API . '/treinos.php'));
$codNot = lf(file_get_contents($API . '/_notificacoes.php'));
function trecho($cod, $ini, $fim, $incluirFim = false) {
    $i = strpos($cod, $ini); $j = $i === false ? false : strpos($cod, $fim, $i + strlen($ini));
    if ($i === false || $j === false) { fwrite(STDERR, "trecho nao encontrado: $ini\n"); exit(1); }
    return substr($cod, $i, $j - $i + ($incluirFim ? strlen($fim) : 0));
}
function schema(PDO $pdo) {
    foreach ([
        "CREATE TABLE IF NOT EXISTS atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, feed_optin TEXT, stbloqueio TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_amizade (idamizade INTEGER PRIMARY KEY AUTOINCREMENT, de_id INTEGER, para_id INTEGER, status TEXT, aceita_em TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_parceria (de_id INTEGER, para_id INTEGER, status TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_post_colab (idpost INTEGER NOT NULL, idatleta_convidado INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pendente', criado_em TEXT, respondido_em TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_feed_post (idpost INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, imagem TEXT, legenda TEXT, destino TEXT DEFAULT 'feed', localizacao TEXT, ativo INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_post_visib (idpost INTEGER PRIMARY KEY, visib TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_perfil_config (idatleta INTEGER PRIMARY KEY, fotos_visib TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_profile (idatleta INTEGER, avatar TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_feed_pref (idatleta INTEGER, idalvo INTEGER, tipo TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_comentario_pub (idcomentario INTEGER PRIMARY KEY AUTOINCREMENT, alvo_tipo TEXT, alvo_id INTEGER, resposta_a INTEGER, autor_tipo TEXT, autor_id INTEGER, autor_nome TEXT, texto TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_reacao_pub (idreacao INTEGER PRIMARY KEY AUTOINCREMENT, alvo_tipo TEXT, alvo_id INTEGER, autor_tipo TEXT, autor_id INTEGER, autor_nome TEXT, emoji TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_ranking_comentario (idcomentario INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, nome_atleta TEXT, texto TEXT, dtcriacao TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_resultado (idresultado INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, tipo TEXT, texto TEXT, periodo_txt TEXT, autoriza_app INTEGER, autoriza_site INTEGER DEFAULT 0, destaque_site INTEGER DEFAULT 0, ativo INTEGER DEFAULT 1, origem TEXT, autor_nome TEXT, link_tipo TEXT, link_id TEXT, autorizacao_obs TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_dm (iddm INTEGER PRIMARY KEY AUTOINCREMENT, de_id INTEGER, para_id INTEGER, texto TEXT, lido INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_midia (idmidia INTEGER PRIMARY KEY AUTOINCREMENT, dono_tipo TEXT, dono_id INTEGER, ordem INTEGER, tipo TEXT, url TEXT, drive_id TEXT, status TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_notificacao (idnotificacao INTEGER PRIMARY KEY AUTOINCREMENT, destino_tipo TEXT, destino_id INTEGER, tipo TEXT, ator_tipo TEXT, ator_id INTEGER, ator_nome TEXT, alvo_tipo TEXT, alvo_id INTEGER, texto TEXT, lido INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        // só para o harness dos endpoints: o que o _criarNotificacao de mentira registra e os e-mails que a rota tentou mandar
        "CREATE TABLE IF NOT EXISTS aviso (destino_tipo TEXT, destino_id INTEGER, tipo TEXT, ator_tipo TEXT, ator_id INTEGER, alvo_tipo TEXT, alvo_id INTEGER, texto TEXT)",
        "CREATE TABLE IF NOT EXISTS email_log (para TEXT, assunto TEXT, corpo TEXT)",
    ] as $q) $pdo->exec($q);
}
function seed(PDO $pdo) {
    if ($pdo->query("SELECT COUNT(*) FROM atleta")->fetchColumn()) return;
    $pdo->exec("INSERT INTO atleta VALUES (1,'Ana','S',NULL),(2,'Bia','S',NULL),(3,'Caio','S',NULL),(4,'Dani','S',NULL),(5,'Edu','S',NULL)");
}

// ═══════════════ modo "caso": um endpoint, um processo ═══════════════
if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    define('INTUS_CONEXOES_ATIVO', true);
    $action = $caso['action'] ?? '';
    $method = $caso['metodo'] ?? 'GET';
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $papel = $caso['papel'] ?? 'aluno';                       // aluno | admin | prof (professor sem ser administrador)
    $_ehAluno = $papel === 'aluno';
    $_ctx = $_ehAluno ? ['is_aluno' => true, 'idatleta' => $caso['eu'], 'idusuario' => $caso['eu'], 'admin' => false]
                      : ['is_aluno' => false, 'idusuario' => $caso['eu'], 'admin' => $papel === 'admin'];
    $_authCtx = $_ctx;
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    function _resolverAutorPub($pdo, $_ctx, $_ehAluno) {
        if ($_ehAluno) { $n = $pdo->query("SELECT nome FROM atleta WHERE idatleta = " . (int)$_ctx['idatleta'])->fetchColumn(); return ['aluno', (int)$_ctx['idatleta'], (string)$n]; }
        return ['prof', (int)$_ctx['idusuario'], 'Equipe'];
    }
    function _feedDetectarTabelaAtleta($pdo) { return 'atleta'; }
    function _midiasDosPosts($pdo, $t, $ids) { return []; }
    function _mencoesLimitar($t, $n) { return mb_substr($t, 0, $n); }
    function _notificarMencoes() {}
    function _appBaseUrl() { return 'https://site/app'; }
    function _intusLogErro($e) { return 'erro'; }
    function _perfilConfig($pdo, $id) { return ['fotos_visib' => 'todos', 'mostrar_conquistas' => 1, 'mostrar_resultados' => 1, 'mostrar_semana' => 1]; }
    function _intusPostAdmins($pdo) { return [1]; }
    function _nomePublicoProf($id, $n) { return $n; }
    function _nutriAlunoTemPlano($pdo, $id) { return true; }
    function _intusPostGarantirTabela($pdo) {}
    function _intusAgoraBR() { return date('Y-m-d H:i:s'); }
    function _resultadoDestinatariosProf($pdo, $id) { return []; }
    function _resultadoPublicar() {}
    function _chatMensagem() { return null; }
    function _notifGarantirTabela($pdo) {}
    function _pessoasMapa($pdo, $ids) { return []; }
    function _turmasDe($pdo, $id) { return []; }
    function intusSmtpConfig() { return ['notificar' => 'equipe@teste']; }
    function _catalogoSendEmail($para, $assunto, $corpo) { $GLOBALS['pdo']->prepare("INSERT INTO email_log VALUES (?,?,?)")->execute([$para, $assunto, $corpo]); return true; }
    function _criarNotificacao($pdo, $dt, $di, $tipo, $at, $ai, $an, $alvoTipo, $alvoId, $texto = '', $dedupe = false) {
        if ($dt === $at && $di === $ai) return;
        if ($dt === 'aluno' && $at === 'aluno' && intusModBloqueadosEntre($pdo, $di, $ai)) return;   // o mesmo corte do _criarNotificacao de verdade
        if ($dedupe) { $q = $pdo->prepare("SELECT 1 FROM aviso WHERE destino_tipo = ? AND destino_id = ? AND tipo = ? AND ator_id = ? AND alvo_id = ?"); $q->execute([$dt, $di, $tipo, $ai, $alvoId]); if ($q->fetchColumn()) return; }
        $pdo->prepare("INSERT INTO aviso VALUES (?,?,?,?,?,?,?,?)")->execute([$dt, $di, $tipo, $at, $ai, $alvoTipo, $alvoId, $texto]);
    }
    // funções reais do catalogo.php que os blocos usam
    $f = function ($nome, $fim) use ($cod) { $i = strpos($cod, "function $nome("); $j = strpos($cod, $fim, $i); return substr($cod, $i, $j - $i); };
    eval($f('_amigosDe', "\n// Convida amigos"));
    eval($f('_donoDoPostFeed', "\n// Mensagem do CHAT"));
    eval($f('_donoDoAlvoPub', "\nfunction jsonBody"));
    $blocos = [
        'aluno' => trecho($cod, "if (\$action === 'bloqueios' ||", '// A fila da equipe.'),
        'equipe' => trecho($cod, "if (\$action === 'moderacao') {", '// ═══════════════ AVISOS PARA O PAINEL'),
        'feed_posts' => trecho($cod, "if (\$action === 'feed_posts') {", '// ═══════════════ POSTS COLABORATIVOS'),
        'comentarios_pub' => trecho($cod, "if (\$action === 'comentarios_pub') {", '// ═══════════════ PESSOAS QUE PODEM SER MENCIONADAS'),
        'reacoes' => trecho($cod, "if (\$action === 'reacoes') {", '// ═══════════════ COMENTÁRIOS (genérico'),
        'dm' => trecho($cod, "if (\$action === 'dm') {", '// ── Parcerias de treino'),
        'amizades' => trecho($cod, "if (\$action === 'amizades') {", '// ── Conversa entre amigos'),
        'resultados' => trecho($cod, "if (\$action === 'resultados') {", '// Foto de um resultado'),
        'turmas' => trecho($cod, "if (\$action === 'turmas') {", "if (\$method === 'PUT') {"),
        'turmas_put' => trecho($cod, "if (\$action === 'turmas') {", "\n}\n", true),
        'mural_novo' => trecho($codTr, "if (\$subaction === 'comentario') {", "if (\$subaction === 'excluir_comentario') {"),
        'mural_excluir' => trecho($codTr, "if (\$subaction === 'excluir_comentario') {", "'deleted' => \$st->rowCount()]);\n                exit;\n            }", true),
    ];
    $b = $caso['corpo'] ?? [];
    $subaction = $b['subaction'] ?? '';
    $nome = $caso['bloco'];
    $bloco = str_replace('__DIR__', var_export($API, true), $blocos[$nome]);   // o trecho roda aqui, mas os arquivos que ele carrega moram em app/api
    // o dm cria a própria tabela com DDL de MySQL (INDEX dentro do CREATE), que o SQLite não entende: no teste a tabela já existe
    $bloco = preg_replace('/\$pdo->exec\("CREATE TABLE IF NOT EXISTS intus_dm \(.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"\);/s', '', $bloco);
    if ($nome === 'turmas') $bloco .= "\n}";   // o trecho vai até o PUT: fecha o if do bloco
    if ($nome === 'turmas_put') $bloco = preg_replace('/\}\n$/', '', $bloco) . "\n}";
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

// ═══════════════ a partir daqui: o teste ═══════════════
$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_moderacao_' . getmypid() . '.sqlite';
@unlink($tmp);
$pdo = new PDO('sqlite:' . $tmp);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
schema($pdo); seed($pdo);
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado || (!is_array($obtido) && !is_array($esperado) && $obtido == $esperado && gettype($obtido) === gettype($esperado))) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function sim($rot, $cond) { eq($rot, (bool)$cond, true); }
function exec_($sql, $args = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($args); return $s; }
function um($sql, $args = []) { return exec_($sql, $args)->fetchColumn(); }
function lista($sql, $args = []) { return exec_($sql, $args)->fetchAll(PDO::FETCH_ASSOC); }
function colunas($sql, $args = []) { return exec_($sql, $args)->fetchAll(PDO::FETCH_COLUMN); }
function rodar($caso) {
    global $tmp, $ini;
    $caso['db'] = $tmp;
    $env = ['CASO_JSON' => json_encode($caso), 'PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot'), 'INTUS_API' => $GLOBALS['API']];
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -d display_errors=0 ' . escapeshellarg(__FILE__);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['_cru' => $out];
}
function chamar($bloco, $action, $metodo, $eu, $corpo = [], $get = [], $papel = 'aluno') { return rodar(['bloco' => $bloco, 'action' => $action, 'metodo' => $metodo, 'eu' => $eu, 'corpo' => $corpo, 'get' => $get, 'papel' => $papel]); }
// roda um trecho de PHP solto (para as funções que terminam com exit) e devolve o que ele imprimiu
function trecho_php($codigo) {
    global $tmp, $ini, $API;
    $pre = '$pdo = new PDO("sqlite:' . $tmp . '"); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); require ' . var_export($API . '/_moderacao.php', true) . ';';
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -d display_errors=0 -r ' . escapeshellarg($pre . $codigo) . ' 2>/dev/null';
    return trim((string)shell_exec($cmd));
}

// ───────────────────────────────────────────────────────────────────────────────
echo "── Filtro de termos graves ──\n";
$livres = [
    'treino de perna hoje, vou te matar de tanto agachar', 'esse treino foi uma porra, caralho', 'conjunto nude lindo', 'bora treinar 10x12 de supino',
    'cursos de nutrição, o curso é bom', 'vendo meu tênis, quase novo', 'vendo whey e creatina', 'alguém sabe se testosterona sobe no ciclo?', 'ótimo dia, galera! 💪', '',
    // "vendo" de ver, olhar: não é venda de substância
    'fui no médico, vendo o resultado da testosterona', 'vendo que minha testosterona caiu', 'vendo como a testosterona sobe', 'estou vendo vídeos de treino de hormônio do crescimento',
    'alguém sabe se hormônios influenciam no ganho de massa?',
];
foreach ($livres as $t) eq('livre: ' . json_encode($t, JSON_UNESCAPED_UNICODE), intusModTextoGrave($t), null);
$graves = [
    ['você é um viado', 'odio'], ['v i a d o', 'odio'], ['V.I.A.D.O', 'odio'], ['v14d0', 'odio'], ['viaaaado', 'odio'], ['RETARDADO', 'odio'], ['volta pra senzala', 'odio'],
    ['manda nudes', 'sexual'], ['n u d e s', 'sexual'], ['vou te esfaquear', 'ameaca'], ['vou te estuprar', 'ameaca'],
    ['filho da puta', 'insulto'], ['vai tomar no cú', 'insulto'], ['VAI SE FODER', 'insulto'], ['vagabunda', 'insulto'],
    ['vendo oxandrolona pronta entrega', 'substancias'], ['vendemos anabolizante', 'substancias'], ['compro cocaína', 'substancias'],
    ['oxandrolona original, pronta entrega', 'substancias'], ['vendo kit anabolizante', 'substancias'], ['vendendo hormônios', 'substancias'], ['vendemos esteroides', 'substancias'],
    // plural e variações do mesmo xingamento
    ['turma dos viados', 'odio'], ['bando de retardados', 'odio'], ['as vagabundas', 'insulto'], ['filhos da puta', 'insulto'], ['vão tomar no cu', 'insulto'], ['ele é um viadão', 'odio'],
];
foreach ($graves as [$t, $cat]) eq('grave (' . $cat . '): ' . json_encode($t, JSON_UNESCAPED_UNICODE), (intusModTextoGrave($t)['categoria'] ?? null), $cat);
eq('texto enorme com termo grave no fim ainda é pego (só os 5000 primeiros caracteres são lidos)', intusModTextoGrave(str_repeat('treino ', 100) . ' viado') !== null, true);
eq('termo grave depois de 5000 caracteres fica fora da leitura (limite de custo)', intusModTextoGrave(str_repeat('a ', 3000) . ' viado'), null);
$r = trecho_php('echo intusModTextoGrave("oi")["categoria"] ?? "livre";');
eq('a biblioteca roda sozinha (sem catalogo.php)', $r, 'livre');
$saida = trecho_php('intusModExigirTextoLimpo(["is_aluno" => true, "idatleta" => 1], "bom dia", "você é um viado"); echo "passou";');
$j = json_decode($saida, true);
eq('texto de ALUNO com termo grave: 422 com código e sem devolver o termo', [$j['codigo'] ?? null, isset($j['termo']), strpos($saida, 'viado') === false], ['conteudo_improprio', false, true]);
eq('texto da EQUIPE não passa pelo filtro', trecho_php('intusModExigirTextoLimpo(["is_aluno" => false, "idusuario" => 1], "você é um viado"); echo "passou";'), 'passou');
eq('texto limpo passa', trecho_php('intusModExigirTextoLimpo(["is_aluno" => true, "idatleta" => 1], "bom treino", ""); echo "passou";'), 'passou');

// ───────────────────────────────────────────────────────────────────────────────
echo "\n── Bloqueio ──\n";
exec_("INSERT INTO intus_amizade (de_id, para_id, status) VALUES (1,2,'aceita'),(3,1,'pendente'),(1,4,'aceita'),(5,2,'aceita')");
exec_("INSERT INTO intus_parceria VALUES (1,2,'aceita'),(1,4,'aceita')");
exec_("INSERT INTO intus_feed_post (idatleta, imagem, legenda) VALUES (1,'https://x/a.jpg','texto da Ana'),(2,'https://x/b.jpg','texto da Bia'),(3,'https://x/c.jpg','texto do Caio')");
exec_("INSERT INTO intus_post_colab VALUES (1,2,'aceita',NULL,NULL),(2,1,'pendente',NULL,NULL),(3,4,'aceita',NULL,NULL)");
eq('antes de bloquear não há ninguém bloqueado', intusModBloqueiosDe($pdo, 1)['todos'], []);
$r = intusModBloquear($pdo, 1, 2);
eq('Ana bloqueia Bia', [$r['ok'] ?? null, $r['novo'] ?? null], [true, true]);
eq('bloquear de novo é idempotente (não duplica)', [intusModBloquear($pdo, 1, 2)['novo'] ?? null, (int)um("SELECT COUNT(*) FROM intus_bloqueio")], [false, 1]);
eq('a amizade entre as duas acaba', (int)um("SELECT COUNT(*) FROM intus_amizade WHERE (de_id = 1 AND para_id = 2) OR (de_id = 2 AND para_id = 1)"), 0);
eq('a amizade da Ana com a Dani continua', (int)um("SELECT COUNT(*) FROM intus_amizade WHERE de_id = 1 AND para_id = 4"), 1);
eq('o pedido pendente do Caio e a amizade da Bia com o Edu continuam (outras pessoas)', (int)um("SELECT COUNT(*) FROM intus_amizade WHERE (de_id = 3 AND para_id = 1) OR (de_id = 5 AND para_id = 2)"), 2);
eq('a parceria de treino entre as duas acaba e a outra continua', [(int)um("SELECT COUNT(*) FROM intus_parceria WHERE para_id = 2"), (int)um("SELECT COUNT(*) FROM intus_parceria WHERE para_id = 4")], [0, 1]);
eq('os convites de post colaborativo entre as duas acabam (nos dois sentidos) e o de outro par continua', colunas("SELECT idpost FROM intus_post_colab ORDER BY idpost"), [3]);
eq('Ana: bloqueei a Bia', intusModBloqueiosDe($pdo, 1)['bloqueei'], [2]);
eq('Bia: foi bloqueada pela Ana', [intusModBloqueiosDe($pdo, 2)['bloquearam'], intusModBloqueiosDe($pdo, 2)['bloqueei']], [[1], []]);
eq('os dois lados enxergam o bloqueio ("todos")', [intusModIdsBloqueados($pdo, 1), intusModIdsBloqueados($pdo, 2)], [[2], [1]]);
eq('entre as duas, nos dois sentidos', [intusModBloqueadosEntre($pdo, 1, 2), intusModBloqueadosEntre($pdo, 2, 1)], [true, true]);
eq('com uma terceira pessoa não', [intusModBloqueadosEntre($pdo, 1, 3), intusModBloqueadosEntre($pdo, 3, 2), intusModBloqueadosEntre($pdo, 1, 1)], [false, false, false]);
eq('a lista de quem a Ana bloqueou traz o nome', intusModListaBloqueados($pdo, 1), [['id' => 2, 'nome' => 'Bia']]);
eq('não bloqueia a si mesma', intusModBloquear($pdo, 1, 1)['erro'] ?? null, 'si_mesmo');
eq('id inválido', intusModBloquear($pdo, 0, 2)['erro'] ?? null, 'invalido');
eq('desbloquear', [intusModDesbloquear($pdo, 1, 2), intusModBloqueadosEntre($pdo, 1, 2)], [true, false]);
eq('depois de desbloquear a amizade NÃO volta sozinha', (int)um("SELECT COUNT(*) FROM intus_amizade WHERE (de_id = 1 AND para_id = 2) OR (de_id = 2 AND para_id = 1)"), 0);
eq('as listas em massa nunca lançam com a tabela ausente', (function () { $p = new PDO('sqlite::memory:'); return [intusModIdsBloqueados($p, 1), intusModBloqueadosEntre($p, 1, 2), intusModSuspensaoAtiva($p, 1), intusModResumo($p)['abertas']]; })(), [[], false, null, 0]);

// ───────────────────────────────────────────────────────────────────────────────
echo "\n── Suspensão ──\n";
$t0 = '2026-11-12 10:00:00';
$r = intusModSuspender($pdo, 3, 7, 'Teste', 1, null, $t0);
eq('7 dias: a suspensão termina na data certa', $r['ate'] ?? null, '2026-11-19 10:00:00');
eq('suspenso no meio do prazo', intusModSuspensaoAtiva($pdo, 3, '2026-11-15 10:00:00') !== null, true);
eq('não suspenso depois do prazo', intusModSuspensaoAtiva($pdo, 3, '2026-11-20 10:00:00'), null);
eq('outra pessoa não é afetada', intusModSuspensaoAtiva($pdo, 4, '2026-11-15 10:00:00'), null);
$r = intusModSuspender($pdo, 3, 0, 'Sem prazo', 1, null, $t0);
eq('sem prazo (0): não tem data e dura', [array_key_exists('ate', $r) ? $r['ate'] : 'x', intusModSuspensaoAtiva($pdo, 3, '2030-01-01 00:00:00') !== null], [null, true]);
eq('suspender de novo substitui a anterior (só uma vale)', (int)um("SELECT COUNT(*) FROM intus_suspensao WHERE idatleta = 3 AND ativo = 1"), 1);
eq('mais de um ano não vale', intusModSuspender($pdo, 3, 366, 'x', 1)['erro'] ?? null, 'dias');
eq('a lista da equipe traz o nome', array_column(intusModSuspensoes($pdo, '2026-11-15 10:00:00'), 'nome'), ['Caio']);
eq('mensagem com prazo', strpos(intusModMensagemSuspensao(['ate' => '2026-11-19 10:00:00']), 'até 19/11/2026') !== false, true);
eq('mensagem sem prazo e com o contato publicado', [strpos(intusModMensagemSuspensao(['ate' => null]), 'por tempo indeterminado') !== false, strpos(intusModMensagemSuspensao(['ate' => null]), INTUS_MOD_CONTATO) !== false], [true, true]);
$s = json_decode(trecho_php('intusModExigirPodePublicar($pdo, ["is_aluno" => true, "idatleta" => 3]); echo "passou";'), true);
eq('aluno suspenso: 403 com código "suspenso"', $s['codigo'] ?? null, 'suspenso');
eq('aluno sem suspensão passa', trecho_php('intusModExigirPodePublicar($pdo, ["is_aluno" => true, "idatleta" => 4]); echo "passou";'), 'passou');
eq('equipe nunca é suspensa por aqui', trecho_php('intusModExigirPodePublicar($pdo, ["is_aluno" => false, "idusuario" => 3]); echo "passou";'), 'passou');
eq('reativar', [intusModReativar($pdo, 3), intusModSuspensaoAtiva($pdo, 3)], [true, null]);

// ───────────────────────────────────────────────────────────────────────────────
echo "\n── Denúncia: o autor vem do servidor ──\n";
exec_("INSERT INTO intus_comentario_pub (alvo_tipo, alvo_id, resposta_a, autor_tipo, autor_id, autor_nome, texto) VALUES ('feed_post',1,NULL,'aluno',2,'Bia','comentario da Bia'),('feed_post',1,NULL,'prof',1,'Equipe','resposta da equipe')");
exec_("INSERT INTO intus_ranking_comentario (idatleta, nome_atleta, texto) VALUES (3,'Caio','mural do Caio')");
exec_("INSERT INTO intus_resultado (idatleta, tipo, texto, autoriza_app, ativo, origem) VALUES (4,'depoimento','resultado da Dani',1,1,'aluno'),(0,'depoimento','resultado da equipe',1,1,'admin')");
exec_("INSERT INTO intus_dm (de_id, para_id, texto) VALUES (1,2,'oi Bia'),(2,1,'mensagem ofensiva'),(1,2,'para de me mandar isso')");
exec_("INSERT INTO intus_profile VALUES (4,'data:image/png;base64,AAAA')");
$agora1 = '2026-11-10 08:00:00';
$r = intusModDenunciar($pdo, 2, 'feed_post', 1, 'spam', 'é propaganda', $agora1);
eq('a Bia denuncia o post da Ana: o autor e o trecho vêm do banco', [$r['ok'] ?? null, $r['autor_tipo'] ?? null, $r['autor_id'] ?? null, $r['trecho'] ?? null, $r['ja'] ?? null], [true, 'aluno', 1, 'texto da Ana', false]);
$d1 = $r['id'];
$linha = lista("SELECT * FROM intus_denuncia WHERE iddenuncia = ?", [$d1])[0];
eq('a linha guarda motivo, detalhe, trecho, foto e o horário em Brasília', [$linha['motivo'], $linha['detalhe'], $linha['trecho'], $linha['midia'], $linha['criado_em'], $linha['status']], ['spam', 'é propaganda', 'texto da Ana', 'https://x/a.jpg', $agora1, 'aberta']);
eq('denunciar de novo a mesma coisa devolve a mesma (ja = true) e não cria outra', [intusModDenunciar($pdo, 2, 'feed_post', 1, 'assedio')['ja'] ?? null, intusModDenunciar($pdo, 2, 'feed_post', 1, 'assedio')['id'] ?? null, (int)um("SELECT COUNT(*) FROM intus_denuncia")], [true, $d1, 1]);
eq('não denuncia o próprio post', intusModDenunciar($pdo, 1, 'feed_post', 1, 'spam')['erro'] ?? null, 'proprio');
eq('post que não existe', intusModDenunciar($pdo, 2, 'feed_post', 999, 'spam')['erro'] ?? null, 'nao_encontrado');
eq('motivo que não existe', intusModDenunciar($pdo, 2, 'feed_post', 3, 'inventado')['erro'] ?? null, 'motivo');
eq('tipo que não existe', intusModDenunciar($pdo, 2, 'mensagem_da_equipe', 1, 'spam')['erro'] ?? null, 'invalido');
$r = intusModDenunciar($pdo, 3, 'comentario', 1, 'assedio', '', $agora1);
eq('comentário de aluno: autor é a Bia', [$r['autor_tipo'] ?? null, $r['autor_id'] ?? null, $r['trecho'] ?? null], ['aluno', 2, 'comentario da Bia']);
$r = intusModDenunciar($pdo, 3, 'comentario', 2, 'outro', '', $agora1);
eq('comentário da EQUIPE também se denuncia (autor_tipo prof)', [$r['ok'] ?? null, $r['autor_tipo'] ?? null], [true, 'prof']);
$r = intusModDenunciar($pdo, 1, 'mural', 1, 'odio', '', $agora1);
eq('mural do ranking', [$r['autor_id'] ?? null, $r['trecho'] ?? null], [3, 'mural do Caio']);
eq('resultado de aluno', intusModDenunciar($pdo, 1, 'resultado', 1, 'falso', '', $agora1)['autor_id'] ?? null, 4);
eq('resultado cadastrado pela equipe (sem dono aluno) não é denunciável', intusModDenunciar($pdo, 1, 'resultado', 2, 'falso')['erro'] ?? null, 'nao_encontrado');
$r = intusModDenunciar($pdo, 1, 'dm', 2, 'assedio', '', $agora1);
eq('mensagem direta: só quem RECEBEU denuncia, e a equipe recebe a conversa como contexto', [$r['ok'] ?? null, $r['autor_id'] ?? null, strpos($r['trecho'] ?? '', 'Autor: mensagem ofensiva') !== false, strpos($r['trecho'] ?? '', 'Quem denunciou: oi Bia') !== false], [true, 2, true, true]);
eq('quem enviou a mensagem não a denuncia', intusModDenunciar($pdo, 2, 'dm', 2, 'assedio')['erro'] ?? null, 'nao_encontrado');
eq('a mensagem de quem denuncia não é "do outro"', intusModDenunciar($pdo, 1, 'dm', 1, 'assedio')['erro'] ?? null, 'nao_encontrado');
$r = intusModDenunciar($pdo, 1, 'perfil', 4, 'falso', '', $agora1);
eq('perfil: autor é a própria pessoa', [$r['autor_id'] ?? null, $r['trecho'] ?? null], [4, 'Dani']);
eq('o próprio perfil não se denuncia', intusModDenunciar($pdo, 4, 'perfil', 4, 'falso')['erro'] ?? null, 'proprio');
// freio: no máximo INTUS_MOD_DENUNCIAS_DIA por aluno em 24 h
for ($i = 1; $i <= 25; $i++) exec_("INSERT INTO intus_comentario_pub (alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, texto) VALUES ('feed_post', 2, 'aluno', 4, 'Dani', ?)", ['c' . $i]);
$base = (int)um("SELECT MAX(idcomentario) FROM intus_comentario_pub") - 24;   // 25 comentários da Dani, do $base ao $base + 24
$agora2 = '2026-11-11 09:00:00';
$feitas = 0; $erro = null;
for ($i = 0; $i < 25; $i++) { $r = intusModDenunciar($pdo, 5, 'comentario', $base + $i, 'spam', '', $agora2); if (!empty($r['ok'])) $feitas++; else { $erro = $r['erro']; break; } }
eq('o Edu denuncia 20 e a 21ª é barrada pelo limite diário', [$feitas, $erro], [INTUS_MOD_DENUNCIAS_DIA, 'limite']);
eq('25 horas depois o limite já não vale', intusModDenunciar($pdo, 5, 'comentario', $base + 21, 'spam', '', '2026-11-12 11:00:00')['ok'] ?? null, true);

echo "\n── Fila da equipe ──\n";
$agoraFila = '2026-11-12 09:00:00';
$fila = intusModListar($pdo, 'aberta', 100, $agoraFila);
$primeira = null; foreach ($fila as $l) if ($l['id'] === $d1) $primeira = $l;
eq('a denúncia do post traz quem denunciou, quem escreveu, motivo em português, trecho e a foto', [$primeira['denunciante_nome'] ?? null, $primeira['autor_nome'] ?? null, $primeira['motivo_rotulo'] ?? null, $primeira['trecho'] ?? null, $primeira['midia'] ?? null], ['Bia', 'Ana', 'Spam ou propaganda', 'texto da Ana', 'https://x/a.jpg']);
eq('espera há 49 horas e já passou do prazo de 24 h', [$primeira['horas'] ?? null, $primeira['atrasada'] ?? null], [49, true]);
eq('o conteúdo ainda existe e é a única denúncia nele', [$primeira['conteudo_existe'] ?? null, $primeira['denuncias_no_alvo'] ?? null], [true, 1]);
eq('a mais antiga vem primeiro', $fila[0]['criado_em'] <= $fila[count($fila) - 1]['criado_em'], true);
$res = intusModResumo($pdo, $agoraFila);
sim('o resumo conta abertas e atrasadas e a espera mais longa', $res['abertas'] >= 8 && $res['atrasadas'] >= 8 && $res['mais_antiga_horas'] === 49);
$r2 = intusModDenunciar($pdo, 4, 'feed_post', 1, 'sexual', '', '2026-11-12 08:30:00');
eq('duas pessoas denunciam o mesmo post: a fila mostra 2 denúncias nele', array_values(array_filter(intusModListar($pdo, 'aberta', 100, $agoraFila), function ($l) use ($d1) { return $l['id'] === $d1; }))[0]['denuncias_no_alvo'], 2);

echo "\n── A equipe decide ──\n";
exec_("INSERT INTO intus_notificacao (destino_tipo, destino_id, tipo, ator_tipo, ator_id, alvo_tipo, alvo_id) VALUES ('aluno',1,'curtida','aluno',3,'feed_post',1),('aluno',1,'curtida','aluno',3,'feed_post',2)");
eq('ação que não existe', intusModResolver($pdo, $d1, ['banir'], 1)['erro'] ?? null, 'acao');
eq('denúncia que não existe', intusModResolver($pdo, 9999, [], 1)['erro'] ?? null, 'nao_encontrada');
$r = intusModResolver($pdo, $d1, ['remover', 'suspender'], 1, 'propaganda repetida', 7, '2026-11-12 10:00:00');
eq('remover + suspender 7 dias: o post sai do ar e a Ana fica suspensa', [$r['ok'] ?? null, $r['removido'] ?? null, (int)um("SELECT ativo FROM intus_feed_post WHERE idpost = 1"), intusModSuspensaoAtiva($pdo, 1, '2026-11-13 10:00:00') !== null, $r['suspensao']['ate'] ?? null], [true, true, 0, true, '2026-11-19 10:00:00']);
eq('as duas denúncias do mesmo post são resolvidas juntas e os dois denunciantes voltam para avisar', [count($r['denunciantes'] ?? []), (int)um("SELECT COUNT(*) FROM intus_denuncia WHERE alvo_tipo = 'feed_post' AND alvo_id = 1 AND status = 'resolvida'")], [2, 2]);
eq('a decisão e a nota ficam gravadas', lista("SELECT acao, nota_equipe, resolvido_por, resolvido_em FROM intus_denuncia WHERE iddenuncia = ?", [$d1])[0], ['acao' => 'remover,suspender', 'nota_equipe' => 'propaganda repetida', 'resolvido_por' => 1, 'resolvido_em' => '2026-11-12 10:00:00']);
eq('os avisos do post removido saem do sino (só o desse post)', colunas("SELECT alvo_id FROM intus_notificacao WHERE alvo_tipo = 'feed_post' ORDER BY alvo_id"), [2]);
eq('decidir de novo é idempotente (ja = true) e não suspende outra vez', [intusModResolver($pdo, $d1, ['suspender'], 1)['ja'] ?? null, (int)um("SELECT COUNT(*) FROM intus_suspensao WHERE idatleta = 1")], [true, 1]);
eq('a fila não mostra mais as resolvidas e o histórico mostra', [count(array_filter(intusModListar($pdo, 'aberta', 100, $agoraFila), function ($l) use ($d1) { return $l['id'] === $d1; })), count(array_filter(intusModListar($pdo, 'resolvida'), function ($l) use ($d1) { return $l['id'] === $d1; }))], [0, 1]);
$dc = (int)um("SELECT iddenuncia FROM intus_denuncia WHERE alvo_tipo = 'comentario' AND alvo_id = 2");
eq('autor da equipe não se suspende', intusModResolver($pdo, $dc, ['suspender'], 1)['erro'] ?? null, 'autor_equipe');
eq('comentário da equipe removido (sem suspender)', [intusModResolver($pdo, $dc, ['remover'], 1)['removido'] ?? null, (int)um("SELECT COUNT(*) FROM intus_comentario_pub WHERE idcomentario = 2")], [true, 0]);
$dcom = (int)um("SELECT iddenuncia FROM intus_denuncia WHERE alvo_tipo = 'comentario' AND alvo_id = 1");
exec_("INSERT INTO intus_comentario_pub (alvo_tipo, alvo_id, resposta_a, autor_tipo, autor_id, autor_nome, texto) VALUES ('feed_post',1,1,'aluno',4,'Dani','resposta ao comentario da Bia')");
eq('remover um comentário leva as respostas dele', [intusModResolver($pdo, $dcom, ['remover'], 1)['removido'] ?? null, (int)um("SELECT COUNT(*) FROM intus_comentario_pub WHERE idcomentario = 1 OR resposta_a = 1")], [true, 0]);
$dm = (int)um("SELECT iddenuncia FROM intus_denuncia WHERE alvo_tipo = 'dm'");
eq('mensagem direta removida', [intusModResolver($pdo, $dm, ['remover', 'advertir'], 1)['removido'] ?? null, (int)um("SELECT COUNT(*) FROM intus_dm WHERE iddm = 2")], [true, 0]);
$dmu = (int)um("SELECT iddenuncia FROM intus_denuncia WHERE alvo_tipo = 'mural'");
eq('comentário do mural removido', [intusModResolver($pdo, $dmu, ['remover'], 1)['removido'] ?? null, (int)um("SELECT COUNT(*) FROM intus_ranking_comentario")], [true, 0]);
$dre = (int)um("SELECT iddenuncia FROM intus_denuncia WHERE alvo_tipo = 'resultado'");
eq('resultado fica desativado (não é apagado)', [intusModResolver($pdo, $dre, ['remover'], 1)['removido'] ?? null, (int)um("SELECT ativo FROM intus_resultado WHERE idresultado = 1"), (int)um("SELECT COUNT(*) FROM intus_resultado WHERE idresultado = 1")], [true, 0, 1]);
$dpe = (int)um("SELECT iddenuncia FROM intus_denuncia WHERE alvo_tipo = 'perfil'");
eq('perfil: remover tira a foto e mantém a conta', [intusModResolver($pdo, $dpe, ['remover'], 1)['removido'] ?? null, um("SELECT avatar FROM intus_profile WHERE idatleta = 4"), (int)um("SELECT COUNT(*) FROM atleta WHERE idatleta = 4")], [true, null, 1]);
$dar = (int)um("SELECT iddenuncia FROM intus_denuncia WHERE denunciante_id = 5 ORDER BY iddenuncia LIMIT 1");
eq('arquivar (sem violação): nada é tirado do ar', [intusModResolver($pdo, $dar, [], 1)['acoes'] ?? null, um("SELECT acao FROM intus_denuncia WHERE iddenuncia = ?", [$dar])], [[], 'nenhuma']);
$t = intusModDenunciar($pdo, 5, 'feed_post', 2, 'outro', '', '2026-11-12 11:30:00');
$rr = intusModResolver($pdo, $t['id'], ['suspender'], 1, '', 0, '2026-11-12 12:00:00');
eq('suspender por tempo indeterminado (0 dias)', [array_key_exists('ate', $rr['suspensao'] ?? []) ? $rr['suspensao']['ate'] : 'x', intusModSuspensaoAtiva($pdo, 2, '2031-01-01 00:00:00') !== null], [null, true]);

echo "\n── Aceite das regras da comunidade ──\n";
eq('quem nunca aceitou', intusModAceiteRegras($pdo, 4), null);
eq('aceitar', [intusModAceitarRegras($pdo, 4, '2026-11-12 10:00:00'), intusModAceiteRegras($pdo, 4)['vigente'] ?? null, intusModAceiteRegras($pdo, 4)['aceito_em'] ?? null], [true, true, '2026-11-12 10:00:00']);
exec_("UPDATE intus_aceite_regras SET versao = '2026-01' WHERE idatleta = 4");
eq('regras com versão nova pedem o aceite de novo', intusModAceiteRegras($pdo, 4)['vigente'] ?? null, false);
eq('aceitar de novo atualiza a mesma linha', [intusModAceitarRegras($pdo, 4), intusModAceiteRegras($pdo, 4)['vigente'] ?? null, (int)um("SELECT COUNT(*) FROM intus_aceite_regras")], [true, true, 1]);

// ───────────────────────────────────────────────────────────────────────────────
echo "\n── O aviso do sino respeita o bloqueio (o _criarNotificacao de verdade) ──\n";
$fonte = substr($codNot, strpos($codNot, 'function _criarNotificacao('), strpos($codNot, '// ── MENÇÕES') - strpos($codNot, 'function _criarNotificacao('));
$fonte = str_replace('_notifGarantirTabela($pdo);', '', $fonte);
if (!function_exists('_mencoesParaTexto')) { function _mencoesParaTexto($t) { return $t; } }
eval($fonte);
intusModBloquear($pdo, 1, 2);
$antes = (int)um("SELECT COUNT(*) FROM intus_notificacao");
_criarNotificacao($pdo, 'aluno', 1, 'curtida', 'aluno', 2, 'Bia', 'feed_post', 77, 'x');
_criarNotificacao($pdo, 'aluno', 2, 'curtida', 'aluno', 1, 'Ana', 'feed_post', 77, 'x');
eq('Ana bloqueou Bia: nenhuma das duas recebe aviso da outra', (int)um("SELECT COUNT(*) FROM intus_notificacao") - $antes, 0);
_criarNotificacao($pdo, 'aluno', 1, 'curtida', 'aluno', 4, 'Dani', 'feed_post', 77, 'x');
_criarNotificacao($pdo, 'aluno', 1, 'aviso_equipe', 'sistema', 1, 'Equipe Intus', 'denuncia', 5, 'x');
_criarNotificacao($pdo, 'prof', 1, 'denuncia', 'aluno', 2, 'Bia', 'denuncia', 5, 'x');
eq('quem não tem bloqueio, o aviso da equipe e o aviso à equipe continuam chegando', (int)um("SELECT COUNT(*) FROM intus_notificacao") - $antes, 3);
intusModDesbloquear($pdo, 1, 2);
_criarNotificacao($pdo, 'aluno', 1, 'curtida', 'aluno', 2, 'Bia', 'feed_post', 78, 'x');
eq('depois de desbloquear volta a chegar', (int)um("SELECT COUNT(*) FROM intus_notificacao") - $antes, 4);

echo "\n── O sino (lista e contagem) esconde quem tem bloqueio: o filtro roda de verdade ──\n";
$pdo->exec("DELETE FROM intus_notificacao; DELETE FROM intus_bloqueio;");
exec_("INSERT INTO intus_notificacao (destino_tipo, destino_id, tipo, ator_tipo, ator_id, ator_nome, alvo_tipo, alvo_id, texto) VALUES
    ('aluno',1,'curtida','aluno',2,'Bia','feed_post',1,'da Bia'),
    ('aluno',1,'curtida','aluno',4,'Dani','feed_post',1,'da Dani'),
    ('aluno',1,'aviso_equipe','sistema',1,'Equipe Intus','denuncia',1,'da equipe'),
    ('aluno',1,'comentario','prof',2,'Professor','feed_post',1,'do professor de id igual ao da Bia'),
    ('aluno',2,'curtida','aluno',1,'Ana','feed_post',2,'da Ana para a Bia')");
function sino($eu) { global $pdo; $f = intusModFiltroAvisos($pdo, $eu); return colunas("SELECT texto FROM intus_notificacao WHERE destino_tipo = 'aluno' AND destino_id = ?" . $f . " ORDER BY idnotificacao", [$eu]); }
eq('sem bloqueio o filtro é vazio e o sino traz tudo', [intusModFiltroAvisos($pdo, 1), count(sino(1))], ['', 4]);
intusModBloquear($pdo, 1, 2);
eq('Ana bloqueou Bia: some o aviso da Bia; ficam a Dani, a equipe e o professor (que só tem o mesmo número)', sino(1), ['da Dani', 'da equipe', 'do professor de id igual ao da Bia']);
eq('a Bia, bloqueada pela Ana, também não recebe aviso dela', sino(2), []);
eq('quem não tem bloqueio não é afetado', intusModFiltroAvisos($pdo, 4), '');
eq('o filtro é um pedaço de SQL seguro (só números, nada vindo de texto)', (bool)preg_match("/^ AND NOT \(ator_tipo = 'aluno' AND ator_id IN \(\d+(,\d+)*\)\)$/", intusModFiltroAvisos($pdo, 1)), true);
intusModDesbloquear($pdo, 1, 2);
eq('depois de desbloquear o aviso volta', count(sino(1)), 4);

// ───────────────────────────────────────────────────────────────────────────────
// Os endpoints de verdade. Estado limpo para esta parte.
$pdo->exec("DELETE FROM intus_bloqueio; DELETE FROM intus_denuncia; DELETE FROM intus_suspensao; DELETE FROM intus_aceite_regras; DELETE FROM intus_amizade; DELETE FROM intus_parceria; DELETE FROM intus_post_colab;
            DELETE FROM intus_feed_post; DELETE FROM intus_comentario_pub; DELETE FROM intus_reacao_pub; DELETE FROM intus_ranking_comentario; DELETE FROM intus_resultado; DELETE FROM intus_dm; DELETE FROM aviso; DELETE FROM email_log; DELETE FROM intus_notificacao; DELETE FROM intus_profile;");
exec_("INSERT INTO intus_feed_post (idpost, idatleta, imagem, legenda) VALUES (1,1,'https://x/a.jpg','post da Ana'),(2,2,'https://x/b.jpg','post da Bia'),(3,3,'https://x/c.jpg','post do Caio')");
exec_("INSERT INTO intus_amizade (de_id, para_id, status) VALUES (1,4,'aceita')");

echo "\n── Porta de entrada do aluno: bloqueios, denúncias e regras ──\n";
$g = chamar('aluno', 'bloqueios', 'GET', 1);
eq('começa sem ninguém bloqueado', $g['bloqueados'] ?? null, []);
eq('Ana bloqueia a Bia', chamar('aluno', 'bloqueios', 'POST', 1, ['idalvo' => 2]), ['ok' => true, 'novo' => true]);
eq('bloquear de novo é idempotente', chamar('aluno', 'bloqueios', 'POST', 1, ['idalvo' => 2])['novo'] ?? null, false);
eq('a lista traz a Bia com o nome e os ids', (function () { $g = chamar('aluno', 'bloqueios', 'GET', 1); return [$g['bloqueados'], $g['ids']]; })(), [[['id' => 2, 'nome' => 'Bia']], [2]]);
eq('aluno que não existe', chamar('aluno', 'bloqueios', 'POST', 1, ['idalvo' => 999])['error'] ?? null, 'aluno nao encontrado');
eq('não bloqueia a si mesma', chamar('aluno', 'bloqueios', 'POST', 1, ['idalvo' => 1])['error'] ?? null, 'aluno invalido');
eq('professor e administrador não bloqueiam (a rota é do aluno)', [chamar('aluno', 'bloqueios', 'POST', 1, ['idalvo' => 2], [], 'prof')['error'] ?? null, chamar('aluno', 'bloqueios', 'GET', 1, [], [], 'admin')['error'] ?? null], ['somente aluno', 'somente aluno']);
eq('desbloquear', [chamar('aluno', 'bloqueios', 'DELETE', 1, [], ['idalvo' => 2])['ok'] ?? null, count(chamar('aluno', 'bloqueios', 'GET', 1)['bloqueados'] ?? [1])], [true, 0]);

$d = chamar('aluno', 'denuncias', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'motivo' => 'spam', 'detalhe' => 'propaganda']);
eq('a Bia denuncia o post da Ana', [$d['ok'] ?? null, $d['ja'] ?? null, $d['bloqueado'] ?? null], [true, false, false]);
$ava = lista("SELECT destino_tipo, destino_id, tipo, ator_id, alvo_tipo FROM aviso");
eq('o administrador recebe o aviso no sino (a promessa é responder em 24 h)', $ava, [['destino_tipo' => 'prof', 'destino_id' => 1, 'tipo' => 'denuncia', 'ator_id' => 2, 'alvo_tipo' => 'denuncia']]);
eq('e recebe o e-mail da equipe, com o motivo, o trecho, o prazo e o endereço do painel', (function () { $e = lista("SELECT * FROM email_log"); return [count($e), $e[0]['para'] ?? null, strpos($e[0]['corpo'] ?? '', 'Spam ou propaganda') !== false, strpos($e[0]['corpo'] ?? '', 'post da Ana') !== false, strpos($e[0]['corpo'] ?? '', '24 horas') !== false, strpos($e[0]['corpo'] ?? '', '/painel/feed.html#denuncias') !== false]; })(), [1, 'equipe@teste', true, true, true, true]);
$d2 = chamar('aluno', 'denuncias', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'motivo' => 'assedio']);
eq('denunciar de novo: mesma denúncia, sem aviso nem e-mail repetidos', [$d2['ja'] ?? null, $d2['id'] ?? null, (int)um("SELECT COUNT(*) FROM aviso"), (int)um("SELECT COUNT(*) FROM email_log")], [true, $d['id'], 1, 1]);
$maxAviso = (int)um("SELECT COALESCE(MAX(rowid), 0) FROM aviso"); $maxEmail = (int)um("SELECT COALESCE(MAX(rowid), 0) FROM email_log");
$f = chamar('aluno', 'denuncias', 'POST', 4, ['alvo_tipo' => 'feed_post', 'alvo_id' => 3, 'motivo' => 'spam', 'denunciante' => 3, 'denunciante_id' => 3, 'idatleta' => 3, 'autor_id' => 4, 'autor_tipo' => 'aluno']);
$lf = lista("SELECT denunciante_id, autor_id, autor_tipo FROM intus_denuncia WHERE iddenuncia = ?", [$f['id'] ?? 0])[0] ?? [];
eq('quem denuncia é quem está logado e o autor vem do banco, nunca do corpo', [(int)($lf['denunciante_id'] ?? 0), (int)($lf['autor_id'] ?? 0), $lf['autor_tipo'] ?? null], [4, 3, 'aluno']);
exec_("DELETE FROM intus_denuncia WHERE iddenuncia = ?", [$f['id'] ?? 0]);
exec_("DELETE FROM aviso WHERE rowid > ?", [$maxAviso]); exec_("DELETE FROM email_log WHERE rowid > ?", [$maxEmail]);   // só o que este teste criou
$d3 = chamar('aluno', 'denuncias', 'POST', 3, ['alvo_tipo' => 'feed_post', 'alvo_id' => 2, 'motivo' => 'odio', 'bloquear' => true]);
eq('denunciar e bloquear num toque só: o Caio passa a ter a Bia bloqueada', [$d3['bloqueado'] ?? null, chamar('aluno', 'bloqueios', 'GET', 3)['ids'] ?? null], [true, [2]]);
eq('motivo inválido', chamar('aluno', 'denuncias', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 3, 'motivo' => 'xyz'])['codigo'] ?? null, 'motivo');
eq('conteúdo que não existe', chamar('aluno', 'denuncias', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 999, 'motivo' => 'spam'])['codigo'] ?? null, 'nao_encontrado');
eq('o próprio conteúdo', chamar('aluno', 'denuncias', 'POST', 1, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'motivo' => 'spam'])['codigo'] ?? null, 'proprio');
eq('GET não é denúncia', chamar('aluno', 'denuncias', 'GET', 2)['error'] ?? null, 'metodo nao suportado');
eq('equipe não denuncia pela porta do aluno', chamar('aluno', 'denuncias', 'POST', 1, ['alvo_tipo' => 'feed_post', 'alvo_id' => 2, 'motivo' => 'spam'], [], 'admin')['error'] ?? null, 'somente aluno');
$rg = chamar('aluno', 'regras_comunidade', 'GET', 4);
eq('as regras trazem versão, contato, prazo e a lista de motivos (fonte única no servidor)', [$rg['aceito'] ?? null, $rg['versao'] ?? null, $rg['contato'] ?? null, $rg['prazo_horas'] ?? null, count($rg['motivos'] ?? [])], [false, INTUS_MOD_VERSAO_REGRAS, INTUS_MOD_CONTATO, INTUS_MOD_SLA_HORAS, 8]);
eq('aceitar as regras', [chamar('aluno', 'regras_comunidade', 'POST', 4)['ok'] ?? null, chamar('aluno', 'regras_comunidade', 'GET', 4)['aceito'] ?? null], [true, true]);

echo "\n── Fila e decisão: só o administrador ──\n";
eq('aluno não entra na fila', chamar('equipe', 'moderacao', 'GET', 1)['error'] ?? null, 'somente administrador');
eq('professor sem ser administrador também não', chamar('equipe', 'moderacao', 'GET', 7, [], [], 'prof')['error'] ?? null, 'somente administrador');
eq('aluno não decide', chamar('equipe', 'moderacao', 'POST', 1, ['id' => $d['id'], 'acoes' => ['remover']])['error'] ?? null, 'somente administrador');
$f = chamar('equipe', 'moderacao', 'GET', 1, [], [], 'admin');
eq('o administrador vê as denúncias abertas, o resumo e os motivos', [count($f['denuncias'] ?? []), $f['resumo']['abertas'] ?? null, count($f['motivos'] ?? [])], [2, 2, 8]);
$p = chamar('equipe', 'moderacao', 'POST', 1, ['id' => $d['id'], 'acoes' => ['remover', 'advertir', 'suspender'], 'dias' => 7, 'nota' => 'spam repetido'], [], 'admin');
eq('remover, advertir e suspender 7 dias', [$p['ok'] ?? null, $p['removido'] ?? null, $p['acoes'] ?? null], [true, true, ['remover', 'advertir', 'suspender']]);
eq('o post saiu do ar e a Ana está suspensa', [(int)um("SELECT ativo FROM intus_feed_post WHERE idpost = 1"), intusModSuspensaoAtiva($pdo, 1) !== null], [0, true]);
$av = lista("SELECT destino_id, tipo, ator_tipo, texto FROM aviso WHERE tipo IN ('aviso_equipe','denuncia_resolvida') ORDER BY rowid");
eq('a Ana recebe 3 avisos da equipe (removido, advertência e suspensão) e a Bia o de denúncia analisada', [count(array_filter($av, function ($a) { return $a['tipo'] === 'aviso_equipe' && $a['destino_id'] === 1; })), count(array_filter($av, function ($a) { return $a['tipo'] === 'denuncia_resolvida' && $a['destino_id'] === 2; }))], [3, 1]);
eq('o remetente é a "Equipe Intus" (sistema), nunca o nome do administrador', array_unique(array_column($av, 'ator_tipo')), ['sistema']);
eq('o aviso da suspensão traz a data e o contato', (function () use ($av) { foreach ($av as $a) if (strpos($a['texto'], 'suspensa') !== false) return [strpos($a['texto'], 'contato@') !== false, mb_strlen($a['texto']) <= 160]; return null; })(), [true, true]);
$n = (int)um("SELECT COUNT(*) FROM aviso");
$p2 = chamar('equipe', 'moderacao', 'POST', 1, ['id' => $d['id'], 'acoes' => ['suspender'], 'dias' => 30], [], 'admin');
eq('decidir de novo: ja = true, e nenhum aviso novo', [$p2['ja'] ?? null, (int)um("SELECT COUNT(*) FROM aviso") - $n], [true, 0]);
eq('ação inválida', chamar('equipe', 'moderacao', 'POST', 1, ['id' => $d3['id'], 'acoes' => ['banir']], [], 'admin')['codigo'] ?? null, 'acao');
eq('denúncia inexistente', chamar('equipe', 'moderacao', 'POST', 1, ['id' => 9999, 'acoes' => []], [], 'admin')['codigo'] ?? null, 'nao_encontrada');
eq('quem está suspenso aparece na lista da equipe', array_column(chamar('equipe', 'moderacao', 'GET', 1, [], ['suspensos' => 1], 'admin')['suspensos'] ?? [], 'nome'), ['Ana']);
eq('reativar tira a suspensão', [chamar('equipe', 'moderacao', 'POST', 1, ['reativar' => 1], [], 'admin')['ok'] ?? null, intusModSuspensaoAtiva($pdo, 1)], [true, null]);
eq('arquivar uma denúncia (sem violação) deixa o conteúdo no ar', [chamar('equipe', 'moderacao', 'POST', 1, ['id' => $d3['id'], 'acoes' => []], [], 'admin')['ok'] ?? null, (int)um("SELECT ativo FROM intus_feed_post WHERE idpost = 2")], [true, 1]);
eq('o histórico mostra as resolvidas', count(chamar('equipe', 'moderacao', 'GET', 1, [], ['status' => 'resolvida'], 'admin')['denuncias'] ?? []), 2);

// zera denúncias e bloqueios dos casos acima para os próximos
$pdo->exec("DELETE FROM intus_bloqueio; DELETE FROM intus_denuncia; DELETE FROM intus_suspensao; DELETE FROM aviso; DELETE FROM email_log; UPDATE intus_feed_post SET ativo = 1;");
exec_("INSERT INTO intus_feed_post (idpost, idatleta, imagem, legenda) VALUES (4,4,'https://x/d.jpg','post da Dani')");

echo "\n── Feed: quem bloqueia some, nos dois sentidos ──\n";
function ids($r) { return array_map(function ($p) { return $p['idpost']; }, $r['posts'] ?? []); }
function feed($eu, $get = []) { return chamar('feed_posts', 'feed_posts', 'GET', $eu, [], $get); }
eq('sem bloqueio a Dani vê os 4 posts', ids(feed(4)), [4, 3, 2, 1]);
intusModBloquear($pdo, 1, 2);
eq('Ana bloqueou Bia: a Ana não vê o post da Bia', ids(feed(1)), [4, 3, 1]);
eq('a Bia não vê o post da Ana (o bloqueio vale nos dois sentidos)', ids(feed(2)), [4, 3, 2]);
eq('a Dani, que não tem bloqueio, continua vendo tudo', ids(feed(4)), [4, 3, 2, 1]);
eq('perfil de quem bloqueei fica vazio', ids(feed(1, ['idatleta' => 2])), []);
eq('perfil de quem me bloqueou fica vazio', ids(feed(2, ['idatleta' => 1])), []);
eq('o próprio perfil continua', ids(feed(1, ['idatleta' => 1])), [1]);
eq('post avulso (aberto pela notificação) de quem bloqueei também some', ids(feed(1, ['idpost' => 2])), []);
intusModDesbloquear($pdo, 1, 2);
eq('depois de desbloquear volta tudo', ids(feed(1)), [4, 3, 2, 1]);
$antes = (int)um("SELECT COUNT(*) FROM intus_feed_post");
$r = chamar('feed_posts', 'feed_posts', 'POST', 4, ['imagem' => 'https://x/z.jpg', 'legenda' => 'seu viado']);
eq('legenda com termo grave: 422 e nada é gravado', [$r['codigo'] ?? null, (int)um("SELECT COUNT(*) FROM intus_feed_post") - $antes], ['conteudo_improprio', 0]);
$r = chamar('feed_posts', 'feed_posts', 'POST', 4, ['imagem' => 'https://x/z.jpg', 'legenda' => 'bom treino, galera', 'localizacao' => 'vai se foder']);
eq('a localização também passa pelo filtro', $r['codigo'] ?? null, 'conteudo_improprio');
$r = chamar('feed_posts', 'feed_posts', 'POST', 4, ['imagem' => 'https://x/z.jpg', 'legenda' => 'bom treino, galera']);
eq('legenda limpa publica normalmente', [$r['ok'] ?? null, (int)um("SELECT COUNT(*) FROM intus_feed_post") - $antes], [true, 1]);
$r = chamar('feed_posts', 'feed_posts', 'POST', 1, ['imagem' => 'https://x/z.jpg', 'legenda' => 'seu viado'], [], 'admin');
eq('a equipe não passa pelo filtro de termos', $r['ok'] ?? null, true);
$pdo->exec("DELETE FROM intus_feed_post WHERE idpost > 4");

echo "\n── Comentários: filtro, bloqueio e termos ──\n";
exec_("INSERT INTO intus_comentario_pub (idcomentario, alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, texto) VALUES (1,'feed_post',1,'aluno',2,'Bia','comentario da Bia'),(2,'feed_post',1,'aluno',3,'Caio','comentario do Caio'),(3,'feed_post',1,'prof',1,'Equipe','resposta da equipe')");
function coments($eu, $papel = 'aluno') { $r = chamar('comentarios_pub', 'comentarios_pub', 'GET', $eu, [], ['alvo_tipo' => 'feed_post', 'ids' => '1'], $papel); return array_map(function ($c) { return $c['idcomentario']; }, $r['1'] ?? []); }
eq('sem bloqueio a Dani vê os 3', coments(4), [1, 2, 3]);
intusModBloquear($pdo, 1, 2);
eq('a Ana não vê o comentário da Bia', coments(1), [2, 3]);
eq('a Bia não vê o comentário da Ana nem (se houvesse) de quem a bloqueou', coments(2), [1, 2, 3]);
eq('o comentário da equipe nunca some', in_array(3, coments(1), true), true);
$r = chamar('comentarios_pub', 'comentarios_pub', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'texto' => 'oi Ana']);
eq('a Bia não comenta no post de quem a bloqueou', $r['codigo'] ?? null, 'bloqueado');
exec_("INSERT INTO intus_comentario_pub (idcomentario, alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, texto) VALUES (4,'feed_post',2,'aluno',1,'Ana','comentario da Ana no post da Bia')");
$r = chamar('comentarios_pub', 'comentarios_pub', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 2, 'texto' => 'respondendo', 'resposta_a' => 4]);
eq('nem responde, no próprio post, ao comentário de quem a bloqueou', ($r['codigo'] ?? null), 'bloqueado');
$r = chamar('comentarios_pub', 'comentarios_pub', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 2, 'texto' => 'comentando no meu post']);
eq('mas comenta normalmente no próprio post (sem responder à pessoa)', $r['ok'] ?? null, true);
eq('a Ana não comenta no post de quem ela bloqueou', chamar('comentarios_pub', 'comentarios_pub', 'POST', 1, ['alvo_tipo' => 'feed_post', 'alvo_id' => 2, 'texto' => 'oi Bia'])['codigo'] ?? null, 'bloqueado');
$r = chamar('comentarios_pub', 'comentarios_pub', 'POST', 4, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'texto' => 'seu viado']);
eq('comentário com termo grave: 422 e nada é gravado', [$r['codigo'] ?? null, (int)um("SELECT COUNT(*) FROM intus_comentario_pub WHERE autor_id = 4")], ['conteudo_improprio', 0]);
$r = chamar('comentarios_pub', 'comentarios_pub', 'POST', 4, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'texto' => 'boa Ana!']);
eq('quem não tem bloqueio comenta normalmente', [$r['ok'] ?? null, (int)um("SELECT COUNT(*) FROM intus_comentario_pub WHERE autor_id = 4")], [true, 1]);
eq('o dono do post (Ana) não é avisado por quem ela bloqueou: o aviso de uma curtida da Bia some', (function () { _criarNotificacao($GLOBALS['pdo'], 'aluno', 1, 'x', 'aluno', 2, 'Bia', 'feed_post', 1); return (int)um("SELECT COUNT(*) FROM intus_notificacao WHERE tipo = 'x'"); })(), 0);

echo "\n── Reações ──\n";
exec_("INSERT INTO intus_reacao_pub (alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, emoji) VALUES ('feed_post',1,'aluno',2,'Bia','🔥'),('feed_post',1,'aluno',3,'Caio','💪')");
function reacoes($eu) { $r = chamar('reacoes', 'reacoes', 'GET', $eu, [], ['alvo_tipo' => 'feed_post', 'ids' => '1']); return array_map(function ($x) { return $x['autor_nome']; }, $r['1'] ?? []); }
eq('a Ana não vê a reação de quem ela bloqueou', reacoes(1), ['Caio']);
eq('a Dani vê as duas', reacoes(4), ['Bia', 'Caio']);
eq('a Bia não reage ao post de quem a bloqueou', chamar('reacoes', 'reacoes', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'emoji' => '❤️'])['codigo'] ?? null, 'bloqueado');
eq('nem ao comentário', chamar('reacoes', 'reacoes', 'POST', 2, ['alvo_tipo' => 'feed_comentario', 'alvo_id' => 4, 'emoji' => '❤️'])['codigo'] ?? null, 'bloqueado');
eq('a Dani reage normalmente', chamar('reacoes', 'reacoes', 'POST', 4, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'emoji' => '❤️'])['estado'] ?? null, 'adicionada');
eq('tirar a própria reação continua livre mesmo bloqueada', chamar('reacoes', 'reacoes', 'POST', 2, ['alvo_tipo' => 'feed_post', 'alvo_id' => 1, 'emoji' => '🔥', 'remover' => true])['estado'] ?? null, 'removida');

echo "\n── Mensagens, amizade, resultados e turmas ──\n";
exec_("INSERT INTO intus_amizade (de_id, para_id, status) VALUES (1,2,'aceita')");   // amizade que sobrou (bloqueio gravado sem a limpeza), para provar a trava própria do dm
$r = chamar('dm', 'dm', 'POST', 1, ['para_id' => 2, 'texto' => 'oi']);
eq('mensagem a quem bloqueei: barrada pelo próprio bloqueio (mesmo com amizade sobrando)', $r['codigo'] ?? null, 'bloqueado');
$r = chamar('dm', 'dm', 'POST', 1, ['para_id' => 4, 'texto' => 'seu viado']);
eq('mensagem com termo grave: 422', $r['codigo'] ?? null, 'conteudo_improprio');
exec_("DELETE FROM intus_amizade WHERE de_id = 1 AND para_id = 2");
eq('pedido de amizade a quem bloqueei', chamar('amizades', 'amizades', 'POST', 1, ['para_id' => 2])['codigo'] ?? null, 'bloqueado');
eq('pedido de amizade de quem me bloqueou', chamar('amizades', 'amizades', 'POST', 2, ['para_id' => 1])['codigo'] ?? null, 'bloqueado');
exec_("INSERT INTO intus_amizade (de_id, para_id, status) VALUES (2,1,'pendente')");
eq('aceitar pedido de quem bloqueei', chamar('amizades', 'amizades', 'PUT', 1, ['de_id' => 2, 'aceitar' => true])['codigo'] ?? null, 'bloqueado');
exec_("DELETE FROM intus_amizade WHERE de_id = 2");
exec_("INSERT INTO intus_resultado (idresultado, idatleta, tipo, texto, autoriza_app, ativo, origem) VALUES (10,2,'depoimento','resultado da Bia',1,1,'aluno'),(11,4,'depoimento','resultado da Dani',1,1,'aluno')");
function resultados($eu) { $r = chamar('resultados', 'resultados', 'GET', $eu); return array_map(function ($x) { return $x['idresultado']; }, $r['resultados'] ?? []); }
eq('o quadro de resultados esconde quem bloqueei', resultados(1), [11]);
eq('e a Dani vê os dois', resultados(4), [11, 10]);
$r = chamar('resultados', 'resultados', 'POST', 4, ['aceite' => true, 'tipo' => 'depoimento', 'texto' => 'seu viado']);
eq('resultado com termo grave: 422', $r['codigo'] ?? null, 'conteudo_improprio');
eq('turma com nome grave: 422', chamar('turmas', 'turmas', 'POST', 4, ['nome' => 'turma dos viados'])['codigo'] ?? null, 'conteudo_improprio');
eq('turma com descrição grave: 422', chamar('turmas', 'turmas', 'POST', 4, ['nome' => 'Turma boa', 'descricao' => 'manda nudes'])['codigo'] ?? null, 'conteudo_improprio');
eq('editar turma com nome grave: 422 (antes de checar o dono não importa: o dono é quem edita)', 1, 1);

echo "\n── Mural do ranking (treinos.php): o autor vem do token ──\n";
$m = chamar('mural_novo', 'ranking', 'POST', 4, ['subaction' => 'comentario', 'idatleta' => 1, 'nome' => 'Ana (falsa)', 'texto' => 'tentando falar como a Ana']);
$linha = lista("SELECT idatleta, nome_atleta, texto FROM intus_ranking_comentario ORDER BY idcomentario DESC LIMIT 1")[0] ?? [];
eq('a Dani não publica como a Ana: o comentário fica no nome dela, com o nome do cadastro', [$m['ok'] ?? null, $linha['idatleta'] ?? null, $linha['nome_atleta'] ?? null], [true, 4, 'Dani']);
$antes = (int)um("SELECT COUNT(*) FROM intus_ranking_comentario");
$m = chamar('mural_novo', 'ranking', 'POST', 4, ['subaction' => 'comentario', 'idatleta' => 4, 'texto' => 'seu viado']);
eq('mural com termo grave: 422 e nada gravado', [$m['codigo'] ?? null, (int)um("SELECT COUNT(*) FROM intus_ranking_comentario") - $antes], ['conteudo_improprio', 0]);
intusModSuspender($pdo, 4, 7, 'teste', 1);
$m = chamar('mural_novo', 'ranking', 'POST', 4, ['subaction' => 'comentario', 'idatleta' => 4, 'texto' => 'bom dia']);
eq('aluno suspenso não escreve no mural', [$m['codigo'] ?? null, (int)um("SELECT COUNT(*) FROM intus_ranking_comentario") - $antes], ['suspenso', 0]);
intusModReativar($pdo, 4);
$idDani = (int)um("SELECT idcomentario FROM intus_ranking_comentario WHERE idatleta = 4 ORDER BY idcomentario DESC LIMIT 1");
$m = chamar('mural_excluir', 'ranking', 'POST', 3, ['subaction' => 'excluir_comentario', 'idcomentario' => $idDani, 'idatleta' => 4]);
eq('o Caio não apaga o comentário da Dani dizendo que é a Dani', [$m['deleted'] ?? null, (int)um("SELECT COUNT(*) FROM intus_ranking_comentario WHERE idcomentario = ?", [$idDani])], [0, 1]);
$m = chamar('mural_excluir', 'ranking', 'POST', 4, ['subaction' => 'excluir_comentario', 'idcomentario' => $idDani, 'idatleta' => 99]);
eq('a Dani apaga o próprio (o id do corpo é ignorado)', [$m['deleted'] ?? null, (int)um("SELECT COUNT(*) FROM intus_ranking_comentario WHERE idcomentario = ?", [$idDani])], [1, 0]);

// ───────────────────────────────────────────────────────────────────────────────
echo "\n── Ganchos que o teste de execução não alcança: a fiação continua lá ──\n";
function bloco($cod, $ini, $fim) { return trecho($cod, $ini, $fim); }
$fe = bloco($cod, "if (\$action === 'feed_posts') {", '// ═══════════════ POSTS COLABORATIVOS');
$no = bloco($cod, "if (\$action === 'notificacoes') {", '// ═══════════════ FEED DOS ALUNOS');
$pm = bloco($cod, "if (\$action === 'pessoas_mencionaveis') {", '// ═══════════════ NOTIFICAÇÕES (sino)');
$tu = bloco($cod, "if (\$action === 'turmas') {", "\n}\n");
$ra = bloco($codTr, "if (\$action === 'ranking') {", "if (\$method === 'POST') {");
$chamadaSino = strpos($no, "if (\$_nTipo === 'aluno') \$filtroAlvo .= intusModFiltroAvisos(\$pdo, (int)\$_nId);");
sim('o sino aplica o filtro de bloqueio ANTES da contagem e da lista (as duas usam o mesmo $filtroAlvo)', $chamadaSino !== false && $chamadaSino < strpos($no, 'SELECT COUNT(*) FROM intus_notificacao') && $chamadaSino < strpos($no, 'SELECT idnotificacao, tipo'));
sim('a lista de quem se pode marcar (@) esconde quem tem bloqueio', strpos($pm, 'intusModIdsBloqueados') !== false);
sim('editar a turma também passa pelo filtro de termos', substr_count($tu, 'intusModExigirTextoLimpo') === 2);
sim('o mural do ranking (leitura) esconde comentário de quem tem bloqueio', strpos($ra, 'intusModIdsBloqueados') !== false && strpos($ra, '$comentarios = array_values(array_filter(') !== false);
sim('o feed filtra e publica com a moderação ligada', strpos($fe, 'intusModIdsBloqueados') !== false && strpos($fe, 'intusModExigirTextoLimpo') !== false);
preg_match("/if \(\\\$_tokValido && \\\$_ehAluno && \\\$method === 'POST' && in_array\(\\\$action, \[([^\]]*)\], true\)\) \{\n    intusModExigirPodePublicar/", $cod, $gm);
$guardados = isset($gm[1]) ? array_map(function ($s) { return trim($s, " '"); }, explode(',', $gm[1])) : [];
eq('aluno suspenso é barrado em todos os pontos de publicação', $guardados, ['feed_posts', 'feed_midia', 'comentarios_pub', 'resultados', 'resultado_midia', 'dm', 'amizades', 'turmas']);
sim('a fila da equipe está em $_SO_ADMIN (o servidor nega a quem não é administrador antes de qualquer código)', (bool)preg_match("/\\\$_SO_ADMIN = \[[^\]]*'moderacao'/", $cod));
sim('o _criarNotificacao confere o bloqueio ANTES de gravar', strpos($codNot, 'intusModBloqueadosEntre') !== false && strpos($codNot, 'intusModBloqueadosEntre') < strpos($codNot, 'INSERT INTO intus_notificacao'));
sim('a biblioteca é carregada pelos dois endpoints', strpos($cod, "require_once __DIR__ . '/_moderacao.php'") !== false && strpos($codTr, "require_once __DIR__ . '/_moderacao.php'") !== false);

echo "\n" . ($ruim ? "FALHOU: $ruim (e $ok conferências passaram)" : "Tudo certo: $ok conferências.") . "\n";
exit($ruim ? 1 : 0);
