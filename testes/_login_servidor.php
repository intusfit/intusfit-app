<?php
/**
 * Servidor de teste do login do aluno: roda o app/api/aluno_login.php DE VERDADE (lê o arquivo a cada pedido, sem cópia), com SQLite no
 * lugar do MySQL. Só para testes; nada daqui vai para o servidor.
 *   LOGIN_BANCO=/tmp/login.sqlite php -S 127.0.0.1:8131 testes/_login_servidor.php
 * Cabeçalho de teste X-Teste-Ip: finge o IP de quem chama (para simular um ataque espalhado por muitos IPs).
 * O Cloudflare Turnstile é de mentira: token "ok-valido" passa, "cf-fora" simula o Cloudflare fora do ar, qualquer outro é recusado.
 */
date_default_timezone_set('UTC');
$raiz = realpath(__DIR__ . '/..');
$api = $raiz . '/app/api';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri !== '/app/api/aluno_login.php') { http_response_code(404); echo 'nao encontrado'; exit; }

class TesteMysqlPDO extends PDO
{
    // o SQL do servidor é de MySQL: só o que o SQLite não entende é traduzido aqui
    private function t(string $s): string
    {
        return preg_replace('/DATE_SUB\(NOW\(\),\s*INTERVAL\s+\?\s+SECOND\)/i', "datetime('now', '-' || ? || ' seconds')", $s);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->t($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->t($query)) : parent::query($this->t($query), $fetchMode, ...$args);
    }
    public function exec(string $statement): int|false { return parent::exec($this->t($statement)); }
}

$fonte = file_get_contents($api . '/aluno_login.php');
$ini = strpos($fonte, '$cfg = @include');
$fim = strpos($fonte, '// ---------- Detectar tabela e colunas ----------');
if ($ini === false || $fim === false) { http_response_code(500); echo 'aluno_login.php mudou: ajuste este servidor de teste'; exit; }
$fonte = substr($fonte, 0, $ini) . '$pdo = $GLOBALS["TESTE_PDO"];' . "\n" . substr($fonte, $fim);
$a = '$st = $pdo->query("SHOW COLUMNS FROM `$tabela`");' . "\n" . '    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[] = $r[\'Field\'];';
if (strpos($fonte, $a) === false) { http_response_code(500); echo 'listCols mudou: ajuste este servidor de teste'; exit; }
$fonte = str_replace($a, '$st = $pdo->query("PRAGMA table_info(`$tabela`)");' . "\n" . '    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[] = $r[\'name\'];', $fonte);
$fonte = str_replace('__DIR__', var_export($api, true), $fonte);
$fonte = preg_replace('/^<\?php/', '', $fonte, 1);

if (!empty($_SERVER['HTTP_X_TESTE_IP'])) $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_TESTE_IP'];
$GLOBALS['TESTE_PDO'] = new TesteMysqlPDO('sqlite:' . getenv('LOGIN_BANCO'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['TESTE_PDO']->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
$GLOBALS['intusTurnstileHttpFake'] = function (string $secret, string $token, string $ip): array {
    if ($token === 'cf-fora') throw new RuntimeException('rede');
    if ($secret === 'segredo-errado') return [200, ['success' => false, 'error-codes' => ['invalid-input-secret']]];
    return $token === 'ok-valido' ? [200, ['success' => true, 'error-codes' => []]] : [200, ['success' => false, 'error-codes' => ['invalid-input-response']]];
};
eval($fonte);
