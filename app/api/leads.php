<?php
// ── DETALHE DE ERRO NAO VAI PARA O CLIENTE ─────────────────────────────────
// Mesmo padrao dos demais endpoints: mensagem completa so no log do servidor.
function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

/**
 * Captura de leads (visitante interessado, ainda nao e aluno) — usado pela
 * landing page de teste gratis. Nao cria conta de aluno nenhuma: e so um
 * registro de interesse pra alguem da equipe seguir por WhatsApp.
 *
 * Rotas:
 *   POST /api/leads.php?action=criar {nome, whatsapp, email?, objetivo?, origem?}
 *     -> { ok:true, id }
 *
 * Sem auth — endpoint publico consumido pela landing page (mesmo padrao do
 * aluno_login.php). Protegido por rate limit (por IP).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_charset_fix.php';
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'metodo invalido']);
    exit;
}

// ---------- Conexao ----------
$cfg = @include __DIR__ . '/../config/db.php';
$pdo = null;
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new Exception('sem credenciais');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_lead (
        idlead      INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(150) NOT NULL,
        whatsapp    VARCHAR(30)  NOT NULL,
        email       VARCHAR(150) NULL,
        objetivo    VARCHAR(100) NULL,
        origem      VARCHAR(50)  NOT NULL DEFAULT 'landing-teste-gratis',
        status      VARCHAR(20)  NOT NULL DEFAULT 'novo',
        ip          VARCHAR(45)  NULL,
        criado_em   DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_criado (criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
_intusGarantirUtf8mb4($pdo, 'intus_lead', ['nome', 'objetivo']);

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if ($action === 'criar') {
    require_once __DIR__ . '/_rate_limit.php';
    checkRateLimit($pdo, 'lead_criar', 10, 3600);

    // Honeypot: campo invisivel pro humano, so um robo de spam preenche.
    if (!empty($body['site'])) { echo json_encode(['ok' => true]); exit; } // finge sucesso, nao grava nada

    $nome = trim((string)($body['nome'] ?? ''));
    $whatsapp = preg_replace('/[^0-9+]/', '', (string)($body['whatsapp'] ?? ''));
    $email = trim((string)($body['email'] ?? ''));
    $objetivo = trim((string)($body['objetivo'] ?? ''));
    $origem = trim((string)($body['origem'] ?? '')) ?: 'landing-teste-gratis';

    if ($nome === '' || mb_strlen($nome) > 150) { http_response_code(400); echo json_encode(['ok' => false, 'erro' => 'nome invalido']); exit; }
    if (strlen($whatsapp) < 10 || strlen($whatsapp) > 20) { http_response_code(400); echo json_encode(['ok' => false, 'erro' => 'whatsapp invalido']); exit; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['ok' => false, 'erro' => 'email invalido']); exit; }

    require_once __DIR__ . '/_rate_limit.php';
    recordAttempt($pdo, 'lead_criar');

    $st = $pdo->prepare("INSERT INTO intus_lead (nome, whatsapp, email, objetivo, origem, ip) VALUES (?, ?, ?, ?, ?, ?)");
    $st->execute([$nome, $whatsapp, $email ?: null, $objetivo ?: null, $origem, $_SERVER['REMOTE_ADDR'] ?? null]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'erro' => 'acao invalida']);
