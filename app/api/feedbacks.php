<?php
/**
 * Intus Fit — Feedbacks em vídeo (gravação de tela estilo Loom)
 *
 * O professor grava a tela/câmera em /feedback-video/ comentando foto ou vídeo do
 * aluno; o vídeo vai para o Google Drive (mesma conta de serviço e pasta das fotos
 * de avaliação, ver _gdrive.php) e o aluno assiste por um link secreto
 * (/feedback/?v=<token>), que o professor manda pelo WhatsApp.
 *
 * O vídeo NUNCA fica no disco da KingHost nem em coluna do banco: o navegador manda
 * pedaços de poucos MB (action=enviar_parte), e cada pedaço é repassado na hora para
 * uma sessão de upload retomável do Drive. Para tocar, action=video lê do Drive por
 * faixa de bytes (o player do navegador pede aos poucos) — nada de arquivo gigante
 * passando de uma vez pela hospedagem compartilhada.
 *
 * Tabela pensada para, depois, virar a área "Feedbacks" no perfil do aluno (app e
 * painel): cada linha já tem idatleta, e as rotas listar?idatleta= (professor) e
 * meus (aluno logado) já existem.
 *
 * Rotas:
 *   PÚBLICAS (sem login — quem tem o token do link):
 *     GET  ?action=ver&v=TOKEN                  -> {ok, feedback:{titulo, descricao, aluno_nome, professor_nome, criado_em, duracao_seg, video_url}}
 *     GET  ?action=video&v=TOKEN                -> bytes do vídeo (aceita Range)
 *   ALUNO LOGADO (Bearer de aluno):
 *     GET  ?action=meus                         -> feedbacks prontos do próprio aluno
 *   PROFESSOR (Bearer de professor, mesmo login do painel):
 *     GET  ?action=eu
 *     GET  ?action=listar[&idatleta=N]          -> sem idatleta: os do professor (admin: todos); com idatleta: todos daquele aluno
 *     POST ?action=iniciar  {idatleta, aluno_nome, titulo, descricao, mime, tamanho, duracao_seg} -> {ok, id, parte_bytes}
 *     POST ?action=enviar_parte&id=N&inicio=BYTE (corpo = bytes crus do pedaço) -> {ok, recebido, concluido, link?}
 *     POST ?action=excluir  {id}                -> {ok, aviso?}
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_charset_fix.php';
require_once __DIR__ . '/_gdrive.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, Range');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

define('FB_DURACAO_MAX_SEG', 600);            // 10 min (decisão do Luiz, 25/09/2026)
define('FB_TAMANHO_MAX', 250 * 1024 * 1024);  // folga para 10 min mesmo em navegador que ignora o bitrate pedido
define('FB_FAIXA_VIDEO', 4 * 1024 * 1024);    // quanto o player recebe por pedido

function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus feedbacks ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

function _fbResponder($codigo, $dados) {
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

// Tamanho de cada pedaço do upload: 4 MB, ou menos se o post_max_size do servidor for
// menor. O Drive exige múltiplo de 256 KB (menos no último pedaço).
function _fbTamanhoParte() {
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

function _fbLinkPublico($token) {
    return 'https://intusfit.com.br/feedback/?v=' . $token;
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
    _fbResponder(500, ['ok' => false, 'erro' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
}

// ---------- Tabela ----------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_feedback (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        tipo            VARCHAR(20) NOT NULL DEFAULT 'video',
        idprofessor     INT NOT NULL,
        professor_nome  VARCHAR(150) NOT NULL DEFAULT '',
        idatleta        INT NOT NULL,
        aluno_nome      VARCHAR(150) NOT NULL DEFAULT '',
        titulo          VARCHAR(150) NOT NULL DEFAULT '',
        descricao       VARCHAR(1000) NOT NULL DEFAULT '',
        token_publico   CHAR(32) NOT NULL,
        status          VARCHAR(20) NOT NULL DEFAULT 'enviando',
        mime            VARCHAR(80) NOT NULL DEFAULT 'video/webm',
        tamanho_bytes   BIGINT NOT NULL DEFAULT 0,
        duracao_seg     INT NOT NULL DEFAULT 0,
        bytes_enviados  BIGINT NOT NULL DEFAULT 0,
        upload_sessao   TEXT NULL,
        drive_file_id   VARCHAR(120) NULL,
        visto_em        DATETIME NULL,
        criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token (token_publico),
        INDEX idx_professor (idprofessor),
        INDEX idx_atleta (idatleta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
_intusGarantirUtf8mb4($pdo, 'intus_feedback', ['professor_nome', 'aluno_nome', 'titulo', 'descricao']);

function _fbPorToken(PDO $pdo, $token) {
    if (!preg_match('/^[0-9a-f]{32}$/', (string)$token)) return null;
    $st = $pdo->prepare("SELECT * FROM intus_feedback WHERE token_publico = ? AND status = 'pronto' LIMIT 1");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function _fbResumo(array $r, $comLink) {
    $o = [
        'id' => (int)$r['id'], 'tipo' => $r['tipo'], 'idatleta' => (int)$r['idatleta'], 'aluno_nome' => $r['aluno_nome'],
        'idprofessor' => (int)$r['idprofessor'], 'professor_nome' => $r['professor_nome'],
        'titulo' => $r['titulo'], 'descricao' => $r['descricao'], 'status' => $r['status'],
        'duracao_seg' => (int)$r['duracao_seg'], 'tamanho_bytes' => (int)$r['tamanho_bytes'],
        'visto_em' => $r['visto_em'], 'criado_em' => $r['criado_em'],
    ];
    if ($comLink && $r['status'] === 'pronto') {
        $o['link'] = _fbLinkPublico($r['token_publico']);
        $o['video_url'] = '/app/api/feedbacks.php?action=video&v=' . $r['token_publico'];
    }
    return $o;
}

$action = $_GET['action'] ?? '';

try {

    // ======================= PÚBLICAS (link do WhatsApp) =======================

    if ($action === 'ver') {
        $r = _fbPorToken($pdo, $_GET['v'] ?? '');
        if (!$r) _fbResponder(404, ['ok' => false, 'erro' => 'Este feedback não existe mais ou o link está incompleto.']);
        // Professor conferindo o próprio link (mesmo navegador do painel manda o token)
        // não conta como "aluno assistiu".
        $ehEquipe = false;
        if (bearerToken() !== '') {
            require_once __DIR__ . '/_auth_context.php';
            $c = getAuthContext($pdo, bearerToken());
            $ehEquipe = (int)($c['idusuario'] ?? 0) > 0 && empty($c['is_aluno']);
        }
        if (!$r['visto_em'] && !$ehEquipe) $pdo->prepare("UPDATE intus_feedback SET visto_em = NOW() WHERE id = ?")->execute([(int)$r['id']]);
        $partes = preg_split('/\s+/', trim($r['aluno_nome']));
        echo json_encode(['ok' => true, 'feedback' => [
            'titulo' => $r['titulo'], 'descricao' => $r['descricao'],
            // Link pode ser repassado: expõe só o primeiro nome do aluno, nunca id/e-mail.
            'aluno_nome' => $partes[0] ?? '', 'professor_nome' => $r['professor_nome'],
            'criado_em' => $r['criado_em'], 'duracao_seg' => (int)$r['duracao_seg'], 'mime' => $r['mime'],
            'video_url' => '/app/api/feedbacks.php?action=video&v=' . $r['token_publico'],
        ]]);
        exit;
    }

    if ($action === 'video') {
        $r = _fbPorToken($pdo, $_GET['v'] ?? '');
        if (!$r || !$r['drive_file_id']) _fbResponder(404, ['ok' => false, 'erro' => 'nao encontrado']);
        $total = (int)$r['tamanho_bytes'];
        $ini = 0; $fim = $total - 1;
        if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
            if ($m[1] === '' && $m[2] !== '') { $ini = max(0, $total - (int)$m[2]); }   // "bytes=-500" = últimos 500
            else { $ini = (int)$m[1]; if ($m[2] !== '') $fim = min($fim, (int)$m[2]); }
        }
        if ($ini >= $total || $ini > $fim) {
            header('Content-Range: bytes */' . $total);
            _fbResponder(416, ['ok' => false]);
        }
        // Cada resposta leva no máximo 4 MB; o player pede o resto sozinho.
        $fim = min($fim, $ini + FB_FAIXA_VIDEO - 1);
        $f = gdriveLerFaixa($r['drive_file_id'], $ini, $fim);
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

    // ======================= COM LOGIN =======================

    require_once __DIR__ . '/_auth_context.php';
    $ctx = getAuthContext($pdo, bearerToken());

    if ($action === 'meus') {
        if (empty($ctx['is_aluno']) || (int)($ctx['idatleta'] ?? 0) <= 0) _fbResponder(401, ['ok' => false, 'erro' => 'sem sessao de aluno']);
        $st = $pdo->prepare("SELECT * FROM intus_feedback WHERE idatleta = ? AND status = 'pronto' ORDER BY criado_em DESC LIMIT 200");
        $st->execute([(int)$ctx['idatleta']]);
        echo json_encode(['ok' => true, 'feedbacks' => array_map(function ($r) { return _fbResumo($r, true); }, $st->fetchAll(PDO::FETCH_ASSOC))]);
        exit;
    }

    if ((int)($ctx['idusuario'] ?? 0) <= 0 || !empty($ctx['is_aluno'])) {
        _fbResponder(401, ['ok' => false, 'erro' => 'sem sessao valida de professor']);
    }
    $idprofessor = (int)$ctx['idusuario'];
    $ehAdmin = !empty($ctx['admin']);

    // Professor não-admin só grava/vê feedback de aluno da própria carteira
    // (mesma regra de atletas.php/treinos.php).
    $podeVerAluno = function ($idatleta) use ($pdo, $idprofessor, $ehAdmin) {
        if ($ehAdmin) return true;
        return in_array((int)$idatleta, getAtletasDoUsuario($pdo, $idprofessor), true);
    };
    $carregarDoDono = function ($id) use ($pdo, $idprofessor, $ehAdmin) {
        $st = $pdo->prepare("SELECT * FROM intus_feedback WHERE id = ?");
        $st->execute([(int)$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) _fbResponder(404, ['ok' => false, 'erro' => 'nao encontrado']);
        if (!$ehAdmin && (int)$r['idprofessor'] !== $idprofessor) _fbResponder(403, ['ok' => false, 'erro' => 'sem permissao']);
        return $r;
    };
    $exigirPost = function () {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') _fbResponder(405, ['ok' => false, 'erro' => 'metodo invalido']);
    };

    if ($action === 'eu') {
        echo json_encode(['ok' => true, 'idprofessor' => $idprofessor, 'admin' => $ehAdmin, 'nome' => $ctx['nmusuario'] ?? '',
            'drive_ok' => gdriveDisponivel(), 'duracao_max_seg' => FB_DURACAO_MAX_SEG]);
        exit;
    }

    if ($action === 'listar') {
        $idatleta = (int)($_GET['idatleta'] ?? 0);
        if ($idatleta > 0) {
            if (!$podeVerAluno($idatleta)) _fbResponder(403, ['ok' => false, 'erro' => 'aluno fora da sua carteira']);
            $st = $pdo->prepare("SELECT * FROM intus_feedback WHERE idatleta = ? AND status = 'pronto' ORDER BY criado_em DESC LIMIT 200");
            $st->execute([$idatleta]);
        } elseif ($ehAdmin) {
            $st = $pdo->query("SELECT * FROM intus_feedback ORDER BY criado_em DESC LIMIT 200");
        } else {
            $st = $pdo->prepare("SELECT * FROM intus_feedback WHERE idprofessor = ? ORDER BY criado_em DESC LIMIT 200");
            $st->execute([$idprofessor]);
        }
        $lista = array_map(function ($r) { return _fbResumo($r, true); }, $st->fetchAll(PDO::FETCH_ASSOC));
        $total = 0; foreach ($lista as $f) if ($f['status'] === 'pronto') $total += $f['tamanho_bytes'];
        echo json_encode(['ok' => true, 'feedbacks' => $lista, 'espaco_bytes' => $total]);
        exit;
    }

    if ($action === 'iniciar') {
        $exigirPost();
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $idatleta = (int)($b['idatleta'] ?? 0);
        if ($idatleta <= 0) _fbResponder(400, ['ok' => false, 'erro' => 'Escolha o aluno na lista de busca.']);
        if (!$podeVerAluno($idatleta)) _fbResponder(403, ['ok' => false, 'erro' => 'Esse aluno não está na sua carteira.']);
        $titulo = mb_substr(trim((string)($b['titulo'] ?? '')), 0, 150);
        if ($titulo === '') _fbResponder(400, ['ok' => false, 'erro' => 'Dê um título ao feedback.']);
        $mime = strtolower(trim((string)($b['mime'] ?? '')));
        $mime = preg_replace('/;.*$/', '', $mime);
        if (!in_array($mime, ['video/webm', 'video/mp4'], true)) _fbResponder(400, ['ok' => false, 'erro' => 'formato de video nao suportado']);
        $tamanho = (int)($b['tamanho'] ?? 0);
        if ($tamanho <= 0 || $tamanho > FB_TAMANHO_MAX) _fbResponder(400, ['ok' => false, 'erro' => 'Vídeo grande demais (máx. ' . round(FB_TAMANHO_MAX / 1048576) . ' MB).']);
        $duracao = (int)($b['duracao_seg'] ?? 0);
        if ($duracao > FB_DURACAO_MAX_SEG + 5) _fbResponder(400, ['ok' => false, 'erro' => 'Gravação acima de 10 minutos.']);
        if (!gdriveDisponivel()) _fbResponder(503, ['ok' => false, 'erro' => 'Google Drive não está configurado neste servidor.']);

        $alunoNome = mb_substr(trim((string)($b['aluno_nome'] ?? '')), 0, 150);
        $token = bin2hex(random_bytes(16));
        $ext = $mime === 'video/mp4' ? 'mp4' : 'webm';
        $nomeArq = 'feedback_' . date('Y-m-d_His') . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $alunoNome) ?: 'aluno') . '.' . $ext;
        $sessao = gdriveIniciarUploadGrande($nomeArq, $mime, $tamanho);

        $st = $pdo->prepare("INSERT INTO intus_feedback (tipo, idprofessor, professor_nome, idatleta, aluno_nome, titulo, descricao, token_publico, status, mime, tamanho_bytes, duracao_seg, upload_sessao)
                             VALUES ('video', ?, ?, ?, ?, ?, ?, ?, 'enviando', ?, ?, ?, ?)");
        $st->execute([$idprofessor, mb_substr((string)($ctx['nmusuario'] ?? ''), 0, 150), $idatleta, $alunoNome, $titulo,
            mb_substr(trim((string)($b['descricao'] ?? '')), 0, 1000), $token, $mime, $tamanho, max(0, $duracao), $sessao]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'parte_bytes' => _fbTamanhoParte()]);
        exit;
    }

    if ($action === 'enviar_parte') {
        $exigirPost();
        $r = $carregarDoDono($_GET['id'] ?? 0);
        if ($r['status'] === 'pronto') _fbResponder(200, ['ok' => true, 'recebido' => (int)$r['tamanho_bytes'], 'concluido' => true, 'link' => _fbLinkPublico($r['token_publico'])]);
        if ($r['status'] !== 'enviando' || !$r['upload_sessao']) _fbResponder(409, ['ok' => false, 'erro' => 'upload nao esta aberto']);
        $inicio = (int)($_GET['inicio'] ?? -1);
        $esperado = (int)$r['bytes_enviados'];
        // Pedaço fora de ordem (ex.: reenvio depois de falha de rede): diz de onde continuar.
        if ($inicio !== $esperado) _fbResponder(200, ['ok' => true, 'recebido' => $esperado, 'concluido' => false, 'fora_de_ordem' => true]);
        $bin = file_get_contents('php://input');
        if ($bin === '' || $bin === false) _fbResponder(400, ['ok' => false, 'erro' => 'pedaco vazio (limite de upload do servidor?)']);
        $total = (int)$r['tamanho_bytes'];
        if ($inicio + strlen($bin) > $total) _fbResponder(400, ['ok' => false, 'erro' => 'pedaco passa do tamanho declarado']);

        $res = gdriveEnviarParte($r['upload_sessao'], $bin, $inicio, $total);
        if ($res['file_id']) {
            $pdo->prepare("UPDATE intus_feedback SET status = 'pronto', bytes_enviados = tamanho_bytes, drive_file_id = ?, upload_sessao = NULL WHERE id = ?")
                ->execute([$res['file_id'], (int)$r['id']]);
            echo json_encode(['ok' => true, 'recebido' => $total, 'concluido' => true, 'link' => _fbLinkPublico($r['token_publico'])]);
            exit;
        }
        $pdo->prepare("UPDATE intus_feedback SET bytes_enviados = ? WHERE id = ?")->execute([$res['recebido'], (int)$r['id']]);
        echo json_encode(['ok' => true, 'recebido' => $res['recebido'], 'concluido' => false]);
        exit;
    }

    if ($action === 'excluir') {
        $exigirPost();
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        $r = $carregarDoDono($b['id'] ?? 0);
        $aviso = null;
        if ($r['drive_file_id']) {
            $apagou = false;
            try { $apagou = gdriveExcluirArquivo($r['drive_file_id']); } catch (Throwable $e) { _intusLogErro($e); }
            // O link morre de qualquer jeito (a linha sai do banco); só avisa que sobrou arquivo no Drive.
            if (!$apagou) $aviso = 'O link já não funciona, mas não consegui apagar o arquivo no Google Drive. Procure por "feedback_" na pasta e apague manualmente.';
        }
        $pdo->prepare("DELETE FROM intus_feedback WHERE id = ?")->execute([(int)$r['id']]);
        echo json_encode(['ok' => true, 'aviso' => $aviso]);
        exit;
    }

    _fbResponder(400, ['ok' => false, 'erro' => 'acao invalida']);

} catch (Throwable $e) {
    _fbResponder(500, ['ok' => false, 'erro' => 'falha interna: ' . $e->getMessage(), 'detalhe' => _intusLogErro($e)]);
}
