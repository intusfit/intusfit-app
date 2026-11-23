<?php
// Pagamento dividido em varias formas (ex.: R$ 300 em dinheiro + R$ 700 no Pix, ou parte no cartao).
// Cada baixa de mensalidade pode ter ate 8 partes (forma + valor). A soma das partes tem de fechar com o valor recebido (vlpagamento).
// intus_mensalidade.forma_pgto continua guardando UMA forma (a de maior valor), para tudo o que ja le essa coluna seguir funcionando.
// Funcoes puras e SQL portavel (MySQL e SQLite dos testes). Prefixo _pgto para nao colidir com nada.

function _pgtoFormasValidas() { return ['pix', 'dinheiro', 'cartao', 'boleto']; }

// Tabela nova e aditiva: nenhum dado existente e alterado.
function _pgtoPartesTabela(PDO $pdo) {
    $sqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_mensalidade_pgto (
        idpgto " . ($sqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        idmensalidade INT NOT NULL,
        forma VARCHAR(12) NOT NULL,
        valor DECIMAL(10,2) NOT NULL" . ($sqlite ? "" : ",
        INDEX idx_mens (idmensalidade)") . "
    )" . ($sqlite ? "" : " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4") . ";");
}

// Confere e normaliza as partes. $total = valor recebido (ou null para nao conferir a soma).
// Devolve ['ok'=>true, 'partes'=>[['forma','valor']...], 'principal'=>forma de maior valor] ou ['ok'=>false, 'erro'=>texto].
function _pgtoPartesNormalizar($partes, $total = null) {
    if (!is_array($partes)) return ['ok' => false, 'erro' => 'partes inválidas'];
    if (count($partes) > 8) return ['ok' => false, 'erro' => 'no máximo 8 formas de pagamento'];
    $out = []; $soma = 0.0;
    foreach ($partes as $p) {
        $f = strtolower(trim((string)(is_array($p) ? ($p['forma'] ?? '') : '')));
        if (!in_array($f, _pgtoFormasValidas(), true)) return ['ok' => false, 'erro' => 'forma de pagamento inválida na divisão'];
        $v = is_array($p) ? round((float)str_replace(',', '.', (string)($p['valor'] ?? 0)), 2) : 0;
        if (!($v > 0)) return ['ok' => false, 'erro' => 'cada forma precisa ter valor maior que zero'];
        $out[] = ['forma' => $f, 'valor' => $v]; $soma += $v;
    }
    if (!$out) return ['ok' => true, 'partes' => [], 'principal' => null];
    if ($total !== null && abs(round($soma, 2) - round((float)$total, 2)) > 0.009) {
        return ['ok' => false, 'erro' => 'a soma das formas (R$ ' . number_format($soma, 2, ',', '.') . ') não fecha com o valor recebido (R$ ' . number_format((float)$total, 2, ',', '.') . ')'];
    }
    // principal = maior valor; empate fica com a primeira digitada
    $princ = $out[0];
    foreach ($out as $o) { if ($o['valor'] > $princ['valor'] + 0.0001) $princ = $o; }
    return ['ok' => true, 'partes' => $out, 'principal' => $princ['forma']];
}

function _pgtoPartesGravar(PDO $pdo, $idmensalidade, array $partes) {
    $pdo->prepare("DELETE FROM intus_mensalidade_pgto WHERE idmensalidade = ?")->execute([(int)$idmensalidade]);
    // uma unica forma nao e "divisao": so a coluna forma_pgto basta
    if (count($partes) < 2) return;
    $st = $pdo->prepare("INSERT INTO intus_mensalidade_pgto (idmensalidade, forma, valor) VALUES (?, ?, ?)");
    foreach ($partes as $p) $st->execute([(int)$idmensalidade, $p['forma'], $p['valor']]);
}

function _pgtoPartesApagar(PDO $pdo, $idmensalidade) {
    $pdo->prepare("DELETE FROM intus_mensalidade_pgto WHERE idmensalidade = ?")->execute([(int)$idmensalidade]);
}

// Anexa 'partes' em cada linha de mensalidade que tenha divisao (so as que tem; as demais ficam como estavam).
function _pgtoPartesAnexar(PDO $pdo, array &$rows) {
    $ids = [];
    foreach ($rows as $r) { if (isset($r['idmensalidade'])) $ids[] = (int)$r['idmensalidade']; }
    if (!$ids) return;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT idmensalidade, forma, valor FROM intus_mensalidade_pgto WHERE idmensalidade IN ($ph) ORDER BY idpgto");
    $st->execute($ids);
    $por = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) $por[(int)$x['idmensalidade']][] = ['forma' => $x['forma'], 'valor' => (float)$x['valor']];
    foreach ($rows as &$r) { $k = (int)($r['idmensalidade'] ?? 0); if (isset($por[$k])) $r['partes'] = $por[$k]; }
    unset($r);
}
