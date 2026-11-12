<?php
// Rota legada auth.php?action=login_atleta (mesma brecha do achado S2, em versão pior), com o ARQUIVO DE VERDADE rodando num servidor php -S
// com SQLite (testes/_login_servidor.php): o código de acesso sozinho não entra, conta sem senha não entra nem tem senha definida por esta
// rota, há limite de tentativas, e quem tem código + senha certos continua entrando.
//   php -c <ini com pdo_sqlite> testes/auth_atleta.php
date_default_timezone_set('UTC');
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$raiz = realpath(__DIR__ . '/..');
$arq = tempnam(sys_get_temp_dir(), 'auth') . '.sqlite';
$db = new PDO('sqlite:' . $arq, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, genero TEXT, senha TEXT, codacesso TEXT, stbloqueio TEXT DEFAULT 'N')");
$db->exec("CREATE TABLE sessao (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT, tipo TEXT, ref_id INT, expires_at TEXT)");
$db->exec("CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, action TEXT NOT NULL, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$ins = $db->prepare('INSERT INTO atleta (nome, email, senha, codacesso, stbloqueio) VALUES (?,?,?,?,?)');
$ins->execute(['Ana', 'ana@t.com', password_hash('SenhaForte1', PASSWORD_BCRYPT), 'INT1A2B3C', 'N']);   // 1: com senha
$ins->execute(['Beto', 'beto@t.com', '', '1234', 'N']);                                                  // 2: SEM senha, código de 4 dígitos
$ins->execute(['Caio', 'caio@t.com', 'texto-puro', 'INT0CAIO0', 'N']);                                   // 3: senha antiga em texto puro
$ins->execute(['Dani', 'dani@t.com', password_hash('SenhaDani1', PASSWORD_BCRYPT), 'INT00DANI', 'S']);  // 4: bloqueada

$sock = stream_socket_server('tcp://127.0.0.1:0'); $porta = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
$ini = php_ini_loaded_file();
$cmd = [PHP_BINARY];
if ($ini) { $cmd[] = '-c'; $cmd[] = $ini; } else { array_push($cmd, '-n', '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3'); }
array_push($cmd, '-S', "127.0.0.1:$porta", $raiz . '/testes/_login_servidor.php');
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $raiz . '/testes', array_merge($_ENV, ['LOGIN_BANCO' => $arq, 'PATH' => getenv('PATH')]));
register_shutdown_function(function () use ($proc, $arq) { if (is_resource($proc)) proc_terminate($proc); @unlink($arq); });
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $porta, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

$ipN = 0;
function login(array $corpo, ?string $ip = null): array {
    global $porta, $ipN;
    $ip = $ip ?? '10.2.' . intdiv(++$ipN, 250) . '.' . ($ipN % 250 + 1);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 10, 'header' => "Content-Type: application/json\r\nX-Teste-Ip: $ip\r\n", 'content' => json_encode($corpo)]]);
    $r = @file_get_contents("http://127.0.0.1:$porta/app/api/auth.php?action=login_atleta", false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), json_decode((string)$r, true) ?: ['_cru' => $r]];
}
$sessoes = fn() => (int)$db->query('SELECT COUNT(*) FROM sessao')->fetchColumn();
$senha = fn($id) => (string)$db->query("SELECT senha FROM atleta WHERE idatleta = $id")->fetchColumn();

echo "\n══ Conta sem senha: o código sozinho NÃO entra e NÃO define senha ══\n";
$r = login(['codacesso' => '1234']);
eq('código de 4 dígitos sozinho, em conta sem senha: 401 (antes entregava a sessão)', [$r[0], $sessoes()], [401, 0]);
$r = login(['codacesso' => '1234', 'senha' => 'minha-senha-nova']);
eq('código + uma senha qualquer em conta sem senha: 401 e a senha NÃO é gravada (antes virava o "primeiro acesso" e sequestrava a conta)', [$r[0], $senha(2), $sessoes()], [401, '', 0]);

echo "\n══ Erro único: não confirma se o código existe ══\n";
$a = login(['codacesso' => '9999', 'senha' => 'x']);
$b = login(['codacesso' => 'INT1A2B3C', 'senha' => 'errada']);
$c = login(['codacesso' => 'INT1A2B3C']);
$d = login(['codacesso' => 'INT00DANI', 'senha' => 'SenhaDani1']);
eq('código que não existe, senha errada, senha ausente e conta bloqueada dão a MESMA resposta', [$a, $b, $c, $d], [[401, ['error' => 'Credenciais inválidas']], [401, ['error' => 'Credenciais inválidas']], [401, ['error' => 'Credenciais inválidas']], [401, ['error' => 'Credenciais inválidas']]]);
eq('sem código: 400', login(['senha' => 'x'])[0], 400);

echo "\n══ Quem tem código + senha certos continua entrando ══\n";
$r = login(['codacesso' => 'INT1A2B3C', 'senha' => 'SenhaForte1']);
eq('código + senha: entra, com token de 64 caracteres e uma sessão gravada', [$r[0], preg_match('/^[a-f0-9]{64}$/', (string)($r[1]['token'] ?? '')) === 1, $r[1]['idatleta'] ?? null, $sessoes()], [200, true, 1, 1]);
eq('primeiro_acesso é sempre false (a rota não define mais senha)', $r[1]['primeiro_acesso'] ?? null, false);
$r = login(['codacesso' => 'INT0CAIO0', 'senha' => 'texto-puro']);
eq('senha antiga em texto puro ainda entra e é regravada como hash', [$r[0], strlen($senha(3)) >= 50], [200, true]);

echo "\n══ Limite de tentativas (antes não havia nenhum nesta rota) ══\n";
$ip = '172.20.0.1';
for ($i = 0; $i < 5; $i++) login(['codacesso' => 'INT1A2B3C', 'senha' => 'errada'], $ip);
$r = login(['codacesso' => 'INT1A2B3C', 'senha' => 'SenhaForte1'], $ip);
eq('depois de 5 erros do mesmo IP, a 6ª tentativa (mesmo certa) leva 429', [$r[0], $r[1]['error'] ?? null], [429, 'muitas_tentativas']);
$db->exec('DELETE FROM rate_limits');
for ($i = 0; $i < 8; $i++) login(['codacesso' => '0000'], null);   // 8 IPs diferentes, mesmo código
$r = login(['codacesso' => '0000'], null);
eq('8 erros no MESMO código, de IPs diferentes, travam o código (varrer os 10 mil códigos de 4 dígitos fica inviável)', [$r[0], $r[1]['error'] ?? null], [429, 'muitas_tentativas']);

echo "\n══ Estrutura ══\n";
$php = str_replace("\r\n", "\n", file_get_contents($raiz . '/app/api/auth.php'));
$bl = substr($php, strpos($php, "\$action === 'login_atleta'"), 2600);
eq('a rota não tem mais o ramo que gravava senha no primeiro acesso', strpos($bl, 'elseif ($senha)') === false && substr_count($bl, 'UPDATE atleta SET senha') === 1, true);
eq('a rota usa as duas travas (IP e código) e registra a falha', strpos($bl, "checkRateLimit(\$db, 'auth_login_atleta'") !== false && strpos($bl, "checkRateLimitConta(\$db, 'auth_login_atleta_conta'") !== false && strpos($bl, "recordAttempt(\$db, 'auth_login_atleta')") !== false, true);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
