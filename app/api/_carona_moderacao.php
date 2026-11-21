<?php
/**
 * Gatilho da varredura de moderação (ver _moderacao.php). Incluído por catalogo.php depois que a resposta do sino já saiu.
 * Faz algo no máximo uma vez a cada 5 minutos; nas outras vezes só confere a data de um arquivinho e sai.
 */
(function () {
    if (PHP_SAPI === 'cli') return;
    if (!function_exists('fastcgi_finish_request')) return;   // sem resposta antecipada o app ficaria esperando
    require_once __DIR__ . '/_moderacao.php';
    $marca = __DIR__ . '/../config/moderacao-ultima.txt';
    if (is_file($marca) && (time() - (int)@filemtime($marca)) < INTUS_MOD_VARREDURA_SEG) return;
    @file_put_contents($marca, date('c'));      // marca ANTES: dois sinos juntos não varrem duas vezes
    fastcgi_finish_request();
    @ignore_user_abort(true);
    @set_time_limit(60);
    try {
        require_once __DIR__ . '/_gdrive.php';
        $pdo = _gdriveConn();
        if ($pdo) intusModVarrer($pdo);
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/../config/email_erros.log', date('c') . ' | varredura de moderacao falhou: ' . $e->getMessage() . "\n", FILE_APPEND);
    }
})();
