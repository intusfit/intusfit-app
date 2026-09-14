<?php
/**
 * Intus Fit — Relatórios de avaliação por fotos (armazenamento server-side)
 *
 * Guarda os relatórios criados na ferramenta /relatorios-fotos/: até 20 linhas de
 * comparativo "antes/depois" por relatório, cada lado com sua própria foto e o
 * ajuste de recorte (zoom + posição) já feito pelo professor, para poder reabrir
 * e continuar editando depois.
 *
 * Fotos são salvas como ARQUIVO em app/img/relatorios-fotos/<id>/, nunca como
 * base64 dentro do banco — mesmo motivo do midia_upload em catalogo.php: base64
 * em coluna estoura limite do MySQL/PHP e falha calado.
 *
 * Autenticação: exige a MESMA sessão de professor do painel (Bearer token
 * validado contra intus_sessions via _auth_context.php). Sem token válido de
 * professor, nenhuma ação funciona — essas fotos são dado sensível de aluno.
 *
 * Rotas:
 *   GET  ?action=listar                 -> relatórios do professor logado (ou todos, se admin)
 *   GET  ?action=obter&id=N             -> relatório completo, com URL das fotos e recorte salvo
 *   POST ?action=criar        {aluno_nome, bg, marca}                    -> {ok, id}
 *   POST ?action=salvar_meta  {id, aluno_nome, bg, marca}                -> {ok}
 *   POST ?action=salvar_linha {relatorio_id, linha_idx, label, largura, altura,
 *                               esquerda:{tag,zoom,pan_x,pan_y,data?,remover?},
 *                               direita:{tag,zoom,pan_x,pan_y,data?,remover?}} -> {ok, esquerda_url?, direita_url?}
 *   POST ?action=excluir      {id}                                       -> {ok}
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_charset_fix.php';
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

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

function _appBaseUrl() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/app/api/relatorios_fotos.php';
    $b = rtrim(dirname(dirname($script)), '/'); // /app/api/x.php -> /app
    if ($b === '' || $b === '.' || $b === '/') $b = '/app';
    return $b;
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
    echo json_encode(['ok' => false, 'erro' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Auth: exige sessao de PROFESSOR (mesma do painel) ----------
require_once __DIR__ . '/_auth_context.php';
$tok = bearerToken();
$ctx = getAuthContext($pdo, $tok);
if ((int)($ctx['idusuario'] ?? 0) <= 0 || !empty($ctx['is_aluno'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'sem sessao valida de professor']);
    exit;
}
$idprofessor = (int)$ctx['idusuario'];
$ehAdmin = !empty($ctx['admin']);

// ---------- Tabelas ----------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_relatorio_fotos (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        idprofessor   INT NOT NULL,
        idatleta      INT NULL,
        aluno_nome    VARCHAR(150) NOT NULL DEFAULT '',
        bg            VARCHAR(10) NOT NULL DEFAULT 'escuro',
        marca         TINYINT(1) NOT NULL DEFAULT 1,
        criado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_professor (idprofessor)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Coluna nova em tabela que pode ja existir de uma versao anterior desta ferramenta
// (MySQL 5.6 nao tem "ADD COLUMN IF NOT EXISTS" — confere no schema antes de alterar).
try {
    $temColuna = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'intus_relatorio_fotos' AND COLUMN_NAME = 'idatleta'")->fetchColumn();
    if (!$temColuna) $pdo->exec("ALTER TABLE intus_relatorio_fotos ADD COLUMN idatleta INT NULL AFTER idprofessor");
} catch (Throwable $e) { @error_log('[intus relatorios_fotos] falha ao garantir coluna idatleta: ' . $e->getMessage()); }
$pdo->exec("
    CREATE TABLE IF NOT EXISTS intus_relatorio_fotos_linha (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        relatorio_id     INT NOT NULL,
        linha_idx        TINYINT UNSIGNED NOT NULL,
        label            VARCHAR(120) NOT NULL DEFAULT '',
        largura          INT NOT NULL DEFAULT 900,
        altura           INT NOT NULL DEFAULT 1200,
        esquerda_tag     VARCHAR(60) NOT NULL DEFAULT 'Antes',
        esquerda_arquivo VARCHAR(190) DEFAULT NULL,
        esquerda_zoom    DECIMAL(4,2) NOT NULL DEFAULT 1.00,
        esquerda_pan_x   DECIMAL(6,5) NOT NULL DEFAULT 0.50000,
        esquerda_pan_y   DECIMAL(6,5) NOT NULL DEFAULT 0.50000,
        direita_tag      VARCHAR(60) NOT NULL DEFAULT 'Depois',
        direita_arquivo  VARCHAR(190) DEFAULT NULL,
        direita_zoom     DECIMAL(4,2) NOT NULL DEFAULT 1.00,
        direita_pan_x    DECIMAL(6,5) NOT NULL DEFAULT 0.50000,
        direita_pan_y    DECIMAL(6,5) NOT NULL DEFAULT 0.50000,
        UNIQUE KEY uniq_linha (relatorio_id, linha_idx),
        INDEX idx_relatorio (relatorio_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
_intusGarantirUtf8mb4($pdo, 'intus_relatorio_fotos', ['aluno_nome']);
_intusGarantirUtf8mb4($pdo, 'intus_relatorio_fotos_linha', ['label']);

// Colunas adicionadas depois da primeira versao (grade de simetrografo, anotacoes
// desenhadas e comentario por lado). Mesmo motivo do idatleta acima: MySQL 5.6 nao
// tem "ADD COLUMN IF NOT EXISTS", entao confere no schema antes de alterar.
function _garantirColuna(PDO $pdo, string $tabela, string $coluna, string $definicao): void {
    try {
        $existe = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $existe->execute([$tabela, $coluna]);
        if (!$existe->fetchColumn()) $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `$coluna` $definicao");
    } catch (Throwable $e) { @error_log("[intus relatorios_fotos] falha ao garantir coluna $tabela.$coluna: " . $e->getMessage()); }
}
foreach (['esquerda', 'direita'] as $_lado) {
    _garantirColuna($pdo, 'intus_relatorio_fotos_linha', $_lado . '_grade', "TINYINT(1) NOT NULL DEFAULT 0");
    _garantirColuna($pdo, 'intus_relatorio_fotos_linha', $_lado . '_anotacoes', "MEDIUMTEXT NULL");
    _garantirColuna($pdo, 'intus_relatorio_fotos_linha', $_lado . '_comentario', "VARCHAR(500) NOT NULL DEFAULT ''");
    // Divisoes do simetrografo (numero de linhas horizontais/verticais), por foto —
    // cada lado pode precisar de uma densidade de grade diferente (ex.: frente vs perfil).
    _garantirColuna($pdo, 'intus_relatorio_fotos_linha', $_lado . '_grade_h', "TINYINT UNSIGNED NOT NULL DEFAULT 10");
    _garantirColuna($pdo, 'intus_relatorio_fotos_linha', $_lado . '_grade_v', "TINYINT UNSIGNED NOT NULL DEFAULT 2");
}
_intusGarantirUtf8mb4($pdo, 'intus_relatorio_fotos_linha', ['esquerda_comentario', 'direita_comentario']);

// Colunas grade_h/grade_v em intus_relatorio_fotos (versao anterior, por relatorio inteiro) ficam
// paradas sem uso — a densidade da grade virou ajuste por foto (esquerda_grade_h/v, direita_grade_h/v
// acima). Nao removidas por seguranca (nao apaga coluna com dado que ja possa existir).

// ---------- Helpers de arquivo ----------
function _dirRelatorio($id) {
    $dir = __DIR__ . '/../img/relatorios-fotos/' . (int)$id;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function _salvarFotoLado($relatorioId, $linhaIdx, $lado, $dataUrl) {
    if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#is', $dataUrl, $m)) {
        throw new Exception('formato de imagem invalido (use PNG, JPG ou WEBP)');
    }
    $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
    $bin = base64_decode($m[2], true);
    if ($bin === false) throw new Exception('base64 invalido');
    if (strlen($bin) > 8 * 1024 * 1024) throw new Exception('imagem grande demais (max 8MB apos compressao)');
    $dir = _dirRelatorio($relatorioId);
    if (!is_writable($dir)) throw new Exception('pasta de upload indisponivel');
    $fname = (int)$linhaIdx . '-' . $lado . '.' . $ext;
    // remove versao anterior com outra extensao, se houver (evita arquivo orfao)
    foreach (['jpg', 'png', 'webp'] as $e) {
        if ($e !== $ext) @unlink($dir . '/' . $linhaIdx . '-' . $lado . '.' . $e);
    }
    if (@file_put_contents($dir . '/' . $fname, $bin) === false) throw new Exception('falha ao gravar arquivo');
    return $fname;
}

function _removerFotoLado($relatorioId, $linhaIdx, $lado) {
    $dir = __DIR__ . '/../img/relatorios-fotos/' . (int)$relatorioId;
    foreach (['jpg', 'png', 'webp'] as $e) @unlink($dir . '/' . $linhaIdx . '-' . $lado . '.' . $e);
}

function _urlFoto($relatorioId, $arquivo) {
    if (!$arquivo) return null;
    return _appBaseUrl() . '/img/relatorios-fotos/' . (int)$relatorioId . '/' . $arquivo;
}

// Confere que o relatorio existe e pertence a este professor (ou o professor e admin).
function _carregarRelatorioDoDono(PDO $pdo, $id, $idprofessor, $ehAdmin) {
    $st = $pdo->prepare("SELECT * FROM intus_relatorio_fotos WHERE id = ?");
    $st->execute([(int)$id]);
    $rel = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rel) return null;
    if (!$ehAdmin && (int)$rel['idprofessor'] !== (int)$idprofessor) return false; // existe, mas nao e dono
    return $rel;
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {

    if ($action === 'eu') {
        echo json_encode(['ok' => true, 'idprofessor' => $idprofessor, 'admin' => $ehAdmin, 'nome' => $ctx['nmusuario'] ?? '']);
        exit;
    }

    if ($action === 'listar') {
        if ($ehAdmin) {
            $st = $pdo->query("SELECT id, idatleta, aluno_nome, criado_em, atualizado_em FROM intus_relatorio_fotos ORDER BY atualizado_em DESC LIMIT 200");
            $lista = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $pdo->prepare("SELECT id, idatleta, aluno_nome, criado_em, atualizado_em FROM intus_relatorio_fotos WHERE idprofessor = ? ORDER BY atualizado_em DESC LIMIT 200");
            $st->execute([$idprofessor]);
            $lista = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($lista as &$r) {
            $r['id'] = (int)$r['id'];
            $stf = $pdo->prepare("SELECT esquerda_arquivo, direita_arquivo FROM intus_relatorio_fotos_linha WHERE relatorio_id = ? AND (esquerda_arquivo IS NOT NULL OR direita_arquivo IS NOT NULL) ORDER BY linha_idx ASC LIMIT 1");
            $stf->execute([$r['id']]);
            $foto = $stf->fetch(PDO::FETCH_ASSOC);
            $arq = $foto ? ($foto['esquerda_arquivo'] ?: $foto['direita_arquivo']) : null;
            $r['thumb_url'] = $arq ? _urlFoto($r['id'], $arq) : null;
        }
        echo json_encode(['ok' => true, 'relatorios' => $lista]);
        exit;
    }

    if ($action === 'obter') {
        $rel = _carregarRelatorioDoDono($pdo, $_GET['id'] ?? 0, $idprofessor, $ehAdmin);
        if ($rel === null) { http_response_code(404); echo json_encode(['ok' => false, 'erro' => 'nao encontrado']); exit; }
        if ($rel === false) { http_response_code(403); echo json_encode(['ok' => false, 'erro' => 'sem permissao']); exit; }
        $id = (int)$rel['id'];
        $st = $pdo->prepare("SELECT * FROM intus_relatorio_fotos_linha WHERE relatorio_id = ? ORDER BY linha_idx ASC");
        $st->execute([$id]);
        $linhas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $linhas[] = [
                'linha_idx' => (int)$l['linha_idx'],
                'label' => $l['label'],
                'largura' => (int)$l['largura'],
                'altura' => (int)$l['altura'],
                'esquerda' => [
                    'tag' => $l['esquerda_tag'], 'url' => _urlFoto($id, $l['esquerda_arquivo']),
                    'zoom' => (float)$l['esquerda_zoom'], 'pan_x' => (float)$l['esquerda_pan_x'], 'pan_y' => (float)$l['esquerda_pan_y'],
                    'grade' => !empty($l['esquerda_grade']), 'grade_h' => (int)($l['esquerda_grade_h'] ?? 10), 'grade_v' => (int)($l['esquerda_grade_v'] ?? 2),
                    'comentario' => $l['esquerda_comentario'] ?? '',
                    'anotacoes' => $l['esquerda_anotacoes'] ? (json_decode($l['esquerda_anotacoes'], true) ?: []) : [],
                ],
                'direita' => [
                    'tag' => $l['direita_tag'], 'url' => _urlFoto($id, $l['direita_arquivo']),
                    'zoom' => (float)$l['direita_zoom'], 'pan_x' => (float)$l['direita_pan_x'], 'pan_y' => (float)$l['direita_pan_y'],
                    'grade' => !empty($l['direita_grade']), 'grade_h' => (int)($l['direita_grade_h'] ?? 10), 'grade_v' => (int)($l['direita_grade_v'] ?? 2),
                    'comentario' => $l['direita_comentario'] ?? '',
                    'anotacoes' => $l['direita_anotacoes'] ? (json_decode($l['direita_anotacoes'], true) ?: []) : [],
                ],
            ];
        }
        echo json_encode(['ok' => true, 'relatorio' => [
            'id' => $id, 'idatleta' => $rel['idatleta'] !== null ? (int)$rel['idatleta'] : null,
            'aluno_nome' => $rel['aluno_nome'], 'bg' => $rel['bg'], 'marca' => (bool)$rel['marca'],
            'criado_em' => $rel['criado_em'], 'atualizado_em' => $rel['atualizado_em'], 'linhas' => $linhas,
        ]]);
        exit;
    }

    if ($action === 'criar') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $alunoNome = trim((string)($body['aluno_nome'] ?? ''));
        $idatleta = isset($body['idatleta']) && $body['idatleta'] !== null && $body['idatleta'] !== '' ? (int)$body['idatleta'] : null;
        $bg = ($body['bg'] ?? 'escuro') === 'claro' ? 'claro' : 'escuro';
        // Marca d'agua e obrigatoria por enquanto pra quem nao e admin (regra de negocio,
        // nao so trava de tela: reforcada aqui pra nao dar pra contornar via chamada direta).
        $marca = $ehAdmin ? (empty($body['marca']) ? 0 : 1) : 1;
        $st = $pdo->prepare("INSERT INTO intus_relatorio_fotos (idprofessor, idatleta, aluno_nome, bg, marca) VALUES (?, ?, ?, ?, ?)");
        $st->execute([$idprofessor, $idatleta, $alunoNome, $bg, $marca]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'salvar_meta') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $rel = _carregarRelatorioDoDono($pdo, $body['id'] ?? 0, $idprofessor, $ehAdmin);
        if ($rel === null) { http_response_code(404); echo json_encode(['ok' => false, 'erro' => 'nao encontrado']); exit; }
        if ($rel === false) { http_response_code(403); echo json_encode(['ok' => false, 'erro' => 'sem permissao']); exit; }
        $alunoNome = trim((string)($body['aluno_nome'] ?? $rel['aluno_nome']));
        $idatleta = isset($body['idatleta']) && $body['idatleta'] !== null && $body['idatleta'] !== '' ? (int)$body['idatleta'] : null;
        $bg = ($body['bg'] ?? $rel['bg']) === 'claro' ? 'claro' : 'escuro';
        $marca = $ehAdmin ? (empty($body['marca']) ? 0 : 1) : 1;
        $st = $pdo->prepare("UPDATE intus_relatorio_fotos SET idatleta = ?, aluno_nome = ?, bg = ?, marca = ? WHERE id = ?");
        $st->execute([$idatleta, $alunoNome, $bg, $marca, (int)$rel['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'salvar_linha') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $rel = _carregarRelatorioDoDono($pdo, $body['relatorio_id'] ?? 0, $idprofessor, $ehAdmin);
        if ($rel === null) { http_response_code(404); echo json_encode(['ok' => false, 'erro' => 'relatorio nao encontrado']); exit; }
        if ($rel === false) { http_response_code(403); echo json_encode(['ok' => false, 'erro' => 'sem permissao']); exit; }
        $relatorioId = (int)$rel['id'];
        $linhaIdx = (int)($body['linha_idx'] ?? -1);
        if ($linhaIdx < 0 || $linhaIdx > 19) { http_response_code(400); echo json_encode(['ok' => false, 'erro' => 'linha_idx invalido']); exit; }

        $label = trim((string)($body['label'] ?? ''));
        $largura = max(100, min(4000, (int)($body['largura'] ?? 900)));
        $altura = max(100, min(4000, (int)($body['altura'] ?? 1200)));

        $st = $pdo->prepare("SELECT * FROM intus_relatorio_fotos_linha WHERE relatorio_id = ? AND linha_idx = ?");
        $st->execute([$relatorioId, $linhaIdx]);
        $existente = $st->fetch(PDO::FETCH_ASSOC);

        $resultado = ['ok' => true];
        $campos = ['esquerda' => [], 'direita' => []];
        foreach (['esquerda', 'direita'] as $lado) {
            $ladoIn = is_array($body[$lado] ?? null) ? $body[$lado] : [];
            $tag = trim((string)($ladoIn['tag'] ?? ($lado === 'esquerda' ? 'Antes' : 'Depois')));
            $zoom = max(1, min(4, (float)($ladoIn['zoom'] ?? 1)));
            $panX = max(0, min(1, (float)($ladoIn['pan_x'] ?? 0.5)));
            $panY = max(0, min(1, (float)($ladoIn['pan_y'] ?? 0.5)));
            $arquivo = $existente ? $existente[$lado . '_arquivo'] : null;

            if (!empty($ladoIn['remover'])) {
                _removerFotoLado($relatorioId, $linhaIdx, $lado);
                $arquivo = null;
            } elseif (!empty($ladoIn['data'])) {
                $arquivo = _salvarFotoLado($relatorioId, $linhaIdx, $lado, $ladoIn['data']);
                $resultado[$lado . '_url'] = _urlFoto($relatorioId, $arquivo);
            }

            $grade = empty($ladoIn['grade']) ? 0 : 1;
            $gradeH = max(1, min(20, (int)($ladoIn['grade_h'] ?? 10)));
            $gradeV = max(1, min(20, (int)($ladoIn['grade_v'] ?? 2)));
            $comentario = mb_substr(trim((string)($ladoIn['comentario'] ?? '')), 0, 500);
            $anotacoesRaw = is_string($ladoIn['anotacoes'] ?? null) ? $ladoIn['anotacoes'] : '[]';
            // Confere que e JSON valido antes de gravar (senao guarda lista vazia) e limita
            // tamanho — isso e desenho vetorial, nao deveria nunca chegar perto disso.
            $anotacoesDecoded = json_decode($anotacoesRaw, true);
            if (!is_array($anotacoesDecoded) || strlen($anotacoesRaw) > 200000) $anotacoesRaw = '[]';

            $campos[$lado] = [$tag, $arquivo, $zoom, $panX, $panY, $grade, $gradeH, $gradeV, $anotacoesRaw, $comentario];
        }

        if ($existente) {
            $sql = "UPDATE intus_relatorio_fotos_linha SET label=?, largura=?, altura=?,
                    esquerda_tag=?, esquerda_arquivo=?, esquerda_zoom=?, esquerda_pan_x=?, esquerda_pan_y=?, esquerda_grade=?, esquerda_grade_h=?, esquerda_grade_v=?, esquerda_anotacoes=?, esquerda_comentario=?,
                    direita_tag=?, direita_arquivo=?, direita_zoom=?, direita_pan_x=?, direita_pan_y=?, direita_grade=?, direita_grade_h=?, direita_grade_v=?, direita_anotacoes=?, direita_comentario=?
                    WHERE id = ?";
            $pdo->prepare($sql)->execute(array_merge(
                [$label, $largura, $altura], $campos['esquerda'], $campos['direita'], [$existente['id']]
            ));
        } else {
            $sql = "INSERT INTO intus_relatorio_fotos_linha
                    (relatorio_id, linha_idx, label, largura, altura,
                     esquerda_tag, esquerda_arquivo, esquerda_zoom, esquerda_pan_x, esquerda_pan_y, esquerda_grade, esquerda_grade_h, esquerda_grade_v, esquerda_anotacoes, esquerda_comentario,
                     direita_tag, direita_arquivo, direita_zoom, direita_pan_x, direita_pan_y, direita_grade, direita_grade_h, direita_grade_v, direita_anotacoes, direita_comentario)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute(array_merge(
                [$relatorioId, $linhaIdx, $label, $largura, $altura], $campos['esquerda'], $campos['direita']
            ));
        }
        $pdo->prepare("UPDATE intus_relatorio_fotos SET atualizado_em = NOW() WHERE id = ?")->execute([$relatorioId]);
        echo json_encode($resultado);
        exit;
    }

    if ($action === 'excluir') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'erro' => 'metodo invalido']); exit; }
        $rel = _carregarRelatorioDoDono($pdo, $body['id'] ?? 0, $idprofessor, $ehAdmin);
        if ($rel === null) { http_response_code(404); echo json_encode(['ok' => false, 'erro' => 'nao encontrado']); exit; }
        if ($rel === false) { http_response_code(403); echo json_encode(['ok' => false, 'erro' => 'sem permissao']); exit; }
        $id = (int)$rel['id'];
        $pdo->prepare("DELETE FROM intus_relatorio_fotos_linha WHERE relatorio_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM intus_relatorio_fotos WHERE id = ?")->execute([$id]);
        $dir = __DIR__ . '/../img/relatorios-fotos/' . $id;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'erro' => 'acao invalida']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'falha interna', 'detalhe' => _intusLogErro($e)]);
}
