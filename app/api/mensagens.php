<?php
/**
 * Intus Fit — API de Mensagens / Chat Interno
 *
 * Tabelas:
 *   intus_mensagem      — mensagens de chat entre professor e aluno
 *   intus_comentario    — comentários de treino (criados pela sessão do aluno)
 *   intus_reacao        — reações rápidas do professor aos comentários
 *
 * Rotas:
 *   GET  ?action=listar[&atleta=ID]              — lista mensagens (filtradas por atleta se informado)
 *   POST ?action=enviar                          — envia mensagem { idatleta, texto, remetente }
 *   POST ?action=marcar_lida                     — marca como lida { ids: [1,2,3] }
 *   GET  ?action=comentarios[&atleta=ID]         — lista comentários de treino
 *   POST ?action=reagir                          — reação a comentário { idcomentario, emoji }
 *   POST ?action=salvar_comentario               — salva comentário de treino do aluno
 *   GET  ?action=nao_lidas[&atleta=ID]           — contagem de não lidas
 *   DELETE ?action=excluir&id=X                  — exclui mensagem
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_charset_fix.php';
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Conexão ----------
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
    echo json_encode(['ok' => false, 'error' => 'falha conexao']);
    exit;
}

// ---------- Tabelas (auto-create) ----------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_mensagem (
        idmensagem   INT AUTO_INCREMENT PRIMARY KEY,
        idatleta     INT NOT NULL,
        idusuario    INT NULL COMMENT 'professor que enviou (null = aluno)',
        remetente    ENUM('professor','aluno') NOT NULL DEFAULT 'aluno',
        texto        TEXT NOT NULL,
        stlido       CHAR(1) NOT NULL DEFAULT 'N',
        dtmensagem   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_atleta (idatleta),
        INDEX idx_lido (stlido)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
_intusGarantirUtf8mb4($pdo, 'intus_mensagem', ['texto']);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_comentario (
        idcomentario INT AUTO_INCREMENT PRIMARY KEY,
        idatleta     INT NOT NULL,
        idficha      INT NULL,
        divisao      VARCHAR(4) NULL,
        dtsessao     DATE NULL,
        texto        TEXT NOT NULL,
        dtcriacao    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_atleta (idatleta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
_intusGarantirUtf8mb4($pdo, 'intus_comentario', ['texto']);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_reacao (
        idreacao     INT AUTO_INCREMENT PRIMARY KEY,
        idcomentario INT NOT NULL,
        idusuario    INT NOT NULL,
        emoji        VARCHAR(10) NOT NULL,
        dtcriacao    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_comment (idcomentario, idusuario),
        INDEX idx_comentario (idcomentario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Auth ----------
function getAuthUser() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (strpos($h, 'Bearer ') === 0) return substr($h, 7);
    return null;
}
$tok = getAuthUser() ?? '';
if ($tok === '') { http_response_code(401); echo json_encode(['error' => 'token ausente']); exit; }

function jsonBody() {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

require_once __DIR__ . '/_auth_context.php';
$_authCtx = getAuthContext($pdo, $tok);
$_isAdmin = $_authCtx['admin'];
$_userId  = $_authCtx['idusuario'];
$_allowedIds = null;
if (!$_isAdmin && $_userId > 0 && !$_authCtx['is_aluno']) {
    $_allowedIds = getAtletasDoUsuario($pdo, $_userId);
}

// ---------- Roteamento ----------
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // ===== Listar mensagens =====
    if ($action === 'listar') {
        $atleta = (int)($_GET['atleta'] ?? 0);
        if ($atleta > 0) {
            // Verificar acesso ao atleta
            if ($_allowedIds !== null && !in_array($atleta, $_allowedIds)) {
                echo json_encode([]);
                exit;
            }
            $st = $pdo->prepare("SELECT * FROM intus_mensagem WHERE idatleta = ? ORDER BY dtmensagem ASC");
            $st->execute([$atleta]);
        } elseif ($_allowedIds !== null) {
            if (empty($_allowedIds)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($_allowedIds), '?'));
            $st = $pdo->prepare("SELECT * FROM intus_mensagem WHERE idatleta IN ($ph) ORDER BY dtmensagem DESC LIMIT 500");
            $st->execute($_allowedIds);
        } else {
            $st = $pdo->query("SELECT * FROM intus_mensagem ORDER BY dtmensagem DESC LIMIT 500");
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_map(function($r) {
            return [
                'idmensagem' => (int)$r['idmensagem'],
                'idatleta'   => (int)$r['idatleta'],
                'idusuario'  => $r['idusuario'] ? (int)$r['idusuario'] : null,
                'remetente'  => $r['remetente'],
                'texto'      => $r['texto'],
                'stlido'     => $r['stlido'],
                'dtmensagem' => $r['dtmensagem'],
            ];
        }, $rows));
        exit;
    }

    // ===== Enviar mensagem =====
    if ($action === 'enviar' && $method === 'POST') {
        $b = jsonBody();
        $idatleta  = (int)($b['idatleta'] ?? 0);
        $texto     = trim((string)($b['texto'] ?? ''));
        $remetente = ($b['remetente'] ?? 'aluno') === 'professor' ? 'professor' : 'aluno';
        $idusuario = isset($b['idusuario']) ? (int)$b['idusuario'] : null;

        if ($idatleta <= 0 || $texto === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'idatleta e texto obrigatorios']);
            exit;
        }

        $st = $pdo->prepare("INSERT INTO intus_mensagem (idatleta, idusuario, remetente, texto) VALUES (?, ?, ?, ?)");
        $st->execute([$idatleta, $idusuario, $remetente, $texto]);
        $id = (int)$pdo->lastInsertId();

        $row = $pdo->query("SELECT * FROM intus_mensagem WHERE idmensagem = $id")->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'ok' => true,
            'mensagem' => [
                'idmensagem' => (int)$row['idmensagem'],
                'idatleta'   => (int)$row['idatleta'],
                'idusuario'  => $row['idusuario'] ? (int)$row['idusuario'] : null,
                'remetente'  => $row['remetente'],
                'texto'      => $row['texto'],
                'stlido'     => $row['stlido'],
                'dtmensagem' => $row['dtmensagem'],
            ],
        ]);
        exit;
    }

    // ===== Marcar como lida =====
    if ($action === 'marcar_lida' && $method === 'POST') {
        $b = jsonBody();
        $ids = $b['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            echo json_encode(['ok' => true, 'atualizados' => 0]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("UPDATE intus_mensagem SET stlido = 'S' WHERE idmensagem IN ($placeholders)");
        $st->execute(array_map('intval', $ids));
        echo json_encode(['ok' => true, 'atualizados' => $st->rowCount()]);
        exit;
    }

    // ===== Não lidas (contagem) =====
    if ($action === 'nao_lidas') {
        $atleta = (int)($_GET['atleta'] ?? 0);
        if ($atleta > 0) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM intus_mensagem WHERE idatleta = ? AND stlido = 'N'");
            $st->execute([$atleta]);
        } else {
            $st = $pdo->query("SELECT COUNT(*) FROM intus_mensagem WHERE stlido = 'N'");
        }
        echo json_encode(['count' => (int)$st->fetchColumn()]);
        exit;
    }

    // ===== Salvar comentário de treino =====
    if ($action === 'salvar_comentario' && $method === 'POST') {
        $b = jsonBody();
        $idatleta = (int)($b['idatleta'] ?? 0);
        $texto    = trim((string)($b['texto'] ?? ''));
        $idficha  = isset($b['idficha']) ? (int)$b['idficha'] : null;
        $divisao  = $b['divisao'] ?? null;
        $dtsessao = $b['dtsessao'] ?? date('Y-m-d');

        if ($idatleta <= 0 || $texto === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'idatleta e texto obrigatorios']);
            exit;
        }

        $st = $pdo->prepare("INSERT INTO intus_comentario (idatleta, idficha, divisao, dtsessao, texto) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$idatleta, $idficha, $divisao, $dtsessao, $texto]);
        echo json_encode(['ok' => true, 'idcomentario' => (int)$pdo->lastInsertId()]);
        exit;
    }

    // ===== Listar comentários =====
    if ($action === 'comentarios') {
        $atleta = (int)($_GET['atleta'] ?? 0);
        if ($atleta > 0) {
            $st = $pdo->prepare("SELECT c.*, GROUP_CONCAT(CONCAT(r.idusuario,':',r.emoji) SEPARATOR '|') as reacoes FROM intus_comentario c LEFT JOIN intus_reacao r ON r.idcomentario = c.idcomentario WHERE c.idatleta = ? GROUP BY c.idcomentario ORDER BY c.dtcriacao DESC LIMIT 100");
            $st->execute([$atleta]);
        } else {
            $st = $pdo->query("SELECT c.*, GROUP_CONCAT(CONCAT(r.idusuario,':',r.emoji) SEPARATOR '|') as reacoes FROM intus_comentario c LEFT JOIN intus_reacao r ON r.idcomentario = c.idcomentario GROUP BY c.idcomentario ORDER BY c.dtcriacao DESC LIMIT 200");
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_map(function($r) {
            $reacoes = [];
            if (!empty($r['reacoes'])) {
                foreach (explode('|', $r['reacoes']) as $part) {
                    [$uid, $emoji] = explode(':', $part, 2);
                    $reacoes[] = ['idusuario' => (int)$uid, 'emoji' => $emoji];
                }
            }
            return [
                'idcomentario' => (int)$r['idcomentario'],
                'idatleta'     => (int)$r['idatleta'],
                'idficha'      => $r['idficha'] ? (int)$r['idficha'] : null,
                'divisao'      => $r['divisao'],
                'dtsessao'     => $r['dtsessao'],
                'texto'        => $r['texto'],
                'dtcriacao'    => $r['dtcriacao'],
                'reacoes'      => $reacoes,
            ];
        }, $rows));
        exit;
    }

    // ===== Reagir a comentário =====
    if ($action === 'reagir' && $method === 'POST') {
        $b = jsonBody();
        $idcomentario = (int)($b['idcomentario'] ?? 0);
        $idusuario    = (int)($b['idusuario'] ?? 0);
        $emoji        = trim((string)($b['emoji'] ?? ''));

        if ($idcomentario <= 0 || $idusuario <= 0 || $emoji === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'dados incompletos']);
            exit;
        }

        $st = $pdo->prepare("INSERT INTO intus_reacao (idcomentario, idusuario, emoji) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)");
        $st->execute([$idcomentario, $idusuario, $emoji]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ===== Excluir mensagem =====
    if ($action === 'excluir' && $method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'id obrigatorio']);
            exit;
        }
        $pdo->prepare("DELETE FROM intus_mensagem WHERE idmensagem = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'action invalida']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
