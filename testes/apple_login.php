<?php
// Entrar com Apple: verificação do token (assinatura, emissor, destinatário, validade), ligação ao aluno, lead sem duplicar e alerta de possível cadastro duplicado.
//   php apple_login.php   (precisa de openssl, pdo_sqlite e mbstring)
require __DIR__ . '/../app/api/_apple_login.php';
$ok = 0; $ruim = 0;
function eq($rot, $obt, $esp) { global $ok, $ruim; if ($obt === $esp) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esp, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obt, JSON_UNESCAPED_UNICODE) . "\n"; } }
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

// Windows sem openssl.cnf configurado: usa o que acompanha o PHP (extras/ssl), se houver
// Windows sem openssl.cnf utilizável: usa um arquivo de configuração vazio só para gerar a chave de teste
$cnf = sys_get_temp_dir() . '/intus-openssl-vazio.cnf'; if (!is_file($cnf)) file_put_contents($cnf, "openssl_conf = default_conf
[default_conf]
");
$optChave = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf];
$chave = openssl_pkey_new($optChave);
$d = openssl_pkey_get_details($chave)['rsa'];
$outra = openssl_pkey_new($optChave);
$GLOBALS['INTUS_APPLE_JWKS'] = [['kty' => 'RSA', 'kid' => 'K1', 'alg' => 'RS256', 'use' => 'sig', 'n' => b64u($d['n']), 'e' => b64u($d['e'])]];
function jwt($claims, $priv, $kid = 'K1', $alg = 'RS256') {
    $h = b64u(json_encode(['alg' => $alg, 'kid' => $kid])); $p = b64u(json_encode($claims));
    openssl_sign("$h.$p", $sig, $priv, OPENSSL_ALGO_SHA256);
    return "$h.$p." . b64u($sig);
}
$base = ['iss' => 'https://appleid.apple.com', 'aud' => 'br.com.intusfit.app', 'exp' => time() + 600, 'sub' => '001.abc', 'email' => 'ana@exemplo.com', 'email_verified' => 'true'];

echo "\n══ Verificação do token ══\n";
$r = intusAppleVerificar(jwt($base, $chave));
eq('token válido: devolve sub, e-mail verificado e não oculto', [$r['sub'], $r['email'], $r['email_verificado'], $r['email_oculto']], ['001.abc', 'ana@exemplo.com', true, false]);
eq('assinatura de outra chave: recusado', intusAppleVerificar(jwt($base, $outra)), null);
eq('kid desconhecido: recusado', intusAppleVerificar(jwt($base, $chave, 'K9')), null);
eq('emissor errado: recusado', intusAppleVerificar(jwt(['iss' => 'https://evil.example'] + $base, $chave)), null);
eq('destinatário (aud) de outro app: recusado', intusAppleVerificar(jwt(['aud' => 'com.outro.app'] + $base, $chave)), null);
eq('vencido: recusado', intusAppleVerificar(jwt(['exp' => time() - 5] + $base, $chave)), null);
eq('sem sub: recusado', intusAppleVerificar(jwt(['sub' => ''] + $base, $chave)), null);
eq('algoritmo "none": recusado', intusAppleVerificar(b64u('{"alg":"none","kid":"K1"}') . '.' . b64u(json_encode($base)) . '.'), null);
$t = jwt($base, $chave); $pp = explode('.', $t); $pp[1] = b64u(json_encode(['email' => 'adm@intusfit.com.br'] + $base));
eq('conteúdo adulterado depois de assinado: recusado', intusAppleVerificar(implode('.', $pp)), null);
eq('lixo: recusado', intusAppleVerificar('abc'), null);
$r = intusAppleVerificar(jwt(['email' => 'x1y2@privaterelay.appleid.com', 'is_private_email' => 'true'] + $base, $chave));
eq('e-mail oculto da Apple é reconhecido', $r['email_oculto'], true);
$r = intusAppleVerificar(jwt(['email_verified' => 'false'] + $base, $chave));
eq('e-mail não verificado é marcado', $r['email_verificado'], false);

echo "\n══ Banco: entrar, ligar e alertar ══\n";
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, stbloqueio TEXT DEFAULT 'N')");
$pdo->exec("INSERT INTO atleta (nome, email) VALUES ('Ana Souza', 'ana@exemplo.com'), ('Carlos Lima', 'carlos@exemplo.com'), ('Bia Rocha', 'bia@exemplo.com')");
$m = ['col_id' => 'idatleta', 'col_nome' => 'nome', 'col_email' => 'email', 'col_block' => 'stbloqueio'];
$emails = []; $GLOBALS['INTUS_APPLE_EMAIL_STUB'] = function ($a, $c) use (&$emails) { $emails[] = [$a, $c]; return true; };
$cl = fn($o = []) => intusAppleVerificar(jwt($o + $base, $chave));

$r = intusAppleEntrar($pdo, 'atleta', $m, $cl());
eq('e-mail verificado de um aluno: entra na conta dele', [$r['status'], (int)$r['row']['idatleta']], ['entrou', 1]);
eq('e a ligação Apple fica gravada', (int)$pdo->query("SELECT idatleta FROM intus_apple_vinculo WHERE sub = '001.abc'")->fetchColumn(), 1);
$pdo->exec("UPDATE atleta SET email = 'ana.nova@exemplo.com' WHERE idatleta = 1");
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['email' => 'qualquer@outro.com']));
eq('depois de ligada, entra pelo sub mesmo com outro e-mail', [$r['status'], (int)$r['row']['idatleta']], ['entrou', 1]);
$pdo->exec("UPDATE atleta SET stbloqueio = 'S' WHERE idatleta = 1");
eq('aluno bloqueado: não entra', intusAppleEntrar($pdo, 'atleta', $m, $cl())['status'], 'bloqueado');
$pdo->exec("UPDATE atleta SET stbloqueio = 'N' WHERE idatleta = 1");
eq('e-mail NÃO verificado não casa com aluno (não vira conta de outra pessoa)', intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S2', 'email' => 'carlos@exemplo.com', 'email_verified' => 'false']))['status'], 'pendente');
eq('nenhuma conta de aluno foi criada', (int)$pdo->query('SELECT COUNT(*) FROM atleta')->fetchColumn(), 3);

echo "\n══ Alertas ══\n";
$emails = []; $pdo->exec('DELETE FROM intus_login_alerta');
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S3', 'email' => 'zzz@privaterelay.appleid.com', 'is_private_email' => 'true']), '');
eq('e-mail oculto sem nome: vira lead e alerta', [$r['status'], $r['idlead'] > 0, $r['idalerta'] > 0], ['pendente', true, true]);
eq('o administrador recebe o e-mail na hora', [count($emails), str_contains($emails[0][1], 'e-mail oculto')], [1, true]);
$nLeads = (int)$pdo->query('SELECT COUNT(*) FROM intus_lead')->fetchColumn();
$r2 = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S3', 'email' => 'zzz@privaterelay.appleid.com', 'is_private_email' => 'true']), '');
eq('tentar de novo não cria outro lead nem outro alerta nem outro e-mail', [$r2['idlead'] === $r['idlead'], $r2['idalerta'] === $r['idalerta'], count($emails), (int)$pdo->query('SELECT COUNT(*) FROM intus_lead')->fetchColumn() === $nLeads], [true, true, 1, true]);

$emails = [];
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S4', 'email' => 'novo.carlos@gmail.com']), 'Carlos Lima');
$a = array_values(array_filter(intusAppleAlertas($pdo)['pendentes'], fn($x) => $x['nome'] === 'Carlos Lima'))[0];
eq('mesmo nome de um aluno: alerta com o aluno como candidato', [$a['motivos'], $a['candidatos'][0]['tipo'], $a['candidatos'][0]['id']], [['nome_igual'], 'aluno', 2]);
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S5', 'email' => 'outra@gmail.com']), 'Maria Desconhecida da Silva');
eq('pessoa realmente nova (nome e e-mail sem par): só lead, SEM alerta', [$r['status'], $r['idalerta']], ['pendente', 0]);
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S6', 'email' => 'so@gmail.com']), 'Carlos');
eq('só o primeiro nome não basta para acusar duplicado', $r['idalerta'], 0);
$pdo->exec("INSERT INTO intus_lead (nome, email, origem) VALUES ('Fulano Teste', 'fulano@gmail.com', 'landing-teste-gratis')");
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S7', 'email' => 'fulano@gmail.com']), 'Fulano Teste');
$a = array_values(array_filter(intusAppleAlertas($pdo)['pendentes'], fn($x) => $x['email'] === 'fulano@gmail.com'))[0];
eq('mesmo e-mail e nome de um lead existente: alerta de lead', [in_array('email_igual', $a['motivos']), $a['candidatos'][0]['tipo']], [true, 'lead']);

echo "\n══ Ligar pela própria conta, resolver ══\n";
$antes = count(intusAppleAlertas($pdo)['pendentes']);
intusAppleVincular($pdo, 'S3', 2, 'carlos@exemplo.com');
eq('ao ligar a Apple a um aluno, o alerta dela se resolve sozinho', count(intusAppleAlertas($pdo)['pendentes']), $antes - 1);
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S3', 'email' => 'zzz@privaterelay.appleid.com', 'is_private_email' => 'true']));
eq('e a próxima entrada com a mesma Apple já cai na conta do aluno', [$r['status'], (int)$r['row']['idatleta']], ['entrou', 2]);
$id = intusAppleAlertas($pdo)['pendentes'][0]['idalerta'];
eq('marcar como resolvido no painel', [intusAppleResolverAlerta($pdo, $id)['ok'], intusAppleResolverAlerta($pdo, $id)['ok']], [true, false]);
$pdo->exec('DELETE FROM atleta WHERE idatleta = 2');
$r = intusAppleEntrar($pdo, 'atleta', $m, $cl(['sub' => 'S3', 'email' => 'zzz@privaterelay.appleid.com', 'is_private_email' => 'true']));
eq('aluno apagado: a ligação some e a pessoa volta a ser só lead', $r['status'], 'pendente');

echo "\n" . ($ruim ? "$ruim FALHA(S), " : '') . "$ok ok\n";
exit($ruim ? 1 : 0);
