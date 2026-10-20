<?php
// "Manter conectado" do aluno (app/api/_sessions.php e aluno_login.php), em SQLite na memória com NOW() emulado:
// a sessão vale 90 dias, é renovada a cada uso, o cookie de retomada é HttpOnly/Secure e sair apaga tudo.
//   php -c <ini com pdo_sqlite> testes/sessao_aluno.php
require __DIR__ . '/../app/api/_sessions.php';
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$agora = strtotime('2026-10-19 12:00:00');

echo "\n══ Quando renovar ══\n";
$mais = fn($dias) => date('Y-m-d H:i:s', $agora + $dias * 86400);
eq('sessão recém-criada (90 dias pela frente) não grava nada', intusSessaoNovaValidade($mais(90), $agora), null);
eq('com 80 dias pela frente ainda não renova', intusSessaoNovaValidade($mais(80), $agora), null);
eq('com 74 dias pela frente renova para 90 dias', intusSessaoNovaValidade($mais(74), $agora), $mais(90));
eq('sessão antiga de 30 dias (criada antes desta mudança) é esticada para 90 no primeiro uso', intusSessaoNovaValidade($mais(12), $agora), $mais(90));
eq('data ilegível não renova nem quebra', intusSessaoNovaValidade('lixo', $agora), null);

echo "\n══ Cookie de retomada ══\n";
$o = intusCookieRetomarOpcoes($agora);
eq('HttpOnly, só HTTPS, SameSite Lax, só na API', [$o['httponly'], $o['secure'], $o['samesite'], $o['path']], [true, true, 'Lax', '/app/api/']);
eq('vale 90 dias', $o['expires'], $agora + 90 * 86400);
eq('para apagar, expira no passado', intusCookieRetomarOpcoes($agora, true)['expires'] < $agora, true);

echo "\n══ Sessão de ponta a ponta (SQLite, NOW() emulado) ══\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
$pdo->exec("CREATE TABLE intus_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT, user_id INT, user_type TEXT, user_name TEXT, admin INT DEFAULT 0, created_at TEXT, expires_at TEXT, last_used TEXT, ip TEXT)");
$tk = createSession($pdo, 7, 'aluno', 'Aluna', false, 30);   // o app antigo ainda pede 30 dias: vira 90
eq('createSession devolve um token de 64 caracteres', is_string($tk) && preg_match('/^[a-f0-9]{64}$/', $tk) === 1, true);
$exp = strtotime($pdo->query('SELECT expires_at FROM intus_sessions')->fetchColumn());
eq('aluno que pede 30 dias recebe 90', (int)round(($exp - time()) / 86400), 90);
$tkP = createSession($pdo, 1, 'prof', 'Prof', false, 7);
$expP = strtotime($pdo->query("SELECT expires_at FROM intus_sessions WHERE user_type='prof'")->fetchColumn());
eq('professor continua com 7 dias', (int)round(($expP - time()) / 86400), 7);
// aluno com 10 dias pela frente (sessão antiga): usar renova
$pdo->exec("UPDATE intus_sessions SET expires_at = '" . date('Y-m-d H:i:s', time() + 10 * 86400) . "' WHERE user_type='aluno'");
$s = validateSession($pdo, $tk);
eq('validar devolve o aluno', [$s['user_id'], $s['user_type']], [7, 'aluno']);
$exp2 = strtotime($pdo->query("SELECT expires_at FROM intus_sessions WHERE user_type='aluno'")->fetchColumn());
eq('usar o app empurra a validade para 90 dias', (int)round(($exp2 - time()) / 86400), 90);
// professor com 2 dias pela frente: usar NÃO renova (só aluno tem "manter conectado")
$pdo->exec("UPDATE intus_sessions SET expires_at = '" . date('Y-m-d H:i:s', time() + 2 * 86400) . "' WHERE user_type='prof'");
validateSession($pdo, $tkP);
$expP2 = strtotime($pdo->query("SELECT expires_at FROM intus_sessions WHERE user_type='prof'")->fetchColumn());
eq('professor não é renovado', (int)round(($expP2 - time()) / 86400), 2);
// vencida: não valida
$pdo->exec("UPDATE intus_sessions SET expires_at = '" . date('Y-m-d H:i:s', time() - 60) . "' WHERE user_type='aluno'");
eq('sessão vencida não valida (e NÃO é ressuscitada)', validateSession($pdo, $tk), null);
// sair
$tk2 = createSession($pdo, 8, 'aluno', 'Outra', false, 90);
revokeSession($pdo, $tk2);
eq('revogar apaga a sessão', validateSession($pdo, $tk2), null);

echo "\n══ Estrutura do servidor ══\n";
$php = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/aluno_login.php'));
$ses = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/_sessions.php'));
eq('retomar só aceita sessão de aluno e apaga o cookie quando não vale', strpos($php, "action === 'retomar'") !== false && strpos($php, "'aluno'") !== false && strpos($php, 'intusLimparCookieRetomar();') !== false, true);
eq('retomar respeita conta bloqueada', strpos(substr($php, strpos($php, "action === 'retomar'"), 2200), "'conta bloqueada'") !== false, true);
eq('esquecer apaga a sessão do cabeçalho e a do cookie', strpos($php, "action === 'esquecer'") !== false && substr_count(substr($php, strpos($php, "action === 'esquecer'"), 900), 'revokeSession(') === 2, true);
eq('os dois logins de aluno pedem 90 dias', substr_count($php, "'aluno', \$atleta['nome'] ?? '', false, 90)"), 2);
eq('o cookie só é emitido para aluno', strpos($ses, "if (\$type === 'aluno') intusEmitirCookieRetomar(\$token);") !== false, true);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
