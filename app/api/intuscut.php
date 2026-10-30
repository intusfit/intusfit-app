<?php
/**
 * Intus Cut: editor de vídeo da equipe (tela em /intus-cut/). Regra e armazenamento em _intuscut.php.
 *
 * Só equipe com login: administrador ou quem tem a chave cut_ver (Usuários). Aluno recebe 403.
 *   GET  ?action=inicio                       {projetos:[...], biblioteca:[...], ia, drive, limites, eu}
 *   GET  ?action=projeto&id=ID                {projeto}
 *   POST ?action=salvar        {projeto}      grava (id, tamanho e mídia conferidos)
 *   POST ?action=excluir       {id}           tira o projeto (ação do painel, com confirmação na tela)
 *   POST ?action=bib_salvar    {item}         entra ou atualiza na biblioteca
 *   POST ?action=bib_excluir   {id, apagar_arquivo}  tira da biblioteca; com apagar_arquivo, apaga o vídeo do Drive
 *   POST ?action=imagem        {dataurl, nome}       logo ou foto (até 8 MB) em app/img/cut/
 *   POST ?action=midia_iniciar {mime, tamanho, duracao, nome, w, h}   abre o envio ao Google Drive
 *   POST ?action=midia_parte&id=N&inicio=BYTE (corpo = bytes)       um pedaço; responde de onde continuar
 *   POST ?action=drive_iniciar {drive_id, nome, duracao}  vídeo do Drive do Luiz (link compartilhado) para o Drive da Intus
 *   POST ?action=drive_parte&id=N                   copia o próximo pedaço de 16 MB; responde de onde continuar
 *   POST ?action=ia            {pedido}       montagem automática pelo Claude (chave da Central de atendimento)
 * O vídeo toca por midia.php?action=video&t=TOKEN (o mesmo do Feed).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_gdrive.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _cutResp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function _cutErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus cut ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}
// 4 MB por pedaço (ou menos se o post_max_size do servidor for menor); o Drive exige múltiplo de 256 KB. Igual a midia.php.
function _cutTamanhoParte(): int {
    $bytes = function ($v) { $v = trim((string)$v); if ($v === '') return 0; $n = (float)$v; $u = strtolower(substr($v, -1));
        if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024; return (int)$n; };
    $limite = $bytes(ini_get('post_max_size')); $max = 4 * 1024 * 1024;
    if ($limite > 0) $max = min($max, $limite - 64 * 1024);
    $bloco = 256 * 1024;
    return max($bloco, (int)floor($max / $bloco) * $bloco);
}

$cfg = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) {
    _cutResp(500, ['ok' => false, 'erro' => 'falha conexao', 'detalhe' => _cutErro($e)]);
}

require_once __DIR__ . '/_auth_context.php';
require_once __DIR__ . '/_intuscut.php';
if (!function_exists('bearerToken')) {
    function bearerToken() {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
        return preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';
    }
}

try {
    $ctx = getAuthContext($pdo, bearerToken());
    if ((int)($ctx['idusuario'] ?? 0) <= 0) _cutResp(401, ['ok' => false, 'erro' => 'Entre no painel da Intus para usar o Intus Cut.']);
    if (!cut_pode($pdo, $ctx)) _cutResp(403, ['ok' => false, 'erro' => 'Seu usuário não tem acesso ao Intus Cut. Peça ao administrador a permissão "Usar o Intus Cut".']);
    $eu = (int)$ctx['idusuario']; $admin = !empty($ctx['admin']);
    $action = (string)($_GET['action'] ?? '');
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $corpo = function () { return json_decode(file_get_contents('php://input'), true) ?: []; };

    if ($action === 'inicio' && $metodo === 'GET') {
        _cutResp(200, ['ok' => true, 'projetos' => cut_lista($pdo), 'biblioteca' => cut_bib($pdo), 'drive' => gdriveDisponivel(),
            'limites' => ['video_bytes' => CUT_VIDEO_MAX, 'imagem_bytes' => CUT_IMAGEM_MAX, 'ia_dia' => CUT_IA_DIA, 'ia_hoje' => cut_ia_uso_hoje($pdo)],
            'eu' => ['id' => $eu, 'nome' => (string)($ctx['nmusuario'] ?? ''), 'admin' => $admin]]);
    }
    if ($action === 'projeto' && $metodo === 'GET') {
        $p = cut_projeto($pdo, (string)($_GET['id'] ?? ''));
        if (!$p) _cutResp(404, ['ok' => false, 'erro' => 'projeto não encontrado']);
        _cutResp(200, ['ok' => true, 'projeto' => $p]);
    }
    if ($metodo !== 'POST') _cutResp(405, ['ok' => false, 'erro' => 'método inválido']);

    if ($action === 'salvar') { $r = cut_salvar($pdo, $corpo()['projeto'] ?? null, $ctx); _cutResp($r['ok'] ? 200 : 400, $r); }
    if ($action === 'excluir') {
        $id = (string)($corpo()['id'] ?? '');
        _cutResp(cut_excluir($pdo, $id) ? 200 : 404, ['ok' => true]);
    }
    if ($action === 'status') { $r = cut_status($pdo, $corpo(), $ctx); _cutResp($r['ok'] ? 200 : 400, $r); }
    if ($action === 'bib_salvar') { $r = cut_bib_salvar($pdo, $corpo()['item'] ?? null, $ctx); _cutResp($r['ok'] ? 200 : 400, $r); }
    if ($action === 'bib_excluir') {
        $b = $corpo(); $tirado = cut_bib_excluir($pdo, (string)($b['id'] ?? ''));
        if (!$tirado) _cutResp(404, ['ok' => false, 'erro' => 'item não encontrado']);
        $apagou = false; $tok = cut_token_de_url((string)($tirado['url'] ?? ''));
        if (!empty($b['apagar_arquivo']) && $tok !== '') {
            $st = $pdo->prepare("SELECT idmidia, drive_id, dono_id FROM intus_midia WHERE token_publico = ? AND dono_tipo = 'cut' LIMIT 1");
            $st->execute([$tok]); $m = $st->fetch(PDO::FETCH_ASSOC);
            if ($m && ($admin || (int)$m['dono_id'] === $eu)) {
                if (!empty($m['drive_id'])) { try { gdriveExcluirArquivo($m['drive_id']); } catch (Throwable $e) { _cutErro($e); } }
                $pdo->prepare("UPDATE intus_midia SET status = 'excluido' WHERE idmidia = ?")->execute([(int)$m['idmidia']]);
                $apagou = true;
            }
        }
        _cutResp(200, ['ok' => true, 'apagou_arquivo' => $apagou]);
    }
    if ($action === 'imagem') {
        $b = $corpo();
        if (!preg_match('#^data:image/(png|jpe?g|webp|gif);base64,(.+)$#is', (string)($b['dataurl'] ?? ''), $m)) _cutResp(400, ['ok' => false, 'erro' => 'imagem inválida']);
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > CUT_IMAGEM_MAX) _cutResp(400, ['ok' => false, 'erro' => 'imagem grande demais (até 8 MB)']);
        $i = @getimagesizefromstring($bin);
        $mapa = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif']; if (defined('IMAGETYPE_WEBP')) $mapa[IMAGETYPE_WEBP] = 'webp';
        $ext = $i ? ($mapa[$i[2] ?? 0] ?? '') : '';
        if ($ext === '') _cutResp(400, ['ok' => false, 'erro' => 'o arquivo não é uma imagem']);
        require_once __DIR__ . '/_antivirus.php';
        if (intusAntivirus($bin) !== '') _cutResp(400, ['ok' => false, 'erro' => 'arquivo recusado pela verificação de segurança']);
        $dir = __DIR__ . '/../img/cut';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $nome = 'cut_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (@file_put_contents($dir . '/' . $nome, $bin) === false) _cutResp(500, ['ok' => false, 'erro' => 'não consegui guardar a imagem']);
        _cutResp(200, ['ok' => true, 'url' => '/app/img/cut/' . $nome, 'w' => (int)$i[0], 'h' => (int)$i[1]]);
    }

    if (in_array($action, ['midia_iniciar', 'midia_parte', 'drive_iniciar', 'drive_parte'], true)) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_midia (
            idmidia INT AUTO_INCREMENT PRIMARY KEY, dono_tipo VARCHAR(20) NOT NULL, dono_id INT NOT NULL, ordem TINYINT NOT NULL DEFAULT 0,
            tipo VARCHAR(10) NOT NULL DEFAULT 'imagem', url VARCHAR(500) NULL, poster VARCHAR(500) NULL, drive_id VARCHAR(120) NULL,
            token_publico VARCHAR(40) NULL, mime VARCHAR(60) NULL, tamanho BIGINT NULL, duracao_seg INT NULL, status VARCHAR(10) NOT NULL DEFAULT 'pronto',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_dono (dono_tipo, dono_id, ordem), INDEX idx_token (token_publico)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $cols = array_column($pdo->query("SHOW COLUMNS FROM intus_midia")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('upload_sessao', $cols)) $pdo->exec("ALTER TABLE intus_midia ADD COLUMN upload_sessao TEXT NULL");
        if (!in_array('bytes_enviados', $cols)) $pdo->exec("ALTER TABLE intus_midia ADD COLUMN bytes_enviados BIGINT NOT NULL DEFAULT 0");
    }
    if ($action === 'midia_iniciar') {
        $b = $corpo(); $v = cut_validar_envio($b);
        if (is_string($v)) _cutResp(400, ['ok' => false, 'erro' => $v]);
        [$mime, $tam, $dur] = $v;
        if (!gdriveDisponivel()) _cutResp(503, ['ok' => false, 'erro' => 'O Google Drive não está configurado neste servidor.']);
        $token = bin2hex(random_bytes(16));
        $ext = ['video/webm' => 'webm', 'video/quicktime' => 'mov', 'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/webm' => 'webm', 'audio/aac' => 'aac'][$mime] ?? (strpos($mime, 'audio') === 0 ? 'm4a' : 'mp4');
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', mb_substr((string)($b['nome'] ?? 'video'), 0, 40)) ?: 'video';
        $nomeArq = 'intuscut_' . date('Y-m-d_His') . '_' . $eu . '_' . $base . '_' . substr($token, 0, 6) . '.' . $ext;
        $sessao = gdriveIniciarUploadGrande($nomeArq, $mime, $tam);
        $url = '/app/api/midia.php?action=video&t=' . $token;
        // tipo 'video' também para áudio: é o que midia.php aceita para tocar por faixa de bytes.
        $pdo->prepare("INSERT INTO intus_midia (dono_tipo, dono_id, ordem, tipo, url, token_publico, mime, tamanho, duracao_seg, status, upload_sessao, bytes_enviados)
                       VALUES ('cut', ?, 0, 'video', ?, ?, ?, ?, ?, 'enviando', ?, 0)")->execute([$eu, $url, $token, $mime, $tam, (int)round($dur), $sessao]);
        _cutResp(200, ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'token' => $token, 'url' => $url, 'parte_bytes' => _cutTamanhoParte()]);
    }
    if ($action === 'midia_parte') {
        $st = $pdo->prepare("SELECT * FROM intus_midia WHERE idmidia = ? AND dono_tipo = 'cut' LIMIT 1");
        $st->execute([(int)($_GET['id'] ?? 0)]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) _cutResp(404, ['ok' => false, 'erro' => 'envio não encontrado']);
        if ((int)$r['dono_id'] !== $eu) _cutResp(403, ['ok' => false, 'erro' => 'este envio é de outra pessoa']);
        if ($r['status'] === 'pronto') _cutResp(200, ['ok' => true, 'recebido' => (int)$r['tamanho'], 'concluido' => true]);
        if ($r['status'] !== 'enviando' || !$r['upload_sessao']) _cutResp(409, ['ok' => false, 'erro' => 'o envio não está aberto']);
        $inicio = (int)($_GET['inicio'] ?? -1); $esperado = (int)$r['bytes_enviados'];
        if ($inicio !== $esperado) _cutResp(200, ['ok' => true, 'recebido' => $esperado, 'concluido' => false, 'fora_de_ordem' => true]);
        $bin = file_get_contents('php://input');
        if ($bin === '' || $bin === false) _cutResp(400, ['ok' => false, 'erro' => 'pedaço vazio']);
        $total = (int)$r['tamanho'];
        if ($inicio + strlen($bin) > $total) _cutResp(400, ['ok' => false, 'erro' => 'pedaço passa do tamanho declarado']);
        $res = gdriveEnviarParte($r['upload_sessao'], $bin, $inicio, $total);
        if ($res['file_id']) {
            $pdo->prepare("UPDATE intus_midia SET status = 'pronto', bytes_enviados = tamanho, drive_id = ?, upload_sessao = NULL WHERE idmidia = ?")->execute([$res['file_id'], (int)$r['idmidia']]);
            _cutResp(200, ['ok' => true, 'recebido' => $total, 'concluido' => true]);
        }
        $pdo->prepare("UPDATE intus_midia SET bytes_enviados = ? WHERE idmidia = ?")->execute([$res['recebido'], (int)$r['idmidia']]);
        _cutResp(200, ['ok' => true, 'recebido' => $res['recebido'], 'concluido' => false]);
    }
    // Vídeo do Google Drive do Luiz (link compartilhado) copiado para o Drive da Intus; usado pelas edições do Claude.
    if ($action === 'drive_iniciar') {
        $b = $corpo(); $did = (string)($b['drive_id'] ?? '');
        if (!cut_drive_id_ok($did)) _cutResp(400, ['ok' => false, 'erro' => 'link do Drive inválido']);
        $ja = cut_drive_map($pdo)[$did] ?? 0;
        if ($ja) {
            $st = $pdo->prepare("SELECT * FROM intus_midia WHERE idmidia = ? AND dono_tipo = 'cut' LIMIT 1"); $st->execute([(int)$ja]); $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['status'] === 'pronto') _cutResp(200, ['ok' => true, 'id' => (int)$r['idmidia'], 'url' => $r['url'], 'total' => (int)$r['tamanho'], 'recebido' => (int)$r['tamanho'], 'concluido' => true]);
            if ($r && $r['status'] === 'enviando' && $r['upload_sessao']) _cutResp(200, ['ok' => true, 'id' => (int)$r['idmidia'], 'url' => $r['url'], 'total' => (int)$r['tamanho'], 'recebido' => (int)$r['bytes_enviados'], 'concluido' => false]);
        }
        if (!gdriveDisponivel()) _cutResp(503, ['ok' => false, 'erro' => 'O Google Drive não está configurado neste servidor.']);
        $v = cut_drive_sondar($did);
        if (is_string($v)) _cutResp(400, ['ok' => false, 'erro' => $v]);
        [$mime, $tam] = $v;
        $token = bin2hex(random_bytes(16));
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', mb_substr((string)($b['nome'] ?? 'video'), 0, 40)) ?: 'video';
        $nomeArq = 'intuscut_' . date('Y-m-d_His') . '_' . $eu . '_' . $base . '_' . substr($token, 0, 6) . '.' . (['video/quicktime' => 'mov', 'video/webm' => 'webm'][$mime] ?? 'mp4');
        $sessao = gdriveIniciarUploadGrande($nomeArq, $mime, $tam);
        $url = '/app/api/midia.php?action=video&t=' . $token;
        $pdo->prepare("INSERT INTO intus_midia (dono_tipo, dono_id, ordem, tipo, url, token_publico, mime, tamanho, duracao_seg, status, upload_sessao, bytes_enviados)
                       VALUES ('cut', ?, 0, 'video', ?, ?, ?, ?, ?, 'enviando', ?, 0)")->execute([$eu, $url, $token, $mime, $tam, (int)round((float)($b['duracao'] ?? 0)), $sessao]);
        $idm = (int)$pdo->lastInsertId();
        cut_drive_map_gravar($pdo, $did, $idm);
        _cutResp(200, ['ok' => true, 'id' => $idm, 'url' => $url, 'total' => $tam, 'recebido' => 0, 'concluido' => false]);
    }
    if ($action === 'drive_parte') {
        @set_time_limit(280);
        $idm = (int)($_GET['id'] ?? 0);
        $did = array_search($idm, cut_drive_map($pdo), true);
        if ($did === false) _cutResp(404, ['ok' => false, 'erro' => 'envio não encontrado']);
        $st = $pdo->prepare("SELECT * FROM intus_midia WHERE idmidia = ? AND dono_tipo = 'cut' LIMIT 1"); $st->execute([$idm]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) _cutResp(404, ['ok' => false, 'erro' => 'envio não encontrado']);
        if ($r['status'] === 'pronto') _cutResp(200, ['ok' => true, 'recebido' => (int)$r['tamanho'], 'concluido' => true]);
        if ($r['status'] !== 'enviando' || !$r['upload_sessao']) _cutResp(409, ['ok' => false, 'erro' => 'o envio não está aberto']);
        $ini = (int)$r['bytes_enviados']; $total = (int)$r['tamanho']; $fim = min($total, $ini + CUT_DRIVE_PARTE) - 1;
        $f = cut_drive_faixa((string)$did, $ini, $fim);
        if ($f['status'] !== 206 || strlen($f['corpo']) !== $fim - $ini + 1) _cutResp(502, ['ok' => false, 'erro' => 'o Drive de origem não entregou o pedaço (HTTP ' . $f['status'] . ')']);
        $res = gdriveEnviarParte($r['upload_sessao'], $f['corpo'], $ini, $total);
        if ($res['file_id']) {
            $pdo->prepare("UPDATE intus_midia SET status = 'pronto', bytes_enviados = tamanho, drive_id = ?, upload_sessao = NULL WHERE idmidia = ?")->execute([$res['file_id'], $idm]);
            _cutResp(200, ['ok' => true, 'recebido' => $total, 'concluido' => true]);
        }
        $pdo->prepare("UPDATE intus_midia SET bytes_enviados = ? WHERE idmidia = ?")->execute([$res['recebido'], $idm]);
        _cutResp(200, ['ok' => true, 'recebido' => $res['recebido'], 'concluido' => false]);
    }
    if ($action === 'ia') {
        require_once __DIR__ . '/_wa.php';
        require_once __DIR__ . '/_atendimento.php';
        @set_time_limit(140);
        $c = $corpo();
        $r = cut_ia($pdo, (string)($c['pedido'] ?? ''), null, $c['imagens'] ?? []);
        _cutResp($r['ok'] ? 200 : 400, $r);
    }
    _cutResp(400, ['ok' => false, 'erro' => 'ação inválida']);
} catch (Throwable $e) {
    _cutResp(500, ['ok' => false, 'erro' => 'falha interna', 'detalhe' => _cutErro($e)]);
}
