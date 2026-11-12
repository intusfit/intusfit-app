<?php
/**
 * Intus Fit — Health Check (SOMENTE LEITURA)
 *
 * Só administrador (sessão real em intus_sessions). Conta e relata; NUNCA altera o banco:
 *   - exercícios com nome repetido (grupos e linhas excedentes), fichas sem aluno e treinos sem ficha: só CONTADOS;
 *   - totais de exercícios, fichas, treinos e alunos.
 * Endpoint: GET /app/api/healthcheck.php  (Authorization: Bearer <token de administrador>)
 *
 * HISTÓRICO (11/11/2026): este arquivo apagava dados a cada chamada de administrador (um DELETE removia exercícios de nome
 * repetido e outro, treinos sem ficha) e, até 20/08/2026, a de qualquer visitante, sem senha. Nome igual não prova duplicata
 * (existem homônimos reais) e treino sem ficha pode ser sintoma de outro defeito que a limpeza escondia. Agora ele só lê.
 * Se alguma limpeza ainda for desejada, tem de ser ação explícita no painel, com prévia do que sai e confirmação (regra do
 * projeto: exclusão de dado só pelo painel). testes/healthcheck.php garante que nenhuma tabela muda.
 * "actions" é o nome histórico da lista de verificações (mantido para não quebrar quem lê a resposta).
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

// Só administrador. (Até 20/08/2026 este arquivo não exigia autenticação nenhuma e devolvia a contagem real da base de clientes.)
$_sessHc = _intusExigeSessao($pdo, true, true);


$log = ['timestamp' => date('c'), 'modo' => 'somente_leitura', 'actions' => []];
$problemas = false;

// 1. EXERCÍCIOS DUPLICADOS — só relata (nome igual sem diferença de caixa e espaços). Nada é apagado.
try {
    $dup = $pdo->query("SELECT LOWER(TRIM(nmexercicio)) AS nm, COUNT(*) AS c FROM intus_exercicio
                        WHERE nmexercicio IS NOT NULL AND TRIM(nmexercicio) <> ''
                        GROUP BY LOWER(TRIM(nmexercicio)) HAVING COUNT(*) > 1 ORDER BY c DESC, nm")->fetchAll(PDO::FETCH_ASSOC);
    $excedentes = 0;
    foreach ($dup as $d) $excedentes += (int)$d['c'] - 1;
    $amostra = [];
    foreach (array_slice($dup, 0, 10) as $d) $amostra[] = ['nome' => (string)$d['nm'], 'copias' => (int)$d['c']];
    $log['actions'][] = ['check' => 'exercicios_duplicados', 'grupos' => count($dup), 'excedentes' => $excedentes, 'amostra' => $amostra];
    if (count($dup) > 0) $problemas = true;
} catch (Throwable $e) {
    $log['actions'][] = ['check' => 'exercicios_duplicados', 'error' => $e->getMessage()];
}

// 2. FICHAS ÓRFÃS — fichas cujo atleta não existe mais (só conta)
$tblAtleta = null;
try {
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tblAtleta = $t; break; } catch (Throwable $e) {}
    }
    if ($tblAtleta) {
        $orphan = (int)$pdo->query("SELECT COUNT(*) FROM intus_ficha f LEFT JOIN `$tblAtleta` a ON f.idatleta = a.idatleta WHERE a.idatleta IS NULL")->fetchColumn();
        $log['actions'][] = ['check' => 'fichas_orfas', 'count' => $orphan];
        if ($orphan > 0) $problemas = true;
    }
} catch (Throwable $e) {
    $log['actions'][] = ['check' => 'fichas_orfas', 'error' => $e->getMessage()];
}

// 3. TREINOS ÓRFÃOS — treinos cuja ficha não existe (só conta; antes o arquivo os apagava)
try {
    $tOrphan = (int)$pdo->query("SELECT COUNT(*) FROM intus_treino t LEFT JOIN intus_ficha f ON t.idficha = f.idficha WHERE f.idficha IS NULL")->fetchColumn();
    $log['actions'][] = ['check' => 'treinos_orfaos', 'count' => $tOrphan];
    if ($tOrphan > 0) $problemas = true;
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
    $problemas = true;
}

foreach ($log['actions'] as $a) {
    if (isset($a['error'])) $problemas = true;
}
$log['status'] = $problemas ? 'issues_found' : 'ok';

// 5. LOG em arquivo (para histórico; só contagens, nada de dado de aluno)
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/healthcheck-' . date('Y-m') . '.log';
$line = date('Y-m-d H:i:s') . ' | ' . json_encode($log, JSON_UNESCAPED_UNICODE) . "\n";
@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

echo json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
