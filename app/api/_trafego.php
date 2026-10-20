<?php
/**
 * ANALYTICS DE CAPTAÇÃO (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Mede as páginas públicas (site, teste grátis, login, CDD, páginas feitas no admin) sem serviço de terceiros e sem cookie de rastreio.
 * O visitante é um código anônimo guardado no próprio navegador (vid). O IP NÃO é gravado. País e cidade vêm do Cloudflare quando ele está ligado.
 *
 * Tabelas novas (autorizadas pelo Luiz em 19/10/2026; só recebem linhas; criadas na primeira chamada):
 *   intus_trafego_visita   1 linha por visita a uma página: dia (Brasília), hora, página, canal, UTM, indicação (?ref), aparelho, local.
 *   intus_trafego_evento   1 linha por clique ou etapa (whatsapp, acessar_app, form_inicio, etapa1_ok, conta_criada, lead_enviado...), com a origem da sessão copiada da 1ª visita.
 *
 * SQL portável de propósito (sem NOW(), sem INTERVAL, sem ON DUPLICATE KEY): o PHP calcula as datas e o teste roda em SQLite.
 */

const TR_CONVERSOES = ['conta_criada', 'lead_enviado'];                 // o que conta como "pessoa captada"
const TR_CLIQUES = ['whatsapp', 'acessar_app', 'cta'];                   // cliques que mostram interesse
const TR_FUSO = 'America/Sao_Paulo';

function trSqlite(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }

function trGarantirTabelas(PDO $pdo): bool
{
    static $ok = [];
    $k = spl_object_id($pdo);
    if (!empty($ok[$k])) return true;
    try {
        $pdo->query('SELECT 1 FROM intus_trafego_visita LIMIT 1');
        $pdo->query('SELECT 1 FROM intus_trafego_evento LIMIT 1');
        return $ok[$k] = true;
    } catch (Throwable $e) { /* ainda não existe: cria abaixo */ }
    try {
        if (trSqlite($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS intus_trafego_visita (idvisita INTEGER PRIMARY KEY AUTOINCREMENT, vid TEXT, sid TEXT, pagina TEXT, canal TEXT DEFAULT 'direto', utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT, ref_idatleta INT, referrer_host TEXT, novo INT DEFAULT 0, aparelho TEXT, navegador TEXT, so TEXT, largura INT, pais TEXT, regiao TEXT, cidade TEXT, dia TEXT, hora INT, criado_em TEXT)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS intus_trafego_evento (idevento INTEGER PRIMARY KEY AUTOINCREMENT, vid TEXT, sid TEXT, pagina TEXT, evento TEXT, rotulo TEXT, valor INT, canal TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, ref_idatleta INT, dia TEXT, hora INT, criado_em TEXT)");
            return $ok[$k] = true;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_trafego_visita (
            idvisita BIGINT AUTO_INCREMENT PRIMARY KEY,
            vid VARCHAR(40) NOT NULL, sid VARCHAR(40) NOT NULL,
            pagina VARCHAR(80) NOT NULL,
            canal VARCHAR(40) NOT NULL DEFAULT 'direto',
            utm_source VARCHAR(80) NULL, utm_medium VARCHAR(80) NULL, utm_campaign VARCHAR(80) NULL, utm_content VARCHAR(80) NULL, utm_term VARCHAR(80) NULL,
            ref_idatleta INT NULL,
            referrer_host VARCHAR(120) NULL,
            novo TINYINT NOT NULL DEFAULT 0,
            aparelho VARCHAR(10) NULL, navegador VARCHAR(20) NULL, so VARCHAR(20) NULL, largura SMALLINT NULL,
            pais VARCHAR(2) NULL, regiao VARCHAR(80) NULL, cidade VARCHAR(80) NULL,
            dia DATE NOT NULL, hora TINYINT NOT NULL, criado_em DATETIME NOT NULL,
            INDEX i_dia (dia), INDEX i_vid (vid, criado_em), INDEX i_sid (sid), INDEX i_pagina (pagina, dia), INDEX i_ref (ref_idatleta), INDEX i_criado (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_trafego_evento (
            idevento BIGINT AUTO_INCREMENT PRIMARY KEY,
            vid VARCHAR(40) NOT NULL, sid VARCHAR(40) NOT NULL,
            pagina VARCHAR(80) NOT NULL,
            evento VARCHAR(40) NOT NULL, rotulo VARCHAR(120) NULL, valor INT NULL,
            canal VARCHAR(40) NULL,
            utm_source VARCHAR(80) NULL, utm_medium VARCHAR(80) NULL, utm_campaign VARCHAR(80) NULL, utm_content VARCHAR(80) NULL,
            ref_idatleta INT NULL,
            dia DATE NOT NULL, hora TINYINT NOT NULL, criado_em DATETIME NOT NULL,
            INDEX i_dia (dia), INDEX i_evento (evento, dia), INDEX i_vid (vid, criado_em), INDEX i_sid (sid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok[$k] = true;
    } catch (Throwable $e) { return $ok[$k] = false; }
}

/** [UTC 'Y-m-d H:i:s', dia em Brasília 'Y-m-d', hora em Brasília] */
function trAgora(?int $ts = null): array
{
    $ts = $ts ?? time();
    $d = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone(TR_FUSO));
    return [gmdate('Y-m-d H:i:s', $ts), $d->format('Y-m-d'), (int)$d->format('G')];
}

function trLimpar($s, int $max): string
{
    $s = is_scalar($s) ? (string)$s : '';
    $s = preg_replace('/[\x00-\x1F\x7F<>"\']/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/** Caminho da página, sem endereço e sem parâmetros. "/index.html" e "/" são a mesma página. */
function trPagina($p): string
{
    $p = strtolower(trLimpar($p, 200));
    $p = parse_url($p, PHP_URL_PATH) ?: $p;
    $p = preg_replace('#/{2,}#', '/', $p);
    if ($p === '' || $p[0] !== '/') $p = '/' . $p;
    $p = preg_replace('#/index\.html?$#', '/', $p);
    $p = preg_replace('/[^a-z0-9\/_.\-]/', '', $p);
    return substr($p === '' ? '/' : $p, 0, 80);
}

function trHost($url): string
{
    $h = strtolower((string)parse_url(trLimpar($url, 300), PHP_URL_HOST));
    $h = preg_replace('/^www\./', '', $h);
    return preg_match('/^[a-z0-9.\-]{3,120}$/', $h) ? $h : '';
}

/** @return array{bot:bool,aparelho:string,navegador:string,so:string} */
function trClassificarUA(string $ua): array
{
    $bot = $ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp\/|telegram|preview|headless|lighthouse|pingdom|monitor|curl|wget|python|java\/|httpclient|axios|node-fetch|go-http/i', $ua) === 1;
    $aparelho = preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua) || (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) ? 'tablet'
        : (preg_match('/Mobi|iPhone|iPod|Android/i', $ua) ? 'celular' : 'desktop');
    $nav = preg_match('/Instagram/i', $ua) ? 'Instagram' : (preg_match('/FBAN|FBAV|FB_IAB/i', $ua) ? 'Facebook' : (preg_match('/OPR\/|Opera/i', $ua) ? 'Opera'
        : (preg_match('/Edg\//i', $ua) ? 'Edge' : (preg_match('/SamsungBrowser/i', $ua) ? 'Samsung' : (preg_match('/Firefox\//i', $ua) ? 'Firefox'
        : (preg_match('/Chrome\/|CriOS/i', $ua) ? 'Chrome' : (preg_match('/Safari\//i', $ua) ? 'Safari' : 'Outro')))))));
    $so = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad|iPod/i', $ua) ? 'iOS' : (preg_match('/Windows/i', $ua) ? 'Windows'
        : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'macOS' : (preg_match('/Linux|X11/i', $ua) ? 'Linux' : 'Outro'))));
    return ['bot' => $bot, 'aparelho' => $aparelho, 'navegador' => $nav, 'so' => $so];
}

/** De onde veio a visita: indicação > UTM > site de origem > direto. */
function trCanal(?int $ref, string $utmSource, string $referrerHost): string
{
    if ($ref) return 'indicacao';
    $s = strtolower(trim($utmSource));
    if ($s !== '') {
        $mapa = ['ig' => 'instagram', 'instagram' => 'instagram', 'insta' => 'instagram', 'fb' => 'facebook', 'facebook' => 'facebook', 'meta' => 'facebook',
                 'wa' => 'whatsapp', 'whatsapp' => 'whatsapp', 'zap' => 'whatsapp', 'google' => 'google', 'youtube' => 'youtube', 'yt' => 'youtube',
                 'tiktok' => 'tiktok', 'email' => 'email', 'e-mail' => 'email', 'sms' => 'sms', 'qr' => 'qrcode', 'qrcode' => 'qrcode'];
        return $mapa[$s] ?? mb_substr(preg_replace('/[^a-z0-9_\-]/', '', $s) ?: 'campanha', 0, 40);
    }
    $h = $referrerHost;
    if ($h === '') return 'direto';
    if (preg_match('/instagram\./', $h)) return 'instagram';
    if (preg_match('/(^|\.)(facebook|fb|messenger)\.com$|fb\.me$/', $h)) return 'facebook';
    if (preg_match('/whatsapp|(^|\.)wa\.me$/', $h)) return 'whatsapp';
    if (preg_match('/(^|\.)google\./', $h)) return 'google';
    if (preg_match('/bing\.|duckduckgo\.|yahoo\.|ecosia\./', $h)) return 'busca';
    if (preg_match('/youtube\.|youtu\.be/', $h)) return 'youtube';
    if (preg_match('/tiktok\./', $h)) return 'tiktok';
    if (preg_match('/(^|\.)(t\.co|twitter\.com|x\.com)$/', $h)) return 'twitter';
    return 'outros sites';
}

function trValidaId($v): string
{
    $v = is_string($v) ? strtolower(trim($v)) : '';
    return preg_match('/^[a-z0-9]{8,40}$/', $v) ? $v : '';
}

/** Registra uma visita. Devolve ['ok'=>bool, 'id'=>?int, 'motivo'=>?string]. Nunca lança erro. */
function trRegistrarVisita(PDO $pdo, array $b, string $ua, array $geo = [], ?int $ts = null, string $hostSite = ''): array
{
    try {
        $c = trClassificarUA($ua);
        if ($c['bot']) return ['ok' => true, 'id' => null, 'motivo' => 'bot'];
        $vid = trValidaId($b['vid'] ?? ''); $sid = trValidaId($b['sid'] ?? '');
        if ($vid === '' || $sid === '') return ['ok' => false, 'id' => null, 'motivo' => 'ids'];
        if (!trGarantirTabelas($pdo)) return ['ok' => false, 'id' => null, 'motivo' => 'tabela'];
        [$utc, $dia, $hora] = trAgora($ts);
        $pagina = trPagina($b['pagina'] ?? '/');
        // limite por visitante: 200 visitas por hora (barra repetição e inundação)
        $umaHoraAtras = gmdate('Y-m-d H:i:s', ($ts ?? time()) - 3600);
        $st = $pdo->prepare('SELECT COUNT(*) FROM intus_trafego_visita WHERE vid = ? AND criado_em > ?'); $st->execute([$vid, $umaHoraAtras]);
        if ((int)$st->fetchColumn() >= 200) return ['ok' => true, 'id' => null, 'motivo' => 'limite'];
        // mesmo visitante, mesma sessão e mesma página nos últimos 3 segundos: disparo duplo
        $st = $pdo->prepare('SELECT idvisita FROM intus_trafego_visita WHERE vid = ? AND sid = ? AND pagina = ? AND criado_em > ? ORDER BY idvisita DESC LIMIT 1');
        $st->execute([$vid, $sid, $pagina, gmdate('Y-m-d H:i:s', ($ts ?? time()) - 3)]);
        if ($dup = $st->fetchColumn()) return ['ok' => true, 'id' => (int)$dup, 'motivo' => 'duplicada'];
        $st = $pdo->prepare('SELECT 1 FROM intus_trafego_visita WHERE vid = ? LIMIT 1'); $st->execute([$vid]);
        $novo = $st->fetchColumn() ? 0 : 1;
        $ref = filter_var($b['ref'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $ref = $ref !== false ? (int)$ref : null;
        $utm = [];
        foreach (['source', 'medium', 'campaign', 'content', 'term'] as $k) $utm[$k] = trLimpar($b['utm_' . $k] ?? '', 80);
        $refHost = trHost($b['referrer'] ?? '');
        if ($hostSite !== '' && $refHost === preg_replace('/^www\./', '', strtolower($hostSite))) $refHost = '';   // navegação interna não é origem
        $canal = trCanal($ref, $utm['source'], $refHost);
        $pdo->prepare('INSERT INTO intus_trafego_visita (vid, sid, pagina, canal, utm_source, utm_medium, utm_campaign, utm_content, utm_term, ref_idatleta, referrer_host, novo, aparelho, navegador, so, largura, pais, regiao, cidade, dia, hora, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$vid, $sid, $pagina, $canal, $utm['source'] ?: null, $utm['medium'] ?: null, $utm['campaign'] ?: null, $utm['content'] ?: null, $utm['term'] ?: null,
                $ref, $refHost ?: null, $novo, $c['aparelho'], $c['navegador'], $c['so'], max(0, min(10000, (int)($b['w'] ?? 0))) ?: null,
                ($geo['pais'] ?? '') ?: null, ($geo['regiao'] ?? '') ?: null, ($geo['cidade'] ?? '') ?: null, $dia, $hora, $utc]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'motivo' => null];
    } catch (Throwable $e) { return ['ok' => false, 'id' => null, 'motivo' => 'erro']; }
}

/** Registra um clique ou etapa. A origem (canal, UTM, indicação) é a da 1ª visita da mesma sessão. */
function trRegistrarEvento(PDO $pdo, array $b, string $ua, ?int $ts = null): array
{
    try {
        $c = trClassificarUA($ua);
        if ($c['bot']) return ['ok' => true, 'id' => null, 'motivo' => 'bot'];
        $vid = trValidaId($b['vid'] ?? ''); $sid = trValidaId($b['sid'] ?? '');
        $evento = strtolower(trLimpar($b['evento'] ?? '', 40));
        if ($vid === '' || $sid === '' || !preg_match('/^[a-z0-9_]{2,40}$/', $evento)) return ['ok' => false, 'id' => null, 'motivo' => 'dados'];
        if (!trGarantirTabelas($pdo)) return ['ok' => false, 'id' => null, 'motivo' => 'tabela'];
        [$utc, $dia, $hora] = trAgora($ts);
        $st = $pdo->prepare('SELECT COUNT(*) FROM intus_trafego_evento WHERE vid = ? AND criado_em > ?'); $st->execute([$vid, gmdate('Y-m-d H:i:s', ($ts ?? time()) - 3600)]);
        if ((int)$st->fetchColumn() >= 100) return ['ok' => true, 'id' => null, 'motivo' => 'limite'];
        $st = $pdo->prepare('SELECT canal, utm_source, utm_medium, utm_campaign, utm_content, ref_idatleta FROM intus_trafego_visita WHERE sid = ? ORDER BY idvisita ASC LIMIT 1');
        $st->execute([$sid]);
        $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $valor = filter_var($b['valor'] ?? null, FILTER_VALIDATE_INT);
        $pdo->prepare('INSERT INTO intus_trafego_evento (vid, sid, pagina, evento, rotulo, valor, canal, utm_source, utm_medium, utm_campaign, utm_content, ref_idatleta, dia, hora, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$vid, $sid, trPagina($b['pagina'] ?? '/'), $evento, trLimpar($b['rotulo'] ?? '', 120) ?: null, $valor !== false ? $valor : null,
                $o['canal'] ?? null, $o['utm_source'] ?? null, $o['utm_medium'] ?? null, $o['utm_campaign'] ?? null, $o['utm_content'] ?? null, $o['ref_idatleta'] ?? null, $dia, $hora, $utc]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'motivo' => null];
    } catch (Throwable $e) { return ['ok' => false, 'id' => null, 'motivo' => 'erro']; }
}

// ───────────────────────── RELATÓRIO ─────────────────────────

function trDataValida($s): bool { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4)); }

function trFiltroPagina(?string $pagina): array { return $pagina ? [' AND pagina = ?', [$pagina]] : ['', []]; }

function trKpis(PDO $pdo, string $de, string $ate, ?string $pagina): array
{
    [$fp, $pp] = trFiltroPagina($pagina);
    $st = $pdo->prepare("SELECT COUNT(*) AS visitas, COUNT(DISTINCT vid) AS visitantes, COUNT(DISTINCT sid) AS sessoes, COALESCE(SUM(novo),0) AS novos FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?" . $fp);
    $st->execute(array_merge([$de, $ate], $pp));
    $v = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $ph = implode(',', array_fill(0, count(TR_CONVERSOES), '?'));
    $st = $pdo->prepare("SELECT evento, COUNT(*) AS n FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph)" . $fp . " GROUP BY evento");
    $st->execute(array_merge([$de, $ate], TR_CONVERSOES, $pp));
    $porEv = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porEv[$r['evento']] = (int)$r['n'];
    $conv = array_sum($porEv);
    $ph2 = implode(',', array_fill(0, count(TR_CLIQUES), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph2)" . $fp);
    $st->execute(array_merge([$de, $ate], TR_CLIQUES, $pp));
    $cliques = (int)$st->fetchColumn();
    $visitantes = (int)($v['visitantes'] ?? 0);
    return ['visitas' => (int)($v['visitas'] ?? 0), 'visitantes' => $visitantes, 'sessoes' => (int)($v['sessoes'] ?? 0), 'novos' => (int)($v['novos'] ?? 0),
            'conversoes' => $conv, 'contas_criadas' => $porEv['conta_criada'] ?? 0, 'contatos_sem_conta' => $porEv['lead_enviado'] ?? 0, 'cliques' => $cliques, 'taxa' => $visitantes > 0 ? round($conv * 100 / $visitantes, 1) : 0.0];
}

function trLinhas(PDO $pdo, string $sql, array $p): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }

/** Junta duas listas (visitas e conversões) pela mesma chave. */
function trJuntar(array $visitas, array $convs, array $chaves, string $campoConv = 'conv'): array
{
    $idx = [];
    foreach ($convs as $r) { $k = implode('|', array_map(fn($c) => (string)($r[$c] ?? ''), $chaves)); $idx[$k] = (int)$r['n']; }
    $out = [];
    foreach ($visitas as $r) {
        $k = implode('|', array_map(fn($c) => (string)($r[$c] ?? ''), $chaves));
        $r['visitas'] = (int)$r['visitas']; $r['visitantes'] = (int)$r['visitantes'];
        $r[$campoConv] = $idx[$k] ?? 0; unset($idx[$k]);
        $out[] = $r;
    }
    foreach ($idx as $k => $n) {   // conversão sem visita no período (visita anterior ao período)
        $v = explode('|', $k); $r = ['visitas' => 0, 'visitantes' => 0, $campoConv => $n];
        foreach ($chaves as $i => $c) $r[$c] = $v[$i] === '' ? null : $v[$i];
        $out[] = $r;
    }
    return $out;
}

function trRelatorio(PDO $pdo, string $de, string $ate, ?string $pagina = null, ?string $tblAtleta = null, ?int $ts = null): array
{
    trGarantirTabelas($pdo);
    [$fp, $pp] = trFiltroPagina($pagina);
    $base = [$de, $ate]; $bp = array_merge($base, $pp);
    $dias = (int)round((strtotime($ate) - strtotime($de)) / 86400) + 1;
    $antAte = date('Y-m-d', strtotime($de . ' -1 day')); $antDe = date('Y-m-d', strtotime($de . ' -' . $dias . ' days'));
    $out = ['periodo' => ['de' => $de, 'ate' => $ate, 'dias' => $dias, 'anterior_de' => $antDe, 'anterior_ate' => $antAte, 'pagina' => $pagina]];
    $out['kpis'] = trKpis($pdo, $de, $ate, $pagina);
    $out['kpis_anterior'] = trKpis($pdo, $antDe, $antAte, $pagina);

    // série diária (preenche os dias sem visita com zero)
    $vis = []; foreach (trLinhas($pdo, "SELECT dia, COUNT(*) AS visitas, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp GROUP BY dia", $bp) as $r) $vis[$r['dia']] = $r;
    $ph = implode(',', array_fill(0, count(TR_CONVERSOES), '?'));
    $cv = []; foreach (trLinhas($pdo, "SELECT dia, COUNT(*) AS n FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph)$fp GROUP BY dia", array_merge($base, TR_CONVERSOES, $pp)) as $r) $cv[$r['dia']] = (int)$r['n'];
    $serie = []; $porSemana = array_fill(0, 7, 0);
    for ($i = 0; $i < $dias; $i++) {
        $d = date('Y-m-d', strtotime($de . " +$i days"));
        $n = (int)($vis[$d]['visitas'] ?? 0);
        $serie[] = ['dia' => $d, 'visitas' => $n, 'visitantes' => (int)($vis[$d]['visitantes'] ?? 0), 'conversoes' => $cv[$d] ?? 0];
        $porSemana[(int)date('w', strtotime($d))] += $n;
    }
    $out['serie'] = $serie; $out['dia_semana'] = $porSemana;
    $h = array_fill(0, 24, 0);
    foreach (trLinhas($pdo, "SELECT hora, COUNT(*) AS n FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp GROUP BY hora", $bp) as $r) $h[(int)$r['hora']] = (int)$r['n'];
    $out['horas'] = $h;

    // por página
    $pgV = trLinhas($pdo, "SELECT pagina, COUNT(*) AS visitas, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp GROUP BY pagina ORDER BY visitas DESC LIMIT 40", $bp);
    $pgC = trLinhas($pdo, "SELECT pagina, COUNT(*) AS n FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph)$fp GROUP BY pagina", array_merge($base, TR_CONVERSOES, $pp));
    $ph2 = implode(',', array_fill(0, count(TR_CLIQUES), '?'));
    $pgK = trLinhas($pdo, "SELECT pagina, COUNT(*) AS n FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph2)$fp GROUP BY pagina", array_merge($base, TR_CLIQUES, $pp));
    $pags = trJuntar($pgV, $pgC, ['pagina'], 'conversoes');
    $kidx = []; foreach ($pgK as $r) $kidx[$r['pagina']] = (int)$r['n'];
    foreach ($pags as &$r) { $r['cliques'] = $kidx[$r['pagina']] ?? 0; $r['taxa'] = $r['visitantes'] > 0 ? round($r['conversoes'] * 100 / $r['visitantes'], 1) : 0.0; }
    unset($r);
    $out['paginas'] = $pags;

    // canais e campanhas
    $chV = trLinhas($pdo, "SELECT canal, COUNT(*) AS visitas, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp GROUP BY canal ORDER BY visitas DESC", $bp);
    $chC = trLinhas($pdo, "SELECT canal, COUNT(*) AS n FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph)$fp GROUP BY canal", array_merge($base, TR_CONVERSOES, $pp));
    $canais = trJuntar($chV, $chC, ['canal'], 'conversoes');
    foreach ($canais as &$r) { $r['canal'] = $r['canal'] ?: 'desconhecido'; $r['taxa'] = $r['visitantes'] > 0 ? round($r['conversoes'] * 100 / $r['visitantes'], 1) : 0.0; }
    unset($r); usort($canais, fn($a, $b) => $b['visitas'] <=> $a['visitas']);
    $out['canais'] = $canais;
    $cpV = trLinhas($pdo, "SELECT utm_source, utm_medium, utm_campaign, utm_content, COUNT(*) AS visitas, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp AND (utm_source IS NOT NULL OR utm_campaign IS NOT NULL) GROUP BY utm_source, utm_medium, utm_campaign, utm_content ORDER BY visitas DESC LIMIT 60", $bp);
    $cpC = trLinhas($pdo, "SELECT utm_source, utm_medium, utm_campaign, utm_content, COUNT(*) AS n FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph)$fp AND (utm_source IS NOT NULL OR utm_campaign IS NOT NULL) GROUP BY utm_source, utm_medium, utm_campaign, utm_content", array_merge($base, TR_CONVERSOES, $pp));
    $camp = trJuntar($cpV, $cpC, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'], 'conversoes');
    foreach ($camp as &$r) $r['taxa'] = $r['visitantes'] > 0 ? round($r['conversoes'] * 100 / $r['visitantes'], 1) : 0.0;
    unset($r); usort($camp, fn($a, $b) => [$b['conversoes'], $b['visitas']] <=> [$a['conversoes'], $a['visitas']]);
    $out['campanhas'] = $camp;
    $out['sites_origem'] = array_map(fn($r) => ['host' => $r['referrer_host'], 'visitas' => (int)$r['visitas'], 'visitantes' => (int)$r['visitantes']],
        trLinhas($pdo, "SELECT referrer_host, COUNT(*) AS visitas, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp AND referrer_host IS NOT NULL GROUP BY referrer_host ORDER BY visitas DESC LIMIT 15", $bp));

    // links de indicação
    $inV = trLinhas($pdo, "SELECT ref_idatleta, COUNT(*) AS visitas, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp AND ref_idatleta IS NOT NULL GROUP BY ref_idatleta ORDER BY visitas DESC LIMIT 50", $bp);
    $inC = trLinhas($pdo, "SELECT ref_idatleta, COUNT(*) AS n FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ($ph)$fp AND ref_idatleta IS NOT NULL GROUP BY ref_idatleta", array_merge($base, TR_CONVERSOES, $pp));
    $ind = trJuntar($inV, $inC, ['ref_idatleta'], 'conversoes');
    $nomes = [];
    if ($ind && $tblAtleta) {
        try {
            $ids = array_map(fn($r) => (int)$r['ref_idatleta'], $ind);
            $st = $pdo->prepare("SELECT idatleta, nome FROM `$tblAtleta` WHERE idatleta IN (" . implode(',', array_fill(0, count($ids), '?')) . ')'); $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $nomes[(int)$r['idatleta']] = (string)$r['nome'];
        } catch (Throwable $e) {}
    }
    foreach ($ind as &$r) { $r['ref_idatleta'] = (int)$r['ref_idatleta']; $r['nome'] = $nomes[$r['ref_idatleta']] ?? ('Aluno #' . $r['ref_idatleta']); }
    unset($r); usort($ind, fn($a, $b) => [$b['conversoes'], $b['visitas']] <=> [$a['conversoes'], $a['visitas']]);
    $out['indicacoes'] = $ind;

    // funil do teste grátis (pessoas distintas por sessão)
    $pgT = '/teste-gratis.html';
    $st = $pdo->prepare("SELECT COUNT(DISTINCT sid) FROM intus_trafego_visita WHERE dia BETWEEN ? AND ? AND pagina = ?"); $st->execute([$de, $ate, $pgT]);
    $funil = [['etapa' => 'visita', 'rotulo' => 'Visitaram a página', 'n' => (int)$st->fetchColumn()]];
    foreach ([['form_inicio', 'Começaram a preencher'], ['etapa1_ok', 'Passaram da 1ª etapa'], ['conta_criada', 'Criaram o acesso grátis'], ['lead_enviado', 'Deixaram só o contato (sem conta)'], ['acessar_app', 'Abriram o app'], ['whatsapp', 'Foram para o WhatsApp']] as [$ev, $rot]) {
        $st = $pdo->prepare("SELECT COUNT(DISTINCT sid) FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND pagina = ? AND evento = ?"); $st->execute([$de, $ate, $pgT, $ev]);
        $funil[] = ['etapa' => $ev, 'rotulo' => $rot, 'n' => (int)$st->fetchColumn()];
    }
    $out['funil'] = $funil;
    $out['eventos'] = array_map(fn($r) => ['evento' => $r['evento'], 'rotulo' => $r['rotulo'], 'n' => (int)$r['n'], 'visitantes' => (int)$r['visitantes']],
        trLinhas($pdo, "SELECT evento, rotulo, COUNT(*) AS n, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_evento WHERE dia BETWEEN ? AND ?$fp GROUP BY evento, rotulo ORDER BY n DESC LIMIT 40", $bp));

    // quem são: aparelho, navegador, sistema, local
    foreach (['aparelho' => 'aparelhos', 'navegador' => 'navegadores', 'so' => 'sistemas'] as $col => $chave) {
        $out[$chave] = array_map(fn($r) => ['nome' => $r[$col] ?: 'desconhecido', 'visitantes' => (int)$r['visitantes']],
            trLinhas($pdo, "SELECT $col, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp GROUP BY $col ORDER BY visitantes DESC LIMIT 10", $bp));
    }
    $out['paises'] = array_map(fn($r) => ['nome' => $r['pais'], 'visitantes' => (int)$r['visitantes']],
        trLinhas($pdo, "SELECT pais, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp AND pais IS NOT NULL GROUP BY pais ORDER BY visitantes DESC LIMIT 10", $bp));
    $out['cidades'] = array_map(fn($r) => ['nome' => trim($r['cidade'] . ($r['regiao'] ? ' - ' . $r['regiao'] : '')), 'visitantes' => (int)$r['visitantes']],
        trLinhas($pdo, "SELECT cidade, regiao, COUNT(DISTINCT vid) AS visitantes FROM intus_trafego_visita WHERE dia BETWEEN ? AND ?$fp AND cidade IS NOT NULL GROUP BY cidade, regiao ORDER BY visitantes DESC LIMIT 15", $bp));

    // agora (últimos 30 minutos) e últimas visitas
    $agora = $ts ?? time();
    $st = $pdo->prepare('SELECT COUNT(DISTINCT vid) FROM intus_trafego_visita WHERE criado_em > ?'); $st->execute([gmdate('Y-m-d H:i:s', $agora - 1800)]);
    $ult = trLinhas($pdo, 'SELECT criado_em, pagina, canal, utm_campaign, ref_idatleta, aparelho, cidade, regiao, novo FROM intus_trafego_visita ORDER BY idvisita DESC LIMIT 15', []);
    $out['agora'] = ['ativos' => (int)$st->fetchColumn(), 'ultimas' => $ult];

    // conferência com o que está de fato no banco (cadastros reais, não só o que o navegador avisou)
    $banco = ['leads' => null, 'leads_por_origem' => [], 'leads_indicados' => null];
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM intus_lead WHERE DATE(criado_em) BETWEEN ? AND ?'); $st->execute($base); $banco['leads'] = (int)$st->fetchColumn();
        $banco['leads_por_origem'] = array_map(fn($r) => ['origem' => $r['origem'], 'n' => (int)$r['n']], trLinhas($pdo, 'SELECT origem, COUNT(*) AS n FROM intus_lead WHERE DATE(criado_em) BETWEEN ? AND ? GROUP BY origem ORDER BY n DESC', $base));
        $st = $pdo->prepare('SELECT COUNT(*) FROM intus_lead WHERE DATE(criado_em) BETWEEN ? AND ? AND indicado_por_idatleta IS NOT NULL'); $st->execute($base); $banco['leads_indicados'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
    $out['banco'] = $banco;
    $out['armazenado'] = trArmazenado($pdo);
    $out['gerado_em'] = gmdate('Y-m-d H:i:s', $agora);
    return $out;
}

const TR_LIMPEZA_MIN_DIAS = 365;   // nunca apaga nada com menos de 12 meses

/** Quanto há guardado no total e desde quando. */
function trArmazenado(PDO $pdo): array
{
    $v = (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_visita')->fetchColumn();
    $e = (int)$pdo->query('SELECT COUNT(*) FROM intus_trafego_evento')->fetchColumn();
    $d = $pdo->query('SELECT MIN(dia) FROM intus_trafego_visita')->fetchColumn();
    return ['visitas' => $v, 'eventos' => $e, 'desde' => $d ?: null];
}

/** O que a limpeza apagaria (só conta, não apaga nada). */
function trPreviaLimpeza(PDO $pdo, int $dias, ?int $ts = null): array
{
    $dias = max(TR_LIMPEZA_MIN_DIAS, $dias);
    $corte = date('Y-m-d', ($ts ?? time()) - $dias * 86400);
    $st = $pdo->prepare('SELECT COUNT(*) FROM intus_trafego_visita WHERE dia < ?'); $st->execute([$corte]); $v = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM intus_trafego_evento WHERE dia < ?'); $st->execute([$corte]); $e = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT MIN(dia) FROM intus_trafego_visita WHERE dia < ?'); $st->execute([$corte]); $d = $st->fetchColumn();
    return ['dias' => $dias, 'corte' => $corte, 'visitas' => $v, 'eventos' => $e, 'total' => $v + $e, 'mais_antiga' => $d ?: null, 'armazenado' => trArmazenado($pdo)];
}

/** Apaga o que for mais velho que N dias (mínimo 365). Só apaga se o total bater com o que a pessoa viu na prévia. Devolve quantas linhas saíram ou null se o total mudou. */
function trLimparAntigos(PDO $pdo, int $dias, ?int $esperadas = null, ?int $ts = null): ?int
{
    $pre = trPreviaLimpeza($pdo, $dias, $ts);
    if ($esperadas !== null && $esperadas !== $pre['total']) return null;
    $n = 0;
    foreach (['intus_trafego_visita', 'intus_trafego_evento'] as $t) { $st = $pdo->prepare("DELETE FROM $t WHERE dia < ?"); $st->execute([$pre['corte']]); $n += $st->rowCount(); }
    return $n;
}

// ───────────────────────── PESSOAS CAPTADAS (leads) ─────────────────────────
// Quem são, como chegaram e em que ponto do funil estão. Junta: os eventos medidos (conta_criada, lead_enviado), a tabela de atletas
// (contas do teste grátis), a de leads (contato sem conta), os acessos ao app, os treinos e as matrículas. Só leitura.

const TR_ETAPAS = [
    'cliente'        => [6, 'Virou cliente', 'Matrícula paga. Nada a fazer aqui.'],
    'negociando'     => [5, 'Matrícula criada, falta pagar', 'Falar sobre o pagamento para liberar o acesso.'],
    'teste_expirado' => [4, 'Teste acabou, sem comprar', 'Melhor hora para oferecer o plano: ele já conheceu o app.'],
    'treinando'      => [3, 'Em teste: já treinou', 'Perguntar como está sendo e apresentar o plano antes do teste acabar.'],
    'abriu_app'      => [2, 'Abriu o app, ainda não treinou', 'Ajudar a fazer o primeiro treino.'],
    'so_conta'       => [1, 'Criou o acesso, nunca abriu o app', 'Dar boas-vindas e ajudar a entrar no app.'],
    'contato'        => [0, 'Deixou só o contato (sem conta)', 'Chamar para criar o acesso grátis e começar.'],
];

function trColunas(PDO $pdo, string $tbl): array
{
    try {
        if (trSqlite($pdo)) return array_column($pdo->query("PRAGMA table_info(`$tbl`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        return array_column($pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Throwable $e) { return []; }
}
function trPick(array $cols, array $cands): ?string { foreach ($cands as $c) if (in_array($c, $cols, true)) return $c; return null; }

/** Número só com dígitos e DDI do Brasil, pronto para o wa.me. Vazio se não parecer telefone. */
function trWhatsapp($tel): string
{
    $d = preg_replace('/\D/', '', (string)$tel);
    if (strlen($d) >= 12 && substr($d, 0, 2) === '55') return $d;
    if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
    return '';
}

function trMensagem(string $etapa, string $nome): string
{
    $p = trim(explode(' ', trim($nome))[0] ?? '');
    $oi = 'Oi' . ($p !== '' ? ' ' . $p : '') . ', tudo bem? Aqui é da Intus Fit. ';
    switch ($etapa) {
        case 'so_conta':       return $oi . 'Vi que você criou o seu acesso ao teste grátis. Posso te ajudar a entrar no app e começar o primeiro treino?';
        case 'abriu_app':      return $oi . 'Você já entrou no app, mas ainda não fez o primeiro treino. Quer que eu te explique por onde começar?';
        case 'treinando':      return $oi . 'Vi que você já treinou no teste grátis. Como está sendo para você? Posso te ajudar com alguma dúvida?';
        case 'teste_expirado': return $oi . 'O seu teste grátis terminou. Quer conversar sobre continuar com acompanhamento profissional?';
        case 'negociando':     return $oi . 'Sua matrícula está criada e falta só o pagamento para liberar o acesso. Posso te ajudar com isso?';
        case 'contato':        return $oi . 'Você deixou o seu contato para o teste grátis. Posso te ajudar a começar?';
        default:               return $oi . 'Como estão os treinos?';
    }
}

function trParaBrt(string $utc): string
{
    try { return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(TR_FUSO))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return $utc; }
}

function trEhPlanoTeste(array $m): bool
{
    return preg_match('/\bteste\b|gr[aá]tis|trial|degusta|experimental|cortesia/iu', (string)($m['tipo_plano'] ?? '') . ' ' . (string)($m['dshistorico'] ?? '')) === 1;
}

/**
 * Lista de pessoas captadas no período (pela data do cadastro), com a etapa do funil de cada uma.
 * $tblAtleta: nome da tabela de atletas (ou null). Devolve ['pessoas'=>[], 'resumo'=>[], 'captadas_medidas'=>int, 'sem_medicao'=>int].
 */
function trLeads(PDO $pdo, string $de, string $ate, ?string $tblAtleta, ?int $ts = null): array
{
    trGarantirTabelas($pdo);
    $hoje = trAgora($ts)[1];
    $pessoas = [];   // chave => dados
    $ev = $pdo->prepare("SELECT idevento, evento, valor, canal, utm_source, utm_medium, utm_campaign, utm_content, ref_idatleta, rotulo, criado_em FROM intus_trafego_evento WHERE dia BETWEEN ? AND ? AND evento IN ('conta_criada','lead_enviado') ORDER BY idevento DESC LIMIT 1500");
    $ev->execute([$de, $ate]);
    $eventos = $ev->fetchAll(PDO::FETCH_ASSOC);
    $captadasMedidas = count($eventos);
    $idsAt = []; $idsLead = [];
    foreach ($eventos as $e) {
        $v = $e['valor'] !== null ? (int)$e['valor'] : 0;
        if ($e['evento'] === 'conta_criada') { $k = $v ? 'a' . $v : 'e' . $e['idevento']; if ($v) $idsAt[$v] = true; }
        else { $k = $v ? 'l' . $v : 'e' . $e['idevento']; if ($v) $idsLead[$v] = true; }
        $pessoas[$k] = ['chave' => $k, 'tipo' => $v ? ($e['evento'] === 'conta_criada' ? 'conta' : 'contato') : 'anonimo', 'id' => $v ?: null, 'medido' => true, 'criado_em' => trParaBrt((string)$e['criado_em']),
            'canal' => $e['canal'] ?: null, 'utm' => ['source' => $e['utm_source'], 'medium' => $e['utm_medium'], 'campaign' => $e['utm_campaign'], 'content' => $e['utm_content']],
            'ref' => $e['ref_idatleta'] !== null ? (int)$e['ref_idatleta'] : null, 'anonimo_evento' => $e['evento']];
    }
    // Contas do teste grátis criadas no período que a medição não viu (antes dela existir ou com o navegador bloqueando)
    $cA = $tblAtleta ? trColunas($pdo, $tblAtleta) : [];
    $cId = trPick($cA, ['idatleta', 'idaluno', 'id']); $cDt = trPick($cA, ['dt_cadastro']); $cOri = trPick($cA, ['origem']);
    if ($tblAtleta && $cId && $cDt && $cOri) {
        try {
            $st = $pdo->prepare("SELECT `$cId` AS i FROM `$tblAtleta` WHERE DATE(`$cDt`) BETWEEN ? AND ? AND `$cOri` LIKE 'landing%' LIMIT 1500");
            $st->execute([$de, $ate]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i) { $i = (int)$i; $idsAt[$i] = true; if (!isset($pessoas['a' . $i])) $pessoas['a' . $i] = ['chave' => 'a' . $i, 'tipo' => 'conta', 'id' => $i, 'medido' => false, 'criado_em' => null, 'canal' => null, 'utm' => [], 'ref' => null]; }
        } catch (Throwable $e) {}
    }
    $leadsRows = [];
    try {
        $st = $pdo->prepare("SELECT * FROM intus_lead WHERE DATE(criado_em) BETWEEN ? AND ? AND origem LIKE 'landing%' LIMIT 1500"); $st->execute([$de, $ate]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $leadsRows[(int)$r['idlead']] = $r; $idsLead[(int)$r['idlead']] = true; if (!isset($pessoas['l' . $r['idlead']])) $pessoas['l' . $r['idlead']] = ['chave' => 'l' . $r['idlead'], 'tipo' => 'contato', 'id' => (int)$r['idlead'], 'medido' => false, 'criado_em' => null, 'canal' => null, 'utm' => [], 'ref' => null]; }
        $falta = array_diff(array_keys($idsLead), array_keys($leadsRows));
        if ($falta) { $st = $pdo->prepare('SELECT * FROM intus_lead WHERE idlead IN (' . implode(',', array_fill(0, count($falta), '?')) . ')'); $st->execute(array_values($falta)); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $leadsRows[(int)$r['idlead']] = $r; }
    } catch (Throwable $e) {}
    // Dados das contas
    $atRows = [];
    if ($idsAt && $tblAtleta && $cId) {
        try { $st = $pdo->prepare("SELECT * FROM `$tblAtleta` WHERE `$cId` IN (" . implode(',', array_fill(0, count($idsAt), '?')) . ')'); $st->execute(array_keys($idsAt)); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $atRows[(int)$r[$cId]] = $r; } catch (Throwable $e) {}
    }
    $ids = array_keys($atRows);
    $ph = $ids ? implode(',', array_fill(0, count($ids), '?')) : '';
    $treinos = []; $mats = []; $abert = [];
    if ($ids) {
        try { $st = $pdo->prepare("SELECT idatleta, COUNT(*) AS n, MIN(dtsessao) AS p, MAX(dtsessao) AS u FROM intus_sessao WHERE idatleta IN ($ph) AND (nao_contar IS NULL OR nao_contar = 0) GROUP BY idatleta"); $st->execute($ids); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $treinos[(int)$r['idatleta']] = $r; } catch (Throwable $e) {}
        try { $st = $pdo->prepare("SELECT idatleta, dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto, vlpagamento, stmatricula FROM intus_mensalidade WHERE idatleta IN ($ph)"); $st->execute($ids); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $mats[(int)$r['idatleta']][] = $r; } catch (Throwable $e) {}
        try { $st = $pdo->prepare("SELECT user_id, COUNT(*) AS n, MAX(criado_em) AS u FROM intus_acesso WHERE user_tipo = 'aluno' AND evento IN ('abertura','login') AND user_id IN ($ph) GROUP BY user_id"); $st->execute($ids); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $abert[(int)$r['user_id']] = $r; } catch (Throwable $e) {}
    }
    // Nomes de quem indicou
    $refIds = [];
    foreach ($pessoas as $p) if (!empty($p['ref'])) $refIds[(int)$p['ref']] = true;
    foreach ($atRows as $r) if (!empty($r['indicado_por_idatleta'])) $refIds[(int)$r['indicado_por_idatleta']] = true;
    foreach ($leadsRows as $r) if (!empty($r['indicado_por_idatleta'])) $refIds[(int)$r['indicado_por_idatleta']] = true;
    $nomesRef = [];
    if ($refIds && $tblAtleta && $cId && ($cNm = trPick($cA, ['nome', 'nmatleta', 'nmaluno']))) {
        try { $st = $pdo->prepare("SELECT `$cId` AS i, `$cNm` AS n FROM `$tblAtleta` WHERE `$cId` IN (" . implode(',', array_fill(0, count($refIds), '?')) . ')'); $st->execute(array_keys($refIds)); foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $nomesRef[(int)$r['i']] = (string)$r['n']; } catch (Throwable $e) {}
    }
    $cNome = trPick($cA, ['nome', 'nmatleta', 'nmaluno']); $cEmail = trPick($cA, ['email', 'dsemail']); $cTel = trPick($cA, ['telefone', 'nrtelefone', 'dstelefone', 'celular']);
    $out = [];
    foreach ($pessoas as $p) {
        $nome = ''; $tel = ''; $email = ''; $obj = ''; $criado = $p['criado_em']; $ref = $p['ref'];
        $r = null; $etapa = 'contato';
        $p['treinos'] = 0; $p['primeiro_treino'] = null; $p['ultimo_treino'] = null; $p['abriu_app'] = false; $p['aberturas'] = 0; $p['ultimo_acesso'] = null; $p['teste'] = null; $p['matricula'] = null; $p['bloqueado'] = false;
        if ($p['tipo'] === 'conta' && isset($atRows[$p['id']])) {
            $r = $atRows[$p['id']];
            $nome = (string)($cNome ? ($r[$cNome] ?? '') : ''); $tel = (string)($cTel ? ($r[$cTel] ?? '') : ''); $email = (string)($cEmail ? ($r[$cEmail] ?? '') : '');
            $criado = $criado ?: ($cDt ? ($r[$cDt] ?? null) : null);
            if (!$ref && !empty($r['indicado_por_idatleta'])) $ref = (int)$r['indicado_por_idatleta'];
            $id = (int)$p['id'];
            if (isset($treinos[$id])) { $p['treinos'] = (int)$treinos[$id]['n']; $p['primeiro_treino'] = $treinos[$id]['p']; $p['ultimo_treino'] = $treinos[$id]['u']; }
            $ab = $abert[$id] ?? null;
            $p['aberturas'] = $ab ? (int)$ab['n'] : 0;
            $ult = $ab['u'] ?? ($r['ultimo_acesso'] ?? null);
            $p['ultimo_acesso'] = $ult ?: null;
            $criadoTs = $criado ? strtotime((string)$criado) : 0;
            // "abriu o app": houve abertura registrada; sem a tabela de acessos, vale o último acesso uns minutos depois do cadastro
            $p['abriu_app'] = $p['aberturas'] > 0 || ($ult && $criadoTs && strtotime((string)$ult) - $criadoTs > 600);
            $p['bloqueado'] = isset($r['stbloqueio']) && $r['stbloqueio'] === 'S';
            $pago = false; $pend = false; $teste = null; $planoPago = null; $valor = null;
            foreach ($mats[$id] ?? [] as $m) {
                if (strtolower((string)($m['stmatricula'] ?? '')) === 'cancelada') continue;
                if (trEhPlanoTeste($m)) { $teste = ['inicio' => $m['dtinicio'], 'vence' => $m['dtvencimento']]; continue; }
                if (($m['stpgto'] ?? '') === 'S' && (float)($m['vlpagamento'] ?? 0) > 0) { $pago = true; $planoPago = $m['tipo_plano'] ?: $m['dshistorico']; $valor = (float)$m['vlpagamento']; }
                elseif (($m['stpgto'] ?? '') === 'N') { $pend = true; $planoPago = $planoPago ?: ($m['tipo_plano'] ?: $m['dshistorico']); }
            }
            if ($teste) { $teste['dias_restantes'] = (int)round((strtotime((string)$teste['vence']) - strtotime($hoje)) / 86400); $teste['expirou'] = $teste['dias_restantes'] < 0; }
            $p['teste'] = $teste;
            $p['matricula'] = ($pago || $pend) ? ['plano' => $planoPago, 'pago' => $pago, 'valor' => $valor] : null;
            $etapa = $pago ? 'cliente' : ($pend ? 'negociando' : (($teste && $teste['expirou']) ? 'teste_expirado' : ($p['treinos'] > 0 ? 'treinando' : ($p['abriu_app'] ? 'abriu_app' : 'so_conta'))));
        } elseif ($p['tipo'] === 'contato' && isset($leadsRows[$p['id']])) {
            $r = $leadsRows[$p['id']];
            $nome = (string)($r['nome'] ?? ''); $tel = (string)($r['whatsapp'] ?? ''); $email = (string)($r['email'] ?? ''); $obj = (string)($r['objetivo'] ?? '');
            $criado = $criado ?: ($r['criado_em'] ?? null);
            if (!$ref && !empty($r['indicado_por_idatleta'])) $ref = (int)$r['indicado_por_idatleta'];
            $etapa = 'contato';
            $p['status_lead'] = $r['status'] ?? null;
        } elseif ($p['tipo'] === 'anonimo') {
            $etapa = $p['anonimo_evento'] === 'conta_criada' ? 'so_conta' : 'contato';
        } else { continue; }   // conta apagada ou fora da tabela: não há o que mostrar
        $wa = trWhatsapp($tel);
        $out[] = array_merge($p, [
            'nome' => $nome !== '' ? $nome : '(sem nome)', 'telefone' => $tel, 'whatsapp' => $wa, 'email' => $email, 'objetivo' => $obj, 'criado_em' => $criado,
            'ref' => $ref, 'indicado_por' => $ref ? ($nomesRef[$ref] ?? ('Aluno #' . $ref)) : null,
            'etapa' => $etapa, 'etapa_rotulo' => TR_ETAPAS[$etapa][1], 'etapa_ordem' => TR_ETAPAS[$etapa][0], 'acao' => TR_ETAPAS[$etapa][2],
            'mensagem' => $etapa === 'cliente' ? '' : trMensagem($etapa, $nome),
        ]);
    }
    usort($out, fn($a, $b) => strcmp((string)($b['criado_em'] ?? ''), (string)($a['criado_em'] ?? '')));
    $resumo = []; foreach (TR_ETAPAS as $k => $v) $resumo[$k] = 0;
    foreach ($out as $p) $resumo[$p['etapa']]++;
    return ['periodo' => ['de' => $de, 'ate' => $ate], 'pessoas' => $out, 'resumo' => $resumo, 'etapas' => array_map(fn($k, $v) => ['etapa' => $k, 'ordem' => $v[0], 'rotulo' => $v[1]], array_keys(TR_ETAPAS), array_values(TR_ETAPAS)),
            'captadas_medidas' => $captadasMedidas, 'sem_medicao' => count(array_filter($out, fn($p) => !$p['medido'])), 'gerado_em' => gmdate('Y-m-d H:i:s', $ts ?? time())];
}
