<?php
// ── LIMITE PARA IMAGEM EM BASE64 ───────────────────────────────────────────
// Estes campos gravam a imagem inteira dentro do banco, e nao tinham teto nem
// conferencia de formato. Alguns megabytes por chamada, em laco, enchem a
// tabela e derrubam o banco por espaco. O projeto ja valida MIME e tamanho no
// upload de midia; aqui a validacao nunca foi aplicada.
function _intusImagemOk($v, int $maxBytes = 3145728) {
    if ($v === null || $v === '') return null;
    if (!is_string($v)) return false;
    if (strlen($v) > $maxBytes) return false;
    // aceita data URI de imagem ou caminho/URL simples
    if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v)) return $v;
    if (preg_match('#^(https?://|/)[^\s"\'<>]{1,500}$#i', $v)) return $v;
    return false;
}

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
 * Endpoint unificado de treinos: fichas + exercicios da ficha.
 *
 * Auto-bootstrap: se as tabelas intus_ficha / intus_treino nao existirem,
 * sao criadas no primeiro hit. Auth via Bearer token (mesmo header dos
 * outros endpoints do painel) — aceita qualquer token nao vazio para
 * compatibilidade (a propria auth.php ja valida).
 *
 * Rotas:
 *   GET    /api/treinos.php?action=ficha&atleta=ID            -> ficha do aluno (1 por aluno, primeira ativa)
 *   GET    /api/treinos.php?action=fichas                     -> todas as fichas (lista)
 *   POST   /api/treinos.php?action=ficha   {idatleta, nmficha, dtinicio, dtfim, observacao}
 *   PUT    /api/treinos.php?action=ficha&id=ID  {nmficha, ...}
 *   DELETE /api/treinos.php?action=ficha&id=ID
 *
 *   GET    /api/treinos.php?action=treinos&ficha=ID           -> exercicios de uma ficha
 *   GET    /api/treinos.php?action=treinos                    -> TODOS os exercicios prescritos (cross-ficha)
 *   POST   /api/treinos.php?action=treinos {idficha, idexercicio, nmexercicio, divisao, qtdserie, qtdrepeticao, tempopausa, observacao, substitutos}
 *   PUT    /api/treinos.php?action=treinos&id=ID
 *   DELETE /api/treinos.php?action=treinos&id=ID
 *
 *   GET    /api/treinos.php?action=exercicios                 -> catalogo de exercicios (passa por exercicio.php se existir, senao retorna [])
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_charset_fix.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Auth minima ----------
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
    echo json_encode(['error' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Bootstrap tabelas ----------
function ensureTables(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_ficha (
            idficha      INT AUTO_INCREMENT PRIMARY KEY,
            idatleta     INT NOT NULL,
            nmficha      VARCHAR(120) NOT NULL DEFAULT 'Ficha',
            dtinicio     DATE NULL,
            dtfim        DATE NULL,
            observacao   TEXT NULL,
            ativo        TINYINT NOT NULL DEFAULT 1,
            alongamentos TEXT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Auto-add new columns for existing intus_ficha tables
    $fcols = array_column($pdo->query("SHOW COLUMNS FROM intus_ficha")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('ativo', $fcols)) $pdo->exec("ALTER TABLE intus_ficha ADD COLUMN ativo TINYINT NOT NULL DEFAULT 1 AFTER observacao");
    if (!in_array('alongamentos', $fcols)) $pdo->exec("ALTER TABLE intus_ficha ADD COLUMN alongamentos TEXT NULL AFTER ativo");
    if (!in_array('div_nomes', $fcols)) $pdo->exec("ALTER TABLE intus_ficha ADD COLUMN div_nomes TEXT NULL AFTER alongamentos");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_treino (
            idtreino     INT AUTO_INCREMENT PRIMARY KEY,
            idficha      INT NOT NULL,
            idexercicio  INT NOT NULL,
            nmexercicio  VARCHAR(160) NOT NULL DEFAULT '',
            divisao      VARCHAR(4)   NOT NULL DEFAULT 'A',
            qtdserie     INT NOT NULL DEFAULT 3,
            qtdrepeticao INT NOT NULL DEFAULT 12,
            repeticoes   VARCHAR(160) NULL,
            tempopausa   INT NOT NULL DEFAULT 60,
            intervalo    VARCHAR(120) NULL,
            tecnicas     VARCHAR(200) NULL,
            metodo       VARCHAR(120) NULL,
            observacao   TEXT NULL,
            substitutos  TEXT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ficha (idficha)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Auto-add new columns for existing tables
    $cols = array_column($pdo->query("SHOW COLUMNS FROM intus_treino")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('repeticoes', $cols)) $pdo->exec("ALTER TABLE intus_treino ADD COLUMN repeticoes VARCHAR(60) NULL AFTER qtdrepeticao");
    if (!in_array('intervalo', $cols))  $pdo->exec("ALTER TABLE intus_treino ADD COLUMN intervalo VARCHAR(20) NULL AFTER tempopausa");
    if (!in_array('metodo', $cols))     $pdo->exec("ALTER TABLE intus_treino ADD COLUMN metodo VARCHAR(120) NULL AFTER intervalo");
    if (!in_array('unilateral', $cols)) $pdo->exec("ALTER TABLE intus_treino ADD COLUMN unilateral TINYINT NOT NULL DEFAULT 0 AFTER substitutos");

    // ── PRESCRICAO POR SERIE (22/08/2026) ──────────────────────────────────
    // Repeticoes e intervalo viajam como lista separada por "/" quando o
    // professor diferencia as series: "12/10/8" e "01:30/01:00/00:45".
    // As larguras originais nao cabiam essa lista: intervalo tinha VARCHAR(20),
    // que estoura ja na quarta serie ("01:30/01:00/00:45/01:00" tem 23), e o
    // MySQL corta em silencio — a prescricao chegava truncada no celular do
    // aluno sem ninguem ver erro nenhum. Alargadas as duas.
    if (!in_array('tecnicas', $cols)) $pdo->exec("ALTER TABLE intus_treino ADD COLUMN tecnicas VARCHAR(200) NULL AFTER intervalo");
    try {
        $_larg = [];
        foreach ($pdo->query("SHOW COLUMNS FROM intus_treino")->fetchAll(PDO::FETCH_ASSOC) as $_c) {
            $_larg[$_c['Field']] = strtolower((string)$_c['Type']);
        }
        if (isset($_larg['intervalo']) && $_larg['intervalo'] !== 'varchar(120)') {
            $pdo->exec("ALTER TABLE intus_treino MODIFY COLUMN intervalo VARCHAR(120) NULL");
        }
        if (isset($_larg['repeticoes']) && $_larg['repeticoes'] !== 'varchar(160)') {
            $pdo->exec("ALTER TABLE intus_treino MODIFY COLUMN repeticoes VARCHAR(160) NULL");
        }
    } catch (Throwable $e) { /* sem permissao de ALTER: segue com o que existe */ }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_sessao (
            idsessao     INT AUTO_INCREMENT PRIMARY KEY,
            idatleta     INT NOT NULL,
            idficha      INT NULL,
            divisao      VARCHAR(4) NOT NULL DEFAULT 'A',
            dtsessao     DATE NOT NULL,
            duracao_seg  INT NOT NULL DEFAULT 0,
            comentario   TEXT NULL,
            marcado_como_feito TINYINT NOT NULL DEFAULT 0,
            itens        TEXT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta),
            INDEX idx_data (dtsessao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN nao_contar TINYINT NOT NULL DEFAULT 0 AFTER marcado_como_feito"); } catch (Throwable $e2) {}
    // tipo: 'musculacao' (default) | 'cardio'
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'musculacao' AFTER nao_contar"); } catch (Throwable $e2) {}
    // pontos calculados (musculacao=3 por sessão, cardio via METs)
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN pontos DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER tipo"); } catch (Throwable $e2) {}
    // cardio fields
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN cardio_tipo VARCHAR(60) NULL AFTER pontos"); } catch (Throwable $e2) {}
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN cardio_intensidade VARCHAR(20) NULL AFTER cardio_tipo"); } catch (Throwable $e2) {}
    // escala subjetiva de esforço (RPE 1-10) e foto de comprovação (esteira/relógio/print)
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN cardio_rpe TINYINT NULL AFTER cardio_intensidade"); } catch (Throwable $e2) {}
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN cardio_foto LONGTEXT NULL AFTER cardio_rpe"); } catch (Throwable $e2) {}
    // hora do registro (HH:MM, hora local do aluno) — para o professor conferir
    try { $pdo->exec("ALTER TABLE intus_sessao ADD COLUMN hora_conclusao VARCHAR(5) NULL AFTER cardio_foto"); } catch (Throwable $e2) {}
}
try { ensureTables($pdo); } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'falha bootstrap tabelas', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Helpers ----------
function jsonBody() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function fichaRowToOut($r) {
    $alongs = null;
    if (isset($r['alongamentos']) && $r['alongamentos'] !== null && $r['alongamentos'] !== '') {
        $tmp = json_decode($r['alongamentos'], true);
        if (is_array($tmp)) $alongs = $tmp;
    }
    $divNomes = null;
    if (isset($r['div_nomes']) && $r['div_nomes'] !== null && $r['div_nomes'] !== '') {
        $tmp = json_decode($r['div_nomes'], true);
        if (is_array($tmp)) $divNomes = (object)$tmp;
    }
    return [
        'idficha'      => (int)$r['idficha'],
        'idatleta'     => (int)$r['idatleta'],
        'nmficha'      => (string)$r['nmficha'],
        'dtinicio'     => $r['dtinicio'],
        'dtfim'        => $r['dtfim'],
        'observacao'   => (string)($r['observacao'] ?? ''),
        'ativo'        => (int)($r['ativo'] ?? 1),
        'alongamentos' => $alongs,
        'divNomes'     => $divNomes,
    ];
}
// Carrega mapa id->nome do catálogo uma vez por request
$_catNomeMap = null;
function getCatNomeMap(PDO $pdo): array {
    global $_catNomeMap;
    if ($_catNomeMap !== null) return $_catNomeMap;
    $_catNomeMap = [];
    try {
        $rows = $pdo->query("SELECT idexercicio, nmexercicio FROM intus_exercicio")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $_catNomeMap[(int)$row['idexercicio']] = $row['nmexercicio'];
    } catch (Throwable $e) {}
    return $_catNomeMap;
}
// Tecnicas por serie chegam do painel como array (["","","drop"]). Guardamos
// como lista separada por "/", igual as repeticoes e ao intervalo. Se nenhuma
// serie tem tecnica, grava NULL em vez de "//" — coluna limpa, consulta simples.
// Nomes fora da lista conhecida viram vazio: o banco nao guarda lixo vindo do
// cliente, e o app nunca recebe uma chave que nao sabe desenhar.
function tecnicasToCol($v) {
    if (is_string($v)) $v = ($v === '') ? [] : explode('/', $v);
    if (!is_array($v)) return null;
    $ok = ['drop','restpause','cluster','backoff','falha','isometria','negativa'];
    $out = [];
    foreach ($v as $x) {
        $x = strtolower(trim((string)$x));
        $out[] = in_array($x, $ok, true) ? $x : '';
    }
    while (count($out) && $out[count($out) - 1] === '') array_pop($out);
    if (!count($out)) return null;
    return substr(implode('/', $out), 0, 200);
}

// Converte array de substitutos (pode ter IDs inteiros ou nomes) para array de nomes
function subsToNomes(array $raw, PDO $pdo): array {
    $nomeMap = getCatNomeMap($pdo);
    $nomes = [];
    foreach ($raw as $v) {
        if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
            $id = (int)$v;
            if (isset($nomeMap[$id])) $nomes[] = $nomeMap[$id];
        } elseif (is_string($v) && trim($v) !== '') {
            $nomes[] = trim($v);
        }
    }
    return array_values(array_unique($nomes));
}
// Converte array de nomes para array de IDs (para salvar no DB)
function nomesToIds(array $raw, PDO $pdo): array {
    // Mapa invertido nome_norm -> id
    $nomeMap = getCatNomeMap($pdo);
    $normMap = [];
    foreach ($nomeMap as $id => $nm) $normMap[mb_strtolower(trim($nm), 'UTF-8')] = $id;
    $ids = [];
    foreach ($raw as $v) {
        if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
            $ids[] = (int)$v; // já é ID
        } elseif (is_string($v) && trim($v) !== '') {
            $key = mb_strtolower(trim($v), 'UTF-8');
            if (isset($normMap[$key])) $ids[] = $normMap[$key];
        }
    }
    return array_values(array_unique($ids));
}
function treinoRowToOut($r) {
    global $pdo;
    $subs = null;
    if (isset($r['substitutos']) && $r['substitutos'] !== null && $r['substitutos'] !== '') {
        $tmp = json_decode($r['substitutos'], true);
        if (is_array($tmp) && count($tmp) > 0) $subs = subsToNomes($tmp, $pdo);
    }
    return [
        'idtreino'     => (int)$r['idtreino'],
        'idficha'      => (int)$r['idficha'],
        'idexercicio'  => (int)$r['idexercicio'],
        'nmexercicio'  => (string)$r['nmexercicio'],
        'divisao'      => (string)$r['divisao'],
        'qtdserie'     => (int)$r['qtdserie'],
        'qtdrepeticao' => (int)$r['qtdrepeticao'],
        'repeticoes'   => (string)($r['repeticoes'] ?? ''),
        'tempopausa'   => (int)$r['tempopausa'],
        'intervalo'    => (string)($r['intervalo'] ?? ''),
        // O app monta as series a partir destas listas. Vao como array para
        // nao obrigar cada tela a repetir o mesmo split.
        'tecnicas_por_serie' => (($r['tecnicas'] ?? '') !== '') ? explode('/', (string)$r['tecnicas']) : [],
        'reps_por_serie'     => (strpos((string)($r['repeticoes'] ?? ''), '/') !== false) ? explode('/', (string)$r['repeticoes']) : [],
        'intervalos_por_serie' => (strpos((string)($r['intervalo'] ?? ''), '/') !== false) ? explode('/', (string)$r['intervalo']) : [],
        'metodo'       => (string)($r['metodo'] ?? ''),
        'observacao'   => (string)($r['observacao'] ?? ''),
        'substitutos'  => $subs,
        'unilateral'   => (bool)(int)($r['unilateral'] ?? 0),
    ];
}

require_once __DIR__ . '/_auth_context.php';
$_authCtx = getAuthContext($pdo, $tok);
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

// ── ESCOPO DE ACESSO ───────────────────────────────────────────────────────
// $_accessFilter = null quer dizer "admin, sem restricao" — e era exatamente o
// que o ALUNO recebia, pelo mesmo motivo de sempre: a condicao terminava em
// "&& !is_aluno". Medido em 20/08/2026 com token de aluno: ?action=fichas
// devolveu 94 fichas, os treinos de todo mundo. E como as 13 checagens do
// arquivo passam por _checkAtletaAccess(), o mesmo buraco valia para editar e
// apagar ficha alheia. Aluno agora recebe uma lista com o proprio id.
$_ehAlunoTr = !empty($_authCtx['is_aluno']);
$_accessFilter = null;
if ($_ehAlunoTr) {
    $_meuTr = (int)($_authCtx['idatleta'] ?? $_authCtx['idusuario']);
    $_accessFilter = $_meuTr > 0 ? [$_meuTr] : [];
} elseif (!$_authCtx['admin'] && $_authCtx['idusuario'] > 0) {
    $_accessFilter = getAtletasDoUsuario($pdo, $_authCtx['idusuario']);
}

function _checkAtletaAccess(int $idatleta): bool {
    global $_accessFilter;
    if ($_accessFilter === null) return true;
    return in_array($idatleta, $_accessFilter);
}
function _checkFichaAccess(PDO $pdo, int $idficha): bool {
    global $_accessFilter;
    if ($_accessFilter === null) return true;
    $st = $pdo->prepare("SELECT idatleta FROM intus_ficha WHERE idficha = ? LIMIT 1");
    $st->execute([$idficha]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    return in_array((int)$row['idatleta'], $_accessFilter);
}

require_once __DIR__ . '/_audit_log.php';

// ---------- Roteamento ----------
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Prescricao de treino e do professor. O aplicativo do aluno registra sessao e
// cardio (tratados adiante), mas nao cria, edita nem apaga ficha e exercicio.
$_SO_PROF_TREINO = ['ficha','fichas','treinos','treinos_replace','exercicios',
                    'fix_subs_treino','dedup_exercicios','fix_ids'];
if ($_ehAlunoTr && $method !== 'GET' && in_array($action, $_SO_PROF_TREINO, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'apenas o professor altera treinos']);
    exit;
}
// Rotas de manutencao em massa: so admin. Elas fazem DELETE e UPDATE em toda a
// base de exercicios, sem volta, e aceitavam qualquer token valido.
if (in_array($action, ['fix_subs_treino','dedup_exercicios','fix_ids'], true) && empty($_authCtx['admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'restrito ao administrador']);
    exit;
}


try {
    if ($action === 'ficha') {
        if ($method === 'GET') {
            $idat = (int)($_GET['atleta'] ?? 0);
            if ($idat <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }
            if (!_checkAtletaAccess($idat)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            $st = $pdo->prepare("SELECT * FROM intus_ficha WHERE idatleta = ? ORDER BY idficha DESC LIMIT 1");
            $st->execute([$idat]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            echo json_encode($row ? fichaRowToOut($row) : null);
            exit;
        }
        if ($method === 'POST') {
            $b = jsonBody();
            $idat = (int)($b['idatleta'] ?? 0);
            if ($idat <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
            if (!_checkAtletaAccess($idat)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            $alongs = null;
            if (isset($b['alongamentos']) && is_array($b['alongamentos'])) $alongs = json_encode($b['alongamentos']);
            $divNomes = null;
            if (isset($b['divNomes']) && is_array($b['divNomes'])) $divNomes = json_encode($b['divNomes']);
            $st = $pdo->prepare("INSERT INTO intus_ficha (idatleta, nmficha, dtinicio, dtfim, observacao, ativo, alongamentos, div_nomes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([
                $idat,
                $b['nmficha'] ?? 'Ficha',
                $b['dtinicio'] ?? null,
                $b['dtfim'] ?? null,
                $b['observacao'] ?? null,
                (int)($b['ativo'] ?? 1),
                $alongs,
                $divNomes,
            ]);
            $id = (int)$pdo->lastInsertId();
            $row = $pdo->query("SELECT * FROM intus_ficha WHERE idficha = $id")->fetch(PDO::FETCH_ASSOC);
            auditLog($pdo, $tok, 'criar', 'ficha', $id, 'Criou ficha p/ atleta #' . $idat, null, $row);
            echo json_encode(fichaRowToOut($row));
            exit;
        }
        if ($method === 'PUT') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
            if (!_checkFichaAccess($pdo, $id)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            $b = jsonBody();
            $sets = []; $vals = [];
            foreach (['nmficha','dtinicio','dtfim','observacao','ativo','idatleta'] as $c) {
                if (array_key_exists($c, $b)) { $sets[] = "$c = ?"; $vals[] = $b[$c]; }
            }
            if (array_key_exists('alongamentos', $b)) {
                $sets[] = "alongamentos = ?";
                $vals[] = is_array($b['alongamentos']) ? json_encode($b['alongamentos']) : null;
            }
            if (array_key_exists('divNomes', $b)) {
                $sets[] = "div_nomes = ?";
                $vals[] = is_array($b['divNomes']) ? json_encode($b['divNomes']) : null;
            }
            if (!$sets) { echo json_encode(['ok' => true, 'noop' => true]); exit; }
            $vals[] = $id;
            $st = $pdo->prepare("UPDATE intus_ficha SET " . implode(',', $sets) . " WHERE idficha = ?");
            $st->execute($vals);
            $row = $pdo->query("SELECT * FROM intus_ficha WHERE idficha = $id")->fetch(PDO::FETCH_ASSOC);
            echo json_encode($row ? fichaRowToOut($row) : null);
            exit;
        }
        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
            if (!_checkFichaAccess($pdo, $id)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            $fichaRow = $pdo->query("SELECT * FROM intus_ficha WHERE idficha = $id")->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM intus_treino WHERE idficha = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM intus_ficha  WHERE idficha = ?")->execute([$id]);
            auditLog($pdo, $tok, 'excluir', 'ficha', $id, 'Excluiu ficha #' . $id, $fichaRow, null);
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    if ($action === 'fichas') {
        if ($_accessFilter === null) {
            $rows = $pdo->query("SELECT * FROM intus_ficha ORDER BY idficha DESC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            if (empty($_accessFilter)) { echo json_encode([]); exit; }
            $ph = implode(',', array_fill(0, count($_accessFilter), '?'));
            $st = $pdo->prepare("SELECT * FROM intus_ficha WHERE idatleta IN ($ph) ORDER BY idficha DESC");
            $st->execute($_accessFilter);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(array_map('fichaRowToOut', $rows));
        exit;
    }

    if ($action === 'treinos') {
        if ($method === 'GET') {
            if (isset($_GET['ficha'])) {
                $id = (int)$_GET['ficha'];
                if (!_checkFichaAccess($pdo, $id)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
                $st = $pdo->prepare("SELECT * FROM intus_treino WHERE idficha = ? ORDER BY divisao, idtreino");
                $st->execute([$id]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } else {
                if ($_accessFilter === null) {
                    $rows = $pdo->query("SELECT * FROM intus_treino ORDER BY idficha, divisao, idtreino")->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    if (empty($_accessFilter)) { echo json_encode([]); exit; }
                    $ph = implode(',', array_fill(0, count($_accessFilter), '?'));
                    $st = $pdo->prepare("SELECT t.* FROM intus_treino t INNER JOIN intus_ficha f ON f.idficha = t.idficha WHERE f.idatleta IN ($ph) ORDER BY t.idficha, t.divisao, t.idtreino");
                    $st->execute($_accessFilter);
                    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            echo json_encode(array_map('treinoRowToOut', $rows));
            exit;
        }
        if ($method === 'POST') {
            $b = jsonBody();
            $idficha = (int)($b['idficha'] ?? 0);
            $idex    = (int)($b['idexercicio'] ?? 0);
            if ($idficha <= 0 || $idex <= 0) { http_response_code(400); echo json_encode(['error' => 'idficha/idexercicio obrigatorios']); exit; }
            if (!_checkFichaAccess($pdo, $idficha)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            // Validate idexercicio matches nmexercicio; fix if mismatch
            $nmex = trim($b['nmexercicio'] ?? '');
            $normNm = function($s) { return str_replace("\xC2\xBA", "\xC2\xB0", mb_strtolower(trim($s), 'UTF-8')); };
            if ($nmex) {
                $chk = $pdo->prepare("SELECT idexercicio, nmexercicio FROM intus_exercicio WHERE idexercicio = ? LIMIT 1");
                $chk->execute([$idex]);
                $catRow = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$catRow || $normNm($catRow['nmexercicio']) !== $normNm($nmex)) {
                    $allEx = $pdo->query("SELECT idexercicio, nmexercicio FROM intus_exercicio")->fetchAll(PDO::FETCH_ASSOC);
                    $target = $normNm($nmex);
                    foreach ($allEx as $ex) { if ($normNm($ex['nmexercicio']) === $target) { $idex = (int)$ex['idexercicio']; break; } }
                }
            }
            $subs = null;
            if (array_key_exists('substitutos', $b)) {
                if (is_array($b['substitutos']) && count($b['substitutos']) > 0) $subs = json_encode(nomesToIds($b['substitutos'], $pdo));
                elseif ($b['substitutos'] === null) $subs = null;
            }
            $st = $pdo->prepare("INSERT INTO intus_treino
                (idficha, idexercicio, nmexercicio, divisao, qtdserie, qtdrepeticao, repeticoes, tempopausa, intervalo, tecnicas, metodo, observacao, substitutos, unilateral)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $st->execute([
                $idficha, $idex,
                (string)($b['nmexercicio'] ?? ''),
                (string)($b['divisao'] ?? 'A'),
                (int)($b['qtdserie'] ?? 3),
                (int)($b['qtdrepeticao'] ?? 12),
                $b['repeticoes'] ?? null,
                (int)($b['tempopausa'] ?? 60),
                $b['intervalo'] ?? null,
                tecnicasToCol($b['tecnicas_por_serie'] ?? ($b['tecnicas'] ?? null)),
                $b['metodo'] ?? null,
                $b['observacao'] ?? null,
                $subs,
                (int)(!empty($b['unilateral']) ? 1 : 0),
            ]);
            $id = (int)$pdo->lastInsertId();
            $row = $pdo->query("SELECT * FROM intus_treino WHERE idtreino = $id")->fetch(PDO::FETCH_ASSOC);
            echo json_encode(treinoRowToOut($row));
            exit;
        }
        if ($method === 'PUT') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
            // Check access via the treino's ficha
            $chkT = $pdo->prepare("SELECT idficha FROM intus_treino WHERE idtreino = ? LIMIT 1");
            $chkT->execute([$id]);
            $chkR = $chkT->fetch(PDO::FETCH_ASSOC);
            if ($chkR && !_checkFichaAccess($pdo, (int)$chkR['idficha'])) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            $b = jsonBody();
            $sets = []; $vals = [];
            foreach (['nmexercicio','divisao','qtdserie','qtdrepeticao','repeticoes','tempopausa','intervalo','metodo','observacao','idexercicio'] as $c) {
                if (array_key_exists($c, $b)) { $sets[] = "$c = ?"; $vals[] = $b[$c]; }
            }
            if (array_key_exists('unilateral', $b)) { $sets[] = "unilateral = ?"; $vals[] = (int)(!empty($b['unilateral']) ? 1 : 0); }
            if (array_key_exists('tecnicas_por_serie', $b) || array_key_exists('tecnicas', $b)) {
                $sets[] = "tecnicas = ?";
                $vals[] = tecnicasToCol(array_key_exists('tecnicas_por_serie', $b) ? $b['tecnicas_por_serie'] : $b['tecnicas']);
            }
            if (array_key_exists('substitutos', $b)) {
                $sets[] = "substitutos = ?";
                if (is_array($b['substitutos']) && count($b['substitutos']) > 0) $vals[] = json_encode(nomesToIds($b['substitutos'], $pdo));
                else $vals[] = null;
            }
            if (!$sets) { echo json_encode(['ok' => true, 'noop' => true]); exit; }
            $vals[] = $id;
            $st = $pdo->prepare("UPDATE intus_treino SET " . implode(',', $sets) . " WHERE idtreino = ?");
            $st->execute($vals);
            $row = $pdo->query("SELECT * FROM intus_treino WHERE idtreino = $id")->fetch(PDO::FETCH_ASSOC);
            echo json_encode($row ? treinoRowToOut($row) : null);
            exit;
        }
        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
            $chkT = $pdo->prepare("SELECT * FROM intus_treino WHERE idtreino = ? LIMIT 1");
            $chkT->execute([$id]);
            $chkR = $chkT->fetch(PDO::FETCH_ASSOC);
            if ($chkR && !_checkFichaAccess($pdo, (int)$chkR['idficha'])) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            $pdo->prepare("DELETE FROM intus_treino WHERE idtreino = ?")->execute([$id]);
            auditLog($pdo, $tok, 'excluir', 'treino', $id, 'Excluiu exercício: ' . ($chkR['nmexercicio'] ?? '#'.$id), $chkR, null);
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    if ($action === 'treinos_replace') {
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'metodo nao permitido']); exit; }
        $b = jsonBody();
        $idficha = (int)($b['idficha'] ?? 0);
        if ($idficha <= 0) { http_response_code(400); echo json_encode(['error' => 'idficha obrigatorio']); exit; }
        if (!_checkFichaAccess($pdo, $idficha)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
        $exs = (isset($b['exercicios']) && is_array($b['exercicios'])) ? $b['exercicios'] : [];
        $normNm = function($s) { return str_replace("\xC2\xBA", "\xC2\xB0", mb_strtolower(trim((string)$s), 'UTF-8')); };
        $catAll = null;
        $usouTx = false;
        try { $pdo->beginTransaction(); $usouTx = true; } catch (\Throwable $e) { $usouTx = false; }
        try {
            $pdo->prepare("DELETE FROM intus_treino WHERE idficha = ?")->execute([$idficha]);
            $ins = $pdo->prepare("INSERT INTO intus_treino
                (idficha, idexercicio, nmexercicio, divisao, qtdserie, qtdrepeticao, repeticoes, tempopausa, intervalo, tecnicas, metodo, observacao, substitutos, unilateral)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($exs as $ex) {
                if (!is_array($ex)) continue;
                $idex = (int)($ex['idexercicio'] ?? 0);
                $nmex = trim($ex['nmexercicio'] ?? '');
                if ($idex <= 0 && $nmex === '') continue;
                if ($nmex) {
                    $chk = $pdo->prepare("SELECT nmexercicio FROM intus_exercicio WHERE idexercicio = ? LIMIT 1");
                    $chk->execute([$idex]);
                    $catRow = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$catRow || $normNm($catRow['nmexercicio']) !== $normNm($nmex)) {
                        if ($catAll === null) $catAll = $pdo->query("SELECT idexercicio, nmexercicio FROM intus_exercicio")->fetchAll(PDO::FETCH_ASSOC);
                        $alvo = $normNm($nmex);
                        foreach ($catAll as $c) { if ($normNm($c['nmexercicio']) === $alvo) { $idex = (int)$c['idexercicio']; break; } }
                    }
                }
                if ($idex <= 0) continue;
                $subs = null;
                if (isset($ex['substitutos']) && is_array($ex['substitutos']) && count($ex['substitutos']) > 0) {
                    $subs = json_encode(nomesToIds($ex['substitutos'], $pdo));
                }
                $ins->execute([
                    $idficha, $idex,
                    (string)($ex['nmexercicio'] ?? ''),
                    (string)($ex['divisao'] ?? 'A'),
                    (int)($ex['qtdserie'] ?? 3),
                    (int)($ex['qtdrepeticao'] ?? 12),
                    $ex['repeticoes'] ?? null,
                    (int)($ex['tempopausa'] ?? 60),
                    $ex['intervalo'] ?? null,
                    tecnicasToCol($ex['tecnicas_por_serie'] ?? ($ex['tecnicas'] ?? null)),
                    $ex['metodo'] ?? null,
                    $ex['observacao'] ?? null,
                    $subs,
                    (int)(!empty($ex['unilateral']) ? 1 : 0),
                ]);
            }
            if ($usouTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($usouTx && $pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'falha ao substituir exercicios']);
            exit;
        }
        $st = $pdo->prepare("SELECT * FROM intus_treino WHERE idficha = ? ORDER BY divisao, idtreino");
        $st->execute([$idficha]);
        $out = array_map('treinoRowToOut', $st->fetchAll(PDO::FETCH_ASSOC));
        try { auditLog($pdo, $tok, 'substituir', 'ficha_exercicios', $idficha, 'Substituiu ' . count($out) . ' exercicio(s) da ficha #' . $idficha, null, null); } catch (\Throwable $e) {}
        echo json_encode($out);
        exit;
    }

    if ($action === 'sessoes') {
        if ($method === 'GET') {
            $idat = (int)($_GET['atleta'] ?? 0);
            if ($idat > 0) {
                if (!_checkAtletaAccess($idat)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
                $st = $pdo->prepare("SELECT * FROM intus_sessao WHERE idatleta = ? ORDER BY dtsessao DESC, idsessao DESC");
                $st->execute([$idat]);
            } elseif ($_accessFilter === null) {
                $st = $pdo->query("SELECT * FROM intus_sessao ORDER BY dtsessao DESC, idsessao DESC");
            } else {
                if (empty($_accessFilter)) { echo json_encode([]); exit; }
                $ph = implode(',', array_fill(0, count($_accessFilter), '?'));
                $st = $pdo->prepare("SELECT * FROM intus_sessao WHERE idatleta IN ($ph) ORDER BY dtsessao DESC, idsessao DESC");
                $st->execute($_accessFilter);
            }
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $out = array_map(function($r) {
                $itens = null;
                if (isset($r['itens']) && $r['itens']) { $tmp = json_decode($r['itens'], true); if (is_array($tmp)) $itens = $tmp; }
                return [
                    'idsessao' => (int)$r['idsessao'],
                    'idatleta' => (int)$r['idatleta'],
                    'idficha'  => $r['idficha'] ? (int)$r['idficha'] : null,
                    'divisao'  => (string)$r['divisao'],
                    'dtsessao' => $r['dtsessao'],
                    'duracao_seg' => (int)$r['duracao_seg'],
                    'comentario' => (string)($r['comentario'] ?? ''),
                    'marcado_como_feito' => (bool)$r['marcado_como_feito'],
                    'nao_contar' => (int)($r['nao_contar'] ?? 0),
                    'tipo' => $r['tipo'] ?? 'musculacao',
                    'pontos' => (float)($r['pontos'] ?? 0),
                    'cardio_tipo' => $r['cardio_tipo'] ?? null,
                    'cardio_intensidade' => $r['cardio_intensidade'] ?? null,
                    'cardio_rpe' => isset($r['cardio_rpe']) && $r['cardio_rpe'] !== null ? (int)$r['cardio_rpe'] : null,
                    'hora_conclusao' => $r['hora_conclusao'] ?? null,
                    'tem_foto' => (!empty($r['cardio_foto']) ? 1 : 0),
                    'itens' => $itens,
                ];
            }, $rows);
            echo json_encode($out);
            exit;
        }
        if ($method === 'POST') {
            $b = jsonBody();
            $idat = (int)($b['idatleta'] ?? 0);
            if ($idat <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
            $itens = isset($b['itens']) && is_array($b['itens']) ? json_encode($b['itens']) : null;
            $naoContar = (int)($b['nao_contar'] ?? 0);
            // Musculação = 5 pontos por sessão válida (MET médio 5.0)
            $pontos = ($naoContar ? 0 : 5.0);
            $horaConcl = (isset($b['hora_conclusao']) && $b['hora_conclusao'] !== '') ? substr((string)$b['hora_conclusao'], 0, 5) : null;
            $st = $pdo->prepare("INSERT INTO intus_sessao (idatleta, idficha, divisao, dtsessao, duracao_seg, comentario, marcado_como_feito, nao_contar, tipo, pontos, itens, hora_conclusao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'musculacao', ?, ?, ?)");
            $st->execute([
                $idat,
                isset($b['idficha']) ? (int)$b['idficha'] : null,
                $b['divisao'] ?? 'A',
                $b['dtsessao'] ?? date('Y-m-d'),
                (int)($b['duracao_seg'] ?? 0),
                $b['comentario'] ?? null,
                (int)($b['marcado_como_feito'] ?? 0),
                $naoContar,
                $pontos,
                $itens,
                $horaConcl,
            ]);
            $id = (int)$pdo->lastInsertId();
            echo json_encode(['idsessao' => $id, 'ok' => true]);
            exit;
        }
        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
            $pdo->prepare("DELETE FROM intus_sessao WHERE idsessao = ?")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    // ── CARDIO ──────────────────────────────────────────────────
    if ($action === 'cardio') {
        if ($method === 'POST') {
            $b = jsonBody();
            $idat = (int)($b['idatleta'] ?? 0);
            if ($idat <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
            $duracao_seg = (int)($b['duracao_seg'] ?? 0);
            $pontos = (float)($b['pontos'] ?? 0);
            $rpe = isset($b['cardio_rpe']) && $b['cardio_rpe'] !== '' ? (int)$b['cardio_rpe'] : null;
            $foto = isset($b['cardio_foto']) && $b['cardio_foto'] !== '' ? $b['cardio_foto'] : null;
    $foto = _intusImagemOk($foto);
    if ($foto === false) { http_response_code(413); echo json_encode(['error' => 'imagem invalida ou grande demais (max 3 MB)']); exit; }
            $dtCardio = $b['dtsessao'] ?? date('Y-m-d');
            $naoContar = isset($b['nao_contar']) ? (int)$b['nao_contar'] : 0;
            // Regras configuráveis pelo painel (chave cardio_regras): limite diário e duração máxima
            $LIMITE_CARDIO_DIA = 3; $MAX_MIN_CARDIO = 240;
            try {
                $rcfg = $pdo->query("SELECT valor FROM intus_config WHERE chave = 'cardio_regras'")->fetch(PDO::FETCH_ASSOC);
                if ($rcfg && $rcfg['valor']) {
                    $jr = json_decode($rcfg['valor'], true);
                    if (is_array($jr)) {
                        if (isset($jr['limiteDia']) && (int)$jr['limiteDia'] > 0) $LIMITE_CARDIO_DIA = (int)$jr['limiteDia'];
                        if (isset($jr['maxMin']) && (int)$jr['maxMin'] > 0) $MAX_MIN_CARDIO = (int)$jr['maxMin'];
                    }
                }
            } catch (Throwable $e) {}
            // Limite diário de cardios que contam pontos (anti-spam do ranking).
            try {
                $cst = $pdo->prepare("SELECT COUNT(*) FROM intus_sessao WHERE idatleta = ? AND tipo = 'cardio' AND dtsessao = ? AND (nao_contar IS NULL OR nao_contar = 0) AND pontos > 0");
                $cst->execute([$idat, $dtCardio]);
                if ((int)$cst->fetchColumn() >= $LIMITE_CARDIO_DIA) { $naoContar = 1; $pontos = 0; }
            } catch (Throwable $e) {}
            // Controle de tempo: duração acima do máximo configurado não soma pontos
            if ($duracao_seg > $MAX_MIN_CARDIO * 60) { $naoContar = 1; $pontos = 0; }
            $horaConcl = (isset($b['hora_conclusao']) && $b['hora_conclusao'] !== '') ? substr((string)$b['hora_conclusao'], 0, 5) : null;
            $st = $pdo->prepare("INSERT INTO intus_sessao (idatleta, dtsessao, duracao_seg, comentario, nao_contar, tipo, pontos, cardio_tipo, cardio_intensidade, cardio_rpe, cardio_foto, divisao, hora_conclusao) VALUES (?, ?, ?, ?, ?, 'cardio', ?, ?, ?, ?, ?, 'C', ?)");
            $st->execute([
                $idat,
                $dtCardio,
                $duracao_seg,
                $b['comentario'] ?? null,
                $naoContar,
                $pontos,
                $b['cardio_tipo'] ?? null,
                $b['cardio_intensidade'] ?? null,
                $rpe,
                $foto,
                $horaConcl,
            ]);
            $id = (int)$pdo->lastInsertId();
            echo json_encode(['idsessao' => $id, 'ok' => true, 'pontos' => $pontos, 'nao_contar' => $naoContar, 'limite_dia' => $LIMITE_CARDIO_DIA]);
            exit;
        }
        // Foto do cardio (para conferência do professor) — buscada sob demanda
        if ($method === 'GET' && isset($_GET['foto'])) {
            $id = (int)($_GET['foto'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
            $st = $pdo->prepare("SELECT cardio_foto FROM intus_sessao WHERE idsessao = ? AND tipo = 'cardio'");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['foto' => ($row['cardio_foto'] ?? '')]);
            exit;
        }
        if ($method === 'GET') {
            $idat = (int)($_GET['atleta'] ?? 0);
            if ($idat <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }
            $st = $pdo->prepare("SELECT idsessao, idatleta, dtsessao, duracao_seg, pontos, cardio_tipo, cardio_intensidade, cardio_rpe, nao_contar, (cardio_foto IS NOT NULL AND cardio_foto <> '') AS tem_foto, comentario FROM intus_sessao WHERE idatleta = ? AND tipo = 'cardio' ORDER BY dtsessao DESC, idsessao DESC LIMIT 100");
            $st->execute([$idat]);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }
        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
            $pdo->prepare("DELETE FROM intus_sessao WHERE idsessao = ? AND tipo = 'cardio'")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }
    }

    if ($action === 'ranking') {
        // Create ranking comments table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS intus_ranking_comentario (
                idcomentario  INT AUTO_INCREMENT PRIMARY KEY,
                idatleta      INT NOT NULL,
                nome_atleta   VARCHAR(200) NOT NULL,
                texto         TEXT NOT NULL,
                dtcriacao     DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        _intusGarantirUtf8mb4($pdo, 'intus_ranking_comentario', ['nome_atleta', 'texto']);
        _intusGarantirUtf8mb4($pdo, 'intus_sessao', ['comentario']);

        // Conserto pontual: dois comentarios do mural ficaram com emoji de
        // fogo virado "?" (a tabela estava presa num charset antigo — ver
        // _charset_fix.php). Update exato, por texto igual; assim que o
        // texto for corrigido a condicao para de bater e isto vira no-op.
        try {
            $pdo->exec("UPDATE intus_ranking_comentario SET texto = 'Quero ver quem vai ficar no Top 5 esse mês 🔥🔥🔥' WHERE nome_atleta = 'Luiz Nunes (aluno)' AND texto = 'Quero ver quem vai ficar no Top 5 esse mês ???'");
            $pdo->exec("UPDATE intus_ranking_comentario SET texto = 'Treino rendeu hoje 🔥🔥🔥🔥' WHERE nome_atleta = 'Isabela Pazzinatto' AND texto = 'Treino rendeu hoje ????'");
        } catch (Throwable $e) { @error_log('[intus mural fix] ' . $e->getMessage()); }

        if ($method === 'GET') {
            // Detect athlete table name
            $_rkTbl = null;
            foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $_rt) {
                try { $pdo->query("SELECT 1 FROM `$_rt` LIMIT 1"); $_rkTbl = $_rt; break; } catch (Throwable $e) {}
            }
            $atletas = [];
            if ($_rkTbl) {
                try {
                    $atletas = $pdo->query("SELECT idatleta, nome FROM `$_rkTbl` WHERE ranking_optin = 'S' AND (stbloqueio IS NULL OR stbloqueio != 'S')")->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $atletas = $pdo->query("SELECT idatleta, nome FROM `$_rkTbl`")->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            $ids = array_map(function($a) { return (int)$a['idatleta']; }, $atletas);
            $nomeMap = [];
            foreach ($atletas as $a) $nomeMap[(int)$a['idatleta']] = $a['nome'];

            $sessoes = [];
            if (count($ids) > 0) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $st = $pdo->prepare("SELECT idsessao, idatleta, dtsessao, duracao_seg, COALESCE(tipo,'musculacao') AS tipo, COALESCE(pontos,0) AS pontos, comentario, cardio_tipo, cardio_intensidade, cardio_rpe, (cardio_foto IS NOT NULL AND cardio_foto <> '') AS tem_foto, divisao FROM intus_sessao WHERE idatleta IN ($placeholders) AND (duracao_seg >= 300 OR duracao_seg IS NULL) AND (nao_contar IS NULL OR nao_contar = 0) ORDER BY dtsessao DESC");
                $st->execute($ids);
                $sessoes = $st->fetchAll(PDO::FETCH_ASSOC);
                // Back-fill pontos for old musculacao rows: 0 = legacy pre-column rows → assign 5
                foreach ($sessoes as &$s) {
                    if ($s['tipo'] === 'musculacao' && (float)$s['pontos'] == 0) $s['pontos'] = 5;
                }
                unset($s);
            }

            // Get comments
            $comentarios = $pdo->query("SELECT * FROM intus_ranking_comentario ORDER BY dtcriacao DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

            // Fetch avatars from intus_profile
            $avatarMap = [];
            try {
                $avRows = $pdo->query("SELECT idatleta, avatar FROM intus_profile WHERE avatar IS NOT NULL AND avatar != ''")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($avRows as $av) { $avatarMap[(int)$av['idatleta']] = $av['avatar']; }
            } catch (Throwable $e) {}

            // Cidades do cadastro do aluno (em branco quando não preenchido)
            $cidadeMap = [];
            if ($_rkTbl && count($ids) > 0) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                foreach (['cidade', 'city', 'municipio', 'cidade_uf'] as $_col) {
                    try {
                        $cq = $pdo->prepare("SELECT idatleta, `$_col` AS cidade FROM `$_rkTbl` WHERE idatleta IN ($placeholders)");
                        $cq->execute($ids);
                        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                            $c = trim((string)($cr['cidade'] ?? ''));
                            if ($c !== '') $cidadeMap[(int)$cr['idatleta']] = $c;
                        }
                        break; // coluna encontrada
                    } catch (Throwable $e) { /* tenta a próxima coluna */ }
                }
            }

            // Estado e país do cadastro (em branco quando não preenchidos)
            $estadoMap = [];
            $paisMap = [];
            if ($_rkTbl && count($ids) > 0) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                foreach ([['estado','uf','regiao','state'], ['pais','country']] as $_grupo) {
                    $_alvo = ($_grupo[0] === 'pais') ? 'paisMap' : 'estadoMap';
                    foreach ($_grupo as $_col) {
                        try {
                            $cq = $pdo->prepare("SELECT idatleta, `$_col` AS val FROM `$_rkTbl` WHERE idatleta IN ($placeholders)");
                            $cq->execute($ids);
                            foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                                $v = trim((string)($cr['val'] ?? ''));
                                if ($v !== '') { if ($_alvo === 'paisMap') $paisMap[(int)$cr['idatleta']] = $v; else $estadoMap[(int)$cr['idatleta']] = $v; }
                            }
                            break; // coluna encontrada para este grupo
                        } catch (Throwable $e) { /* tenta a próxima coluna */ }
                    }
                }
            }

            echo json_encode([
                'atletas' => $nomeMap,
                'sessoes' => $sessoes,
                'comentarios' => $comentarios,
                'avatars' => $avatarMap,
                'cidades' => $cidadeMap,
                'estados' => $estadoMap,
                'paises' => $paisMap,
            ]);
            exit;
        }
        if ($method === 'POST') {
            $b = jsonBody();
            $subaction = $b['subaction'] ?? '';
            if ($subaction === 'comentario') {
                $idat = (int)($b['idatleta'] ?? 0);
                $nome = trim($b['nome'] ?? '');
                $texto = trim($b['texto'] ?? '');
                if ($idat <= 0 || !$texto) { http_response_code(400); echo json_encode(['error' => 'dados incompletos']); exit; }
                $st = $pdo->prepare("INSERT INTO intus_ranking_comentario (idatleta, nome_atleta, texto) VALUES (?, ?, ?)");
                $st->execute([$idat, $nome, $texto]);
                echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
                exit;
            }
            if ($subaction === 'excluir_comentario') {
                // Admin (painel) exclui qualquer comentário; aluno exclui apenas o próprio.
                $idc = (int)($b['idcomentario'] ?? 0);
                $idat = (int)($b['idatleta'] ?? 0);
                // ANTES: $isAdminReq = !empty($b['admin']) — o cliente se declarava admin no
    // corpo da requisicao e o servidor acreditava. Qualquer token valido apagava
    // qualquer comentario do mural. Quem diz se e admin e o token.
    $isAdminReq = !empty($_authCtx['admin']);
                if ($idc <= 0) { http_response_code(400); echo json_encode(['error' => 'idcomentario obrigatorio']); exit; }
                if ($isAdminReq) {
                    $st = $pdo->prepare("DELETE FROM intus_ranking_comentario WHERE idcomentario = ?");
                    $st->execute([$idc]);
                } else {
                    if ($idat <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
                    $st = $pdo->prepare("DELETE FROM intus_ranking_comentario WHERE idcomentario = ? AND idatleta = ?");
                    $st->execute([$idc, $idat]);
                }
                echo json_encode(['ok' => true, 'deleted' => $st->rowCount()]);
                exit;
            }
        }
    }

    if ($action === 'exercicios') {
        // Exercise catalog — persisted in intus_exercicio table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS intus_exercicio (
                idexercicio   INT AUTO_INCREMENT PRIMARY KEY,
                nmexercicio   VARCHAR(200) NOT NULL,
                grupo         VARCHAR(120) NULL,
                grupos        TEXT NULL,
                observacao    TEXT NULL,
                descricao     TEXT NULL,
                videoyoutube  VARCHAR(500) NULL,
                gifexercicio  VARCHAR(500) NULL,
                instrucao_ia  TEXT NULL,
                substitutos   TEXT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        // Auto-add columns that may not exist yet
        $ecols = array_column($pdo->query("SHOW COLUMNS FROM intus_exercicio")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('gifexercicio', $ecols)) $pdo->exec("ALTER TABLE intus_exercicio ADD COLUMN gifexercicio VARCHAR(500) NULL AFTER videoyoutube");
        if (!in_array('instrucao_ia', $ecols)) $pdo->exec("ALTER TABLE intus_exercicio ADD COLUMN instrucao_ia TEXT NULL AFTER gifexercicio");
        if (!in_array('grupos', $ecols)) $pdo->exec("ALTER TABLE intus_exercicio ADD COLUMN grupos TEXT NULL AFTER grupo");
        if (!in_array('descricao', $ecols)) $pdo->exec("ALTER TABLE intus_exercicio ADD COLUMN descricao TEXT NULL AFTER observacao");
        // Equipamento exigido (maquina | halter | barra_livre | peso_corporal | elastico).
        // Alimenta o plano self-service: o motor troca exercicio por um substituto do
        // catalogo que bata com o que o aluno tem disponivel (academia completa/basica,
        // casa com halteres-elasticos, so peso corporal). Marcado manualmente pelo
        // professor em exercicios.html — nao ha como inferir com confianca so pelo nome.
        if (!in_array('equipamento', $ecols)) $pdo->exec("ALTER TABLE intus_exercicio ADD COLUMN equipamento VARCHAR(30) NULL AFTER grupos");

        if ($method === 'GET') {
            $rows = $pdo->query("SELECT * FROM intus_exercicio ORDER BY nmexercicio")->fetchAll(PDO::FETCH_ASSOC);
            $out = [];
            foreach ($rows as $r) {
                $subs = null;
                if (!empty($r['substitutos'])) { $tmp = json_decode($r['substitutos'], true); if (is_array($tmp) && count($tmp) > 0) $subs = subsToNomes($tmp, $pdo); }
                $grupos = null;
                if (!empty($r['grupos'])) { $tmp = json_decode($r['grupos'], true); if (is_array($tmp)) $grupos = $tmp; }
                $out[] = [
                    'idexercicio'  => (int)$r['idexercicio'],
                    'nmexercicio'  => $r['nmexercicio'],
                    'grupo'        => $r['grupo'] ?? '',
                    'grupos'       => $grupos,
                    'equipamento'  => $r['equipamento'] ?? '',
                    'observacao'   => $r['observacao'] ?? '',
                    'descricao'    => $r['descricao'] ?? '',
                    'videoyoutube' => $r['videoyoutube'] ?? '',
                    'gifexercicio' => $r['gifexercicio'] ?? '',
                    'instrucao_ia' => $r['instrucao_ia'] ?? '',
                    'substitutos'  => $subs,
                ];
            }
            if (empty($out)) {
                // Return 404 so frontend falls back to local seed on first use
                http_response_code(404);
                echo json_encode(['error' => 'empty_catalog']);
            } else {
                echo json_encode($out);
            }
            exit;
        }

        if ($method === 'POST') {
            $b = jsonBody();
            $nm = trim($b['nmexercicio'] ?? '');
            if (!$nm) { http_response_code(400); echo json_encode(['error' => 'nmexercicio obrigatorio']); exit; }
            // Evita duplicata por nome
            $chk = $pdo->prepare("SELECT idexercicio FROM intus_exercicio WHERE LOWER(TRIM(nmexercicio)) = LOWER(?) LIMIT 1");
            $chk->execute([$nm]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                echo json_encode(['ok' => true, 'idexercicio' => (int)$existing['idexercicio'], 'duplicate' => true]);
                exit;
            }
            $grupos = isset($b['grupos']) && is_array($b['grupos']) ? json_encode($b['grupos']) : null;
            $subs = (isset($b['substitutos']) && is_array($b['substitutos']) && count($b['substitutos']) > 0) ? json_encode(nomesToIds($b['substitutos'], $pdo)) : null;
            $st = $pdo->prepare("INSERT INTO intus_exercicio (nmexercicio, grupo, grupos, equipamento, observacao, descricao, videoyoutube, gifexercicio, instrucao_ia, substitutos) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $nm,
                $b['grupo'] ?? '',
                $grupos,
                $b['equipamento'] ?? null,
                $b['observacao'] ?? '',
                $b['descricao'] ?? '',
                $b['videoyoutube'] ?? '',
                $b['gifexercicio'] ?? '',
                $b['instrucao_ia'] ?? '',
                $subs,
            ]);
            echo json_encode(['ok' => true, 'idexercicio' => (int)$pdo->lastInsertId()]);
            exit;
        }

        if ($method === 'PUT') {
            $b = jsonBody();
            $id = (int)($b['idexercicio'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'idexercicio obrigatorio']); exit; }
            $sets = []; $vals = [];
            foreach (['nmexercicio','grupo','equipamento','observacao','descricao','videoyoutube','gifexercicio','instrucao_ia'] as $k) {
                if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = $b[$k]; }
            }
            if (array_key_exists('grupos', $b)) { $sets[] = "grupos = ?"; $vals[] = is_array($b['grupos']) ? json_encode($b['grupos']) : null; }
            if (array_key_exists('substitutos', $b)) { $sets[] = "substitutos = ?"; $vals[] = (is_array($b['substitutos']) && count($b['substitutos']) > 0) ? json_encode(nomesToIds($b['substitutos'], $pdo)) : null; }
            if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
            $vals[] = $id;
            $pdo->prepare("UPDATE intus_exercicio SET " . implode(', ', $sets) . " WHERE idexercicio = ?")->execute($vals);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) { $b = jsonBody(); $id = (int)($b['idexercicio'] ?? 0); }
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'idexercicio obrigatorio']); exit; }
            // Substituto "fantasma": sem isto, exercicio apagado continuava preso na
            // lista de substitutos de quem o referenciava — a leitura ja filtra isso
            // em silencio (subsToNomes), mas o dado sujo ficava salvo pra sempre.
            try {
                $refs = $pdo->query("SELECT idexercicio, substitutos FROM intus_exercicio WHERE substitutos LIKE '%\"" . $id . "\"%' OR substitutos LIKE '%," . $id . ",%' OR substitutos LIKE '%[" . $id . ",%' OR substitutos LIKE '%," . $id . "]%' OR substitutos = '[" . $id . "]'")->fetchAll(PDO::FETCH_ASSOC);
                $upd = $pdo->prepare("UPDATE intus_exercicio SET substitutos = ? WHERE idexercicio = ?");
                foreach ($refs as $r) {
                    $arr = json_decode($r['substitutos'], true);
                    if (!is_array($arr)) continue;
                    $novo = array_values(array_filter($arr, fn($v) => (int)$v !== $id));
                    $upd->execute([count($novo) ? json_encode($novo) : null, $r['idexercicio']]);
                }
            } catch (Throwable $e) {}
            $pdo->prepare("DELETE FROM intus_exercicio WHERE idexercicio = ?")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        http_response_code(405);
        echo json_encode(['error' => 'method not allowed']);
        exit;
    }

    if ($action === 'fix_subs_treino') {
        // Limpa substitutos corrompidos do intus_treino (resultado de colisão de IDs do seed).
        // Lógica: para cada registro com substitutos, verifica se algum nome não existe no catálogo
        // ou se o substituto é claramente de grupo muscular diferente do exercício principal.
        // Opção segura: limpa TODOS os substitutos do intus_treino, forçando o fallback pro catálogo.
        $rows = $pdo->query("SELECT idtreino, substitutos FROM intus_treino WHERE substitutos IS NOT NULL AND substitutos != '' AND substitutos != 'null'")->fetchAll(PDO::FETCH_ASSOC);
        $catNomes = getCatNomeMap($pdo);
        $catNomesNorm = [];
        foreach ($catNomes as $id => $nm) $catNomesNorm[mb_strtolower(trim($nm), 'UTF-8')] = $nm;
        $cleared = 0;
        $update = $pdo->prepare("UPDATE intus_treino SET substitutos = NULL WHERE idtreino = ?");
        foreach ($rows as $row) {
            $raw = json_decode($row['substitutos'], true);
            if (!is_array($raw) || count($raw) === 0) { $update->execute([$row['idtreino']]); $cleared++; continue; }
            // Verificar se todos os itens são nomes válidos no catálogo
            $allValid = true;
            foreach ($raw as $v) {
                if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
                    // Ainda tem ID — dado antigo, limpar
                    $allValid = false; break;
                }
                $key = mb_strtolower(trim((string)$v), 'UTF-8');
                if (!isset($catNomesNorm[$key])) { $allValid = false; break; }
            }
            if (!$allValid) { $update->execute([$row['idtreino']]); $cleared++; }
        }
        echo json_encode(['ok' => true, 'cleared' => $cleared, 'total_checked' => count($rows)]);
        exit;
    }

    if ($action === 'dedup_exercicios') {
        $pdo->exec("
            DELETE e1 FROM intus_exercicio e1
            INNER JOIN intus_exercicio e2
            ON LOWER(TRIM(e1.nmexercicio)) = LOWER(TRIM(e2.nmexercicio))
            AND e1.idexercicio > e2.idexercicio
        ");
        $remaining = $pdo->query("SELECT COUNT(*) as c FROM intus_exercicio")->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'remaining' => (int)$remaining['c']]);
        exit;
    }

    if ($action === 'fix_ids') {
        // Normalize degree symbols: º (ordinal, 186) → ° (degree, 176)
        $normalize = function($s) { return str_replace("\xC2\xBA", "\xC2\xB0", mb_strtolower(trim($s), 'UTF-8')); };
        $rows = $pdo->query("SELECT idtreino, idexercicio, nmexercicio FROM intus_treino WHERE nmexercicio IS NOT NULL AND nmexercicio != ''")->fetchAll(PDO::FETCH_ASSOC);
        $catalog = $pdo->query("SELECT idexercicio, nmexercicio FROM intus_exercicio")->fetchAll(PDO::FETCH_ASSOC);
        $catMap = [];
        foreach ($catalog as $c) { $catMap[$normalize($c['nmexercicio'])] = (int)$c['idexercicio']; }
        $catById = [];
        foreach ($catalog as $c) { $catById[(int)$c['idexercicio']] = $normalize($c['nmexercicio']); }
        $fixed = 0;
        $update = $pdo->prepare("UPDATE intus_treino SET idexercicio = ? WHERE idtreino = ?");
        foreach ($rows as $row) {
            $nomeNorm = $normalize($row['nmexercicio']);
            $idex = (int)$row['idexercicio'];
            if (isset($catById[$idex]) && $catById[$idex] === $nomeNorm) continue;
            if (isset($catMap[$nomeNorm])) {
                $update->execute([$catMap[$nomeNorm], $row['idtreino']]);
                $fixed++;
            }
        }
        echo json_encode(['ok' => true, 'total' => count($rows), 'fixed' => $fixed]);
        exit;
    }

    if ($action === 'healthcheck') {
        $checks = [];
        // Detectar tabela de atletas
        $tblAtleta = null;
        foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $_t) {
            try { $pdo->query("SELECT 1 FROM `$_t` LIMIT 1"); $tblAtleta = $_t; break; } catch (Throwable $e) {}
        }
        // Exercícios duplicados
        try {
            $dup = $pdo->query("SELECT LOWER(TRIM(nmexercicio)) as nm, COUNT(*) as c FROM intus_exercicio GROUP BY nm HAVING c > 1")->fetchAll(PDO::FETCH_ASSOC);
            $checks['exercicios_duplicados'] = count($dup);
            $totalEx = $pdo->query("SELECT COUNT(*) as c FROM intus_exercicio")->fetch(PDO::FETCH_ASSOC);
            $checks['total_exercicios'] = (int)$totalEx['c'];
        } catch (Throwable $e) { $checks['exercicios_duplicados'] = -1; $checks['total_exercicios'] = -1; }
        // Fichas sem atleta válido
        try {
            if ($tblAtleta) {
                $orphan = $pdo->query("SELECT COUNT(*) as c FROM intus_ficha f LEFT JOIN `$tblAtleta` a ON f.idatleta = a.idatleta WHERE a.idatleta IS NULL")->fetch(PDO::FETCH_ASSOC);
                $checks['fichas_orfas'] = (int)($orphan['c'] ?? 0);
            } else { $checks['fichas_orfas'] = -1; }
            $totalF = $pdo->query("SELECT COUNT(*) as c FROM intus_ficha")->fetch(PDO::FETCH_ASSOC);
            $checks['total_fichas'] = (int)$totalF['c'];
        } catch (Throwable $e) { $checks['fichas_orfas'] = -1; $checks['total_fichas'] = -1; }
        // Treinos sem ficha
        try {
            $tOrphan = $pdo->query("SELECT COUNT(*) as c FROM intus_treino t LEFT JOIN intus_ficha f ON t.idficha = f.idficha WHERE f.idficha IS NULL")->fetch(PDO::FETCH_ASSOC);
            $checks['treinos_orfaos'] = (int)($tOrphan['c'] ?? 0);
        } catch (Throwable $e) { $checks['treinos_orfaos'] = -1; }
        // Total atletas
        try {
            if ($tblAtleta) {
                $totalA = $pdo->query("SELECT COUNT(*) as c FROM `$tblAtleta`")->fetch(PDO::FETCH_ASSOC);
                $checks['total_atletas'] = (int)$totalA['c'];
            } else { $checks['total_atletas'] = -1; }
        } catch (Throwable $e) { $checks['total_atletas'] = -1; }
        $checks['status'] = ($checks['exercicios_duplicados'] === 0 && $checks['fichas_orfas'] === 0 && $checks['treinos_orfaos'] === 0) ? 'ok' : 'issues_found';
        $checks['timestamp'] = date('c');
        echo json_encode($checks);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'action invalida', 'action' => $action]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'falha interna', 'detalhe' => _intusLogErro($e)]);
}
