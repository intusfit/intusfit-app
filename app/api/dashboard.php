<?php
/**
 * Dashboard agregado: contadores + listas de mensalidades vencidas e
 * alunos sem ficha ativa. Usa as tabelas reais (atletas / mensalidades /
 * intus_ficha) — auto-graceful: se alguma tabela nao existir, devolve
 * 0 / lista vazia em vez de quebrar.
 *
 * GET /api/dashboard.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
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

// A rota de configuracao rodava e dava exit ANTES do contexto de autenticacao
// ser carregado — bastava um token nao-vazio para ler os dados da empresa e,
// por POST, sobrescrever a configuracao inteira do sistema, sem merge e sem log.
$_sessDash = _intusExigeSessao($pdo, true);

function tableExists(PDO $pdo, $t) {
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}
function colExists(PDO $pdo, $tab, $col) {
    try {
        $r = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $r->execute([$tab, $col]);
        return (int)$r->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

// ═══════════════ CONFIG GERAL ═══════════════
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'config') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_config (
        chave VARCHAR(100) PRIMARY KEY,
        valor LONGTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'geral'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $config = $row ? json_decode($row['valor'], true) : null;
        echo json_encode(['config' => $config]);
        exit;
    }
    if ($method === 'POST' || $method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) { http_response_code(400); echo json_encode(['error' => 'body vazio']); exit; }
        $json = json_encode($body);
        $st = $pdo->prepare("SELECT 1 FROM intus_config WHERE chave = 'geral'");
        $st->execute();
        if ($st->fetch()) {
            $pdo->prepare("UPDATE intus_config SET valor = ? WHERE chave = 'geral'")->execute([$json]);
        } else {
            $pdo->prepare("INSERT INTO intus_config (chave, valor) VALUES ('geral', ?)")->execute([$json]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

// Auth Context (filtro por professor)
require_once __DIR__ . '/_auth_context.php';
$_authCtx = getAuthContext($pdo, $tok);
$_isAdmin = $_authCtx['admin'];
$_userId  = $_authCtx['idusuario'];
$_allowedIds = null; // null = sem filtro (admin)
if (!$_isAdmin && $_userId > 0 && !$_authCtx['is_aluno']) {
    $_allowedIds = getAtletasDoUsuario($pdo, $_userId);
}

// Localizar tabelas
$tab_atleta = null;
foreach (['atleta','atletas','aluno','alunos'] as $t) if (tableExists($pdo, $t)) { $tab_atleta = $t; break; }
$tab_mens   = null;
foreach (['mensalidade','mensalidades'] as $t) if (tableExists($pdo, $t)) { $tab_mens = $t; break; }
$tab_msg    = null;
foreach (['mensagem','mensagens','intus_mensagem'] as $t) if (tableExists($pdo, $t)) { $tab_msg = $t; break; }
$tem_ficha  = tableExists($pdo, 'intus_ficha');

$resp = [
    'resumo' => [
        'total_atletas'   => 0,
        'atletas_ativos'  => 0,
        'mens_vencidas'   => 0,
        'msgs_nao_lidas'  => 0,
    ],
    'treinos_inativos'      => [],
    'mensalidades_vencidas' => [],
];

try {
    // Atletas
    if ($tab_atleta) {
        if ($_allowedIds === null) {
            $resp['resumo']['total_atletas'] = (int)$pdo->query("SELECT COUNT(*) FROM `$tab_atleta`")->fetchColumn();
            if (colExists($pdo, $tab_atleta, 'stbloqueio')) {
                $resp['resumo']['atletas_ativos'] = (int)$pdo->query("SELECT COUNT(*) FROM `$tab_atleta` WHERE stbloqueio <> 'S' OR stbloqueio IS NULL")->fetchColumn();
            } else {
                $resp['resumo']['atletas_ativos'] = $resp['resumo']['total_atletas'];
            }
        } else {
            $resp['resumo']['total_atletas'] = count($_allowedIds);
            if (count($_allowedIds) > 0 && colExists($pdo, $tab_atleta, 'stbloqueio')) {
                $ph = implode(',', array_fill(0, count($_allowedIds), '?'));
                $st = $pdo->prepare("SELECT COUNT(*) FROM `$tab_atleta` WHERE idatleta IN ($ph) AND (stbloqueio <> 'S' OR stbloqueio IS NULL)");
                $st->execute($_allowedIds);
                $resp['resumo']['atletas_ativos'] = (int)$st->fetchColumn();
            } else {
                $resp['resumo']['atletas_ativos'] = count($_allowedIds);
            }
        }
    }

    // Mensalidades vencidas
    if ($tab_mens && colExists($pdo, $tab_mens, 'stpgto') && colExists($pdo, $tab_mens, 'dtvencimento')) {
        $hoje = date('Y-m-d');
        $rows = [];
        if ($_allowedIds === null) {
            $sqlM = "SELECT * FROM `$tab_mens` WHERE (stpgto <> 'S' OR stpgto IS NULL) AND dtvencimento < ? ORDER BY dtvencimento ASC LIMIT 50";
            $st = $pdo->prepare($sqlM);
            $st->execute([$hoje]);
        } elseif (count($_allowedIds) > 0 && colExists($pdo, $tab_mens, 'idatleta')) {
            $ph = implode(',', array_fill(0, count($_allowedIds), '?'));
            $sqlM = "SELECT * FROM `$tab_mens` WHERE idatleta IN ($ph) AND (stpgto <> 'S' OR stpgto IS NULL) AND dtvencimento < ? ORDER BY dtvencimento ASC LIMIT 50";
            $st = $pdo->prepare($sqlM);
            $st->execute(array_merge($_allowedIds, [$hoje]));
        } else {
            $st = null;
        }
        if ($st) $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $resp['resumo']['mens_vencidas'] = count($rows);

        // Resolver nome do atleta se houver coluna idatleta
        $temAtletaFK = colExists($pdo, $tab_mens, 'idatleta') && $tab_atleta;
        $col_nome = null;
        if ($tab_atleta) {
            foreach (['nome','nmatleta','nmaluno'] as $c) if (colExists($pdo, $tab_atleta, $c)) { $col_nome = $c; break; }
        }
        $cache = [];
        $venc = [];
        foreach ($rows as $m) {
            $nome = $m['nmathleta'] ?? $m['nmatleta'] ?? '';
            if (!$nome && $temAtletaFK && $col_nome && !empty($m['idatleta'])) {
                $idat = (int)$m['idatleta'];
                if (!isset($cache[$idat])) {
                    $st2 = $pdo->prepare("SELECT `$col_nome` FROM `$tab_atleta` WHERE idatleta = ? LIMIT 1");
                    $st2->execute([$idat]);
                    $cache[$idat] = $st2->fetchColumn() ?: '—';
                }
                $nome = $cache[$idat];
            }
            $venc[] = [
                'nome'         => $nome ?: '—',
                'dtvencimento' => $m['dtvencimento'] ?? null,
                'vlpagar'      => isset($m['vlpagar']) ? (float)$m['vlpagar'] : 0,
                'professor'    => '',
            ];
        }
        $resp['mensalidades_vencidas'] = $venc;
    }

    // Treinos inativos: atletas ativos que NAO tem ficha
    if ($tab_atleta && $tem_ficha) {
        $col_nome = null;
        foreach (['nome','nmatleta','nmaluno'] as $c) if (colExists($pdo, $tab_atleta, $c)) { $col_nome = $c; break; }
        $bloq = colExists($pdo, $tab_atleta, 'stbloqueio') ? "AND (a.stbloqueio <> 'S' OR a.stbloqueio IS NULL)" : '';
        $filtroIds = '';
        if ($_allowedIds !== null) {
            if (count($_allowedIds) === 0) { $filtroIds = 'AND 1=0'; }
            else { $filtroIds = 'AND a.idatleta IN (' . implode(',', array_map('intval', $_allowedIds)) . ')'; }
        }
        $sql = "SELECT a.idatleta, a.`$col_nome` AS nome
                FROM `$tab_atleta` a
                LEFT JOIN intus_ficha f ON f.idatleta = a.idatleta
                WHERE f.idficha IS NULL $bloq $filtroIds
                LIMIT 10";
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            $resp['treinos_inativos'] = array_map(function($r){ return ['nome' => $r['nome'] ?? '—', 'dt' => '—']; }, $rows);
        } catch (Throwable $e) { /* ignore */ }
    }

    // Msgs nao lidas
    if ($tab_msg && colExists($pdo, $tab_msg, 'stlido')) {
        try {
            $resp['resumo']['msgs_nao_lidas'] = (int)$pdo->query("SELECT COUNT(*) FROM `$tab_msg` WHERE stlido = 'N'")->fetchColumn();
        } catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    $resp['_warn'] = 'falha ao carregar parte dos dados';
}

echo json_encode($resp);

// ── BACKUP DE CARONA ───────────────────────────────────────────────────────
// Esta linha é tudo que liga o backup diário. Ela não custa nada nas 99% das
// vezes: o _carona_backup.php lê a data do último backup, vê que já rodou hoje
// e devolve na mesma hora. No primeiro acesso do dia ele espera a resposta
// acima chegar ao professor e só então executa a cópia, com a conexão já
// encerrada. Quem está do outro lado não percebe: a tela dele já carregou.
//
// O limite honesto do método: depende de alguém abrir o painel. Cinco dias sem
// ninguém entrar são cinco dias sem cópia nova. Para rodar sozinho no horário,
// aí sim é o CronJob pago do painel da KingHost.
//
// Precisa vir DEPOIS do echo: o include encerra a conexão com o navegador.
@include __DIR__ . '/_carona_backup.php';
