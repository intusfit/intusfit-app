<?php
/**
 * Intus Fit — API de Desafios e Catálogo de Planos
 *
 * Tabelas:
 *   intus_plano_catalogo       — templates de planos (individual ou desafio)
 *   intus_desafio              — grupos de desafio criados pelo admin
 *   intus_desafio_participante — atletas vinculados ao desafio
 *   intus_desafio_professor    — professores colaboradores do desafio
 *   intus_desafio_mensagem     — chat interno do grupo de desafio
 *
 * Rotas:
 *   GET    ?action=planos                         — lista catálogo de planos
 *   POST   ?action=planos                         — cria plano no catálogo
 *   PUT    ?action=planos&id=X                    — edita plano
 *   DELETE ?action=planos&id=X                    — exclui plano
 *
 *   GET    ?action=desafios                       — lista desafios
 *   GET    ?action=desafio&id=X                   — detalhe de um desafio
 *   POST   ?action=desafios                       — cria desafio
 *   PUT    ?action=desafios&id=X                  — edita desafio
 *   DELETE ?action=desafios&id=X                  — exclui desafio
 *
 *   POST   ?action=participantes                  — add participante(s)
 *   DELETE ?action=participantes&desafio=X&atleta=Y — remove participante
 *   GET    ?action=participantes&desafio=X        — lista participantes
 *
 *   POST   ?action=professores                    — add professor colaborador
 *   DELETE ?action=professores&desafio=X&usuario=Y — remove professor
 *
 *   GET    ?action=mensagens_grupo&desafio=X      — mensagens do grupo
 *   POST   ?action=mensagens_grupo                — envia mensagem no grupo
 *
 *   GET    ?action=ranking&desafio=X              — ranking de frequência do desafio
 *   GET    ?action=meus_desafios&atleta=X         — desafios ativos de um atleta
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

// ── PORTA DE ENTRADA ────────────────────────────────────────────────────────
// Este arquivo nao exigia autenticacao NENHUMA. Verificado no ar em 20/08/2026.
// A identidade agora vem da sessao real gravada em intus_sessions — nunca do
// formato do texto do token, nunca do corpo da requisicao.
require_once __DIR__ . '/_sessions.php';
function _intusExigeSessao(PDO $pdo, bool $soProfessor = true, bool $soAdmin = false): array {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    $tok = preg_match('/Bearer\s+(.+)$/i', trim($h), $m) ? trim($m[1]) : '';
    if ($tok === '') { http_response_code(401); echo json_encode(['error' => 'token ausente']); exit; }

    $sess = function_exists('validateSession') ? validateSession($pdo, $tok) : null;
    if (!$sess || !empty($sess['legacy'])) {
        http_response_code(401);
        echo json_encode(['error' => 'token invalido']);
        exit;
    }
    if ($soProfessor && ($sess['user_type'] ?? '') === 'aluno') {
        http_response_code(403);
        echo json_encode(['error' => 'restrito ao professor']);
        exit;
    }
    if ($soAdmin && empty($sess['admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'restrito ao administrador']);
        exit;
    }
    return $sess;
}


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

// Desafios sao do professor: catalogo de planos com valores, grupos, e o chat
// interno. Aluno le o proprio pelo app; qualquer escrita e do professor.
$_sessDes = _intusExigeSessao($pdo, false);
$_alunoDes = (($_sessDes['user_type'] ?? '') === 'aluno');
if ($_alunoDes && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(403);
    echo json_encode(['error' => 'apenas o professor altera desafios']);
    exit;
}

// ---------- Auto-create tables ----------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_plano_catalogo (
        idplano      INT AUTO_INCREMENT PRIMARY KEY,
        nome         VARCHAR(120) NOT NULL,
        tipo         ENUM('individual','desafio') NOT NULL DEFAULT 'individual',
        duracao_dias INT NOT NULL DEFAULT 30,
        valor        DECIMAL(10,2) NOT NULL DEFAULT 0,
        descricao    TEXT NULL,
        ativo        TINYINT NOT NULL DEFAULT 1,
        dtcriacao    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_desafio (
        iddesafio    INT AUTO_INCREMENT PRIMARY KEY,
        nome         VARCHAR(150) NOT NULL,
        descricao    TEXT NULL,
        idplano      INT NULL COMMENT 'plano do catálogo vinculado',
        dtinicio     DATE NOT NULL,
        dtfim        DATE NOT NULL,
        criado_por   INT NULL COMMENT 'idusuario admin que criou',
        ativo        TINYINT NOT NULL DEFAULT 1,
        dtcriacao    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_datas (dtinicio, dtfim),
        INDEX idx_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_desafio_participante (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        iddesafio    INT NOT NULL,
        idatleta     INT NOT NULL,
        dtentrada    DATE NOT NULL DEFAULT (CURRENT_DATE),
        ativo        TINYINT NOT NULL DEFAULT 1,
        UNIQUE KEY uk_desafio_atleta (iddesafio, idatleta),
        INDEX idx_desafio (iddesafio),
        INDEX idx_atleta (idatleta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_desafio_professor (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        iddesafio    INT NOT NULL,
        idusuario    INT NOT NULL,
        UNIQUE KEY uk_desafio_prof (iddesafio, idusuario),
        INDEX idx_desafio (iddesafio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_desafio_mensagem (
        idmensagem   INT AUTO_INCREMENT PRIMARY KEY,
        iddesafio    INT NOT NULL,
        idatleta     INT NULL COMMENT 'null = professor/admin',
        idusuario    INT NULL COMMENT 'null = aluno',
        nome_autor   VARCHAR(100) NULL,
        texto        TEXT NOT NULL,
        dtmensagem   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_desafio (iddesafio),
        INDEX idx_dt (dtmensagem)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ---------- Helpers ----------
function jsonBody() {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

$action = $_GET['action'] ?? '';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {

// ═══════════════ CATÁLOGO DE PLANOS ═══════════════
if ($action === 'planos') {
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT * FROM intus_plano_catalogo ORDER BY tipo, nome")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_map(function($r) {
            return [
                'idplano' => (int)$r['idplano'],
                'nome' => $r['nome'],
                'tipo' => $r['tipo'],
                'duracao_dias' => (int)$r['duracao_dias'],
                'valor' => (float)$r['valor'],
                'descricao' => $r['descricao'],
                'ativo' => (bool)(int)$r['ativo'],
            ];
        }, $rows));
        exit;
    }

    if ($method === 'POST') {
        $b = jsonBody();
        $nome = trim((string)($b['nome'] ?? ''));
        $tipo = ($b['tipo'] ?? 'individual') === 'desafio' ? 'desafio' : 'individual';
        $duracao = max(1, (int)($b['duracao_dias'] ?? 30));
        $valor = max(0, (float)($b['valor'] ?? 0));
        $desc = trim((string)($b['descricao'] ?? ''));

        if ($nome === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'nome obrigatório']); exit; }

        $st = $pdo->prepare("INSERT INTO intus_plano_catalogo (nome, tipo, duracao_dias, valor, descricao) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$nome, $tipo, $duracao, $valor, $desc]);
        $id = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'idplano' => $id]);
        exit;
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id obrigatório']); exit; }
        $b = jsonBody();
        $sets = []; $params = [];
        if (isset($b['nome']))         { $sets[] = "nome = ?"; $params[] = trim($b['nome']); }
        if (isset($b['tipo']))         { $sets[] = "tipo = ?"; $params[] = $b['tipo'] === 'desafio' ? 'desafio' : 'individual'; }
        if (isset($b['duracao_dias'])) { $sets[] = "duracao_dias = ?"; $params[] = max(1, (int)$b['duracao_dias']); }
        if (isset($b['valor']))        { $sets[] = "valor = ?"; $params[] = max(0, (float)$b['valor']); }
        if (isset($b['descricao']))    { $sets[] = "descricao = ?"; $params[] = trim($b['descricao']); }
        if (isset($b['ativo']))        { $sets[] = "ativo = ?"; $params[] = $b['ativo'] ? 1 : 0; }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $params[] = $id;
        $pdo->prepare("UPDATE intus_plano_catalogo SET " . implode(', ', $sets) . " WHERE idplano = ?")->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id obrigatório']); exit; }
        $pdo->prepare("DELETE FROM intus_plano_catalogo WHERE idplano = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ DESAFIOS ═══════════════
if ($action === 'desafios' || $action === 'desafio') {
    if ($method === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0 || $action === 'desafio') {
            $id = $id ?: (int)($_GET['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM intus_desafio WHERE iddesafio = ?");
            $st->execute([$id]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d) { http_response_code(404); echo json_encode(['error' => 'não encontrado']); exit; }

            $parts = $pdo->prepare("SELECT dp.idatleta, dp.dtentrada, dp.ativo FROM intus_desafio_participante dp WHERE dp.iddesafio = ?");
            $parts->execute([$id]);
            $participantes = $parts->fetchAll(PDO::FETCH_ASSOC);

            $profs = $pdo->prepare("SELECT idusuario FROM intus_desafio_professor WHERE iddesafio = ?");
            $profs->execute([$id]);
            $professores = array_column($profs->fetchAll(PDO::FETCH_ASSOC), 'idusuario');

            echo json_encode([
                'iddesafio' => (int)$d['iddesafio'],
                'nome' => $d['nome'],
                'descricao' => $d['descricao'],
                'idplano' => $d['idplano'] ? (int)$d['idplano'] : null,
                'dtinicio' => $d['dtinicio'],
                'dtfim' => $d['dtfim'],
                'criado_por' => $d['criado_por'] ? (int)$d['criado_por'] : null,
                'ativo' => (bool)(int)$d['ativo'],
                'participantes' => array_map(function($p) {
                    return ['idatleta' => (int)$p['idatleta'], 'dtentrada' => $p['dtentrada'], 'ativo' => (bool)(int)$p['ativo']];
                }, $participantes),
                'professores' => array_map('intval', $professores),
            ]);
            exit;
        }

        // Lista todos
        $rows = $pdo->query("SELECT * FROM intus_desafio ORDER BY dtinicio DESC")->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $d) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM intus_desafio_participante WHERE iddesafio = ? AND ativo = 1");
            $cnt->execute([(int)$d['iddesafio']]);
            $result[] = [
                'iddesafio' => (int)$d['iddesafio'],
                'nome' => $d['nome'],
                'descricao' => $d['descricao'],
                'idplano' => $d['idplano'] ? (int)$d['idplano'] : null,
                'dtinicio' => $d['dtinicio'],
                'dtfim' => $d['dtfim'],
                'ativo' => (bool)(int)$d['ativo'],
                'participantes_count' => (int)$cnt->fetchColumn(),
            ];
        }
        echo json_encode($result);
        exit;
    }

    if ($method === 'POST') {
        $b = jsonBody();
        $nome = trim((string)($b['nome'] ?? ''));
        $desc = trim((string)($b['descricao'] ?? ''));
        $idplano = isset($b['idplano']) ? (int)$b['idplano'] : null;
        $dtinicio = $b['dtinicio'] ?? date('Y-m-d');
        $dtfim = $b['dtfim'] ?? date('Y-m-d', strtotime('+30 days'));
        $criado_por = isset($b['criado_por']) ? (int)$b['criado_por'] : null;

        if ($nome === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'nome obrigatório']); exit; }

        $st = $pdo->prepare("INSERT INTO intus_desafio (nome, descricao, idplano, dtinicio, dtfim, criado_por) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([$nome, $desc ?: null, $idplano, $dtinicio, $dtfim, $criado_por]);
        $id = (int)$pdo->lastInsertId();

        // Add participantes iniciais
        if (!empty($b['participantes']) && is_array($b['participantes'])) {
            $stP = $pdo->prepare("INSERT IGNORE INTO intus_desafio_participante (iddesafio, idatleta) VALUES (?, ?)");
            foreach ($b['participantes'] as $idatleta) {
                $stP->execute([$id, (int)$idatleta]);
            }
        }

        // Add professores colaboradores
        if (!empty($b['professores']) && is_array($b['professores'])) {
            $stU = $pdo->prepare("INSERT IGNORE INTO intus_desafio_professor (iddesafio, idusuario) VALUES (?, ?)");
            foreach ($b['professores'] as $idusuario) {
                $stU->execute([$id, (int)$idusuario]);
            }
        }

        echo json_encode(['ok' => true, 'iddesafio' => $id]);
        exit;
    }

    if ($method === 'PUT') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id obrigatório']); exit; }
        $b = jsonBody();
        $sets = []; $params = [];
        if (isset($b['nome']))      { $sets[] = "nome = ?"; $params[] = trim($b['nome']); }
        if (isset($b['descricao'])) { $sets[] = "descricao = ?"; $params[] = trim($b['descricao']); }
        if (isset($b['idplano']))   { $sets[] = "idplano = ?"; $params[] = $b['idplano'] ? (int)$b['idplano'] : null; }
        if (isset($b['dtinicio']))  { $sets[] = "dtinicio = ?"; $params[] = $b['dtinicio']; }
        if (isset($b['dtfim']))     { $sets[] = "dtfim = ?"; $params[] = $b['dtfim']; }
        if (isset($b['ativo']))     { $sets[] = "ativo = ?"; $params[] = $b['ativo'] ? 1 : 0; }
        if (!empty($sets)) {
            $params[] = $id;
            $pdo->prepare("UPDATE intus_desafio SET " . implode(', ', $sets) . " WHERE iddesafio = ?")->execute($params);
        }

        // Sync participantes if provided
        if (isset($b['participantes']) && is_array($b['participantes'])) {
            $pdo->prepare("DELETE FROM intus_desafio_participante WHERE iddesafio = ?")->execute([$id]);
            $stP = $pdo->prepare("INSERT INTO intus_desafio_participante (iddesafio, idatleta) VALUES (?, ?)");
            foreach ($b['participantes'] as $idatleta) {
                $stP->execute([$id, (int)$idatleta]);
            }
        }

        // Sync professores if provided
        if (isset($b['professores']) && is_array($b['professores'])) {
            $pdo->prepare("DELETE FROM intus_desafio_professor WHERE iddesafio = ?")->execute([$id]);
            $stU = $pdo->prepare("INSERT INTO intus_desafio_professor (iddesafio, idusuario) VALUES (?, ?)");
            foreach ($b['professores'] as $idusuario) {
                $stU->execute([$id, (int)$idusuario]);
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'id obrigatório']); exit; }
        $pdo->prepare("DELETE FROM intus_desafio_participante WHERE iddesafio = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM intus_desafio_professor WHERE iddesafio = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM intus_desafio_mensagem WHERE iddesafio = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM intus_desafio WHERE iddesafio = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PARTICIPANTES ═══════════════
if ($action === 'participantes') {
    $iddesafio = (int)($_GET['desafio'] ?? 0);

    if ($method === 'GET') {
        if ($iddesafio <= 0) { http_response_code(400); echo json_encode(['error' => 'desafio obrigatório']); exit; }
        $st = $pdo->prepare("SELECT * FROM intus_desafio_participante WHERE iddesafio = ?");
        $st->execute([$iddesafio]);
        echo json_encode(array_map(function($r) {
            return ['idatleta' => (int)$r['idatleta'], 'dtentrada' => $r['dtentrada'], 'ativo' => (bool)(int)$r['ativo']];
        }, $st->fetchAll(PDO::FETCH_ASSOC)));
        exit;
    }

    if ($method === 'POST') {
        $b = jsonBody();
        $iddesafio = (int)($b['iddesafio'] ?? $iddesafio);
        $atletas = $b['atletas'] ?? [];
        if ($iddesafio <= 0 || empty($atletas)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'iddesafio e atletas obrigatórios']);
            exit;
        }
        $st = $pdo->prepare("INSERT IGNORE INTO intus_desafio_participante (iddesafio, idatleta) VALUES (?, ?)");
        $added = 0;
        foreach ($atletas as $ida) {
            $st->execute([$iddesafio, (int)$ida]);
            $added += $st->rowCount();
        }
        echo json_encode(['ok' => true, 'adicionados' => $added]);
        exit;
    }

    if ($method === 'DELETE') {
        $idatleta = (int)($_GET['atleta'] ?? 0);
        if ($iddesafio <= 0 || $idatleta <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'desafio e atleta obrigatórios']);
            exit;
        }
        $pdo->prepare("DELETE FROM intus_desafio_participante WHERE iddesafio = ? AND idatleta = ?")->execute([$iddesafio, $idatleta]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PROFESSORES COLABORADORES ═══════════════
if ($action === 'professores') {
    if ($method === 'POST') {
        $b = jsonBody();
        $iddesafio = (int)($b['iddesafio'] ?? 0);
        $idusuario = (int)($b['idusuario'] ?? 0);
        if ($iddesafio <= 0 || $idusuario <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'iddesafio e idusuario obrigatórios']);
            exit;
        }
        $pdo->prepare("INSERT IGNORE INTO intus_desafio_professor (iddesafio, idusuario) VALUES (?, ?)")->execute([$iddesafio, $idusuario]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $iddesafio = (int)($_GET['desafio'] ?? 0);
        $idusuario = (int)($_GET['usuario'] ?? 0);
        if ($iddesafio <= 0 || $idusuario <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'desafio e usuario obrigatórios']);
            exit;
        }
        $pdo->prepare("DELETE FROM intus_desafio_professor WHERE iddesafio = ? AND idusuario = ?")->execute([$iddesafio, $idusuario]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ MENSAGENS DO GRUPO ═══════════════
if ($action === 'mensagens_grupo') {
    $iddesafio = (int)($_GET['desafio'] ?? 0);

    if ($method === 'GET') {
        if ($iddesafio <= 0) { http_response_code(400); echo json_encode(['error' => 'desafio obrigatório']); exit; }
        $st = $pdo->prepare("SELECT * FROM intus_desafio_mensagem WHERE iddesafio = ? ORDER BY dtmensagem ASC LIMIT 500");
        $st->execute([$iddesafio]);
        echo json_encode(array_map(function($r) {
            return [
                'idmensagem' => (int)$r['idmensagem'],
                'iddesafio' => (int)$r['iddesafio'],
                'idatleta' => $r['idatleta'] ? (int)$r['idatleta'] : null,
                'idusuario' => $r['idusuario'] ? (int)$r['idusuario'] : null,
                'nome_autor' => $r['nome_autor'],
                'texto' => $r['texto'],
                'dtmensagem' => $r['dtmensagem'],
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC)));
        exit;
    }

    if ($method === 'POST') {
        $b = jsonBody();
        $iddesafio = (int)($b['iddesafio'] ?? $iddesafio);
        $texto = trim((string)($b['texto'] ?? ''));
        $idatleta = isset($b['idatleta']) ? (int)$b['idatleta'] : null;
        $idusuario = isset($b['idusuario']) ? (int)$b['idusuario'] : null;
        $nome = trim((string)($b['nome_autor'] ?? ''));

        if ($iddesafio <= 0 || $texto === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'iddesafio e texto obrigatórios']);
            exit;
        }

        $st = $pdo->prepare("INSERT INTO intus_desafio_mensagem (iddesafio, idatleta, idusuario, nome_autor, texto) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$iddesafio, $idatleta, $idusuario, $nome ?: null, $texto]);
        $id = (int)$pdo->lastInsertId();

        $row = $pdo->query("SELECT * FROM intus_desafio_mensagem WHERE idmensagem = $id")->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'ok' => true,
            'mensagem' => [
                'idmensagem' => (int)$row['idmensagem'],
                'iddesafio' => (int)$row['iddesafio'],
                'idatleta' => $row['idatleta'] ? (int)$row['idatleta'] : null,
                'idusuario' => $row['idusuario'] ? (int)$row['idusuario'] : null,
                'nome_autor' => $row['nome_autor'],
                'texto' => $row['texto'],
                'dtmensagem' => $row['dtmensagem'],
            ],
        ]);
        exit;
    }
}

// ═══════════════ RANKING DO DESAFIO ═══════════════
if ($action === 'ranking') {
    $iddesafio = (int)($_GET['desafio'] ?? 0);
    if ($iddesafio <= 0) { http_response_code(400); echo json_encode(['error' => 'desafio obrigatório']); exit; }

    // Busca período do desafio
    $st = $pdo->prepare("SELECT dtinicio, dtfim FROM intus_desafio WHERE iddesafio = ?");
    $st->execute([$iddesafio]);
    $desafio = $st->fetch(PDO::FETCH_ASSOC);
    if (!$desafio) { http_response_code(404); echo json_encode(['error' => 'desafio não encontrado']); exit; }

    // Busca participantes ativos
    $st = $pdo->prepare("SELECT idatleta FROM intus_desafio_participante WHERE iddesafio = ? AND ativo = 1");
    $st->execute([$iddesafio]);
    $ids = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'idatleta');

    if (empty($ids)) { echo json_encode([]); exit; }

    // Busca sessões no período (tabela intus_sessao se existir)
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$desafio['dtinicio'], $desafio['dtfim']]);

    $ranking = [];
    try {
        $st = $pdo->prepare("
            SELECT idatleta, COUNT(*) as sessoes, SUM(duracao) as duracao_total
            FROM intus_sessao
            WHERE idatleta IN ($placeholders)
              AND DATE(dtsessao) >= ? AND DATE(dtsessao) <= ?
            GROUP BY idatleta
            ORDER BY sessoes DESC, duracao_total DESC
        ");
        $st->execute($params);
        $ranking = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // intus_sessao pode não existir ainda
        $ranking = [];
    }

    // Completa participantes sem sessão
    $ranked = array_column($ranking, 'idatleta');
    foreach ($ids as $ida) {
        if (!in_array($ida, $ranked)) {
            $ranking[] = ['idatleta' => (int)$ida, 'sessoes' => 0, 'duracao_total' => 0];
        }
    }

    echo json_encode(array_map(function($r) {
        return [
            'idatleta' => (int)$r['idatleta'],
            'sessoes' => (int)$r['sessoes'],
            'duracao_total' => (int)($r['duracao_total'] ?? 0),
        ];
    }, $ranking));
    exit;
}

// ═══════════════ MEUS DESAFIOS (para app do aluno) ═══════════════
if ($action === 'meus_desafios') {
    $idatleta = (int)($_GET['atleta'] ?? 0);
    if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatório']); exit; }

    $st = $pdo->prepare("
        SELECT d.*, dp.ativo as participante_ativo
        FROM intus_desafio d
        JOIN intus_desafio_participante dp ON dp.iddesafio = d.iddesafio
        WHERE dp.idatleta = ?
        ORDER BY d.dtinicio DESC
    ");
    $st->execute([$idatleta]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $hoje = date('Y-m-d');
    echo json_encode(array_map(function($d) use ($hoje) {
        $ativo = (bool)(int)$d['ativo'] && $d['dtfim'] >= $hoje && (bool)(int)$d['participante_ativo'];
        return [
            'iddesafio' => (int)$d['iddesafio'],
            'nome' => $d['nome'],
            'descricao' => $d['descricao'],
            'dtinicio' => $d['dtinicio'],
            'dtfim' => $d['dtfim'],
            'ativo' => $ativo,
            'encerrado' => $d['dtfim'] < $hoje,
        ];
    }, $rows));
    exit;
}

// Rota não encontrada
http_response_code(404);
echo json_encode(['error' => 'action inválida: ' . $action]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
