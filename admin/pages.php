<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();
if (empty($_SESSION['auth']) || (time()-$_SESSION['login_time'])>SESSION_TIMEOUT) {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Não autorizado']); exit;
}
header('Content-Type: application/json');

define('PAGES_DIR', __DIR__ . '/pages/');
define('TPL_DIR2',  __DIR__ . '/templates/');
define('WEB_ROOT',  dirname(__DIR__) . '/');

if (!is_dir(PAGES_DIR)) mkdir(PAGES_DIR, 0755, true);

$action = $_GET['action'] ?? '';

function sanitizeSlug($s) {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\-]/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-');
}

// ── LIST pages ────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $pages = [];
    foreach (glob(PAGES_DIR . '*.json') as $f) {
        $meta = json_decode(file_get_contents($f), true);
        $slug = pathinfo($f, PATHINFO_FILENAME);
        $pages[] = [
            'slug'      => $slug,
            'title'     => $meta['seo']['title'] ?? $slug,
            'url'       => '/' . $slug . '/',
            'published' => file_exists(WEB_ROOT . $slug . '/index.html'),
            'updated'   => date('Y-m-d H:i', filemtime($f)),
        ];
    }
    // Also include the main page
    $main = json_decode(file_get_contents(CONTENT_FILE), true);
    array_unshift($pages, [
        'slug'      => '__main__',
        'title'     => $main['seo']['title'] ?? 'Página Principal',
        'url'       => '/',
        'published' => file_exists(INDEX_FILE),
        'updated'   => date('Y-m-d H:i', filemtime(CONTENT_FILE)),
        'is_main'   => true,
    ]);
    echo json_encode(['ok'=>true,'pages'=>$pages]);
    exit;
}

// ── CREATE page from template ─────────────────────────────────────────────────
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $slug     = sanitizeSlug($body['slug'] ?? '');
    $tplId    = preg_replace('/[^a-z0-9_-]/', '', $body['template'] ?? '');
    $title    = trim($body['title'] ?? $slug);

    if (!$slug) { echo json_encode(['ok'=>false,'error'=>'Slug inválido']); exit; }
    if ($slug === '__main__') { echo json_encode(['ok'=>false,'error'=>'Nome reservado']); exit; }

    // Load from template or clone main content
    if ($tplId && file_exists(TPL_DIR2 . $tplId . '.json')) {
        $content = json_decode(file_get_contents(TPL_DIR2 . $tplId . '.json'), true);
    } else {
        $content = json_decode(file_get_contents(CONTENT_FILE), true);
    }

    // Update title if provided
    if ($title) {
        $content['seo']['title']    = $title;
        $content['seo']['og_title'] = $title;
    }
    // Remove template meta keys
    unset($content['_tpl_name'], $content['_tpl_created'], $content['_tpl_id']);

    file_put_contents(PAGES_DIR . $slug . '.json', json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok'=>true,'slug'=>$slug]);
    exit;
}

// ── LOAD page content ─────────────────────────────────────────────────────────
if ($action === 'load') {
    $slug = $_GET['slug'] ?? '';
    if ($slug === '__main__') {
        echo json_encode(['ok'=>true,'content'=>json_decode(file_get_contents(CONTENT_FILE), true)]);
        exit;
    }
    $slug = sanitizeSlug($slug);
    $f    = PAGES_DIR . $slug . '.json';
    if (!file_exists($f)) { echo json_encode(['ok'=>false,'error'=>'Página não encontrada']); exit; }
    echo json_encode(['ok'=>true,'content'=>json_decode(file_get_contents($f), true)]);
    exit;
}

// ── DELETE page ───────────────────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $slug = sanitizeSlug($body['slug'] ?? '');
    if (!$slug || $slug === '__main__') { echo json_encode(['ok'=>false,'error'=>'Não pode excluir']); exit; }
    $f = PAGES_DIR . $slug . '.json';
    if (file_exists($f)) unlink($f);
    // Remove published folder if exists
    $dir = WEB_ROOT . $slug;
    if (is_dir($dir)) {
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Ação inválida']);
