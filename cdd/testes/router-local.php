<?php
/**
 * Servidor local de desenvolvimento do CDD (nunca vai para produção: a pasta testes/ é bloqueada no .htaccess).
 * Usa um SQLite temporário no lugar do MySQL, então não toca em nenhum banco real.
 *   php -d extension=pdo_sqlite -d extension=sqlite3 -d extension=mbstring -S localhost:8765 -t site site/cdd/testes/router-local.php
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/cdd/api/api.php') {
    $arq = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cdd-local.sqlite';
    $GLOBALS['CDD_PDO'] = new PDO('sqlite:' . $arq, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    require __DIR__ . '/../api/api.php';
    return true;
}
return false;
