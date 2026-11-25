<?php
/**
 * Entrega uma foto de avaliação guardada como arquivo em app/img/avaliacoes/.
 *
 * GET ?f=av_xxx.jpg&e=<validade (unix)>&s=<assinatura>
 *
 * A pasta app/img/avaliacoes/ responde 403 direto (app/.htaccess): foto de corpo de aluno não fica
 * aberta na internet para quem tiver o endereço. O link só nasce quando catalogo.php lista as
 * avaliações para quem tem sessão (aluno dono ou equipe) e vale 24 h (ver fotoLinkAssinar em _gdrive.php).
 * Sem login de propósito: quem usa é um <img src>, que não manda Bearer.
 */
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_gdrive.php';

function _fotoAvFalha($codigo, $msg) {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

$f = (string)($_GET['f'] ?? '');
$e = (string)($_GET['e'] ?? '');
$s = (string)($_GET['s'] ?? '');
if (!preg_match('/^av_[A-Za-z0-9_]+\.(jpg|png|gif|webp)$/', $f) || !preg_match('/^\d{9,11}$/', $e) || !preg_match('/^[0-9a-f]{32}$/', $s)) _fotoAvFalha(404, 'nao encontrado');
if (!fotoLinkConfere('a', $f, $e, $s)) _fotoAvFalha(404, 'nao encontrado');

$caminho = __DIR__ . '/../img/avaliacoes/' . $f;
if (!is_file($caminho)) _fotoAvFalha(404, 'nao encontrado');
$info = @getimagesize($caminho);
$mime = is_array($info) ? ($info['mime'] ?? '') : '';
if (!preg_match('#^image/(png|jpeg|gif|webp)$#', $mime)) _fotoAvFalha(415, 'arquivo nao e imagem');

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($caminho));
header('Cache-Control: private, max-age=3600');
readfile($caminho);
