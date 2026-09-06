<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();

if (empty($_SESSION['auth']) || (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['image']) || empty($input['filename'])) {
    echo json_encode(['ok' => false, 'error' => 'Dados incompletos']);
    exit;
}

// Sanitize filename — allow only safe chars, force .jpg
$filename = preg_replace('/[^a-zA-Z0-9_\-]/', '', pathinfo($input['filename'], PATHINFO_FILENAME));
if (!$filename) $filename = 'crop-' . time();
$filename = $filename . '.jpg';

// Decode base64 data URI
$dataUri = $input['image'];
if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,/', $dataUri)) {
    echo json_encode(['ok' => false, 'error' => 'Formato inválido']);
    exit;
}
$base64 = preg_replace('/^data:image\/[a-z]+;base64,/', '', $dataUri);
$imageData = base64_decode($base64);
if ($imageData === false || strlen($imageData) < 100) {
    echo json_encode(['ok' => false, 'error' => 'Imagem inválida']);
    exit;
}

// Size guard: max 8 MB
if (strlen($imageData) > 8 * 1024 * 1024) {
    echo json_encode(['ok' => false, 'error' => 'Imagem muito grande (máx 8 MB)']);
    exit;
}

$destPath = IMG_DIR . $filename;
if (file_put_contents($destPath, $imageData) === false) {
    echo json_encode(['ok' => false, 'error' => 'Erro ao salvar arquivo']);
    exit;
}

echo json_encode(['ok' => true, 'filename' => $filename]);
