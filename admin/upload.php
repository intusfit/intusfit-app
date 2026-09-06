<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['auth'])) { echo json_encode(['ok'=>false,'error'=>'Não autorizado']); exit; }

$allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
$maxSize = 8 * 1024 * 1024; // 8MB

if (empty($_FILES['img'])) { echo json_encode(['ok'=>false,'error'=>'Nenhum arquivo']); exit; }

$f = $_FILES['img'];
if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'error'=>'Erro no upload']); exit; }
if ($f['size'] > $maxSize) { echo json_encode(['ok'=>false,'error'=>'Arquivo muito grande (max 8MB)']); exit; }
if (!in_array($f['type'], $allowed)) { echo json_encode(['ok'=>false,'error'=>'Tipo de arquivo não permitido']); exit; }

// ── EXECUCAO REMOTA DE CODIGO ───────────────────────────────────────────────
// Dois defeitos que se somavam. Primeiro, a validacao de tipo olhava
// $f['type'], que e o Content-Type que o CLIENTE escreve na requisicao — nao o
// conteudo do arquivo. Segundo, a extensao gravada vinha crua do nome enviado:
// a limpeza com regex tocava so no nome, nunca na extensao.
// Resultado: enviar "shell.php" dizendo que era "image/jpeg" gravava um arquivo
// .php dentro da pasta publica de imagens. Abrir esse endereco executava codigo
// no servidor. Agora o tipo e detectado do CONTEUDO e a extensao vem de uma
// lista fixa, decidida pelo servidor.
$_permitidas = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$_info = @getimagesize($f['tmp_name']);
$_mimeReal = $_info['mime'] ?? '';
if (!$_mimeReal && function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $_mimeReal = $fi ? (finfo_file($fi, $f['tmp_name']) ?: '') : '';
    if ($fi) finfo_close($fi);
}
if (!isset($_permitidas[$_mimeReal])) {
    echo json_encode(['ok'=>false,'error'=>'O arquivo não é uma imagem válida']);
    exit;
}
$ext = $_permitidas[$_mimeReal];
$name = preg_replace('/[^a-z0-9_-]/', '-', strtolower(pathinfo($f['name'], PATHINFO_FILENAME)));
$name = trim($name, '-');
if ($name === '') $name = 'img';
$name = substr($name, 0, 60);
$filename = $name . '.' . $ext;

// Evitar sobrescrever
$dest = IMG_DIR . $filename;
if (file_exists($dest)) {
    $filename = $name . '-' . time() . '.' . $ext;
    $dest = IMG_DIR . $filename;
}

if (!move_uploaded_file($f['tmp_name'], $dest)) {
    echo json_encode(['ok'=>false,'error'=>'Falha ao salvar arquivo']); exit;
}

echo json_encode(['ok'=>true, 'filename'=>$filename, 'url'=>IMG_URL . $filename]);
