<?php
// Endpoint catalogo.php?action=nutri_conteudo (receitas, vídeos e dicas da Nutrição) rodando de verdade em PHP, com SQLite.
// Extrai do catalogo.php o bloco do endpoint (sem mexer no arquivo) e executa cada caso num processo próprio, porque o endpoint termina com exit.
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/conteudo.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$JSON = realpath(__DIR__ . '/../app/painel/nutri-conteudo.json');
$cod = file_get_contents($SRC);
$i = strpos($cod, '// ═══════════════ NUTRIÇÃO: receitas, vídeos e dicas');
$j = strpos($cod, '// Painel da nutri: planos ativos', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco do endpoint nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);
$bloco = str_replace("__DIR__ . '/../painel/nutri-conteudo.json'", var_export($JSON, true), $bloco);

if (getenv('CASO_JSON') !== false) {
    // ── processo de um caso ──
    $caso = json_decode(getenv('CASO_JSON'), true);
    $db = $caso['db'];
    class PdoTeste extends PDO { public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query), $options); } }
    $pdo = new PdoTeste('sqlite:' . $db);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_nutri_conteudo (idconteudo INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL, slug TEXT NOT NULL, titulo TEXT NOT NULL, cat TEXT NULL, ordem INTEGER NOT NULL DEFAULT 0, ativo INTEGER NOT NULL DEFAULT 1, foto TEXT NULL, dados TEXT NULL, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL, UNIQUE (tipo, slug))");
    $action = 'nutri_conteudo';
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $_ehAluno = !empty($caso['aluno']);
    $_ctx = ['admin' => true, 'idusuario' => 1, 'is_aluno' => $_ehAluno];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    function _intusLogErro($e) { return 'erro-' . substr(md5($e->getMessage()), 0, 6); }
    function _salvarImagemFeedB64(string $raw, int $autor, string $pasta = 'feed', string $prefixo = 'feed'): string { return 'https://site/img/' . $pasta . '/' . $prefixo . '_' . $autor . '.jpg'; }
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_conteudo_' . getmypid() . '.sqlite';
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

echo "\n── Importar o e-book ──\n";
$r = rodar(['metodo' => 'POST', 'corpo' => ['subacao' => 'importar']]);
eq('importa 47 receitas e 8 dicas', [$r['ok'] ?? null, $r['novos'] ?? null, $r['ja_existiam'] ?? null], [true, 55, 0]);
$r = rodar(['metodo' => 'POST', 'corpo' => ['subacao' => 'importar']]);
eq('importar de novo não repete nada', [$r['novos'] ?? null, $r['ja_existiam'] ?? null], [0, 55]);
$g = rodar(['metodo' => 'GET']);
eq('equipe lê 47 receitas, 8 dicas, 0 vídeos', [count($g['receitas'] ?? []), count($g['dicas'] ?? []), count($g['videos'] ?? [])], [47, 8, 0]);
$p = $g['receitas'][0] ?? [];
eq('receita volta com slug, foto e ingredientes', [$p['id'] ?? null, $p['foto'] ?? null, count($p['ingredientes'] ?? []) > 0, $p['ativo'] ?? null], ['panqueca-banana-aveia-whey', 'receitas/panqueca-banana-aveia-whey.jpg', true, 1]);
$stro = null; foreach ($g['receitas'] as $x) if ($x['id'] === 'strogonoff-light-frango') $stro = $x;
eq('strogonoff mantém informação nutricional e variação', [$stro['nutricao']['kcal'] ?? null, isset($stro['variacao']['texto']), $stro['dificuldade'] ?? null], [280.0, true, 'Fácil']);

echo "\n── Criar, editar, inativar, excluir ──\n";
$r = rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'video', 'titulo' => 'Como fazer overnight', 'ativo' => 1, 'ordem' => 10,
    'dados' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'duracao' => '3:20', 'lixo' => 'x', 'receita' => 'overnight-iogurte-chia']]]);
eq('cria vídeo', [$r['ok'] ?? null, $r['id'] ?? null], [true, 'como-fazer-overnight']);
$idv = $r['idconteudo'] ?? 0;
$r2 = rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'video', 'titulo' => 'Como fazer overnight', 'dados' => ['url' => 'https://youtu.be/abc12345678']]]);
eq('título repetido ganha slug diferente', $r2['id'] ?? null, 'como-fazer-overnight-2');
$g = rodar(['metodo' => 'GET']);
$v = []; foreach ($g['videos'] ?? [] as $x) if ($x['id'] === 'como-fazer-overnight') $v = $x;
eq('vídeo guarda só os campos conhecidos', [array_key_exists('lixo', $v), $v['url'] ?? null, $v['receita'] ?? null, $v['duracao'] ?? null], [false, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'overnight-iogurte-chia', '3:20']);
$r = rodar(['metodo' => 'PUT', 'corpo' => ['idconteudo' => $idv, 'titulo' => 'Overnight (novo título)', 'ativo' => 0, 'ordem' => 20, 'dados' => ['url' => 'https://youtu.be/xyz98765432']]]);
eq('edita e inativa', $r['ok'] ?? null, true);
$gAluno = rodar(['metodo' => 'GET', 'aluno' => true]);
eq('aluno não vê o item inativo', array_map(fn($x) => $x['titulo'], $gAluno['videos'] ?? []), ['Como fazer overnight']);
$gEq = rodar(['metodo' => 'GET']);
eq('equipe ainda vê os dois vídeos', count($gEq['videos'] ?? []), 2);
eq('aluno vê todas as receitas ativas', count($gAluno['receitas'] ?? []), 47);
$r = rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'dica', 'titulo' => 'Dica nova', 'dados' => ['icone' => '💧', 'texto' => 'Beba água']], 'aluno' => true]);
eq('aluno não grava conteúdo', $r['error'] ?? null, 'somente a equipe');
$r = rodar(['metodo' => 'DELETE', 'get' => ['id' => $idv]]);
$r = rodar(['metodo' => 'GET']);
eq('exclui o vídeo editado', array_map(fn($x) => $x['titulo'], $r['videos'] ?? []), ['Como fazer overnight']);

echo "\n── Validações ──\n";
eq('título obrigatório', (rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'dica', 'titulo' => '  ']]))['error'] ?? null, 'titulo obrigatorio');
eq('tipo inválido', (rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'banner', 'titulo' => 'x']]))['error'] ?? null, 'tipo invalido');
eq('PUT de id que não existe', (rodar(['metodo' => 'PUT', 'corpo' => ['idconteudo' => 99999, 'titulo' => 'x']]))['error'] ?? null, 'nao encontrado');
$r = rodar(['metodo' => 'POST', 'corpo' => ['tipo' => 'receita', 'titulo' => 'Receita com foto', 'cat' => 'cafe', 'foto_base64' => 'data:image/jpeg;base64,AAAA', 'dados' => ['ingredientes' => ['a', '', 'b'], 'preparo' => ['x']]]]);
$g = rodar(['metodo' => 'GET']);
$nova = null; foreach ($g['receitas'] as $x) if ($x['titulo'] === 'Receita com foto') $nova = $x;
eq('foto enviada vira URL do servidor', $nova['foto'] ?? null, 'https://site/img/receitas/receita_1.jpg');
eq('linhas vazias da lista saem', $nova['ingredientes'] ?? null, ['a', 'b']);
eq('sem id, apagar exige id', (rodar(['metodo' => 'DELETE']))['error'] ?? null, 'id obrigatorio');

@unlink($tmp);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
