<?php
/**
 * CDD: anamnese, pontos, ranking e conquistas. Biblioteca interna (o "_" no nome bloqueia o acesso direto), carregada por _nucleo.php.
 *
 * As regras de pontos seguem as da Intus (CLAUDE.md, seção 8): 1º treino do dia vale 10 (5 se durar menos de 20 min), o 2º do mesmo dia
 * vale 5, o 3º em diante 0, e +5 de bônus quando a aluna treina em 4 dias diferentes da semana. Semana é de domingo a sábado.
 * Treino com menos de 5 minutos não conta (a Intus faz igual). Nada aqui lê ou grava tabela da Intus.
 */

const CDD_RANK = ['musBase' => 10, 'musCurtaMin' => 20, 'musMeia' => 5, 'musSegundo' => 5, 'musTerceiro' => 0, 'bonusDias' => 4, 'bonusPts' => 5, 'minimoSeg' => 300];

/* ───────────────────────── Semana de domingo a sábado ───────────────────────── */

function cddInicioSemana(?string $d = null): string {
    $ts = strtotime(($d ?: cddHoje()) . ' 12:00:00');
    return date('Y-m-d', $ts - (int)date('w', $ts) * 86400);
}

/* ───────────────────────── Pontos e ranking ───────────────────────── */

/**
 * Pontos por aluna no período [$de, $ate] (datas AAAA-MM-DD).
 * @return array idaluna => ['pontos' => float, 'treinos' => int, 'dias' => int]
 */
function cddPontosPorAluna(PDO $pdo, string $de, string $ate): array {
    $ini = cddInicioSemana($de);   // semanas inteiras para o bônus
    $st = $pdo->prepare('SELECT idtreino, idaluna, data, duracao_seg FROM cdd_treino WHERE data >= ? AND data <= ? ORDER BY idaluna, data, idtreino');
    $st->execute([$ini, $ate]);
    $r = []; $ordem = []; $semDias = [];
    foreach ($st->fetchAll() as $t) {
        $a = (int)$t['idaluna'];
        if ((int)$t['duracao_seg'] < CDD_RANK['minimoSeg']) continue;
        $k = $a . '|' . $t['data'];
        $n = $ordem[$k] = ($ordem[$k] ?? -1) + 1;
        if ($n === 0) $p = ((int)$t['duracao_seg'] / 60 < CDD_RANK['musCurtaMin']) ? CDD_RANK['musMeia'] : CDD_RANK['musBase'];
        elseif ($n === 1) $p = CDD_RANK['musSegundo']; else $p = CDD_RANK['musTerceiro'];
        $noPeriodo = $t['data'] >= $de;
        if (!isset($r[$a])) $r[$a] = ['pontos' => 0.0, 'treinos' => 0, 'dias' => 0];
        if ($noPeriodo) { $r[$a]['pontos'] += $p; $r[$a]['treinos']++; }
        if ($n === 0) {
            $sk = $a . '|' . cddInicioSemana($t['data']);
            $semDias[$sk][] = $t['data'];
            if ($noPeriodo) $r[$a]['dias']++;
            // bônus: entra na data em que a semana chega ao 4º dia diferente
            if (count($semDias[$sk]) === CDD_RANK['bonusDias'] && $t['data'] >= $de) $r[$a]['pontos'] += CDD_RANK['bonusPts'];
        }
    }
    return $r;
}

function cddPeriodoRanking(string $periodo): array {
    $hoje = cddHoje();
    if ($periodo === 'mes') return [date('Y-m-01'), date('Y-m-t')];
    if ($periodo === 'geral') return ['2000-01-01', $hoje];
    $ini = cddInicioSemana(); return [$ini, date('Y-m-d', strtotime($ini . ' +6 days'))];
}

function cddRanking(PDO $pdo, int $idaluna, string $periodo): array {
    [$de, $ate] = cddPeriodoRanking($periodo);
    $pts = cddPontosPorAluna($pdo, $de, $ate);
    $ids = array_keys($pts);
    $info = [];
    if ($ids) {
        $st = $pdo->query("SELECT idaluna, nome, ranking_visivel FROM cdd_aluna WHERE papel = 'aluna' AND status = 'ativa'");
        foreach ($st->fetchAll() as $a) $info[(int)$a['idaluna']] = $a;
    }
    $linhas = [];
    foreach ($pts as $id => $p) {
        if (!isset($info[$id]) || ($p['pontos'] <= 0 && $id !== $idaluna)) continue;
        if (!(int)$info[$id]['ranking_visivel'] && $id !== $idaluna) continue;
        $linhas[] = ['idaluna' => $id, 'nome' => cddNomePublico($info[$id]['nome']), 'pontos' => (int)round($p['pontos']), 'treinos' => $p['treinos'], 'dias' => $p['dias']];
    }
    usort($linhas, function ($a, $b) { return $b['pontos'] <=> $a['pontos'] ?: strcmp($a['nome'], $b['nome']); });
    // posição densa: quem tem o mesmo número divide a colocação (igual à Intus)
    $pos = 0; $ult = null;
    foreach ($linhas as $i => $l) { if ($ult === null || $l['pontos'] !== $ult) { $pos++; $ult = $l['pontos']; } $linhas[$i]['pos'] = $pos; }
    $eu = null;
    foreach ($linhas as $l) { if ($l['idaluna'] === $idaluna) $eu = $l; }
    $visivel = $pdo->prepare('SELECT ranking_visivel FROM cdd_aluna WHERE idaluna = ?'); $visivel->execute([$idaluna]);
    $top = [];
    foreach (array_slice(array_values(array_filter($linhas, function ($l) use ($info) { return (int)($info[$l['idaluna']]['ranking_visivel'] ?? 1) === 1; })), 0, 30) as $l) {
        $top[] = ['pos' => $l['pos'], 'nome' => $l['idaluna'] === $idaluna ? 'Você' : $l['nome'], 'pontos' => $l['pontos'], 'treinos' => $l['treinos'], 'eu' => $l['idaluna'] === $idaluna];
    }
    return ['periodo' => $periodo, 'de' => $de, 'ate' => $ate, 'top' => $top, 'participantes' => count($linhas),
            'eu' => $eu ? ['pos' => $eu['pos'], 'pontos' => $eu['pontos'], 'treinos' => $eu['treinos']] : ['pos' => null, 'pontos' => 0, 'treinos' => 0],
            'visivel' => (int)$visivel->fetchColumn() === 1,
            'regras' => 'Treino de 5 minutos ou mais: 10 pontos o primeiro do dia (5 se durar menos de 20 min), 5 pontos o segundo, e 5 de bônus ao treinar em 4 dias diferentes da semana (domingo a sábado).'];
}

/* ───────────────────────── Conquistas ───────────────────────── */

function cddConquistaModelo(): array {
    $d = [];
    $add = function ($chave, $emoji, $titulo, $desc, $metrica, $meta) use (&$d) { $d[] = compact('chave', 'emoji', 'titulo', 'desc', 'metrica', 'meta'); };
    $add('treino_1', '🥇', 'Primeiro treino', 'Registrou o primeiro treino no app.', 'treinos', 1);
    $add('treino_10', '💪', '10 treinos', 'Dez treinos registrados.', 'treinos', 10);
    $add('treino_25', '🔥', '25 treinos', 'Vinte e cinco treinos registrados.', 'treinos', 25);
    $add('treino_50', '🏅', '50 treinos', 'Cinquenta treinos registrados.', 'treinos', 50);
    $add('treino_100', '👑', '100 treinos', 'Cem treinos. Isso é rotina de verdade.', 'treinos', 100);
    $add('semana_4dias', '⚡', 'Semana perfeita', 'Treinou em 4 dias diferentes na mesma semana.', 'melhor_semana', 4);
    $add('const_2', '📆', 'Constância', 'Duas semanas seguidas com treino.', 'melhor_sequencia', 2);
    $add('const_4', '🗓️', 'Um mês firme', 'Quatro semanas seguidas com treino.', 'melhor_sequencia', 4);
    $add('const_8', '🌟', 'Dois meses de foco', 'Oito semanas seguidas com treino.', 'melhor_sequencia', 8);
    $add('const_12', '🏆', 'Trimestre de ouro', 'Doze semanas seguidas com treino.', 'melhor_sequencia', 12);
    $add('recorde_1', '🚀', 'Primeiro recorde', 'Bateu a sua maior carga em um exercício.', 'recordes', 1);
    $add('recorde_10', '🏋️', '10 recordes', 'Dez recordes de carga.', 'recordes', 10);
    $add('aula_1', '🎬', 'Primeira aula', 'Concluiu a primeira aula.', 'aulas', 1);
    $add('aula_10', '🍿', 'Maratonista', 'Dez aulas concluídas.', 'aulas', 10);
    $add('aula_30', '📚', 'Aprendiz dedicada', 'Trinta aulas concluídas.', 'aulas', 30);
    $add('modulo_1', '✅', 'Módulo concluído', 'Concluiu todas as aulas de um módulo.', 'modulos', 1);
    $add('modulo_3', '🧩', 'Três módulos', 'Três módulos concluídos.', 'modulos', 3);
    $add('anamnese', '📝', 'Perfil completo', 'Enviou a anamnese para a equipe.', 'anamnese', 1);
    $add('avaliou', '⭐', 'Primeira avaliação', 'Avaliou uma aula.', 'avaliacoes', 1);
    $add('comentou', '💬', 'Primeiro comentário', 'Comentou em uma aula.', 'comentarios', 1);
    $add('dia_7', '🌱', 'Primeira semana', 'Sete dias de programa.', 'dias', 7);
    $add('dia_30', '🌸', 'Um mês de CDD', 'Trinta dias de programa.', 'dias', 30);
    $add('dia_100', '💎', '100 dias de CDD', 'Cem dias de programa.', 'dias', 100);
    return $d;
}

/** Contagens que alimentam as conquistas. */
function cddMetricas(PDO $pdo, int $idaluna): array {
    $um = function ($sql, $par = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($par); return (int)$s->fetchColumn(); };
    $st = $pdo->prepare('SELECT idtreino, data, itens FROM cdd_treino WHERE idaluna = ? ORDER BY data, idtreino');
    $st->execute([$idaluna]);
    $treinos = $st->fetchAll();
    $dias = []; $maxPeso = []; $recordes = 0; $semanas = [];
    foreach ($treinos as $t) {
        $dias[$t['data']] = true; $semanas[cddInicioSemana($t['data'])][$t['data']] = true;
        $it = json_decode((string)$t['itens'], true);
        foreach (is_array($it) ? $it : [] as $ex) {
            $k = cddNorm($ex['exercicio'] ?? ''); if ($k === '') continue;
            $mx = 0.0; foreach ($ex['series'] ?? [] as $s) $mx = max($mx, (float)($s['peso'] ?? 0));
            if ($mx <= 0) continue;
            if (isset($maxPeso[$k]) && $mx > $maxPeso[$k]) $recordes++;
            $maxPeso[$k] = max($maxPeso[$k] ?? 0, $mx);
        }
    }
    $melhorSemana = 0; foreach ($semanas as $s) $melhorSemana = max($melhorSemana, count($s));
    $ks = array_keys($semanas); sort($ks); $melhorSeq = 0; $seq = 0; $ant = null;
    foreach ($ks as $k) { $seq = ($ant !== null && date('Y-m-d', strtotime($ant . ' +7 days')) === $k) ? $seq + 1 : 1; $melhorSeq = max($melhorSeq, $seq); $ant = $k; }
    $mat = cddMatricula($pdo, $idaluna);
    $diasProg = !empty($mat['inicio']) ? max(0, (int)floor((strtotime(cddHoje()) - strtotime($mat['inicio'])) / 86400)) + 1 : 0;
    $mods = 0;
    $m = $pdo->query('SELECT idmodulo FROM cdd_modulo WHERE publicado = 1')->fetchAll();
    foreach ($m as $x) {
        $tot = $um('SELECT COUNT(*) FROM cdd_aula WHERE idmodulo = ? AND publicado = 1', [$x['idmodulo']]);
        if (!$tot) continue;
        $fe = $um('SELECT COUNT(*) FROM cdd_progresso p JOIN cdd_aula a ON a.idaula = p.idaula WHERE p.idaluna = ? AND a.idmodulo = ? AND a.publicado = 1', [$idaluna, $x['idmodulo']]);
        if ($fe >= $tot) $mods++;
    }
    return ['treinos' => count($treinos), 'melhor_semana' => $melhorSemana, 'melhor_sequencia' => $melhorSeq, 'recordes' => $recordes,
            'aulas' => $um('SELECT COUNT(*) FROM cdd_progresso WHERE idaluna = ?', [$idaluna]), 'modulos' => $mods,
            'anamnese' => $um('SELECT COUNT(*) FROM cdd_anamnese WHERE idaluna = ? AND enviado_em IS NOT NULL', [$idaluna]),
            'avaliacoes' => $um('SELECT COUNT(*) FROM cdd_avaliacao WHERE idaluna = ?', [$idaluna]),
            'comentarios' => $um('SELECT COUNT(*) FROM cdd_comentario WHERE idaluna = ? AND equipe = 0', [$idaluna]), 'dias' => $diasProg];
}

/** Grava as conquistas que acabaram de ser alcançadas e devolve só as novas (para comemorar). */
function cddAtualizarConquistas(PDO $pdo, int $idaluna): array {
    $met = cddMetricas($pdo, $idaluna);
    $st = $pdo->prepare('SELECT chave FROM cdd_conquista WHERE idaluna = ?'); $st->execute([$idaluna]);
    $tem = array_flip(array_column($st->fetchAll(), 'chave'));
    $novas = [];
    foreach (cddConquistaModelo() as $c) {
        if (isset($tem[$c['chave']]) || ($met[$c['metrica']] ?? 0) < $c['meta']) continue;
        $pdo->prepare('INSERT INTO cdd_conquista (idaluna, chave, conquistada_em) VALUES (?, ?, ?)')->execute([$idaluna, $c['chave'], cddAgora()]);
        $novas[] = ['chave' => $c['chave'], 'emoji' => $c['emoji'], 'titulo' => $c['titulo'], 'desc' => $c['desc']];
    }
    return $novas;
}

function cddConquistasDaAluna(PDO $pdo, int $idaluna): array {
    cddAtualizarConquistas($pdo, $idaluna);
    $met = cddMetricas($pdo, $idaluna);
    $st = $pdo->prepare('SELECT chave, conquistada_em FROM cdd_conquista WHERE idaluna = ?'); $st->execute([$idaluna]);
    $em = []; foreach ($st->fetchAll() as $r) $em[$r['chave']] = $r['conquistada_em'];
    $out = [];
    foreach (cddConquistaModelo() as $c) {
        $atual = (int)($met[$c['metrica']] ?? 0);
        $out[] = ['chave' => $c['chave'], 'emoji' => $c['emoji'], 'titulo' => $c['titulo'], 'desc' => $c['desc'], 'meta' => $c['meta'], 'atual' => min($atual, $c['meta']),
                  'conquistada' => isset($em[$c['chave']]), 'em' => $em[$c['chave']] ?? null];
    }
    return $out;
}

/* ───────────────────────── Anamnese ───────────────────────── */

/** Perguntas por etapa. Mesmo conteúdo da anamnese da Intus, com campos objetivos no começo e o que importa para o público do CDD. */
function cddAnamneseModelo(): array {
    $t = function ($id, $label, $dica = '', $obrig = false) { return ['id' => $id, 'tipo' => 'texto', 'label' => $label, 'dica' => $dica, 'obrig' => $obrig]; };
    $n = function ($id, $label, $un, $obrig = false) { return ['id' => $id, 'tipo' => 'numero', 'label' => $label, 'un' => $un, 'obrig' => $obrig]; };
    $o = function ($id, $label, $ops, $obrig = false) { return ['id' => $id, 'tipo' => 'opcao', 'label' => $label, 'opcoes' => $ops, 'obrig' => $obrig]; };
    return [
        ['titulo' => 'Sobre você', 'emoji' => '🌸', 'perguntas' => [
            $n('idade', 'Quantos anos você tem?', 'anos', true), $n('peso', 'Peso atual', 'kg', true), $n('altura', 'Altura', 'cm', true),
            $o('nivel', 'Como você se considera no treino?', ['Iniciante', 'Intermediária', 'Avançada'], true),
            $o('gestacao', 'Você está gestante ou amamentando?', ['Não', 'Gestante', 'Amamentando'], true)]],
        ['titulo' => 'Seu objetivo', 'emoji' => '🎯', 'perguntas' => [
            $t('objetivo', 'Qual seu principal objetivo, quando pretende alcançá-lo e por quê?', 'Fale do que você quer mudar no corpo e na rotina, e o que isso representa para você.', true),
            $o('local_treino', 'Onde você vai treinar?', ['Academia', 'Em casa', 'Ao ar livre', 'Um pouco de cada'], true),
            $t('academia', 'Como é o seu local de treino?', 'Nome da academia, ou os equipamentos que você tem em casa (halteres, elásticos, banco...).'),
            $t('dias_horarios', 'Quais dias e horários você reservou para treinar e fazer cardio?', 'Ex.: seg, qua e sex às 7h; cardio ter e qui às 18h.')]],
        ['titulo' => 'Rotina e treino', 'emoji' => '🏃‍♀️', 'perguntas' => [
            $t('rotina_trabalho', 'Como é o seu trabalho e a sua rotina diária?', 'Se movimenta muito, fica sentada, viaja, cuida de filhos...'),
            $t('atividades_fisicas', 'Que atividades físicas você pratica hoje e com que frequência?', 'Descreva a divisão dos treinos e a intensidade.'),
            $t('exercicios_gosta', 'Quais exercícios você mais gosta de fazer?'), $t('exercicios_naogosta', 'Quais exercícios você menos gosta de fazer?'),
            $t('parceiro_treino', 'Você treina sozinha, com parceira ou com personal?')]],
        ['titulo' => 'Saúde', 'emoji' => '❤️', 'perguntas' => [
            $t('doencas', 'Tem ou já teve problema cardíaco, circulatório, respiratório ou de pressão?', 'Se não tiver, escreva "nenhum".', true),
            $t('articulares', 'Tem problema articular, postural ou alguma limitação? Já quebrou ossos ou lesionou ligamentos?', 'Descreva qualquer limitação física. Se não tiver, escreva "nenhuma".', true),
            $t('cirurgias', 'Já passou por alguma cirurgia? Quais e quando?', 'Deixe em branco se nenhuma.'),
            $t('medicamentos', 'Usa algum medicamento contínuo ou controlado?', 'Inclua anticoncepcional, reposição hormonal, suplementos e outros. Se não usar, escreva "nenhum".', true),
            $t('fatores_limitantes', 'Existe algo que limite o seu desempenho?', 'Ex.: labirintite, gastrite, diabetes, lipedema, TPM ou menstruação intensa, ansiedade, depressão.')]],
        ['titulo' => 'Sono e alimentação', 'emoji' => '🌙', 'perguntas' => [
            $t('sono', 'Quantas horas você dorme e como é a qualidade do seu sono?', 'Horas, qualidade (ruim, regular, boa, ótima) e se acorda durante a noite.'),
            $t('alimentacao', 'Segue ou já seguiu algum plano alimentar? Tem algum cuidado específico?', 'Com ou sem acompanhamento profissional. Alergias e restrições também.'),
            $t('observacoes', 'Quer contar mais alguma coisa para a equipe?', 'Qualquer informação importante de saúde ou de rotina.')]],
    ];
}

function cddAnamneseCampos(): array {
    $c = [];
    foreach (cddAnamneseModelo() as $e) foreach ($e['perguntas'] as $p) $c[$p['id']] = $p;
    return $c;
}

/** Só aceita as perguntas do modelo e limpa cada resposta pelo tipo. */
function cddAnamneseLimpar(array $entrada): array {
    $out = [];
    foreach (cddAnamneseCampos() as $id => $p) {
        $v = $entrada[$id] ?? '';
        if (is_array($v)) $v = '';
        if ($p['tipo'] === 'numero') { $v = str_replace(',', '.', trim((string)$v)); $v = preg_match('/^\d{1,4}(\.\d{1,2})?$/', $v) ? $v : ''; }
        elseif ($p['tipo'] === 'opcao') { $v = in_array((string)$v, $p['opcoes'], true) ? (string)$v : ''; }
        else $v = cddStr($v, 2000);
        $out[$id] = $v;
    }
    return $out;
}

/** Rotas da anamnese (qualquer aluna logada) e do ranking e conquistas (aluna com matrícula). Devolve null se a rota não é daqui. */
function cddRotaApp(PDO $pdo, string $metodo, string $acao, array $q, array $b, array $eu, array $ctx): ?array {
    $get = ($metodo === 'GET'); $id = (int)$eu['idaluna'];

    if ($acao === 'anamnese' && $get) {
        $st = $pdo->prepare('SELECT respostas, enviado_em, atualizado_em FROM cdd_anamnese WHERE idaluna = ?'); $st->execute([$id]); $r = $st->fetch();
        return cddResp(200, ['etapas' => cddAnamneseModelo(), 'respostas' => $r ? (json_decode((string)$r['respostas'], true) ?: new stdClass()) : new stdClass(),
                             'enviado_em' => $r['enviado_em'] ?? null, 'atualizado_em' => $r['atualizado_em'] ?? null]);
    }
    if ($acao === 'anamnese_salvar' && !$get) {
        $resp = cddAnamneseLimpar(is_array($b['respostas'] ?? null) ? $b['respostas'] : []);
        $enviar = !empty($b['enviar']);
        if ($enviar) {
            $faltam = [];
            foreach (cddAnamneseCampos() as $pid => $p) { if (!empty($p['obrig']) && $resp[$pid] === '') $faltam[] = $p['label']; }
            if ($faltam) return cddErro(422, 'anamnese_incompleta', 'Falta responder: ' . implode('; ', array_slice($faltam, 0, 4)) . (count($faltam) > 4 ? ' e mais ' . (count($faltam) - 4) : '') . '.', ['faltam' => $faltam]);
        }
        $json = json_encode($resp, JSON_UNESCAPED_UNICODE);
        $st = $pdo->prepare('SELECT enviado_em FROM cdd_anamnese WHERE idaluna = ?'); $st->execute([$id]); $ex = $st->fetch();
        $agora = cddAgora();
        if ($ex) {
            $env = $enviar ? $agora : ($ex['enviado_em'] ?: null);
            $pdo->prepare('UPDATE cdd_anamnese SET respostas = ?, enviado_em = ?, atualizado_em = ? WHERE idaluna = ?')->execute([$json, $env, $agora, $id]);
        } else {
            $pdo->prepare('INSERT INTO cdd_anamnese (idaluna, respostas, enviado_em, atualizado_em) VALUES (?, ?, ?, ?)')->execute([$id, $json, $enviar ? $agora : null, $agora]);
        }
        if ($enviar) cddRegistrarEvento($pdo, $id, 'anamnese_enviada', 'aluna');
        return cddResp(200, ['ok' => true, 'enviada' => $enviar || !empty($ex['enviado_em']), 'novas' => $enviar ? cddAtualizarConquistas($pdo, $id) : []]);
    }
    if ($acao === 'perfil_ranking' && !$get) {
        $pdo->prepare('UPDATE cdd_aluna SET ranking_visivel = ? WHERE idaluna = ?')->execute([!empty($b['visivel']) ? 1 : 0, $id]);
        return cddResp(200, ['ok' => true, 'visivel' => !empty($b['visivel'])]);
    }
    return null;
}

/** Ranking e conquistas: só com matrícula ativa (a checagem fica em cddTratar). */
function cddRotaMembro(PDO $pdo, string $metodo, string $acao, array $q, array $eu): ?array {
    $id = (int)$eu['idaluna'];
    if ($acao === 'ranking' && $metodo === 'GET') {
        $p = in_array($q['periodo'] ?? '', ['semana', 'mes', 'geral'], true) ? $q['periodo'] : 'semana';
        return cddResp(200, ['ranking' => cddRanking($pdo, $id, $p)]);
    }
    if ($acao === 'conquistas' && $metodo === 'GET') {
        $l = cddConquistasDaAluna($pdo, $id);
        return cddResp(200, ['conquistas' => $l, 'total' => count($l), 'ganhas' => count(array_filter($l, function ($c) { return $c['conquistada']; }))]);
    }
    return null;
}

/** Rotas da equipe para anamnese. Devolve null se a rota não é daqui. */
function cddAdminAnamnese(PDO $pdo, string $metodo, string $acao, array $q): ?array {
    if ($acao === 'a_anamneses' && $metodo === 'GET') {
        $st = $pdo->query('SELECT a.idaluna, a.nome, a.email, n.enviado_em, n.atualizado_em FROM cdd_anamnese n JOIN cdd_aluna a ON a.idaluna = n.idaluna ORDER BY (CASE WHEN n.enviado_em IS NULL THEN 1 ELSE 0 END), n.enviado_em DESC, n.atualizado_em DESC LIMIT 300');
        return cddResp(200, ['anamneses' => $st->fetchAll()]);
    }
    if ($acao === 'a_anamnese' && $metodo === 'GET') {
        $id = (int)($q['id'] ?? 0);
        $st = $pdo->prepare('SELECT a.nome, a.email, n.respostas, n.enviado_em, n.atualizado_em FROM cdd_anamnese n JOIN cdd_aluna a ON a.idaluna = n.idaluna WHERE n.idaluna = ?'); $st->execute([$id]); $r = $st->fetch();
        if (!$r) return cddErro(404, 'anamnese_nao_encontrada', 'Esta aluna ainda não começou a anamnese.');
        $resp = json_decode((string)$r['respostas'], true) ?: [];
        $etapas = [];
        foreach (cddAnamneseModelo() as $e) {
            $itens = [];
            foreach ($e['perguntas'] as $p) { $v = $resp[$p['id']] ?? ''; $itens[] = ['pergunta' => $p['label'], 'resposta' => $v === '' ? null : $v . (($p['tipo'] ?? '') === 'numero' && $v !== '' ? ' ' . $p['un'] : '')]; }
            $etapas[] = ['titulo' => $e['titulo'], 'emoji' => $e['emoji'], 'itens' => $itens];
        }
        return cddResp(200, ['aluna' => ['nome' => $r['nome'], 'email' => $r['email']], 'enviado_em' => $r['enviado_em'], 'atualizado_em' => $r['atualizado_em'], 'etapas' => $etapas]);
    }
    return null;
}
