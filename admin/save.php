<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();
if (empty($_SESSION['auth'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Não autorizado']);
    exit;
}
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$c   = json_decode($raw, true);
if (!$c) { echo json_encode(['ok' => false, 'error' => 'JSON inválido']); exit; }

function s($v) { return is_string($v) ? htmlspecialchars_decode(strip_tags(trim($v))) : ''; }

$wa      = s($c['contato']['whatsapp']               ?? '5545991156006');
$waSauda = s($c['contato']['whatsapp_saudacao_site'] ?? 'Olá! Vim pelo site e quero saber mais sobre a Consultoria Intus Fit.');
$baseUrl = 'https://intusfit.com.br';
$imgBase = '/img/';
$blocos  = is_array($c['blocos_ocultos'] ?? null) ? $c['blocos_ocultos'] : [];

function blocoOculto($slug) { global $blocos; return in_array($slug, $blocos); }

// Salva content.json
file_put_contents(CONTENT_FILE, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Gera index.html a partir do template
ob_start();
include __DIR__ . '/render-template.php';
$html = ob_get_clean();

file_put_contents(INDEX_FILE, $html);

echo json_encode(['ok' => true]);
