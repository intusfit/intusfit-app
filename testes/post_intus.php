<?php
// Posts da Intus (catalogo.php: action=post_intus e o campo "intus" do Feed) rodando de verdade em PHP, com SQLite: só o administrador cria,
// edita e apaga; o aluno vê só o que já está no ar (não vê agendado, vencido nem oculto); fixado vem primeiro; o ranking é uma foto com o
// nome vindo do cadastro; quem saiu do ranking depois some do post; o link do botão só aceita https; o Feed geral traz os posts da Intus
// só na primeira página. Extrai os blocos do catalogo.php (sem mexer no arquivo) e roda cada caso num processo próprio.
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/post_intus.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = str_replace("\r\n", "\n", file_get_contents($SRC));
function trecho($cod, $ini, $fim) {
    $i = strpos($cod, $ini); $j = $i === false ? false : strpos($cod, $fim, $i);
    if ($i === false || $j === false) { fwrite(STDERR, "trecho nao encontrado: $ini\n"); exit(1); }
    return substr($cod, $i, $j - $i);
}
$funcs = trecho($cod, "if (!defined('INTUS_POST_TIPOS'))", "if (\$action === 'post_intus') {");
// o CREATE TABLE é de MySQL; no teste a tabela é criada pelo próprio teste
$funcs = str_replace('function _intusPostGarantirTabela(PDO $pdo): void {', 'function _intusPostGarantirTabela(PDO $pdo): void { return;', $funcs);
$blocoPost = trecho($cod, "if (\$action === 'post_intus') {", "if (\$action === 'feed_posts') {");
$blocoFeed = trecho($cod, "if (\$action === 'feed_posts') {", '// ═══════════════ POSTS COLABORATIVOS');
$blocoComent = trecho($cod, "if (\$action === 'comentarios_pub') {", '// ═══════════════ PESSOAS QUE PODEM SER MENCIONADAS');
$blocoNotif = trecho($cod, "if (\$action === 'notificacoes') {", '// ═══════════════ FEED DOS ALUNOS');
$blocoNotif = str_replace("require_once __DIR__ . '/_acesso.php';", '', $blocoNotif);
// a biblioteca real de avisos (_notificacoes.php); o CREATE TABLE dela é de MySQL e a tabela é criada pelo teste
$libNotif = preg_replace('/^<\?php/', '', str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/_notificacoes.php')));
$libNotif = str_replace('function _notifGarantirTabela(PDO $pdo) {', 'function _notifGarantirTabela(PDO $pdo) { return;', $libNotif);
$libNotif = str_replace('__DIR__', var_export(realpath(__DIR__ . '/../app/api'), true), $libNotif);   // o require da biblioteca de moderação, de dentro da _notificacoes.php

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require_once __DIR__ . '/../app/api/_moderacao.php';   // o catalogo.php carrega a biblioteca de moderação (bloqueio, filtro de termos) no topo; o teste usa a real
    foreach ([
        "CREATE TABLE IF NOT EXISTS intus_post_intus (idpost INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL DEFAULT 'campanha', titulo TEXT NOT NULL DEFAULT '', texto TEXT, imagem TEXT NOT NULL DEFAULT '', botao_texto TEXT NOT NULL DEFAULT '', botao_link TEXT NOT NULL DEFAULT '', dados TEXT, fixado INTEGER NOT NULL DEFAULT 0, publicar_em TEXT NOT NULL, expira_em TEXT, ativo INTEGER NOT NULL DEFAULT 1, criado_por INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_reacao_pub (idreacao INTEGER PRIMARY KEY AUTOINCREMENT, alvo_tipo TEXT, alvo_id INTEGER, autor_tipo TEXT, autor_id INTEGER, autor_nome TEXT, emoji TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_feed_post (idpost INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER, imagem TEXT, legenda TEXT, destino TEXT DEFAULT 'feed', localizacao TEXT, ativo INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_post_visib (idpost INTEGER PRIMARY KEY, visib TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_perfil_config (idatleta INTEGER PRIMARY KEY, fotos_visib TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_post_colab (idpost INTEGER NOT NULL, idatleta_convidado INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pendente', criado_em TEXT DEFAULT CURRENT_TIMESTAMP, respondido_em TEXT NULL, PRIMARY KEY (idpost, idatleta_convidado))",
        "CREATE TABLE IF NOT EXISTS atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, feed_optin TEXT, ranking_optin TEXT, stbloqueio TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_profile (idatleta INTEGER, avatar TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_feed_pref (idatleta INTEGER, idalvo INTEGER, tipo TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_amizade (de_id INTEGER, para_id INTEGER, status TEXT)",
        "CREATE TABLE IF NOT EXISTS intus_comentario_pub (idcomentario INTEGER PRIMARY KEY AUTOINCREMENT, alvo_tipo TEXT, alvo_id INTEGER, resposta_a INTEGER NULL, autor_tipo TEXT, autor_id INTEGER, autor_nome TEXT, texto TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_notificacao (idnotificacao INTEGER PRIMARY KEY AUTOINCREMENT, destino_tipo TEXT, destino_id INTEGER, tipo TEXT, ator_tipo TEXT, ator_id INTEGER, ator_nome TEXT, alvo_tipo TEXT, alvo_id INTEGER, texto TEXT, lido INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS intus_resultado (idresultado INTEGER PRIMARY KEY, ativo INTEGER)",
        "CREATE TABLE IF NOT EXISTS intus_mensagem (idmensagem INTEGER PRIMARY KEY)",
        "CREATE TABLE IF NOT EXISTS intus_ranking_comentario (idcomentario INTEGER PRIMARY KEY)",
    ] as $q) $pdo->exec($q);
    if (!$pdo->query("SELECT COUNT(*) FROM atleta")->fetchColumn()) {
        $pdo->exec("INSERT INTO atleta VALUES (1,'Ana Souza','S','S',NULL),(2,'Bia Lima','S','S',NULL),(3,'Caio Reis','S','S',NULL),(4,'Dani Melo','S','N',NULL)");
    }
    define('INTUS_CONEXOES_ATIVO', true);
    $action = $caso['action'];
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $_ehAluno = !empty($caso['aluno']);
    $_ctx = ['idatleta' => $caso['eu'], 'idusuario' => $caso['eu'], 'admin' => !empty($caso['admin'])];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    function _resolverAutorPub($pdo, $_ctx, $_ehAluno) {
        if (!$_ehAluno) return ['prof', (int)$_ctx['idusuario'], 'Equipe Intus'];
        $n = $pdo->query("SELECT nome FROM atleta WHERE idatleta = " . (int)$_ctx['idatleta'])->fetchColumn(); return ['aluno', (int)$_ctx['idatleta'], (string)$n];
    }
    function _nomePublicoProf($id, $nome) { return $nome; }
    function _nutriAlunoTemPlano($pdo, $id) { return false; }
    function _resultadoDestinatariosProf($pdo, $id) { return [1]; }
    function _donoDoAlvoPub($pdo, $tipo, $id) { return 0; }
    function _donoDoPostFeed($pdo, $id) { return 0; }
    function intusRegistrarAcesso() {}
    function _feedDetectarTabelaAtleta($pdo) { return 'atleta'; }
    function _midiasDosPosts($pdo, $t, $ids) { return []; }
    function _appBaseUrl() { return '/app'; }
    function _intusLogErro($e) { return 'erro'; }
    function _perfilConfig($pdo, $id) { return ['fotos_visib' => 'todos']; }
    function _intusGarantirUtf8mb4() {}
    function _intusExtImagem(string $bin): string { return substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n" ? 'png' : ''; }
    function _amigosDe($pdo, $id) { return []; }
    eval($libNotif);
    eval($funcs);
    ob_start();
    $blocos = ['post_intus' => $blocoPost, 'feed_posts' => $blocoFeed, 'comentarios_pub' => $blocoComent, 'notificacoes' => $blocoNotif];
    eval($blocos[$action]);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_post_intus_' . getmypid() . '.sqlite';
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
function adm($metodo, $corpo = [], $get = []) { return rodar(['action' => 'post_intus', 'metodo' => $metodo, 'eu' => 1, 'admin' => true, 'corpo' => $corpo, 'get' => $get]); }
function aluno($eu, $get = []) { return rodar(['action' => 'post_intus', 'metodo' => 'GET', 'eu' => $eu, 'aluno' => true, 'get' => $get]); }
function titulos($r) { return array_map(fn($p) => $p['titulo'], $r['posts'] ?? []); }
function agora($sec = 0) { return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->modify(($sec >= 0 ? '+' : '') . $sec . ' seconds')->format('Y-m-d\TH:i'); }

echo "\n── Quem pode mexer ──\n";
eq('aluno não cria post da Intus', rodar(['action' => 'post_intus', 'metodo' => 'POST', 'eu' => 2, 'aluno' => true, 'corpo' => ['tipo' => 'aviso', 'titulo' => 'x', 'texto' => 'y']])['error'] ?? null, 'somente administrador');
eq('professor sem ser administrador também não', rodar(['action' => 'post_intus', 'metodo' => 'POST', 'eu' => 5, 'admin' => false, 'corpo' => ['tipo' => 'aviso', 'titulo' => 'x', 'texto' => 'y']])['error'] ?? null, 'somente administrador');
eq('aluno não apaga', rodar(['action' => 'post_intus', 'metodo' => 'DELETE', 'eu' => 2, 'aluno' => true, 'get' => ['id' => 1]])['error'] ?? null, 'somente administrador');

echo "\n── Validação ──\n";
eq('tipo inválido', adm('POST', ['tipo' => 'xx', 'titulo' => 'a', 'texto' => 'b'])['error'] ?? null, 'tipo invalido');
eq('título obrigatório', adm('POST', ['tipo' => 'campanha', 'titulo' => '  ', 'texto' => 'b'])['error'] ?? null, 'titulo obrigatorio');
eq('sem texto nem imagem não publica', adm('POST', ['tipo' => 'campanha', 'titulo' => 'a'])['error'] ?? null, 'escreva um texto ou escolha uma imagem');
eq('link do botão só com https', adm('POST', ['tipo' => 'campanha', 'titulo' => 'a', 'texto' => 'b', 'botao_link' => 'http://x.com'])['error'] ?? null, 'o link do botao precisa comecar com https://');
eq('javascript: é recusado', adm('POST', ['tipo' => 'campanha', 'titulo' => 'a', 'texto' => 'b', 'botao_link' => 'javascript:alert(1)'])['error'] ?? null, 'o link do botao precisa comecar com https://');
eq('imagem que não é imagem é recusada', adm('POST', ['tipo' => 'campanha', 'titulo' => 'a', 'imagem' => 'data:image/png;base64,' . base64_encode('isso nao e imagem')])['error'] ?? null, 'o arquivo nao e uma imagem valida');
eq('ranking sem ninguém não publica', adm('POST', ['tipo' => 'ranking_semana', 'titulo' => 'Top 5', 'dados' => ['top' => []]])['error'] ?? null, 'o ranking esta vazio: ninguem pontuou neste periodo');
eq('data de publicação inválida', adm('POST', ['tipo' => 'aviso', 'titulo' => 'a', 'texto' => 'b', 'publicar_em' => '31/12/2026'])['error'] ?? null, 'data de publicacao invalida');

echo "\n── O que o aluno enxerga ──\n";
$c1 = adm('POST', ['tipo' => 'campanha', 'titulo' => 'Black Friday', 'texto' => 'Condição especial', 'botao_link' => 'https://wa.me/5545991156006', 'botao_texto' => '']);
eq('campanha criada', isset($c1['idpost']), true);
eq('aluno vê a campanha, com botão "Saiba mais" por padrão', [titulos(aluno(2)), (aluno(2)['posts'][0]['botao_texto'] ?? '')], [['Black Friday'], 'Saiba mais']);
$ag = adm('POST', ['tipo' => 'aviso', 'titulo' => 'Agendado', 'texto' => 'amanhã', 'publicar_em' => agora(3600 * 5)]);
$ve = adm('POST', ['tipo' => 'aviso', 'titulo' => 'Vencido', 'texto' => 'velho', 'publicar_em' => agora(-3600 * 48), 'expira_em' => agora(-3600)]);
$oc = adm('POST', ['tipo' => 'aviso', 'titulo' => 'Oculto', 'texto' => 'escondido']);
adm('PUT', ['idpost' => $oc['idpost'], 'ativo' => false]);
eq('aluno não vê agendado, vencido nem oculto', titulos(aluno(2)), ['Black Friday']);
$st = []; foreach (adm('GET', [], ['todos' => '1'])['posts'] as $p) $st[$p['titulo']] = $p['status'];
eq('a equipe vê todos, com o estado de cada um', $st, ['Black Friday' => 'no_ar', 'Agendado' => 'agendado', 'Vencido' => 'vencido', 'Oculto' => 'oculto']);
eq('aluno não consegue pedir a visão da equipe', count(aluno(2, ['todos' => '1'])['posts'] ?? []), 1);
$fx = adm('POST', ['tipo' => 'aviso', 'titulo' => 'Fixado', 'texto' => 'importante']);
eq('sem fixar, o mais novo vem primeiro', titulos(aluno(2)), ['Fixado', 'Black Friday']);
adm('PUT', ['idpost' => $c1['idpost'], 'fixado' => true]);
eq('fixar o mais antigo põe ele no topo', titulos(aluno(2)), ['Black Friday', 'Fixado']);
adm('PUT', ['idpost' => $c1['idpost'], 'fixado' => false]);
eq('desafixar volta à ordem por data', titulos(aluno(2)), ['Fixado', 'Black Friday']);
adm('PUT', ['idpost' => $fx['idpost'], 'ativo' => false]);
eq('ocultar tira do aluno', titulos(aluno(2)), ['Black Friday']);

echo "\n── Ranking: foto do placar ──\n";
$rk = adm('POST', ['tipo' => 'ranking_semana', 'titulo' => 'Top 5 da semana', 'dados' => [
    'periodo' => ['ini' => '2026-10-25', 'fim' => '2026-10-31', 'rotulo' => '25/10 a 31/10'],
    'top' => [['id' => 1, 'nome' => 'NOME FALSO', 'pts' => 40.4, 'pos' => 1], ['id' => 2, 'nome' => 'Bia', 'pts' => 31, 'pos' => 2], ['id' => 3, 'nome' => 'Caio', 'pts' => 31, 'pos' => 2], ['id' => 4, 'nome' => 'Dani', 'pts' => 20, 'pos' => 4]],
]]);
eq('ranking criado', isset($rk['idpost']), true);
$vi = aluno(2)['posts'] ?? []; $rkp = null; foreach ($vi as $p) if ($p['tipo'] === 'ranking_semana') $rkp = $p;
eq('o nome vem do cadastro, não do navegador', $rkp['dados']['top'][0]['nome'] ?? null, 'Ana Souza');
eq('pontos inteiros e empate preservado (dois em 2º)', array_map(fn($t) => [$t['pos'], $t['pts']], $rkp['dados']['top'] ?? []), [[1, 40], [2, 31], [2, 31]]);
eq('quem não está no ranking (Dani, ranking_optin N) não aparece no post', array_map(fn($t) => $t['id'], $rkp['dados']['top'] ?? []), [1, 2, 3]);
eq('o período fica guardado', $rkp['dados']['periodo']['rotulo'] ?? null, '25/10 a 31/10');
$pp = new PDO('sqlite:' . $tmp); $pp->exec("UPDATE atleta SET ranking_optin = 'N' WHERE idatleta = 3");
$vi = aluno(2)['posts'] ?? []; foreach ($vi as $p) if ($p['tipo'] === 'ranking_semana') $rkp = $p;
eq('quem saiu do ranking depois some do post', array_map(fn($t) => $t['id'], $rkp['dados']['top'] ?? []), [1, 2]);
$pp->exec("UPDATE atleta SET ranking_optin = 'S' WHERE idatleta = 3");
$sem = $pp->query("SELECT expira_em, publicar_em FROM intus_post_intus WHERE idpost = " . (int)$rk['idpost'])->fetch(PDO::FETCH_ASSOC);
eq('ranking da semana vence sozinho em 8 dias', (int)round((strtotime($sem['expira_em']) - strtotime($sem['publicar_em'])) / 86400), 8);
$mes = adm('POST', ['tipo' => 'ranking_mes', 'titulo' => 'Top 10 do mês', 'dados' => ['top' => [['id' => 1, 'nome' => 'x', 'pts' => 100, 'pos' => 1]]]]);
$sem = $pp->query("SELECT expira_em, publicar_em, dados FROM intus_post_intus WHERE idpost = " . (int)$mes['idpost'])->fetch(PDO::FETCH_ASSOC);
eq('ranking do mês vence em 32 dias e guarda n = 10', [(int)round((strtotime($sem['expira_em']) - strtotime($sem['publicar_em'])) / 86400), json_decode($sem['dados'], true)['n']], [32, 10]);

echo "\n── Editar e apagar ──\n";
eq('editar o texto', adm('PUT', ['idpost' => $c1['idpost'], 'titulo' => 'Black Friday 2026'])['ok'] ?? null, true);
eq('o título novo aparece', in_array('Black Friday 2026', titulos(aluno(2)), true), true);
eq('editar post que não existe', adm('PUT', ['idpost' => 999, 'ativo' => false])['error'] ?? null, 'post nao encontrado');
$pp->exec("INSERT INTO intus_reacao_pub (alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, emoji) VALUES ('intus_post', {$c1['idpost']}, 'aluno', 2, 'Bia', '❤️')");
eq('apagar remove o post e as reações dele', [adm('DELETE', [], ['id' => $c1['idpost']])['ok'] ?? null, (int)$pp->query("SELECT COUNT(*) FROM intus_reacao_pub WHERE alvo_tipo = 'intus_post'")->fetchColumn(), in_array('Black Friday 2026', titulos(aluno(2)), true)], [true, 0, false]);

echo "\n── Comentários ──\n";
function comentar($eu, $alvo, $texto, $resp = 0, $admin = false) {
    $c = ['action' => 'comentarios_pub', 'metodo' => 'POST', 'eu' => $eu, 'corpo' => ['alvo_tipo' => 'intus_post', 'alvo_id' => $alvo, 'texto' => $texto] + ($resp ? ['resposta_a' => $resp] : [])];
    if ($admin) $c['admin'] = true; else $c['aluno'] = true;
    return rodar($c);
}
function comentarios($eu, $alvo) { return rodar(['action' => 'comentarios_pub', 'metodo' => 'GET', 'eu' => $eu, 'aluno' => true, 'get' => ['alvo_tipo' => 'intus_post', 'ids' => (string)$alvo]]); }
function avisosDe($tipo, $id) { global $tmp; $p = new PDO('sqlite:' . $tmp); return $p->query("SELECT tipo, ator_tipo, alvo_tipo, alvo_id, texto FROM intus_notificacao WHERE destino_tipo = '$tipo' AND destino_id = $id ORDER BY idnotificacao")->fetchAll(PDO::FETCH_ASSOC); }
$cp = adm('POST', ['tipo' => 'campanha', 'titulo' => 'Promo com conversa', 'texto' => 'comente']);
$cp = $cp['idpost'];
$a1 = comentar(2, $cp, 'Quero saber o valor');
eq('aluno comenta num post no ar', isset($a1['idcomentario']), true);
eq('a administradora é avisada do comentário do aluno', array_map(fn($a) => [$a['tipo'], $a['ator_tipo'], $a['alvo_tipo'], $a['alvo_id']], avisosDe('prof', 1)), [['comentario', 'aluno', 'intus_post', $cp]]);
$a2 = comentar(3, $cp, 'Também quero', $a1['idcomentario']);
eq('resposta avisa o autor do comentário-pai', array_map(fn($a) => $a['tipo'], avisosDe('aluno', 2)), ['resposta']);
$a3 = comentar(1, $cp, 'Respondendo como equipe', $a1['idcomentario'], true);
eq('a equipe também pode responder', isset($a3['idcomentario']), true);
eq('a lista traz os três, com a árvore de respostas', array_map(fn($c) => [$c['autor_nome'], $c['resposta_a'] ? 'resposta' : 'raiz'], comentarios(2, $cp)[(string)$cp] ?? []), [['Bia Lima', 'raiz'], ['Caio Reis', 'resposta'], ['Equipe Intus', 'resposta']]);
eq('"meu" marca só o comentário de quem pergunta', array_map(fn($c) => $c['meu'], comentarios(2, $cp)[(string)$cp] ?? []), [true, false, false]);
$oc2 = adm('POST', ['tipo' => 'aviso', 'titulo' => 'Escondido', 'texto' => 'x']); adm('PUT', ['idpost' => $oc2['idpost'], 'ativo' => false]);
eq('não comenta em post oculto', comentar(2, $oc2['idpost'], 'oi')['error'] ?? null, 'post nao encontrado');
$ag2 = adm('POST', ['tipo' => 'aviso', 'titulo' => 'Futuro', 'texto' => 'x', 'publicar_em' => agora(3600 * 5)]);
eq('não comenta em post agendado', comentar(2, $ag2['idpost'], 'oi')['error'] ?? null, 'post nao encontrado');
eq('não comenta em post que não existe', comentar(2, 9999, 'oi')['error'] ?? null, 'post nao encontrado');
eq('comentário vazio é recusado', comentar(2, $cp, '  ')['error'] ?? null, 'dados incompletos');

echo "\n── Aviso no sino ──\n";
function sino($eu, $get = []) { return rodar(['action' => 'notificacoes', 'metodo' => 'GET', 'eu' => $eu, 'aluno' => true, 'get' => $get]); }
function doIntus($r) { return array_values(array_filter($r['notificacoes'] ?? [], fn($n) => $n['tipo'] === 'intus_post')); }
function textos($r) { return array_map(fn($n) => $n['texto'], doIntus($r)); }
$s5 = sino(5);
eq('aluno que nunca abriu o sino recebe o aviso dos posts no ar (promo, ranking da semana, ranking do mês)', count(doIntus($s5)), 3);
eq('quem está no placar recebe o texto pessoal', in_array('Você ficou em 1º lugar no Top 5 da semana. Parabéns!', textos(sino(1)), true), true);
eq('quem não está no placar recebe só o título', in_array('Top 5 da semana', textos($s5), true), true);
eq('quem saiu do ranking (Dani, ranking_optin N) não recebe "Você ficou em..."', count(array_filter(textos(sino(4)), fn($t) => strpos($t, 'Você ficou') === 0)), 0);
$sinoNovo = sino(6);
eq('o aviso conta como não lido', $sinoNovo['nao_lidas'] ?? null, 3);
eq('consultar de novo não repete o aviso', (sino(6)['nao_lidas'] ?? null), 3);
eq('o contador leve também mostra', (sino(6, ['so_contagem' => 1])['nao_lidas'] ?? null), 3);
eq('o aviso aponta para o post (alvo intus_post)', array_values(array_unique(array_map(fn($n) => $n['alvo_tipo'], doIntus($sinoNovo)))), ['intus_post']);
$pp->exec("UPDATE intus_post_intus SET publicar_em = '" . (new DateTime('-1 minute', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s') . "' WHERE idpost = " . (int)$ag2['idpost']);
eq('post agendado só avisa depois de entrar no ar', in_array('Futuro', textos(sino(6)), true), true);
adm('PUT', ['idpost' => $cp, 'ativo' => false]);
eq('post ocultado some do sino e do contador', [in_array('Promo com conversa', textos(sino(6)), true), (sino(6, ['so_contagem' => 1])['nao_lidas'] ?? null)], [false, 3]);
adm('PUT', ['idpost' => $cp, 'ativo' => true]);
adm('DELETE', [], ['id' => $cp]);
eq('excluir o post apaga os comentários e os avisos dele', [(int)$pp->query("SELECT COUNT(*) FROM intus_comentario_pub WHERE alvo_tipo = 'intus_post'")->fetchColumn(), (int)$pp->query("SELECT COUNT(*) FROM intus_notificacao WHERE alvo_tipo = 'intus_post' AND alvo_id = $cp")->fetchColumn()], [0, 0]);

echo "\n── No Feed ──\n";
$f = rodar(['action' => 'feed_posts', 'metodo' => 'GET', 'eu' => 2, 'aluno' => true, 'get' => []]);
$tiposF = array_map(fn($p) => $p['tipo'], $f['intus'] ?? []); sort($tiposF);
eq('o Feed geral traz os posts da Intus no ar (e só eles)', $tiposF, ['aviso', 'ranking_mes', 'ranking_semana']);
eq('primeira página só: "ver mais" (antes_de) não repete', $f2 = (rodar(['action' => 'feed_posts', 'metodo' => 'GET', 'eu' => 2, 'aluno' => true, 'get' => ['antes_de' => 50]])['intus'] ?? 'sem'), []);
eq('perfil de alguém não traz', (rodar(['action' => 'feed_posts', 'metodo' => 'GET', 'eu' => 2, 'aluno' => true, 'get' => ['idatleta' => 1]])['intus'] ?? 'sem'), []);
eq('filtro "Amigos" não traz', (rodar(['action' => 'feed_posts', 'metodo' => 'GET', 'eu' => 2, 'aluno' => true, 'get' => ['so_amigos' => '1']])['intus'] ?? 'sem'), []);
eq('um post avulso (notificação) não traz', (rodar(['action' => 'feed_posts', 'metodo' => 'GET', 'eu' => 2, 'aluno' => true, 'get' => ['idpost' => 1]])['intus'] ?? 'sem'), []);

echo "\n";
@unlink($tmp);
echo $ruim ? "$ruim FALHA(S), $ok ok.\n" : "Tudo certo: $ok conferências.\n";
exit($ruim ? 1 : 0);
