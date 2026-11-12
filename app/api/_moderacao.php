<?php
/**
 * Intus Fit: moderação do que os alunos publicam (denúncia, bloqueio, suspensão, filtro de termos graves e aceite das regras).
 *
 * Biblioteca interna (prefixo _ = não é endpoint). Existe por exigência das lojas: a Apple (diretriz 1.2) e a política de conteúdo gerado por
 * usuário do Google Play pedem (1) filtro de conteúdo ofensivo ANTES de publicar, (2) denúncia de conteúdo com resposta em tempo hábil, (3) bloqueio
 * de usuário abusivo e (4) contato publicado e regras aceitas antes de publicar. A regra mora AQUI; catalogo.php e treinos.php só chamam.
 *
 * Tabelas novas e aditivas, criadas na primeira gravação (nenhuma tabela existente é alterada): intus_bloqueio, intus_denuncia, intus_suspensao e
 * intus_aceite_regras. SQL portável de propósito (roda em SQLite nos testes e em MySQL na produção): sem INSERT IGNORE, ON DUPLICATE KEY nem NOW();
 * os horários são gravados pelo PHP, em Brasília. Nenhuma função de LEITURA lança: banco fora do ar nunca derruba o Feed.
 *
 * Bloqueio é só entre ALUNOS (professor e administrador não são bloqueáveis: a consultoria é o serviço). Vale nos dois sentidos: quem bloqueia não
 * vê a pessoa e a pessoa não vê quem bloqueou (Feed, comentários, mural, resultados, amizade, mensagens, menções e avisos).
 */

if (!defined('INTUS_MOD_CONTATO')) define('INTUS_MOD_CONTATO', 'contato@intusfit.com.br');
if (!defined('INTUS_MOD_SLA_HORAS')) define('INTUS_MOD_SLA_HORAS', 24);          // resposta às denúncias (também é a promessa dos Termos de Uso)
if (!defined('INTUS_MOD_DENUNCIAS_DIA')) define('INTUS_MOD_DENUNCIAS_DIA', 20);  // por aluno, em 24 h: freio contra denúncia em massa
if (!defined('INTUS_MOD_JANELA_VENDA')) define('INTUS_MOD_JANELA_VENDA', 1);      // palavras permitidas entre "vendo" e a substância para contar como venda
if (!defined('INTUS_MOD_VERSAO_REGRAS')) define('INTUS_MOD_VERSAO_REGRAS', '2026-11');   // mudar o texto das regras = subir a versão (pede o aceite de novo)

/** Motivos de denúncia: código => rótulo. O app mostra estes rótulos; o servidor só aceita estes códigos. */
function intusModMotivos(): array
{
    return [
        'spam' => 'Spam ou propaganda',
        'assedio' => 'Assédio, bullying ou ameaça',
        'odio' => 'Ódio ou discriminação',
        'sexual' => 'Nudez ou conteúdo sexual',
        'violencia' => 'Violência ou conteúdo chocante',
        'substancias' => 'Venda ou incentivo de substâncias proibidas',
        'falso' => 'Perfil falso, golpe ou fraude',
        'outro' => 'Outro motivo',
    ];
}

/** O que dá para denunciar. */
function intusModTipos(): array
{
    return ['feed_post', 'comentario', 'mural', 'resultado', 'dm', 'perfil'];
}

function intusModAgora(?int $ts = null): string
{
    $d = new DateTime('@' . ($ts ?? time()));
    $d->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return $d->format('Y-m-d H:i:s');
}

function intusModSqlite(PDO $pdo): bool { return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }

function intusModGarantirTabelas(PDO $pdo): bool
{
    static $ok = [];
    $k = spl_object_id($pdo);
    if (!empty($ok[$k])) return true;
    try {
        foreach (['intus_bloqueio', 'intus_denuncia', 'intus_suspensao', 'intus_aceite_regras'] as $t) $pdo->query("SELECT 1 FROM $t LIMIT 1");
        return $ok[$k] = true;
    } catch (Throwable $e) { /* alguma ainda não existe: cria abaixo */ }
    try {
        if (intusModSqlite($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS intus_bloqueio (idbloqueio INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, idbloqueado INTEGER NOT NULL, criado_em TEXT NOT NULL, UNIQUE (idatleta, idbloqueado))");
            $pdo->exec("CREATE TABLE IF NOT EXISTS intus_denuncia (iddenuncia INTEGER PRIMARY KEY AUTOINCREMENT, denunciante_id INTEGER NOT NULL, alvo_tipo TEXT NOT NULL, alvo_id INTEGER NOT NULL, autor_tipo TEXT NOT NULL DEFAULT 'aluno', autor_id INTEGER NOT NULL DEFAULT 0, motivo TEXT NOT NULL, detalhe TEXT, trecho TEXT, midia TEXT, contexto TEXT, status TEXT NOT NULL DEFAULT 'aberta', acao TEXT, nota_equipe TEXT, resolvido_por INTEGER, resolvido_em TEXT, criado_em TEXT NOT NULL, UNIQUE (denunciante_id, alvo_tipo, alvo_id))");
            $pdo->exec("CREATE TABLE IF NOT EXISTS intus_suspensao (idsuspensao INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, ate TEXT, motivo TEXT, iddenuncia INTEGER, criado_por INTEGER, criado_em TEXT NOT NULL, ativo INTEGER NOT NULL DEFAULT 1)");
            $pdo->exec("CREATE TABLE IF NOT EXISTS intus_aceite_regras (idatleta INTEGER PRIMARY KEY, versao TEXT NOT NULL, aceito_em TEXT NOT NULL)");
            return $ok[$k] = true;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_bloqueio (
            idbloqueio INT AUTO_INCREMENT PRIMARY KEY,
            idatleta INT NOT NULL,
            idbloqueado INT NOT NULL,
            criado_em DATETIME NOT NULL,
            UNIQUE KEY u_par (idatleta, idbloqueado),
            INDEX i_bloqueado (idbloqueado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_denuncia (
            iddenuncia INT AUTO_INCREMENT PRIMARY KEY,
            denunciante_id INT NOT NULL,
            alvo_tipo VARCHAR(20) NOT NULL,
            alvo_id INT NOT NULL,
            autor_tipo VARCHAR(10) NOT NULL DEFAULT 'aluno',
            autor_id INT NOT NULL DEFAULT 0,
            motivo VARCHAR(20) NOT NULL,
            detalhe VARCHAR(500) NULL,
            trecho VARCHAR(600) NULL,
            midia VARCHAR(500) NULL,
            contexto VARCHAR(60) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'aberta',
            acao VARCHAR(40) NULL,
            nota_equipe VARCHAR(500) NULL,
            resolvido_por INT NULL,
            resolvido_em DATETIME NULL,
            criado_em DATETIME NOT NULL,
            UNIQUE KEY u_denuncia (denunciante_id, alvo_tipo, alvo_id),
            INDEX i_status (status, criado_em),
            INDEX i_autor (autor_tipo, autor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_suspensao (
            idsuspensao INT AUTO_INCREMENT PRIMARY KEY,
            idatleta INT NOT NULL,
            ate DATETIME NULL,
            motivo VARCHAR(200) NULL,
            iddenuncia INT NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL,
            ativo TINYINT NOT NULL DEFAULT 1,
            INDEX i_atleta (idatleta, ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_aceite_regras (
            idatleta INT NOT NULL PRIMARY KEY,
            versao VARCHAR(20) NOT NULL,
            aceito_em DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Texto livre com emoji: se a tabela já existia com outro charset, converte (mesma regra de _charset_fix.php).
        if (function_exists('_intusGarantirUtf8mb4')) {
            _intusGarantirUtf8mb4($pdo, 'intus_denuncia', ['detalhe', 'trecho', 'midia', 'contexto', 'nota_equipe']);
            _intusGarantirUtf8mb4($pdo, 'intus_suspensao', ['motivo']);
        }
        return $ok[$k] = true;
    } catch (Throwable $e) { return $ok[$k] = false; }
}

// ───────────────────────── alunos: tabela e nomes ─────────────────────────

function intusModTabelaAtleta(PDO $pdo): ?string
{
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return $t; } catch (Throwable $e) { /* tenta a próxima */ }
    }
    return null;
}

/** id => nome, dos alunos pedidos (o que não existir fica de fora). */
function intusModNomes(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($n) { return $n > 0; })));
    $out = [];
    $tbl = $ids ? intusModTabelaAtleta($pdo) : null;
    if (!$tbl) return $out;
    try {
        $st = $pdo->query("SELECT idatleta, nome FROM `$tbl` WHERE idatleta IN (" . implode(',', $ids) . ")");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['idatleta']] = (string)$r['nome'];
    } catch (Throwable $e) { /* sem nomes: a tela mostra o número */ }
    return $out;
}

// ───────────────────────── bloqueio ─────────────────────────

/** ['bloqueei' => ids que EU bloqueei, 'bloquearam' => ids que me bloquearam, 'todos' => os dois juntos]. Nunca lança. */
function intusModBloqueiosDe(PDO $pdo, int $id): array
{
    $vazio = ['bloqueei' => [], 'bloquearam' => [], 'todos' => []];
    if ($id <= 0) return $vazio;
    try {
        $a = $pdo->prepare("SELECT idbloqueado FROM intus_bloqueio WHERE idatleta = ?");
        $a->execute([$id]);
        $b = $pdo->prepare("SELECT idatleta FROM intus_bloqueio WHERE idbloqueado = ?");
        $b->execute([$id]);
        $bloqueei = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN));
        $bloquearam = array_map('intval', $b->fetchAll(PDO::FETCH_COLUMN));
        return ['bloqueei' => $bloqueei, 'bloquearam' => $bloquearam, 'todos' => array_values(array_unique(array_merge($bloqueei, $bloquearam)))];
    } catch (Throwable $e) {
        return $vazio;   // tabela ainda não existe (ninguém bloqueou ninguém) ou banco fora: nada a esconder
    }
}

/** Ids que não devem aparecer para $id (quem ele bloqueou e quem o bloqueou). */
function intusModIdsBloqueados(PDO $pdo, int $id): array
{
    return intusModBloqueiosDe($pdo, $id)['todos'];
}

function intusModBloqueadosEntre(PDO $pdo, int $a, int $b): bool
{
    if ($a <= 0 || $b <= 0 || $a === $b) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM intus_bloqueio WHERE (idatleta = ? AND idbloqueado = ?) OR (idatleta = ? AND idbloqueado = ?) LIMIT 1");
        $st->execute([$a, $b, $b, $a]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/**
 * $quem bloqueia $alvo. Desfaz amizade (pedido pendente também), parceria de treino e convites de post colaborativo entre os dois.
 * Idempotente. Devolve ['ok' => bool, 'novo' => bool] ou ['erro' => código].
 */
function intusModBloquear(PDO $pdo, int $quem, int $alvo, ?string $agora = null): array
{
    if ($quem <= 0 || $alvo <= 0) return ['erro' => 'invalido'];
    if ($quem === $alvo) return ['erro' => 'si_mesmo'];
    if (!intusModGarantirTabelas($pdo)) return ['erro' => 'banco'];
    $novo = false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM intus_bloqueio WHERE idatleta = ? AND idbloqueado = ?");
        $st->execute([$quem, $alvo]);
        if (!$st->fetchColumn()) {
            $pdo->prepare("INSERT INTO intus_bloqueio (idatleta, idbloqueado, criado_em) VALUES (?,?,?)")->execute([$quem, $alvo, $agora ?? intusModAgora()]);
            $novo = true;
        }
    } catch (Throwable $e) { return ['erro' => 'banco']; }
    // O que ligava os dois acaba junto. Cada limpeza é independente: tabela ausente numa instalação antiga não impede o bloqueio.
    foreach (['intus_amizade', 'intus_parceria'] as $t) {
        try { $pdo->prepare("DELETE FROM $t WHERE (de_id = ? AND para_id = ?) OR (de_id = ? AND para_id = ?)")->execute([$quem, $alvo, $alvo, $quem]); } catch (Throwable $e) {}
    }
    foreach ([[$alvo, $quem], [$quem, $alvo]] as [$convidado, $autor]) {
        try { $pdo->prepare("DELETE FROM intus_post_colab WHERE idatleta_convidado = ? AND idpost IN (SELECT idpost FROM intus_feed_post WHERE idatleta = ?)")->execute([$convidado, $autor]); } catch (Throwable $e) {}
    }
    return ['ok' => true, 'novo' => $novo];
}

function intusModDesbloquear(PDO $pdo, int $quem, int $alvo): bool
{
    if ($quem <= 0 || $alvo <= 0) return false;
    try {
        $pdo->prepare("DELETE FROM intus_bloqueio WHERE idatleta = ? AND idbloqueado = ?")->execute([$quem, $alvo]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** Pedaço de SQL (" AND NOT (...)") que tira dos avisos do sino de $alunoId os gestos de quem ele bloqueou e de quem o bloqueou. Vazio se não há bloqueio. Aviso da equipe e do sistema não é afetado. */
function intusModFiltroAvisos(PDO $pdo, int $alunoId): string
{
    $ids = intusModIdsBloqueados($pdo, $alunoId);
    if (!$ids) return '';
    return " AND NOT (ator_tipo = 'aluno' AND ator_id IN (" . implode(',', array_map('intval', $ids)) . "))";
}

/** Quem EU bloqueei, com o nome (a tela "Pessoas bloqueadas"). */
function intusModListaBloqueados(PDO $pdo, int $quem): array
{
    $ids = intusModBloqueiosDe($pdo, $quem)['bloqueei'];
    $nomes = intusModNomes($pdo, $ids);
    $out = [];
    foreach ($ids as $i) $out[] = ['id' => $i, 'nome' => $nomes[$i] ?? ('Aluno #' . $i)];
    usort($out, function ($x, $y) { return strcasecmp($x['nome'], $y['nome']); });
    return $out;
}

// ───────────────────────── suspensão ─────────────────────────

/** Suspensão em vigor do aluno (publicar, comentar e mandar mensagem), ou null. $dias = 0 grava "sem prazo". */
function intusModSuspensaoAtiva(PDO $pdo, int $idatleta, ?string $agora = null): ?array
{
    if ($idatleta <= 0) return null;
    $agora = $agora ?? intusModAgora();
    try {
        $st = $pdo->prepare("SELECT idsuspensao, ate, motivo FROM intus_suspensao WHERE idatleta = ? AND ativo = 1 AND (ate IS NULL OR ate > ?) ORDER BY idsuspensao DESC LIMIT 1");
        $st->execute([$idatleta, $agora]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['idsuspensao' => (int)$r['idsuspensao'], 'ate' => $r['ate'], 'motivo' => (string)($r['motivo'] ?? '')] : null;
    } catch (Throwable $e) { return null; }   // na dúvida (banco fora, tabela ausente) ninguém fica suspenso
}

function intusModSuspender(PDO $pdo, int $idatleta, int $dias, string $motivo, int $porId, ?int $iddenuncia = null, ?string $agora = null): array
{
    if ($idatleta <= 0) return ['erro' => 'invalido'];
    if ($dias < 0 || $dias > 365) return ['erro' => 'dias'];
    if (!intusModGarantirTabelas($pdo)) return ['erro' => 'banco'];
    $agora = $agora ?? intusModAgora();
    $ate = null;
    if ($dias > 0) {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', $agora, new DateTimeZone('America/Sao_Paulo'));
        $ate = $d->modify('+' . $dias . ' days')->format('Y-m-d H:i:s');
    }
    try {
        // Suspender de novo substitui a anterior (só uma vale por vez).
        $pdo->prepare("UPDATE intus_suspensao SET ativo = 0 WHERE idatleta = ? AND ativo = 1")->execute([$idatleta]);
        $pdo->prepare("INSERT INTO intus_suspensao (idatleta, ate, motivo, iddenuncia, criado_por, criado_em, ativo) VALUES (?,?,?,?,?,?,1)")
            ->execute([$idatleta, $ate, mb_substr(trim($motivo), 0, 200), $iddenuncia, $porId > 0 ? $porId : null, $agora]);
        return ['ok' => true, 'ate' => $ate];
    } catch (Throwable $e) { return ['erro' => 'banco']; }
}

function intusModReativar(PDO $pdo, int $idatleta): bool
{
    if ($idatleta <= 0) return false;
    try {
        $pdo->prepare("UPDATE intus_suspensao SET ativo = 0 WHERE idatleta = ? AND ativo = 1")->execute([$idatleta]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** Suspensões em vigor, com o nome (a tela da equipe). */
function intusModSuspensoes(PDO $pdo, ?string $agora = null): array
{
    $agora = $agora ?? intusModAgora();
    try {
        $st = $pdo->prepare("SELECT idsuspensao, idatleta, ate, motivo, criado_em FROM intus_suspensao WHERE ativo = 1 AND (ate IS NULL OR ate > ?) ORDER BY idsuspensao DESC LIMIT 200");
        $st->execute([$agora]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $nomes = intusModNomes($pdo, array_column($rows, 'idatleta'));
    foreach ($rows as &$r) { $r['idsuspensao'] = (int)$r['idsuspensao']; $r['idatleta'] = (int)$r['idatleta']; $r['nome'] = $nomes[$r['idatleta']] ?? ('Aluno #' . $r['idatleta']); }
    unset($r);
    return $rows;
}

function intusModMensagemSuspensao(array $s): string
{
    $quando = ' por tempo indeterminado';
    if (!empty($s['ate'])) {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', (string)$s['ate']);
        if ($d) $quando = ' até ' . $d->format('d/m/Y');
    }
    return 'Sua conta está suspensa de publicar, comentar e enviar mensagens' . $quando . '. Treino, cardio, ranking e nutrição continuam normais. '
         . 'Se acha que foi um engano, fale com a equipe: ' . INTUS_MOD_CONTATO . '.';
}

/** Versão curta do aviso de suspensão para o sino: a coluna do aviso tem 160 caracteres, e a data e o contato não podem ser cortados. */
function intusModAvisoSuspensao(array $s): string
{
    $quando = 'por tempo indeterminado';
    if (!empty($s['ate'])) {
        $d = DateTime::createFromFormat('Y-m-d H:i:s', (string)$s['ate']);
        if ($d) $quando = 'até ' . $d->format('d/m/Y');
    }
    return 'Sua conta está suspensa de publicar ' . $quando . '. Treino e ranking seguem normais. Dúvidas: ' . INTUS_MOD_CONTATO;
}

/** Responde 403 e encerra se o ALUNO está suspenso. $ctx = contexto de autenticação (is_aluno, idatleta). Equipe não passa por aqui. */
function intusModExigirPodePublicar(PDO $pdo, array $ctx): void
{
    if (empty($ctx['is_aluno'])) return;
    $id = (int)($ctx['idatleta'] ?? $ctx['idusuario'] ?? 0);
    $s = intusModSuspensaoAtiva($pdo, $id);
    if ($s) {
        http_response_code(403);
        echo json_encode(['error' => intusModMensagemSuspensao($s), 'codigo' => 'suspenso', 'ate' => $s['ate']], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ───────────────────────── filtro de termos graves ─────────────────────────
// Primeira barreira, antes de publicar. NÃO pega tudo (nenhuma lista pega): a segunda barreira é a denúncia e a terceira, a equipe. Por isso a lista é
// curta e de termos sem uso inocente comum no treino (não bloqueia "porra", "caralho" nem "vou te matar no treino de perna", que são conversa de academia).

function intusModTermos(): array
{
    return [
        'odio' => [
            'viado', 'viadinho', 'viadao', 'traveco', 'crioulo', 'retardado', 'retardada', 'mongoloide',
            'macaco imundo', 'preto imundo', 'preta imunda', 'preto fedido', 'preta fedida', 'volta pra senzala',
            'judeu imundo', 'heil hitler', 'viva hitler', 'supremacia branca', 'morte aos gays', 'morte aos negros', 'morte aos judeus',
        ],
        'sexual' => [
            'nudes', 'onlyfans', 'xvideos', 'pornhub', 'pornografia', 'buceta', 'xoxota', 'piroca', 'punheta', 'boquete',
            'sexo anal', 'sexo oral', 'fotos intimas',
        ],
        'ameaca' => [
            'vou te esfaquear', 'vou te dar um tiro', 'vou te encher de porrada', 'vou te espancar', 'vou te estuprar', 'vou estuprar',
            'vai se matar', 'se mata logo', 'merece morrer',
        ],
        'insulto' => [
            'filho da puta', 'filha da puta', 'filhos da puta', 'filhas da puta', 'tomar no cu', 'tomar no seu cu', 'vai se foder', 'vai se fuder', 'vao se foder',
            'arrombado', 'arrombada', 'vagabunda', 'vadia',
        ],
    ];
}

/** Venda de anabolizante ou droga: precisa de uma palavra de venda JUNTO de uma palavra de substância (conversar sobre o assunto é permitido). */
function intusModPalavrasVenda(): array
{
    return ['vendo', 'vende', 'vendem', 'vendemos', 'vendendo', 'compro', 'pronta entrega', 'a pronta entrega'];
}

function intusModPalavrasSubstancia(): array
{
    return [
        'anabolizante', 'esteroide', 'hormonio', 'oxandrolona', 'stanozolol', 'winstrol', 'trembolona', 'trenbolona', 'boldenona', 'durateston', 'deca durabolin',
        'decadurabolin', 'nandrolona', 'sustanon', 'primobolan', 'dianabol', 'clenbuterol', 'clembuterol', 'testosterona', 'hemogenin',
        'maconha', 'cocaina', 'crack', 'lsd', 'ecstasy', 'mdma', 'haxixe', 'metanfetamina',
    ];
}

/** Minúsculas, sem acento, sem letra escondida (v i a d o, v.i.a.d.o, v14d0), tudo em palavras separadas por um espaço. */
function intusModNormalizar(string $texto): string
{
    $t = @iconv('UTF-8', 'UTF-8//IGNORE', mb_substr($texto, 0, 5000));
    if ($t === false || $t === '') return '';
    $t = mb_strtolower($t, 'UTF-8');
    $t = (string)preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x{00AD}]/u', '', $t);
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    // letras trocadas por número ou símbolo, só dentro de palavra que TEM letra (números sozinhos ficam como estão)
    $t = (string)preg_replace_callback('/\S+/u', function ($m) {
        $w = $m[0];
        if (!preg_match('/[a-z]/', $w) || !preg_match('/[0134578@$]/', $w)) return $w;
        return strtr($w, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);
    }, $t);
    // letras soltas separadas por espaço, ponto, hífen, sublinhado ou asterisco (4 ou mais): "v i a d o" vira "viado"
    $t = (string)preg_replace_callback('/(?<![a-z0-9])(?:[a-z][\s.\-_*]+){3,}[a-z](?![a-z0-9])/u', function ($m) { return preg_replace('/[^a-z]+/', '', $m[0]); }, $t);
    $t = (string)preg_replace('/[^a-z]+/', ' ', $t);
    return trim((string)preg_replace('/ +/', ' ', $t));
}

/** Letras repetidas viram uma só ("viaaado" e "porrra"), para o termo e para o texto. */
function intusModColapsar(string $s): string { return (string)preg_replace('/([a-z])\1+/', '$1', $s); }

/** O termo está no texto como palavra inteira, no singular ou no plural ("viado", "viados"), com ou sem letra repetida? ($palavras e $colapsado vêm com um espaço nas pontas.) */
function intusModAcha(string $palavras, string $colapsado, string $termo): bool
{
    $c = intusModColapsar($termo);
    foreach (['', 's', 'es'] as $suf) {
        if (strpos($palavras, ' ' . $termo . $suf . ' ') !== false) return true;
        if (strpos($colapsado, ' ' . $c . intusModColapsar($suf) . ' ') !== false) return true;
    }
    return false;
}

/** ['categoria' => ..., 'termo' => ...] se o texto tem termo grave, senão null. */
function intusModTextoGrave(string $texto): ?array
{
    $n = intusModNormalizar($texto);
    if ($n === '') return null;
    $palavras = ' ' . $n . ' ';
    $colapsado = ' ' . intusModColapsar($n) . ' ';
    foreach (intusModTermos() as $categoria => $lista) {
        foreach ($lista as $termo) {
            if (intusModAcha($palavras, $colapsado, $termo)) return ['categoria' => $categoria, 'termo' => $termo];
        }
    }
    // Venda de anabolizante ou droga: a palavra de venda e a substância têm de estar PERTO (no máximo INTUS_MOD_JANELA_VENDA palavra entre elas).
    // "vendo oxandrolona" e "oxandrolona pronta entrega" são barradas; "vendo o resultado da testosterona" e "vendo que minha testosterona caiu" (de ver, olhar) não:
    // "vendo" seguido de "que", "o", "a", "como"... é o verbo ver, não vender.
    $tok = explode(' ', $n);
    $tokC = explode(' ', intusModColapsar($n));
    $vendas = [];
    foreach (intusModPalavrasVenda() as $p) foreach (intusModPosicoes($tok, $tokC, $p) as $pos) $vendas[] = [$p, $pos[0], $pos[1]];
    if (!$vendas) return null;
    foreach (intusModPalavrasSubstancia() as $sub) {
        foreach (intusModPosicoes($tok, $tokC, $sub) as $ps) {
            foreach ($vendas as $v) {
                if ($v[0] === 'vendo' && in_array($tok[$v[2] + 1] ?? '', ['que', 'o', 'a', 'os', 'as', 'como', 'se', 'isso', 'tudo', 'quanto', 'ele', 'ela', 'eles', 'elas', 'um', 'uma', 'essa', 'esse'], true)) continue;
                $entre = $ps[0] > $v[2] ? $ps[0] - $v[2] - 1 : ($v[1] > $ps[1] ? $v[1] - $ps[1] - 1 : 0);
                if ($entre <= INTUS_MOD_JANELA_VENDA) return ['categoria' => 'substancias', 'termo' => $v[0] . ' + ' . $sub];
            }
        }
    }
    return null;
}

/** Onde a frase $termo (uma ou mais palavras; a última também vale no plural) aparece nas palavras do texto: lista de [primeira, última] (posições). */
function intusModPosicoes(array $tok, array $tokC, string $termo): array
{
    $pal = explode(' ', $termo);
    $palC = explode(' ', intusModColapsar($termo));
    $n = count($pal);
    $out = [];
    for ($i = 0; $i + $n <= count($tok); $i++) {
        $ok = true;
        for ($k = 0; $k < $n && $ok; $k++) {
            $sufs = $k === $n - 1 ? ['', 's', 'es'] : [''];
            $igual = false;
            foreach ($sufs as $suf) {
                if ($tok[$i + $k] === $pal[$k] . $suf || (isset($tokC[$i + $k]) && $tokC[$i + $k] === $palC[$k] . intusModColapsar($suf))) { $igual = true; break; }
            }
            $ok = $igual;
        }
        if ($ok) $out[] = [$i, $i + $n - 1];
    }
    return $out;
}

/** Responde 422 e encerra se algum dos textos (de ALUNO) tem termo grave. Equipe não passa por aqui. Não devolve o termo: só o motivo geral. */
function intusModExigirTextoLimpo(array $ctx, string ...$textos): void
{
    if (empty($ctx['is_aluno'])) return;
    foreach ($textos as $t) {
        if (trim($t) === '') continue;
        $g = intusModTextoGrave($t);
        if ($g) {
            @error_log('[intus moderacao] texto barrado categoria=' . $g['categoria'] . ' aluno=' . (int)($ctx['idatleta'] ?? $ctx['idusuario'] ?? 0));
            http_response_code(422);
            echo json_encode(['error' => 'Seu texto tem termos que não são permitidos na comunidade da Intus. Reescreva e tente de novo.', 'codigo' => 'conteudo_improprio'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// ───────────────────────── aceite das regras da comunidade ─────────────────────────

/** ['versao' => ..., 'aceito_em' => ..., 'vigente' => bool] ou null se nunca aceitou. */
function intusModAceiteRegras(PDO $pdo, int $idatleta): ?array
{
    if ($idatleta <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT versao, aceito_em FROM intus_aceite_regras WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['versao' => (string)$r['versao'], 'aceito_em' => (string)$r['aceito_em'], 'vigente' => (string)$r['versao'] === INTUS_MOD_VERSAO_REGRAS] : null;
    } catch (Throwable $e) { return null; }
}

function intusModAceitarRegras(PDO $pdo, int $idatleta, ?string $agora = null): bool
{
    if ($idatleta <= 0 || !intusModGarantirTabelas($pdo)) return false;
    try {
        $agora = $agora ?? intusModAgora();
        $st = $pdo->prepare("SELECT 1 FROM intus_aceite_regras WHERE idatleta = ?");
        $st->execute([$idatleta]);
        if ($st->fetchColumn()) $pdo->prepare("UPDATE intus_aceite_regras SET versao = ?, aceito_em = ? WHERE idatleta = ?")->execute([INTUS_MOD_VERSAO_REGRAS, $agora, $idatleta]);
        else $pdo->prepare("INSERT INTO intus_aceite_regras (idatleta, versao, aceito_em) VALUES (?,?,?)")->execute([$idatleta, INTUS_MOD_VERSAO_REGRAS, $agora]);
        return true;
    } catch (Throwable $e) { return false; }
}

// ───────────────────────── denúncia ─────────────────────────

function _intusModLimpar(string $s, int $max): string
{
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return mb_substr(trim($s), 0, $max);
}

/**
 * Descobre, NO SERVIDOR, quem escreveu o que está sendo denunciado e guarda um trecho (a equipe precisa ver o que foi dito mesmo que a pessoa
 * apague depois). Nunca confia no que o app disser sobre o autor. null = não existe (ou não é denunciável).
 * Devolve ['autor_tipo' => 'aluno'|'prof', 'autor_id' => int, 'trecho' => string, 'midia' => string, 'contexto' => string].
 */
function intusModAlvo(PDO $pdo, string $tipo, int $id, int $denunciante = 0): ?array
{
    if ($id <= 0 || !in_array($tipo, intusModTipos(), true)) return null;
    try {
        if ($tipo === 'feed_post') {
            $st = $pdo->prepare("SELECT idatleta, legenda, imagem FROM intus_feed_post WHERE idpost = ? AND ativo = 1");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return ['autor_tipo' => 'aluno', 'autor_id' => (int)$r['idatleta'], 'trecho' => _intusModLimpar((string)$r['legenda'], 600), 'midia' => _intusModLimpar((string)$r['imagem'], 500), 'contexto' => 'post ' . $id];
        }
        if ($tipo === 'comentario') {
            $st = $pdo->prepare("SELECT autor_tipo, autor_id, texto, alvo_tipo, alvo_id FROM intus_comentario_pub WHERE idcomentario = ?");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return ['autor_tipo' => $r['autor_tipo'] === 'prof' ? 'prof' : 'aluno', 'autor_id' => (int)$r['autor_id'], 'trecho' => _intusModLimpar((string)$r['texto'], 600), 'midia' => '', 'contexto' => _intusModLimpar($r['alvo_tipo'] . ' ' . (int)$r['alvo_id'], 60)];
        }
        if ($tipo === 'mural') {
            $st = $pdo->prepare("SELECT idatleta, texto FROM intus_ranking_comentario WHERE idcomentario = ?");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return ['autor_tipo' => 'aluno', 'autor_id' => (int)$r['idatleta'], 'trecho' => _intusModLimpar((string)$r['texto'], 600), 'midia' => '', 'contexto' => 'mural do ranking'];
        }
        if ($tipo === 'resultado') {
            $st = $pdo->prepare("SELECT idatleta, texto FROM intus_resultado WHERE idresultado = ? AND ativo = 1 AND idatleta > 0");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            return ['autor_tipo' => 'aluno', 'autor_id' => (int)$r['idatleta'], 'trecho' => _intusModLimpar((string)$r['texto'], 600), 'midia' => '', 'contexto' => 'quadro de resultados'];
        }
        if ($tipo === 'dm') {
            // Só quem RECEBEU a mensagem a denuncia (a mensagem de quem denuncia não é "do outro").
            $st = $pdo->prepare("SELECT iddm, de_id, para_id, texto FROM intus_dm WHERE iddm = ?");
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || ($denunciante > 0 && (int)$r['para_id'] !== $denunciante)) return null;
            // contexto: as 3 mensagens anteriores da conversa, para a equipe entender o que aconteceu
            $c = $pdo->prepare("SELECT de_id, texto FROM intus_dm WHERE ((de_id = ? AND para_id = ?) OR (de_id = ? AND para_id = ?)) AND iddm <= ? ORDER BY iddm DESC LIMIT 4");
            $c->execute([(int)$r['de_id'], (int)$r['para_id'], (int)$r['para_id'], (int)$r['de_id'], $id]);
            $linhas = array_reverse($c->fetchAll(PDO::FETCH_ASSOC));
            $trecho = '';
            foreach ($linhas as $l) $trecho .= (((int)$l['de_id'] === (int)$r['de_id']) ? 'Autor: ' : 'Quem denunciou: ') . _intusModLimpar((string)$l['texto'], 200) . "\n";
            return ['autor_tipo' => 'aluno', 'autor_id' => (int)$r['de_id'], 'trecho' => _intusModLimpar($trecho, 600), 'midia' => '', 'contexto' => 'mensagem direta'];
        }
        if ($tipo === 'perfil') {
            $nomes = intusModNomes($pdo, [$id]);
            if (!isset($nomes[$id])) return null;
            return ['autor_tipo' => 'aluno', 'autor_id' => $id, 'trecho' => _intusModLimpar($nomes[$id], 200), 'midia' => '', 'contexto' => 'perfil'];
        }
    } catch (Throwable $e) { /* cai no null abaixo */ }
    return null;
}

/**
 * Registra a denúncia. Devolve ['ok' => true, 'id' => n, 'ja' => bool, 'autor_tipo' => ..., 'autor_id' => ..., 'trecho' => ...]
 * ou ['erro' => 'invalido'|'motivo'|'proprio'|'nao_encontrado'|'limite'|'banco'].
 * Denunciar duas vezes a mesma coisa não cria outra linha (devolve a que existe com ja = true). Aluno só denuncia o que enxerga: o alvo é
 * conferido aqui e a denúncia do aluno nunca vira ação sozinha (quem decide é a equipe).
 */
function intusModDenunciar(PDO $pdo, int $denunciante, string $tipo, int $id, string $motivo, string $detalhe = '', ?string $agora = null): array
{
    if ($denunciante <= 0 || $id <= 0 || !in_array($tipo, intusModTipos(), true)) return ['erro' => 'invalido'];
    if (!isset(intusModMotivos()[$motivo])) return ['erro' => 'motivo'];
    if (!intusModGarantirTabelas($pdo)) return ['erro' => 'banco'];
    $alvo = intusModAlvo($pdo, $tipo, $id, $denunciante);
    if (!$alvo) return ['erro' => 'nao_encontrado'];
    if ($alvo['autor_tipo'] === 'aluno' && $alvo['autor_id'] === $denunciante) return ['erro' => 'proprio'];
    $agora = $agora ?? intusModAgora();
    try {
        $st = $pdo->prepare("SELECT iddenuncia FROM intus_denuncia WHERE denunciante_id = ? AND alvo_tipo = ? AND alvo_id = ?");
        $st->execute([$denunciante, $tipo, $id]);
        $ja = $st->fetchColumn();
        if ($ja) return ['ok' => true, 'id' => (int)$ja, 'ja' => true, 'autor_tipo' => $alvo['autor_tipo'], 'autor_id' => $alvo['autor_id'], 'trecho' => $alvo['trecho']];
        $desde = DateTime::createFromFormat('Y-m-d H:i:s', $agora, new DateTimeZone('America/Sao_Paulo'));
        $desde = $desde->modify('-1 day')->format('Y-m-d H:i:s');
        $q = $pdo->prepare("SELECT COUNT(*) FROM intus_denuncia WHERE denunciante_id = ? AND criado_em > ?");
        $q->execute([$denunciante, $desde]);
        if ((int)$q->fetchColumn() >= INTUS_MOD_DENUNCIAS_DIA) return ['erro' => 'limite'];
        $pdo->prepare("INSERT INTO intus_denuncia (denunciante_id, alvo_tipo, alvo_id, autor_tipo, autor_id, motivo, detalhe, trecho, midia, contexto, status, criado_em)
                       VALUES (?,?,?,?,?,?,?,?,?,?, 'aberta', ?)")
            ->execute([$denunciante, $tipo, $id, $alvo['autor_tipo'], $alvo['autor_id'], $motivo, _intusModLimpar($detalhe, 500) ?: null,
                       $alvo['trecho'] !== '' ? $alvo['trecho'] : null, $alvo['midia'] !== '' ? $alvo['midia'] : null, $alvo['contexto'], $agora]);
        return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'ja' => false, 'autor_tipo' => $alvo['autor_tipo'], 'autor_id' => $alvo['autor_id'], 'trecho' => $alvo['trecho']];
    } catch (Throwable $e) { return ['erro' => 'banco']; }
}

/** Resumo para o aviso da equipe: abertas, quantas estouraram o prazo de resposta e há quantas horas a mais antiga espera. */
function intusModResumo(PDO $pdo, ?string $agora = null): array
{
    $r = ['abertas' => 0, 'atrasadas' => 0, 'mais_antiga_horas' => 0, 'suspensos' => 0, 'prazo_horas' => INTUS_MOD_SLA_HORAS];
    $agora = $agora ?? intusModAgora();
    try {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $rows = $pdo->query("SELECT criado_em FROM intus_denuncia WHERE status = 'aberta'")->fetchAll(PDO::FETCH_COLUMN);
        $now = DateTime::createFromFormat('Y-m-d H:i:s', $agora, $tz)->getTimestamp();
        foreach ($rows as $c) {
            $d = DateTime::createFromFormat('Y-m-d H:i:s', (string)$c, $tz);
            if (!$d) continue;
            $h = (int)floor(max(0, $now - $d->getTimestamp()) / 3600);
            $r['abertas']++;
            if ($h >= INTUS_MOD_SLA_HORAS) $r['atrasadas']++;
            if ($h > $r['mais_antiga_horas']) $r['mais_antiga_horas'] = $h;
        }
    } catch (Throwable $e) { /* sem tabela = sem denúncia */ }
    $r['suspensos'] = count(intusModSuspensoes($pdo, $agora));
    return $r;
}

/**
 * Lista para a equipe. $status = 'aberta' | 'resolvida' | 'todas'. Cada linha traz nome de quem denunciou e de quem escreveu, quantas
 * denúncias o mesmo conteúdo tem, se o conteúdo ainda existe, há quantas horas espera e se o autor já está suspenso.
 */
function intusModListar(PDO $pdo, string $status = 'aberta', int $limite = 100, ?string $agora = null): array
{
    $limite = max(1, min(300, $limite));
    $agora = $agora ?? intusModAgora();
    $onde = $status === 'resolvida' ? "WHERE status = 'resolvida'" : ($status === 'todas' ? '' : "WHERE status = 'aberta'");
    $ordem = $status === 'resolvida' ? 'resolvido_em DESC, iddenuncia DESC' : 'criado_em ASC, iddenuncia ASC';
    try {
        $rows = $pdo->query("SELECT * FROM intus_denuncia $onde ORDER BY $ordem LIMIT $limite")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $tz = new DateTimeZone('America/Sao_Paulo');
    $now = DateTime::createFromFormat('Y-m-d H:i:s', $agora, $tz)->getTimestamp();
    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['denunciante_id']; if ($r['autor_tipo'] === 'aluno') $ids[] = (int)$r['autor_id']; }
    $nomes = intusModNomes($pdo, $ids);
    $motivos = intusModMotivos();
    $out = [];
    foreach ($rows as $r) {
        $c = DateTime::createFromFormat('Y-m-d H:i:s', (string)$r['criado_em'], $tz);
        $horas = $c ? (int)floor(max(0, $now - $c->getTimestamp()) / 3600) : 0;
        $q = $pdo->prepare("SELECT COUNT(*) FROM intus_denuncia WHERE alvo_tipo = ? AND alvo_id = ?");
        $q->execute([$r['alvo_tipo'], (int)$r['alvo_id']]);
        $susp = $r['autor_tipo'] === 'aluno' ? intusModSuspensaoAtiva($pdo, (int)$r['autor_id'], $agora) : null;
        $out[] = [
            'id' => (int)$r['iddenuncia'], 'status' => $r['status'], 'acao' => (string)($r['acao'] ?? ''), 'nota_equipe' => (string)($r['nota_equipe'] ?? ''),
            'alvo_tipo' => $r['alvo_tipo'], 'alvo_id' => (int)$r['alvo_id'], 'contexto' => (string)($r['contexto'] ?? ''),
            'motivo' => $r['motivo'], 'motivo_rotulo' => $motivos[$r['motivo']] ?? $r['motivo'], 'detalhe' => (string)($r['detalhe'] ?? ''),
            'trecho' => (string)($r['trecho'] ?? ''), 'midia' => (string)($r['midia'] ?? ''),
            'denunciante_id' => (int)$r['denunciante_id'], 'denunciante_nome' => $nomes[(int)$r['denunciante_id']] ?? ('Aluno #' . (int)$r['denunciante_id']),
            'autor_tipo' => $r['autor_tipo'], 'autor_id' => (int)$r['autor_id'],
            'autor_nome' => $r['autor_tipo'] === 'aluno' ? ($nomes[(int)$r['autor_id']] ?? ('Aluno #' . (int)$r['autor_id'])) : 'Equipe',
            'denuncias_no_alvo' => (int)$q->fetchColumn(),
            'conteudo_existe' => intusModAlvo($pdo, $r['alvo_tipo'], (int)$r['alvo_id'], 0) !== null,
            'horas' => $horas, 'atrasada' => $r['status'] === 'aberta' && $horas >= INTUS_MOD_SLA_HORAS,
            'autor_suspenso' => $susp ? ['ate' => $susp['ate']] : null,
            'criado_em' => $r['criado_em'], 'resolvido_em' => $r['resolvido_em'] ?? null,
        ];
    }
    return $out;
}

// ───────────────────────── ação da equipe ─────────────────────────

/** Dono (aluno) de um alvo de curtida ou comentário: post, mural, resultado ou comentário. 0 = não existe ou não é de aluno. */
function intusModDonoDoAlvo(PDO $pdo, string $tipo, int $id): int
{
    if ($id <= 0) return 0;
    try {
        if ($tipo === 'feed_post') { $s = $pdo->prepare("SELECT idatleta FROM intus_feed_post WHERE idpost = ? AND ativo = 1"); }
        elseif ($tipo === 'mural') { $s = $pdo->prepare("SELECT idatleta FROM intus_ranking_comentario WHERE idcomentario = ?"); }
        elseif ($tipo === 'resultado') { $s = $pdo->prepare("SELECT idatleta FROM intus_resultado WHERE idresultado = ? AND ativo = 1"); }
        elseif ($tipo === 'feed_comentario' || $tipo === 'comentario') { $s = $pdo->prepare("SELECT autor_id FROM intus_comentario_pub WHERE idcomentario = ? AND autor_tipo = 'aluno'"); }
        else return 0;
        $s->execute([$id]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/**
 * Tira o conteúdo do ar (ação da equipe, pelo painel). Post e resultado ficam desativados (ativo = 0, como no "excluir" do próprio dono);
 * comentário leva as respostas; mensagem direta e comentário do mural são apagados; em perfil, remove a foto. Vídeo no Drive é apagado se a
 * função do Drive estiver carregada (catalogo.php). Devolve true se achou o que remover.
 */
function intusModRemoverConteudo(PDO $pdo, string $tipo, int $id): bool
{
    if ($id <= 0) return false;
    $apagarVideos = function (string $donoTipo) use ($pdo, $id) {
        try {
            $sv = $pdo->prepare("SELECT idmidia, drive_id FROM intus_midia WHERE dono_tipo = ? AND dono_id = ? AND tipo = 'video' AND drive_id IS NOT NULL");
            $sv->execute([$donoTipo, $id]);
            foreach ($sv->fetchAll(PDO::FETCH_ASSOC) as $vv) {
                $ok = false;
                try { if (function_exists('gdriveExcluirArquivo')) $ok = gdriveExcluirArquivo($vv['drive_id']); } catch (Throwable $e) {}
                if ($ok) $pdo->prepare("UPDATE intus_midia SET status = 'apagado', drive_id = NULL WHERE idmidia = ?")->execute([(int)$vv['idmidia']]);
            }
        } catch (Throwable $e) { /* sem vídeo ou sem tabela */ }
    };
    $limpar = function (array $sqls) use ($pdo) {
        foreach ($sqls as [$sql, $args]) { try { $pdo->prepare($sql)->execute($args); } catch (Throwable $e) {} }
    };
    try {
        if ($tipo === 'feed_post') {
            $u = $pdo->prepare("UPDATE intus_feed_post SET ativo = 0 WHERE idpost = ? AND ativo > 0");
            $u->execute([$id]);
            if ($u->rowCount() < 1) return false;
            $apagarVideos('feed_post');
            $limpar([["DELETE FROM intus_notificacao WHERE alvo_tipo = 'feed_post' AND alvo_id = ?", [$id]], ["DELETE FROM intus_post_colab WHERE idpost = ?", [$id]]]);
            return true;
        }
        if ($tipo === 'resultado') {
            $u = $pdo->prepare("UPDATE intus_resultado SET ativo = 0, destaque_site = 0 WHERE idresultado = ? AND ativo > 0");
            $u->execute([$id]);
            if ($u->rowCount() < 1) return false;
            $apagarVideos('resultado');
            $limpar([["DELETE FROM intus_notificacao WHERE alvo_tipo = 'resultado' AND alvo_id = ?", [$id]], ["DELETE FROM intus_reacao_pub WHERE alvo_tipo = 'resultado' AND alvo_id = ?", [$id]]]);
            return true;
        }
        if ($tipo === 'comentario') {
            $ids = [$id]; $fronteira = [$id];
            for ($n = 0; $n < 30 && $fronteira; $n++) {
                $ph = implode(',', array_fill(0, count($fronteira), '?'));
                $s = $pdo->prepare("SELECT idcomentario FROM intus_comentario_pub WHERE resposta_a IN ($ph)");
                $s->execute($fronteira);
                $filhos = array_values(array_diff(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)), $ids));
                $ids = array_merge($ids, $filhos);
                $fronteira = $filhos;
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $d = $pdo->prepare("DELETE FROM intus_comentario_pub WHERE idcomentario IN ($ph)");
            $d->execute($ids);
            $limpar([["DELETE FROM intus_reacao_pub WHERE alvo_tipo = 'feed_comentario' AND alvo_id IN ($ph)", $ids]]);
            return $d->rowCount() > 0;
        }
        if ($tipo === 'mural') {
            $d = $pdo->prepare("DELETE FROM intus_ranking_comentario WHERE idcomentario = ?");
            $d->execute([$id]);
            if ($d->rowCount() < 1) return false;
            $limpar([["DELETE FROM intus_notificacao WHERE alvo_tipo = 'mural' AND alvo_id = ?", [$id]], ["DELETE FROM intus_reacao_pub WHERE alvo_tipo = 'mural' AND alvo_id = ?", [$id]],
                     ["DELETE FROM intus_comentario_pub WHERE alvo_tipo = 'mural' AND alvo_id = ?", [$id]]]);
            return true;
        }
        if ($tipo === 'dm') {
            $d = $pdo->prepare("DELETE FROM intus_dm WHERE iddm = ?");
            $d->execute([$id]);
            return $d->rowCount() > 0;
        }
        if ($tipo === 'perfil') {
            $u = $pdo->prepare("UPDATE intus_profile SET avatar = NULL WHERE idatleta = ?");
            $u->execute([$id]);
            return $u->rowCount() > 0;
        }
    } catch (Throwable $e) { return false; }
    return false;
}

/**
 * A equipe decide sobre uma denúncia. $acoes: qualquer combinação de 'remover' (tira o conteúdo do ar), 'advertir' (o app avisa o autor) e
 * 'suspender' (autor aluno fica $dias dias sem publicar, comentar nem mandar mensagem; 0 = sem prazo). Lista vazia = arquivar (sem violação).
 * Vale para TODAS as denúncias abertas do mesmo conteúdo. Idempotente: denúncia já resolvida devolve ja = true sem refazer nada.
 * Devolve ['ok' => true, 'acoes' => [...], 'removido' => bool, 'autor_tipo' => ..., 'autor_id' => ..., 'denunciantes' => [ids], 'suspensao' => [...]|null]
 * ou ['erro' => 'nao_encontrada'|'acao'|'dias'|'autor_equipe'|'banco'].
 */
function intusModResolver(PDO $pdo, int $id, array $acoes, int $porId, string $nota = '', int $dias = 7, ?string $agora = null): array
{
    $validas = ['remover', 'advertir', 'suspender'];
    $acoes = array_values(array_unique(array_map('strval', $acoes)));
    foreach ($acoes as $a) if (!in_array($a, $validas, true)) return ['erro' => 'acao'];
    if (!intusModGarantirTabelas($pdo)) return ['erro' => 'banco'];
    $agora = $agora ?? intusModAgora();
    try {
        $st = $pdo->prepare("SELECT * FROM intus_denuncia WHERE iddenuncia = ?");
        $st->execute([$id]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ['erro' => 'banco']; }
    if (!$d) return ['erro' => 'nao_encontrada'];
    if ($d['status'] === 'resolvida') return ['ok' => true, 'ja' => true, 'acoes' => array_values(array_filter(explode(',', (string)$d['acao']))), 'autor_tipo' => $d['autor_tipo'], 'autor_id' => (int)$d['autor_id']];
    if (in_array('suspender', $acoes, true) && ($d['autor_tipo'] !== 'aluno' || (int)$d['autor_id'] <= 0)) return ['erro' => 'autor_equipe'];
    if (in_array('suspender', $acoes, true) && ($dias < 0 || $dias > 365)) return ['erro' => 'dias'];
    $removido = false; $susp = null;
    if (in_array('remover', $acoes, true)) $removido = intusModRemoverConteudo($pdo, (string)$d['alvo_tipo'], (int)$d['alvo_id']);
    if (in_array('suspender', $acoes, true)) {
        $susp = intusModSuspender($pdo, (int)$d['autor_id'], $dias, 'Denúncia #' . $id . ' (' . (intusModMotivos()[$d['motivo']] ?? $d['motivo']) . ')', $porId, $id, $agora);
        if (isset($susp['erro'])) return ['erro' => $susp['erro']];
    }
    try {
        $q = $pdo->prepare("SELECT denunciante_id FROM intus_denuncia WHERE alvo_tipo = ? AND alvo_id = ? AND status = 'aberta'");
        $q->execute([$d['alvo_tipo'], (int)$d['alvo_id']]);
        $denunciantes = array_values(array_unique(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))));
        $pdo->prepare("UPDATE intus_denuncia SET status = 'resolvida', acao = ?, nota_equipe = ?, resolvido_por = ?, resolvido_em = ? WHERE alvo_tipo = ? AND alvo_id = ? AND status = 'aberta'")
            ->execute([$acoes ? implode(',', $acoes) : 'nenhuma', _intusModLimpar($nota, 500) ?: null, $porId > 0 ? $porId : null, $agora, $d['alvo_tipo'], (int)$d['alvo_id']]);
    } catch (Throwable $e) { return ['erro' => 'banco']; }
    return ['ok' => true, 'ja' => false, 'acoes' => $acoes, 'removido' => $removido, 'autor_tipo' => $d['autor_tipo'], 'autor_id' => (int)$d['autor_id'], 'denunciantes' => $denunciantes, 'suspensao' => $susp];
}
