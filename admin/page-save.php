<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();
if (empty($_SESSION['auth'])) { echo json_encode(['ok'=>false,'error'=>'Não autorizado']); exit; }
header('Content-Type: application/json');

define('PAGES_DIR', __DIR__ . '/pages/');
define('WEB_ROOT',  dirname(__DIR__) . '/');

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) { echo json_encode(['ok'=>false,'error'=>'JSON inválido']); exit; }

$slug = $body['slug'] ?? '__main__';

function sanitizeSlug($s) {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\-]/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

$c = $body['content'] ?? null;
if (!$c) { echo json_encode(['ok'=>false,'error'=>'Conteúdo ausente']); exit; }

// Determine output paths
if ($slug === '__main__') {
    $contentFile = CONTENT_FILE;
    $indexFile   = INDEX_FILE;
    $baseUrl     = 'https://intusfit.com.br';
    $imgBase     = '/img/';
} else {
    $slug        = sanitizeSlug($slug);
    $contentFile = PAGES_DIR . $slug . '.json';
    $pageDir     = WEB_ROOT . $slug . '/';
    if (!is_dir($pageDir)) mkdir($pageDir, 0755, true);
    $indexFile   = $pageDir . 'index.html';
    $baseUrl     = 'https://intusfit.com.br/' . $slug;
    $imgBase     = '/img/';
}

// Save content JSON
file_put_contents($contentFile, json_encode($c, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));

// ── Reuse save.php render engine ──────────────────────────────────────────────
// Override constants for this page
define('CONTENT_FILE_OVERRIDE', $contentFile);
define('INDEX_FILE_OVERRIDE',   $indexFile);

function s($v) { return is_string($v) ? htmlspecialchars_decode(strip_tags(trim($v))) : ''; }
function sa($arr) { return is_array($arr) ? array_map('s', $arr) : []; }

$wa      = s($c['contato']['whatsapp'] ?? '5545991156006');
$waSauda = s($c['contato']['whatsapp_saudacao_site'] ?? 'Olá! Vim pelo site e quero saber mais sobre a Consultoria Intus Fit.');
$blocos  = is_array($c['blocos_ocultos'] ?? null) ? $c['blocos_ocultos'] : [];

function blocoOculto($slug) { global $blocos; return in_array($slug, $blocos); }

// Include the render template (same as save.php but isolated)
ob_start();
include __DIR__ . '/render-template.php';
$html = ob_get_clean();

file_put_contents($indexFile, $html);

echo json_encode(['ok'=>true,'slug'=>$slug,'url'=>($slug==='__main__'?'/':'/'.$slug.'/')]);
