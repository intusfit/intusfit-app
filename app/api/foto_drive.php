<?php
/**
 * Entrega uma foto de avaliação que está guardada só no Google Drive.
 *
 * GET ?f=<id do arquivo no Drive>&s=<assinatura>
 *
 * Sem login de propósito: quem usa é um <img src> e o "importar pro Relatório de
 * Fotos", que não mandam Bearer. A proteção é a assinatura (HMAC com um segredo que
 * só o servidor tem, ver gdriveFotoAssinar em _gdrive.php) — o link só nasce quando
 * catalogo.php lista as avaliações pra quem tem sessão. Ver o motivo todo em
 * _gdrive.php.
 */
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_gdrive.php';

function _fotoDriveFalha($codigo, $msg) {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

$id = (string)($_GET['f'] ?? '');
$sig = (string)($_GET['s'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{10,}$/', $id) || !preg_match('/^[0-9a-f]{32}$/', $sig)) _fotoDriveFalha(404, 'nao encontrado');
$esperada = gdriveFotoAssinar($id);
if (!$esperada || !hash_equals($esperada, $sig)) _fotoDriveFalha(404, 'nao encontrado');

try {
    $corpo = gdriveBaixarArquivo($id);
} catch (Throwable $e) {
    @error_log('[intus foto_drive] ' . $e->getMessage());
    _fotoDriveFalha(502, 'foto indisponivel');
}

// Só entrega o que realmente é imagem (nada de HTML servido a partir do nosso domínio).
$info = @getimagesizefromstring($corpo);
$mime = is_array($info) ? ($info['mime'] ?? '') : '';
if (!preg_match('#^image/(png|jpeg|gif|webp)$#', $mime)) _fotoDriveFalha(415, 'arquivo nao e imagem');

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($corpo));
header('Cache-Control: private, max-age=86400');
echo $corpo;
