<?php
/**
 * Audit trail — registra alterações feitas por usuários no sistema.
 * Retém 7 dias de histórico. Ação de desfazer disponível apenas para admin.
 *
 * Rotas:
 *   GET    /api/audit.php                     -> lista histórico (7 dias)
 *   POST   /api/audit.php                     -> registrar evento { usuario, acao, entidade, entidade_id, dados_antes, dados_depois }
 *   POST   /api/audit.php?action=desfazer&id=X -> desfazer alteração (somente admin)
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function bearerToken() {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}
$tok = bearerToken();
if ($tok === '') { http_response_code(401); echo json_encode(['error' => 'token ausente']); exit; }

$cfg = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error' => 'falha conexao']); exit;
}

// Auto-create table
$pdo->exec("CREATE TABLE IF NOT EXISTS intus_audit (
    idaudit       INT AUTO_INCREMENT PRIMARY KEY,
    idusuario     INT NULL,
    nmusuario     VARCHAR(100) NULL,
    acao          VARCHAR(50) NOT NULL,
    entidade      VARCHAR(50) NOT NULL,
    entidade_id   INT NULL,
    descricao     VARCHAR(300) NULL,
    dados_antes   LONGTEXT NULL,
    dados_depois  LONGTEXT NULL,
    desfeito      TINYINT(1) DEFAULT 0,
    desfeito_por  INT NULL,
    dt_desfeito   DATETIME NULL,
    dtcriacao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dt (dtcriacao),
    INDEX idx_entidade (entidade, entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Purge entries older than 7 days
$pdo->exec("DELETE FROM intus_audit WHERE dtcriacao < DATE_SUB(NOW(), INTERVAL 7 DAY)");

// ── QUEM E O DONO DESTE TOKEN ───────────────────────────────────────────────
// ATE 20/08/2026 ESTA FUNCAO ERA UM BYPASS COMPLETO DE AUTENTICACAO.
// Ela aceitava duas coisas que qualquer pessoa consegue escrever:
//   1. "prof-1-qualquercoisa" — o formato ERA a credencial. Sem senha, sem
//      conferencia em banco, sem validade. Medido no ar em 20/08: uma chamada
//      com "Bearer prof-1-1" respondeu 200 com admin:true e a trilha inteira.
//   2. Um JWT com a assinatura IGNORADA — bastava montar o miolo com {"id":1}.
// Como este arquivo tem a rota de DESFAZER, que escreve na tabela indicada pelo
// proprio registro de auditoria, o encadeamento dava tomada de conta: registrar
// um evento falso com uma senha nova para o usuario 1 e mandar desfazer.
// O resto do sistema ja tinha fechado o formato antigo; este arquivo tinha a
// propria porta, e ela ficou aberta.
// Agora a identidade vem da sessao real gravada em intus_sessions, e de mais
// lugar nenhum.
function isAdmin(PDO $pdo, string $tok): array {
    $idusuario = 0;
    $admin = false;
    $nmusuario = '';

    require_once __DIR__ . '/_sessions.php';
    if (function_exists('validateSession')) {
        $sess = validateSession($pdo, $tok);
        if ($sess && empty($sess['legacy']) && ($sess['user_type'] ?? '') !== 'aluno') {
            $idusuario = (int)($sess['user_id'] ?? 0);
        }
    }
    if ($idusuario <= 0) {
        return ['idusuario' => 0, 'admin' => false, 'nmusuario' => ''];
    }

    if ($idusuario > 0) {
        $userTable = null;
        foreach (['professor', 'usuario', 'usuarios', 'professores'] as $t) {
            try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $userTable = $t; break; } catch (Throwable $e) {}
        }
        if ($userTable) {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM `$userTable`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $colId = null;
            foreach (['idusuario', 'idprofessor', 'id'] as $c) { if (in_array($c, $cols)) { $colId = $c; break; } }
            $colAdmin = null;
            foreach (['admin', 'tpacesso', 'tipo', 'is_admin'] as $c) { if (in_array($c, $cols)) { $colAdmin = $c; break; } }
            $colNome = null;
            foreach (['nome', 'nmusuario', 'nmprofessor', 'name'] as $c) { if (in_array($c, $cols)) { $colNome = $c; break; } }

            if ($colId) {
                $st = $pdo->prepare("SELECT * FROM `$userTable` WHERE `$colId` = ? LIMIT 1");
                $st->execute([$idusuario]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if ($colNome) $nmusuario = $row[$colNome] ?? '';
                    if ($colAdmin) {
                        $val = $row[$colAdmin] ?? null;
                        if ($colAdmin === 'tpacesso') $admin = ($val === 'A');
                        elseif ($colAdmin === 'is_admin') $admin = ((int)$val === 1);
                        else $admin = ($val === 'S' || $val === '1' || $val === 1);
                    }
                }
            }
        }
    }
    return ['idusuario' => $idusuario, 'admin' => $admin, 'nmusuario' => $nmusuario];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$auth = isAdmin($pdo, $tok);

// Sessao de professor valida e mais nada. A trilha de auditoria guarda os
// valores ANTERIORES de cada linha editada — e-mail, telefone, valor de
// mensalidade e o que mais for registrado. Nao e material para "qualquer token".
if ((int)($auth['idusuario'] ?? 0) <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'token invalido']);
    exit;
}

// ===== GET — listar histórico (7 dias) =====
if ($method === 'GET') {
    if (empty($auth['admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'restrito ao administrador']);
        exit;
    }
    $st = $pdo->query("SELECT * FROM intus_audit ORDER BY dtcriacao DESC LIMIT 200");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = array_map(function($r) {
        return [
            'idaudit'      => (int)$r['idaudit'],
            'idusuario'    => $r['idusuario'] ? (int)$r['idusuario'] : null,
            'nmusuario'    => $r['nmusuario'] ?? '',
            'acao'         => $r['acao'],
            'entidade'     => $r['entidade'],
            'entidade_id'  => $r['entidade_id'] ? (int)$r['entidade_id'] : null,
            'descricao'    => $r['descricao'] ?? '',
            'dados_antes'  => $r['dados_antes'] ? json_decode($r['dados_antes'], true) : null,
            'dados_depois' => $r['dados_depois'] ? json_decode($r['dados_depois'], true) : null,
            'desfeito'     => (bool)$r['desfeito'],
            'desfeito_por' => $r['desfeito_por'] ? (int)$r['desfeito_por'] : null,
            'dt_desfeito'  => $r['dt_desfeito'],
            'dtcriacao'    => $r['dtcriacao'],
        ];
    }, $rows);
    echo json_encode(['admin' => $auth['admin'], 'historico' => $out]);
    exit;
}

// ===== POST — registrar ou desfazer =====
if ($method === 'POST') {
    // Desfazer ação
    if ($action === 'desfazer') {
        if (!$auth['admin']) {
            http_response_code(403);
            echo json_encode(['error' => 'apenas admin pode desfazer']);
            exit;
        }
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }

        $st = $pdo->prepare("SELECT * FROM intus_audit WHERE idaudit = ? LIMIT 1");
        $st->execute([$id]);
        $audit = $st->fetch(PDO::FETCH_ASSOC);
        if (!$audit) { http_response_code(404); echo json_encode(['error' => 'registro nao encontrado']); exit; }
        if ($audit['desfeito']) { echo json_encode(['ok' => true, 'msg' => 'ja desfeito']); exit; }

        // ── SO ESTAS TABELAS PODEM SER TOCADAS PELO DESFAZER ────────────────
        // O nome da tabela vinha do registro de auditoria, que qualquer um
        // conseguia criar. Com o mapa aceitando qualquer nome como fallback,
        // "desfazer" virava um UPDATE em tabela arbitraria — inclusive na de
        // usuarios, gravando a senha que o atacante escolhesse.
        $_ENTIDADES_OK = ['atleta','atletas','aluno','alunos','mensalidade','intus_mensalidade',
                          'ficha','intus_ficha','treino','intus_treino','exercicio','intus_exercicio'];
        $entidade = $audit['entidade'];
        if (!in_array($entidade, $_ENTIDADES_OK, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'entidade nao permitida para desfazer']);
            exit;
        }
        $entidade_id = (int)$audit['entidade_id'];
        $acao = $audit['acao'];
        $dados_antes = $audit['dados_antes'] ? json_decode($audit['dados_antes'], true) : null;

        $sucesso = false;
        $motivo = '';

        // Attempt undo based on entity and action
        if ($dados_antes && $entidade_id > 0) {
            $tabMap = [
                'atleta' => ['atleta', 'atletas', 'aluno', 'alunos'],
                'mensalidade' => ['intus_mensalidade'],
                'ficha' => ['intus_ficha'],
                'treino' => ['intus_treino'],
            ];
            $candidates = $tabMap[$entidade] ?? [$entidade];
            $targetTable = null;
            foreach ($candidates as $t) {
                try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $targetTable = $t; break; } catch (Throwable $e) {}
            }

            if ($targetTable) {
                $cols = array_column($pdo->query("SHOW COLUMNS FROM `$targetTable`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                $idCol = null;
                $idCandidates = ['id' . $entidade, 'id', 'idatleta', 'idaluno', 'idmensalidade', 'idficha', 'idtreino'];
                foreach ($idCandidates as $c) { if (in_array($c, $cols)) { $idCol = $c; break; } }

                if ($idCol) {
                    if ($acao === 'excluir') {
                        // Re-insert deleted row
                        $insertCols = [];
                        $insertVals = [];
                        foreach ($dados_antes as $k => $v) {
                            if (in_array($k, $cols)) {
                                $insertCols[] = "`$k`";
                                $insertVals[] = $v;
                            }
                        }
                        if ($insertCols) {
                            $ph = implode(',', array_fill(0, count($insertCols), '?'));
                            $sql = "INSERT INTO `$targetTable` (" . implode(',', $insertCols) . ") VALUES ($ph)";
                            try {
                                $pdo->prepare($sql)->execute($insertVals);
                                $sucesso = true;
                            } catch (Throwable $e) { $motivo = $e->getMessage(); }
                        }
                    } elseif ($acao === 'editar' || $acao === 'bloquear') {
                        // Restore previous values
                        $sets = [];
                        $vals = [];
                        foreach ($dados_antes as $k => $v) {
                            if (in_array($k, $cols) && $k !== $idCol) {
                                $sets[] = "`$k` = ?";
                                $vals[] = $v;
                            }
                        }
                        if ($sets) {
                            $vals[] = $entidade_id;
                            $sql = "UPDATE `$targetTable` SET " . implode(', ', $sets) . " WHERE `$idCol` = ?";
                            try {
                                $pdo->prepare($sql)->execute($vals);
                                $sucesso = true;
                            } catch (Throwable $e) { $motivo = $e->getMessage(); }
                        }
                    } elseif ($acao === 'criar') {
                        // Delete the created record
                        try {
                            $pdo->prepare("DELETE FROM `$targetTable` WHERE `$idCol` = ?")->execute([$entidade_id]);
                            $sucesso = true;
                        } catch (Throwable $e) { $motivo = $e->getMessage(); }
                    }
                } else {
                    $motivo = 'coluna id nao detectada';
                }
            } else {
                $motivo = 'tabela nao encontrada';
            }
        } else {
            $motivo = 'sem dados para restaurar';
        }

        if ($sucesso) {
            $pdo->prepare("UPDATE intus_audit SET desfeito = 1, desfeito_por = ?, dt_desfeito = NOW() WHERE idaudit = ?")
                ->execute([$auth['idusuario'], $id]);
            echo json_encode(['ok' => true, 'msg' => 'acao desfeita com sucesso']);
        } else {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'nao foi possivel desfazer', 'motivo' => $motivo]);
        }
        exit;
    }

    // Registrar novo evento
    $b = json_decode(file_get_contents('php://input'), true) ?: [];
    $acao = trim($b['acao'] ?? '');
    $entidade = trim($b['entidade'] ?? '');
    if (!$acao || !$entidade) { http_response_code(400); echo json_encode(['error' => 'acao e entidade obrigatorios']); exit; }

    $st = $pdo->prepare("INSERT INTO intus_audit (idusuario, nmusuario, acao, entidade, entidade_id, descricao, dados_antes, dados_depois) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    // O autor vem do token. Antes, quando a identificacao falhava, o autor era
    // lido do CORPO da requisicao — ou seja, dava para assinar um evento com o
    // nome de qualquer pessoa, e a trilha deixava de servir como prova.
    $st->execute([
        $auth['idusuario'],
        $auth['nmusuario'],
        $acao,
        $entidade,
        $b['entidade_id'] ?? null,
        $b['descricao'] ?? null,
        isset($b['dados_antes']) ? json_encode($b['dados_antes']) : null,
        isset($b['dados_depois']) ? json_encode($b['dados_depois']) : null,
    ]);
    echo json_encode(['ok' => true, 'idaudit' => (int)$pdo->lastInsertId()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'metodo nao suportado']);
