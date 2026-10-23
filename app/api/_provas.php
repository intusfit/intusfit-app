<?php
/**
 * Banco de provas sociais (resultados de quem treinou/seguiu a consultoria), só para a equipe (administrador e professores).
 *
 * É o lugar onde se guarda, com cuidado, a história de cada resultado: quem foi, quanto tempo levou, peso antes e depois, a transformação,
 * o depoimento, as fotos e os vídeos, e SE a pessoa autorizou o uso. De lá saem:
 *   - o robô de atendimento (variável {{8}} das mensagens de fechamento): só prova com autorização "divulgar" e uso "robô" ligado;
 *   - marketing, site e a inteligência do ecossistema (avaliações, copy): cada uso é uma chave separada e só vale com a autorização certa;
 *   - o Laboratório de copy da Central (provas liberadas para a IA).
 *
 * Regras de privacidade (valem no servidor, não só na tela):
 *   - autorização "pendente": nada sai da equipe, nem para a IA;
 *   - "interna": só análise interna e IA; nunca robô, marketing nem site;
 *   - "divulgar": a pessoa autorizou; aí vale a chave de cada uso.
 *   - O robô e a IA usam só o PRIMEIRO NOME; sobrenome e telefone nunca entram no texto.
 *
 * SQL portável (SQLite nos testes, MariaDB em produção): sem upsert próprio do MySQL e sem função de hora do banco; a hora é gravada pelo PHP.
 * Tabelas novas e só aditivas, criadas na primeira chamada: intus_prova e intus_prova_midia. O vídeo enviado vive em intus_midia
 * (dono_tipo = 'prova') e toca pelo mesmo endereço público dos vídeos do app (midia.php?action=video&t=TOKEN).
 */

const PROVAS_OBJETIVOS = ['emagrecimento' => 'Emagrecimento', 'hipertrofia' => 'Ganho de massa', 'definicao' => 'Definição', 'saude' => 'Saúde e disposição', 'performance' => 'Performance', 'reabilitacao' => 'Reabilitação', 'outro' => 'Outro'];
const PROVAS_AUTORIZACAO = ['pendente' => 'Sem autorização ainda', 'interna' => 'Só uso interno (equipe e IA)', 'divulgar' => 'Autorizou divulgar'];
const PROVAS_USOS = ['robo' => 'Robô de atendimento', 'marketing' => 'Marketing', 'site' => 'Site', 'ia' => 'Inteligência interna (IA)'];
const PROVAS_MOMENTOS = ['antes', 'depois', 'durante', 'depoimento'];
const PROVAS_MAX_FOTOS = 12;
const PROVAS_MAX_VIDEOS = 4;
const PROVAS_BASE_URL = 'https://intusfit.com.br';

function provas_agora(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');
}

function provas_garantir_tabelas(PDO $pdo): void {
    static $feito = []; $k = spl_object_id($pdo);
    if (!empty($feito[$k])) return;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $fim = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_prova (
        idprova $pk,
        titulo VARCHAR(160) NOT NULL DEFAULT '',
        pessoa_nome VARCHAR(120) NOT NULL DEFAULT '',
        idatleta INT NULL,
        sexo VARCHAR(1) NULL,
        idade INT NULL,
        objetivo VARCHAR(20) NOT NULL DEFAULT 'outro',
        programa VARCHAR(10) NOT NULL DEFAULT 'intus',
        plano_usado VARCHAR(80) NOT NULL DEFAULT '',
        professor VARCHAR(120) NOT NULL DEFAULT '',
        tempo_meses DECIMAL(5,1) NULL,
        tempo_txt VARCHAR(60) NOT NULL DEFAULT '',
        peso_antes DECIMAL(5,1) NULL,
        peso_depois DECIMAL(5,1) NULL,
        gordura_antes DECIMAL(4,1) NULL,
        gordura_depois DECIMAL(4,1) NULL,
        medidas_txt VARCHAR(255) NOT NULL DEFAULT '',
        resultado_txt TEXT NULL,
        depoimento_txt TEXT NULL,
        contexto_txt TEXT NULL,
        tags VARCHAR(255) NOT NULL DEFAULT '',
        autorizacao VARCHAR(10) NOT NULL DEFAULT 'pendente',
        autorizacao_obs VARCHAR(255) NOT NULL DEFAULT '',
        uso_robo TINYINT NOT NULL DEFAULT 0,
        uso_marketing TINYINT NOT NULL DEFAULT 0,
        uso_site TINYINT NOT NULL DEFAULT 0,
        uso_ia TINYINT NOT NULL DEFAULT 0,
        destaque TINYINT NOT NULL DEFAULT 0,
        ativo TINYINT NOT NULL DEFAULT 1,
        criado_por INT NOT NULL DEFAULT 0,
        criado_por_nome VARCHAR(120) NOT NULL DEFAULT '',
        criado_em DATETIME NULL,
        atualizado_em DATETIME NULL
    )$fim");
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_prova_midia (
        idpm $pk,
        idprova INT NOT NULL,
        tipo VARCHAR(10) NOT NULL DEFAULT 'foto',
        momento VARCHAR(12) NOT NULL DEFAULT 'depois',
        url VARCHAR(500) NOT NULL DEFAULT '',
        poster VARCHAR(500) NULL,
        idmidia INT NULL,
        ordem INT NOT NULL DEFAULT 0,
        legenda VARCHAR(160) NOT NULL DEFAULT '',
        criado_em DATETIME NULL
    )$fim");
    if (!$sqlite) {
        try { $pdo->exec('CREATE INDEX idx_prova_midia ON intus_prova_midia (idprova, ordem)'); } catch (Throwable $e) {}
        try { $pdo->exec('CREATE INDEX idx_prova_busca ON intus_prova (ativo, autorizacao, uso_robo)'); } catch (Throwable $e) {}
    }
    $feito[$k] = true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Validação
// ─────────────────────────────────────────────────────────────────────────────

function provas_txt($v, int $max): string {
    $s = is_string($v) ? $v : (is_scalar($v) ? (string)$v : '');
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return mb_substr(trim($s), 0, $max);
}
function provas_num($v, float $min, float $max, string $rotulo): ?float {
    if ($v === null || $v === '') return null;
    $n = (float)str_replace(',', '.', (string)$v);
    if ($n < $min || $n > $max) throw new InvalidArgumentException($rotulo . ' fora do esperado (entre ' . $min . ' e ' . $max . ').');
    return round($n, 1);
}
function provas_bool($v): int { return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on') ? 1 : 0; }

/** Valida e normaliza uma prova vinda da tela. Lança InvalidArgumentException com texto para a pessoa. */
function provas_limpar(array $d): array {
    $nome = provas_txt($d['pessoa_nome'] ?? '', 120);
    if ($nome === '') throw new InvalidArgumentException('Diga quem é a pessoa (pode ser só o primeiro nome).');
    $objetivo = (string)($d['objetivo'] ?? 'outro');
    if (!isset(PROVAS_OBJETIVOS[$objetivo])) $objetivo = 'outro';
    $autorizacao = (string)($d['autorizacao'] ?? 'pendente');
    if (!isset(PROVAS_AUTORIZACAO[$autorizacao])) throw new InvalidArgumentException('Escolha a autorização de uso.');
    $sexo = strtoupper(provas_txt($d['sexo'] ?? '', 1));
    if ($sexo !== 'F' && $sexo !== 'M') $sexo = '';
    $idade = provas_num($d['idade'] ?? null, 10, 100, 'Idade');
    $tags = $d['tags'] ?? '';
    if (is_string($tags)) $tags = preg_split('/[,;\n]+/', $tags);
    $tl = [];
    foreach ((array)$tags as $t) {
        $t = trim(mb_strtolower(preg_replace('/\s+/u', ' ', (string)$t), 'UTF-8'));
        $t = mb_substr($t, 0, 24);
        if ($t !== '' && !in_array($t, $tl, true)) $tl[] = $t;
        if (count($tl) >= 10) break;
    }
    $programa = ($d['programa'] ?? 'intus') === 'cdd' ? 'cdd' : 'intus';
    $antes = provas_num($d['peso_antes'] ?? null, 20, 400, 'Peso antes');
    $depois = provas_num($d['peso_depois'] ?? null, 20, 400, 'Peso depois');
    $gA = provas_num($d['gordura_antes'] ?? null, 2, 70, 'Gordura antes');
    $gD = provas_num($d['gordura_depois'] ?? null, 2, 70, 'Gordura depois');
    $meses = provas_num($d['tempo_meses'] ?? null, 0, 240, 'Tempo em meses');
    $divulga = $autorizacao === 'divulgar';
    $interno = $autorizacao === 'divulgar' || $autorizacao === 'interna';
    return [
        'titulo' => provas_txt($d['titulo'] ?? '', 160),
        'pessoa_nome' => $nome, 'idatleta' => !empty($d['idatleta']) ? max(0, (int)$d['idatleta']) : null,
        'sexo' => $sexo !== '' ? $sexo : null, 'idade' => $idade !== null ? (int)$idade : null,
        'objetivo' => $objetivo, 'programa' => $programa,
        'plano_usado' => provas_txt($d['plano_usado'] ?? '', 80), 'professor' => provas_txt($d['professor'] ?? '', 120),
        'tempo_meses' => $meses, 'tempo_txt' => provas_txt($d['tempo_txt'] ?? '', 60),
        'peso_antes' => $antes, 'peso_depois' => $depois, 'gordura_antes' => $gA, 'gordura_depois' => $gD,
        'medidas_txt' => provas_txt($d['medidas_txt'] ?? '', 255),
        'resultado_txt' => provas_txt($d['resultado_txt'] ?? '', 1000), 'depoimento_txt' => provas_txt($d['depoimento_txt'] ?? '', 1000), 'contexto_txt' => provas_txt($d['contexto_txt'] ?? '', 1000),
        'tags' => implode(', ', $tl),
        'autorizacao' => $autorizacao, 'autorizacao_obs' => provas_txt($d['autorizacao_obs'] ?? '', 255),
        // o uso para fora só liga com autorização para divulgar; a IA interna precisa de pelo menos a autorização interna
        'uso_robo' => $divulga ? provas_bool($d['uso_robo'] ?? 0) : 0,
        'uso_marketing' => $divulga ? provas_bool($d['uso_marketing'] ?? 0) : 0,
        'uso_site' => $divulga ? provas_bool($d['uso_site'] ?? 0) : 0,
        'uso_ia' => $interno ? provas_bool($d['uso_ia'] ?? 0) : 0,
        'destaque' => provas_bool($d['destaque'] ?? 0), 'ativo' => array_key_exists('ativo', $d) ? provas_bool($d['ativo']) : 1,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Gravar e ler
// ─────────────────────────────────────────────────────────────────────────────

function provas_pode_mexer(array $linha, int $idusuario, bool $admin): bool {
    return $admin || ((int)$linha['criado_por'] > 0 && (int)$linha['criado_por'] === $idusuario);
}

/** Cria (sem $id) ou atualiza. Professor só mexe no que ele mesmo criou; administrador mexe em tudo. */
function provas_salvar(PDO $pdo, array $d, int $idusuario, string $nomeUsuario, bool $admin, ?int $id = null): int {
    provas_garantir_tabelas($pdo);
    $c = provas_limpar($d);
    if ($c['titulo'] === '') $c['titulo'] = mb_substr(trim(explode(' ', $c['pessoa_nome'])[0] . ($c['tempo_txt'] !== '' ? ': ' . $c['tempo_txt'] : '')), 0, 160);
    $agora = provas_agora();
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM intus_prova WHERE idprova = ?'); $st->execute([$id]);
        $atual = $st->fetch(PDO::FETCH_ASSOC);
        if (!$atual) throw new RuntimeException('Prova não encontrada.');
        if (!provas_pode_mexer($atual, $idusuario, $admin)) throw new RuntimeException('Só quem cadastrou a prova (ou o administrador) pode alterar.');
        $sets = []; $vals = [];
        foreach ($c as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
        $sets[] = 'atualizado_em = ?'; $vals[] = $agora; $vals[] = $id;
        $pdo->prepare('UPDATE intus_prova SET ' . implode(', ', $sets) . ' WHERE idprova = ?')->execute($vals);
        return $id;
    }
    $cols = array_keys($c);
    $pdo->prepare('INSERT INTO intus_prova (' . implode(', ', $cols) . ', criado_por, criado_por_nome, criado_em, atualizado_em) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?, ?, ?, ?)')
        ->execute(array_merge(array_values($c), [$idusuario, mb_substr($nomeUsuario, 0, 120), $agora, $agora]));
    return (int)$pdo->lastInsertId();
}

function provas_url_publica(?string $u): ?string {
    if ($u === null || $u === '') return null;
    return preg_match('#^https?://#i', $u) ? $u : PROVAS_BASE_URL . '/' . ltrim($u, '/');
}

function provas_midias(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {   // intus_midia é a tabela de vídeos do Feed; se ainda não existe, só não há vídeo enviado para tocar
        $st = $pdo->prepare("SELECT m.idpm, m.idprova, m.tipo, m.momento, m.url, m.poster, m.idmidia, m.ordem, m.legenda, i.token_publico, i.status AS video_status FROM intus_prova_midia m LEFT JOIN intus_midia i ON i.idmidia = m.idmidia WHERE m.idprova IN ($in) ORDER BY m.idprova, m.ordem, m.idpm");
        $st->execute(array_values($ids));
    } catch (Throwable $e) {
        $st = $pdo->prepare("SELECT m.idpm, m.idprova, m.tipo, m.momento, m.url, m.poster, m.idmidia, m.ordem, m.legenda, NULL AS token_publico, NULL AS video_status FROM intus_prova_midia m WHERE m.idprova IN ($in) ORDER BY m.idprova, m.ordem, m.idpm");
        $st->execute(array_values($ids));
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $url = $r['url'];
        if ($r['tipo'] === 'video' && !empty($r['token_publico'])) $url = '/app/api/midia.php?action=video&t=' . $r['token_publico'];
        $out[(int)$r['idprova']][] = ['idpm' => (int)$r['idpm'], 'tipo' => $r['tipo'], 'momento' => $r['momento'], 'url' => provas_url_publica($url), 'poster' => provas_url_publica($r['poster']),
            'legenda' => $r['legenda'], 'pronto' => $r['tipo'] !== 'video' || empty($r['idmidia']) || ($r['video_status'] ?? 'pronto') === 'pronto'];
    }
    return $out;
}

function provas_peso_txt($antes, $depois): string {
    if ($antes === null || $depois === null) return '';
    $a = (float)$antes; $d = (float)$depois;
    $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . ' kg';
    $dif = round($d - $a, 1);
    return 'de ' . $fmt($a) . ' para ' . $fmt($d) . ($dif == 0 ? '' : ' (' . ($dif < 0 ? 'menos ' : 'mais ') . $fmt(abs($dif)) . ')');
}

/** A prova como a tela e os outros módulos usam: com mídias, peso resumido e o que cada uso pode (já com a regra de autorização). */
function provas_formatar(array $r, array $midias): array {
    $divulga = $r['autorizacao'] === 'divulgar';
    $interno = $divulga || $r['autorizacao'] === 'interna';
    $p = ['idprova' => (int)$r['idprova'], 'titulo' => $r['titulo'], 'pessoa_nome' => $r['pessoa_nome'], 'idatleta' => $r['idatleta'] !== null ? (int)$r['idatleta'] : null,
        'sexo' => $r['sexo'], 'idade' => $r['idade'] !== null ? (int)$r['idade'] : null, 'objetivo' => $r['objetivo'], 'programa' => $r['programa'], 'plano_usado' => $r['plano_usado'], 'professor' => $r['professor'],
        'tempo_meses' => $r['tempo_meses'] !== null ? (float)$r['tempo_meses'] : null, 'tempo_txt' => $r['tempo_txt'],
        'peso_antes' => $r['peso_antes'] !== null ? (float)$r['peso_antes'] : null, 'peso_depois' => $r['peso_depois'] !== null ? (float)$r['peso_depois'] : null,
        'gordura_antes' => $r['gordura_antes'] !== null ? (float)$r['gordura_antes'] : null, 'gordura_depois' => $r['gordura_depois'] !== null ? (float)$r['gordura_depois'] : null,
        'medidas_txt' => $r['medidas_txt'], 'resultado_txt' => (string)$r['resultado_txt'], 'depoimento_txt' => (string)$r['depoimento_txt'], 'contexto_txt' => (string)$r['contexto_txt'],
        'tags' => $r['tags'] === '' ? [] : array_map('trim', explode(',', $r['tags'])), 'autorizacao' => $r['autorizacao'], 'autorizacao_obs' => $r['autorizacao_obs'],
        'uso_robo' => (int)$r['uso_robo'] === 1, 'uso_marketing' => (int)$r['uso_marketing'] === 1, 'uso_site' => (int)$r['uso_site'] === 1, 'uso_ia' => (int)$r['uso_ia'] === 1,
        'destaque' => (int)$r['destaque'] === 1, 'ativo' => (int)$r['ativo'] === 1, 'criado_por' => (int)$r['criado_por'], 'criado_por_nome' => $r['criado_por_nome'], 'criado_em' => $r['criado_em'], 'atualizado_em' => $r['atualizado_em'],
        'midias' => $midias];
    // o que de fato pode sair: a autorização manda sobre as chaves
    $p['pode_robo'] = $p['ativo'] && $divulga && $p['uso_robo'];
    $p['pode_marketing'] = $p['ativo'] && $divulga && $p['uso_marketing'];
    $p['pode_site'] = $p['ativo'] && $divulga && $p['uso_site'];
    $p['pode_ia'] = $p['ativo'] && $interno && $p['uso_ia'];
    $p['peso_resumo'] = provas_peso_txt($r['peso_antes'], $r['peso_depois']);
    $p['variacao_peso'] = ($r['peso_antes'] !== null && $r['peso_depois'] !== null) ? round((float)$r['peso_depois'] - (float)$r['peso_antes'], 1) : null;
    return $p;
}

/** Lista para a tela. Filtros: busca, objetivo, autorizacao, uso (robo|marketing|site|ia), ativo (1 padrão, 0, todos), limite. */
function provas_listar(PDO $pdo, array $f = []): array {
    provas_garantir_tabelas($pdo);
    $w = []; $v = [];
    $ativo = (string)($f['ativo'] ?? '1');
    if ($ativo === '1' || $ativo === '0') { $w[] = 'ativo = ?'; $v[] = (int)$ativo; }
    if (!empty($f['objetivo']) && isset(PROVAS_OBJETIVOS[$f['objetivo']])) { $w[] = 'objetivo = ?'; $v[] = $f['objetivo']; }
    if (!empty($f['autorizacao']) && isset(PROVAS_AUTORIZACAO[$f['autorizacao']])) { $w[] = 'autorizacao = ?'; $v[] = $f['autorizacao']; }
    if (!empty($f['uso']) && isset(PROVAS_USOS[$f['uso']])) $w[] = 'uso_' . $f['uso'] . ' = 1';
    $q = trim((string)($f['busca'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $w[] = "(pessoa_nome LIKE ? ESCAPE '\\' OR titulo LIKE ? ESCAPE '\\' OR resultado_txt LIKE ? ESCAPE '\\' OR tags LIKE ? ESCAPE '\\' OR plano_usado LIKE ? ESCAPE '\\')";
        array_push($v, $like, $like, $like, $like, $like);
    }
    $lim = max(1, min(300, (int)($f['limite'] ?? 200)));
    $st = $pdo->prepare('SELECT * FROM intus_prova' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY destaque DESC, idprova DESC LIMIT ' . $lim);
    $st->execute($v);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    $m = provas_midias($pdo, array_map(fn($r) => (int)$r['idprova'], $linhas));
    return array_map(fn($r) => provas_formatar($r, $m[(int)$r['idprova']] ?? []), $linhas);
}

function provas_obter(PDO $pdo, int $id): ?array {
    provas_garantir_tabelas($pdo);
    $st = $pdo->prepare('SELECT * FROM intus_prova WHERE idprova = ?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $m = provas_midias($pdo, [$id]);
    return provas_formatar($r, $m[$id] ?? []);
}

function provas_excluir(PDO $pdo, int $id, int $idusuario, bool $admin): bool {
    provas_garantir_tabelas($pdo);
    $st = $pdo->prepare('SELECT * FROM intus_prova WHERE idprova = ?'); $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;
    if (!provas_pode_mexer($r, $idusuario, $admin)) throw new RuntimeException('Só quem cadastrou a prova (ou o administrador) pode excluir.');
    $pdo->prepare('DELETE FROM intus_prova_midia WHERE idprova = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM intus_prova WHERE idprova = ?')->execute([$id]);
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Mídias
// ─────────────────────────────────────────────────────────────────────────────

function provas_proxima_ordem(PDO $pdo, int $idprova): int {
    $st = $pdo->prepare('SELECT COALESCE(MAX(ordem), -1) + 1 FROM intus_prova_midia WHERE idprova = ?'); $st->execute([$idprova]);
    return (int)$st->fetchColumn();
}
function provas_checar_dono(PDO $pdo, int $idprova, int $idusuario, bool $admin): array {
    $st = $pdo->prepare('SELECT * FROM intus_prova WHERE idprova = ?'); $st->execute([$idprova]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('Prova não encontrada.');
    if (!provas_pode_mexer($r, $idusuario, $admin)) throw new RuntimeException('Só quem cadastrou a prova (ou o administrador) pode alterar.');
    return $r;
}
function provas_momento(string $m): string { return in_array($m, PROVAS_MOMENTOS, true) ? $m : 'depois'; }

/** Guarda uma foto enviada como data URI (JPEG, PNG ou WEBP, até 8 MB, conferida e sem código escondido) em app/img/provas. */
function provas_foto_adicionar(PDO $pdo, int $idprova, string $dataUri, string $momento, string $legenda, int $idusuario, bool $admin, ?string $pasta = null): array {
    provas_garantir_tabelas($pdo);
    provas_checar_dono($pdo, $idprova, $idusuario, $admin);
    $st = $pdo->prepare("SELECT COUNT(*) FROM intus_prova_midia WHERE idprova = ? AND tipo = 'foto'"); $st->execute([$idprova]);
    if ((int)$st->fetchColumn() >= PROVAS_MAX_FOTOS) throw new InvalidArgumentException('Limite de ' . PROVAS_MAX_FOTOS . ' fotos por prova.');
    if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#is', $dataUri, $m)) throw new InvalidArgumentException('Imagem inválida.');
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 8 * 1024 * 1024) throw new InvalidArgumentException('Imagem inválida ou grande demais (máx. 8 MB).');
    $inf = @getimagesizefromstring($bin);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];
    if (defined('IMAGETYPE_WEBP')) $tipos[IMAGETYPE_WEBP] = 'webp';
    if (!$inf || !isset($tipos[$inf[2] ?? 0])) throw new InvalidArgumentException('O arquivo não é uma imagem válida (use JPEG, PNG ou WEBP).');
    if (is_file(__DIR__ . '/_antivirus.php')) { require_once __DIR__ . '/_antivirus.php'; if (function_exists('intusAntivirus') && intusAntivirus($bin) !== '') throw new InvalidArgumentException('A imagem foi recusada pela verificação de segurança.'); }
    $dir = $pasta ?: (__DIR__ . '/../img/provas');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('Sem permissão para gravar a imagem no servidor.');
    try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
    $nome = 'prova_' . $idprova . '_' . time() . '_' . $rand . '.' . $tipos[$inf[2]];
    if (@file_put_contents($dir . '/' . $nome, $bin) === false) throw new RuntimeException('Falha ao gravar a imagem.');
    $rel = '/app/img/provas/' . $nome;
    $pdo->prepare("INSERT INTO intus_prova_midia (idprova, tipo, momento, url, ordem, legenda, criado_em) VALUES (?, 'foto', ?, ?, ?, ?, ?)")
        ->execute([$idprova, provas_momento($momento), $rel, provas_proxima_ordem($pdo, $idprova), provas_txt($legenda, 160), provas_agora()]);
    return ['idpm' => (int)$pdo->lastInsertId(), 'url' => provas_url_publica($rel)];
}

/** Vídeo por link (YouTube, Instagram, Drive...). Só https. */
function provas_link_adicionar(PDO $pdo, int $idprova, string $url, string $momento, string $legenda, int $idusuario, bool $admin): array {
    provas_garantir_tabelas($pdo);
    provas_checar_dono($pdo, $idprova, $idusuario, $admin);
    $url = trim($url);
    if (!preg_match('#^https://[^\s<>"\']{4,480}$#i', $url)) throw new InvalidArgumentException('O link do vídeo precisa começar com https://');
    $st = $pdo->prepare("SELECT COUNT(*) FROM intus_prova_midia WHERE idprova = ? AND tipo IN ('video','link')"); $st->execute([$idprova]);
    if ((int)$st->fetchColumn() >= PROVAS_MAX_VIDEOS) throw new InvalidArgumentException('Limite de ' . PROVAS_MAX_VIDEOS . ' vídeos por prova.');
    $pdo->prepare("INSERT INTO intus_prova_midia (idprova, tipo, momento, url, ordem, legenda, criado_em) VALUES (?, 'link', ?, ?, ?, ?, ?)")
        ->execute([$idprova, provas_momento($momento), $url, provas_proxima_ordem($pdo, $idprova), provas_txt($legenda, 160), provas_agora()]);
    return ['idpm' => (int)$pdo->lastInsertId()];
}

/** Liga à prova um vídeo enviado para o Drive (a linha em intus_midia já existe, com dono_tipo 'prova'). */
function provas_video_ligar(PDO $pdo, int $idprova, int $idmidia, ?string $poster, string $momento, string $legenda): int {
    $pdo->prepare("INSERT INTO intus_prova_midia (idprova, tipo, momento, url, poster, idmidia, ordem, legenda, criado_em) VALUES (?, 'video', ?, '', ?, ?, ?, ?, ?)")
        ->execute([$idprova, provas_momento($momento), $poster, $idmidia, provas_proxima_ordem($pdo, $idprova), provas_txt($legenda, 160), provas_agora()]);
    return (int)$pdo->lastInsertId();
}

/** Tira a mídia da prova (a referência; o arquivo continua no disco ou no Drive, como no resto do sistema). */
function provas_midia_excluir(PDO $pdo, int $idpm, int $idusuario, bool $admin): bool {
    provas_garantir_tabelas($pdo);
    $st = $pdo->prepare('SELECT idprova FROM intus_prova_midia WHERE idpm = ?'); $st->execute([$idpm]);
    $idprova = $st->fetchColumn();
    if (!$idprova) return false;
    provas_checar_dono($pdo, (int)$idprova, $idusuario, $admin);
    $pdo->prepare('DELETE FROM intus_prova_midia WHERE idpm = ?')->execute([$idpm]);
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Para quem consome: robô, marketing e inteligência
// ─────────────────────────────────────────────────────────────────────────────

/** Só o primeiro nome vai para fora da equipe. */
function provas_primeiro_nome(string $nome): string {
    $p = preg_split('/\s+/u', trim($nome));
    return $p[0] ?? '';
}

/** Texto pronto da prova, para o robô e a equipe mandarem: fatos da pessoa, sem promessa. */
function provas_texto(array $p): string {
    $nome = provas_primeiro_nome((string)$p['pessoa_nome']);
    $quem = $p['sexo'] === 'F' ? 'a aluna' : ($p['sexo'] === 'M' ? 'o aluno' : 'a pessoa');
    $abre = ($p['sexo'] === 'F' ? 'A ' : ($p['sexo'] === 'M' ? 'O ' : '')) . $nome;
    $tempo = (string)$p['tempo_txt'];
    if ($tempo === '' && !empty($p['tempo_meses'])) { $m = (float)$p['tempo_meses']; $tempo = $m == 1.0 ? '1 mês' : rtrim(rtrim(number_format($m, 1, ',', ''), '0'), ',') . ' meses'; }
    $frase = trim($abre . (!empty($p['idade']) ? ' (' . (int)$p['idade'] . ' anos)' : '')) . ($tempo !== '' ? ' treinou por ' . $tempo . ' com a Intus' : ' treina com a Intus');
    $peso = (string)($p['peso_resumo'] ?? provas_peso_txt($p['peso_antes'] ?? null, $p['peso_depois'] ?? null));
    if ($peso !== '') $frase .= ' e foi ' . $peso;
    $frase = rtrim($frase, '.') . '.';
    $res = trim((string)$p['resultado_txt']);
    if ($res !== '') $frase .= ' ' . rtrim($res, '.') . '.';
    $dep = trim((string)$p['depoimento_txt']);
    if ($dep !== '') $frase .= ' Nas palavras dela(e): “' . rtrim($dep, '.') . '”';
    $frase = str_replace('dela(e)', $p['sexo'] === 'M' ? 'dele' : ($p['sexo'] === 'F' ? 'dela' : 'da pessoa'), $frase);
    if (mb_strlen($frase) > 600) $frase = rtrim(mb_substr($frase, 0, 597)) . '...';
    return $frase;
}

/** As provas que o robô pode usar: ativas, com autorização para divulgar e o uso "robô" ligado. */
function provas_carregar_robo(PDO $pdo): array {
    try {
        provas_garantir_tabelas($pdo);
        $st = $pdo->prepare("SELECT * FROM intus_prova WHERE ativo = 1 AND autorizacao = 'divulgar' AND uso_robo = 1 ORDER BY destaque DESC, idprova DESC LIMIT 200");
        $st->execute();
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        $m = provas_midias($pdo, array_map(fn($r) => (int)$r['idprova'], $linhas));
        return array_map(fn($r) => provas_formatar($r, $m[(int)$r['idprova']] ?? []), $linhas);
    } catch (Throwable $e) { return []; }
}

/** Objetivo (código) a partir do texto livre que a pessoa deu ("Emagrecer", "ganhar massa"...). */
function provas_objetivo_de_texto(string $t): string {
    $t = mb_strtolower($t, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    if (preg_match('/emagre|perder peso|queimar|secar barriga/', $t)) return 'emagrecimento';
    if (preg_match('/massa|hipertrof|ganhar peso|crescer/', $t)) return 'hipertrofia';
    if (preg_match('/defin|tonific|shape/', $t)) return 'definicao';
    if (preg_match('/saude|disposic|qualidade de vida|bem.?estar/', $t)) return 'saude';
    if (preg_match('/performance|condicion|corrida|desempenho|forca/', $t)) return 'performance';
    if (preg_match('/lesao|reabilit|dor|recuper/', $t)) return 'reabilitacao';
    return '';
}

/** A prova que mais combina com a pessoa (objetivo igual, mesmo sexo, destaque, com foto ou vídeo, com peso). null se não há nenhuma. */
function provas_escolher(array $lista, array $pessoa): ?array {
    if (!$lista) return null;
    $obj = provas_objetivo_de_texto((string)($pessoa['objetivo'] ?? ''));
    $sexo = strtoupper((string)($pessoa['genero'] ?? $pessoa['sexo'] ?? ''));
    $melhor = null; $melhorPts = -1;
    foreach ($lista as $p) {
        $pts = 0;
        if ($obj !== '' && $p['objetivo'] === $obj) $pts += 3;
        if (($sexo === 'F' || $sexo === 'M') && $p['sexo'] === $sexo) $pts += 4;   // se identificar com a pessoa pesa mais que o resto
        if (!empty($p['destaque'])) $pts += 2;
        if (!empty($p['midias'])) $pts += 1;
        if ($p['peso_antes'] !== null && $p['peso_depois'] !== null) $pts += 1;
        if ($pts > $melhorPts) { $melhorPts = $pts; $melhor = $p; }   // empate: a lista já vem do mais novo para o mais antigo
    }
    return $melhor;
}

/**
 * Pacote para a inteligência do ecossistema (Laboratório de copy, marketing, avaliações): só provas com o uso "IA" ligado e autorização
 * interna ou de divulgação. Primeiro nome, sem telefone e sem endereço de foto; as fotos entram só como contagem.
 */
function provas_para_ia(PDO $pdo, int $limite = 60): array {
    provas_garantir_tabelas($pdo);
    $st = $pdo->prepare("SELECT * FROM intus_prova WHERE ativo = 1 AND uso_ia = 1 AND autorizacao IN ('interna','divulgar') ORDER BY destaque DESC, idprova DESC LIMIT " . max(1, min(200, $limite)));
    $st->execute();
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    $m = provas_midias($pdo, array_map(fn($r) => (int)$r['idprova'], $linhas));
    $out = [];
    foreach ($linhas as $r) {
        $p = provas_formatar($r, $m[(int)$r['idprova']] ?? []);
        $out[] = ['id' => $p['idprova'], 'nome' => provas_primeiro_nome($p['pessoa_nome']), 'sexo' => $p['sexo'], 'idade' => $p['idade'], 'objetivo' => $p['objetivo'], 'programa' => $p['programa'],
            'plano' => $p['plano_usado'], 'tempo' => $p['tempo_txt'] !== '' ? $p['tempo_txt'] : ($p['tempo_meses'] !== null ? $p['tempo_meses'] . ' meses' : ''),
            'peso_antes' => $p['peso_antes'], 'peso_depois' => $p['peso_depois'], 'variacao_peso' => $p['variacao_peso'], 'gordura_antes' => $p['gordura_antes'], 'gordura_depois' => $p['gordura_depois'],
            'medidas' => $p['medidas_txt'], 'resultado' => $p['resultado_txt'], 'depoimento' => $p['depoimento_txt'], 'contexto' => $p['contexto_txt'], 'tags' => $p['tags'],
            'pode_divulgar' => $p['autorizacao'] === 'divulgar', 'midias' => ['fotos' => count(array_filter($p['midias'], fn($x) => $x['tipo'] === 'foto')), 'videos' => count(array_filter($p['midias'], fn($x) => $x['tipo'] !== 'foto'))],
            'texto_pronto' => $p['autorizacao'] === 'divulgar' ? provas_texto($p) : null];
    }
    return $out;
}

/** Contagens para o topo da tela. */
function provas_resumo(PDO $pdo): array {
    provas_garantir_tabelas($pdo);
    $r = $pdo->query("SELECT COUNT(*) AS total, SUM(CASE WHEN autorizacao = 'divulgar' THEN 1 ELSE 0 END) AS divulgar, SUM(CASE WHEN autorizacao = 'pendente' THEN 1 ELSE 0 END) AS pendentes, SUM(CASE WHEN uso_robo = 1 AND autorizacao = 'divulgar' THEN 1 ELSE 0 END) AS robo, SUM(CASE WHEN destaque = 1 THEN 1 ELSE 0 END) AS destaques FROM intus_prova WHERE ativo = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['total' => (int)($r['total'] ?? 0), 'divulgar' => (int)($r['divulgar'] ?? 0), 'pendentes' => (int)($r['pendentes'] ?? 0), 'robo' => (int)($r['robo'] ?? 0), 'destaques' => (int)($r['destaques'] ?? 0)];
}
