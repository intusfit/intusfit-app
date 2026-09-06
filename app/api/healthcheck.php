<?php
/**
 * Intus Fit — Health Check Autônomo (roda via cron no servidor)
 *
 * Executa: dedup de exercícios, verifica integridade, loga resultado.
 * Endpoint: GET /api/healthcheck.php
 * Cron sugerido: 0 8 * * * (diário às 08:00)
 */

header('Content-Type: application/json; charset=utf-8');

// Config DB (mesma do sistema)
$cfg = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new Exception('sem credenciais');
    }
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed']);
    exit;
}

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

// ── ESTE ARQUIVO APAGAVA DADOS A CADA VISITA, SEM SENHA ────────────────────
// Ele nao exigia autenticacao nenhuma e, em toda requisicao, rodava dois DELETE
// no banco: um removendo exercicios com nome repetido e outro removendo treinos
// sem ficha. Ou seja, qualquer pessoa que abrisse o endereco disparava uma
// limpeza destrutiva na base de producao — e, em laco, tambem uma negacao de
// servico, porque o self-join sem indice trava a tabela.
// Alem disso devolvia a contagem de exercicios, fichas, treinos e atletas (o
// tamanho real da base de clientes) e, em falha de conexao, o host e o nome do
// banco na mensagem de erro.
$_sessHc = _intusExigeSessao($pdo, true, true);


$log = ['timestamp' => date('c'), 'actions' => []];

// 1. DEDUP EXERCÍCIOS — remove duplicatas por nome (mantém o de menor ID)
try {
    $before = (int)$pdo->query("SELECT COUNT(*) FROM intus_exercicio")->fetchColumn();
    $pdo->exec("
        DELETE e1 FROM intus_exercicio e1
        INNER JOIN intus_exercicio e2
        ON LOWER(TRIM(e1.nmexercicio)) = LOWER(TRIM(e2.nmexercicio))
        AND e1.idexercicio > e2.idexercicio
    ");
    $after = (int)$pdo->query("SELECT COUNT(*) FROM intus_exercicio")->fetchColumn();
    $removed = $before - $after;
    $log['actions'][] = ['check' => 'dedup_exercicios', 'removed' => $removed, 'remaining' => $after];
} catch (Throwable $e) {
    $log['actions'][] = ['check' => 'dedup_exercicios', 'error' => $e->getMessage()];
}

// 2. FICHAS ÓRFÃS — fichas cujo atleta não existe mais
try {
    $tblAtleta = null;
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tblAtleta = $t; break; } catch (Throwable $e) {}
    }
    if ($tblAtleta) {
        $orphan = (int)$pdo->query("SELECT COUNT(*) FROM intus_ficha f LEFT JOIN `$tblAtleta` a ON f.idatleta = a.idatleta WHERE a.idatleta IS NULL")->fetchColumn();
        $log['actions'][] = ['check' => 'fichas_orfas', 'count' => $orphan];
    }
} catch (Throwable $e) {
    $log['actions'][] = ['check' => 'fichas_orfas', 'error' => $e->getMessage()];
}

// 3. TREINOS ÓRFÃOS — treinos cujo ficha não existe
try {
    $tOrphan = (int)$pdo->query("SELECT COUNT(*) FROM intus_treino t LEFT JOIN intus_ficha f ON t.idficha = f.idficha WHERE f.idficha IS NULL")->fetchColumn();
    if ($tOrphan > 0) {
        // Auto-limpa treinos órfãos
        $pdo->exec("DELETE t FROM intus_treino t LEFT JOIN intus_ficha f ON t.idficha = f.idficha WHERE f.idficha IS NULL");
    }
    $log['actions'][] = ['check' => 'treinos_orfaos', 'removed' => $tOrphan];
} catch (Throwable $e) {
    $log['actions'][] = ['check' => 'treinos_orfaos', 'error' => $e->getMessage()];
}

// 4. CONTAGENS GERAIS
try {
    $log['totals'] = [
        'exercicios' => (int)$pdo->query("SELECT COUNT(*) FROM intus_exercicio")->fetchColumn(),
        'fichas' => (int)$pdo->query("SELECT COUNT(*) FROM intus_ficha")->fetchColumn(),
        'treinos' => (int)$pdo->query("SELECT COUNT(*) FROM intus_treino")->fetchColumn(),
    ];
    if ($tblAtleta) {
        $log['totals']['atletas'] = (int)$pdo->query("SELECT COUNT(*) FROM `$tblAtleta`")->fetchColumn();
    }
} catch (Throwable $e) {
    $log['totals'] = ['error' => $e->getMessage()];
}

// 5. LOG em arquivo (para histórico)
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/healthcheck-' . date('Y-m') . '.log';
$line = date('Y-m-d H:i:s') . ' | ' . json_encode($log, JSON_UNESCAPED_UNICODE) . "\n";
@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

$hasIssues = false;
foreach ($log['actions'] as $a) {
    if (isset($a['error'])) $hasIssues = true;
}
$log['status'] = $hasIssues ? 'issues_found' : 'ok';

echo json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
