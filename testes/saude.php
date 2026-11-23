<?php
// Endpoint catalogo.php?action=saude (Apple Saúde e Health Connect) rodando de verdade em PHP, com SQLite.
// Extrai o bloco do endpoint do catalogo.php (sem mexer no arquivo) e executa cada caso num processo próprio, porque o endpoint termina com exit.
//   php -c <php.ini com mbstring, pdo_sqlite, sqlite3> testes/saude.php
$SRC = realpath(__DIR__ . '/../app/api/catalogo.php');
$cod = str_replace("\r\n", "\n", file_get_contents($SRC));
$i = strpos($cod, '// ═══════════════ SAÚDE DO CELULAR');
$j = strpos($cod, '// ═══════════════ NUTRIÇÃO: ler a dieta de um PDF', $i);
if ($i === false || $j === false) { fwrite(STDERR, "bloco do endpoint nao encontrado\n"); exit(1); }
$bloco = substr($cod, $i, $j - $i);
// O servidor usa ON DUPLICATE KEY (MySQL); o SQLite do teste faz o mesmo com ON CONFLICT.
$bloco = preg_replace('/(INSERT INTO intus_saude_dia[^"]*?)ON DUPLICATE KEY UPDATE ([^"]*)"/s', '$1ON CONFLICT(idatleta, data, fonte) DO UPDATE SET $2"', $bloco);
$bloco = preg_replace('/(INSERT INTO intus_saude_treino[^"]*?)ON DUPLICATE KEY UPDATE ([^"]*)"/s', '$1ON CONFLICT(idatleta, ext_id) DO UPDATE SET $2"', $bloco);
$bloco = preg_replace('/VALUES\((\w+)\)(?=[,"])/', 'excluded.$1', $bloco);
if (strpos($bloco, 'ON DUPLICATE') !== false) { fwrite(STDERR, "nao converteu o upsert\n"); exit(1); }

if (getenv('CASO_JSON') !== false) {
    $caso = json_decode(getenv('CASO_JSON'), true);
    $pdo = new PDO('sqlite:' . $caso['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_saude_dia (idatleta INTEGER NOT NULL, data TEXT NOT NULL, fonte TEXT NOT NULL, passos INTEGER, distancia_m INTEGER, kcal_ativas INTEGER, sono_min INTEGER, fc_repouso INTEGER, peso_kg REAL, PRIMARY KEY (idatleta, data, fonte))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_saude_treino (idsaude INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, ext_id TEXT NOT NULL, fonte TEXT NOT NULL, tipo TEXT NOT NULL, inicio TEXT NOT NULL, duracao_min INTEGER NOT NULL, distancia_m INTEGER, kcal INTEGER, fc_media INTEGER, importado INTEGER NOT NULL DEFAULT 0, UNIQUE (idatleta, ext_id))");
    $action = 'saude';
    $method = $caso['metodo'];
    $_GET = $caso['get'] ?? [];
    $_tokValido = true;
    $_ehAluno = !empty($caso['aluno']);
    $_ctx = ['idatleta' => $caso['eu'], 'idusuario' => 1];
    $GLOBALS['__corpo'] = $caso['corpo'] ?? [];
    function jsonBody() { return $GLOBALS['__corpo']; }
    function _nutriData($d): ?string { $d = substr(trim((string)$d), 0, 10); if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null; return $d; }
    function _resolverAutorPub($pdo, $_ctx, $_ehAluno) { return [$_ehAluno ? 'aluno' : 'prof', (int)$_ctx['idatleta'], 'x']; }
    function _intusLogErro($e) { return $e->getMessage(); }
    ob_start();
    eval($bloco);
    echo ob_get_clean();
    exit;
}

$ini = php_ini_loaded_file();
$tmp = sys_get_temp_dir() . '/intus_saude_' . getmypid() . '.sqlite';
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
function ver($eu, $aluno = true) { return rodar(['metodo' => 'GET', 'aluno' => $aluno, 'eu' => $eu, 'get' => []]); }
$hoje = date('Y-m-d'); $ontem = date('Y-m-d', strtotime('-1 day')); $antigo = date('Y-m-d', strtotime('-60 days'));

echo "\n── Quem pode ──\n";
eq('equipe não lê nem grava', rodar(['metodo' => 'GET', 'aluno' => false, 'eu' => 1])['error'] ?? null, 'somente aluno');
eq('fonte inválida', post(7, ['fonte' => 'garmin', 'dias' => []])['error'] ?? null, 'fonte invalida');

echo "\n── Gravar e limitar ──\n";
$r = post(7, ['fonte' => 'apple', 'dias' => [
    ['data' => $hoje, 'passos' => 8421, 'distancia_m' => 6100, 'kcal_ativas' => 420, 'sono_min' => 440, 'fc_repouso' => 58, 'peso_kg' => 72.46],
    ['data' => $ontem, 'passos' => 999999, 'sono_min' => 5000, 'fc_repouso' => 5, 'peso_kg' => 5],
    ['data' => $antigo, 'passos' => 100],
    ['data' => 'lixo', 'passos' => 100],
], 'treinos' => [
    ['ext_id' => 'A1', 'tipo' => 'corrida', 'inicio' => $hoje . 'T07:00:00', 'duracao_min' => 35, 'distancia_m' => 5200, 'kcal' => 380, 'fc_media' => 152],
    ['ext_id' => 'A2', 'tipo' => 'corrida', 'inicio' => $hoje . 'T07:00:00', 'duracao_min' => 0],
    ['ext_id' => '', 'tipo' => 'corrida', 'inicio' => $hoje . 'T07:00:00', 'duracao_min' => 20],
]]);
eq('conta só o que passou na validação', [$r['dias'] ?? null, $r['treinos'] ?? null], [2, 1]);
$g = ver(7);
$d = []; foreach ($g['dias'] ?? [] as $x) $d[$x['data']] = $x;
eq('o dia bom guarda tudo (peso com 1 casa)', [$d[$hoje]['passos'] ?? null, $d[$hoje]['sono_min'] ?? null, $d[$hoje]['fc_repouso'] ?? null, (float)($d[$hoje]['peso_kg'] ?? 0)], [8421, 440, 58, 72.5]);
eq('valor absurdo vira vazio, não erro', isset($d[$ontem]) ? [$d[$ontem]['passos'], $d[$ontem]['sono_min'], $d[$ontem]['fc_repouso'], $d[$ontem]['peso_kg']] : 'dia sumiu', [null, null, null, null]);
eq('dia com mais de 45 dias não entra', isset($d[$antigo]), false);
eq('o treino detectado volta com a duração e a distância', [count($g['treinos'] ?? []), $g['treinos'][0]['duracao_min'] ?? null, $g['treinos'][0]['distancia_m'] ?? null, $g['treinos'][0]['importado'] ?? null], [1, 35, 5200, 0]);

echo "\n── Reenviar não duplica ──\n";
post(7, ['fonte' => 'apple', 'dias' => [['data' => $hoje, 'passos' => 9000]], 'treinos' => [['ext_id' => 'A1', 'tipo' => 'corrida', 'inicio' => $hoje . 'T07:00:00', 'duracao_min' => 36]]]);
$g = ver(7);
eq('mesmo dia atualiza em vez de repetir', [count($g['dias']), $g['dias'][0]['passos'] ?? null], [2, 9000]);
eq('mesmo treino (ext_id) atualiza em vez de repetir', [count($g['treinos']), $g['treinos'][0]['duracao_min'] ?? null], [1, 36]);

echo "\n── Importado e privacidade ──\n";
$id = $g['treinos'][0]['idsaude'];
rodar(['metodo' => 'PUT', 'aluno' => true, 'eu' => 8, 'corpo' => ['idsaude' => $id, 'importado' => 1]]);
eq('outro aluno não mexe no treino alheio', ver(7)['treinos'][0]['importado'] ?? null, 0);
rodar(['metodo' => 'PUT', 'aluno' => true, 'eu' => 7, 'corpo' => ['idsaude' => $id, 'importado' => 1]]);
eq('o dono marca como importado', ver(7)['treinos'][0]['importado'] ?? null, 1);
post(8, ['fonte' => 'android', 'dias' => [['data' => $hoje, 'passos' => 1234]]]);
eq('um aluno não vê os dados do outro', [count(ver(8)['dias'] ?? []), ver(8)['dias'][0]['passos'] ?? null, count(ver(8)['treinos'] ?? [])], [1, 1234, 0]);

echo "\n── Desconectar apaga tudo do aluno ──\n";
rodar(['metodo' => 'DELETE', 'aluno' => true, 'eu' => 7]);
eq('apagou os dados do aluno 7', [count(ver(7)['dias'] ?? []), count(ver(7)['treinos'] ?? [])], [0, 0]);
eq('e não tocou nos do aluno 8', count(ver(8)['dias'] ?? []), 1);

@unlink($tmp);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
