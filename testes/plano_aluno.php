<?php
// Teste grátis x plano ativo (app/api/_plano_aluno.php), em SQLite na memória: quem está só no teste NÃO publica no Feed nem comenta,
// mas treino, cardio, ranking e pontos seguem livres; cortesia conta como plano de verdade.
//   php -c <ini com pdo_sqlite> testes/plano_aluno.php
require __DIR__ . '/../app/api/_plano_aluno.php';
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$hoje = '2026-10-20';
$teste = fn($ini = '2026-10-19', $venc = '2026-10-22') => ['dshistorico' => 'Teste Grátis (3 dias)', 'tipo_plano' => null, 'dtinicio' => $ini, 'dtvencimento' => $venc, 'stpgto' => 'S', 'stmatricula' => 'ativa'];
$pago = fn($venc = '2026-11-20', $pg = 'S', $st = 'ativa', $desc = 'Consultoria Premium Mensal') => ['dshistorico' => $desc, 'tipo_plano' => 'mensal', 'dtinicio' => '2026-10-20', 'dtvencimento' => $venc, 'stpgto' => $pg, 'stmatricula' => $st];
$so = fn(array $l) => intusPlanoAlunoDeLinhas($l, $hoje)['so_teste'];

echo "\n══ Quem está só no teste ══\n";
eq('teste vigente e mais nada: bloqueia', $so([$teste()]), true);
eq('teste no último dia (vence hoje): ainda bloqueia', $so([$teste('2026-10-17', '2026-10-20')]), true);
eq('teste identificado pelo tipo de plano, sem a palavra no histórico', $so([['dshistorico' => 'Plano', 'tipo_plano' => 'trial', 'dtinicio' => '2026-10-19', 'dtvencimento' => '2026-10-22', 'stpgto' => 'S', 'stmatricula' => 'ativa']]), true);
eq('sem acento também vale (Teste gratis)', $so([['dshistorico' => 'Teste gratis 7 dias', 'dtinicio' => '2026-10-19', 'dtvencimento' => '2026-10-26', 'stpgto' => 'S']]), true);

echo "\n══ Libera com outro plano ativo ══\n";
eq('plano pago em dia junto do teste: libera', $so([$teste(), $pago()]), false);
eq('plano pago ainda dentro da carência de 5 dias: libera', $so([$teste(), $pago('2026-10-16')]), false);
eq('plano pago vencido há 6 dias (fora da carência): volta a bloquear', $so([$teste(), $pago('2026-10-14')]), true);
eq('plano NÃO pago não libera', $so([$teste(), $pago('2026-11-20', 'N')]), true);
eq('plano cancelado não libera', $so([$teste(), $pago('2026-11-20', 'S', 'cancelada')]), true);
eq('plano encerrado não libera', $so([$teste(), $pago('2026-11-20', 'S', 'encerrada')]), true);
eq('plano trancado não libera', $so([$teste(), $pago('2026-11-20', 'S', 'trancada')]), true);
eq('plano inativo não libera', $so([$teste(), $pago('2026-11-20', 'S', 'inativa')]), true);
eq('cortesia de verdade (plano dado de presente) libera', $so([$teste(), $pago('2026-11-20', 'S', 'ativa', 'Cortesia Premium 30 dias')]), false);
eq('parcela futura paga (mesmo plano em parcelas) libera', $so([$teste(), $pago('2027-01-20')]), false);

echo "\n══ Quem NÃO é bloqueado ══\n";
eq('sem matrícula nenhuma: não bloqueia (o app trata por outro caminho)', $so([]), false);
eq('teste já vencido: não bloqueia', $so([$teste('2026-10-10', '2026-10-13')]), false);
eq('teste que ainda não começou: não bloqueia', $so([$teste('2026-10-25', '2026-10-28')]), false);
eq('teste cancelado: não bloqueia', $so([['dshistorico' => 'Teste Grátis', 'dtinicio' => '2026-10-19', 'dtvencimento' => '2026-10-22', 'stpgto' => 'S', 'stmatricula' => 'cancelada']]), false);
eq('só plano pago, sem teste: não bloqueia', $so([$pago()]), false);
eq('matrícula sem vencimento é ignorada', $so([['dshistorico' => 'Teste Grátis', 'dtinicio' => '2026-10-19', 'dtvencimento' => '', 'stpgto' => 'S']]), false);
eq('o nome decide: matrícula com a palavra "teste" no histórico conta como teste mesmo se estiver paga', $so([['dshistorico' => 'Contrato de teste de força', 'dtinicio' => '2026-10-01', 'dtvencimento' => '2026-11-01', 'stpgto' => 'S']]), true);

echo "\n══ Com banco (SQLite) ══\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, dshistorico TEXT, tipo_plano TEXT, dtinicio TEXT, dtvencimento TEXT, stpgto TEXT, stmatricula TEXT DEFAULT 'ativa')");
$ins = $pdo->prepare('INSERT INTO intus_mensalidade (idatleta, dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto, stmatricula) VALUES (?,?,?,?,?,?,?)');
$ins->execute([10, 'Teste Grátis (3 dias)', null, '2026-10-19', '2026-10-22', 'S', 'ativa']);
$ins->execute([11, 'Teste Grátis (3 dias)', null, '2026-10-19', '2026-10-22', 'S', 'ativa']);
$ins->execute([11, 'Consultoria Premium', 'mensal', '2026-10-20', '2026-11-20', 'S', 'ativa']);
$ins->execute([12, 'Consultoria Premium', 'mensal', '2026-10-20', '2026-11-20', 'S', 'ativa']);
eq('aluno 10 (só teste) bloqueado', intusPlanoAluno($pdo, 10, $hoje)['so_teste'], true);
eq('aluno 11 (teste + plano) liberado', intusPlanoAluno($pdo, 11, $hoje), ['teste_ativo' => true, 'plano_ativo' => true, 'so_teste' => false]);
eq('aluno 12 (só plano) liberado', intusPlanoAluno($pdo, 12, $hoje)['so_teste'], false);
eq('aluno 99 (sem matrícula) liberado', intusPlanoAluno($pdo, 99, $hoje)['so_teste'], false);
eq('id inválido não bloqueia', intusPlanoAluno($pdo, 0, $hoje)['so_teste'], false);
$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
eq('banco sem a tabela: não derruba a rota nem bloqueia', intusPlanoAluno($pdo2, 10, $hoje)['so_teste'], false);
// banco antigo sem tipo_plano/stmatricula: o teste ainda é reconhecido pelo histórico
$pdo3 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo3->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo3->exec("CREATE TABLE intus_mensalidade (idatleta INT, dshistorico TEXT, dtinicio TEXT, dtvencimento TEXT, stpgto TEXT)");
$pdo3->exec("INSERT INTO intus_mensalidade VALUES (10, 'Teste Grátis (3 dias)', '2026-10-19', '2026-10-22', 'S')");
eq('tabela sem as colunas novas: ainda bloqueia o teste', intusPlanoAluno($pdo3, 10, $hoje)['so_teste'], true);

echo "\n══ A rota responde 403 e para (processo à parte) ══\n";
function rodarGate(string $ctxJson, string $idSetup): array {
    $tmp = tempnam(sys_get_temp_dir(), 'pa') . '.php';
    $lib = str_replace('\\', '/', realpath(__DIR__ . '/../app/api/_plano_aluno.php'));
    file_put_contents($tmp, "<?php\nrequire '$lib';\n\$pdo = new PDO('sqlite::memory:'); \$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);\n"
        . "\$pdo->exec(\"CREATE TABLE intus_mensalidade (idatleta INT, dshistorico TEXT, tipo_plano TEXT, dtinicio TEXT, dtvencimento TEXT, stpgto TEXT, stmatricula TEXT)\");\n"
        . "\$h = date('Y-m-d'); \$a = date('Y-m-d', strtotime('-1 day')); \$f = date('Y-m-d', strtotime('+2 days')); \$m = date('Y-m-d', strtotime('+30 days'));\n"
        . $idSetup
        . "\$ctx = json_decode('$ctxJson', true);\nintusExigirPlanoParaPublicar(\$pdo, \$ctx);\necho 'PASSOU';\n");
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : ' -n -d extension=pdo_sqlite -d extension=sqlite3') . ' ' . escapeshellarg($tmp);
    $saida = shell_exec($cmd . ' 2>&1'); @unlink($tmp);
    return [trim((string)$saida)];
}
$soTeste = "\$pdo->exec(\"INSERT INTO intus_mensalidade VALUES (7,'Teste Grátis (3 dias)',NULL,'\$a','\$f','S','ativa')\");\n";
$comPlano = $soTeste . "\$pdo->exec(\"INSERT INTO intus_mensalidade VALUES (7,'Premium','mensal','\$h','\$m','S','ativa')\");\n";
$r = rodarGate('{"is_aluno":true,"idatleta":7,"idusuario":7}', $soTeste)[0];
eq('aluno só no teste: a rota responde com o código so_teste e NÃO passa', strpos($r, 'so_teste') !== false && strpos($r, 'PASSOU') === false, true);
eq('a mensagem fala em plano ativo, sem travessão', strpos($r, 'plano ativo') !== false && strpos($r, '—') === false && strpos($r, '–') === false, true);
eq('aluno com plano ativo passa', rodarGate('{"is_aluno":true,"idatleta":7,"idusuario":7}', $comPlano)[0], 'PASSOU');
eq('professor/admin nunca é barrado por esta regra', rodarGate('{"is_aluno":false,"idusuario":1,"admin":true}', $soTeste)[0], 'PASSOU');

echo "\n══ Estrutura: onde a regra está ligada ══\n";
$cat = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/catalogo.php'));
$tre = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/treinos.php'));
$log = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/aluno_login.php'));
$i = strpos($cat, "in_array(\$action, ['feed_posts', 'feed_midia', 'comentarios_pub'], true)");
eq('catalogo.php barra POST de feed_posts, feed_midia e comentarios_pub para aluno', $i !== false && strpos(substr($cat, $i - 200, 700), '$method === \'POST\'') !== false && strpos(substr($cat, $i, 500), 'intusExigirPlanoParaPublicar($pdo, $_ctx)') !== false, true);
eq('a trava vem ANTES das rotas (antes do primeiro "if ($action === \'comentarios_pub\')")', $i !== false && $i < strpos($cat, "if (\$action === 'comentarios_pub')"), true);
eq('a trava só vale para aluno logado e só no POST (editar e apagar o que já existe seguem livres)', strpos(substr($cat, $i - 200, 400), '$_ehAluno && $method === \'POST\'') !== false, true);
$j = strpos($tre, "if (\$subaction === 'comentario') {");
eq('treinos.php barra o mural dos membros (comentario) com a mesma regra', $j !== false && strpos(substr($tre, $j, 600), 'intusExigirPlanoParaPublicar($pdo, $_authCtx)') !== false, true);
eq('treinos.php NÃO barra registro de treino nem de cardio (continuam livres no teste)', substr_count($tre, 'intusExigirPlanoParaPublicar($pdo') === 1, true);
eq('o cadastro da conta nova entra no ranking de forma explícita', strpos($log, "\$cols[]='ranking_optin'; \$vals[]='S';") !== false, true);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
