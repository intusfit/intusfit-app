<?php
/**
 * CDD: gestão. Biblioteca interna (o "_" no nome bloqueia o acesso direto), carregada por _nucleo.php.
 *
 * 1) Liberação manual de módulo por aluna (cdd_aluna_modulo): o módulo que ainda está trancado pela regra de liberação (dias ou data)
 *    abre para aquela aluna. A regra do módulo não muda e as outras alunas não são afetadas. Desfazer é pelo painel.
 * 2) Páginas de venda (LPs): cada LP é um MODELO (arquivo lp-*.html em cdd/) mais textos e links próprios (cdd_lp_campo). Várias LPs podem usar o
 *    mesmo modelo com textos diferentes (variantes para teste). O endereço /vendas sorteia entre as LPs ativas com peso (teste A/B, sticky por
 *    cookie); cada LP também tem endereço fixo /lp/<slug>. Os textos editáveis são elementos marcados com data-k no modelo; os links de compra,
 *    com data-lk. Rastreio de visita, clique, rolagem e vídeo por lp-track.js (cdd_lp_evento); lead e venda vêm das tabelas que já existem.
 */

const CDD_LP_TIPOS = ['view', 'click', 'lead', 'scroll50', 'scroll90', 'video'];

function cddGestaoDdl(string $c): array {
    return [
        'cdd_aluna_modulo' => "CREATE TABLE IF NOT EXISTS cdd_aluna_modulo (
            idaluna INT NOT NULL,
            idmodulo INT NOT NULL,
            liberado_em DATETIME NOT NULL,
            por INT NULL,
            obs VARCHAR(200) NULL,
            PRIMARY KEY (idaluna, idmodulo)
        ) $c",
        'cdd_lp' => "CREATE TABLE IF NOT EXISTS cdd_lp (
            idlp INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL,
            nome VARCHAR(100) NOT NULL,
            arquivo VARCHAR(60) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'ativa',
            peso INT NOT NULL DEFAULT 0,
            obs VARCHAR(300) NULL,
            criado_em DATETIME NOT NULL,
            UNIQUE INDEX idx_slug (slug)
        ) $c",
        'cdd_lp_campo' => "CREATE TABLE IF NOT EXISTS cdd_lp_campo (
            idlp INT NOT NULL,
            chave VARCHAR(60) NOT NULL,
            valor TEXT NOT NULL,
            PRIMARY KEY (idlp, chave)
        ) $c",
        'cdd_lp_evento' => "CREATE TABLE IF NOT EXISTS cdd_lp_evento (
            idevento BIGINT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(40) NOT NULL,
            tipo VARCHAR(12) NOT NULL,
            alvo VARCHAR(40) NULL,
            vid VARCHAR(32) NOT NULL,
            utm_source VARCHAR(80) NULL,
            utm_medium VARCHAR(80) NULL,
            utm_campaign VARCHAR(120) NULL,
            ref VARCHAR(120) NULL,
            disp VARCHAR(8) NULL,
            iph VARCHAR(12) NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_slug_data (slug, criado_em),
            INDEX idx_iph (iph, criado_em)
        ) $c",
    ];
}

/* ───────────────────────── Liberação manual de módulo ───────────────────────── */

/** @return array<int,bool> idmodulo => true, dos módulos liberados à mão para a aluna */
function cddLiberadosManual(PDO $pdo, int $idaluna): array {
    if ($idaluna <= 0) return [];
    try { $st = $pdo->prepare('SELECT idmodulo FROM cdd_aluna_modulo WHERE idaluna = ?'); $st->execute([$idaluna]); }
    catch (Throwable $e) { return []; }
    $r = [];
    foreach ($st->fetchAll() as $x) $r[(int)$x['idmodulo']] = true;
    return $r;
}

function cddGestaoAdmin(PDO $pdo, string $metodo, string $acao, array $q, array $b, array $ctx, int $idadmin): ?array {
    $get = ($metodo === 'GET');

    if ($acao === 'a_aluna_modulos' && $get) {
        $id = (int)($q['idaluna'] ?? 0);
        $st = $pdo->prepare('SELECT idaluna, nome FROM cdd_aluna WHERE idaluna = ?'); $st->execute([$id]);
        $al = $st->fetch();
        if (!$al) return cddErro(404, 'aluna_nao_encontrada', 'Aluna não encontrada.');
        $mat = cddMatricula($pdo, $id);
        $manual = cddLiberadosManual($pdo, $id);
        $mods = $pdo->query('SELECT idmodulo, titulo, grupo, publicado, liberacao_dias, liberacao_data FROM cdd_modulo ORDER BY ordem, idmodulo')->fetchAll();
        $out = [];
        foreach ($mods as $m) {
            $lib = cddModuloLiberacao($m, $mat['inicio'] ?? null);
            $idm = (int)$m['idmodulo'];
            $out[] = ['idmodulo' => $idm, 'titulo' => $m['titulo'], 'grupo' => $m['grupo'], 'publicado' => (int)$m['publicado'],
                      'trancado_pela_regra' => !$lib['liberado'], 'libera_em' => $lib['libera_em'], 'liberado_manual' => isset($manual[$idm])];
        }
        return cddResp(200, ['aluna' => ['idaluna' => (int)$al['idaluna'], 'nome' => $al['nome']], 'matricula_ativa' => $mat['ativa'], 'modulos' => $out]);
    }
    if ($acao === 'a_aluna_modulo' && !$get) {
        $ida = (int)($b['idaluna'] ?? 0); $idm = (int)($b['idmodulo'] ?? 0); $liberar = !empty($b['liberar']);
        $a = $pdo->prepare("SELECT 1 FROM cdd_aluna WHERE idaluna = ? AND papel = 'aluna'"); $a->execute([$ida]);
        $m = $pdo->prepare('SELECT 1 FROM cdd_modulo WHERE idmodulo = ?'); $m->execute([$idm]);
        if (!$a->fetchColumn() || !$m->fetchColumn()) return cddErro(404, 'nao_encontrado', 'Aluna ou módulo não encontrado.');
        $pdo->prepare('DELETE FROM cdd_aluna_modulo WHERE idaluna = ? AND idmodulo = ?')->execute([$ida, $idm]);
        if ($liberar) $pdo->prepare('INSERT INTO cdd_aluna_modulo (idaluna, idmodulo, liberado_em, por, obs) VALUES (?, ?, ?, ?, ?)')->execute([$ida, $idm, cddAgora(), $idadmin ?: null, cddStr($b['obs'] ?? '', 200) ?: null]);
        cddRegistrarEvento($pdo, $ida, $liberar ? 'modulo_liberado_manual' : 'modulo_liberacao_desfeita', 'painel', 'ok', (string)$idm);
        return cddResp(200, ['ok' => true, 'liberado_manual' => $liberar]);
    }
    return cddLpsAdmin($pdo, $metodo, $acao, $q, $b);
}

/* ───────────────────────── Páginas de venda (LPs) ───────────────────────── */

function cddLpArquivos(): array {
    $r = [];
    foreach (glob(__DIR__ . '/../lp-*.html') ?: [] as $f) $r[] = basename($f);
    sort($r);
    return $r;
}

function cddLpSementes(PDO $pdo): void {
    $n = (int)$pdo->query('SELECT COUNT(*) FROM cdd_lp')->fetchColumn();
    if ($n > 0) return;
    $ins = $pdo->prepare('INSERT INTO cdd_lp (slug, nome, arquivo, status, peso, obs, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $ins->execute(['escura', 'Escura (principal)', 'lp-escura.html', 'ativa', 100, 'Landing atual da raiz do domínio.', cddAgora()]);
    $ins->execute(['mulheres', 'Feminina rosé', 'lp-mulheres.html', 'pausada', 0, 'Versão em tons femininos, com dores e desejos do Instagram.', cddAgora()]);
}

/** Campos editáveis do modelo: [['chave', 'rotulo', 'padrao']]. Elementos com data-k="chave" (e data-kl="rótulo"). */
function cddLpCampos(string $html): array {
    $r = [];
    if (preg_match_all('/<([a-z0-9]+)\b([^>]*\bdata-k="([a-z0-9._-]+)"[^>]*)>(.*?)<\/\1>/is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $rot = preg_match('/\bdata-kl="([^"]*)"/', $x[2], $l) ? html_entity_decode($l[1], ENT_QUOTES, 'UTF-8') : $x[3];
            $r[$x[3]] = ['chave' => $x[3], 'rotulo' => $rot, 'padrao' => trim($x[4])];
        }
    }
    return array_values($r);
}

/** Links de compra do modelo: [['chave' => 'link.anual', 'rotulo', 'padrao' => url]] (a partir de data-lk). */
function cddLpLinks(string $html): array {
    $r = [];
    if (preg_match_all('/<a\b([^>]*\bdata-lk="([a-z0-9._-]+)"[^>]*)>/is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $url = preg_match('/\bdata-eduzz="([^"]*)"/', $x[1], $u) ? $u[1] : (preg_match('/\bhref="([^"]*)"/', $x[1], $h) ? $h[1] : '');
            $rot = preg_match('/\bdata-kl="([^"]*)"/', $x[1], $l) ? html_entity_decode($l[1], ENT_QUOTES, 'UTF-8') : 'Link de compra ' . $x[2];
            $r['link.' . $x[2]] = ['chave' => 'link.' . $x[2], 'rotulo' => $rot, 'padrao' => html_entity_decode($url, ENT_QUOTES, 'UTF-8')];
        }
    }
    return array_values($r);
}

/** Texto do editor para dentro do HTML: só <em>, <i>, <b>, <strong> e <br>, sem atributos. */
function cddLpLimparTexto(string $v): string {
    $v = mb_substr(trim($v), 0, 700);
    $v = strip_tags($v, '<em><i><b><strong><br>');
    return preg_replace('/<(em|i|b|strong|br)\b[^>]*>/i', '<$1>', $v);
}

function cddLpUrlOk(string $u): string {
    $u = trim($u);
    return preg_match('~^https://[a-z0-9.-]+\.[a-z]{2,}(/[^\s"<>]*)?$~i', $u) ? $u : '';
}

/** Aplica os textos e links da LP ao modelo e injeta o rastreio. */
function cddLpAplicar(string $html, array $campos, string $slug): string {
    if ($campos) {
        $html = preg_replace_callback('/(<([a-z0-9]+)\b[^>]*\bdata-k="([a-z0-9._-]+)"[^>]*>)(.*?)(<\/\2>)/is', function ($m) use ($campos) {
            return isset($campos[$m[3]]) ? $m[1] . cddLpLimparTexto($campos[$m[3]]) . $m[5] : $m[0];
        }, $html);
        $html = preg_replace_callback('/<a\b[^>]*\bdata-lk="([a-z0-9._-]+)"[^>]*>/is', function ($m) use ($campos) {
            $url = cddLpUrlOk($campos['link.' . $m[1]] ?? '');
            if ($url === '') return $m[0];
            $e = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $t = preg_replace('/\bdata-eduzz="[^"]*"/', 'data-eduzz="' . $e . '"', $m[0]);
            return preg_replace('/\bhref="[^"]*"/', 'href="' . $e . '"', $t);
        }, $html);
    }
    $inj = '<script>window.CDD_LP=' . json_encode($slug) . ';</script><script src="lp-track.js?v=1" defer></script>';
    return stripos($html, '</body>') !== false ? preg_replace('/<\/body>/i', $inj . '</body>', $html, 1) : $html . $inj;
}

function cddLpCamposSalvos(PDO $pdo, int $idlp): array {
    $st = $pdo->prepare('SELECT chave, valor FROM cdd_lp_campo WHERE idlp = ?'); $st->execute([$idlp]);
    $r = []; foreach ($st->fetchAll() as $x) $r[$x['chave']] = $x['valor'];
    return $r;
}

/** Escolhe a LP do endereço principal: a do cookie (se ainda ativa) ou sorteio pelo peso. Devolve a linha de cdd_lp ou null. */
function cddLpEscolher(PDO $pdo, string $slugCookie, ?int $sorteio = null): ?array {
    cddLpSementes($pdo);
    $ativas = $pdo->query("SELECT * FROM cdd_lp WHERE status = 'ativa' AND peso > 0 ORDER BY idlp")->fetchAll();
    if (!$ativas) { $r = $pdo->query("SELECT * FROM cdd_lp WHERE status <> 'arquivada' ORDER BY idlp LIMIT 1")->fetch(); return $r ?: null; }
    foreach ($ativas as $l) if ($l['slug'] === $slugCookie) return $l;
    $total = array_sum(array_map(function ($l) { return (int)$l['peso']; }, $ativas));
    $x = $sorteio ?? random_int(1, $total);
    foreach ($ativas as $l) { $x -= (int)$l['peso']; if ($x <= 0) return $l; }
    return $ativas[0];
}

/** HTML pronto de uma LP (ou null se não existir). */
function cddLpRenderizar(PDO $pdo, array $lp): ?string {
    $arq = basename((string)$lp['arquivo']);
    if (!preg_match('/^lp-[a-z0-9-]+\.html$/', $arq)) return null;
    $f = __DIR__ . '/../' . $arq;
    if (!is_file($f)) return null;
    return cddLpAplicar((string)file_get_contents($f), cddLpCamposSalvos($pdo, (int)$lp['idlp']), $lp['slug']);
}

/* ---- rastreio público ---- */
function cddLpRegistrar(PDO $pdo, array $b, string $ip, string $ua): array {
    $slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($b['slug'] ?? '')));
    $tipo = (string)($b['tipo'] ?? '');
    $vid = preg_replace('/[^a-z0-9]/', '', strtolower((string)($b['vid'] ?? '')));
    if (!in_array($tipo, CDD_LP_TIPOS, true) || strlen($vid) < 8 || strlen($vid) > 32 || $slug === '') return cddErro(422, 'dados_invalidos', 'Dados inválidos.');
    if (preg_match('/bot|crawl|spider|facebookexternalhit|whatsapp|preview|slurp|headless/i', $ua)) return cddResp(200, ['ok' => true, 'ignorado' => true]);
    $st = $pdo->prepare("SELECT 1 FROM cdd_lp WHERE slug = ? AND status <> 'arquivada'"); $st->execute([$slug]);
    if (!$st->fetchColumn()) return cddErro(404, 'lp_desconhecida', 'LP desconhecida.');
    $iph = substr(sha1($ip . '|cdd'), 0, 12);
    $c = $pdo->prepare('SELECT COUNT(*) FROM cdd_lp_evento WHERE iph = ? AND criado_em >= ?'); $c->execute([$iph, date('Y-m-d H:i:s', time() - 60)]);
    if ((int)$c->fetchColumn() >= 150) return cddErro(429, 'muitos_eventos', 'Muitos eventos.');
    $host = ''; if (!empty($b['ref'])) { $h = parse_url((string)$b['ref'], PHP_URL_HOST); $host = is_string($h) ? substr($h, 0, 120) : ''; }
    $disp = preg_match('/mobile|android|iphone|ipad/i', $ua) ? 'mobile' : 'desktop';
    $pdo->prepare('INSERT INTO cdd_lp_evento (slug, tipo, alvo, vid, utm_source, utm_medium, utm_campaign, ref, disp, iph, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, $tipo, cddStr($b['alvo'] ?? '', 40) ?: null, $vid, cddStr($b['utm_source'] ?? '', 80) ?: null, cddStr($b['utm_medium'] ?? '', 80) ?: null, cddStr($b['utm_campaign'] ?? '', 120) ?: null, $host ?: null, $disp, $iph, cddAgora()]);
    return cddResp(200, ['ok' => true]);
}

/* ---- estatísticas ---- */
function cddLpOrigensDeLead(string $slug): array {
    $o = [$slug, 'lp-' . $slug];
    if ($slug === 'escura') $o[] = 'vendas';
    return $o;
}

function cddLpEstatisticas(PDO $pdo, string $slug, int $dias): array {
    $desde = $dias > 0 ? date('Y-m-d 00:00:00', time() - ($dias - 1) * 86400) : '2000-01-01 00:00:00';
    $n = function ($sql, $par) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($par); return (int)$s->fetchColumn(); };
    $visitantes = $n("SELECT COUNT(DISTINCT vid) FROM cdd_lp_evento WHERE slug = ? AND tipo = 'view' AND criado_em >= ?", [$slug, $desde]);
    $visitas = $n("SELECT COUNT(*) FROM cdd_lp_evento WHERE slug = ? AND tipo = 'view' AND criado_em >= ?", [$slug, $desde]);
    $s = $pdo->prepare("SELECT alvo, COUNT(*) AS n, COUNT(DISTINCT vid) AS pessoas FROM cdd_lp_evento WHERE slug = ? AND tipo = 'click' AND criado_em >= ? GROUP BY alvo ORDER BY n DESC");
    $s->execute([$slug, $desde]); $cliques = $s->fetchAll();
    foreach ($cliques as &$c) { $c['n'] = (int)$c['n']; $c['pessoas'] = (int)$c['pessoas']; }
    $checkout = $n("SELECT COUNT(DISTINCT vid) FROM cdd_lp_evento WHERE slug = ? AND tipo = 'click' AND alvo IN ('anual', 'mensal') AND criado_em >= ?", [$slug, $desde]);
    $scroll90 = $n("SELECT COUNT(DISTINCT vid) FROM cdd_lp_evento WHERE slug = ? AND tipo = 'scroll90' AND criado_em >= ?", [$slug, $desde]);
    $video = $n("SELECT COUNT(DISTINCT vid) FROM cdd_lp_evento WHERE slug = ? AND tipo = 'video' AND criado_em >= ?", [$slug, $desde]);
    $orig = cddLpOrigensDeLead($slug);
    $leads = $n('SELECT COUNT(*) FROM cdd_lead WHERE origem IN (' . implode(',', array_fill(0, count($orig), '?')) . ') AND criado_em >= ?', array_merge($orig, [$desde]));
    // venda atribuída à LP: lead que veio dela e virou aluna (ganho); o valor vem dos pagamentos pagos dessa aluna
    $in = implode(',', array_fill(0, count($orig), '?'));
    $s = $pdo->prepare("SELECT COUNT(DISTINCT l.idaluna) AS n, COALESCE(SUM(p.valor), 0) AS v FROM cdd_lead l LEFT JOIN cdd_pagamento p ON p.idaluna = l.idaluna AND p.status = 'pago' WHERE l.status = 'ganho' AND l.idaluna IS NOT NULL AND l.origem IN ($in) AND l.criado_em >= ?");
    $s->execute(array_merge($orig, [$desde])); $vd = $s->fetch();
    $s = $pdo->prepare("SELECT COALESCE(NULLIF(utm_source, ''), 'direto') AS fonte, COUNT(DISTINCT vid) AS pessoas FROM cdd_lp_evento WHERE slug = ? AND tipo = 'view' AND criado_em >= ? GROUP BY fonte ORDER BY pessoas DESC LIMIT 6");
    $s->execute([$slug, $desde]); $fontes = $s->fetchAll();
    foreach ($fontes as &$f) $f['pessoas'] = (int)$f['pessoas'];
    $s = $pdo->prepare("SELECT disp, COUNT(DISTINCT vid) AS pessoas FROM cdd_lp_evento WHERE slug = ? AND tipo = 'view' AND criado_em >= ? GROUP BY disp");
    $s->execute([$slug, $desde]); $disp = []; foreach ($s->fetchAll() as $d) $disp[(string)$d['disp']] = (int)$d['pessoas'];
    $serie = [];
    $dd = $dias > 0 ? min($dias, 60) : 30;
    $s = $pdo->prepare("SELECT substr(criado_em, 1, 10) AS dia, COUNT(DISTINCT vid) AS p FROM cdd_lp_evento WHERE slug = ? AND tipo = 'view' AND criado_em >= ? GROUP BY dia");
    $s->execute([$slug, date('Y-m-d 00:00:00', time() - ($dd - 1) * 86400)]); $porDia = []; foreach ($s->fetchAll() as $x) $porDia[$x['dia']] = (int)$x['p'];
    for ($i = $dd - 1; $i >= 0; $i--) { $d = date('Y-m-d', time() - $i * 86400); $serie[] = ['dia' => $d, 'visitantes' => $porDia[$d] ?? 0]; }
    $taxa = function ($a, $b) { return $b > 0 ? round($a * 100 / $b, 1) : null; };
    return ['visitantes' => $visitantes, 'visitas' => $visitas, 'cliques' => $cliques, 'checkout' => $checkout, 'rolou_90' => $scroll90, 'video' => $video, 'leads' => $leads,
            'vendas' => (int)$vd['n'], 'receita' => round((float)$vd['v'], 2), 'fontes' => $fontes, 'dispositivos' => $disp, 'serie' => $serie,
            'taxa_checkout' => $taxa($checkout, $visitantes), 'taxa_lead' => $taxa($leads, $visitantes), 'taxa_venda' => $taxa((int)$vd['n'], $visitantes)];
}

/* ---- rotas do painel ---- */
function cddLpsAdmin(PDO $pdo, string $metodo, string $acao, array $q, array $b): ?array {
    if (strpos($acao, 'a_lp') !== 0) return null;
    $get = ($metodo === 'GET');
    cddLpSementes($pdo);

    if ($acao === 'a_lps' && $get) {
        $dias = in_array((int)($q['dias'] ?? 30), [0, 7, 30, 90], true) ? (int)($q['dias'] ?? 30) : 30;
        $l = $pdo->query('SELECT idlp, slug, nome, arquivo, status, peso, obs FROM cdd_lp ORDER BY idlp')->fetchAll();
        foreach ($l as &$x) { $x['idlp'] = (int)$x['idlp']; $x['peso'] = (int)$x['peso']; $x['estatisticas'] = cddLpEstatisticas($pdo, $x['slug'], $dias); $x['campos_editados'] = (int)$pdo->query('SELECT COUNT(*) FROM cdd_lp_campo WHERE idlp = ' . $x['idlp'])->fetchColumn(); }
        return cddResp(200, ['lps' => $l, 'dias' => $dias, 'modelos' => cddLpArquivos()]);
    }
    if ($acao === 'a_lp_campos' && $get) {
        $id = (int)($q['idlp'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM cdd_lp WHERE idlp = ?'); $st->execute([$id]); $lp = $st->fetch();
        if (!$lp) return cddErro(404, 'lp_nao_encontrada', 'LP não encontrada.');
        $arq = basename($lp['arquivo']); $f = __DIR__ . '/../' . $arq;
        $html = preg_match('/^lp-[a-z0-9-]+\.html$/', $arq) && is_file($f) ? (string)file_get_contents($f) : '';
        $salvos = cddLpCamposSalvos($pdo, $id);
        $campos = array_merge(cddLpCampos($html), cddLpLinks($html));
        foreach ($campos as &$c) { $c['valor'] = $salvos[$c['chave']] ?? null; }
        return cddResp(200, ['campos' => $campos, 'slug' => $lp['slug']]);
    }
    if ($acao === 'a_lp_campo' && !$get) {
        $id = (int)($b['idlp'] ?? 0); $chave = preg_replace('/[^a-z0-9._-]/', '', strtolower((string)($b['chave'] ?? '')));
        $st = $pdo->prepare('SELECT arquivo FROM cdd_lp WHERE idlp = ?'); $st->execute([$id]); $arq = $st->fetchColumn();
        if (!$arq || $chave === '') return cddErro(404, 'nao_encontrado', 'LP ou campo não encontrado.');
        $f = __DIR__ . '/../' . basename((string)$arq);
        $html = is_file($f) ? (string)file_get_contents($f) : '';
        $validas = array_column(array_merge(cddLpCampos($html), cddLpLinks($html)), 'chave');
        if (!in_array($chave, $validas, true)) return cddErro(422, 'campo_invalido', 'Este campo não existe no modelo.');
        $valor = (string)($b['valor'] ?? '');
        if (strpos($chave, 'link.') === 0) { $valor = trim($valor) === '' ? '' : cddLpUrlOk($valor); if (trim((string)($b['valor'] ?? '')) !== '' && $valor === '') return cddErro(422, 'link_invalido', 'Use um endereço completo começando com https://.'); }
        else $valor = cddLpLimparTexto($valor);
        $pdo->prepare('DELETE FROM cdd_lp_campo WHERE idlp = ? AND chave = ?')->execute([$id, $chave]);
        if ($valor !== '') $pdo->prepare('INSERT INTO cdd_lp_campo (idlp, chave, valor) VALUES (?, ?, ?)')->execute([$id, $chave, $valor]);
        return cddResp(200, ['ok' => true, 'restaurado' => $valor === '']);
    }
    if ($acao === 'a_lp_salvar' && !$get) {
        $id = (int)($b['idlp'] ?? 0);
        $nome = cddStr($b['nome'] ?? '', 100); $arq = basename((string)($b['arquivo'] ?? ''));
        $status = in_array($b['status'] ?? '', ['ativa', 'pausada', 'arquivada'], true) ? $b['status'] : 'pausada';
        $peso = max(0, min(100, (int)($b['peso'] ?? 0))); $obs = cddStr($b['obs'] ?? '', 300);
        if ($nome === '' || !in_array($arq, cddLpArquivos(), true)) return cddErro(422, 'dados_invalidos', 'Informe o nome e escolha um modelo existente.');
        if ($id) {
            $pdo->prepare('UPDATE cdd_lp SET nome = ?, arquivo = ?, status = ?, peso = ?, obs = ? WHERE idlp = ?')->execute([$nome, $arq, $status, $peso, $obs ?: null, $id]);
            return cddResp(200, ['idlp' => $id]);
        }
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(str_replace(' ', '-', (string)($b['slug'] ?? ''))));
        if ($slug === '' || strlen($slug) > 40) return cddErro(422, 'slug_invalido', 'Informe um nome curto para o endereço (letras, números e hífen).');
        $dup = $pdo->prepare('SELECT 1 FROM cdd_lp WHERE slug = ?'); $dup->execute([$slug]);
        if ($dup->fetchColumn()) return cddErro(409, 'slug_repetido', 'Já existe uma LP com este endereço.');
        $pdo->prepare('INSERT INTO cdd_lp (slug, nome, arquivo, status, peso, obs, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$slug, $nome, $arq, $status, $peso, $obs ?: null, cddAgora()]);
        $novo = (int)$pdo->lastInsertId();
        $copiar = (int)($b['copiar_de'] ?? 0);   // variante: nasce com os textos e links da LP de origem
        if ($copiar) $pdo->prepare('INSERT INTO cdd_lp_campo (idlp, chave, valor) SELECT ?, chave, valor FROM cdd_lp_campo WHERE idlp = ?')->execute([$novo, $copiar]);
        return cddResp(200, ['idlp' => $novo]);
    }
    return null;
}
