<?php
/**
 * Webhook do WhatsApp (API oficial da Meta).
 *
 * URL para cadastrar na Meta: https://intusfit.com.br/app/api/whatsapp_webhook.php
 *
 *   GET  hub.mode=subscribe&hub.verify_token=...&hub.challenge=...
 *        A Meta chama uma vez ao cadastrar o webhook. Respondemos o desafio se o
 *        token conferir com o gravado em admin/whatsapp-keys.php.
 *   POST corpo JSON assinado (X-Hub-Signature-256, HMAC com o segredo do app).
 *        Mensagens recebidas e status de entrega. Sem assinatura valida, 403.
 *
 * Sem chave configurada o endpoint responde 503 e nao grava nada.
 * As regras ficam em _wa.php; aqui so entra e sai HTTP.
 */

require_once __DIR__ . '/_wa.php';

$pdo = wa_conectar();
if (!$pdo) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'falha conexao';
    exit;
}

try {
    wa_garantir_tabelas($pdo);
    $cfg = wa_config_whatsapp($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'falha ' . wa_log_erro($e);
    exit;
}

if (!$cfg) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'whatsapp nao configurado';
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    // O PHP troca o ponto por sublinhado nos nomes dos parametros (hub.mode vira hub_mode).
    $modo = (string)($_GET['hub_mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? '');
    $desafio = (string)($_GET['hub_challenge'] ?? '');
    header('Content-Type: text/plain; charset=utf-8');
    if ($modo === 'subscribe' && $cfg['verify_token'] !== '' && hash_equals((string)$cfg['verify_token'], $token)) {
        echo $desafio;
        exit;
    }
    http_response_code(403);
    echo 'token invalido';
    exit;
}

if ($metodo !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'metodo invalido';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$corpo = file_get_contents('php://input') ?: '';
$assinatura = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if (!wa_assinatura_valida($corpo, $assinatura, (string)$cfg['app_secret'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'assinatura invalida']);
    exit;
}

$payload = json_decode($corpo, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'corpo invalido']);
    exit;
}

// Se o processamento falhar por um erro nosso, respondemos 200 mesmo assim: a Meta
// desativa o webhook de quem falha por muito tempo, e a reentrega nao consertaria
// um bug. O erro vai para o log do servidor com um codigo para cruzar.
try {
    $res = wa_processar_payload($pdo, $payload, [
        'enviar' => function (array $contato, string $texto) use ($pdo, $cfg) {
            wa_enviar_texto($pdo, $cfg, $contato, $texto, 'sistema');
        },
    ]);
    echo json_encode(['ok' => true] + $res);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'detalhe' => wa_log_erro($e)]);
}
