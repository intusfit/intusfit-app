<?php
// Login do aluno (app/api/aluno_login.php) e teto global de falhas (app/api/_login_protecao.php), em SQLite, com o ENDPOINT DE VERDADE rodando
// num servidor php -S (testes/_login_servidor.php troca só a conexão MySQL por SQLite):
//  - o código de acesso NÃO identifica a conta sozinho (achado S2): código sozinho não entra; e-mail + código entra; e-mail + senha igual a antes;
//  - o teto global de falhas por minuto (todas as IPs somadas) liga o modo de verificação, sem derrubar quem acerta;
//  - com Turnstile: token conferido antes da conta; sem Turnstile (ou Cloudflare fora do ar): limites por IP e por conta mais apertados.
//   php -c <ini com pdo_sqlite> testes/login_protecao.php
date_default_timezone_set('UTC');
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$raiz = realpath(__DIR__ . '/..');
require $raiz . '/app/api/_login_protecao.php';

// ── banco de teste ──
$arq = tempnam(sys_get_temp_dir(), 'login') . '.sqlite';
$db = new PDO('sqlite:' . $arq, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, senha TEXT, codacesso TEXT, stbloqueio TEXT DEFAULT 'N', telefone TEXT,
    cpf TEXT, dtnascimento TEXT, genero TEXT, objetivos TEXT, dificuldades TEXT, plano TEXT, periodo TEXT, dt_cadastro TEXT, ultimo_acesso TEXT)");
$db->exec("CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, action TEXT NOT NULL, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE intus_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT, user_id INT, user_type TEXT, user_name TEXT, admin INT DEFAULT 0, created_at TEXT, expires_at TEXT, last_used TEXT, ip TEXT)");
$db->exec("CREATE TABLE intus_secrets (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)");
$ins = $db->prepare('INSERT INTO atleta (nome, email, senha, codacesso, stbloqueio) VALUES (?,?,?,?,?)');
$ins->execute(['Ana', 'ana@t.com', password_hash('SenhaForte1', PASSWORD_BCRYPT), 'INT1A2B3C', 'N']);   // 1: tem senha e código
$ins->execute(['Beto', 'beto@t.com', '', '1234', 'N']);                                                  // 2: sem senha, código antigo de 4 dígitos
$ins->execute(['Carla', '', '', 'INT9Z9Z9Z', 'N']);                                                      // 3: sem e-mail (só entrava pelo código)
$ins->execute(['Dani', 'dani@t.com', password_hash('SenhaDani1', PASSWORD_BCRYPT), 'INT00DANI', 'S']);  // 4: bloqueada

// ── servidor php -S com o endpoint de verdade ──
$sock = stream_socket_server('tcp://127.0.0.1:0'); $porta = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1); fclose($sock);
$ini = php_ini_loaded_file();
$cmd = [PHP_BINARY];
if ($ini) { $cmd[] = '-c'; $cmd[] = $ini; } else { array_push($cmd, '-n', '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3'); }
array_push($cmd, '-S', "127.0.0.1:$porta", $raiz . '/testes/_login_servidor.php');
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $raiz . '/testes', array_merge($_ENV, ['LOGIN_BANCO' => $arq, 'PATH' => getenv('PATH')]));
register_shutdown_function(function () use ($proc, $arq) { if (is_resource($proc)) proc_terminate($proc); @unlink($arq); });
for ($i = 0; $i < 50; $i++) { $c = @fsockopen('127.0.0.1', $porta, $en, $es, 0.2); if ($c) { fclose($c); break; } usleep(100000); }

$ipN = 0;
/** Faz um login. IP novo a cada pedido, salvo se informado. Devolve [status HTTP, json]. */
function req(array $corpo, ?string $ip = null): array {
    global $porta, $ipN;
    $ip = $ip ?? '10.1.' . intdiv(++$ipN, 250) . '.' . ($ipN % 250 + 1);
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Content-Type: application/json\r\nX-Teste-Ip: $ip\r\n", 'content' => json_encode($corpo)]]);
    $r = @file_get_contents("http://127.0.0.1:$porta/app/api/aluno_login.php", false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), json_decode((string)$r, true) ?: ['_cru' => $r]];
}
function entrou(array $r): bool { return $r[0] === 200 && !empty($r[1]['ok']) && preg_match('/^[a-f0-9]{64}$/', (string)($r[1]['atleta']['server_token'] ?? '')) === 1; }
function limpar(): void { global $db; $db->exec('DELETE FROM rate_limits'); $db->exec('DELETE FROM intus_sessions'); $db->exec('DELETE FROM intus_secrets'); }
function falhas(int $n, string $prefixo = 'x'): void { for ($i = 0; $i < $n; $i++) req(['email' => "$prefixo$i@t.com", 'senha' => 'errada']); }   // cada uma de um IP diferente
function pressao(): bool { global $db; return intusLoginSobPressao($db); }
function turnstile(string $site = 'chave-publica-teste', string $segredo = 'segredo-teste', int $teto = 20): void { global $db; intusLoginCfgGravar($db, ['site_key' => $site, 'secret_key' => $segredo, 'teto' => $teto]); }
$senhaBeto = fn() => (string)$GLOBALS['db']->query("SELECT senha FROM atleta WHERE idatleta = 2")->fetchColumn();

echo "\n══ O código de acesso NÃO identifica a conta sozinho (achado S2) ══\n";
$r = req(['email' => '1234', 'senha' => '1234']);
eq('{email:"1234", senha:"1234"} (código antigo de 4 dígitos nos dois campos) NÃO entra', [$r[0], entrou($r)], [401, false]);
eq('a resposta é a genérica, sem dizer se o código existe', $r[1]['erro'] ?? null, 'credenciais invalidas');
eq('e não gravou senha nenhuma na conta do código (antes o primeiro acesso definia a senha)', $senhaBeto(), '');
$r = req(['email' => 'INT1A2B3C', 'senha' => 'INT1A2B3C']);
eq('código novo nos dois campos NÃO entra', [$r[0], entrou($r)], [401, false]);
$r = req(['email' => 'INT1A2B3C', 'senha' => 'SenhaForte1']);
eq('código no lugar do e-mail + senha verdadeira NÃO entra (o código não acha a conta)', [$r[0], entrou($r)], [401, false]);
$r = req(['email' => 'int1a2b3c', 'senha' => 'int1a2b3c']);
eq('nem em minúsculas', [$r[0], entrou($r)], [401, false]);
$r = req(['email' => 'INT9Z9Z9Z', 'senha' => 'INT9Z9Z9Z']);
eq('aluna sem e-mail cadastrado deixa de entrar pelo código (efeito esperado: precisa ter e-mail cadastrado)', [$r[0], entrou($r)], [401, false]);
$r = req(['email' => '', 'senha' => 'INT9Z9Z9Z']);
eq('e-mail vazio + código: 400, nada de achar conta pelo código', $r[0], 400);

echo "\n══ Quem entra hoje continua entrando igual ══\n";
limpar();
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1']);
eq('e-mail + senha entra e recebe sessão', [entrou($r), $r[1]['atleta']['idatleta'] ?? null, $r[1]['atleta']['nome'] ?? null], [true, 1, 'Ana']);
$r = req(['email' => '  ANA@T.COM ', 'senha' => 'SenhaForte1']);
eq('e-mail com espaço e maiúsculas continua valendo', entrou($r), true);
$r = req(['email' => 'ana@t.com', 'senha' => 'INT1A2B3C']);
eq('e-mail + código de acesso (no lugar da senha) entra', [entrou($r), $r[1]['atleta']['idatleta'] ?? null], [true, 1]);
$r = req(['email' => 'ana@t.com', 'senha' => 'int1a2b3c']);
eq('e-mail + código em minúsculas entra (como antes)', entrou($r), true);
$r = req(['email' => 'beto@t.com', 'senha' => '1234']);
eq('conta sem senha: e-mail + código antigo entra (primeiro acesso) e o código vira a primeira senha, como antes', [entrou($r), strlen($senhaBeto()) >= 50], [true, true]);
$r = req(['email' => 'ana@t.com', 'senha' => 'senha-errada']);
eq('senha errada: 401', [$r[0], entrou($r)], [401, false]);
$r = req(['email' => 'ana@t.com', 'senha' => 'INT00DANI']);
eq('o código de OUTRA conta no lugar da senha não vale', [$r[0], entrou($r)], [401, false]);
$r = req(['email' => 'dani@t.com', 'senha' => 'SenhaDani1']);
eq('conta bloqueada com senha certa: 403, sem sessão', [$r[0], entrou($r)], [403, false]);
eq('senha em branco: 400', req(['email' => 'ana@t.com', 'senha' => ''])[0], 400);
eq('nenhuma sessão extra foi criada pelos pedidos recusados', (int)$db->query('SELECT COUNT(*) FROM intus_sessions')->fetchColumn(), 5);

echo "\n══ Teto global de falhas por minuto (todas as IPs somadas) ══\n";
limpar();
falhas(19);
eq('19 falhas de 19 IPs diferentes (abaixo do teto de 20): ainda no modo normal', pressao(), false);
falhas(1, 'y');
eq('a 20ª falha em 1 minuto liga o modo de verificação', pressao(), true);
$st = intusLoginStatus($db);
eq('o status mostra as falhas, o teto e até quando dura (10 min)', [$st['falhas_ultimo_minuto'], $st['teto'], $st['pressao'], $st['turnstile'], strtotime($st['pressao_ate']) - time() > 590], [20, 20, true, false, true]);
eq('só uma marca por minuto, mesmo com mais falhas (a tabela não enche durante um ataque)', (function () use ($db) { falhas(5, 'z'); return (int)$db->query("SELECT COUNT(*) FROM rate_limits WHERE action = 'aluno_login_pressao'")->fetchColumn(); })(), 1);

echo "\n══ Modo de verificação SEM Turnstile: atraso progressivo (limites apertados), sem derrubar quem acerta ══\n";
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1']);
eq('quem acerta de primeira entra normalmente', entrou($r), true);
$ipA = '172.16.0.1';
eq('1ª falha do mesmo IP: 401', req(['email' => 'ana@t.com', 'senha' => 'errada'], $ipA)[0], 401);
eq('2ª falha do mesmo IP: 401', req(['email' => 'ana@t.com', 'senha' => 'errada'], $ipA)[0], 401);
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1'], $ipA);
eq('3ª tentativa do mesmo IP (mesmo certa) leva 429: limite por IP caiu de 5 para 2', [$r[0], $r[1]['error'] ?? null], [429, 'muitas_tentativas']);
$db->exec("DELETE FROM rate_limits WHERE action NOT IN ('aluno_login_pressao','aluno_login_falha_global')");
$ipsC = ['172.16.1.1', '172.16.1.2', '172.16.1.3'];
foreach ($ipsC as $ip) req(['email' => 'ana@t.com', 'senha' => 'errada'], $ip);
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1'], '172.16.1.4');
eq('3 falhas na MESMA conta (de IPs diferentes) travam a conta: limite por conta caiu de 8 para 3', [$r[0], $r[1]['error'] ?? null], [429, 'muitas_tentativas']);
$r = req(['email' => 'beto@t.com', 'senha' => '1234'], '172.16.1.5');
eq('outra conta, sem falhas, entra normalmente', entrou($r), true);
// o modo dura 10 min depois do último estouro, mesmo sem novas falhas
$db->exec("DELETE FROM rate_limits WHERE action = 'aluno_login_falha_global'");
eq('sem novas falhas o modo continua ligado (não desliga assim que o minuto passa)', pressao(), true);
$db->exec("UPDATE rate_limits SET attempted_at = '" . gmdate('Y-m-d H:i:s', time() - 660) . "' WHERE action = 'aluno_login_pressao'");
eq('11 minutos depois do último estouro o modo desliga sozinho', pressao(), false);
$db->exec("DELETE FROM rate_limits");
$ipB = '172.16.2.1';
for ($i = 0; $i < 4; $i++) req(['email' => 'ana@t.com', 'senha' => 'errada'], $ipB . '');
eq('de volta ao normal: o limite por IP volta a 5 (a 5ª tentativa ainda é processada)', req(['email' => 'ana@t.com', 'senha' => 'errada'], $ipB)[0], 401);

echo "\n══ Modo de verificação COM Turnstile: token conferido antes da conta ══\n";
limpar(); turnstile('chave-publica-teste', 'segredo-teste');
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1']);
eq('fora do modo de verificação o token nem é pedido (login normal)', entrou($r), true);
falhas(20);
eq('20 falhas ligam o modo', pressao(), true);
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1']);
eq('sem token, mesmo com a senha certa: 429 pedindo verificação, sem sessão', [$r[0], $r[1]['codigo'] ?? null, entrou($r)], [429, 'verificacao_necessaria', false]);
eq('a resposta traz o tipo e a chave PÚBLICA do widget, e nunca a secreta', [$r[1]['verificacao'] ?? null, strpos(json_encode($r[1]), 'segredo-teste') === false], [['tipo' => 'turnstile', 'site_key' => 'chave-publica-teste'], true]);
eq('a mensagem é em português e sem travessão', [is_string($r[1]['erro']) && strpos($r[1]['erro'], 'pessoa') !== false, strpos($r[1]['erro'], '—') === false], [true, true]);
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1', 'turnstile_token' => 'recusado']);
eq('token recusado pelo Cloudflare: de novo o desafio', [$r[0], $r[1]['codigo'] ?? null, entrou($r)], [429, 'verificacao_necessaria', false]);
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1', 'turnstile_token' => str_repeat('x', 3000)]);
eq('token lixo (grande demais) é recusado sem consultar o Cloudflare', [$r[0], $r[1]['codigo'] ?? null], [429, 'verificacao_necessaria']);
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1', 'turnstile_token' => 'ok-valido']);
eq('token válido + senha certa: ENTRA (quem acerta não é derrubado)', [entrou($r), $r[1]['atleta']['idatleta'] ?? null], [true, 1]);
$r = req(['email' => 'ana@t.com', 'senha' => 'INT1A2B3C', 'turnstile_token' => 'ok-valido']);
eq('token válido + e-mail + código entra', entrou($r), true);
$r = req(['email' => '1234', 'senha' => '1234', 'turnstile_token' => 'ok-valido']);
eq('token válido NÃO abre a brecha do código: código sozinho continua sem entrar', [$r[0], entrou($r)], [401, false]);
$r = req(['email' => 'ana@t.com', 'senha' => 'errada', 'turnstile_token' => 'ok-valido']);
eq('token válido + senha errada: 401 (a conta continua sendo conferida)', [$r[0], $r[1]['erro'] ?? null], [401, 'credenciais invalidas']);
$ipC = '172.17.0.1';
$cod = [];
for ($i = 0; $i < 7; $i++) $cod[] = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1', 'turnstile_token' => 'recusado'], $ipC);
eq('quem insiste com token recusado é cortado por IP (5 recusas) e para de gastar conferência no Cloudflare', [array_column($cod, 0), $cod[6][1]['error'] ?? null], [[429, 429, 429, 429, 429, 429, 429], 'muitas_tentativas']);
eq('o 6º já é a trava por IP (sem o campo de desafio)', isset($cod[5][1]['codigo']), false);
eq('o 5º ainda era desafio', $cod[4][1]['codigo'] ?? null, 'verificacao_necessaria');

echo "\n══ Turnstile fora do ar ou mal configurado: nunca derruba todo mundo ══\n";
$db->exec('DELETE FROM rate_limits'); falhas(20, 'w');
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1', 'turnstile_token' => 'cf-fora']);
eq('Cloudflare fora do ar: quem acerta entra (sem desafio impossível)', entrou($r), true);
$ipD = '172.18.0.1';
req(['email' => 'ana@t.com', 'senha' => 'errada', 'turnstile_token' => 'cf-fora'], $ipD); req(['email' => 'ana@t.com', 'senha' => 'errada', 'turnstile_token' => 'cf-fora'], $ipD);
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1', 'turnstile_token' => 'cf-fora'], $ipD);
eq('e os limites apertados valem no lugar do desafio (3ª tentativa do IP: 429 de limite)', [$r[0], $r[1]['error'] ?? null], [429, 'muitas_tentativas']);
limpar(); turnstile('chave-publica-teste', 'segredo-errado'); falhas(20, 'v');
$r = req(['email' => 'ana@t.com', 'senha' => 'SenhaForte1', 'turnstile_token' => 'ok-valido']);
eq('chave secreta recusada pelo Cloudflare (configuração errada): trata como indisponível e quem acerta entra', entrou($r), true);

echo "\n══ Configuração ══\n";
limpar();
eq('sem linha em intus_secrets: Turnstile desligado e teto padrão', [intusTurnstileAtivo(intusLoginCfg($db)), intusLoginCfg($db)['teto']], [false, 20]);
turnstile('s', 'k', 8);
eq('o teto é configurável', [intusTurnstileAtivo(intusLoginCfg($db)), intusLoginCfg($db)['teto']], [true, 8]);
falhas(8, 'c');
eq('com teto 8, a 8ª falha liga o modo', pressao(), true);
limpar(); turnstile('s', 'k', 2);
eq('teto absurdo (2) volta ao padrão em vez de travar o login por qualquer erro', intusLoginCfg($db)['teto'], 20);
limpar(); intusLoginCfgGravar($db, ['site_key' => 'so-site', 'secret_key' => '']);
eq('só a chave pública (sem a secreta) não liga o Turnstile', intusTurnstileAtivo(intusLoginCfg($db)), false);

echo "\n══ Estrutura (o arquivo do servidor) ══\n";
$php = str_replace("\r\n", "\n", file_get_contents($raiz . '/app/api/aluno_login.php'));
$lib = str_replace("\r\n", "\n", file_get_contents($raiz . '/app/api/_login_protecao.php'));
$bloco = substr($php, strpos($php, '// ===== Validar login (default) ====='));
eq('o login padrão não procura mais a conta pelo código de acesso', strpos($bloco, 'UPPER(`$col_cod`)') === false && strpos($bloco, 'col_cod)') === false, true);
eq('as duas falhas de credencial alimentam o teto global', substr_count($bloco, 'intusLoginFalhaRegistrar($pdo);'), 2);
eq('nenhuma chave do Turnstile no código (só lida do banco)', preg_match('/0x4[A-Za-z0-9_-]{15,}|1x0{10,}/', $lib . $php) === 0 && strpos($lib, "'turnstile_config'") !== false, true);
$chamaSleep = false; $toks = token_get_all($lib);
foreach ($toks as $i => $tk) { if (is_array($tk) && $tk[0] === T_STRING && in_array(strtolower($tk[1]), ['sleep', 'usleep', 'time_nanosleep'], true) && ($toks[$i + 1] ?? null) === '(') $chamaSleep = true; }
eq('a biblioteca não chama sleep (a hospedagem tem 4 processos PHP: um atacante travaria o site)', $chamaSleep, false);
$adm = @file_get_contents($raiz . '/admin/turnstile-keys.php');
eq('a tela do admin existe e nunca devolve a chave secreta salva', $adm !== false && strpos($adm, "h(\$cfg['secret_key'])") === false && strpos($adm, 'value="<?= h($cfg[\'secret_key\']') === false, true);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
