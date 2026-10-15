<?php
/**
 * Gatilho das rotinas automáticas (ver _rotinas.php). Incluído por catalogo.php, depois de a resposta do sino sair. Faz algo no máximo uma vez a cada 6 horas:
 * nas outras vezes só confere a data de um arquivinho e sai.
 */
(function () {
    if (PHP_SAPI === 'cli') return;
    if (!function_exists('fastcgi_finish_request')) return;   // sem resposta antecipada o painel ficaria esperando
    $dir = __DIR__ . '/../config';
    $marca = $dir . '/rotinas-ultima.txt';
    if (is_file($marca) && (time() - (int)@filemtime($marca)) < 6 * 3600) return;
    @file_put_contents($marca, date('c'));      // marca ANTES: duas telas abrindo juntas não rodam duas vezes
    fastcgi_finish_request();
    @ignore_user_abort(true);
    @set_time_limit(180);
    try {
        require_once __DIR__ . '/_gdrive.php';
        require_once __DIR__ . '/_rotinas.php';
        $pdo = _gdriveConn();
        if ($pdo) intusRotinasRodar($pdo);
    } catch (Throwable $e) {
        @file_put_contents($dir . '/backup.log', '[' . date('Y-m-d H:i:s') . '] ERRO nas rotinas automaticas: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }
})();
