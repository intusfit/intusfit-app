<?php
/**
 * Intus Fit — Vídeo em posts do Feed (e, depois, do Quadro de Resultados)
 *
 * O aluno escolhe um vídeo (até 60 s e 100 MB); o celular manda pedaços de até 4 MB
 * (action=enviar_parte) e cada pedaço é repassado na hora a uma sessão de upload
 * retomável do Google Drive (mesma conta de serviço/pasta de feedbacks.php e das fotos
 * de avaliação, ver _gdrive.php). Nada de vídeo no disco da KingHost nem no banco.
 * Para tocar, action=video lê do Drive por faixa de bytes (Range), com um token
 * público de 32 hex por vídeo (o <video> do navegador não consegue mandar Authorization).
 *
 * Mesma lógica de feedbacks.php, copiada de propósito: feedbacks.php já funciona em
 * produção e não é refatorado aqui.
 *
 * A linha do vídeo mora em intus_midia (dono_tipo 'feed_post' | 'resultado'); a capa
 * (poster) é um JPEG em app/img/feed enviado junto no "iniciar".
 *
 * Rotas:
 *   PÚBLICA (quem tem o token):
 *     GET  ?action=video&t=TOKEN                       -> bytes do vídeo (aceita Range)
 *   ALUNO LOGADO:
 *     POST ?action=iniciar {dono_tipo, dono_id, mime, tamanho, duracao_seg, poster(dataURL)} -> {ok, id, token, parte_bytes}
 *     POST ?action=enviar_parte&id=N&inicio=BYTE (corpo = bytes crus)                         -> {ok, recebido, concluido}
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_gdrive.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, Range');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

define('MD_DURACAO_MAX_SEG', 30);   // 07/10/2026: de 60 para 30 s, para poupar espaço no Drive
define('MD_TAMANHO_MAX', 60 * 1024 * 1024);   // 30 s a ~2,5 Mbps dão uns 10 MB; 60 MB cobre navegador que ignora o bitrate pedido
define('MD_FAIXA_VIDEO', 4 * 1024 * 1024);
define('MD_MAX_MIDIAS', 10);
define('MD_MAX_VIDEOS', 3);

function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus midia ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

function _mdResponder($codigo, $dados) {
    http_response_code($codigo);
    echo json_encode($dados);
    exit;
}

function bearerToken() {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}

// 4 MB por pedaço (ou menos se o post_max_size do servidor for menor); o Drive exige múltiplo de 256 KB.
function _mdTamanhoParte() {
    $bytes = function ($v) {
        $v = trim((string)$v); if ($v === '') return 0;
        $n = (float)$v; $u = strtolower(substr($v, -1));
        if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
        return (int)$n;
    };
    $limite = $bytes(ini_get('post_max_size'));
    $max = 4 * 1024 * 1024;
    if ($limite > 0) $max = min($max, $limite - 64 * 1024);
    $bloco = 256 * 1024;
    return max($bloco, (int)floor($max / $bloco) * $bloco);
}

// Salva a capa (data URI JPEG/PNG/WEBP até 8 MB) em app/img/feed e devolve a URL pública.
function _mdSalvarCapa(string $raw, int $idatleta): string {
    if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#is', $raw, $m)) throw new Exception('capa invalida');
    $dir = __DIR__ . '/../img/feed';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 8 * 1024 * 1024) throw new Exception('capa invalida ou grande demais');
    if (!is_dir($dir) || !is_writable($dir)) throw new Exception('sem permissao de escrita');
    try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
    $fname = 'poster_' . $idatleta . '_' . time() . '_' . $rand . '.' . $ext;
    if (@file_put_contents($dir . '/' . $fname, $bin) === false) throw new Exception('falha ao salvar capa');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = $_SERVER['HTTP_HOST'] ?? 'intusfit.com.br';
    return ($https ? 'https' : 'http') . '://' . $host . '/app/img/feed/' . $fname;
}

// ---------- Conexao ----------
$cfg = @include_once __DIR__ . '/../config/db.php';
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
    _mdResponder(500, ['ok' => false, 'erro' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
}

// ---------- Tabela (a mesma de catalogo.php; as colunas de upload entram aqui) ----------
// Tocar vídeo (action=video) é o pedido mais frequente: não repete a checagem de tabela nele.
if (($_GET['action'] ?? '') !== 'video') {
$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_midia (
        idmidia        INT AUTO_INCREMENT PRIMARY KEY,
        dono_tipo      VARCHAR(20)  NOT NULL,
        dono_id        INT          NOT NULL,
        ordem          TINYINT      NOT NULL DEFAULT 0,
        tipo           VARCHAR(10)  NOT NULL DEFAULT 'imagem',
        url            VARCHAR(500) NULL,
        poster         VARCHAR(500) NULL,
        drive_id       VARCHAR(120) NULL,
        token_publico  VARCHAR(40)  NULL,
        mime           VARCHAR(60)  NULL,
        tamanho        BIGINT       NULL,
        duracao_seg    INT          NULL,
        status         VARCHAR(10)  NOT NULL DEFAULT 'pronto',
        created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dono (dono_tipo, dono_id, ordem),
        INDEX idx_token (token_publico)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$_mdCols = array_column($pdo->query("SHOW COLUMNS FROM intus_midia")->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('upload_sessao', $_mdCols)) $pdo->exec("ALTER TABLE intus_midia ADD COLUMN upload_sessao TEXT NULL");
if (!in_array('bytes_enviados', $_mdCols)) $pdo->exec("ALTER TABLE intus_midia ADD COLUMN bytes_enviados BIGINT NOT NULL DEFAULT 0");
}

$action = $_GET['action'] ?? '';

try {

    // ======================= PÚBLICA: tocar o vídeo =======================
    if ($action === 'video') {
        $t = (string)($_GET['t'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $t)) _mdResponder(404, ['ok' => false, 'erro' => 'nao encontrado']);
        $st = $pdo->prepare("SELECT drive_id, mime, tamanho FROM intus_midia WHERE token_publico = ? AND tipo = 'video' AND status = 'pronto' LIMIT 1");
        $st->execute([$t]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || !$r['drive_id']) _mdResponder(404, ['ok' => false, 'erro' => 'nao encontrado']);
        $total = (int)$r['tamanho'];
        $ini = 0; $fim = $total - 1;
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
            if ($m[1] === '' && $m[2] !== '') { $ini = max(0, $total - (int)$m[2]); }
            else { $ini = (int)$m[1]; if ($m[2] !== '') $fim = min($fim, (int)$m[2]); }
        }
        if ($ini >= $total || $ini > $fim) {
            header('Content-Range: bytes */' . $total);
            _mdResponder(416, ['ok' => false]);
        }
        // Primeiro trecho pequeno (1 MB) para o vídeo começar logo; os seguintes maiores.
        $tamTrecho = ($ini === 0) ? 1024 * 1024 : MD_FAIXA_VIDEO;
        $fim = min($fim, $ini + $tamTrecho - 1);
        $f = gdriveLerFaixa($r['drive_id'], $ini, $fim);
        $corpo = $f['corpo'];
        if ($f['codigo'] === 200 && strlen($corpo) > ($fim - $ini + 1)) $corpo = substr($corpo, $ini, $fim - $ini + 1);
        $fim = $ini + strlen($corpo) - 1;
        http_response_code(206);
        header('Content-Type: ' . $r['mime']);
        header('Accept-Ranges: bytes');
        header('Content-Range: bytes ' . $ini . '-' . $fim . '/' . $total);
        header('Content-Length: ' . strlen($corpo));
        header('Cache-Control: private, max-age=3600');
        echo $corpo;
        exit;
    }

    // ======================= COM LOGIN (aluno) =======================
    require_once __DIR__ . '/_auth_context.php';
    $ctx = getAuthContext($pdo, bearerToken());
    if (empty($ctx['is_aluno']) || (int)($ctx['idatleta'] ?? 0) <= 0) _mdResponder(401, ['ok' => false, 'erro' => 'sem sessao de aluno']);
    $idatleta = (int)$ctx['idatleta'];
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') _mdResponder(405, ['ok' => false, 'erro' => 'metodo invalido']);

    // O dono da mídia (post do Feed ou resultado) tem que ser do próprio aluno e ainda estar em montagem/ativo.
    $donoValido = function ($tipo, $id) use ($pdo, $idatleta) {
        try {
            if ($tipo === 'feed_post') $st = $pdo->prepare("SELECT 1 FROM intus_feed_post WHERE idpost = ? AND idatleta = ? AND ativo IN (1,2) LIMIT 1");
            elseif ($tipo === 'resultado') $st = $pdo->prepare("SELECT 1 FROM intus_resultado WHERE idresultado = ? AND idatleta = ? AND ativo IN (1,2) LIMIT 1");
            else return false;
            $st->execute([(int)$id, $idatleta]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) { return false; }
    };
    $carregarDoDono = function ($id) use ($pdo, $donoValido) {
        $st = $pdo->prepare("SELECT * FROM intus_midia WHERE idmidia = ? AND tipo = 'video'");
        $st->execute([(int)$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) _mdResponder(404, ['ok' => false, 'erro' => 'nao encontrado']);
        if (!$donoValido($r['dono_tipo'], $r['dono_id'])) _mdResponder(403, ['ok' => false, 'erro' => 'sem permissao']);
        return $r;
    };

    if ($action === 'iniciar') {
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $donoTipo = (string)($b['dono_tipo'] ?? '');
        $donoId = (int)($b['dono_id'] ?? 0);
        if (!$donoValido($donoTipo, $donoId)) _mdResponder(403, ['ok' => false, 'erro' => 'post invalido']);
        $mime = strtolower(trim((string)($b['mime'] ?? '')));
        $mime = preg_replace('/;.*$/', '', $mime);
        if (!in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm'], true)) _mdResponder(400, ['ok' => false, 'erro' => 'Formato de vídeo não suportado (use MP4, MOV ou WEBM).']);
        $tamanho = (int)($b['tamanho'] ?? 0);
        if ($tamanho <= 0 || $tamanho > MD_TAMANHO_MAX) _mdResponder(400, ['ok' => false, 'erro' => 'Vídeo grande demais (máx. ' . round(MD_TAMANHO_MAX / 1048576) . ' MB).']);
        $duracao = (int)($b['duracao_seg'] ?? 0);
        if ($duracao > MD_DURACAO_MAX_SEG + 2) _mdResponder(400, ['ok' => false, 'erro' => 'Vídeo acima de ' . MD_DURACAO_MAX_SEG . ' segundos.']);
        if (!gdriveDisponivel()) _mdResponder(503, ['ok' => false, 'erro' => 'Google Drive não está configurado neste servidor.']);

        $st = $pdo->prepare("SELECT COUNT(*) AS n, SUM(tipo = 'video') AS v FROM intus_midia WHERE dono_tipo = ? AND dono_id = ?");
        $st->execute([$donoTipo, $donoId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$c['n'] >= MD_MAX_MIDIAS) _mdResponder(400, ['ok' => false, 'erro' => 'Limite de ' . MD_MAX_MIDIAS . ' mídias por post.']);
        if ((int)$c['v'] >= MD_MAX_VIDEOS) _mdResponder(400, ['ok' => false, 'erro' => 'Limite de ' . MD_MAX_VIDEOS . ' vídeos por post.']);

        $poster = null;
        if (!empty($b['poster'])) {
            try { $poster = _mdSalvarCapa((string)$b['poster'], $idatleta); }
            catch (Exception $e) { _mdResponder(400, ['ok' => false, 'erro' => $e->getMessage()]); }
        }
        $token = bin2hex(random_bytes(16));
        $ext = $mime === 'video/webm' ? 'webm' : ($mime === 'video/quicktime' ? 'mov' : 'mp4');
        $nomeArq = 'feedvideo_' . date('Y-m-d_His') . '_' . $idatleta . '_' . substr($token, 0, 6) . '.' . $ext;
        $sessao = gdriveIniciarUploadGrande($nomeArq, $mime, $tamanho);
        $urlRel = '/app/api/midia.php?action=video&t=' . $token;

        $st = $pdo->prepare("INSERT INTO intus_midia (dono_tipo, dono_id, ordem, tipo, url, poster, token_publico, mime, tamanho, duracao_seg, status, upload_sessao, bytes_enviados)
                             VALUES (?, ?, ?, 'video', ?, ?, ?, ?, ?, ?, 'enviando', ?, 0)");
        $st->execute([$donoTipo, $donoId, (int)$c['n'], $urlRel, $poster, $token, $mime, $tamanho, max(0, $duracao), $sessao]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'token' => $token, 'parte_bytes' => _mdTamanhoParte()]);
        exit;
    }

    if ($action === 'enviar_parte') {
        $r = $carregarDoDono($_GET['id'] ?? 0);
        if ($r['status'] === 'pronto') _mdResponder(200, ['ok' => true, 'recebido' => (int)$r['tamanho'], 'concluido' => true]);
        if ($r['status'] !== 'enviando' || !$r['upload_sessao']) _mdResponder(409, ['ok' => false, 'erro' => 'upload nao esta aberto']);
        $inicio = (int)($_GET['inicio'] ?? -1);
        $esperado = (int)$r['bytes_enviados'];
        // Pedaço fora de ordem (reenvio depois de falha de rede): diz de onde continuar.
        if ($inicio !== $esperado) _mdResponder(200, ['ok' => true, 'recebido' => $esperado, 'concluido' => false, 'fora_de_ordem' => true]);
        $bin = file_get_contents('php://input');
        if ($bin === '' || $bin === false) _mdResponder(400, ['ok' => false, 'erro' => 'pedaco vazio (limite de upload do servidor?)']);
        $total = (int)$r['tamanho'];
        if ($inicio + strlen($bin) > $total) _mdResponder(400, ['ok' => false, 'erro' => 'pedaco passa do tamanho declarado']);

        $res = gdriveEnviarParte($r['upload_sessao'], $bin, $inicio, $total);
        if ($res['file_id']) {
            $pdo->prepare("UPDATE intus_midia SET status = 'pronto', bytes_enviados = tamanho, drive_id = ?, upload_sessao = NULL WHERE idmidia = ?")
                ->execute([$res['file_id'], (int)$r['idmidia']]);
            echo json_encode(['ok' => true, 'recebido' => $total, 'concluido' => true]);
            exit;
        }
        $pdo->prepare("UPDATE intus_midia SET bytes_enviados = ? WHERE idmidia = ?")->execute([$res['recebido'], (int)$r['idmidia']]);
        echo json_encode(['ok' => true, 'recebido' => $res['recebido'], 'concluido' => false]);
        exit;
    }

    _mdResponder(400, ['ok' => false, 'erro' => 'acao invalida']);

} catch (Throwable $e) {
    _mdResponder(500, ['ok' => false, 'erro' => 'falha interna', 'detalhe' => _intusLogErro($e)]);
}
