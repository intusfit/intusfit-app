<?php
/**
 * Intus Fit — Relatórios de avaliação por fotos (armazenamento server-side)
 *
 * Guarda os relatórios criados na ferramenta /relatorios-fotos/: até 20 linhas de
 * comparativo por relatório, cada linha com 2 a 4 fotos (antes/depois, ou com
 * checkpoints intermediários), cada foto com sua própria etiqueta e o ajuste de
 * recorte (zoom + posição) já feito pelo professor, para poder reabrir e
 * continuar editando depois.
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
 *                                          (linha.slots: array de 2 a 4 fotos, sempre nessa forma
 *                                          mesmo pra relatório antigo salvo só com esquerda/direita)
 *   POST ?action=criar        {aluno_nome, bg, marca}                    -> {ok, id}
 *   POST ?action=salvar_meta  {id, aluno_nome, bg, marca}                -> {ok}
 *   POST ?action=salvar_linha {relatorio_id, linha_idx, label, largura, altura,
 *                               slots:[{tag,zoom,pan_x,pan_y,grade,grade_h,grade_v,
 *                                       grade_line_overrides,comentario,anotacoes,data?,remover?}, ...]}
 *                              -> {ok, slot0_url?, slot1_url?, ...}
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

// Uma linha passou a aceitar de 2 a 4 fotos (nao so "esquerda"/"direita" fixos), pra caber
// comparativo com foto(s) do meio (ex.: Antes / Semana 4 / Depois). Guardado como JSON — mesmo
// desenho ja usado em intus_plano_nutricional.refeicoes e intus_avaliacao.medidas/fotos. Linha
// antiga (sem slots_json) continua lida a partir das colunas esquerda_*/direita_* de sempre; ao
// salvar de novo, vira slots_json e as colunas velhas ficam paradas (mesmo motivo de sempre: nao
// apaga coluna que pode ter dado).
_garantirColuna($pdo, 'intus_relatorio_fotos_linha', 'slots_json', "LONGTEXT NULL");

// ---------- Helpers de arquivo ----------
function _dirRelatorio($id) {
    $dir = __DIR__ . '/../img/relatorios-fotos/' . (int)$id;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function _salvarFotoSlot($relatorioId, $linhaIdx, $slotIdx, $dataUrl) {
    if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#is', $dataUrl, $m)) {
        throw new Exception('formato de imagem invalido (use PNG, JPG ou WEBP)');
    }
    $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
    $bin = base64_decode($m[2], true);
    if ($bin === false) throw new Exception('base64 invalido');
    if (strlen($bin) > 8 * 1024 * 1024) throw new Exception('imagem grande demais (max 8MB apos compressao)');
    $dir = _dirRelatorio($relatorioId);
    if (!is_writable($dir)) throw new Exception('pasta de upload indisponivel');
    $base = (int)$linhaIdx . '-slot' . (int)$slotIdx;
    $fname = $base . '.' . $ext;
    // remove versao anterior com outra extensao, se houver (evita arquivo orfao)
    foreach (['jpg', 'png', 'webp'] as $e) {
        if ($e !== $ext) @unlink($dir . '/' . $base . '.' . $e);
    }
    if (@file_put_contents($dir . '/' . $fname, $bin) === false) throw new Exception('falha ao gravar arquivo');
    return $fname;
}

function _removerFotoSlot($relatorioId, $linhaIdx, $slotIdx) {
    $dir = __DIR__ . '/../img/relatorios-fotos/' . (int)$relatorioId;
    $base = (int)$linhaIdx . '-slot' . (int)$slotIdx;
    foreach (['jpg', 'png', 'webp'] as $e) @unlink($dir . '/' . $base . '.' . $e);
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
            $stf = $pdo->prepare("SELECT esquerda_arquivo, direita_arquivo, slots_json FROM intus_relatorio_fotos_linha WHERE relatorio_id = ? ORDER BY linha_idx ASC");
            $stf->execute([$r['id']]);
            $arq = null;
            foreach ($stf->fetchAll(PDO::FETCH_ASSOC) as $linhaFoto) {
                if (!empty($linhaFoto['slots_json'])) {
                    $slotsTmp = json_decode($linhaFoto['slots_json'], true);
                    if (is_array($slotsTmp)) {
                        foreach ($slotsTmp as $st2) { if (!empty($st2['arquivo'])) { $arq = $st2['arquivo']; break; } }
                    }
                } elseif (!empty($linhaFoto['esquerda_arquivo'])) { $arq = $linhaFoto['esquerda_arquivo']; }
                elseif (!empty($linhaFoto['direita_arquivo'])) { $arq = $linhaFoto['direita_arquivo']; }
                if ($arq) break;
            }
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
            $slots = [];
            $slotsSalvos = !empty($l['slots_json']) ? json_decode($l['slots_json'], true) : null;
            if (is_array($slotsSalvos) && count($slotsSalvos)) {
                foreach ($slotsSalvos as $sd) {
                    $slots[] = [
                        'tag' => $sd['tag'] ?? '', 'url' => _urlFoto($id, $sd['arquivo'] ?? null),
                        'zoom' => (float)($sd['zoom'] ?? 1), 'pan_x' => (float)($sd['pan_x'] ?? 0.5), 'pan_y' => (float)($sd['pan_y'] ?? 0.5),
                        'grade' => !empty($sd['grade']), 'grade_h' => (int)($sd['grade_h'] ?? 10), 'grade_v' => (int)($sd['grade_v'] ?? 2),
                        'grade_line_overrides' => is_array($sd['grade_line_overrides'] ?? null) ? $sd['grade_line_overrides'] : new stdClass(),
                        'comentario' => $sd['comentario'] ?? '',
                        'anotacoes' => is_array($sd['anotacoes'] ?? null) ? $sd['anotacoes'] : [],
                    ];
                }
            } else {
                // Linha antiga (de antes de existir slots_json): sintetiza 2 posicoes a
                // partir das colunas esquerda_*/direita_* de sempre, pro front-end nunca
                // precisar saber a diferenca — sempre recebe uma lista de posicoes.
                foreach (['esquerda', 'direita'] as $lado) {
                    $slots[] = [
                        'tag' => $l[$lado . '_tag'] ?? '', 'url' => _urlFoto($id, $l[$lado . '_arquivo'] ?? null),
                        'zoom' => (float)($l[$lado . '_zoom'] ?? 1), 'pan_x' => (float)($l[$lado . '_pan_x'] ?? 0.5), 'pan_y' => (float)($l[$lado . '_pan_y'] ?? 0.5),
                        'grade' => !empty($l[$lado . '_grade']), 'grade_h' => (int)($l[$lado . '_grade_h'] ?? 10), 'grade_v' => (int)($l[$lado . '_grade_v'] ?? 2),
                        'grade_line_overrides' => new stdClass(),
                        'comentario' => $l[$lado . '_comentario'] ?? '',
                        'anotacoes' => $l[$lado . '_anotacoes'] ? (json_decode($l[$lado . '_anotacoes'], true) ?: []) : [],
                    ];
                }
            }
            $linhas[] = [
                'linha_idx' => (int)$l['linha_idx'],
                'label' => $l['label'],
                'largura' => (int)$l['largura'],
                'altura' => (int)$l['altura'],
                'slots' => $slots,
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

        // Slots vindos do body (formato novo, 2 a 4 posicoes). Linha antiga sendo
        // reaberta e salva de novo tambem manda nesse formato — vira slots_json daqui pra frente.
        $slotsIn = is_array($body['slots'] ?? null) ? array_values($body['slots']) : [];
        if (count($slotsIn) < 2 || count($slotsIn) > 4) {
            http_response_code(400); echo json_encode(['ok' => false, 'erro' => 'um comparativo precisa de 2 a 4 fotos']); exit;
        }

        // Arquivo ja existente por posicao, pra manter quando o body nao manda foto nova
        // nem pede remover. Linha ja no formato novo: le do slots_json salvo. Linha ainda
        // no formato antigo (esquerda_arquivo/direita_arquivo): serve so as posicoes 0 e 1.
        $arquivosAntigos = [];
        if ($existente) {
            if (!empty($existente['slots_json'])) {
                $tmp = json_decode($existente['slots_json'], true);
                if (is_array($tmp)) foreach ($tmp as $i => $sd) $arquivosAntigos[$i] = $sd['arquivo'] ?? null;
            } else {
                $arquivosAntigos[0] = $existente['esquerda_arquivo'] ?? null;
                $arquivosAntigos[1] = $existente['direita_arquivo'] ?? null;
            }
        }

        $resultado = ['ok' => true];
        $slotsOut = [];
        foreach ($slotsIn as $i => $slotIn) {
            if (!is_array($slotIn)) $slotIn = [];
            $tag = trim((string)($slotIn['tag'] ?? ('Foto ' . ($i + 1))));
            $zoom = max(1, min(4, (float)($slotIn['zoom'] ?? 1)));
            $panX = max(0, min(1, (float)($slotIn['pan_x'] ?? 0.5)));
            $panY = max(0, min(1, (float)($slotIn['pan_y'] ?? 0.5)));
            $arquivo = $arquivosAntigos[$i] ?? null;

            if (!empty($slotIn['remover'])) {
                _removerFotoSlot($relatorioId, $linhaIdx, $i);
                $arquivo = null;
            } elseif (!empty($slotIn['data'])) {
                $arquivo = _salvarFotoSlot($relatorioId, $linhaIdx, $i, $slotIn['data']);
                $resultado['slot' . $i . '_url'] = _urlFoto($relatorioId, $arquivo);
            }

            $grade = empty($slotIn['grade']) ? 0 : 1;
            $gradeH = max(1, min(20, (int)($slotIn['grade_h'] ?? 10)));
            $gradeV = max(1, min(20, (int)($slotIn['grade_v'] ?? 2)));
            $overrides = is_array($slotIn['grade_line_overrides'] ?? null) ? $slotIn['grade_line_overrides'] : [];
            $comentario = mb_substr(trim((string)($slotIn['comentario'] ?? '')), 0, 500);
            $anotacoesRaw = is_string($slotIn['anotacoes'] ?? null) ? $slotIn['anotacoes'] : '[]';
            // Confere que e JSON valido antes de gravar (senao guarda lista vazia) e limita
            // tamanho — isso e desenho vetorial, nao deveria nunca chegar perto disso.
            $anotacoesDecoded = json_decode($anotacoesRaw, true);
            if (!is_array($anotacoesDecoded)) $anotacoesDecoded = [];
            if (strlen($anotacoesRaw) > 200000) $anotacoesDecoded = [];

            $slotsOut[] = [
                'tag' => $tag, 'arquivo' => $arquivo, 'zoom' => $zoom, 'pan_x' => $panX, 'pan_y' => $panY,
                'grade' => $grade, 'grade_h' => $gradeH, 'grade_v' => $gradeV, 'grade_line_overrides' => $overrides,
                'comentario' => $comentario, 'anotacoes' => $anotacoesDecoded,
            ];
        }
        // Se a linha encolheu (tinha 4 fotos, passou a ter 2), apaga o arquivo das posicoes que sobraram.
        foreach ($arquivosAntigos as $i => $arq) {
            if ($i >= count($slotsOut) && $arq) _removerFotoSlot($relatorioId, $linhaIdx, $i);
        }

        $slotsJson = json_encode($slotsOut, JSON_UNESCAPED_UNICODE);

        if ($existente) {
            $pdo->prepare("UPDATE intus_relatorio_fotos_linha SET label=?, largura=?, altura=?, slots_json=? WHERE id = ?")
                ->execute([$label, $largura, $altura, $slotsJson, $existente['id']]);
        } else {
            $pdo->prepare("INSERT INTO intus_relatorio_fotos_linha (relatorio_id, linha_idx, label, largura, altura, slots_json) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$relatorioId, $linhaIdx, $label, $largura, $altura, $slotsJson]);
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
