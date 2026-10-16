<?php
/**
 * CDD: treino do dia (cargas e repetições), jornada, liberação de módulos por tempo, comentários e avaliações.
 * Biblioteca interna (o "_" no nome faz o .htaccess bloquear o acesso direto). Carregada por _nucleo.php.
 *
 * O registro de treino segue o mesmo desenho da Intus (intus_sessao): uma sessão por dia e por ficha, com `itens` em JSON
 * [{exercicio, series:[{peso, reps}]}], duração, comentário e esforço. Assim a conexão futura com a Intus é um mapeamento direto
 * (idaula = idficha, itens iguais). Nada daqui lê ou grava tabela da Intus.
 */

/* ───────────────────────── Liberação por tempo ───────────────────────── */

/**
 * Regra do módulo: `liberacao_dias` conta a partir do início da matrícula e `liberacao_data` é uma data fixa.
 * Com as duas preenchidas vale a mais tardia. Sem nenhuma, o módulo está liberado desde o início.
 * @return array ['liberado' => bool, 'libera_em' => ?string AAAA-MM-DD]
 */
function cddModuloLiberacao(array $m, ?string $inicioMatricula): array {
    $datas = [];
    $dias = $m['liberacao_dias'] ?? null;
    if ($dias !== null && $dias !== '' && $inicioMatricula) $datas[] = date('Y-m-d', strtotime($inicioMatricula . ' +' . (int)$dias . ' days'));
    if (!empty($m['liberacao_data'])) $datas[] = substr((string)$m['liberacao_data'], 0, 10);
    if (!$datas) return ['liberado' => true, 'libera_em' => null];
    rsort($datas);
    return ['liberado' => $datas[0] <= cddHoje(), 'libera_em' => $datas[0]];
}

/** Aula que a aluna pode abrir, ou o erro pronto (404 se não existe, 403 se o módulo ainda não liberou). */
function cddAulaAcessivel(PDO $pdo, int $idaula, int $idaluna, bool $admin, ?string $inicio) {
    $a = cddAulaCompleta($pdo, $idaula, $idaluna, $admin, $inicio);
    if (!$a) return cddErro(404, 'aula_nao_encontrada', 'Aula não encontrada.');
    if (!empty($a['_bloqueada'])) {
        $q = $a['libera_em'] ? date('d/m/Y', strtotime($a['libera_em'])) : '';
        return cddErro(403, 'modulo_bloqueado', 'Este módulo será liberado' . ($q ? ' em ' . $q : ' em breve') . '.', ['libera_em' => $a['libera_em']]);
    }
    return $a;
}

/* ───────────────────────── Registro de treino ───────────────────────── */

function cddPesoReps($v): string {
    $v = str_replace(',', '.', trim((string)$v));
    return preg_match('/^\d{1,4}(\.\d{1,2})?$/', $v) ? $v : '';
}

/** Itens do treino já limpos: [{exercicio, series:[{peso, reps}]}], só séries com algum valor. */
function cddItensTreino($entrada): array {
    $out = [];
    if (!is_array($entrada)) return $out;
    foreach (array_slice($entrada, 0, 60) as $it) {
        if (!is_array($it)) continue;
        $nome = cddStr($it['exercicio'] ?? '', 200); if ($nome === '') continue;
        $series = [];
        foreach (array_slice(is_array($it['series'] ?? null) ? $it['series'] : [], 0, 12) as $s) {
            $p = cddPesoReps($s['peso'] ?? ''); $r = preg_match('/^\d{1,3}$/', trim((string)($s['reps'] ?? ''))) ? trim((string)$s['reps']) : '';
            $series[] = ['peso' => $p, 'reps' => $r];
        }
        $temAlgo = false; foreach ($series as $s) { if ($s['peso'] !== '' || $s['reps'] !== '') $temAlgo = true; }
        $out[] = ['exercicio' => $nome, 'series' => $temAlgo ? $series : []];
    }
    return $out;
}

/** Quantas séries mostrar para o exercício: lê o primeiro número do campo "séries" da ficha (3 se não houver, no máximo 8). */
function cddQtdSeries(string $campo): int {
    return preg_match('/\d+/', $campo, $m) ? max(1, min(8, (int)$m[0])) : 3;
}

/**
 * Histórico de cargas da aluna por exercício (chave normalizada): última sessão com carga e recorde de peso.
 * @return array chave => ['nome', 'ultimo' => ['data','series'], 'max' => ['peso','reps','data'], 'sessoes']
 */
function cddHistoricoCargas(PDO $pdo, int $idaluna, int $excluirIdtreino = 0): array {
    $st = $pdo->prepare('SELECT idtreino, data, itens FROM cdd_treino WHERE idaluna = ? AND idtreino <> ? ORDER BY data DESC, idtreino DESC LIMIT 300');
    $st->execute([$idaluna, $excluirIdtreino]);
    $h = [];
    foreach ($st->fetchAll() as $s) {
        $itens = json_decode((string)$s['itens'], true);
        if (!is_array($itens)) continue;
        foreach ($itens as $it) {
            $k = cddNorm($it['exercicio'] ?? ''); if ($k === '' || empty($it['series'])) continue;
            if (!isset($h[$k])) $h[$k] = ['nome' => $it['exercicio'], 'ultimo' => ['data' => $s['data'], 'series' => $it['series']], 'max' => ['peso' => 0, 'reps' => 0, 'data' => null], 'sessoes' => 0];
            $h[$k]['sessoes']++;
            foreach ($it['series'] as $sr) {
                $p = (float)($sr['peso'] ?? 0);
                if ($p > $h[$k]['max']['peso']) $h[$k]['max'] = ['peso' => $p, 'reps' => (int)($sr['reps'] ?? 0), 'data' => $s['data']];
            }
        }
    }
    return $h;
}

function cddResumoTreino(array $itens): array {
    $ex = 0; $se = 0; $vol = 0.0;
    foreach ($itens as $it) {
        if (!$it['series']) continue;
        $ex++;
        foreach ($it['series'] as $s) { if ($s['peso'] !== '' || $s['reps'] !== '') $se++; $vol += (float)$s['peso'] * (int)$s['reps']; }
    }
    return ['exercicios' => $ex, 'series' => $se, 'volume_kg' => (int)round($vol)];
}

/** Aula de treino sugerida: continua no módulo do último treino, na próxima letra; sem treino ainda, abre o primeiro protocolo liberado. */
function cddProximoTreino(PDO $pdo, int $idaluna, array $modulos): ?array {
    $comFicha = array_flip(array_map('intval', array_column($pdo->query('SELECT DISTINCT idaula FROM cdd_ficha_item')->fetchAll(), 'idaula')));
    $cands = [];
    foreach ($modulos as $m) {
        if (empty($m['liberado'])) continue;
        $aulas = array_values(array_filter($m['aulas'], function ($a) use ($comFicha) { return isset($comFicha[$a['idaula']]); }));
        if ($aulas) $cands[] = ['modulo' => $m, 'aulas' => $aulas];
    }
    if (!$cands) return null;
    $st = $pdo->prepare('SELECT idaula, data FROM cdd_treino WHERE idaluna = ? ORDER BY data DESC, idtreino DESC LIMIT 1');
    $st->execute([$idaluna]);
    $ult = $st->fetch();
    $esc = null; $alvo = null;
    if ($ult) {
        foreach ($cands as $c) {
            foreach ($c['aulas'] as $i => $a) {
                if ($a['idaula'] === (int)$ult['idaula']) { $esc = $c; $alvo = $c['aulas'][($i + 1) % count($c['aulas'])]; break 2; }
            }
        }
    }
    if (!$esc) {
        foreach ($cands as $c) { if ($c['modulo']['grupo'] === 'protocolo') { $esc = $c; break; } }
        $esc = $esc ?: $cands[0];
        $alvo = $esc['aulas'][0];
    }
    $hoje = $pdo->prepare('SELECT COUNT(*) FROM cdd_treino WHERE idaluna = ? AND data = ?'); $hoje->execute([$idaluna, cddHoje()]);
    return ['idaula' => $alvo['idaula'], 'titulo' => $alvo['titulo'], 'idmodulo' => $esc['modulo']['idmodulo'], 'modulo' => $esc['modulo']['titulo'],
            'treinou_hoje' => (int)$hoje->fetchColumn() > 0,
            'alternativas' => array_map(function ($a) { return ['idaula' => $a['idaula'], 'titulo' => $a['titulo']]; }, $esc['aulas'])];
}

/** Semanas seguidas (de segunda a domingo) com pelo menos um dia de treino, contando até a semana atual ou a anterior. */
function cddSequenciaSemanas(array $datas): int {
    $sem = [];
    foreach ($datas as $d) $sem[date('o-W', strtotime($d))] = true;
    $n = 0; $t = time();
    if (!isset($sem[date('o-W', $t)])) $t -= 7 * 86400;   // semana atual ainda em aberto não quebra a sequência
    while (isset($sem[date('o-W', $t)])) { $n++; $t -= 7 * 86400; }
    return $n;
}

function cddJornada(PDO $pdo, int $idaluna, array $modulos, ?string $inicio, array $eu = []): array {
    $st = $pdo->prepare('SELECT idtreino, idaula, data, duracao_seg, esforco, itens FROM cdd_treino WHERE idaluna = ? ORDER BY data DESC, idtreino DESC LIMIT 400');
    $st->execute([$idaluna]);
    $treinos = $st->fetchAll();
    $titulos = [];
    foreach ($modulos as $m) foreach ($m['aulas'] as $a) $titulos[$a['idaula']] = $a['titulo'];
    $porDia = []; $recentes = []; $semana = []; $segunda = date('Y-m-d', strtotime('monday this week'));
    foreach ($treinos as $t) {
        $porDia[$t['data']] = ($porDia[$t['data']] ?? 0) + 1;
        if ($t['data'] >= $segunda) $semana[$t['data']] = true;
        if (count($recentes) < 10) {
            $r = cddResumoTreino(cddItensTreino(json_decode((string)$t['itens'], true)));
            $recentes[] = ['idtreino' => (int)$t['idtreino'], 'idaula' => (int)$t['idaula'], 'titulo' => $titulos[(int)$t['idaula']] ?? 'Treino', 'data' => $t['data'],
                           'duracao_seg' => (int)$t['duracao_seg'], 'esforco' => $t['esforco'] === null ? null : (int)$t['esforco'], 'series' => $r['series'], 'volume_kg' => $r['volume_kg']];
        }
    }
    $cal = [];
    $ini = strtotime('monday this week') - 7 * 7 * 86400;   // 8 semanas, terminando na atual
    for ($i = 0; $i < 56; $i++) { $d = date('Y-m-d', $ini + $i * 86400); $cal[] = ['d' => $d, 'n' => $porDia[$d] ?? 0, 'futuro' => $d > cddHoje()]; }
    // evolução por exercício (do mais antigo para o mais novo)
    $evo = [];
    foreach (array_reverse($treinos) as $t) {
        $itens = json_decode((string)$t['itens'], true); if (!is_array($itens)) continue;
        foreach ($itens as $it) {
            $k = cddNorm($it['exercicio'] ?? ''); if ($k === '' || empty($it['series'])) continue;
            $mx = 0.0; foreach ($it['series'] as $s) $mx = max($mx, (float)($s['peso'] ?? 0));
            if ($mx <= 0) continue;
            if (!isset($evo[$k])) $evo[$k] = ['exercicio' => $it['exercicio'], 'primeira' => $mx, 'atual' => $mx, 'maxima' => $mx, 'sessoes' => 0];
            $evo[$k]['atual'] = $mx; $evo[$k]['maxima'] = max($evo[$k]['maxima'], $mx); $evo[$k]['sessoes']++;
        }
    }
    uasort($evo, function ($a, $b) { return $b['sessoes'] <=> $a['sessoes']; });
    $tot = 0; $fe = 0;
    foreach ($modulos as $m) { if (!empty($m['liberado'])) { $tot += $m['total']; $fe += $m['feitas']; } }
    $dias = $inicio ? max(0, (int)floor((strtotime(cddHoje()) - strtotime($inicio)) / 86400)) + 1 : 0;
    return [
        'inicio' => $inicio, 'dia_do_programa' => $dias,
        'treinos_total' => count($treinos), 'treinos_semana' => count($semana), 'sequencia_semanas' => cddSequenciaSemanas(array_keys($porDia)),
        'aulas_total' => $tot, 'aulas_feitas' => $fe,
        'calendario' => $cal, 'recentes' => $recentes, 'evolucao' => array_values(array_slice($evo, 0, 12)),
        'modulos' => array_map(function ($m) {
            return ['idmodulo' => $m['idmodulo'], 'titulo' => $m['titulo'], 'grupo' => $m['grupo'], 'liberado' => $m['liberado'], 'libera_em' => $m['libera_em'], 'total' => $m['total'], 'feitas' => $m['feitas']];
        }, $modulos),
        'proximo' => cddProximoTreino($pdo, $idaluna, $modulos),
    ];
}

/* ───────────────────────── Comentários e avaliações ───────────────────────── */

function cddNomePublico(string $nome): string {
    $p = preg_split('/\s+/', trim($nome));
    $n = $p[0] ?? 'Aluna';
    if (count($p) > 1) $n .= ' ' . (function_exists('mb_substr') ? mb_strtoupper(mb_substr(end($p), 0, 1)) : strtoupper(substr(end($p), 0, 1))) . '.';
    return $n;
}

function cddComentariosDaAula(PDO $pdo, int $idaula, int $idaluna): array {
    $st = $pdo->prepare('SELECT c.idcomentario, c.parent_id, c.idaluna, c.texto, c.equipe, c.criado_em, a.nome FROM cdd_comentario c JOIN cdd_aluna a ON a.idaluna = c.idaluna
                         WHERE c.idaula = ? AND c.oculto = 0 ORDER BY c.criado_em ASC, c.idcomentario ASC LIMIT 400');
    $st->execute([$idaula]);
    $topo = []; $resp = [];
    foreach ($st->fetchAll() as $c) {
        $x = ['id' => (int)$c['idcomentario'], 'autor' => $c['equipe'] ? 'Equipe CDD' : cddNomePublico($c['nome']), 'equipe' => (bool)(int)$c['equipe'], 'texto' => $c['texto'],
              'quando' => $c['criado_em'], 'meu' => (int)$c['idaluna'] === $idaluna, 'respostas' => []];
        if ($c['parent_id']) $resp[(int)$c['parent_id']][] = $x; else $topo[] = $x;
    }
    foreach ($topo as $i => $t) $topo[$i]['respostas'] = $resp[$t['id']] ?? [];
    return array_reverse($topo);   // mais novos primeiro; as respostas ficam em ordem de conversa
}

/** Rotas da aluna (e da equipe) para treino, jornada, comentário e avaliação. Devolve null quando a rota não é daqui. */
function cddRotaAluna(PDO $pdo, string $metodo, string $acao, array $q, array $b, array $eu, array $mat, array $ctx): ?array {
    $get = ($metodo === 'GET');
    $idaluna = (int)$eu['idaluna']; $admin = ($eu['papel'] === 'admin');
    $inicio = $mat['inicio'] ?? null;
    $ip = $ctx['ip'] ?? '0.0.0.0';

    if ($acao === 'treino' && $get) {
        $a = cddAulaAcessivel($pdo, (int)($q['id'] ?? 0), $idaluna, $admin, $inicio);
        if (isset($a[0]) && is_int($a[0])) return $a;
        $hist = cddHistoricoCargas($pdo, $idaluna);
        $mapa = [];
        foreach ($a['ficha'] as $it) {
            $k = cddNorm($it['exercicio']);
            if (isset($hist[$k])) $mapa[$k] = ['ultimo' => $hist[$k]['ultimo'], 'max' => $hist[$k]['max'], 'sessoes' => $hist[$k]['sessoes']];
        }
        $hoje = $pdo->prepare('SELECT idtreino, itens, duracao_seg, esforco, comentario FROM cdd_treino WHERE idaluna = ? AND idaula = ? AND data = ?');
        $hoje->execute([$idaluna, $a['idaula'], cddHoje()]); $h = $hoje->fetch();
        foreach ($a['ficha'] as $i => $it) $a['ficha'][$i]['qtd_series'] = cddQtdSeries((string)$it['series']);
        return cddResp(200, ['aula' => $a, 'historico' => $mapa,
            'hoje' => $h ? ['idtreino' => (int)$h['idtreino'], 'itens' => json_decode((string)$h['itens'], true), 'duracao_seg' => (int)$h['duracao_seg'], 'esforco' => $h['esforco'], 'comentario' => $h['comentario']] : null]);
    }

    if ($acao === 'treino_salvar' && !$get) {
        $ida = (int)($b['idaula'] ?? 0);
        $a = cddAulaAcessivel($pdo, $ida, $idaluna, $admin, $inicio);
        if (isset($a[0]) && is_int($a[0])) return $a;
        $itens = cddItensTreino($b['itens'] ?? []);
        $res = cddResumoTreino($itens);
        if ($res['series'] === 0) return cddErro(422, 'treino_vazio', 'Anote a carga ou as repetições de pelo menos uma série.');
        $data = cddData($b['data'] ?? '') ?: cddHoje();
        if ($data < date('Y-m-d', time() - 3 * 86400) || $data > date('Y-m-d', time() + 86400)) $data = cddHoje();
        $dur = max(0, min(14400, (int)($b['duracao_seg'] ?? 0)));
        $esf = isset($b['esforco']) && (int)$b['esforco'] >= 1 && (int)$b['esforco'] <= 5 ? (int)$b['esforco'] : null;
        $com = cddStr($b['comentario'] ?? '', 600);
        $div = '';
        foreach ($a['ficha'] as $it) { if (!empty($it['grupo'])) { $div = cddStr($it['grupo'], 80); break; } }
        $st = $pdo->prepare('SELECT idtreino FROM cdd_treino WHERE idaluna = ? AND idaula = ? AND data = ?'); $st->execute([$idaluna, $ida, $data]);
        $id = (int)$st->fetchColumn();
        $json = json_encode($itens, JSON_UNESCAPED_UNICODE);
        $antes = cddHistoricoCargas($pdo, $idaluna, $id);
        if ($id) {
            $pdo->prepare('UPDATE cdd_treino SET itens = ?, duracao_seg = ?, esforco = ?, comentario = ?, divisao = ?, atualizado_em = ? WHERE idtreino = ?')->execute([$json, $dur, $esf, $com ?: null, $div ?: null, cddAgora(), $id]);
        } else {
            $pdo->prepare('INSERT INTO cdd_treino (idaluna, idaula, divisao, data, duracao_seg, esforco, comentario, itens, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idaluna, $ida, $div ?: null, $data, $dur, $esf, $com ?: null, $json, cddAgora(), cddAgora()]);
            $id = (int)$pdo->lastInsertId();
        }
        $recordes = [];
        foreach ($itens as $it) {
            $k = cddNorm($it['exercicio']); $mx = 0.0; $rp = 0;
            foreach ($it['series'] as $s) { if ((float)$s['peso'] > $mx) { $mx = (float)$s['peso']; $rp = (int)$s['reps']; } }
            if ($mx > 0 && isset($antes[$k]) && $mx > $antes[$k]['max']['peso']) $recordes[] = ['exercicio' => $it['exercicio'], 'peso' => $mx, 'reps' => $rp, 'anterior' => $antes[$k]['max']['peso']];
        }
        $p = $pdo->prepare('SELECT COUNT(*) FROM cdd_progresso WHERE idaluna = ? AND idaula = ?'); $p->execute([$idaluna, $ida]);
        if ((int)$p->fetchColumn() === 0) $pdo->prepare('INSERT INTO cdd_progresso (idaluna, idaula, concluida_em) VALUES (?, ?, ?)')->execute([$idaluna, $ida, cddAgora()]);
        return cddResp(200, ['idtreino' => $id, 'recordes' => $recordes, 'resumo' => $res]);
    }

    if ($acao === 'treino_excluir' && !$get) {
        $pdo->prepare('DELETE FROM cdd_treino WHERE idtreino = ? AND idaluna = ?')->execute([(int)($b['idtreino'] ?? 0), $idaluna]);
        return cddResp(200, ['ok' => true]);
    }

    if ($acao === 'jornada' && $get) {
        $mods = cddModulosParaAluna($pdo, $idaluna, false, $admin ? cddHoje() : $inicio);
        return cddResp(200, ['jornada' => cddJornada($pdo, $idaluna, $mods, $inicio, $eu)]);
    }

    if ($acao === 'comentarios' && $get) {
        $a = cddAulaAcessivel($pdo, (int)($q['id'] ?? 0), $idaluna, $admin, $inicio);
        if (isset($a[0]) && is_int($a[0])) return $a;
        $m = $pdo->prepare('SELECT AVG(nota) AS media, COUNT(*) AS votos FROM cdd_avaliacao WHERE idaula = ?'); $m->execute([$a['idaula']]); $m = $m->fetch();
        $n = $pdo->prepare('SELECT nota FROM cdd_avaliacao WHERE idaula = ? AND idaluna = ?'); $n->execute([$a['idaula'], $idaluna]); $n = $n->fetchColumn();
        return cddResp(200, ['comentarios' => cddComentariosDaAula($pdo, $a['idaula'], $idaluna), 'media' => $m['votos'] ? round((float)$m['media'], 1) : null, 'votos' => (int)$m['votos'], 'minha_nota' => $n === false ? null : (int)$n]);
    }

    if ($acao === 'comentar' && !$get) {
        $a = cddAulaAcessivel($pdo, (int)($b['id'] ?? 0), $idaluna, $admin, $inicio);
        if (isset($a[0]) && is_int($a[0])) return $a;
        $texto = cddStr($b['texto'] ?? '', 1000);
        if (function_exists('mb_strlen') ? mb_strlen($texto) < 2 : strlen($texto) < 2) return cddErro(422, 'comentario_curto', 'Escreva um pouco mais para enviar o comentário.');
        if (cddBloqueado($pdo, 'comentar', $ip, 'id' . $idaluna, 60, 12, 600)) return cddErro(429, 'muitos_comentarios', 'Você enviou muitos comentários em pouco tempo. Aguarde alguns minutos.');
        $parent = (int)($b['parent'] ?? 0);
        if ($parent) {
            $pp = $pdo->prepare('SELECT idaula, parent_id FROM cdd_comentario WHERE idcomentario = ? AND oculto = 0'); $pp->execute([$parent]); $pp = $pp->fetch();
            if (!$pp || (int)$pp['idaula'] !== $a['idaula'] || $pp['parent_id']) return cddErro(422, 'comentario_invalido', 'Não foi possível responder a este comentário.');
        }
        cddRegistrarTentativa($pdo, 'c:id' . $idaluna, 'comentar'); cddRegistrarTentativa($pdo, 'ip:' . $ip, 'comentar');
        $pdo->prepare('INSERT INTO cdd_comentario (idaula, idaluna, parent_id, texto, oculto, equipe, criado_em) VALUES (?, ?, ?, ?, 0, ?, ?)')
            ->execute([$a['idaula'], $idaluna, $parent ?: null, $texto, $admin ? 1 : 0, cddAgora()]);
        return cddResp(200, ['ok' => true, 'comentarios' => cddComentariosDaAula($pdo, $a['idaula'], $idaluna)]);
    }

    if ($acao === 'comentario_excluir' && !$get) {
        $id = (int)($b['id'] ?? 0);
        $c = $pdo->prepare('SELECT idaluna FROM cdd_comentario WHERE idcomentario = ?'); $c->execute([$id]); $c = $c->fetch();
        if (!$c || ((int)$c['idaluna'] !== $idaluna && !$admin)) return cddErro(403, 'sem_permissao', 'Você só pode apagar os seus comentários.');
        $pdo->prepare('UPDATE cdd_comentario SET oculto = 1 WHERE idcomentario = ? OR parent_id = ?')->execute([$id, $id]);
        return cddResp(200, ['ok' => true]);
    }

    if ($acao === 'avaliar' && !$get) {
        $a = cddAulaAcessivel($pdo, (int)($b['id'] ?? 0), $idaluna, $admin, $inicio);
        if (isset($a[0]) && is_int($a[0])) return $a;
        $nota = (int)($b['nota'] ?? 0);
        if ($nota < 1 || $nota > 5) return cddErro(422, 'nota_invalida', 'A nota vai de 1 a 5.');
        $u = $pdo->prepare('UPDATE cdd_avaliacao SET nota = ?, atualizado_em = ? WHERE idaluna = ? AND idaula = ?');
        $u->execute([$nota, cddAgora(), $idaluna, $a['idaula']]);
        if ($u->rowCount() === 0) {
            $e = $pdo->prepare('SELECT COUNT(*) FROM cdd_avaliacao WHERE idaluna = ? AND idaula = ?'); $e->execute([$idaluna, $a['idaula']]);
            if ((int)$e->fetchColumn() === 0) $pdo->prepare('INSERT INTO cdd_avaliacao (idaluna, idaula, nota, atualizado_em) VALUES (?, ?, ?, ?)')->execute([$idaluna, $a['idaula'], $nota, cddAgora()]);
        }
        return cddResp(200, ['ok' => true, 'minha_nota' => $nota]);
    }
    return null;
}

/** Rotas da equipe: moderação de comentários e relatório de avaliações. Devolve null quando a rota não é daqui. */
function cddAdminExtra(PDO $pdo, string $metodo, string $acao, array $q, array $b, int $idadmin): ?array {
    $get = ($metodo === 'GET');
    if ($acao === 'a_comentarios' && $get) {
        $so = ($q['filtro'] ?? '') === 'sem_resposta';
        $sql = 'SELECT c.idcomentario, c.idaula, c.texto, c.oculto, c.criado_em, a.nome, a.email, au.titulo AS aula, m.titulo AS modulo
                FROM cdd_comentario c JOIN cdd_aluna a ON a.idaluna = c.idaluna JOIN cdd_aula au ON au.idaula = c.idaula JOIN cdd_modulo m ON m.idmodulo = au.idmodulo
                WHERE c.parent_id IS NULL AND c.equipe = 0';
        if ($so) $sql .= ' AND c.oculto = 0 AND NOT EXISTS (SELECT 1 FROM cdd_comentario r WHERE r.parent_id = c.idcomentario AND r.equipe = 1 AND r.oculto = 0)';
        $st = $pdo->query($sql . ' ORDER BY c.criado_em DESC, c.idcomentario DESC LIMIT 150');
        $lista = $st->fetchAll();
        $rp = $pdo->prepare('SELECT r.idcomentario, r.texto, r.equipe, r.oculto, r.criado_em, a.nome FROM cdd_comentario r JOIN cdd_aluna a ON a.idaluna = r.idaluna WHERE r.parent_id = ? ORDER BY r.criado_em');
        foreach ($lista as &$c) { $c['idcomentario'] = (int)$c['idcomentario']; $rp->execute([$c['idcomentario']]); $c['respostas'] = $rp->fetchAll(); }
        return cddResp(200, ['comentarios' => $lista]);
    }
    if ($acao === 'a_comentario_responder' && !$get) {
        $id = (int)($b['id'] ?? 0); $texto = cddStr($b['texto'] ?? '', 1000);
        $pp = $pdo->prepare('SELECT idaula, parent_id FROM cdd_comentario WHERE idcomentario = ?'); $pp->execute([$id]); $pp = $pp->fetch();
        if (!$pp || $pp['parent_id'] || $texto === '') return cddErro(422, 'dados_invalidos', 'Escreva a resposta.');
        $pdo->prepare('INSERT INTO cdd_comentario (idaula, idaluna, parent_id, texto, oculto, equipe, criado_em) VALUES (?, ?, ?, ?, 0, 1, ?)')->execute([(int)$pp['idaula'], $idadmin, $id, $texto, cddAgora()]);
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'a_comentario_ocultar' && !$get) {
        $pdo->prepare('UPDATE cdd_comentario SET oculto = ? WHERE idcomentario = ?')->execute([!empty($b['oculto']) ? 1 : 0, (int)($b['id'] ?? 0)]);
        return cddResp(200, ['ok' => true]);
    }
    return null;
}
