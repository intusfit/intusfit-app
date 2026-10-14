<?php
/**
 * ROTAS DOS ACESSOS (incluído por catalogo.php; o "_" no nome faz o .htaccess bloquear o acesso direto).
 *   ?action=acessos          só ADMINISTRADOR. Linha do tempo de logins/aberturas/treinos/cardios de cada pessoa (aluno ou equipe), com IP, local e horário.
 *   ?action=acessos_painel   equipe. Quadro da Frequência e Ranking: tempo real, horários de pico (Brasília e fuso do aluno), locais e estatísticas, SÓ dos alunos
 *                            que a pessoa pode ver (administrador: todos; professor: os dele). O IP só aparece para o administrador.
 * Leitura apenas. Dados de intus_acesso (ver _acesso.php).
 */

function _acessoNomes(PDO $pdo, ?string $tblAtleta, array $linhas): array
{
    $ids = [];
    foreach ($linhas as $l) { if (($l['nome'] ?? '') === '' && $l['tipo'] === 'aluno') $ids[(int)$l['user_id']] = true; }
    $mapa = [];
    if ($ids && $tblAtleta) {
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT idatleta, nome FROM `$tblAtleta` WHERE idatleta IN ($ph)");
            $st->execute(array_keys($ids));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $mapa[(int)$r['idatleta']] = (string)$r['nome'];
        } catch (Throwable $e) {}
    }
    foreach ($linhas as &$l) { if (($l['nome'] ?? '') === '') $l['nome'] = $mapa[(int)$l['user_id']] ?? (($l['tipo'] === 'aluno' ? 'Aluno #' : 'Usuário #') . $l['user_id']); }
    unset($l);
    return $linhas;
}

function _acessoLocal(array $l): string
{
    $p = [];
    if (($l['cidade'] ?? '') !== '') $p[] = $l['cidade'];
    if (($l['regiao'] ?? '') !== '' && ($l['regiao'] ?? '') !== ($l['cidade'] ?? '')) $p[] = $l['regiao'];
    if (($l['pais'] ?? '') !== '') $p[] = $l['pais'];
    return $p ? implode(', ', $p) : '';
}

function intusAcessoRota(PDO $pdo, array $ctx, bool $ehAluno, string $action, ?string $tblAtleta): void
{
    if ($ehAluno) { http_response_code(403); echo json_encode(['error' => 'somente equipe']); return; }
    $admin = !empty($ctx['admin']);
    require_once __DIR__ . '/_acesso.php';
    require_once __DIR__ . '/_ip.php';
    if (!intusAcessoGarantirTabela($pdo)) { http_response_code(500); echo json_encode(['error' => 'tabela de acessos indisponivel']); return; }

    // ─────────────── Administrador: acessos de qualquer pessoa ───────────────
    if ($action === 'acessos') {
        if (!$admin) { http_response_code(403); echo json_encode(['error' => 'somente administrador']); return; }
        $dias = max(1, min(90, (int)($_GET['dias'] ?? 7)));
        $tipo = (string)($_GET['tipo'] ?? '');
        $q = trim((string)($_GET['q'] ?? ''));
        $limite = max(20, min(500, (int)($_GET['limite'] ?? 200)));
        $where = ['criado_em >= ?']; $par = [gmdate('Y-m-d H:i:s', time() - $dias * 86400)];
        if ($tipo === 'aluno' || $tipo === 'staff') { $where[] = 'user_tipo = ?'; $par[] = $tipo; }
        if (!empty($_GET['user_id'])) { $where[] = 'user_id = ?'; $par[] = (int)$_GET['user_id']; }
        $st = $pdo->prepare('SELECT idacesso, user_tipo, user_id, user_nome, evento, ref_id, ip, pais, regiao, cidade, fuso_cf, fuso_local, aparelho, criado_em FROM intus_acesso WHERE ' . implode(' AND ', $where) . ' ORDER BY idacesso DESC LIMIT 5000');
        $st->execute($par);
        $linhas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $linhas[] = ['id' => (int)$r['idacesso'], 'tipo' => $r['user_tipo'], 'user_id' => (int)$r['user_id'], 'nome' => (string)$r['user_nome'], 'evento' => $r['evento'], 'ref_id' => $r['ref_id'] !== null ? (int)$r['ref_id'] : null,
                         'ip' => (string)$r['ip'], 'pais' => (string)$r['pais'], 'regiao' => (string)$r['regiao'], 'cidade' => (string)$r['cidade'], 'fuso_cf' => (string)$r['fuso_cf'], 'fuso_local' => (string)$r['fuso_local'],
                         'aparelho' => (string)$r['aparelho'], 'quando_utc' => $r['criado_em']];
        }
        $linhas = _acessoNomes($pdo, $tblAtleta, $linhas);
        if ($q !== '') { $qq = mb_strtolower($q); $linhas = array_values(array_filter($linhas, fn($l) => mb_strpos(mb_strtolower($l['nome']), $qq) !== false || strpos($l['ip'], $q) !== false)); }
        // Resumo por pessoa
        $pessoas = [];
        foreach ($linhas as $l) {
            $k = $l['tipo'] . ':' . $l['user_id'];
            if (!isset($pessoas[$k])) $pessoas[$k] = ['tipo' => $l['tipo'], 'user_id' => $l['user_id'], 'nome' => $l['nome'], 'ultimo_utc' => $l['quando_utc'], 'ultimo_local' => _acessoLocal($l), 'ultimo_ip' => $l['ip'], 'eventos' => 0, 'ips' => [], 'locais' => []];
            $pessoas[$k]['eventos']++;
            if ($l['ip'] !== '') $pessoas[$k]['ips'][$l['ip']] = true;
            $loc = _acessoLocal($l); if ($loc !== '') $pessoas[$k]['locais'][$loc] = true;
        }
        $pessoas = array_map(function ($p) { $p['ips'] = array_slice(array_keys($p['ips']), 0, 8); $p['locais'] = array_slice(array_keys($p['locais']), 0, 8); return $p; }, array_values($pessoas));
        $temGeo = false; foreach ($linhas as $l) { if ($l['pais'] !== '') { $temGeo = true; break; } }
        echo json_encode(['agora_utc' => gmdate('Y-m-d H:i:s'), 'dias' => $dias, 'cloudflare' => intusVemDoCloudflare(), 'localizacao_ativa' => $temGeo, 'total' => count($linhas),
                          'linhas' => array_slice($linhas, 0, $limite), 'pessoas' => array_slice($pessoas, 0, 120)], JSON_UNESCAPED_UNICODE);
        return;
    }

    // ─────────────── Equipe: quadro da Frequência e Ranking (só alunos visíveis) ───────────────
    $dias = max(1, min(90, (int)($_GET['dias'] ?? 14)));
    $permitidos = null;   // null = todos (administrador)
    if (!$admin) {
        try { $permitidos = array_map('intval', getAtletasDoUsuario($pdo, (int)($ctx['idusuario'] ?? 0))); } catch (Throwable $e) { $permitidos = []; }
        if (!$permitidos) { echo json_encode(['agora_utc' => gmdate('Y-m-d H:i:s'), 'dias' => $dias, 'vazio' => true, 'recentes' => [], 'totais' => ['eventos' => 0]]); return; }
    }
    $par = [gmdate('Y-m-d H:i:s', time() - $dias * 86400)];
    $sql = "SELECT idacesso, user_id, user_nome, evento, ip, pais, regiao, cidade, fuso_local, criado_em FROM intus_acesso WHERE user_tipo = 'aluno' AND criado_em >= ?";
    if ($permitidos !== null) { $sql .= ' AND user_id IN (' . implode(',', $permitidos) . ')'; }
    $sql .= ' ORDER BY idacesso DESC LIMIT 20000';
    $st = $pdo->prepare($sql); $st->execute($par);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $br = new DateTimeZone('America/Sao_Paulo'); $utc = new DateTimeZone('UTC'); $tzCache = [];
    $evs = ['abertura', 'treino', 'cardio'];
    $horaBr = []; $horaLocal = []; foreach ($evs as $e) { $horaBr[$e] = array_fill(0, 24, 0); $horaLocal[$e] = array_fill(0, 24, 0); }
    $semana = array_fill(0, 7, 0); $locais = []; $fusos = []; $alunos = []; $tot = ['eventos' => 0, 'treino' => 0, 'cardio' => 0, 'abertura' => 0, 'login' => 0, 'ultima_hora' => 0, 'online_10min' => 0];
    $onlineSet = [];
    $agora = time();
    $recentes = [];
    foreach ($rows as $r) {
        $ev = $r['evento'] === 'login' ? 'abertura' : $r['evento'];   // login e abertura contam como acesso
        if (!isset($horaBr[$ev])) continue;
        $d = new DateTime($r['criado_em'], $utc);
        $ts = $d->getTimestamp();
        $dB = clone $d; $dB->setTimezone($br);
        $horaBr[$ev][(int)$dB->format('G')]++;
        $semana[(int)$dB->format('w')]++;
        $fl = (string)$r['fuso_local']; $horaLoc = (int)$dB->format('G'); $fusoEf = 'America/Sao_Paulo'; $horaTxt = '';
        if ($fl !== '' && intusFusoValido($fl)) {
            if (!isset($tzCache[$fl])) { try { $tzCache[$fl] = new DateTimeZone($fl); } catch (Throwable $e) { $tzCache[$fl] = null; } }
            if ($tzCache[$fl]) { $dL = clone $d; $dL->setTimezone($tzCache[$fl]); $horaLoc = (int)$dL->format('G'); $fusoEf = $fl; $horaTxt = $dL->format('H:i'); }
        }
        $horaLocal[$ev][$horaLoc]++;
        $aid = (int)$r['user_id']; $alunos[$aid] = true;
        $tot['eventos']++; $tot[$r['evento'] === 'login' ? 'login' : $ev]++;
        if ($ts >= $agora - 3600) $tot['ultima_hora']++;
        if ($ts >= $agora - 600) $onlineSet[$aid] = true;
        $loc = _acessoLocal(['cidade' => $r['cidade'], 'regiao' => $r['regiao'], 'pais' => $r['pais']]);
        $chave = $loc !== '' ? $loc : 'Sem localização';
        if (!isset($locais[$chave])) $locais[$chave] = ['local' => $chave, 'n' => 0, 'alunos' => []];
        $locais[$chave]['n']++; $locais[$chave]['alunos'][$aid] = true;
        if ($fusoEf !== 'America/Sao_Paulo') { if (!isset($fusos[$fusoEf])) $fusos[$fusoEf] = ['fuso' => $fusoEf, 'n' => 0, 'alunos' => []]; $fusos[$fusoEf]['n']++; $fusos[$fusoEf]['alunos'][$aid] = true; }
        if (count($recentes) < 60) {
            $recentes[] = ['id' => (int)$r['idacesso'], 'user_id' => $aid, 'tipo' => 'aluno', 'nome' => (string)$r['user_nome'], 'evento' => $r['evento'], 'quando_utc' => $r['criado_em'],
                           'local' => $loc, 'fuso_local' => ($fusoEf !== 'America/Sao_Paulo' ? $fusoEf : ''), 'hora_local' => $horaTxt,
                           'ip' => $admin ? (string)$r['ip'] : null];
        }
    }
    $tot['online_10min'] = count($onlineSet);
    $tot['alunos'] = count($alunos);
    $recentes = _acessoNomes($pdo, $tblAtleta, $recentes);
    usort($locais, fn($a, $b) => $b['n'] <=> $a['n']);
    $locais = array_map(fn($l) => ['local' => $l['local'], 'n' => $l['n'], 'alunos' => count($l['alunos'])], array_slice($locais, 0, 12));
    usort($fusos, fn($a, $b) => $b['n'] <=> $a['n']);
    $fusos = array_map(fn($f) => ['fuso' => $f['fuso'], 'n' => $f['n'], 'alunos' => count($f['alunos'])], array_slice($fusos, 0, 10));
    echo json_encode(['agora_utc' => gmdate('Y-m-d H:i:s'), 'dias' => $dias, 'cloudflare' => intusVemDoCloudflare(), 'totais' => $tot, 'recentes' => $recentes,
                      'hora_br' => $horaBr, 'hora_local' => $horaLocal, 'dia_semana' => $semana, 'locais' => $locais, 'fusos' => $fusos, 'admin' => $admin], JSON_UNESCAPED_UNICODE);
}
