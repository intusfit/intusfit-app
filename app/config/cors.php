<?php
// CORS do login antigo (auth.php): antes devolvia Access-Control-Allow-Origin: * . Agora usa a mesma lista restrita de origens dos outros endpoints (_cors.php).
require_once __DIR__ . '/../api/_cors.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
