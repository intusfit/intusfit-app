<?php
/**
 * CDD: cardio, água e refeições (nutrição do dia). Biblioteca interna carregada por _nucleo.php.
 *
 * Cardio segue a lógica da Intus (CLAUDE.md, seção 56): o tempo manda, o esforço pesa pouco, fator 0,55, teto por sessão, até 3 por dia.
 * Nutrição do CDD é um diário simples (água e refeições feitas), sem plano alimentar individual. Nada aqui lê tabela da Intus.
 */

const CDD_CARDIO = ['fator' => 0.55, 'teto' => 7.0, 'max_dia' => 3, 'min_min' => 10, 'max_min' => 240];
const CDD_REFEICOES = ['cafe' => ['☕', 'Café da manhã'], 'lanche_manha' => ['🍎', 'Lanche da manhã'], 'almoco' => ['🍛', 'Almoço'], 'lanche_tarde' => ['🥪', 'Lanche da tarde'], 'jantar' => ['🍲', 'Jantar']];

/** Atividades e o MET moderado de cada uma (referência: compêndio de atividades físicas). */
function cddAtividades(): array {
    return [
        'caminhada' => ['🚶‍♀️', 'Caminhada', 3.8], 'corrida' => ['🏃‍♀️', 'Corrida', 9.8], 'bike' => ['🚴‍♀️', 'Bike', 7.5], 'eliptico' => ['🌀', 'Elíptico', 5.0],
        'escada' => ['🧗', 'Máquina de escada', 8.8], 'corda' => ['🪢', 'Pular corda', 11.0], 'natacao' => ['🏊‍♀️', 'Natação', 8.0], 'danca' => ['💃', 'Dança', 5.0],
        'hiit' => ['⚡', 'HIIT', 8.0], 'outro' => ['❤️‍🔥', 'Outro', 5.0],
    ];
}

/** Pontos de uma sessão de cardio: base da atividade x esforço x minutos/30 x fator, com teto. Esforço de 1 a 5 vira RPE de 2 a 10. */
function cddCardioPontos(string $atividade, int $minutos, int $esforco): float {
    $at = cddAtividades(); $met = $at[$atividade][2] ?? 5.0;
    $base = 6 + 0.5 * ($met - 6);
    $rpe = max(1, min(5, $esforco)) * 2;
    $mult = 1 + 0.03 * ($rpe - 5);
    return round(min(CDD_CARDIO['teto'], $base * $mult * ($minutos / 30) * CDD_CARDIO['fator']), 1);
}

/** Pontos de cardio por aluna no período: no máximo 3 sessões por dia contam. @return array idaluna => ['pontos' => float, 'sessoes' => int] */
function cddPontosCardio(PDO $pdo, string $de, string $ate): array {
    $st = $pdo->prepare('SELECT idaluna, data, atividade, minutos, esforco FROM cdd_cardio WHERE data >= ? AND data <= ? AND minutos >= ? ORDER BY idaluna, data, idcardio');
    $st->execute([$de, $ate, CDD_CARDIO['min_min']]);
    $r = []; $n = [];
    foreach ($st->fetchAll() as $c) {
        $a = (int)$c['idaluna']; $k = $a . '|' . $c['data'];
        $n[$k] = ($n[$k] ?? 0) + 1;
        if ($n[$k] > CDD_CARDIO['max_dia']) continue;
        if (!isset($r[$a])) $r[$a] = ['pontos' => 0.0, 'sessoes' => 0];
        $r[$a]['pontos'] += cddCardioPontos((string)$c['atividade'], (int)$c['minutos'], (int)$c['esforco']); $r[$a]['sessoes']++;
    }
    return $r;
}

/** Meta de água em ml: 35 ml por kg do peso da anamnese; sem peso, 2500 ml. */
function cddMetaAgua(PDO $pdo, int $idaluna): int {
    $st = $pdo->prepare('SELECT respostas FROM cdd_anamnese WHERE idaluna = ?'); $st->execute([$idaluna]); $r = $st->fetchColumn();
    $resp = $r ? (json_decode((string)$r, true) ?: []) : [];
    $peso = (float)str_replace(',', '.', (string)($resp['peso'] ?? ''));
    if ($peso >= 30 && $peso <= 250) return (int)(round($peso * 35 / 50) * 50);
    return 2500;
}

function cddDataPermitida($d): ?string {
    $d = (string)$d;
    if ($d === '') return cddHoje();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return null;
    $hoje = cddHoje(); $ontem = date('Y-m-d', strtotime($hoje . ' -1 day'));
    return ($d === $hoje || $d === $ontem) ? $d : null;
}

function cddNutriDia(PDO $pdo, int $idaluna, string $data): array {
    $st = $pdo->prepare('SELECT ml FROM cdd_agua WHERE idaluna = ? AND data = ?'); $st->execute([$idaluna, $data]);
    $ml = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT refeicao, status FROM cdd_refeicao WHERE idaluna = ? AND data = ?'); $st->execute([$idaluna, $data]);
    $ref = []; foreach ($st->fetchAll() as $x) $ref[$x['refeicao']] = $x['status'];
    return ['ml' => $ml, 'refeicoes' => $ref];
}

/** Aderência do dia: feito vale 1, parcial 0,5, fora 0. Sem registro conta como pendente (não pontua). */
function cddAderencia(array $ref): ?int {
    if (!$ref) return null;
    $s = 0.0; foreach (CDD_REFEICOES as $k => $_) { $v = $ref[$k] ?? ''; $s += $v === 'feito' ? 1 : ($v === 'parcial' ? 0.5 : 0); }
    return (int)round($s * 100 / count(CDD_REFEICOES));
}

function cddNutriResumo(PDO $pdo, int $idaluna): array {
    $hoje = cddHoje(); $meta = cddMetaAgua($pdo, $idaluna);
    $dia = cddNutriDia($pdo, $idaluna, $hoje);
    $ini = date('Y-m-d', strtotime($hoje . ' -6 days'));
    $ag = $pdo->prepare('SELECT data, ml FROM cdd_agua WHERE idaluna = ? AND data >= ? AND data <= ?'); $ag->execute([$idaluna, $ini, $hoje]);
    $agua = []; foreach ($ag->fetchAll() as $x) $agua[$x['data']] = (int)$x['ml'];
    $rf = $pdo->prepare('SELECT data, refeicao, status FROM cdd_refeicao WHERE idaluna = ? AND data >= ? AND data <= ?'); $rf->execute([$idaluna, $ini, $hoje]);
    $refs = []; foreach ($rf->fetchAll() as $x) $refs[$x['data']][$x['refeicao']] = $x['status'];
    $semana = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($hoje . " -$i days"));
        $semana[] = ['data' => $d, 'ml' => $agua[$d] ?? 0, 'meta_batida' => ($agua[$d] ?? 0) >= $meta, 'aderencia' => cddAderencia($refs[$d] ?? [])];
    }
    $ads = array_filter(array_column($semana, 'aderencia'), function ($v) { return $v !== null; });
    $refList = []; foreach (CDD_REFEICOES as $k => $v) $refList[] = ['chave' => $k, 'emoji' => $v[0], 'nome' => $v[1], 'status' => $dia['refeicoes'][$k] ?? ''];
    return ['hoje' => $hoje, 'meta_ml' => $meta, 'agua_ml' => $dia['ml'], 'refeicoes' => $refList, 'aderencia_hoje' => cddAderencia($dia['refeicoes']),
            'aderencia_semana' => $ads ? (int)round(array_sum($ads) / count($ads)) : null, 'semana' => $semana];
}

/** Rotas de cardio e nutrição (aluna com matrícula; a checagem fica em cddTratar). Devolve null se a rota não é daqui. */
function cddRotaBemestar(PDO $pdo, string $metodo, string $acao, array $q, array $b, array $eu): ?array {
    $id = (int)$eu['idaluna']; $get = ($metodo === 'GET');

    if ($acao === 'cardio' && $get) {
        $hoje = cddHoje(); $ini = date('Y-m-d', strtotime($hoje . ' -45 days'));
        $st = $pdo->prepare('SELECT idcardio, data, atividade, minutos, esforco, comentario FROM cdd_cardio WHERE idaluna = ? AND data >= ? ORDER BY data DESC, idcardio DESC'); $st->execute([$id, $ini]);
        $at = cddAtividades(); $lista = []; $porDia = [];
        $all = $st->fetchAll();
        // pontos de cada sessão, respeitando o limite de 3 por dia (contadas na ordem em que foram feitas)
        foreach (array_reverse($all) as $c) { $k = $c['data']; $valida = (int)$c['minutos'] >= CDD_CARDIO['min_min']; if ($valida) $porDia[$k] = ($porDia[$k] ?? 0) + 1; $c['_conta'] = $valida && $porDia[$k] <= CDD_CARDIO['max_dia']; $lista[] = $c; }
        $lista = array_reverse($lista);
        $out = [];
        foreach ($lista as $c) {
            $a = $at[$c['atividade']] ?? $at['outro'];
            $out[] = ['id' => (int)$c['idcardio'], 'data' => $c['data'], 'atividade' => $c['atividade'], 'emoji' => $a[0], 'nome' => $a[1], 'minutos' => (int)$c['minutos'], 'esforco' => (int)$c['esforco'],
                      'comentario' => $c['comentario'], 'pontos' => $c['_conta'] ? cddCardioPontos((string)$c['atividade'], (int)$c['minutos'], (int)$c['esforco']) : 0.0];
        }
        $ini7 = cddInicioSemana(); $sem = 0; $min = 0; $pts = 0.0;
        foreach ($out as $c) if ($c['data'] >= $ini7) { $sem++; $min += $c['minutos']; $pts += $c['pontos']; }
        $ativ = []; foreach ($at as $k => $v) $ativ[] = ['chave' => $k, 'emoji' => $v[0], 'nome' => $v[1]];
        $hojeN = count(array_filter($out, function ($c) use ($hoje) { return $c['data'] === $hoje; }));
        return cddResp(200, ['cardios' => $out, 'atividades' => $ativ, 'semana' => ['sessoes' => $sem, 'minutos' => $min, 'pontos' => round($pts, 1)], 'hoje' => $hoje, 'sessoes_hoje' => $hojeN,
                             'regra' => 'Cardio de 10 minutos ou mais pontua, até 3 por dia. O tempo é o que mais pesa, o esforço mexe pouco.']);
    }
    if ($acao === 'cardio_salvar' && !$get) {
        $data = cddDataPermitida($b['data'] ?? '');
        if ($data === null) return cddErro(422, 'data_invalida', 'O cardio só pode ser registrado para hoje ou ontem.');
        $at = cddAtividades(); $ativ = (string)($b['atividade'] ?? '');
        if (!isset($at[$ativ])) return cddErro(422, 'atividade_invalida', 'Escolha a atividade.');
        $min = (int)($b['minutos'] ?? 0);
        if ($min < 1 || $min > CDD_CARDIO['max_min']) return cddErro(422, 'minutos_invalidos', 'Informe uma duração entre 1 e ' . CDD_CARDIO['max_min'] . ' minutos.');
        $esf = max(1, min(5, (int)($b['esforco'] ?? 3)));
        $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_cardio WHERE idaluna = ? AND data = ?'); $st->execute([$id, $data]);
        if ((int)$st->fetchColumn() >= 6) return cddErro(429, 'limite_dia', 'Você já registrou cardios demais neste dia.');
        $pdo->prepare('INSERT INTO cdd_cardio (idaluna, data, atividade, minutos, esforco, comentario, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$id, $data, $ativ, $min, $esf, cddStr($b['comentario'] ?? '', 300) ?: null, cddAgora()]);
        $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_cardio WHERE idaluna = ? AND data = ? AND minutos >= ?'); $st->execute([$id, $data, CDD_CARDIO['min_min']]);
        $n = (int)$st->fetchColumn();
        $pts = ($min >= CDD_CARDIO['min_min'] && $n <= CDD_CARDIO['max_dia']) ? cddCardioPontos($ativ, $min, $esf) : 0.0;
        return cddResp(200, ['ok' => true, 'pontos' => $pts, 'conta_ranking' => $pts > 0, 'novas' => cddAtualizarConquistas($pdo, $id)]);
    }
    if ($acao === 'cardio_excluir' && !$get) {
        $pdo->prepare('DELETE FROM cdd_cardio WHERE idcardio = ? AND idaluna = ?')->execute([(int)($b['id'] ?? 0), $id]);
        return cddResp(200, ['ok' => true]);
    }

    if ($acao === 'nutri' && $get) return cddResp(200, ['nutri' => cddNutriResumo($pdo, $id)]);
    if ($acao === 'agua' && !$get) {
        $data = cddDataPermitida($b['data'] ?? '');
        if ($data === null) return cddErro(422, 'data_invalida', 'A água só pode ser ajustada para hoje ou ontem.');
        $dia = cddNutriDia($pdo, $id, $data);
        $novo = isset($b['ml']) ? (int)$b['ml'] : $dia['ml'] + (int)($b['delta'] ?? 0);
        $novo = max(0, min(10000, $novo));
        $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_agua WHERE idaluna = ? AND data = ?'); $st->execute([$id, $data]);
        if ((int)$st->fetchColumn()) $pdo->prepare('UPDATE cdd_agua SET ml = ? WHERE idaluna = ? AND data = ?')->execute([$novo, $id, $data]);
        else $pdo->prepare('INSERT INTO cdd_agua (idaluna, data, ml) VALUES (?, ?, ?)')->execute([$id, $data, $novo]);
        return cddResp(200, ['ok' => true, 'ml' => $novo, 'meta_ml' => cddMetaAgua($pdo, $id), 'novas' => cddAtualizarConquistas($pdo, $id)]);
    }
    if ($acao === 'refeicao' && !$get) {
        $data = cddDataPermitida($b['data'] ?? '');
        if ($data === null) return cddErro(422, 'data_invalida', 'As refeições só podem ser marcadas para hoje ou ontem.');
        $ch = (string)($b['refeicao'] ?? ''); $stt = (string)($b['status'] ?? '');
        if (!isset(CDD_REFEICOES[$ch])) return cddErro(422, 'refeicao_invalida', 'Refeição desconhecida.');
        $pdo->prepare('DELETE FROM cdd_refeicao WHERE idaluna = ? AND data = ? AND refeicao = ?')->execute([$id, $data, $ch]);
        if (in_array($stt, ['feito', 'parcial', 'fora'], true)) $pdo->prepare('INSERT INTO cdd_refeicao (idaluna, data, refeicao, status) VALUES (?, ?, ?, ?)')->execute([$id, $data, $ch, $stt]);
        return cddResp(200, ['ok' => true, 'aderencia' => cddAderencia(cddNutriDia($pdo, $id, $data)['refeicoes']), 'novas' => cddAtualizarConquistas($pdo, $id)]);
    }
    return null;
}
