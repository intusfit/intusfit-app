<?php
// Análise das fotos de avaliação postural: qual das 18 posições do Protocolo INTUS cada foto é, e onde a
// pessoa está na foto (para o recorte automático dos relatórios de fotos).
// Quem detecta é o navegador do professor (MediaPipe, ver app/painel/foto-pose.js): aqui só se GUARDA o
// resultado, para não refazer a cada abertura e para a correção manual ficar valendo para todos.
// SQL portável de propósito (sem ON DUPLICATE KEY nem NOW()), para rodar igual no SQLite dos testes
// (testes/fotos_analise.php) e no MySQL de produção.

const FOTO_ANALISE_MAX_ITENS = 60;     // fotos por chamada
const FOTO_ANALISE_MAX_LM = 6000;      // bytes do JSON de pontos do corpo por foto

function fotoAnaliseGarantirTabela(PDO $pdo): void {
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_foto_analise (
        id          $pk,
        idatleta    INT NOT NULL,
        idavaliacao INT NULL,
        chave       VARCHAR(190) NOT NULL,
        pose        TINYINT NULL,
        conf        DECIMAL(3,2) NOT NULL DEFAULT 0,
        origem      VARCHAR(8) NOT NULL DEFAULT 'auto',
        largura     INT NOT NULL DEFAULT 0,
        altura      INT NOT NULL DEFAULT 0,
        caixa       VARCHAR(80) NULL,
        pontos      TEXT NULL,
        versao      SMALLINT NOT NULL DEFAULT 1,
        criado_em   VARCHAR(19) NOT NULL,
        atualizado_em VARCHAR(19) NOT NULL,
        UNIQUE (idatleta, chave)
    )$tail");
}

// Chave da foto: arquivo local da avaliação (a:), foto do Drive (d:) ou URL (u:). Mesma regra de IntusPose.chaveDaFoto.
function fotoAnaliseChaveValida($c): bool {
    if (!is_string($c) || strpos($c, '..') !== false) return false;
    if (strncmp($c, 'a:', 2) === 0) return (bool)preg_match('/^a:[A-Za-z0-9_.-]{3,120}$/', $c);
    if (strncmp($c, 'd:', 2) === 0) return (bool)preg_match('/^d:[A-Za-z0-9_-]{10,120}$/', $c);
    return (bool)preg_match('/^u:[A-Za-z0-9_.:\/%-]{3,170}$/', $c);
}

function _fotoAnaliseNum($v, float $min, float $max): ?float {
    if (!is_numeric($v)) return null;
    $v = (float)$v;
    return ($v < $min || $v > $max) ? null : $v;
}

// Valida e normaliza um item vindo do navegador. Devolve null se for lixo.
function fotoAnaliseLimpar($it): ?array {
    if (!is_array($it) || !fotoAnaliseChaveValida($it['chave'] ?? null)) return null;
    $pose = isset($it['pose']) && $it['pose'] !== null && $it['pose'] !== '' ? (int)$it['pose'] : null;
    if ($pose !== null && ($pose < 1 || $pose > 18)) $pose = null;
    $conf = _fotoAnaliseNum($it['conf'] ?? 0, 0, 1) ?? 0.0;
    $origem = ($it['origem'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
    $caixa = null;
    if (isset($it['bb']) && is_array($it['bb']) && count($it['bb']) === 4) {
        $b = [];
        foreach ($it['bb'] as $v) { $n = _fotoAnaliseNum($v, -0.5, 1.5); if ($n === null) { $b = null; break; } $b[] = round($n, 4); }
        if ($b) $caixa = json_encode($b);
    }
    $pontos = null;
    if (isset($it['lm']) && is_array($it['lm']) && count($it['lm']) === 33) {
        $l = [];
        foreach ($it['lm'] as $p) {
            if (!is_array($p) || count($p) < 3) { $l = null; break; }
            $x = _fotoAnaliseNum($p[0], -1, 2); $y = _fotoAnaliseNum($p[1], -1, 2); $v = _fotoAnaliseNum($p[3] ?? $p[2], 0, 1);
            if ($x === null || $y === null || $v === null) { $l = null; break; }
            $l[] = [round($x, 4), round($y, 4), round($v, 2)];
        }
        if ($l) { $js = json_encode($l); if (strlen($js) <= FOTO_ANALISE_MAX_LM) $pontos = $js; }
    }
    return [
        'chave' => $it['chave'],
        'idavaliacao' => isset($it['idavaliacao']) && (int)$it['idavaliacao'] > 0 ? (int)$it['idavaliacao'] : null,
        'pose' => $pose, 'conf' => round($conf, 2), 'origem' => $origem,
        'largura' => (int)(_fotoAnaliseNum($it['w'] ?? 0, 0, 20000) ?? 0),
        'altura' => (int)(_fotoAnaliseNum($it['h'] ?? 0, 0, 20000) ?? 0),
        'caixa' => $caixa, 'pontos' => $pontos,
    ];
}

// Grava (ou atualiza) as análises de um aluno. Regra: o que o professor confirmou à mão (origem 'manual')
// NUNCA é sobrescrito por uma análise automática. Devolve quantas linhas entraram.
function fotoAnaliseGravar(PDO $pdo, int $idatleta, array $itens): int {
    fotoAnaliseGarantirTabela($pdo);
    $agora = date('Y-m-d H:i:s');
    $n = 0;
    foreach (array_slice($itens, 0, FOTO_ANALISE_MAX_ITENS) as $bruto) {
        $it = fotoAnaliseLimpar($bruto);
        if (!$it) continue;
        $st = $pdo->prepare("SELECT id, origem FROM intus_foto_analise WHERE idatleta = ? AND chave = ?");
        $st->execute([$idatleta, $it['chave']]);
        $atual = $st->fetch(PDO::FETCH_ASSOC);
        if ($atual) {
            if ($atual['origem'] === 'manual' && $it['origem'] !== 'manual') {
                // só guarda os pontos do corpo (para o recorte), sem mexer na posição confirmada
                $pdo->prepare("UPDATE intus_foto_analise SET largura = ?, altura = ?, caixa = ?, pontos = ?, atualizado_em = ? WHERE id = ?")
                    ->execute([$it['largura'], $it['altura'], $it['caixa'], $it['pontos'], $agora, $atual['id']]);
            } else {
                $pdo->prepare("UPDATE intus_foto_analise SET idavaliacao = ?, pose = ?, conf = ?, origem = ?, largura = ?, altura = ?, caixa = ?, pontos = ?, atualizado_em = ? WHERE id = ?")
                    ->execute([$it['idavaliacao'], $it['pose'], $it['conf'], $it['origem'], $it['largura'], $it['altura'], $it['caixa'], $it['pontos'], $agora, $atual['id']]);
            }
        } else {
            $pdo->prepare("INSERT INTO intus_foto_analise (idatleta, idavaliacao, chave, pose, conf, origem, largura, altura, caixa, pontos, criado_em, atualizado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$idatleta, $it['idavaliacao'], $it['chave'], $it['pose'], $it['conf'], $it['origem'], $it['largura'], $it['altura'], $it['caixa'], $it['pontos'], $agora, $agora]);
        }
        $n++;
    }
    return $n;
}

// Correção manual de UMA foto (a que o professor confirma ou troca). pose null = "sem posição".
function fotoAnaliseDefinirPose(PDO $pdo, int $idatleta, string $chave, ?int $pose, ?int $idavaliacao = null): bool {
    if (!fotoAnaliseChaveValida($chave)) return false;
    if ($pose !== null && ($pose < 1 || $pose > 18)) return false;
    fotoAnaliseGarantirTabela($pdo);
    $agora = date('Y-m-d H:i:s');
    $st = $pdo->prepare("SELECT id FROM intus_foto_analise WHERE idatleta = ? AND chave = ?");
    $st->execute([$idatleta, $chave]);
    $id = $st->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE intus_foto_analise SET pose = ?, conf = 1, origem = 'manual', atualizado_em = ? WHERE id = ?")->execute([$pose, $agora, $id]);
    } else {
        $pdo->prepare("INSERT INTO intus_foto_analise (idatleta, idavaliacao, chave, pose, conf, origem, criado_em, atualizado_em) VALUES (?,?,?,?,1,'manual',?,?)")
            ->execute([$idatleta, $idavaliacao && $idavaliacao > 0 ? $idavaliacao : null, $chave, $pose, $agora, $agora]);
    }
    return true;
}

function fotoAnaliseRemover(PDO $pdo, int $idatleta, string $chave): void {
    if (!fotoAnaliseChaveValida($chave)) return;
    fotoAnaliseGarantirTabela($pdo);
    $pdo->prepare("DELETE FROM intus_foto_analise WHERE idatleta = ? AND chave = ?")->execute([$idatleta, $chave]);
}

// Tudo o que se sabe das fotos de um aluno, pronto para o navegador (pontos e caixa já como números).
function fotoAnaliseListar(PDO $pdo, int $idatleta): array {
    fotoAnaliseGarantirTabela($pdo);
    $st = $pdo->prepare("SELECT chave, idavaliacao, pose, conf, origem, largura, altura, caixa, pontos, versao FROM intus_foto_analise WHERE idatleta = ? ORDER BY id");
    $st->execute([$idatleta]);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[] = [
            'chave' => $r['chave'],
            'idavaliacao' => $r['idavaliacao'] !== null ? (int)$r['idavaliacao'] : null,
            'pose' => $r['pose'] !== null ? (int)$r['pose'] : null,
            'conf' => (float)$r['conf'], 'origem' => $r['origem'],
            'w' => (int)$r['largura'], 'h' => (int)$r['altura'],
            'bb' => $r['caixa'] ? json_decode($r['caixa'], true) : null,
            'lm' => $r['pontos'] ? json_decode($r['pontos'], true) : null,
            'versao' => (int)$r['versao'],
        ];
    }
    return $out;
}
