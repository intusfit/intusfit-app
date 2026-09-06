<?php
function getInput() {
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return !empty($_POST) ? $_POST : [];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : (!empty($_POST) ? $_POST : []);
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function requireAuth() {
    $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    $token = '';
    
    if (preg_match('/Bearer\s+(\S+)/', $header, $m)) {
        $token = $m[1];
    }
    
    if (!$token) {
        jsonError('Token ausente', 401);
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM sessao WHERE token = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch();
    
    if (!$session) {
        jsonError('Token invalido ou expirado', 401);
    }
    
    return $session;
}
