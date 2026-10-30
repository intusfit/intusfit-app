<?php
/**
 * Servidor de teste da Central de conteúdo: serve /conteudo/ e roda o conteudo.php de verdade, com SQLite no lugar do MySQL
 * e um login de mentira (token "teste-admin" ou "teste-equipe"). Só para testes; nada daqui vai para o servidor.
 *   php -S 127.0.0.1:8130 testes/_conteudo_servidor.php
 * Variável CONT_BANCO: caminho do arquivo SQLite (padrão: pasta temporária do sistema).
 */
$raiz = realpath(__DIR__ . '/..');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (strpos($uri, '/app/api/conteudo.php') === 0) {
    $api = $raiz . '/app/api/';
    $fonte = file_get_contents($api . 'conteudo.php');
    $ini = strpos($fonte, '$cfgDb = @include');
    $fim = strpos($fonte, "require_once __DIR__ . '/_auth_context.php';");
    if ($ini === false || $fim === false) { http_response_code(500); echo 'conteudo.php mudou: ajuste este servidor de teste'; exit; }
    $fonte = substr($fonte, 0, $ini) . '$pdo = $GLOBALS["TESTE_PDO"];' . "\n" . substr($fonte, $fim);
    $fonte = str_replace("require_once __DIR__ . '/_auth_context.php';", '', $fonte);
    $fonte = str_replace("__DIR__ . '/_", var_export($api, true) . " . '_", $fonte);
    $fonte = str_replace("__DIR__ . '/_cors.php'", var_export($api, true) . " . '_cors.php'", $fonte);
    $fonte = preg_replace('/^<\?php/', '', $fonte, 1);

    $arq = getenv('CONT_BANCO') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'conteudo-teste.sqlite';
    $GLOBALS['TESTE_PDO'] = new PDO('sqlite:' . $arq, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    function getAuthContext($pdo, $token) {
        if ($token === 'teste-admin') return ['idusuario' => 1, 'admin' => true, 'nmusuario' => 'Luiz Nunes'];
        if ($token === 'teste-equipe') return ['idusuario' => 5, 'nmusuario' => 'Editora Ana'];
        return ['idusuario' => 0];
    }
    function usuarioTemPermissao($pdo, $id, $k) { return $id === 5 && $k === 'cut_ver'; }
    // o analisador de copy mora na Central de atendimento
    require_once $api . '_atendimento.php';
    eval($fonte);
    exit;
}

if ($uri === '/conteudo/' || $uri === '/conteudo') { header('Content-Type: text/html; charset=utf-8'); readfile($raiz . '/conteudo/index.html'); exit; }
if ($uri === '/__zerar') { @unlink(getenv('CONT_BANCO') ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'conteudo-teste.sqlite'); echo 'ok'; exit; }
http_response_code(404);
echo 'não encontrado';
