<?php
require_once 'config.php';
require_once __DIR__ . '/_black_cfg.php';
session_name(SESSION_KEY);
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['auth']) && !blackPeloPainel($_SESSION, time())) { echo '[]'; exit; }
$imgs = array_values(array_filter(scandir(IMG_DIR), fn($f) => preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $f)));
sort($imgs);
echo json_encode($imgs);
