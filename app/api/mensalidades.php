<?php
// ── DETALHE DE ERRO NAO VAI PARA O CLIENTE ─────────────────────────────────
// As respostas devolviam $e->getMessage() direto. Em falha de conexao isso
// traz host, nome do banco e usuario; em erro de SQL, o nome das tabelas e
// colunas. E um mapa do sistema entregue de graca a quem estiver sondando.
// Agora a mensagem completa vai para o log do servidor e o cliente recebe
// so um numero para voce cruzar com o log.
function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

/**
 * Endpoint CRUD de mensalidades (matrículas / cobranças).
 *
 * Rotas:
 *   GET    /api/mensalidades.php               -> lista todas
 *   GET    /api/mensalidades.php?atleta=X      -> lista por atleta
 *   GET    /api/mensalidades.php?status=pago   -> filtra por status
 *   GET    /api/mensalidades.php?id=X          -> 1 mensalidade
 *   POST   /api/mensalidades.php               {idatleta, dshistorico, dtvencimento, vlpagar, ...}
 *   PUT    /api/mensalidades.php?id=X          {campo:valor}
 *   DELETE /api/mensalidades.php?id=X          -> excluir
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Auth ----------
function bearerToken() {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}
$tok = bearerToken();
if ($tok === '') {
    http_response_code(401);
    echo json_encode(['error' => 'token ausente']);
    exit;
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
    echo json_encode(['error' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Auto-create table ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS intus_mensalidade (
    idmensalidade   INT AUTO_INCREMENT PRIMARY KEY,
    idatleta        INT NOT NULL,
    dshistorico     VARCHAR(200) DEFAULT 'Mensalidade',
    dtinicio        DATE DEFAULT NULL,
    dtvencimento    DATE NOT NULL,
    vlpagar         DECIMAL(10,2) DEFAULT 0,
    vldesconto      DECIMAL(10,2) DEFAULT 0,
    vljuro          DECIMAL(10,2) DEFAULT 0,
    stpgto          CHAR(1) DEFAULT 'N',
    dtpagamento     DATE DEFAULT NULL,
    vlpagamento     DECIMAL(10,2) DEFAULT NULL,
    dspagamento     VARCHAR(200) DEFAULT NULL,
    professor       VARCHAR(100) DEFAULT NULL,
    dtcriacao       DATETIME DEFAULT CURRENT_TIMESTAMP,
    tipo_plano          VARCHAR(60)  DEFAULT NULL,
    stmatricula         VARCHAR(12)  DEFAULT 'ativa',
    dt_tranca_inicio    DATE         DEFAULT NULL,
    dt_tranca_fim       DATE         DEFAULT NULL,
    dias_trancado       INT          DEFAULT 0,
    parcela_num         INT          DEFAULT NULL,
    parcela_total       INT          DEFAULT NULL,
    forma_pgto          VARCHAR(20)  DEFAULT NULL,
    idmatricula_origem  INT          DEFAULT NULL,
    motivo_encerramento VARCHAR(120) DEFAULT NULL,
    recorrencia         VARCHAR(12)  DEFAULT NULL,
    INDEX idx_atleta (idatleta),
    INDEX idx_vencimento (dtvencimento),
    INDEX idx_status (stpgto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ---------- Migração incremental (Fase 1): adiciona colunas novas se faltarem ----------
// Idempotente e não-destrutivo: só adiciona o que não existe (tabelas antigas ganham as colunas).
$_novasColunas = [
    'tipo_plano'          => "VARCHAR(60) DEFAULT NULL",
    'stmatricula'         => "VARCHAR(12) DEFAULT 'ativa'",
    'dt_tranca_inicio'    => "DATE DEFAULT NULL",
    'dt_tranca_fim'       => "DATE DEFAULT NULL",
    'dias_trancado'       => "INT DEFAULT 0",
    'parcela_num'         => "INT DEFAULT NULL",
    'parcela_total'       => "INT DEFAULT NULL",
    'forma_pgto'          => "VARCHAR(20) DEFAULT NULL",
    'idmatricula_origem'  => "INT DEFAULT NULL",
    'motivo_encerramento' => "VARCHAR(120) DEFAULT NULL",
    'recorrencia'         => "VARCHAR(12) DEFAULT NULL",
];
try {
    $_cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'intus_mensalidade'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($_novasColunas as $_c => $_def) {
        if (!in_array($_c, $_cols, true)) {
            try { $pdo->exec("ALTER TABLE intus_mensalidade ADD COLUMN `$_c` $_def"); } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) { /* segue mesmo se information_schema falhar */ }

// ---------- Detect atleta table for JOINs ----------
$atletaTable = null;
foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        $atletaTable = $t;
        break;
    } catch (Throwable $e) {}
}

// ---------- Helpers ----------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$id     = isset($_GET['id'])     ? intval($_GET['id'])     : null;
$atleta = isset($_GET['atleta']) ? intval($_GET['atleta']) : null;
$status = isset($_GET['status']) ? $_GET['status']         : null;

function jsonBody() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function idCol($tbl) {
    global $pdo;
    foreach (['idatleta', 'id', 'idaluno'] as $c) {
        try { $pdo->query("SELECT `$c` FROM `$tbl` LIMIT 1"); return $c; } catch (Throwable $e) {}
    }
    return 'id';
}

function nomeCol($tbl) {
    global $pdo;
    foreach (['nome', 'nmathleta', 'name'] as $c) {
        try { $pdo->query("SELECT `$c` FROM `$tbl` LIMIT 1"); return $c; } catch (Throwable $e) {}
    }
    return 'nome';
}

require_once __DIR__ . '/_audit_log.php';
require_once __DIR__ . '/_auth_context.php';

// Auth Context (filtro por professor)
$_authCtx = getAuthContext($pdo, $tok);
$_isAdmin = $_authCtx['admin'];
$_userId  = $_authCtx['idusuario'];
// ─── SEGURANCA: token presente porem nao reconhecido ────────────────────────
// Ate aqui bastava existir QUALQUER texto no cabecalho Authorization. Um token
// invalido produz idusuario = 0, e todos os filtros de acesso abaixo comecam
// com "idusuario > 0" — entao eles eram simplesmente pulados e a resposta saia
// com a base inteira. Na pratica, a palavra "xxxx" no lugar do token devolvia
// todos os clientes, com email e telefone. A verificacao abaixo encerra isso:
// sem sessao valida (ou token legado enquanto PERMITIR_TOKEN_LEGADO estiver
// ligado), a requisicao para no 401 e nao chega em nenhuma consulta.
if ((int)($_authCtx['idusuario'] ?? 0) <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'token invalido']);
    exit;
}

// ── QUEM VE O QUE ──────────────────────────────────────────────────────────
// $_allowedIds = null significa "sem restricao", e era o que o ALUNO recebia:
// a condicao terminava em "&& !is_aluno", entao para aluno ela era falsa, a
// lista ficava nula e o filtro nunca era aplicado. Resultado medido em
// 20/08/2026 com um token de aluno de verdade: a rota devolveu 100 mensalidades
// da base inteira — nome, valor, vencimento, forma de pagamento — e
// ?action=metricas devolveu a receita do mes. Agora aluno recebe uma lista
// explicita com o proprio id, e nunca null.
$_ehAlunoMens = !empty($_authCtx['is_aluno']);
$_allowedIds = null;
if ($_ehAlunoMens) {
    $_meuAtleta = (int)($_authCtx['idatleta'] ?? $_userId);
    $_allowedIds = $_meuAtleta > 0 ? [$_meuAtleta] : [];
} elseif (!$_isAdmin && $_userId > 0) {
    $_allowedIds = getAtletasDoUsuario($pdo, $_userId);
}

// Financeiro nao se edita pelo aplicativo do aluno. Sem esta trava, o aluno
// marcava a propria mensalidade como paga (escapando do bloqueio por
// inadimplencia) ou apagava a cobranca.
if ($_ehAlunoMens && $method !== 'GET') {
    http_response_code(403);
    echo json_encode(['error' => 'apenas o professor altera o financeiro']);
    exit;
}
// Numeros do negocio (receita, inadimplencia) nao sao do aluno.
if ($_ehAlunoMens && ($_GET['action'] ?? '') === 'metricas') {
    http_response_code(403);
    echo json_encode(['error' => 'restrito ao professor']);
    exit;
}

// IDs de atletas protegidos (admin é prof responsável → colaboradores não editam)
$_protectedIds = [];
if (!$_isAdmin && $atletaTable) {
    try {
        $idC = idCol($atletaTable);
        $aiCols = array_column($pdo->query("SHOW COLUMNS FROM `$atletaTable`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (in_array('professores_responsaveis', $aiCols)) {
            $rows = $pdo->query("SELECT `$idC`, professores_responsaveis FROM `$atletaTable`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $profs = json_decode($r['professores_responsaveis'] ?? '[]', true) ?: [];
                $adminIds = [1, 2];
                if (array_intersect($adminIds, array_map('intval', $profs))) {
                    $_protectedIds[] = intval($r[$idC]);
                }
            }
        } elseif (in_array('idprofessor', $aiCols)) {
            $st = $pdo->prepare("SELECT `$idC` FROM `$atletaTable` WHERE idprofessor = ?");
            $st->execute([2]);
            $_protectedIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Throwable $e) {}
}

// ---------- METRICAS ----------
$action = $_GET['action'] ?? '';
if ($method === 'GET' && $action === 'metricas') {
    $hoje = date('Y-m-d');
    $mesAtual = date('Y-m');

    $ativas = $pdo->prepare("SELECT COUNT(*) FROM intus_mensalidade WHERE stpgto = 'S' AND dtvencimento >= ?");
    $ativas->execute([$hoje]);
    $numAtivas = (int)$ativas->fetchColumn();

    $vencidas = $pdo->prepare("SELECT COUNT(*) FROM intus_mensalidade WHERE (stpgto = 'N' OR dtvencimento < ?)");
    $vencidas->execute([$hoje]);
    $numVencidas = (int)$vencidas->fetchColumn() - $numAtivas;
    if ($numVencidas < 0) $numVencidas = 0;

    $receita = $pdo->prepare("SELECT COALESCE(SUM(vlpagamento),0) FROM intus_mensalidade WHERE stpgto = 'S' AND dtpagamento LIKE ?");
    $receita->execute([$mesAtual . '%']);
    $receitaMes = (float)$receita->fetchColumn();

    $popular = $pdo->query("SELECT dshistorico, COUNT(*) as cnt FROM intus_mensalidade WHERE dshistorico != '' GROUP BY dshistorico ORDER BY cnt DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ativas' => $numAtivas,
        'vencidas' => $numVencidas,
        'receita_mes' => $receitaMes,
        'plano_popular' => $popular ? $popular['dshistorico'] : null,
    ]);
    exit;
}

// ---------- GET ----------
if ($method === 'GET') {
    $sql = "SELECT m.*";
    if ($atletaTable) {
        $idC = idCol($atletaTable);
        $nmC = nomeCol($atletaTable);
        $sql .= ", a.`$nmC` AS nmathleta";
        $sql .= " FROM intus_mensalidade m LEFT JOIN `$atletaTable` a ON a.`$idC` = m.idatleta";
    } else {
        $sql .= " FROM intus_mensalidade m";
    }

    $where = [];
    $params = [];

    if ($id) {
        $where[] = "m.idmensalidade = ?";
        $params[] = $id;
    }
    if ($atleta) {
        $where[] = "m.idatleta = ?";
        $params[] = $atleta;
    }
    if ($status === 'pago') {
        $where[] = "m.stpgto = 'S'";
    } elseif ($status === 'pendente') {
        $where[] = "m.stpgto != 'S'";
    }

    // Filtro por professor: não-admin vê apenas mensalidades dos seus atletas
    if ($_allowedIds !== null) {
        if (count($_allowedIds) === 0) {
            $where[] = "1=0";
        } else {
            $ph = implode(',', array_fill(0, count($_allowedIds), '?'));
            $where[] = "m.idatleta IN ($ph)";
            $params = array_merge($params, $_allowedIds);
        }
    }

    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY m.dtvencimento DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Para professores não-admin: marcar alunos_intus como somente leitura (sem edição financeira)
    if (!$_isAdmin && $_protectedIds) {
        foreach ($rows as &$row) {
            if (in_array((int)$row['idatleta'], $_protectedIds)) {
                $row['_somente_leitura'] = true;
            }
        }
        unset($row);
    }

    if ($id) {
        echo json_encode($rows ? $rows[0] : null);
    } else {
        echo json_encode($rows);
    }
    exit;
}

// ---------- POST ----------
if ($method === 'POST') {
    $d = jsonBody();
    if (empty($d['idatleta']) || empty($d['dtvencimento'])) {
        http_response_code(400);
        echo json_encode(['error' => 'idatleta e dtvencimento obrigatórios']);
        exit;
    }
    // Bloquear criação por colaborador em atleta protegido
    if (!$_isAdmin && in_array((int)$d['idatleta'], $_protectedIds)) {
        http_response_code(403);
        echo json_encode(['error' => 'sem permissão para gerenciar financeiro deste aluno']);
        exit;
    }
    $stmt = $pdo->prepare("INSERT INTO intus_mensalidade
        (idatleta, dshistorico, dtinicio, dtvencimento, vlpagar, vldesconto, vljuro, stpgto, dtpagamento, vlpagamento, dspagamento, professor,
         tipo_plano, stmatricula, idmatricula_origem, parcela_num, parcela_total, forma_pgto, recorrencia)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        intval($d['idatleta']),
        $d['dshistorico'] ?? 'Mensalidade',
        $d['dtinicio'] ?? null,
        $d['dtvencimento'],
        floatval($d['vlpagar'] ?? 0),
        floatval($d['vldesconto'] ?? 0),
        floatval($d['vljuro'] ?? 0),
        $d['stpgto'] ?? 'N',
        $d['dtpagamento'] ?? null,
        isset($d['vlpagamento']) ? floatval($d['vlpagamento']) : null,
        $d['dspagamento'] ?? null,
        $d['professor'] ?? null,
        $d['tipo_plano'] ?? null,
        $d['stmatricula'] ?? 'ativa',
        isset($d['idmatricula_origem']) ? intval($d['idmatricula_origem']) : null,
        isset($d['parcela_num']) ? intval($d['parcela_num']) : null,
        isset($d['parcela_total']) ? intval($d['parcela_total']) : null,
        $d['forma_pgto'] ?? null,
        $d['recorrencia'] ?? null,
    ]);

    $novoId = $pdo->lastInsertId();
    auditLog($pdo, $tok, 'criar', 'mensalidade', (int)$novoId, 'Criou mensalidade p/ atleta #' . intval($d['idatleta']), null, $d);
    echo json_encode(['idmensalidade' => intval($novoId), 'ok' => true]);
    exit;
}

// ---------- PUT ----------
if ($method === 'PUT') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id obrigatório']);
        exit;
    }
    $stBefore = $pdo->prepare("SELECT * FROM intus_mensalidade WHERE idmensalidade = ? LIMIT 1");
    $stBefore->execute([$id]);
    $rowBefore = $stBefore->fetch(PDO::FETCH_ASSOC);

    if (!$_isAdmin && $rowBefore && in_array((int)$rowBefore['idatleta'], $_protectedIds)) {
        http_response_code(403);
        echo json_encode(['error' => 'sem permissão para gerenciar financeiro deste aluno']);
        exit;
    }

    $d = jsonBody();
    $allowed = ['dshistorico','dtinicio','dtvencimento','vlpagar','vldesconto','vljuro','stpgto','dtpagamento','vlpagamento','dspagamento','professor',
        'tipo_plano','stmatricula','dt_tranca_inicio','dt_tranca_fim','dias_trancado','parcela_num','parcela_total','forma_pgto','idmatricula_origem','motivo_encerramento','recorrencia'];
    $sets = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $d)) {
            $sets[] = "`$col` = ?";
            $params[] = $d[$col];
        }
    }
    if (empty($sets)) {
        http_response_code(400);
        echo json_encode(['error' => 'nenhum campo para atualizar']);
        exit;
    }
    $params[] = $id;

    $sql = "UPDATE intus_mensalidade SET " . implode(', ', $sets) . " WHERE idmensalidade = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    auditLog($pdo, $tok, 'editar', 'mensalidade', $id, 'Editou mensalidade #' . $id, $rowBefore, $d);
    echo json_encode(['ok' => true, 'affected' => $stmt->rowCount()]);
    exit;
}

// ---------- DELETE ----------
if ($method === 'DELETE') {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'id obrigatório']);
        exit;
    }

    $stDel = $pdo->prepare("SELECT * FROM intus_mensalidade WHERE idmensalidade = ? LIMIT 1");
    $stDel->execute([$id]);
    $rowDel = $stDel->fetch(PDO::FETCH_ASSOC);

    if (!$_isAdmin && $rowDel && in_array((int)$rowDel['idatleta'], $_protectedIds)) {
        http_response_code(403);
        echo json_encode(['error' => 'sem permissão para gerenciar financeiro deste aluno']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM intus_mensalidade WHERE idmensalidade = ?");
    $stmt->execute([$id]);
    auditLog($pdo, $tok, 'excluir', 'mensalidade', $id, 'Excluiu mensalidade #' . $id, $rowDel, null);
    echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'método não suportado']);
