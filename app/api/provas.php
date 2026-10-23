<?php
/**
 * Banco de provas sociais (Quadro de Resultados, aba "Provas sociais"): API só da equipe (administrador e professores).
 *
 * GET  ?action=resumo                       contagens, listas de opções e limites
 * GET  ?action=listar[&busca=&objetivo=&autorizacao=&uso=&ativo=1|0|todos]
 * GET  ?action=obter&id=N
 * GET  ?action=ia                           pacote para a inteligência (só administrador)
 * POST ?action=salvar {id?, ...}            cria ou altera
 * POST ?action=excluir {id}
 * POST ?action=foto {idprova, imagem(data URI), momento, legenda}
 * POST ?action=link {idprova, url, momento, legenda}     vídeo por link (YouTube, Instagram, Drive...), só https
 * POST ?action=midia_excluir {idpm}
 * POST ?action=video_iniciar {idprova, mime, tamanho, duracao_seg, momento, legenda, poster?}   e depois
 * POST ?action=video_parte&id=N&inicio=BYTE (corpo = bytes crus)      envio em pedaços para o Google Drive (igual a midia.php)
 *
 * Toda regra mora em _provas.php. Aqui só autenticação, entrada e saída. Aluno nunca entra (401).
 * Professor mexe no que ele mesmo cadastrou; administrador mexe em tudo.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/_wa.php';
require_once __DIR__ . '/_provas.php';

if (!function_exists('bearerToken')) {
    function bearerToken() {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
        return preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';
    }
}
function prv_resp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function prv_entrada(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    if (strlen($raw) > 14 * 1024 * 1024) prv_resp(413, ['ok' => false, 'erro' => 'Envio grande demais.']);
    $a = json_decode($raw, true);
    return is_array($a) ? $a : [];
}

const PRV_VIDEO_SEG_MAX = 60;
const PRV_VIDEO_BYTES_MAX = 60 * 1024 * 1024;

$pdo = wa_conectar();
if (!$pdo) prv_resp(503, ['ok' => false, 'erro' => 'Banco indisponível.']);

try {
    require_once __DIR__ . '/_auth_context.php';
    $ctx = getAuthContext($pdo, bearerToken());
    if ((int)($ctx['idusuario'] ?? 0) <= 0 || !empty($ctx['is_aluno'])) prv_resp(401, ['ok' => false, 'erro' => 'Sessão inválida. Entre de novo.']);
    $idu = (int)$ctx['idusuario'];
    $admin = !empty($ctx['admin']);
    $nomeUsuario = (string)($ctx['nmusuario'] ?? '');

    $action = (string)($_GET['action'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $soPost = ['salvar', 'excluir', 'foto', 'link', 'midia_excluir', 'video_iniciar', 'video_parte'];
    if (in_array($action, $soPost, true) && $metodo !== 'POST') prv_resp(405, ['ok' => false, 'erro' => 'Use POST.']);
    $d = ($metodo === 'POST' && $action !== 'video_parte') ? prv_entrada() : [];

    switch ($action) {
        case 'resumo':
            prv_resp(200, ['ok' => true, 'resumo' => provas_resumo($pdo), 'objetivos' => PROVAS_OBJETIVOS, 'autorizacoes' => PROVAS_AUTORIZACAO, 'usos' => PROVAS_USOS,
                'limites' => ['fotos' => PROVAS_MAX_FOTOS, 'videos' => PROVAS_MAX_VIDEOS, 'video_seg' => PRV_VIDEO_SEG_MAX, 'video_mb' => PRV_VIDEO_BYTES_MAX / 1048576],
                'admin' => $admin, 'eu' => $idu]);
        case 'listar':
            prv_resp(200, ['ok' => true, 'provas' => provas_listar($pdo, $_GET), 'resumo' => provas_resumo($pdo)]);
        case 'obter': {
            $p = provas_obter($pdo, (int)($_GET['id'] ?? 0));
            if (!$p) prv_resp(404, ['ok' => false, 'erro' => 'Prova não encontrada.']);
            prv_resp(200, ['ok' => true, 'prova' => $p, 'texto_pronto' => provas_texto($p)]);
        }
        case 'ia':
            if (!$admin) prv_resp(403, ['ok' => false, 'erro' => 'O pacote da inteligência é só do administrador.']);
            prv_resp(200, ['ok' => true, 'provas' => provas_para_ia($pdo)]);
        case 'salvar': {
            $id = !empty($d['idprova']) ? (int)$d['idprova'] : null;
            $novo = provas_salvar($pdo, $d, $idu, $nomeUsuario, $admin, $id);
            prv_resp(200, ['ok' => true, 'idprova' => $novo, 'prova' => provas_obter($pdo, $novo)]);
        }
        case 'excluir': {
            $ok = provas_excluir($pdo, (int)($d['idprova'] ?? 0), $idu, $admin);
            prv_resp($ok ? 200 : 404, ['ok' => $ok, 'erro' => $ok ? null : 'Prova não encontrada.']);
        }
        case 'foto': {
            $r = provas_foto_adicionar($pdo, (int)($d['idprova'] ?? 0), (string)($d['imagem'] ?? ''), (string)($d['momento'] ?? 'depois'), (string)($d['legenda'] ?? ''), $idu, $admin);
            prv_resp(200, ['ok' => true] + $r + ['prova' => provas_obter($pdo, (int)$d['idprova'])]);
        }
        case 'link': {
            $r = provas_link_adicionar($pdo, (int)($d['idprova'] ?? 0), (string)($d['url'] ?? ''), (string)($d['momento'] ?? 'depois'), (string)($d['legenda'] ?? ''), $idu, $admin);
            prv_resp(200, ['ok' => true] + $r + ['prova' => provas_obter($pdo, (int)$d['idprova'])]);
        }
        case 'midia_excluir': {
            $ok = provas_midia_excluir($pdo, (int)($d['idpm'] ?? 0), $idu, $admin);
            prv_resp($ok ? 200 : 404, ['ok' => $ok, 'erro' => $ok ? null : 'Mídia não encontrada.']);
        }
        case 'video_iniciar': {
            require_once __DIR__ . '/_gdrive.php';
            $idprova = (int)($d['idprova'] ?? 0);
            provas_garantir_tabelas($pdo);
            provas_checar_dono($pdo, $idprova, $idu, $admin);
            $mime = strtolower(preg_replace('/;.*$/', '', trim((string)($d['mime'] ?? ''))));
            if (!in_array($mime, ['video/mp4', 'video/quicktime', 'video/webm'], true)) prv_resp(400, ['ok' => false, 'erro' => 'Formato de vídeo não suportado (use MP4, MOV ou WEBM).']);
            $tamanho = (int)($d['tamanho'] ?? 0);
            if ($tamanho <= 0 || $tamanho > PRV_VIDEO_BYTES_MAX) prv_resp(400, ['ok' => false, 'erro' => 'Vídeo grande demais (máx. ' . (PRV_VIDEO_BYTES_MAX / 1048576) . ' MB). Para vídeo maior, envie por link.']);
            if ((int)($d['duracao_seg'] ?? 0) > PRV_VIDEO_SEG_MAX + 2) prv_resp(400, ['ok' => false, 'erro' => 'Vídeo acima de ' . PRV_VIDEO_SEG_MAX . ' segundos. Para vídeo maior, envie por link.']);
            if (!gdriveDisponivel()) prv_resp(503, ['ok' => false, 'erro' => 'Google Drive não está configurado neste servidor. Use o link do vídeo.']);
            $st = $pdo->prepare("SELECT COUNT(*) FROM intus_prova_midia WHERE idprova = ? AND tipo IN ('video','link')"); $st->execute([$idprova]);
            if ((int)$st->fetchColumn() >= PROVAS_MAX_VIDEOS) prv_resp(400, ['ok' => false, 'erro' => 'Limite de ' . PROVAS_MAX_VIDEOS . ' vídeos por prova.']);
            $pdo->exec("CREATE TABLE IF NOT EXISTS intus_midia (
                idmidia INT AUTO_INCREMENT PRIMARY KEY, dono_tipo VARCHAR(20) NOT NULL, dono_id INT NOT NULL, ordem TINYINT NOT NULL DEFAULT 0,
                tipo VARCHAR(10) NOT NULL DEFAULT 'imagem', url VARCHAR(500) NULL, poster VARCHAR(500) NULL, drive_id VARCHAR(120) NULL,
                token_publico VARCHAR(40) NULL, mime VARCHAR(60) NULL, tamanho BIGINT NULL, duracao_seg INT NULL, status VARCHAR(10) NOT NULL DEFAULT 'pronto',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_dono (dono_tipo, dono_id, ordem), INDEX idx_token (token_publico)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $cols = array_column($pdo->query('SHOW COLUMNS FROM intus_midia')->fetchAll(PDO::FETCH_ASSOC), 'Field');
            if (!in_array('upload_sessao', $cols)) $pdo->exec('ALTER TABLE intus_midia ADD COLUMN upload_sessao TEXT NULL');
            if (!in_array('bytes_enviados', $cols)) $pdo->exec('ALTER TABLE intus_midia ADD COLUMN bytes_enviados BIGINT NOT NULL DEFAULT 0');
            $token = bin2hex(random_bytes(16));
            $ext = $mime === 'video/webm' ? 'webm' : ($mime === 'video/quicktime' ? 'mov' : 'mp4');
            $sessao = gdriveIniciarUploadGrande('provavideo_' . date('Y-m-d_His') . '_' . $idprova . '_' . substr($token, 0, 6) . '.' . $ext, $mime, $tamanho);
            $pdo->prepare("INSERT INTO intus_midia (dono_tipo, dono_id, ordem, tipo, url, token_publico, mime, tamanho, duracao_seg, status, upload_sessao, bytes_enviados) VALUES ('prova', ?, 0, 'video', ?, ?, ?, ?, ?, 'enviando', ?, 0)")
                ->execute([$idprova, '/app/api/midia.php?action=video&t=' . $token, $token, $mime, $tamanho, max(0, (int)($d['duracao_seg'] ?? 0)), $sessao]);
            $idmidia = (int)$pdo->lastInsertId();
            $idpm = provas_video_ligar($pdo, $idprova, $idmidia, null, (string)($d['momento'] ?? 'depois'), (string)($d['legenda'] ?? ''));
            $parte = 4 * 1024 * 1024;
            $lim = (function ($v) { $v = trim((string)$v); $n = (float)$v; $u = strtolower(substr($v, -1)); if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024; return (int)$n; })(ini_get('post_max_size'));
            if ($lim > 0) $parte = min($parte, $lim - 64 * 1024);
            $parte = max(256 * 1024, (int)floor($parte / (256 * 1024)) * 256 * 1024);
            prv_resp(200, ['ok' => true, 'id' => $idmidia, 'idpm' => $idpm, 'parte_bytes' => $parte]);
        }
        case 'video_parte': {
            require_once __DIR__ . '/_gdrive.php';
            $st = $pdo->prepare("SELECT * FROM intus_midia WHERE idmidia = ? AND dono_tipo = 'prova' AND tipo = 'video'"); $st->execute([(int)($_GET['id'] ?? 0)]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) prv_resp(404, ['ok' => false, 'erro' => 'Vídeo não encontrado.']);
            provas_checar_dono($pdo, (int)$r['dono_id'], $idu, $admin);
            if ($r['status'] === 'pronto') prv_resp(200, ['ok' => true, 'recebido' => (int)$r['tamanho'], 'concluido' => true]);
            if ($r['status'] !== 'enviando' || !$r['upload_sessao']) prv_resp(409, ['ok' => false, 'erro' => 'O envio não está aberto.']);
            $inicio = (int)($_GET['inicio'] ?? -1); $esperado = (int)$r['bytes_enviados'];
            if ($inicio !== $esperado) prv_resp(200, ['ok' => true, 'recebido' => $esperado, 'concluido' => false, 'fora_de_ordem' => true]);
            $bin = file_get_contents('php://input');
            if ($bin === '' || $bin === false) prv_resp(400, ['ok' => false, 'erro' => 'Pedaço vazio (limite de envio do servidor?).']);
            $total = (int)$r['tamanho'];
            if ($inicio + strlen($bin) > $total) prv_resp(400, ['ok' => false, 'erro' => 'Pedaço passa do tamanho declarado.']);
            $res = gdriveEnviarParte($r['upload_sessao'], $bin, $inicio, $total);
            if ($res['file_id']) {
                $pdo->prepare("UPDATE intus_midia SET status = 'pronto', bytes_enviados = tamanho, drive_id = ?, upload_sessao = NULL WHERE idmidia = ?")->execute([$res['file_id'], (int)$r['idmidia']]);
                prv_resp(200, ['ok' => true, 'recebido' => $total, 'concluido' => true]);
            }
            $pdo->prepare('UPDATE intus_midia SET bytes_enviados = ? WHERE idmidia = ?')->execute([$res['recebido'], (int)$r['idmidia']]);
            prv_resp(200, ['ok' => true, 'recebido' => $res['recebido'], 'concluido' => false]);
        }
        default:
            prv_resp(400, ['ok' => false, 'erro' => 'Ação inválida.']);
    }
} catch (InvalidArgumentException $e) {
    prv_resp(400, ['ok' => false, 'erro' => $e->getMessage()]);
} catch (RuntimeException $e) {
    prv_resp(strpos($e->getMessage(), 'não encontrada') !== false ? 404 : (strpos($e->getMessage(), 'Sem permissão') === 0 ? 500 : 403), ['ok' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus provas ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    prv_resp(500, ['ok' => false, 'erro' => 'Falha interna (ref ' . $ref . ').']);
}
