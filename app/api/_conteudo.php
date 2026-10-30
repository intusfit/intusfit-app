<?php
/**
 * Central de conteúdo (/conteudo/): TODA a regra. Rotas em conteudo.php, dados de fábrica em _conteudo_padrao.php.
 *
 * Sem tabela nova. Tudo em intus_config (o mesmo armazenamento do Intus Cut, por isso usa cut_ler e cut_gravar):
 *   cont_cfg      marcas, formatos, tipos, funis, pessoas e os prazos (o que a tela edita; sem nada salvo vale a fábrica)
 *   cont_pecas    {id: peça}   posts e vídeos em produção ou publicados
 *   cont_eventos  {id: evento} gravações, eventos, campeonatos e reuniões (o que o Notion de 2025 guardava)
 *   cont_ofertas  [oferta]     o que cada post pode vender (link, preço, cupom)
 *   cont_links    [link]       pastas do Drive, canais e páginas
 * Quem usa: administrador, ou quem tem a chave cut_ver (a equipe que edita vídeo). Aluno nunca.
 *
 * A mesma regra vale para o quadro, o calendário e a edição: toda mudança de etapa passa por cont_salvar_peca,
 * que confere as exigências da etapa (cont_pendencias). Os textos passam pelo analisador de copy da Central de atendimento
 * (atd_analisar_copy), sem copiar as regras.
 */

require_once __DIR__ . '/_intuscut.php';
require_once __DIR__ . '/_conteudo_padrao.php';

const CONT_PECAS_MAX = 1500;
const CONT_EVENTOS_MAX = 800;
const CONT_HISTORICO_MAX = 30;
const CONT_PESSOAS_MAX = 8;
const CONT_ARQUIVOS_MAX = 10;
const CONT_ESTADOS_PUB = ['manual', 'automatico'];

function cont_pode(PDO $pdo, array $ctx): bool { return cut_pode($pdo, $ctx); }

function cont_hoje(?string $ref = null): string {
    if ($ref !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) return $ref;
    return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}
function cont_agora(): string { return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d\TH:i:s'); }

function cont_txt($v, int $max): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
    return mb_substr($s, 0, $max);
}
function cont_data($v): string {
    $s = trim((string)$v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return '';
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : '';
}
function cont_hora($v): string {
    $s = trim((string)$v);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) ? $s : '';
}
function cont_slug(string $s): string {
    $t = mb_strtolower(trim($s));
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c']);
    $t = trim(preg_replace('/[^a-z0-9]+/', '-', $t) ?? '', '-');
    return mb_substr($t, 0, 40);
}
function cont_url($v): string {
    $s = trim((string)$v);
    if ($s === '' || strlen($s) > 500) return '';
    return preg_match('#^https?://[^\s<>"\']+$#i', $s) ? $s : '';
}

// ───────────────────────── configuração editável ─────────────────────────

function cont_cfg(PDO $pdo): array {
    $pad = cont_cfg_padrao();
    $salvo = cut_ler($pdo, 'cont_cfg', null);
    if (!is_array($salvo)) return $pad;
    foreach (['marcas', 'formatos', 'tipos', 'funis'] as $k) if (isset($salvo[$k]) && is_array($salvo[$k]) && count($salvo[$k])) $pad[$k] = array_values($salvo[$k]);
    if (isset($salvo['pessoas']) && is_array($salvo['pessoas'])) $pad['pessoas'] = array_values($salvo['pessoas']);
    foreach (['reciclar_dias' => [7, 365], 'parado_dias' => [1, 60], 'venda_max_pct' => [5, 100]] as $k => [$mi, $ma]) {
        if (isset($salvo[$k])) $pad[$k] = max($mi, min($ma, (int)$salvo[$k]));
    }
    return $pad;
}

/** Valida o que a tela mandou. Devolve a configuração limpa ou ['erro' => texto]. */
function cont_cfg_limpar($in): array {
    if (!is_array($in)) return ['erro' => 'configuração inválida'];
    $pad = cont_cfg_padrao();
    $out = [];
    $lista = function ($v, int $max, callable $item, string $rotulo) use (&$out) {
        if (!is_array($v) || !count($v)) return ['erro' => "$rotulo: precisa ter pelo menos um item"];
        if (count($v) > $max) return ['erro' => "$rotulo: no máximo $max itens"];
        $r = []; $ids = [];
        foreach ($v as $x) {
            if (!is_array($x)) continue;
            $n = $item($x);
            if ($n === null) continue;
            if (isset($ids[$n['id']])) return ['erro' => "$rotulo: \"{$n['nome']}\" repete outro nome"];
            $ids[$n['id']] = true; $r[] = $n;
        }
        if (!count($r)) return ['erro' => "$rotulo: nenhum item válido"];
        return ['ok' => $r];
    };
    $idDe = function (array $x): string { $id = cont_slug((string)($x['id'] ?? '')); return $id !== '' ? $id : cont_slug((string)($x['nome'] ?? '')); };

    $r = $lista($in['marcas'] ?? null, 8, function ($x) use ($idDe) {
        $nome = cont_txt($x['nome'] ?? '', 40); $id = $idDe($x); if ($nome === '' || $id === '') return null;
        $h = cont_txt($x['handle'] ?? '', 40);
        return ['id' => $id, 'nome' => $nome, 'handle' => $h, 'meta_semana' => max(0, min(21, (int)($x['meta_semana'] ?? 0))),
                'cor' => max(1, min(6, (int)($x['cor'] ?? 1))), 'papel' => cont_txt($x['papel'] ?? '', 120)];
    }, 'Marcas');
    if (isset($r['erro'])) return $r; $out['marcas'] = $r['ok'];

    $r = $lista($in['formatos'] ?? null, 20, function ($x) use ($idDe) {
        $nome = cont_txt($x['nome'] ?? '', 40); $id = $idDe($x); if ($nome === '' || $id === '') return null;
        return ['id' => $id, 'nome' => $nome, 'midia' => !empty($x['midia']), 'roteiro' => !array_key_exists('roteiro', $x) || !empty($x['roteiro'])];
    }, 'Formatos');
    if (isset($r['erro'])) return $r; $out['formatos'] = $r['ok'];

    $r = $lista($in['tipos'] ?? null, 20, function ($x) use ($idDe) {
        $nome = cont_txt($x['nome'] ?? '', 40); $id = $idDe($x); if ($nome === '' || $id === '') return null;
        return ['id' => $id, 'nome' => $nome, 'venda' => !empty($x['venda'])];
    }, 'Tipos de post');
    if (isset($r['erro'])) return $r; $out['tipos'] = $r['ok'];

    $r = $lista($in['funis'] ?? null, 8, function ($x) use ($idDe) {
        $nome = cont_txt($x['nome'] ?? '', 40); $id = $idDe($x); if ($nome === '' || $id === '') return null;
        return ['id' => $id, 'nome' => $nome];
    }, 'Etapas do funil');
    if (isset($r['erro'])) return $r; $out['funis'] = $r['ok'];

    // Pessoas: a lista pode ficar vazia (é só sugestão de nomes para marcar nas peças).
    $papeis = array_column(CONT_PAPEIS, 'id');
    $out['pessoas'] = [];
    $vistos = [];
    if (isset($in['pessoas']) && is_array($in['pessoas'])) {
        if (count($in['pessoas']) > 60) return ['erro' => 'Pessoas: no máximo 60 nomes'];
        foreach ($in['pessoas'] as $x) {
            if (!is_array($x)) continue;
            $nome = cont_txt($x['nome'] ?? '', 60); $id = $idDe($x);
            if ($nome === '' || $id === '') continue;
            if (isset($vistos[$id])) return ['erro' => "Pessoas: \"$nome\" repete outro nome"];
            $vistos[$id] = true;
            $pp = (string)($x['papel'] ?? 'apresentador');
            $out['pessoas'][] = ['id' => $id, 'nome' => $nome, 'papel' => in_array($pp, $papeis, true) ? $pp : 'outro'];
        }
    }
    foreach (['reciclar_dias' => [7, 365], 'parado_dias' => [1, 60], 'venda_max_pct' => [5, 100]] as $k => [$mi, $ma]) {
        $out[$k] = max($mi, min($ma, (int)($in[$k] ?? $pad[$k])));
    }
    return $out;
}

function cont_ofertas(PDO $pdo): array {
    $v = cut_ler($pdo, 'cont_ofertas', null);
    return is_array($v) ? array_values(array_filter($v, 'is_array')) : cont_ofertas_padrao();
}
function cont_links(PDO $pdo): array {
    $v = cut_ler($pdo, 'cont_links', null);
    return is_array($v) ? array_values(array_filter($v, 'is_array')) : cont_links_padrao();
}

function cont_ofertas_limpar($in, array $cfg): array {
    if (!is_array($in)) return ['erro' => 'lista inválida'];
    if (count($in) > 60) return ['erro' => 'no máximo 60 ofertas'];
    $marcas = array_column($cfg['marcas'], 'id'); $out = []; $ids = [];
    foreach ($in as $x) {
        if (!is_array($x)) continue;
        $nome = cont_txt($x['nome'] ?? '', 80); if ($nome === '') continue;
        $id = cont_slug((string)($x['id'] ?? '')) ?: cont_slug($nome);
        if ($id === '' || isset($ids[$id])) return ['erro' => "A oferta \"$nome\" repete outro nome"];
        $link = cont_url($x['link'] ?? '');
        if (trim((string)($x['link'] ?? '')) !== '' && $link === '') return ['erro' => "O link de \"$nome\" não é um endereço http(s) válido"];
        $m = (string)($x['marca'] ?? '');
        $ids[$id] = true;
        $out[] = ['id' => $id, 'marca' => in_array($m, $marcas, true) ? $m : '', 'nome' => $nome, 'preco' => cont_txt($x['preco'] ?? '', 40),
                  'link' => $link, 'cupom' => cont_txt($x['cupom'] ?? '', 40), 'ativo' => !array_key_exists('ativo', $x) || !empty($x['ativo']), 'obs' => cont_txt($x['obs'] ?? '', 240)];
    }
    return ['ok' => $out];
}
function cont_links_limpar($in): array {
    if (!is_array($in)) return ['erro' => 'lista inválida'];
    if (count($in) > 80) return ['erro' => 'no máximo 80 links'];
    $out = []; $ids = [];
    foreach ($in as $x) {
        if (!is_array($x)) continue;
        $nome = cont_txt($x['nome'] ?? '', 90); if ($nome === '') continue;
        $url = cont_url($x['url'] ?? '');
        if ($url === '') return ['erro' => "O link de \"$nome\" não é um endereço http(s) válido"];
        $id = cont_slug((string)($x['id'] ?? '')) ?: cont_slug($nome);
        if ($id === '') $id = 'l' . bin2hex(random_bytes(3));
        while (isset($ids[$id])) $id .= '-2';
        $ids[$id] = true;
        $out[] = ['id' => $id, 'grupo' => cont_txt($x['grupo'] ?? 'Links', 40) ?: 'Links', 'nome' => $nome, 'url' => $url, 'obs' => cont_txt($x['obs'] ?? '', 200)];
    }
    return ['ok' => $out];
}

// ───────────────────────── peças ─────────────────────────

function cont_ids(array $lista): array { return array_values(array_map(fn($x) => (string)$x['id'], $lista)); }
function cont_ordem_status(string $s): int { foreach (CONT_STATUS as $i => $x) if ($x['id'] === $s) return $i; return -1; }
function cont_nome_status(string $s): string { foreach (CONT_STATUS as $x) if ($x['id'] === $s) return $x['nome']; return $s; }

function cont_pecas(PDO $pdo): array {
    $m = cut_ler($pdo, 'cont_pecas', []);
    $out = [];
    foreach ($m as $id => $p) if (is_array($p) && is_string($id)) $out[$id] = $p + ['id' => $id];
    return $out;
}
function cont_eventos(PDO $pdo): array {
    $m = cut_ler($pdo, 'cont_eventos', []);
    $out = [];
    foreach ($m as $id => $e) if (is_array($e) && is_string($id)) $out[$id] = $e + ['id' => $id];
    return $out;
}

function cont_peca_vazia(): array {
    return ['titulo' => '', 'marca' => '', 'canais' => ['instagram'], 'formato' => '', 'tipo' => '', 'funil' => '', 'status' => 'ideia',
            'data' => '', 'hora' => '', 'gravacao_data' => '', 'responsavel' => '', 'pessoas' => [], 'autorizacao_imagem' => 'nao_precisa',
            'gancho' => '', 'roteiro' => '', 'legenda' => '', 'hashtags' => '', 'cta' => '', 'oferta' => '', 'cut_projeto' => '', 'arquivos' => [],
            'drive_link' => '', 'notas' => '', 'etiquetas' => [],
            'reciclar' => ['marcar' => 'nao', 'em' => '', 'origem' => '', 'motivo' => '', 'reciclada_em' => ''],
            'resultado' => ['alcance' => 0, 'curtidas' => 0, 'comentarios' => 0, 'salvos' => 0, 'compart' => 0, 'cliques' => 0, 'leads' => 0, 'link_publicado' => '', 'medido_em' => ''],
            'publicacao' => ['modo' => 'manual']];
}

/** Normaliza o que veio da tela sobre a peça anterior (ou uma vazia). Texto e listas limitados, ids conferidos contra a configuração. */
function cont_limpar_peca(array $in, array $cfg, ?array $antes = null): array {
    $b = $antes ?? cont_peca_vazia();
    $p = $b;
    $tem = fn($k) => array_key_exists($k, $in);

    if ($tem('titulo')) $p['titulo'] = cont_txt($in['titulo'], 140);
    $esc = function (string $campo, array $lista) use ($in, &$p, $tem) {
        if (!$tem($campo)) return;
        $v = (string)$in[$campo];
        $p[$campo] = ($v === '' || in_array($v, cont_ids($lista), true)) ? $v : ($p[$campo] ?? '');
    };
    $esc('marca', $cfg['marcas']); $esc('formato', $cfg['formatos']); $esc('tipo', $cfg['tipos']); $esc('funil', $cfg['funis']);
    if ($tem('canais')) {
        $c = array_values(array_unique(array_filter(array_map('strval', (array)$in['canais']), fn($x) => in_array($x, array_column(CONT_CANAIS, 'id'), true))));
        $p['canais'] = $c;
    }
    if ($tem('status') && cont_ordem_status((string)$in['status']) >= 0) $p['status'] = (string)$in['status'];
    if ($tem('data')) $p['data'] = cont_data($in['data']);
    if ($tem('hora')) $p['hora'] = cont_hora($in['hora']);
    if ($tem('gravacao_data')) $p['gravacao_data'] = cont_data($in['gravacao_data']);
    if ($tem('responsavel')) $p['responsavel'] = cont_txt($in['responsavel'], 60);
    if ($tem('pessoas')) {
        $ps = []; $papeis = array_column(CONT_PAPEIS, 'id');
        foreach ((array)$in['pessoas'] as $x) {
            if (!is_array($x) || count($ps) >= CONT_PESSOAS_MAX) continue;
            $nome = cont_txt($x['nome'] ?? '', 60); if ($nome === '') continue;
            $pp = (string)($x['papel'] ?? 'apresentador');
            $ps[] = ['nome' => $nome, 'papel' => in_array($pp, $papeis, true) ? $pp : 'outro'];
        }
        $p['pessoas'] = $ps;
    }
    if ($tem('autorizacao_imagem')) { $a = (string)$in['autorizacao_imagem']; $p['autorizacao_imagem'] = in_array($a, ['nao_precisa', 'pendente', 'ok'], true) ? $a : $p['autorizacao_imagem']; }
    if ($tem('gancho')) $p['gancho'] = cont_txt($in['gancho'], 300);
    if ($tem('roteiro')) $p['roteiro'] = cont_txt($in['roteiro'], 8000);
    if ($tem('legenda')) $p['legenda'] = cont_txt($in['legenda'], 2200);
    if ($tem('hashtags')) $p['hashtags'] = cont_txt($in['hashtags'], 300);
    if ($tem('cta')) $p['cta'] = cont_txt($in['cta'], 200);
    if ($tem('oferta')) { $o = (string)$in['oferta']; $p['oferta'] = preg_match('/^[a-z0-9-]{1,40}$/', $o) ? $o : ''; }
    if ($tem('cut_projeto')) { $o = (string)$in['cut_projeto']; $p['cut_projeto'] = cut_id_valido($o) ? $o : ''; }
    if ($tem('arquivos')) {
        $a = [];
        foreach ((array)$in['arquivos'] as $x) { $x = (string)$x; if (preg_match('/^[a-z0-9-]{3,40}$/', $x) && !in_array($x, $a, true) && count($a) < CONT_ARQUIVOS_MAX) $a[] = $x; }
        $p['arquivos'] = $a;
    }
    if ($tem('drive_link')) $p['drive_link'] = cont_url($in['drive_link']);
    if ($tem('notas')) $p['notas'] = cont_txt($in['notas'], 2000);
    if ($tem('etiquetas')) {
        $e = [];
        foreach ((array)$in['etiquetas'] as $x) { $x = cont_txt($x, 24); if ($x !== '' && !in_array($x, $e, true) && count($e) < 6) $e[] = $x; }
        $p['etiquetas'] = $e;
    }
    if ($tem('reciclar') && is_array($in['reciclar'])) {
        $r = $in['reciclar'];
        $m = (string)($r['marcar'] ?? $p['reciclar']['marcar']);
        $p['reciclar']['marcar'] = in_array($m, ['nao', 'sim'], true) ? $m : 'nao';
        if (array_key_exists('em', $r)) $p['reciclar']['em'] = cont_data($r['em']);
        if (array_key_exists('motivo', $r)) $p['reciclar']['motivo'] = cont_txt($r['motivo'], 200);
    }
    if ($tem('resultado') && is_array($in['resultado'])) {
        $r = $in['resultado'];
        foreach (['alcance', 'curtidas', 'comentarios', 'salvos', 'compart', 'cliques', 'leads'] as $k) if (array_key_exists($k, $r)) $p['resultado'][$k] = max(0, min(100000000, (int)$r[$k]));
        if (array_key_exists('link_publicado', $r)) $p['resultado']['link_publicado'] = cont_url($r['link_publicado']);
        if (array_key_exists('medido_em', $r)) $p['resultado']['medido_em'] = cont_data($r['medido_em']);
    }
    // A publicação automática é da 2ª etapa: o campo já existe, mas só "manual" vale hoje.
    $p['publicacao'] = ['modo' => 'manual'];
    return $p;
}

// ───────────────────────── o que falta para cada etapa ─────────────────────────

/** Confere o texto que vai ao ar com as regras da Intus (travessão, "não é X, é Y", promessa...). Só itens que valem para post. */
function cont_copy(array $p): array {
    $texto = trim(($p['gancho'] ?? '') . "\n" . ($p['legenda'] ?? '') . "\n" . ($p['cta'] ?? ''));
    if ($texto === '') return [];
    if (!function_exists('atd_analisar_copy')) {
        if (!is_file(__DIR__ . '/_atendimento.php')) return [];
        require_once __DIR__ . '/_atendimento.php';
        if (!function_exists('atd_analisar_copy')) return [];
    }
    $r = atd_analisar_copy($texto, []);
    $erro = ['travessao', 'nao_e_x_e_y', 'promessa'];
    $aviso = ['hifen', 'urgencia', 'cliche', 'saude'];
    $out = [];
    foreach ((array)($r['itens'] ?? []) as $i) {
        $rg = (string)($i['regra'] ?? '');
        if (in_array($rg, $erro, true)) $out[] = ['nivel' => 'erro', 'campo' => 'legenda', 'regra' => $rg, 'msg' => (string)$i['msg']];
        elseif (in_array($rg, $aviso, true)) $out[] = ['nivel' => 'aviso', 'campo' => 'legenda', 'regra' => $rg, 'msg' => (string)$i['msg']];
    }
    return $out;
}

function cont_tem_midia(array $p): bool {
    return ($p['cut_projeto'] ?? '') !== '' || count($p['arquivos'] ?? []) > 0 || ($p['drive_link'] ?? '') !== '';
}

/**
 * O que impede (nível "erro") ou merece atenção (nível "aviso") a peça de ir para a etapa $alvo.
 * É a única fonte desta regra: o quadro, a edição e a exportação usam esta função.
 */
function cont_pendencias(array $p, array $cfg, array $ofertas, string $alvo, ?string $hoje = null): array {
    $o = cont_ordem_status($alvo);
    $out = [];
    $add = function (string $nivel, string $campo, string $msg) use (&$out) { $out[] = ['nivel' => $nivel, 'campo' => $campo, 'msg' => $msg]; };
    if ($alvo === 'arquivado' || $o < 0) return [];
    if (trim((string)($p['titulo'] ?? '')) === '') $add('erro', 'titulo', 'Dê um título à peça.');
    if (($p['marca'] ?? '') === '') $add('erro', 'marca', 'Escolha o perfil (marca).');
    $formato = null; foreach ($cfg['formatos'] as $f) if ($f['id'] === ($p['formato'] ?? '')) $formato = $f;
    // Formato que se grava ou se fala (reel, live...) pede gancho ou roteiro. Imagem, story e texto não. Sem formato escolhido ainda, pede.
    $pedeRoteiro = !$formato || !empty($formato['roteiro']);
    if ($o >= 2 && $pedeRoteiro && trim((string)($p['roteiro'] ?? '')) === '' && trim((string)($p['gancho'] ?? '')) === '') $add('erro', 'roteiro', 'Escreva o gancho ou o roteiro antes de gravar.');
    if ($o >= 3 && !$formato) $add('erro', 'formato', 'Escolha o formato (reel, carrossel...).');
    if ($o >= 4 && $formato && !empty($formato['midia']) && !cont_tem_midia($p)) $add('erro', 'midia', 'Falta o vídeo ou a imagem: projeto do Intus Cut, arquivo da biblioteca ou link do Drive.');
    if ($o >= 5) {
        if ($formato && ($formato['id'] ?? '') !== 'story' && trim((string)($p['legenda'] ?? '')) === '') $add('erro', 'legenda', 'Escreva a legenda.');
        if (($p['tipo'] ?? '') === '') $add('erro', 'tipo', 'Escolha o tipo de post.');
        foreach (cont_copy($p) as $c) $out[] = $c;
        $temParticipante = false; foreach ($p['pessoas'] ?? [] as $x) if (($x['papel'] ?? '') === 'participante') $temParticipante = true;
        if ($temParticipante && ($p['autorizacao_imagem'] ?? '') === 'nao_precisa') $add('erro', 'autorizacao_imagem', 'Tem aluno ou aluna na peça: marque se a autorização de uso de imagem está pendente ou ok.');
    }
    if ($o >= 6) {
        if (($p['data'] ?? '') === '') $add('erro', 'data', 'Escolha o dia da publicação.');
        if (($p['hora'] ?? '') === '') $add('erro', 'hora', 'Escolha a hora da publicação.');
        if (($p['autorizacao_imagem'] ?? '') === 'pendente') $add('erro', 'autorizacao_imagem', 'Autorização de uso de imagem pendente.');
        if (!count($p['canais'] ?? [])) $add('erro', 'canais', 'Escolha onde a peça vai ser publicada.');
        if (($p['data'] ?? '') !== '' && $hoje !== null && $p['data'] < $hoje) $add('aviso', 'data', 'O dia escolhido já passou.');
    }
    if ($o >= 5) {
        $venda = false; foreach ($cfg['tipos'] as $t) if ($t['id'] === ($p['tipo'] ?? '') && !empty($t['venda'])) $venda = true;
        if ($venda && ($p['oferta'] ?? '') === '') $add('aviso', 'oferta', 'Post de venda sem oferta ligada: o link e o preço ficam por sua conta.');
        if (($p['oferta'] ?? '') !== '') {
            $achou = null; foreach ($ofertas as $x) if (($x['id'] ?? '') === $p['oferta']) $achou = $x;
            if (!$achou) $add('aviso', 'oferta', 'A oferta ligada não existe mais no catálogo.');
            elseif (empty($achou['ativo'])) $add('aviso', 'oferta', 'A oferta ligada está desativada.');
            elseif (($achou['marca'] ?? '') !== '' && ($p['marca'] ?? '') !== '' && $achou['marca'] !== $p['marca']) $add('aviso', 'oferta', 'A oferta é de outro perfil (anti-canibalização): confira se faz sentido neste.');
        }
    }
    if ($o >= 7 && ($p['resultado']['link_publicado'] ?? '') === '') $add('aviso', 'link_publicado', 'Cole o link do post publicado para acompanhar o resultado.');
    return $out;
}

function cont_erros(array $pend): array { return array_values(array_filter($pend, fn($x) => ($x['nivel'] ?? '') === 'erro')); }

// ───────────────────────── gravar ─────────────────────────

function cont_registrar(array &$p, string $acao, array $ctx, string $de = '', string $para = ''): void {
    $h = $p['historico'] ?? [];
    $h[] = ['em' => cont_agora(), 'por' => cont_txt($ctx['nmusuario'] ?? '', 60), 'acao' => $acao, 'de' => $de, 'para' => $para];
    $p['historico'] = array_slice($h, -CONT_HISTORICO_MAX);
}

/**
 * Cria ou atualiza uma peça. Se a etapa mudou (ou a peça é nova além de "ideia"), as exigências da etapa precisam estar em dia,
 * a não ser que um administrador force. Devolve ['ok', 'peca', 'pendencias'] ou ['ok' => false, 'erro', 'pendencias', 'codigo'].
 */
function cont_salvar_peca(PDO $pdo, array $in, array $ctx, bool $forcar = false, ?string $hoje = null): array {
    $cfg = cont_cfg($pdo); $ofertas = cont_ofertas($pdo);
    $pecas = cont_pecas($pdo);
    $id = (string)($in['id'] ?? '');
    $antes = ($id !== '' && isset($pecas[$id])) ? $pecas[$id] : null;
    if ($id !== '' && !$antes) return ['ok' => false, 'codigo' => 404, 'erro' => 'peça não encontrada'];
    if (!$antes && count($pecas) >= CONT_PECAS_MAX) return ['ok' => false, 'codigo' => 400, 'erro' => 'limite de ' . CONT_PECAS_MAX . ' peças. Arquive ou exclua as antigas.'];

    $p = cont_limpar_peca($in, $cfg, $antes);
    if ($p['titulo'] === '') return ['ok' => false, 'codigo' => 400, 'erro' => 'dê um título à peça', 'pendencias' => [['nivel' => 'erro', 'campo' => 'titulo', 'msg' => 'Dê um título à peça.']]];
    $statusAntes = $antes['status'] ?? null;
    $mudou = $statusAntes !== $p['status'];
    $pend = cont_pendencias($p, $cfg, $ofertas, $p['status'], cont_hoje($hoje));
    $bloq = cont_erros($pend);
    if (($mudou || !$antes) && cont_ordem_status($p['status']) > 0 && count($bloq) && !$forcar) {
        return ['ok' => false, 'codigo' => 422, 'erro' => 'a peça ainda não pode ir para ' . cont_nome_status($p['status']), 'pendencias' => $pend];
    }
    // Peça que já está numa etapa avançada e perdeu uma exigência ao ser editada: salva, mas avisa (não trava o ajuste do texto).
    $agora = cont_agora();
    $p['atualizado_em'] = $agora;
    if ($mudou) $p['status_em'] = $agora;
    if ($p['status'] === 'publicado' && empty($p['publicado_em'])) {
        // Peça lançada depois de já estar no ar: vale o dia que a pessoa escolheu, desde que não seja futuro.
        $p['publicado_em'] = ($p['data'] !== '' && $p['data'] <= cont_hoje($hoje)) ? $p['data'] : cont_hoje($hoje);
    }
    if ($p['status'] !== 'publicado' && $mudou) $p['publicado_em'] = '';
    if (!$antes) {
        $id = 'c' . bin2hex(random_bytes(5));
        $p['criado_em'] = $agora; $p['criado_por'] = (int)($ctx['idusuario'] ?? 0); $p['criado_por_nome'] = cont_txt($ctx['nmusuario'] ?? '', 60);
        if (($p['responsavel'] ?? '') === '') $p['responsavel'] = cont_txt($ctx['nmusuario'] ?? '', 60);
        $p['status_em'] = $agora;
        cont_registrar($p, 'criou', $ctx, '', $p['status']);
    } elseif ($mudou) {
        cont_registrar($p, $forcar && count($bloq) ? 'moveu (forçado)' : 'moveu', $ctx, $statusAntes ?? '', $p['status']);
    }
    $p['id'] = $id;
    $pecas[$id] = $p;
    cont_gravar_pecas($pdo, $pecas);
    return ['ok' => true, 'peca' => $p, 'pendencias' => $pend];
}

function cont_gravar_pecas(PDO $pdo, array $pecas): void {
    foreach ($pecas as $id => $p) unset($pecas[$id]['id']);
    cut_gravar($pdo, 'cont_pecas', $pecas);
}

function cont_excluir_peca(PDO $pdo, string $id): bool {
    $pecas = cont_pecas($pdo);
    if (!isset($pecas[$id])) return false;
    unset($pecas[$id]);
    cont_gravar_pecas($pdo, $pecas);
    return true;
}

function cont_duplicar_peca(PDO $pdo, string $id, array $ctx): array {
    $pecas = cont_pecas($pdo);
    if (!isset($pecas[$id])) return ['ok' => false, 'codigo' => 404, 'erro' => 'peça não encontrada'];
    $o = $pecas[$id];
    $novo = ['titulo' => cont_txt($o['titulo'] . ' (cópia)', 140), 'status' => 'ideia'];
    foreach (['marca', 'canais', 'formato', 'tipo', 'funil', 'pessoas', 'gancho', 'roteiro', 'legenda', 'hashtags', 'cta', 'oferta', 'etiquetas'] as $k) $novo[$k] = $o[$k] ?? null;
    return cont_salvar_peca($pdo, array_filter($novo, fn($v) => $v !== null), $ctx);
}

/** Reciclar: nasce uma peça nova em "ideia" com o conteúdo da original, ligada a ela. A original anota quando foi reciclada. */
function cont_reciclar_peca(PDO $pdo, string $id, array $ctx, ?string $hoje = null): array {
    $pecas = cont_pecas($pdo);
    if (!isset($pecas[$id])) return ['ok' => false, 'codigo' => 404, 'erro' => 'peça não encontrada'];
    $o = $pecas[$id];
    if (($o['status'] ?? '') !== 'publicado') return ['ok' => false, 'codigo' => 422, 'erro' => 'só dá para reciclar uma peça já publicada'];
    $novo = ['titulo' => cont_txt(preg_replace('/ \(reciclagem\)$/u', '', (string)$o['titulo']) . ' (reciclagem)', 140), 'status' => 'ideia'];
    foreach (['marca', 'canais', 'formato', 'tipo', 'funil', 'pessoas', 'gancho', 'roteiro', 'legenda', 'hashtags', 'cta', 'oferta', 'cut_projeto', 'arquivos', 'drive_link', 'etiquetas', 'autorizacao_imagem'] as $k) $novo[$k] = $o[$k] ?? null;
    $r = cont_salvar_peca($pdo, array_filter($novo, fn($v) => $v !== null), $ctx, false, $hoje);
    if (!$r['ok']) return $r;
    $pecas = cont_pecas($pdo);
    $nid = $r['peca']['id'];
    $pecas[$nid]['reciclar']['origem'] = $id;
    cont_registrar($pecas[$nid], 'reciclou de "' . cont_txt($o['titulo'], 60) . '"', $ctx);
    $pecas[$id]['reciclar']['reciclada_em'] = cont_hoje($hoje);
    $pecas[$id]['reciclar']['marcar'] = 'nao';
    cont_registrar($pecas[$id], 'foi reciclada', $ctx);
    cont_gravar_pecas($pdo, $pecas);
    return ['ok' => true, 'peca' => $pecas[$nid], 'origem' => $id];
}

function cont_publicar_peca(PDO $pdo, string $id, array $d, array $ctx, ?string $hoje = null): array {
    $pecas = cont_pecas($pdo);
    if (!isset($pecas[$id])) return ['ok' => false, 'codigo' => 404, 'erro' => 'peça não encontrada'];
    $in = ['id' => $id, 'status' => 'publicado'];
    if (isset($d['link_publicado'])) $in['resultado'] = ['link_publicado' => $d['link_publicado']];
    return cont_salvar_peca($pdo, $in, $ctx, false, $hoje);
}

// ───────────────────────── eventos ─────────────────────────

function cont_salvar_evento(PDO $pdo, array $in, array $ctx): array {
    $cfg = cont_cfg($pdo); $eventos = cont_eventos($pdo);
    $id = (string)($in['id'] ?? '');
    $antes = ($id !== '' && isset($eventos[$id])) ? $eventos[$id] : null;
    if ($id !== '' && !$antes) return ['ok' => false, 'codigo' => 404, 'erro' => 'evento não encontrado'];
    if (!$antes && count($eventos) >= CONT_EVENTOS_MAX) return ['ok' => false, 'codigo' => 400, 'erro' => 'limite de eventos'];
    $titulo = cont_txt($in['titulo'] ?? ($antes['titulo'] ?? ''), 120);
    if ($titulo === '') return ['ok' => false, 'codigo' => 400, 'erro' => 'dê um nome ao evento'];
    $data = cont_data($in['data'] ?? ($antes['data'] ?? ''));
    if ($data === '') return ['ok' => false, 'codigo' => 400, 'erro' => 'escolha o dia do evento'];
    $tipo = (string)($in['tipo'] ?? ($antes['tipo'] ?? 'outro'));
    $marca = (string)($in['marca'] ?? ($antes['marca'] ?? ''));
    $e = ['titulo' => $titulo, 'tipo' => in_array($tipo, array_column(CONT_TIPOS_EVENTO, 'id'), true) ? $tipo : 'outro',
          'marca' => ($marca === '' || in_array($marca, cont_ids($cfg['marcas']), true)) ? $marca : '',
          'data' => $data, 'data_fim' => cont_data($in['data_fim'] ?? ($antes['data_fim'] ?? '')), 'hora' => cont_hora($in['hora'] ?? ($antes['hora'] ?? '')),
          'local' => cont_txt($in['local'] ?? ($antes['local'] ?? ''), 120), 'pessoas' => cont_txt($in['pessoas'] ?? ($antes['pessoas'] ?? ''), 160),
          'notas' => cont_txt($in['notas'] ?? ($antes['notas'] ?? ''), 1000), 'atualizado_em' => cont_agora()];
    if ($e['data_fim'] !== '' && $e['data_fim'] < $e['data']) $e['data_fim'] = '';
    if (!$antes) { $id = 'e' . bin2hex(random_bytes(5)); $e['criado_por_nome'] = cont_txt($ctx['nmusuario'] ?? '', 60); }
    else $e['criado_por_nome'] = $antes['criado_por_nome'] ?? '';
    $eventos[$id] = $e;
    foreach ($eventos as $k => $x) unset($eventos[$k]['id']);
    cut_gravar($pdo, 'cont_eventos', $eventos);
    return ['ok' => true, 'evento' => $e + ['id' => $id]];
}

function cont_excluir_evento(PDO $pdo, string $id): bool {
    $eventos = cont_eventos($pdo);
    if (!isset($eventos[$id])) return false;
    unset($eventos[$id]);
    foreach ($eventos as $k => $x) unset($eventos[$k]['id']);
    cut_gravar($pdo, 'cont_eventos', $eventos);
    return true;
}

// ───────────────────────── leitura enxuta, painel, reciclagem, exportação ─────────────────────────

/** A lista não leva texto longo: roteiro, legenda, notas e histórico só vêm ao abrir a peça. */
function cont_peca_resumo(array $p, array $cfg, array $ofertas, string $hoje): array {
    $pend = cont_pendencias($p, $cfg, $ofertas, (string)$p['status'], $hoje);
    $r = $p;
    foreach (['roteiro', 'legenda', 'notas', 'historico', 'hashtags'] as $k) unset($r[$k]);
    $r['tem_legenda'] = trim((string)($p['legenda'] ?? '')) !== '';
    $r['tem_roteiro'] = trim((string)($p['roteiro'] ?? '')) !== '';
    $r['tem_midia'] = cont_tem_midia($p);
    $r['erros'] = count(cont_erros($pend));
    $r['avisos'] = count($pend) - $r['erros'];
    $r['score'] = cont_score($p);
    return $r;
}

/** Engajamento de uma peça já medida: salvar e compartilhar pesam mais (valem mais que curtir). 0 se não há alcance. */
function cont_score(array $p): float {
    $r = $p['resultado'] ?? [];
    $alc = (int)($r['alcance'] ?? 0);
    if ($alc <= 0) return 0.0;
    $pts = ((int)($r['curtidas'] ?? 0)) + 2 * ((int)($r['comentarios'] ?? 0)) + 3 * ((int)($r['salvos'] ?? 0)) + 3 * ((int)($r['compart'] ?? 0));
    return round(100 * $pts / $alc, 2);
}

function cont_semana(string $hoje, int $desloc = 0): array {
    $d = new DateTime($hoje);
    $d->modify('-' . (int)$d->format('w') . ' days');           // domingo
    if ($desloc) $d->modify(($desloc * 7) . ' days');
    $ini = $d->format('Y-m-d'); $d->modify('+6 days');
    return [$ini, $d->format('Y-m-d')];
}

function cont_reciclagem(array $pecas, array $cfg, string $hoje): array {
    $dias = (int)$cfg['reciclar_dias'];
    $limite = (new DateTime($hoje))->modify("-$dias days")->format('Y-m-d');
    $marcadas = []; $sugeridas = [];
    foreach ($pecas as $p) {
        if (($p['status'] ?? '') !== 'publicado') continue;
        $pub = (string)($p['publicado_em'] ?? ($p['data'] ?? ''));
        if ($pub === '') continue;
        $ult = (string)($p['reciclar']['reciclada_em'] ?? '');
        $semBase = $ult !== '' ? $ult : $pub;       // depois de reciclar, a contagem recomeça
        $item = ['id' => $p['id'], 'titulo' => $p['titulo'], 'marca' => $p['marca'], 'formato' => $p['formato'], 'tipo' => $p['tipo'], 'publicado_em' => $pub,
                 'reciclada_em' => $ult, 'dias' => (int)((strtotime($hoje) - strtotime($pub)) / 86400), 'score' => cont_score($p), 'motivo' => (string)($p['reciclar']['motivo'] ?? '')];
        if (($p['reciclar']['marcar'] ?? 'nao') === 'sim') {
            $em = (string)($p['reciclar']['em'] ?? '');
            $item['quando'] = $em !== '' ? $em : (new DateTime($semBase))->modify("+$dias days")->format('Y-m-d');
            $item['pronta'] = $item['quando'] <= $hoje;
            $marcadas[] = $item;
        } elseif ($item['score'] > 0 && $semBase <= $limite) {
            $sugeridas[] = $item;
        }
    }
    usort($marcadas, fn($a, $b) => strcmp($a['quando'], $b['quando']));
    usort($sugeridas, fn($a, $b) => $b['score'] <=> $a['score']);
    return ['marcadas' => $marcadas, 'sugeridas' => array_slice($sugeridas, 0, 12), 'dias' => $dias];
}

function cont_painel(array $pecas, array $eventos, array $cfg, array $ofertas, string $hoje): array {
    $porStatus = []; foreach (CONT_STATUS as $s) $porStatus[$s['id']] = 0;
    $porMarca = []; foreach ($cfg['marcas'] as $m) $porMarca[$m['id']] = ['total' => 0, 'ativas' => 0, 'publicadas_30d' => 0];
    $atrasadas = []; $semData = []; $hojeLista = []; $paradas = []; $bloqueadas = []; $semMidia = [];
    $parado = (int)$cfg['parado_dias']; $limParado = (new DateTime($hoje))->modify("-$parado days")->format('Y-m-d');
    $ini30 = (new DateTime($hoje))->modify('-30 days')->format('Y-m-d');
    $mix = []; $totalMix = 0; $venda = 0;
    $tiposVenda = []; foreach ($cfg['tipos'] as $t) if (!empty($t['venda'])) $tiposVenda[$t['id']] = true;
    $semanaAtual = cont_semana($hoje, 0); $semanaProx = cont_semana($hoje, 1);
    $cad = [];
    foreach ($cfg['marcas'] as $m) $cad[$m['id']] = ['meta' => (int)$m['meta_semana'], 'atual' => ['planejadas' => 0, 'publicadas' => 0], 'proxima' => ['planejadas' => 0]];

    foreach ($pecas as $p) {
        $st = (string)($p['status'] ?? 'ideia'); $mk = (string)($p['marca'] ?? '');
        if (isset($porStatus[$st])) $porStatus[$st]++;
        if (isset($porMarca[$mk])) {
            $porMarca[$mk]['total']++;
            if (!in_array($st, ['publicado', 'arquivado'], true)) $porMarca[$mk]['ativas']++;
            if ($st === 'publicado' && (string)($p['publicado_em'] ?? '') >= $ini30) $porMarca[$mk]['publicadas_30d']++;
        }
        $data = (string)($p['data'] ?? '');
        $emAberto = !in_array($st, ['publicado', 'arquivado'], true);
        $ref = ['id' => $p['id'], 'titulo' => $p['titulo'], 'marca' => $mk, 'status' => $st, 'data' => $data, 'hora' => (string)($p['hora'] ?? '')];
        if ($emAberto && $data !== '' && $data < $hoje) $atrasadas[] = $ref;
        if ($emAberto && $data === $hoje) $hojeLista[] = $ref;
        if (in_array($st, ['aprovado'], true) && $data === '') $semData[] = $ref;
        if ($emAberto && $st !== 'ideia' && substr((string)($p['status_em'] ?? $p['atualizado_em'] ?? ''), 0, 10) !== '' && substr((string)($p['status_em'] ?? $p['atualizado_em']), 0, 10) <= $limParado) $paradas[] = $ref + ['desde' => substr((string)($p['status_em'] ?? $p['atualizado_em']), 0, 10)];
        if ($emAberto && ($p['autorizacao_imagem'] ?? '') === 'pendente') $bloqueadas[] = $ref;
        if (in_array($st, ['revisao', 'aprovado', 'agendado'], true)) {
            foreach ($cfg['formatos'] as $f) if ($f['id'] === ($p['formato'] ?? '') && !empty($f['midia']) && !cont_tem_midia($p)) $semMidia[] = $ref;
        }
        if (in_array($st, ['agendado', 'publicado'], true) && isset($cad[$mk]) && $data !== '') {
            if ($data >= $semanaAtual[0] && $data <= $semanaAtual[1]) { $cad[$mk]['atual']['planejadas']++; if ($st === 'publicado') $cad[$mk]['atual']['publicadas']++; }
            if ($data >= $semanaProx[0] && $data <= $semanaProx[1]) $cad[$mk]['proxima']['planejadas']++;
        }
        if (in_array($st, ['agendado', 'publicado'], true) && $data !== '' && $data >= $ini30 && $data <= $hoje) {
            $t = (string)($p['tipo'] ?? ''); $mix[$t] = ($mix[$t] ?? 0) + 1; $totalMix++;
            if (isset($tiposVenda[$t])) $venda++;
        }
    }
    $vendaPct = $totalMix > 0 ? (int)round(100 * $venda / $totalMix) : 0;
    $alertas = [];
    if ($totalMix >= 5 && $vendaPct > (int)$cfg['venda_max_pct']) $alertas[] = "Posts de venda são $vendaPct% da grade dos últimos 30 dias (o limite que você definiu é {$cfg['venda_max_pct']}%). Equilibre com conteúdo que ensina e mostra resultado.";
    foreach ($cad as $mk => $c) {
        if ($c['meta'] > 0 && $c['proxima']['planejadas'] < $c['meta']) {
            $nome = ''; foreach ($cfg['marcas'] as $m) if ($m['id'] === $mk) $nome = $m['nome'];
            $alertas[] = "$nome tem {$c['proxima']['planejadas']} de {$c['meta']} posts planejados para a semana que vem.";
        }
    }
    if (count($atrasadas)) $alertas[] = count($atrasadas) . ' peça(s) com o dia de publicação já vencido.';
    if (count($bloqueadas)) $alertas[] = count($bloqueadas) . ' peça(s) esperando autorização de uso de imagem.';

    // Próximos 14 dias: peças e eventos por dia, para o resumo do topo.
    $agenda = [];
    $fim14 = (new DateTime($hoje))->modify('+14 days')->format('Y-m-d');
    foreach ($pecas as $p) {
        $d = (string)($p['data'] ?? '');
        if ($d >= $hoje && $d <= $fim14 && !in_array($p['status'] ?? '', ['arquivado'], true)) $agenda[] = ['tipo' => 'peca', 'id' => $p['id'], 'titulo' => $p['titulo'], 'marca' => $p['marca'], 'status' => $p['status'], 'data' => $d, 'hora' => (string)($p['hora'] ?? '')];
    }
    foreach ($eventos as $e) {
        $d = (string)($e['data'] ?? ''); $f = (string)($e['data_fim'] ?? '') ?: $d;
        if ($f >= $hoje && $d <= $fim14) $agenda[] = ['tipo' => 'evento', 'id' => $e['id'], 'titulo' => $e['titulo'], 'marca' => $e['marca'] ?? '', 'status' => $e['tipo'], 'data' => max($d, $hoje), 'hora' => (string)($e['hora'] ?? '')];
    }
    usort($agenda, fn($a, $b) => strcmp($a['data'] . $a['hora'], $b['data'] . $b['hora']));

    arsort($mix);
    return ['hoje' => $hoje, 'por_status' => $porStatus, 'por_marca' => $porMarca, 'atrasadas' => $atrasadas, 'hoje_lista' => $hojeLista, 'sem_data' => $semData,
            'paradas' => $paradas, 'bloqueadas' => $bloqueadas, 'sem_midia' => $semMidia, 'cadencia' => $cad, 'semana' => ['atual' => $semanaAtual, 'proxima' => $semanaProx],
            'mix_30d' => $mix, 'venda_pct' => $vendaPct, 'alertas' => $alertas, 'agenda' => array_slice($agenda, 0, 40), 'total' => count($pecas)];
}

/** Números do negócio que o conteúdo alimenta. Cada consulta falha sozinha (tabela ainda não criada, banco do CDD à parte). */
function cont_negocio(PDO $pdo, string $hoje, int $dias = 30): array {
    $de = (new DateTime($hoje))->modify("-$dias days")->format('Y-m-d') . ' 00:00:00';
    $q = function (string $sql, array $args = []) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetch(PDO::FETCH_NUM) ?: null; } catch (Throwable $e) { return null; } };
    $r = [];
    $a = $q('SELECT COUNT(*) FROM intus_lead WHERE criado_em >= ?', [$de]); $r['leads_intus'] = $a ? (int)$a[0] : null;
    $a = $q('SELECT COUNT(*) FROM cdd_lead WHERE criado_em >= ?', [$de]); $r['leads_cdd'] = $a ? (int)$a[0] : null;
    $a = $q("SELECT COUNT(*), COALESCE(SUM(valor), 0) FROM cdd_pagamento WHERE status = 'pago' AND pago_em >= ?", [$de]);
    $r['vendas_cdd'] = $a ? (int)$a[0] : null; $r['receita_cdd'] = $a ? round((float)$a[1], 2) : null;
    $r['dias'] = $dias;
    return $r;
}

/** Fila "pronto para postar": o que está agendado, com o texto final montado. É o que a automação da 2ª etapa vai ler. */
function cont_exportar(PDO $pdo, ?string $hoje = null): array {
    $hoje = cont_hoje($hoje);
    $cfg = cont_cfg($pdo); $ofertas = cont_ofertas($pdo); $pecas = cont_pecas($pdo);
    $marcas = []; foreach ($cfg['marcas'] as $m) $marcas[$m['id']] = $m;
    $of = []; foreach ($ofertas as $o) $of[$o['id']] = $o;
    $bib = []; foreach (cut_bib($pdo) as $b) $bib[$b['id']] = $b;
    $fila = [];
    foreach ($pecas as $p) {
        if (!in_array($p['status'] ?? '', ['agendado'], true)) continue;
        $erros = cont_erros(cont_pendencias($p, $cfg, $ofertas, 'agendado', $hoje));
        $texto = trim((string)$p['legenda']);
        if (trim((string)$p['hashtags']) !== '') $texto .= "\n\n" . trim((string)$p['hashtags']);
        $arq = [];
        foreach ($p['arquivos'] as $a) if (isset($bib[$a])) $arq[] = ['id' => $a, 'nome' => $bib[$a]['nome'] ?? '', 'tipo' => $bib[$a]['tipo'] ?? '', 'url' => $bib[$a]['url'] ?? ''];
        $fila[] = ['id' => $p['id'], 'titulo' => $p['titulo'], 'perfil' => $marcas[$p['marca']]['handle'] ?? $p['marca'], 'marca' => $p['marca'], 'canais' => $p['canais'], 'formato' => $p['formato'],
                   'data' => $p['data'], 'hora' => $p['hora'], 'texto' => $texto, 'oferta' => isset($of[$p['oferta']]) ? ['nome' => $of[$p['oferta']]['nome'], 'link' => $of[$p['oferta']]['link'], 'cupom' => $of[$p['oferta']]['cupom']] : null,
                   'cut_projeto' => $p['cut_projeto'], 'arquivos' => $arq, 'drive_link' => $p['drive_link'], 'pronta' => !count($erros), 'pendencias' => array_column($erros, 'msg'),
                   'publicacao' => ['modo' => 'manual']];
    }
    usort($fila, fn($a, $b) => strcmp($a['data'] . $a['hora'], $b['data'] . $b['hora']));
    return ['gerado_em' => cont_agora(), 'automacao' => 'segunda etapa: hoje a publicação é manual', 'fila' => $fila];
}

/** O que a tela precisa do Intus Cut: projetos e vídeos já exportados, sem peso. */
function cont_do_cut(PDO $pdo): array {
    $proj = array_map(fn($x) => ['id' => $x['id'], 'nome' => $x['nome'] ?? $x['id'], 'formato' => $x['formato'] ?? '', 'atualizado_em' => $x['atualizado_em'] ?? '', 'por_nome' => $x['por_nome'] ?? '', 'ia' => $x['ia'] ?? ''], cut_lista($pdo));
    $bib = array_map(fn($b) => ['id' => $b['id'] ?? '', 'tipo' => $b['tipo'] ?? '', 'nome' => $b['nome'] ?? '', 'url' => $b['url'] ?? '', 'dur' => $b['dur'] ?? 0, 'exportado' => !empty($b['exportado']), 'rascunho' => !empty($b['rascunho']), 'criado_em' => $b['criado_em'] ?? ''], cut_bib($pdo));
    return ['projetos' => $proj, 'biblioteca' => $bib];
}
