<?php
// catalogo.php?action=email_diag e ?action=email_teste (diagnóstico e envio de teste do e-mail do site).
// Antes (até 11/11/2026): abriam o SMTP com verify_peer=false (a senha ia sem conferir o certificado) e QUALQUER professor
// enviava e-mail pela conta da empresa a qualquer endereço, sem limite. Agora: conexão por intusSmtpAbrir() (a mesma do envio normal),
// email_teste só para administrador e no máximo 5 por conta a cada 15 minutos.
// Extrai os dois blocos do endpoint sem mexer no arquivo e roda cada caso num processo próprio, com um socket de mentira (par de
// sockets já carregado com as respostas do servidor) no lugar do SMTP, e _rate_limit.php de mentira que conta as chamadas num arquivo.
//   php [-c <php.ini>] testes/email_smtp.php          (INTUS_CATALOGO_PATH=<arquivo> confere outra cópia do catalogo.php)
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }

$SRC = getenv('INTUS_CATALOGO_PATH') ?: realpath(__DIR__ . '/../app/api/catalogo.php');
if (!$SRC || !is_file($SRC)) { fwrite(STDERR, "catalogo.php nao encontrado\n"); exit(1); }
$cod = str_replace("\r\n", "\n", file_get_contents($SRC));
function bloco($cod, $ini, $fim) {
    $i = strpos($cod, $ini); $j = $i === false ? false : strpos($cod, $fim, $i);
    if ($i === false || $j === false) { fwrite(STDERR, "bloco nao encontrado: $ini\n"); exit(1); }
    return substr($cod, $i, $j - $i);
}
$diag  = bloco($cod, "if (\$action === 'email_diag') {", '// ═══════════════ ENVIO DE TESTE');
$teste = bloco($cod, "if (\$action === 'email_teste') {", '// ═══════════════ AGENDA / EVENTOS');

$SENHA = 'SENHA-SECRETA-123';
$raiz = sys_get_temp_dir() . '/intus_smtp_' . getmypid();
@mkdir("$raiz/app/api", 0777, true); @mkdir("$raiz/app/config", 0777, true);
register_shutdown_function(function () use ($raiz) {
    foreach (glob("$raiz/app/api/*") ?: [] as $f) @unlink($f);
    foreach (glob("$raiz/*") ?: [] as $f) if (is_file($f)) @unlink($f);
    @rmdir("$raiz/app/api"); @rmdir("$raiz/app/config"); @rmdir("$raiz/app"); @rmdir($raiz);
});
$pf = DIRECTORY_SEPARATOR === '\\' ? 'STREAM_PF_INET' : 'STREAM_PF_UNIX';

// _smtp_seguro.php de mentira: registra a chamada e devolve um socket já carregado com as respostas do servidor (ou null = sem conexão segura).
// Respostas na ordem em que o bloco as lê, SEM saudação (a de verdade já vem lida por intusSmtpAbrir):
// EHLO 250, AUTH 334, usuário 334, senha 235, MAIL 250, RCPT 251, DATA 354, fim 250, QUIT 221.
file_put_contents("$raiz/app/api/_smtp_seguro.php", "<?php
function intusSmtpEstrito(): bool { return false; }
function intusSmtpAbrir(string \$host, ?array &\$info = null, int \$timeout = 12, ?bool \$estrito = null) {
    file_put_contents(getenv('T_LOG'), \"abrir \$host\\n\", FILE_APPEND);
    if (getenv('T_SMTP') === 'falha') { \$info = ['host' => \$host, 'nome' => null, 'porta' => null, 'verificado' => false, 'erro' => 'sem conexao segura (teste)', 'detalhes' => []]; return null; }
    [\$a, \$b] = stream_socket_pair($pf, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite(\$b, \"250 ok\\r\\n334 u\\r\\n334 p\\r\\n235 autenticado\\r\\n250 ok\\r\\n251 destino\\r\\n354 dados\\r\\n250 aceito\\r\\n221 tchau\\r\\n\");
    register_shutdown_function(function () use (\$b) { stream_set_blocking(\$b, false); file_put_contents(getenv('T_ENVIADO'), (string)stream_get_contents(\$b)); });
    \$info = ['host' => \$host, 'nome' => \$host, 'porta' => 465, 'verificado' => true, 'erro' => '', 'detalhes' => []];
    return \$a;
}
");
// _rate_limit.php de mentira: mesma assinatura e mesmo efeito (429 ao passar do máximo), contando num arquivo; registra cada chamada.
file_put_contents("$raiz/app/api/_rate_limit.php", "<?php
function _rl(): array { \$f = getenv('T_RL'); return is_file(\$f) ? (json_decode(file_get_contents(\$f), true) ?: []) : []; }
function checkRateLimitConta(\$pdo, string \$acao, string \$chave, int \$max = 8, int \$janela = 900): void {
    file_put_contents(getenv('T_LOG'), \"checar \$acao \$chave max=\$max janela=\$janela\\n\", FILE_APPEND);
    \$n = count(array_filter(_rl(), fn(\$x) => \$x === \"\$acao|\$chave\"));
    if (\$n >= \$max) { http_response_code(429); header('Retry-After: ' . \$janela); echo json_encode(['error' => 'muitas_tentativas']); exit; }
}
function recordAttemptConta(\$pdo, string \$acao, string \$chave): void {
    file_put_contents(getenv('T_LOG'), \"registrar \$acao \$chave\\n\", FILE_APPEND);
    \$l = _rl(); \$l[] = \"\$acao|\$chave\"; file_put_contents(getenv('T_RL'), json_encode(\$l));
}
");
function montar($raiz, $acao, $codigoBloco) {
    $senha = $GLOBALS['SENHA'];
    file_put_contents("$raiz/app/api/bloco_$acao.php", "<?php
\$pdo = new PDO('sqlite::memory:');
\$_tokValido = true; \$_ehAluno = getenv('T_ALUNO') === '1';
\$_ctx = ['admin' => getenv('T_ADMIN') === '1', 'idusuario' => (int)getenv('T_USER')];
\$action = '$acao';
function jsonBody() { return json_decode(getenv('T_BODY') ?: '{}', true); }
function intusSmtpConfig() { return ['host' => 'smtp.teste.invalid', 'user' => 'contato@teste.invalid', 'pass' => '$senha', 'from_name' => 'Intus']; }
register_shutdown_function(function () { fwrite(STDERR, '__STATUS__=' . (http_response_code() ?: 200)); });
" . $codigoBloco);
}
montar($raiz, 'email_diag', $diag);
montar($raiz, 'email_teste', $teste);
$phpIni = php_ini_loaded_file();

/** Roda o bloco numa conta/estado. $o: acao, admin, aluno, user, body, smtp (ok|falha). Estado do limite fica em $raiz/rl.json. */
function rodar($raiz, $o) {
    global $phpIni;
    @unlink("$raiz/log.txt"); @unlink("$raiz/enviado.txt");
    $env = ['T_LOG' => "$raiz/log.txt", 'T_ENVIADO' => "$raiz/enviado.txt", 'T_RL' => "$raiz/rl.json", 'T_SMTP' => $o['smtp'] ?? 'ok',
            'T_ADMIN' => !empty($o['admin']) ? '1' : '0', 'T_ALUNO' => !empty($o['aluno']) ? '1' : '0', 'T_USER' => (string)($o['user'] ?? 1),
            'T_BODY' => json_encode($o['body'] ?? []), 'PATH' => getenv('PATH'), 'SystemRoot' => getenv('SystemRoot')];
    $cmd = [PHP_BINARY]; if ($phpIni) { $cmd[] = '-c'; $cmd[] = $phpIni; } $cmd[] = "$raiz/app/api/bloco_{$o['acao']}.php";
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    preg_match('/__STATUS__=(\d+)/', $err, $m);
    $log = is_file("$raiz/log.txt") ? file("$raiz/log.txt", FILE_IGNORE_NEW_LINES) : [];
    return ['status' => (int)($m[1] ?? 0), 'json' => json_decode($out, true), 'cru' => $out . $err, 'log' => $log,
            'enviado' => is_file("$raiz/enviado.txt") ? file_get_contents("$raiz/enviado.txt") : '', 'abriu' => count(array_filter($log, fn($l) => strpos($l, 'abrir ') === 0))];
}
$limpaLimite = function () use ($raiz) { @unlink("$raiz/rl.json"); };

echo "\n══ email_teste: só administrador ══\n";
$limpaLimite();
$r = rodar($raiz, ['acao' => 'email_teste', 'admin' => false, 'user' => 7, 'body' => ['para' => 'alguem@exemplo.com']]);
eq('professor comum: 403 e nenhuma conexão com o SMTP', $r['status'] === 403 && $r['abriu'] === 0 && $r['enviado'] === '', $r['cru']);
$r = rodar($raiz, ['acao' => 'email_teste', 'aluno' => true, 'admin' => false, 'user' => 3, 'body' => ['para' => 'alguem@exemplo.com']]);
eq('aluno: 403 e nenhuma conexão com o SMTP', $r['status'] === 403 && $r['abriu'] === 0, $r['cru']);
$r = rodar($raiz, ['acao' => 'email_teste', 'admin' => true, 'user' => 1, 'body' => ['para' => 'isto nao e email']]);
eq('endereço inválido: 400, sem conexão e sem gastar o limite', $r['status'] === 400 && $r['abriu'] === 0 && !is_file("$raiz/rl.json"), $r['cru']);

echo "\n══ email_teste: administrador envia pela conexão verificada ══\n";
$r = rodar($raiz, ['acao' => 'email_teste', 'admin' => true, 'user' => 1, 'body' => ['para' => 'destino@exemplo.com']]);
$j = $r['json'];
eq('abre o SMTP exatamente uma vez, pelo intusSmtpAbrir, no host configurado', $r['abriu'] === 1 && in_array('abrir smtp.teste.invalid', $r['log'], true), json_encode($r['log']));
eq('as etapas batem com as respostas do servidor (sem ler saudação que já veio lida)', is_array($j) && ($j['ehlo'] ?? 0) === 250 && ($j['entrada'] ?? 0) === 235 && ($j['remetente'] ?? 0) === 250 && ($j['destinatario'] ?? 0) === 251 && ($j['abre_dados'] ?? 0) === 354 && ($j['aceita_pelo_servidor'] ?? 0) === 250, $r['cru']);
eq('entregue ao servidor e mostra a porta e que o certificado foi conferido', ($j['entregue_ao_servidor'] ?? false) === true && ($j['porta'] ?? 0) === 465 && ($j['certificado_verificado'] ?? false) === true);
eq('o comando enviado leva o destinatário pedido', strpos($r['enviado'], 'RCPT TO:<destino@exemplo.com>') !== false && strpos($r['enviado'], 'AUTH LOGIN') !== false);
eq('a senha NUNCA aparece na resposta (só viaja, em base64, pela conexão conferida)', strpos($r['cru'], $SENHA) === false && strpos($r['enviado'], base64_encode($SENHA)) !== false);

echo "\n══ email_teste: sem conexão segura ══\n";
$r = rodar($raiz, ['acao' => 'email_teste', 'admin' => true, 'user' => 2, 'smtp' => 'falha', 'body' => ['para' => 'destino@exemplo.com']]);
eq('devolve etapa conexão, ok=false e o motivo; nada é enviado', ($r['json']['etapa'] ?? '') === 'conexao' && ($r['json']['ok'] ?? true) === false && strpos((string)($r['json']['detalhe'] ?? ''), 'sem conexao segura') !== false && $r['enviado'] === '', $r['cru']);

echo "\n══ email_teste: limite de frequência ══\n";
$limpaLimite(); $codigos = []; $abriu = [];
for ($i = 1; $i <= 6; $i++) { $r = rodar($raiz, ['acao' => 'email_teste', 'admin' => true, 'user' => 1, 'body' => ['para' => 'destino@exemplo.com']]); $codigos[] = $r['status']; $abriu[] = $r['abriu']; }
eq('as 5 primeiras passam e a 6ª recebe 429', $codigos === [200, 200, 200, 200, 200, 429], json_encode($codigos));
eq('a 6ª nem chega a abrir o SMTP', $abriu === [1, 1, 1, 1, 1, 0], json_encode($abriu));
$r = rodar($raiz, ['acao' => 'email_teste', 'admin' => true, 'user' => 2, 'body' => ['para' => 'destino@exemplo.com']]);
eq('o limite é por conta: outro administrador não é afetado', $r['status'] === 200 && $r['abriu'] === 1, $r['cru']);
$limpaLimite();
$r = rodar($raiz, ['acao' => 'email_teste', 'admin' => true, 'user' => 1, 'body' => ['para' => 'destino@exemplo.com']]);
eq('confere a conta, 5 tentativas e 15 minutos, e só depois abre o SMTP (checar, registrar, abrir)',
   $r['log'] === ['checar email_teste u1 max=5 janela=900', 'registrar email_teste u1', 'abrir smtp.teste.invalid'], json_encode($r['log']));

echo "\n══ email_diag (continua aberto à equipe, agora com conexão verificada) ══\n";
$r = rodar($raiz, ['acao' => 'email_diag', 'aluno' => true, 'admin' => false, 'user' => 3]);
eq('aluno: 403 e nenhuma conexão', $r['status'] === 403 && $r['abriu'] === 0, $r['cru']);
$r = rodar($raiz, ['acao' => 'email_diag', 'admin' => false, 'user' => 7]); $j = $r['json'];
eq('professor: abre pelo intusSmtpAbrir e lê as etapas certas', $r['abriu'] === 1 && ($j['conexao'] ?? '') === 'ok' && ($j['ehlo'] ?? 0) === 250 && ($j['auth_codigo'] ?? 0) === 235 && ($j['auth_ok'] ?? false) === true, $r['cru']);
eq('mostra a porta e que o certificado foi conferido', ($j['porta'] ?? 0) === 465 && ($j['certificado_verificado'] ?? false) === true);
eq('não devolve a senha (só se existe e o tamanho)', strpos($r['cru'], $SENHA) === false && ($j['tem_senha'] ?? false) === true);
$r = rodar($raiz, ['acao' => 'email_diag', 'admin' => true, 'user' => 1, 'smtp' => 'falha']);
eq('sem conexão segura: FALHOU com o motivo, sem enviar nada', ($r['json']['conexao'] ?? '') === 'FALHOU' && strpos((string)($r['json']['detalhe'] ?? ''), 'sem conexao segura') !== false && $r['enviado'] === '', $r['cru']);

echo "\n══ Varredura do código ══\n";
$textos = []; foreach (token_get_all('<?php ' . $diag . $teste) as $t) { if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) $textos[] = $t[1]; }
$tudo = []; foreach (token_get_all(str_replace("\r\n", "\n", file_get_contents($SRC))) as $t) { if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) $tudo[] = $t[1]; }
eq('catalogo.php inteiro não desliga a verificação do certificado (nenhum verify_peer em texto de código)', !preg_grep('/verify_peer/', $tudo));
eq('os dois blocos não abrem socket por conta própria', strpos($diag . $teste, 'stream_socket_client') === false && strpos($diag . $teste, 'fsockopen') === false);
eq('os dois blocos usam intusSmtpAbrir()', substr_count($diag . $teste, 'intusSmtpAbrir(') === 2);
eq('o portão central lista email_teste em $_SO_ADMIN', preg_match("/\\\$_SO_ADMIN = \\[[^\\]]*'email_teste'/", $cod) === 1);
eq('o bloco de email_teste checa administrador e o limite por conta', strpos($teste, "empty(\$_ctx['admin'])") !== false && strpos($teste, 'checkRateLimitConta(') !== false && strpos($teste, 'recordAttemptConta(') !== false);
// a assinatura real de intusSmtpAbrir é a que os blocos usam: (host, &info, timeout)
require_once __DIR__ . '/../app/api/_smtp_seguro.php';
$rf = new ReflectionFunction('intusSmtpAbrir'); $ps = $rf->getParameters();
eq('intusSmtpAbrir real: (host, &info, timeout, estrito)', count($ps) >= 3 && $ps[0]->getName() === 'host' && $ps[1]->isPassedByReference() && $ps[2]->getName() === 'timeout');

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
