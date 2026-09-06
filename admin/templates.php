<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();
if (empty($_SESSION['auth']) || (time()-$_SESSION['login_time'])>SESSION_TIMEOUT) {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Não autorizado']); exit;
}
header('Content-Type: application/json');

define('TPL_DIR', __DIR__ . '/templates/');
if (!is_dir(TPL_DIR)) mkdir(TPL_DIR, 0755, true);

$action = $_GET['action'] ?? '';

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $tpls = [];
    foreach (glob(TPL_DIR . '*.json') as $f) {
        $meta = json_decode(file_get_contents($f), true);
        $tpls[] = [
            'id'      => pathinfo($f, PATHINFO_FILENAME),
            'name'    => $meta['_tpl_name'] ?? 'Sem nome',
            'created' => $meta['_tpl_created'] ?? '',
            'updated' => date('Y-m-d H:i', filemtime($f)),
        ];
    }
    usort($tpls, fn($a,$b) => strcmp($b['updated'], $a['updated']));
    echo json_encode(['ok'=>true,'templates'=>$tpls]);
    exit;
}

// ── SAVE (create/update template from current content or posted data) ─────────
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['name'] ?? 'Modelo sem nome');
    $id   = preg_replace('/[^a-z0-9_-]/', '-', strtolower($body['id'] ?? ('tpl-'.time())));
    // Content: from body or from current content.json
    $content = $body['content'] ?? json_decode(file_get_contents(CONTENT_FILE), true);
    $content['_tpl_name']    = $name;
    $content['_tpl_created'] = $content['_tpl_created'] ?? date('Y-m-d H:i');
    $content['_tpl_id']      = $id;
    file_put_contents(TPL_DIR . $id . '.json', json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['ok'=>true,'id'=>$id,'name'=>$name]);
    exit;
}

// ── LOAD (get template content) ───────────────────────────────────────────────
if ($action === 'load') {
    $id = preg_replace('/[^a-z0-9_-]/', '', $_GET['id'] ?? '');
    $f  = TPL_DIR . $id . '.json';
    if (!$id || !file_exists($f)) { echo json_encode(['ok'=>false,'error'=>'Não encontrado']); exit; }
    echo json_encode(['ok'=>true,'content'=>json_decode(file_get_contents($f), true)]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = preg_replace('/[^a-z0-9_-]/', '', $body['id'] ?? '');
    $f    = TPL_DIR . $id . '.json';
    if ($id && file_exists($f)) unlink($f);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── RENAME ────────────────────────────────────────────────────────────────────
if ($action === 'rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = preg_replace('/[^a-z0-9_-]/', '', $body['id'] ?? '');
    $name = trim($body['name'] ?? '');
    $f    = TPL_DIR . $id . '.json';
    if ($id && $name && file_exists($f)) {
        $data = json_decode(file_get_contents($f), true);
        $data['_tpl_name'] = $name;
        file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'Não encontrado']);
    }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Ação inválida']);
