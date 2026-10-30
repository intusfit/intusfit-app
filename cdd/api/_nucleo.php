<?php
/**
 * CDD (Código da Definição): núcleo da API.
 *
 * Sistema próprio do CDD, separado da Intus: tabelas `cdd_*`, sessão própria, nenhum arquivo da Intus é editado.
 * As únicas ligações com a Intus são LEITURAS: as credenciais do banco em app/config/db.php (mesmo banco da hospedagem) e, só quando a equipe
 * clica em "Sincronizar com a Intus", um SELECT no catálogo `intus_exercicio` (cddSincronizarIntus). Nada da Intus é gravado.
 * Biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto.
 *
 * Toda a regra mora em cddTratar() para poder ser testada sem servidor web (cdd/testes/cdd.php).
 * As tabelas são só aditivas (CREATE TABLE IF NOT EXISTS). Datas em PHP (sem NOW()) para o teste rodar em SQLite.
 */

const CDD_EMAIL_INSTALADOR = 'contato@luiznunest.com.br';   // só este e-mail pode criar o primeiro administrador
const CDD_TERMOS_VERSAO = '2026-10-rascunho';
const CDD_INTUS_URL = 'https://intusfit.com.br';
const CDD_ESQUEMA = 7;   // 1 = MVP, 2 = base de exercícios, 3 = liberação, treino, comentários e avaliações, 4 = anamnese, conquistas, capas e ranking, 5 = cardio, água e refeições, 6 = comercial (ofertas, cupons, pagamentos), 7 = leads
require_once __DIR__ . '/_treino.php';
require_once __DIR__ . '/_app.php';
require_once __DIR__ . '/_bemestar.php';
require_once __DIR__ . '/_comercial.php';
require_once __DIR__ . '/_sso.php';
require_once __DIR__ . '/_leads.php';

/* ───────────────────────── Banco ───────────────────────── */

function cddDB(): PDO {
    if (!empty($GLOBALS['CDD_PDO'])) return $GLOBALS['CDD_PDO'];
    static $pdo = null;
    if ($pdo) return $pdo;
    // Servidor próprio do CDD: config/banco.php (fora do git) define CDD_DB_HOST, CDD_DB_NAME, CDD_DB_USER e CDD_DB_PASS.
    // Sem esse arquivo (hospedagem compartilhada com a Intus), usa só as constantes do banco da Intus em app/config/db.php.
    $proprio = __DIR__ . '/config/banco.php';
    if (is_file($proprio)) {
        require_once $proprio;
        $h = CDD_DB_HOST; $n = CDD_DB_NAME; $u = CDD_DB_USER; $pw = CDD_DB_PASS;
    } else {
        require_once __DIR__ . '/../../app/config/db.php';   // só define as constantes DB_*; a conexão abaixo é do CDD
        $h = DB_HOST; $n = DB_NAME; $u = DB_USER; $pw = DB_PASS;
    }
    $dsn = 'mysql:host=' . $h . ';dbname=' . $n . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $u, $pw, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function cddAgora(): string { return date('Y-m-d H:i:s'); }
function cddHoje(): string { return date('Y-m-d'); }

/** DDL do CDD. Cada item é uma tabela; só cria se não existir. */
function cddDdl(): array {
    $c = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    return [
        'cdd_config' => "CREATE TABLE IF NOT EXISTS cdd_config (
            chave VARCHAR(40) NOT NULL PRIMARY KEY,
            valor VARCHAR(255) NOT NULL DEFAULT ''
        ) $c",
        'cdd_aluna' => "CREATE TABLE IF NOT EXISTS cdd_aluna (
            idaluna INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(160) NOT NULL,
            email VARCHAR(190) NOT NULL,
            senha_hash VARCHAR(255) NULL,
            sexo VARCHAR(12) NOT NULL DEFAULT 'feminino',
            papel VARCHAR(12) NOT NULL DEFAULT 'aluna',
            status VARCHAR(12) NOT NULL DEFAULT 'ativa',
            ranking_visivel TINYINT NOT NULL DEFAULT 1,
            termos_versao VARCHAR(40) NULL,
            termos_em DATETIME NULL,
            criado_em DATETIME NOT NULL,
            ultimo_acesso DATETIME NULL,
            UNIQUE INDEX idx_email (email)
        ) $c",
        'cdd_sessao' => "CREATE TABLE IF NOT EXISTS cdd_sessao (
            idsessao INT AUTO_INCREMENT PRIMARY KEY,
            token_hash VARCHAR(64) NOT NULL,
            idaluna INT NOT NULL,
            criado_em DATETIME NOT NULL,
            expira_em DATETIME NOT NULL,
            ultimo_uso DATETIME NULL,
            ip VARCHAR(45) NULL,
            UNIQUE INDEX idx_token (token_hash),
            INDEX idx_aluna (idaluna)
        ) $c",
        'cdd_tentativa' => "CREATE TABLE IF NOT EXISTS cdd_tentativa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chave VARCHAR(64) NOT NULL,
            acao VARCHAR(30) NOT NULL,
            em DATETIME NOT NULL,
            INDEX idx_chave (chave, acao, em)
        ) $c",
        'cdd_ativacao' => "CREATE TABLE IF NOT EXISTS cdd_ativacao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            idaluna INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            criado_em DATETIME NOT NULL,
            expira_em DATETIME NOT NULL,
            usado_em DATETIME NULL,
            UNIQUE INDEX idx_token (token_hash),
            INDEX idx_aluna (idaluna)
        ) $c",
        'cdd_matricula' => "CREATE TABLE IF NOT EXISTS cdd_matricula (
            idmatricula INT AUTO_INCREMENT PRIMARY KEY,
            idaluna INT NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'ativa',
            origem VARCHAR(20) NOT NULL DEFAULT 'manual',
            ref_externa VARCHAR(80) NULL,
            inicio DATE NULL,
            expira_em DATE NULL,
            obs VARCHAR(300) NULL,
            criado_em DATETIME NOT NULL,
            UNIQUE INDEX idx_aluna (idaluna)
        ) $c",
        'cdd_evento' => "CREATE TABLE IF NOT EXISTS cdd_evento (
            idevento BIGINT AUTO_INCREMENT PRIMARY KEY,
            idaluna INT NULL,
            tipo VARCHAR(30) NOT NULL,
            origem VARCHAR(20) NOT NULL,
            id_externo VARCHAR(120) NULL,
            payload TEXT NULL,
            resultado VARCHAR(20) NULL,
            criado_em DATETIME NOT NULL,
            UNIQUE INDEX idx_externo (id_externo),
            INDEX idx_aluna (idaluna)
        ) $c",
        'cdd_vinculo_intus' => "CREATE TABLE IF NOT EXISTS cdd_vinculo_intus (
            idaluna INT NOT NULL PRIMARY KEY,
            idatleta INT NOT NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_atleta (idatleta)
        ) $c",
        'cdd_modulo' => "CREATE TABLE IF NOT EXISTS cdd_modulo (
            idmodulo INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(200) NOT NULL,
            descricao VARCHAR(600) NULL,
            grupo VARCHAR(20) NOT NULL DEFAULT 'curso',
            ordem INT NOT NULL DEFAULT 0,
            publicado TINYINT NOT NULL DEFAULT 1,
            liberacao_dias INT NULL,
            liberacao_data DATE NULL,
            emoji VARCHAR(8) NULL,
            cor VARCHAR(9) NULL,
            capa_url VARCHAR(500) NULL
        ) $c",
        'cdd_aula' => "CREATE TABLE IF NOT EXISTS cdd_aula (
            idaula INT AUTO_INCREMENT PRIMARY KEY,
            idmodulo INT NOT NULL,
            titulo VARCHAR(250) NOT NULL,
            tipo VARCHAR(10) NOT NULL DEFAULT 'video',
            youtube_id VARCHAR(20) NULL,
            texto TEXT NULL,
            ordem INT NOT NULL DEFAULT 0,
            publicado TINYINT NOT NULL DEFAULT 1,
            INDEX idx_modulo (idmodulo, ordem)
        ) $c",
        'cdd_anexo' => "CREATE TABLE IF NOT EXISTS cdd_anexo (
            idanexo INT AUTO_INCREMENT PRIMARY KEY,
            idaula INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            url VARCHAR(500) NOT NULL,
            ordem INT NOT NULL DEFAULT 0,
            INDEX idx_aula (idaula)
        ) $c",
        'cdd_ficha_item' => "CREATE TABLE IF NOT EXISTS cdd_ficha_item (
            iditem INT AUTO_INCREMENT PRIMARY KEY,
            idaula INT NOT NULL,
            ordem INT NOT NULL DEFAULT 0,
            grupo VARCHAR(80) NULL,
            exercicio VARCHAR(200) NOT NULL,
            series VARCHAR(30) NULL,
            repeticoes VARCHAR(40) NULL,
            intervalo VARCHAR(40) NULL,
            metodo VARCHAR(200) NULL,
            metodo_tipo VARCHAR(10) NULL,
            video VARCHAR(500) NULL,
            obs VARCHAR(500) NULL,
            INDEX idx_aula (idaula, ordem)
        ) $c",
        'cdd_exercicio' => "CREATE TABLE IF NOT EXISTS cdd_exercicio (
            idexercicio INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            nome_norm VARCHAR(200) NOT NULL,
            grupo VARCHAR(120) NULL,
            equipamento VARCHAR(40) NULL,
            descricao TEXT NULL,
            video VARCHAR(500) NULL,
            origem VARCHAR(10) NOT NULL DEFAULT 'cdd',
            id_intus INT NULL,
            atualizado_em DATETIME NOT NULL,
            UNIQUE INDEX idx_norm (nome_norm),
            INDEX idx_intus (id_intus)
        ) $c",
        'cdd_treino' => "CREATE TABLE IF NOT EXISTS cdd_treino (
            idtreino INT AUTO_INCREMENT PRIMARY KEY,
            idaluna INT NOT NULL,
            idaula INT NOT NULL,
            divisao VARCHAR(80) NULL,
            data DATE NOT NULL,
            duracao_seg INT NOT NULL DEFAULT 0,
            esforco TINYINT NULL,
            comentario VARCHAR(600) NULL,
            itens TEXT NULL,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL,
            UNIQUE INDEX idx_dia (idaluna, idaula, data),
            INDEX idx_aluna_data (idaluna, data)
        ) $c",
        'cdd_comentario' => "CREATE TABLE IF NOT EXISTS cdd_comentario (
            idcomentario INT AUTO_INCREMENT PRIMARY KEY,
            idaula INT NOT NULL,
            idaluna INT NOT NULL,
            parent_id INT NULL,
            texto VARCHAR(1000) NOT NULL,
            oculto TINYINT NOT NULL DEFAULT 0,
            equipe TINYINT NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL,
            INDEX idx_aula (idaula, criado_em),
            INDEX idx_parent (parent_id)
        ) $c",
        'cdd_avaliacao' => "CREATE TABLE IF NOT EXISTS cdd_avaliacao (
            idaluna INT NOT NULL,
            idaula INT NOT NULL,
            nota TINYINT NOT NULL,
            atualizado_em DATETIME NOT NULL,
            PRIMARY KEY (idaluna, idaula)
        ) $c",
        'cdd_anamnese' => "CREATE TABLE IF NOT EXISTS cdd_anamnese (
            idaluna INT NOT NULL PRIMARY KEY,
            respostas TEXT NULL,
            enviado_em DATETIME NULL,
            atualizado_em DATETIME NOT NULL
        ) $c",
        'cdd_conquista' => "CREATE TABLE IF NOT EXISTS cdd_conquista (
            idaluna INT NOT NULL,
            chave VARCHAR(30) NOT NULL,
            conquistada_em DATETIME NOT NULL,
            PRIMARY KEY (idaluna, chave)
        ) $c",
        'cdd_progresso' => "CREATE TABLE IF NOT EXISTS cdd_progresso (
            idaluna INT NOT NULL,
            idaula INT NOT NULL,
            concluida_em DATETIME NOT NULL,
            PRIMARY KEY (idaluna, idaula)
        ) $c",
        'cdd_cardio' => "CREATE TABLE IF NOT EXISTS cdd_cardio (
            idcardio INT AUTO_INCREMENT PRIMARY KEY,
            idaluna INT NOT NULL,
            data DATE NOT NULL,
            atividade VARCHAR(20) NOT NULL,
            minutos INT NOT NULL,
            esforco TINYINT NOT NULL,
            comentario VARCHAR(300) NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_aluna_data (idaluna, data)
        ) $c",
        'cdd_agua' => "CREATE TABLE IF NOT EXISTS cdd_agua (
            idaluna INT NOT NULL,
            data DATE NOT NULL,
            ml INT NOT NULL,
            PRIMARY KEY (idaluna, data)
        ) $c",
        'cdd_refeicao' => "CREATE TABLE IF NOT EXISTS cdd_refeicao (
            idaluna INT NOT NULL,
            data DATE NOT NULL,
            refeicao VARCHAR(20) NOT NULL,
            status VARCHAR(10) NOT NULL,
            PRIMARY KEY (idaluna, data, refeicao)
        ) $c",
    ] + cddComercialDdl($c) + cddLeadsDdl($c);
}

function cddGarantirTabelas(PDO $pdo): void {
    static $feito = [];
    $k = spl_object_id($pdo);
    if (!empty($feito[$k])) return;
    $primeira = false; $versao = 0;
    try {
        $v = $pdo->query("SELECT valor FROM cdd_config WHERE chave = 'esquema'")->fetchColumn();
        $versao = $v === false ? 1 : (int)$v;   // instalação anterior à versão 2 não tinha a chave
    } catch (Throwable $e) { $primeira = true; }
    if ($primeira || $versao < CDD_ESQUEMA) {
        // Só CREATE TABLE IF NOT EXISTS: cria o que falta, nunca altera nem apaga tabela existente.
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        foreach (cddDdl() as $sql) $pdo->exec($sqlite ? cddDdlSqlite($sql) : $sql);
        if ($primeira) {
            $st = $pdo->prepare('INSERT INTO cdd_config (chave, valor) VALUES (?, ?)');
            $st->execute(['instalacao_aberta', '1']);
            $st->execute(['cadastro_aberto', '1']);
        }
        // Colunas novas de tabelas do próprio CDD (só ADD COLUMN, nunca altera nem apaga dado existente).
        foreach ([['cdd_modulo', 'liberacao_dias', 'INT NULL'], ['cdd_modulo', 'liberacao_data', 'DATE NULL'], ['cdd_modulo', 'emoji', 'VARCHAR(8) NULL'],
                  ['cdd_modulo', 'cor', 'VARCHAR(9) NULL'], ['cdd_modulo', 'capa_url', 'VARCHAR(500) NULL'], ['cdd_aluna', 'ranking_visivel', 'TINYINT NOT NULL DEFAULT 1'],
                  ['cdd_ficha_item', 'metodo_tipo', 'VARCHAR(10) NULL']] as $c3) {
            try { $pdo->query("SELECT {$c3[1]} FROM {$c3[0]} LIMIT 1"); }
            catch (Throwable $e) { $pdo->exec("ALTER TABLE {$c3[0]} ADD COLUMN {$c3[1]} {$c3[2]}"); }
        }
        cddConfigGravar($pdo, 'esquema', (string)CDD_ESQUEMA);
    }
    $feito[$k] = true;
}

/** Só para o teste local em SQLite: traduz o DDL do MySQL. Nunca é usada em produção. */
function cddDdlSqlite(string $sql): string {
    $sql = preg_replace('/\b(?:BIGINT|INT) AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/\) ENGINE=.*$/s', ')', $sql);
    $sql = preg_replace('/,\s*(?:UNIQUE )?INDEX\s+\w+\s*\([^)]*\)/', '', $sql);
    $sql = preg_replace('/\b(?:TINYINT|VARCHAR\(\d+\)|DATETIME|DATE)\b/i', 'TEXT', $sql) ?: $sql;
    return $sql;
}

/* ───────────────────────── Utilidades ───────────────────────── */

function cddResp(int $status, array $dados = []): array { return [$status, $dados]; }
function cddErro(int $status, string $codigo, string $mensagem, array $extra = []): array {
    return [$status, array_merge(['error' => $codigo, 'mensagem' => $mensagem], $extra)];
}
function cddStr($v, int $max): string {
    $v = trim((string)$v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}
function cddEmail($v): string {
    $e = strtolower(trim((string)$v));
    return (strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : '';
}
function cddData($v): ?string {
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
/** Aceita o código de 11 caracteres ou qualquer link do YouTube. */
function cddYoutubeId($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^[\w-]{11}$/', $v)) return $v;
    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:embed/|shorts/|live/|watch\?(?:[^#]*&)?v=))([\w-]{11})~', $v, $m)) return $m[1];
    return null;
}
/** Só https. Evita javascript: e afins. */
function cddUrl($v): string {
    $v = trim((string)$v);
    if (strlen($v) > 500 || !preg_match('~^https://[^\s<>"\']+$~i', $v)) return '';
    return $v;
}
/** Capa de módulo: link https:// ou uma capa pronta do próprio app (marca/capas/arquivo.jpg, caminho relativo, sem pasta acima). */
function cddCapaUrl($v): string {
    $v = trim((string)$v);
    if (preg_match('~^marca/capas/[a-z0-9][a-z0-9-]{0,60}(-wide)?\.(jpg|jpeg|png|webp)$~i', $v)) return $v;
    return cddUrl($v);
}
function cddVideoCampo($v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    $y = cddYoutubeId($v);
    if ($y) return $y;
    return cddUrl($v);
}
function cddHash(string $t): string { return hash('sha256', $t); }

/* ───────────────────────── Trava de tentativas ───────────────────────── */

function cddTentativas(PDO $pdo, string $chave, string $acao, int $janela): int {
    $limite = date('Y-m-d H:i:s', time() - $janela);
    try {
        $pdo->prepare('DELETE FROM cdd_tentativa WHERE em < ?')->execute([$limite]);
        $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_tentativa WHERE chave = ? AND acao = ? AND em >= ?');
        $st->execute([cddHash($chave), $acao, $limite]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
function cddRegistrarTentativa(PDO $pdo, string $chave, string $acao): void {
    try { $pdo->prepare('INSERT INTO cdd_tentativa (chave, acao, em) VALUES (?, ?, ?)')->execute([cddHash($chave), $acao, cddAgora()]); } catch (Throwable $e) {}
}
function cddLimparTentativas(PDO $pdo, string $chave, string $acao): void {
    try { $pdo->prepare('DELETE FROM cdd_tentativa WHERE chave = ? AND acao = ?')->execute([cddHash($chave), $acao]); } catch (Throwable $e) {}
}
/** true se passou do limite (por IP e por conta). */
function cddBloqueado(PDO $pdo, string $acao, string $ip, string $conta, int $maxIp, int $maxConta, int $janela = 900): bool {
    if (cddTentativas($pdo, 'ip:' . $ip, $acao, $janela) >= $maxIp) return true;
    if ($conta !== '' && cddTentativas($pdo, 'c:' . strtolower($conta), $acao, $janela) >= $maxConta) return true;
    return false;
}

/* ───────────────────────── Sessão ───────────────────────── */

function cddCriarSessao(PDO $pdo, int $idaluna, string $ip, int $dias): string {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO cdd_sessao (token_hash, idaluna, criado_em, expira_em, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([cddHash($token), $idaluna, cddAgora(), date('Y-m-d H:i:s', time() + $dias * 86400), $ip]);
    try { $pdo->prepare('UPDATE cdd_aluna SET ultimo_acesso = ? WHERE idaluna = ?')->execute([cddAgora(), $idaluna]); } catch (Throwable $e) {}
    return $token;
}

/** Devolve a aluna da sessão (ativa) ou null. */
function cddSessao(PDO $pdo, string $token): ?array {
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;
    $st = $pdo->prepare('SELECT a.idaluna, a.nome, a.email, a.sexo, a.papel, a.status, s.expira_em
                         FROM cdd_sessao s JOIN cdd_aluna a ON a.idaluna = s.idaluna WHERE s.token_hash = ?');
    $st->execute([cddHash($token)]);
    $r = $st->fetch();
    if (!$r || $r['status'] !== 'ativa' || $r['expira_em'] < cddAgora()) return null;
    try { $pdo->prepare('UPDATE cdd_sessao SET ultimo_uso = ? WHERE token_hash = ?')->execute([cddAgora(), cddHash($token)]); } catch (Throwable $e) {}
    return $r;
}

/** A matrícula vale hoje? Devolve ['ativa'=>bool, 'status'=>..., 'expira_em'=>...] */
function cddMatricula(PDO $pdo, int $idaluna): array {
    $st = $pdo->prepare('SELECT status, origem, inicio, expira_em, obs FROM cdd_matricula WHERE idaluna = ?');
    $st->execute([$idaluna]);
    $m = $st->fetch();
    if (!$m) return ['existe' => false, 'ativa' => false, 'status' => 'sem_matricula', 'expira_em' => null, 'origem' => null];
    $vale = $m['status'] === 'ativa'
        && (empty($m['inicio']) || $m['inicio'] <= cddHoje())
        && (empty($m['expira_em']) || $m['expira_em'] >= cddHoje());
    $status = $m['status'];
    if ($m['status'] === 'ativa' && !$vale) $status = (!empty($m['expira_em']) && $m['expira_em'] < cddHoje()) ? 'expirada' : 'agendada';
    return ['existe' => true, 'ativa' => $vale, 'status' => $status, 'expira_em' => $m['expira_em'], 'inicio' => $m['inicio'], 'origem' => $m['origem']];
}

function cddNovaAtivacao(PDO $pdo, int $idaluna, string $base): array {
    $token = bin2hex(random_bytes(24));
    $pdo->prepare('UPDATE cdd_ativacao SET usado_em = ? WHERE idaluna = ? AND usado_em IS NULL')->execute([cddAgora(), $idaluna]);
    $expira = date('Y-m-d H:i:s', time() + 7 * 86400);
    $pdo->prepare('INSERT INTO cdd_ativacao (idaluna, token_hash, criado_em, expira_em) VALUES (?, ?, ?, ?)')
        ->execute([$idaluna, cddHash($token), cddAgora(), $expira]);
    return ['link' => rtrim($base, '/') . '/index.html#ativar=' . $token, 'expira_em' => $expira];
}

function cddSenhaValida(string $s): bool { return strlen($s) >= 8 && strlen($s) <= 200; }

function cddRegistrarEvento(PDO $pdo, ?int $idaluna, string $tipo, string $origem, string $resultado = 'ok', ?string $payload = null): void {
    try {
        $pdo->prepare('INSERT INTO cdd_evento (idaluna, tipo, origem, payload, resultado, criado_em) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$idaluna, $tipo, $origem, $payload, $resultado, cddAgora()]);
    } catch (Throwable $e) {}
}

function cddConfig(PDO $pdo, string $chave, string $padrao = ''): string {
    $st = $pdo->prepare('SELECT valor FROM cdd_config WHERE chave = ?');
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? $padrao : (string)$v;
}
function cddConfigGravar(PDO $pdo, string $chave, string $valor): void {
    $st = $pdo->prepare('UPDATE cdd_config SET valor = ? WHERE chave = ?');
    $st->execute([$valor, $chave]);
    if ($st->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM cdd_config WHERE chave = ?'); $chk->execute([$chave]);
        if ((int)$chk->fetchColumn() === 0) $pdo->prepare('INSERT INTO cdd_config (chave, valor) VALUES (?, ?)')->execute([$chave, $valor]);
    }
}

/* ───────────────────────── Conteúdo (leitura da aluna) ───────────────────────── */

function cddModulosParaAluna(PDO $pdo, int $idaluna, bool $incluirRascunho, ?string $inicio = null): array {
    $mods = $pdo->query('SELECT idmodulo, titulo, descricao, grupo, ordem, publicado, liberacao_dias, liberacao_data, emoji, cor, capa_url FROM cdd_modulo ORDER BY ordem, idmodulo')->fetchAll();
    $aulas = $pdo->query('SELECT idaula, idmodulo, titulo, tipo, youtube_id, ordem, publicado, (CASE WHEN youtube_id IS NULL OR youtube_id = \'\' THEN 0 ELSE 1 END) AS tem_video FROM cdd_aula ORDER BY ordem, idaula')->fetchAll();
    $comFicha = array_flip(array_map('intval', array_column($pdo->query('SELECT DISTINCT idaula FROM cdd_ficha_item')->fetchAll(), 'idaula')));
    $st = $pdo->prepare('SELECT idaula FROM cdd_progresso WHERE idaluna = ?'); $st->execute([$idaluna]);
    $feitas = array_flip(array_map('intval', array_column($st->fetchAll(), 'idaula')));
    $nota = []; $coment = []; $semResp = [];
    if ($incluirRascunho) {   // painel: nota média, comentários e pendências por aula
        foreach ($pdo->query('SELECT idaula, AVG(nota) AS m, COUNT(*) AS n FROM cdd_avaliacao GROUP BY idaula')->fetchAll() as $r) $nota[(int)$r['idaula']] = [round((float)$r['m'], 1), (int)$r['n']];
        foreach ($pdo->query('SELECT idaula, COUNT(*) AS n FROM cdd_comentario WHERE oculto = 0 GROUP BY idaula')->fetchAll() as $r) $coment[(int)$r['idaula']] = (int)$r['n'];
        foreach ($pdo->query('SELECT c.idaula, COUNT(*) AS n FROM cdd_comentario c WHERE c.parent_id IS NULL AND c.oculto = 0 AND c.equipe = 0 AND NOT EXISTS (SELECT 1 FROM cdd_comentario r WHERE r.parent_id = c.idcomentario AND r.equipe = 1 AND r.oculto = 0) GROUP BY c.idaula')->fetchAll() as $r) $semResp[(int)$r['idaula']] = (int)$r['n'];
    }
    $porMod = []; $thumb = [];
    foreach ($aulas as $a) {
        if (!$incluirRascunho && !(int)$a['publicado']) continue;
        $a['idaula'] = (int)$a['idaula']; $a['concluida'] = isset($feitas[$a['idaula']]); $a['tem_ficha'] = isset($comFicha[$a['idaula']]);
        $a['yt'] = $a['youtube_id'] ?: null; unset($a['youtube_id']);
        if (empty($thumb[(int)$a['idmodulo']]) && $a['yt']) $thumb[(int)$a['idmodulo']] = $a['yt'];
        if ($incluirRascunho) { $a['nota_media'] = $nota[$a['idaula']][0] ?? null; $a['votos'] = $nota[$a['idaula']][1] ?? 0; $a['comentarios'] = $coment[$a['idaula']] ?? 0; $a['sem_resposta'] = $semResp[$a['idaula']] ?? 0; }
        $porMod[(int)$a['idmodulo']][] = $a;
    }
    $out = [];
    foreach ($mods as $m) {
        if (!$incluirRascunho && !(int)$m['publicado']) continue;
        $lista = $porMod[(int)$m['idmodulo']] ?? [];
        if (!$lista && !$incluirRascunho) continue;
        $lib = cddModuloLiberacao($m, $inicio);
        $m['idmodulo'] = (int)$m['idmodulo'];
        $m['liberado'] = $incluirRascunho ? true : $lib['liberado'];
        $m['libera_em'] = $lib['libera_em'];
        $m['liberacao_dias'] = ($m['liberacao_dias'] === null || $m['liberacao_dias'] === '') ? null : (int)$m['liberacao_dias'];
        $m['liberacao_data'] = $m['liberacao_data'] ? substr((string)$m['liberacao_data'], 0, 10) : null;
        $m['thumb_yt'] = $thumb[$m['idmodulo']] ?? null;
        $m['total'] = count($lista);
        $m['feitas'] = count(array_filter($lista, function ($a) { return $a['concluida']; }));
        $m['aulas'] = (!$incluirRascunho && !$lib['liberado']) ? [] : $lista;   // módulo trancado: a aluna vê o nome e quando libera, não as aulas
        if (!$incluirRascunho && !$lib['liberado']) foreach (['thumb_yt'] as $k) $m[$k] = null;
        $out[] = $m;
    }
    return $out;
}

/** Aula completa. Para a aluna, módulo ainda não liberado volta ['_bloqueada' => true, 'libera_em' => ...]. */
function cddAulaCompleta(PDO $pdo, int $idaula, int $idaluna, bool $incluirRascunho, ?string $inicio = null): ?array {
    $st = $pdo->prepare('SELECT a.idaula, a.idmodulo, a.titulo, a.tipo, a.youtube_id, a.texto, a.ordem, a.publicado, m.titulo AS modulo, m.publicado AS mod_pub, m.liberacao_dias, m.liberacao_data
                         FROM cdd_aula a JOIN cdd_modulo m ON m.idmodulo = a.idmodulo WHERE a.idaula = ?');
    $st->execute([$idaula]);
    $a = $st->fetch();
    if (!$a) return null;
    if (!$incluirRascunho && (!(int)$a['publicado'] || !(int)$a['mod_pub'])) return null;
    if (!$incluirRascunho) {
        $lib = cddModuloLiberacao($a, $inicio);
        if (!$lib['liberado']) return ['_bloqueada' => true, 'libera_em' => $lib['libera_em'], 'modulo' => $a['modulo']];
    }
    unset($a['liberacao_dias'], $a['liberacao_data']);
    $an = $pdo->prepare('SELECT idanexo, titulo, url FROM cdd_anexo WHERE idaula = ? ORDER BY ordem, idanexo'); $an->execute([$idaula]);
    $fi = $pdo->prepare('SELECT iditem, grupo, exercicio, series, repeticoes, intervalo, metodo, metodo_tipo, video, obs FROM cdd_ficha_item WHERE idaula = ? ORDER BY ordem, iditem'); $fi->execute([$idaula]);
    $pr = $pdo->prepare('SELECT COUNT(*) FROM cdd_progresso WHERE idaluna = ? AND idaula = ?'); $pr->execute([$idaluna, $idaula]);
    // vizinhas (anterior e próxima, dentro do mesmo módulo)
    $vz = $pdo->prepare('SELECT idaula, publicado FROM cdd_aula WHERE idmodulo = ? ORDER BY ordem, idaula'); $vz->execute([$a['idmodulo']]);
    $ids = [];
    foreach ($vz->fetchAll() as $x) { if ($incluirRascunho || (int)$x['publicado']) $ids[] = (int)$x['idaula']; }
    $pos = array_search((int)$idaula, $ids, true);
    unset($a['mod_pub']);
    $a['idaula'] = (int)$a['idaula']; $a['idmodulo'] = (int)$a['idmodulo'];
    $a['anexos'] = $an->fetchAll();
    $a['ficha'] = $fi->fetchAll();
    if (!$incluirRascunho) $a['ficha'] = cddFichaComBase($pdo, $a['ficha']);   // a aluna recebe o vídeo da base quando o item não tem o seu
    $a['concluida'] = (int)$pr->fetchColumn() > 0;
    $a['anterior'] = ($pos !== false && $pos > 0) ? $ids[$pos - 1] : null;
    $a['proxima'] = ($pos !== false && $pos < count($ids) - 1) ? $ids[$pos + 1] : null;
    return $a;
}

/* ───────────────────────── Métodos de treino (mesma lógica da Intus) ───────────────────────── */

/** Bloco do método: super (bi-set), triset ou circuito. Vale o que a equipe marcou; sem marcação, deduz do texto da ficha. */
function cddMetodoTipoDoItem(array $it): string {
    $m = (string)($it['metodo_tipo'] ?? '');
    if (in_array($m, ['super', 'triset', 'circuito'], true)) return $m;
    $t = strtolower(strtr(($it['series'] ?? '') . ' ' . ($it['metodo'] ?? '') . ' ' . ($it['obs'] ?? ''), ['É' => 'e', 'é' => 'e', 'ç' => 'c']));
    if (preg_match('/tri[\s-]?set/', $t)) return 'triset';
    if (strpos($t, 'circuito') !== false) return 'circuito';
    if (strpos($t, 'super') !== false) return 'super';
    return '';
}

/** Técnica de série (drop, rest-pause...) citada no texto do método. Mesmas chaves da Intus. */
function cddInferirTecnica(array $it): string {
    $t = strtolower(strtr(($it['metodo'] ?? '') . ' ' . ($it['obs'] ?? ''), ['ã' => 'a', 'á' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ç' => 'c']));
    if (preg_match('/drop[\s-]?set|\bdrop\b/', $t)) return 'drop';
    if (preg_match('/rest[\s-]?pause/', $t)) return 'restpause';
    if (strpos($t, 'cluster') !== false) return 'cluster';
    if (preg_match('/back[\s-]?off/', $t)) return 'backoff';
    if (preg_match('/ate a falha|falha total|ate falhar/', $t)) return 'falha';
    if (strpos($t, 'isometria') !== false) return 'isometria';
    if (strpos($t, 'negativa') !== false) return 'negativa';
    return '';
}

function cddGravarItemFicha(PDO $pdo, int $ida, int $ordem, array $it): void {
    $mt = cddMetodoTipoDoItem($it);
    $pdo->prepare('INSERT INTO cdd_ficha_item (idaula, ordem, grupo, exercicio, series, repeticoes, intervalo, metodo, metodo_tipo, video, obs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$ida, $ordem, cddStr($it['grupo'] ?? '', 80), cddStr($it['exercicio'] ?? '', 200), cddStr($it['series'] ?? '', 30), cddStr($it['repeticoes'] ?? '', 40), cddStr($it['intervalo'] ?? '', 40),
                   cddStr($it['metodo'] ?? '', 200), $mt ?: null, cddVideoCampo($it['video'] ?? ''), cddStr($it['obs'] ?? '', 500)]);
}

/* ───────────────────────── Base de exercícios ───────────────────────── */

/** Nome sem acento, minúsculo, só letras e números: chave para achar o mesmo exercício escrito de jeitos diferentes. */
function cddNorm($t): string {
    $t = function_exists('mb_strtolower') ? mb_strtolower((string)$t, 'UTF-8') : strtolower((string)$t);
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
                    'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    return substr(preg_replace('/[^a-z0-9]+/', '', $t) ?? '', 0, 200);
}

/** Completa a ficha da aluna: sem vídeo no item, usa o da base de exercícios (mesmo nome) e leva a descrição. */
function cddFichaComBase(PDO $pdo, array $itens): array {
    if (!$itens) return $itens;
    $chaves = [];
    foreach ($itens as $i => $it) { $k = cddNorm($it['exercicio']); $itens[$i]['_k'] = $k; if ($k !== '') $chaves[$k] = true; }
    $base = [];
    if ($chaves) {
        $ks = array_keys($chaves);
        $st = $pdo->prepare('SELECT nome_norm, video, descricao FROM cdd_exercicio WHERE nome_norm IN (' . implode(',', array_fill(0, count($ks), '?')) . ')');
        $st->execute($ks);
        foreach ($st->fetchAll() as $r) $base[$r['nome_norm']] = $r;
    }
    foreach ($itens as $i => $it) {
        $b = $base[$it['_k']] ?? null;
        if (empty($it['video']) && $b && !empty($b['video'])) $itens[$i]['video'] = $b['video'];
        $itens[$i]['descricao'] = $b['descricao'] ?? '';
        $itens[$i]['metodo_tipo'] = cddMetodoTipoDoItem($it);
        $itens[$i]['tecnica'] = cddInferirTecnica($it);
        unset($itens[$i]['_k']);
    }
    return $itens;
}

/** Cria ou atualiza um exercício da base pelo nome (chave normalizada). Devolve 'novo', 'atualizado' ou 'igual'. */
function cddExercicioGuardar(PDO $pdo, array $d, bool $intusMandaNoCampo = false) {
    $nome = cddStr($d['nome'] ?? '', 200);
    $norm = cddNorm($nome);
    if ($nome === '' || $norm === '') return false;
    $video = cddVideoCampo($d['video'] ?? '');
    $grupo = cddStr($d['grupo'] ?? '', 120); $equip = cddStr($d['equipamento'] ?? '', 40); $desc = cddStr($d['descricao'] ?? '', 4000);
    $origem = ($d['origem'] ?? 'cdd') === 'intus' ? 'intus' : 'cdd'; $idIntus = !empty($d['id_intus']) ? (int)$d['id_intus'] : null;
    $st = $pdo->prepare('SELECT * FROM cdd_exercicio WHERE nome_norm = ?'); $st->execute([$norm]);
    $ex = $st->fetch();
    if (!$ex && $idIntus) { $st = $pdo->prepare('SELECT * FROM cdd_exercicio WHERE id_intus = ?'); $st->execute([$idIntus]); $ex = $st->fetch(); }
    if (!$ex) {
        $pdo->prepare('INSERT INTO cdd_exercicio (nome, nome_norm, grupo, equipamento, descricao, video, origem, id_intus, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nome, $norm, $grupo ?: null, $equip ?: null, $desc ?: null, $video ?: null, $origem, $idIntus, cddAgora()]);
        return 'novo';
    }
    // Campo vazio nunca apaga o que já existe. Com a Intus como fonte principal, o que ela traz substitui o que o CDD tinha.
    $novo = ['grupo' => $ex['grupo'], 'equipamento' => $ex['equipamento'], 'descricao' => $ex['descricao'], 'video' => $ex['video'], 'nome' => $ex['nome'], 'id_intus' => $ex['id_intus'], 'origem' => $ex['origem']];
    foreach (['grupo' => $grupo, 'equipamento' => $equip, 'descricao' => $desc, 'video' => $video] as $k => $v) {
        if ($v === '') continue;
        if ($intusMandaNoCampo || empty($ex[$k])) $novo[$k] = $v;
    }
    if ($intusMandaNoCampo) { $novo['id_intus'] = $idIntus ?: $ex['id_intus']; $novo['origem'] = 'intus'; $novo['nome'] = $nome; }
    $mudou = false; foreach ($novo as $k => $v) { if ((string)$v !== (string)$ex[$k]) $mudou = true; }
    if (!$mudou) return 'igual';
    $pdo->prepare('UPDATE cdd_exercicio SET nome = ?, nome_norm = ?, grupo = ?, equipamento = ?, descricao = ?, video = ?, origem = ?, id_intus = ?, atualizado_em = ? WHERE idexercicio = ?')
        ->execute([$novo['nome'], cddNorm($novo['nome']), $novo['grupo'] ?: null, $novo['equipamento'] ?: null, $novo['descricao'] ?: null, $novo['video'] ?: null, $novo['origem'], $novo['id_intus'], cddAgora(), $ex['idexercicio']]);
    return 'atualizado';
}

/**
 * Sincroniza com o catálogo de exercícios da Intus (a Intus é a fonte principal). SOMENTE LEITURA do lado da Intus:
 * um SELECT em intus_exercicio. Nada é gravado na Intus. Exercícios criados só no CDD continuam como estão.
 */
function cddSincronizarIntus(PDO $pdo): array {
    try {
        $linhas = $pdo->query('SELECT idexercicio, nmexercicio, grupo, equipamento, descricao, videoyoutube FROM intus_exercicio ORDER BY idexercicio')->fetchAll();
    } catch (Throwable $e) {
        return ['erro' => 'Não encontrei o catálogo de exercícios da Intus neste banco.'];
    }
    $r = ['lidos' => count($linhas), 'novos' => 0, 'atualizados' => 0, 'iguais' => 0, 'ignorados' => 0];
    foreach ($linhas as $l) {
        try {
            $res = cddExercicioGuardar($pdo, ['nome' => $l['nmexercicio'], 'grupo' => $l['grupo'], 'equipamento' => $l['equipamento'], 'descricao' => $l['descricao'],
                                              'video' => $l['videoyoutube'], 'origem' => 'intus', 'id_intus' => $l['idexercicio']], true);
        } catch (Throwable $e) { $res = false; }   // por exemplo, dois nomes que viram a mesma chave: segue para o próximo
        if ($res === 'novo') $r['novos']++; elseif ($res === 'atualizado') $r['atualizados']++; elseif ($res === 'igual') $r['iguais']++; else $r['ignorados']++;
    }
    return $r;
}

/* ───────────────────────── Ficha: colar da planilha ───────────────────────── */

/** Lê texto colado do Google Sheets (colunas separadas por TAB). Reconhece cabeçalho; sem cabeçalho usa a ordem da planilha do CDD. */
function cddParseFicha(string $texto): array {
    $linhas = preg_split('/\r\n|\r|\n/', $texto);
    $cols = ['exercicio', 'repeticoes', 'series', 'intervalo', 'metodo', 'video'];   // ordem das planilhas do CDD
    $itens = []; $grupo = ''; $primeira = true;
    foreach ($linhas as $ln) {
        if (trim($ln) === '') continue;
        $c = array_map('trim', explode("\t", $ln));
        $nn = array_values(array_filter($c, function ($x) { return $x !== ''; }));
        $baixo = array_map(function ($x) { return function_exists('mb_strtolower') ? mb_strtolower($x) : strtolower($x); }, $c);
        if ($primeira || in_array('exercício', $baixo, true) || in_array('exercicio', $baixo, true)) {
            $mapa = [];
            foreach ($baixo as $i => $h) {
                $h = strtr($h, ['í' => 'i', 'é' => 'e', 'ç' => 'c', 'ã' => 'a', 'ô' => 'o', 'á' => 'a']);
                if (strpos($h, 'exerc') === 0) $mapa[$i] = 'exercicio';
                elseif (strpos($h, 'repet') === 0) $mapa[$i] = 'repeticoes';
                elseif (strpos($h, 'serie') === 0) $mapa[$i] = 'series';
                elseif (strpos($h, 'interv') === 0) $mapa[$i] = 'intervalo';
                elseif (strpos($h, 'metod') === 0) $mapa[$i] = 'metodo';
                elseif (strpos($h, 'como') === 0 || strpos($h, 'video') === 0) $mapa[$i] = 'video';
            }
            if (in_array('exercicio', $mapa, true)) { $cols = $mapa; $primeira = false; continue; }
            $primeira = false;
        }
        // linha de título de treino (uma célula só preenchida): vira o grupo
        if (count($nn) === 1 && preg_match('/^treino\b/i', $nn[0])) { $grupo = cddStr($nn[0], 80); continue; }
        $it = ['grupo' => $grupo, 'exercicio' => '', 'series' => '', 'repeticoes' => '', 'intervalo' => '', 'metodo' => '', 'video' => '', 'obs' => ''];
        foreach ($cols as $i => $k) { if (($c[$i] ?? '') !== '' && $it[$k] === '') $it[$k] = $c[$i]; }
        $it['exercicio'] = cddStr($it['exercicio'], 200);
        if ($it['exercicio'] === '') continue;
        foreach (['series' => 30, 'repeticoes' => 40, 'intervalo' => 40, 'metodo' => 200] as $k => $m) $it[$k] = cddStr($it[$k], $m);
        $it['video'] = cddVideoCampo($it['video']);
        $it['metodo_tipo'] = cddMetodoTipoDoItem($it);
        $itens[] = $it;
    }
    return $itens;
}

/* ───────────────────────── Importação do conteúdo (seed) ───────────────────────── */

/** Cria ou atualiza módulos, aulas e anexos pelo par (módulo, título). Não apaga nada e não mexe em fichas já editadas. */
function cddImportarSeed(PDO $pdo, array $seed): array {
    $novos = 0; $atualizados = 0; $mods = 0; $fichas = 0; $exNovos = 0;
    $ordemMod = (int)$pdo->query('SELECT COALESCE(MAX(ordem),0) FROM cdd_modulo')->fetchColumn();
    foreach ($seed['modulos'] ?? [] as $m) {
        $titulo = cddStr($m['titulo'] ?? '', 200);
        if ($titulo === '') continue;
        $st = $pdo->prepare('SELECT idmodulo FROM cdd_modulo WHERE titulo = ?'); $st->execute([$titulo]);
        $idm = $st->fetchColumn();
        if (!$idm) {
            $ordemMod += 10;
            $pdo->prepare('INSERT INTO cdd_modulo (titulo, descricao, grupo, ordem, publicado, liberacao_dias, liberacao_data, emoji, cor, capa_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$titulo, cddStr($m['descricao'] ?? '', 600), cddStr($m['grupo'] ?? 'curso', 20), $ordemMod, !empty($m['rascunho']) ? 0 : 1,
                           isset($m['liberacao_dias']) ? (int)$m['liberacao_dias'] : null, !empty($m['liberacao_data']) ? cddData($m['liberacao_data']) : null,
                           cddStr($m['emoji'] ?? '', 8) ?: null, preg_match('/^#[0-9a-fA-F]{6}$/', (string)($m['cor'] ?? '')) ? $m['cor'] : null, cddCapaUrl($m['capa_url'] ?? '') ?: null]);
            $idm = (int)$pdo->lastInsertId(); $mods++;
        } elseif (!empty($m['emoji']) || !empty($m['cor']) || !empty($m['capa_url'])) {
            // módulo que já existia sem capa: completa só o que ainda estiver vazio
            $sv = $pdo->prepare('SELECT emoji, cor, capa_url FROM cdd_modulo WHERE idmodulo = ?'); $sv->execute([$idm]); $sv = $sv->fetch();
            if ($sv && empty($sv['capa_url']) && cddCapaUrl($m['capa_url'] ?? '') !== '') $pdo->prepare('UPDATE cdd_modulo SET capa_url = ? WHERE idmodulo = ?')->execute([cddCapaUrl($m['capa_url']), $idm]);
            if ($sv && empty($sv['emoji']) && !empty($m['emoji'])) $pdo->prepare('UPDATE cdd_modulo SET emoji = ? WHERE idmodulo = ?')->execute([cddStr($m['emoji'], 8), $idm]);
            if ($sv && empty($sv['cor']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)($m['cor'] ?? ''))) $pdo->prepare('UPDATE cdd_modulo SET cor = ? WHERE idmodulo = ?')->execute([$m['cor'], $idm]);
        }
        if ($idm && (isset($m['liberacao_dias']) || !empty($m['liberacao_data']))) {
            // módulo que já existia sem regra de liberação (importação anterior): completa só se ainda estiver vazio
            $sv = $pdo->prepare('SELECT liberacao_dias, liberacao_data FROM cdd_modulo WHERE idmodulo = ?'); $sv->execute([$idm]); $sv = $sv->fetch();
            if ($sv && $sv['liberacao_dias'] === null && empty($sv['liberacao_data'])) {
                $pdo->prepare('UPDATE cdd_modulo SET liberacao_dias = ?, liberacao_data = ? WHERE idmodulo = ?')->execute([isset($m['liberacao_dias']) ? (int)$m['liberacao_dias'] : null, !empty($m['liberacao_data']) ? cddData($m['liberacao_data']) : null, $idm]);
            }
        }
        $ordem = 0;
        foreach ($m['aulas'] ?? [] as $a) {
            $ordem += 10;
            $t = cddStr($a['titulo'] ?? '', 250);
            if ($t === '') continue;
            $yid = cddYoutubeId($a['youtube'] ?? '');
            $st = $pdo->prepare('SELECT idaula, youtube_id FROM cdd_aula WHERE idmodulo = ? AND titulo = ?'); $st->execute([$idm, $t]);
            $ex = $st->fetch();
            if (!$ex) {
                $pdo->prepare('INSERT INTO cdd_aula (idmodulo, titulo, tipo, youtube_id, texto, ordem, publicado) VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$idm, $t, $yid ? 'video' : (cddStr($a['tipo'] ?? 'texto', 10)), $yid, cddStr($a['texto'] ?? '', 4000) ?: null, $ordem, !empty($a['rascunho']) ? 0 : 1]);
                $ida = (int)$pdo->lastInsertId(); $novos++;
            } else {
                $ida = (int)$ex['idaula'];
                if ($yid && empty($ex['youtube_id'])) { $pdo->prepare("UPDATE cdd_aula SET youtube_id = ?, tipo = 'video' WHERE idaula = ?")->execute([$yid, $ida]); $atualizados++; }
                if (($a['tipo'] ?? '') === 'anamnese') $pdo->prepare("UPDATE cdd_aula SET tipo = 'anamnese' WHERE idaula = ? AND tipo = 'link'")->execute([$ida]);
            }
            // Ficha: só entra se a aula ainda não tem nenhum exercício (nunca sobrescreve o que a equipe editou).
            if (!empty($a['ficha']) && is_array($a['ficha'])) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_ficha_item WHERE idaula = ?'); $st->execute([$ida]);
                if ((int)$st->fetchColumn() === 0) {
                    $o = 0;
                    foreach ($a['ficha'] as $it) {
                        $ex = cddStr($it['exercicio'] ?? '', 200); if ($ex === '') continue;
                        $o += 10;
                        cddGravarItemFicha($pdo, $ida, $o, $it);
                    }
                    if ($o) $fichas++;
                }
            }
            foreach ($a['anexos'] ?? [] as $ax) {
                $url = cddUrl($ax['url'] ?? ''); if ($url === '') continue;
                $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_anexo WHERE idaula = ? AND url = ?'); $st->execute([$ida, $url]);
                if ((int)$st->fetchColumn() === 0) $pdo->prepare('INSERT INTO cdd_anexo (idaula, titulo, url, ordem) VALUES (?, ?, ?, ?)')->execute([$ida, cddStr($ax['titulo'] ?? 'Abrir', 200), $url, 10]);
            }
        }
    }
    // Base de exercícios: nomes e vídeos que aparecem nas fichas.
    foreach ($seed['exercicios'] ?? [] as $e) {
        if (cddExercicioGuardar($pdo, ['nome' => $e['nome'] ?? '', 'video' => $e['video'] ?? '', 'origem' => 'cdd']) === 'novo') $exNovos++;
    }
    return ['modulos_novos' => $mods, 'aulas_novas' => $novos, 'aulas_atualizadas' => $atualizados, 'fichas_novas' => $fichas, 'exercicios_novos' => $exNovos];
}

/* ───────────────────────── Roteador ───────────────────────── */

/**
 * @param array $ctx ['ip' => string, 'base' => url da pasta do CDD (sem barra final), 'seed' => caminho do JSON]
 * @return array [status HTTP, dados]
 */
function cddTratar(PDO $pdo, string $metodo, string $acao, array $q, array $b, string $token, array $ctx): array {
    cddGarantirTabelas($pdo);
    $ip = $ctx['ip'] ?? '0.0.0.0';
    $base = $ctx['base'] ?? '';
    $get = ($metodo === 'GET');

    /* ---- públicas ---- */
    if ($acao === 'lead' && !$get) return cddLeadReceber($pdo, $b, $ip);
    if ($acao === 'sso' && !$get) return cddSsoEntrar($pdo, cddStr($b['bilhete'] ?? '', 900), $ip);
    if ($acao === 'webhook' && !$get) return cddComercialWebhook($pdo, cddStr($q['g'] ?? 'eduzz3', 20), $b, $base, $ctx);

    if ($acao === 'saude') {
        return cddResp(200, ['ok' => true, 'cadastro_aberto' => cddConfig($pdo, 'cadastro_aberto', '1') === '1',
                             'instalacao_aberta' => cddConfig($pdo, 'instalacao_aberta', '0') === '1']);
    }

    if ($acao === 'instalar') {
        if ($get) return cddResp(200, ['aberta' => cddConfig($pdo, 'instalacao_aberta', '0') === '1']);
        if (cddConfig($pdo, 'instalacao_aberta', '0') !== '1') return cddErro(403, 'instalacao_fechada', 'A instalação já foi feita.');
        if (cddBloqueado($pdo, 'instalar', $ip, '', 10, 10)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde alguns minutos.');
        $email = cddEmail($b['email'] ?? ''); $senha = (string)($b['senha'] ?? ''); $nome = cddStr($b['nome'] ?? '', 160);
        if ($email !== CDD_EMAIL_INSTALADOR) { cddRegistrarTentativa($pdo, 'ip:' . $ip, 'instalar'); return cddErro(403, 'email_nao_autorizado', 'Este e-mail não pode instalar.'); }
        if ($nome === '' || !cddSenhaValida($senha)) return cddErro(422, 'dados_invalidos', 'Informe o nome e uma senha de pelo menos 8 caracteres.');
        $pdo->prepare('INSERT INTO cdd_aluna (nome, email, senha_hash, sexo, papel, status, termos_versao, termos_em, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nome, $email, password_hash($senha, PASSWORD_BCRYPT), 'masculino', 'admin', 'ativa', CDD_TERMOS_VERSAO, cddAgora(), cddAgora()]);
        $id = (int)$pdo->lastInsertId();
        cddConfigGravar($pdo, 'instalacao_aberta', '0');
        cddRegistrarEvento($pdo, $id, 'instalacao', 'sistema');
        $t = cddCriarSessao($pdo, $id, $ip, 7);
        return cddResp(200, ['token' => $t, 'usuario' => ['idaluna' => $id, 'nome' => $nome, 'email' => $email, 'papel' => 'admin']]);
    }

    if ($acao === 'cadastro' && !$get) {
        if (cddConfig($pdo, 'cadastro_aberto', '1') !== '1') return cddErro(403, 'cadastro_fechado', 'O cadastro está fechado no momento.');
        if (cddBloqueado($pdo, 'cadastro', $ip, '', 8, 8)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde alguns minutos.');
        $sexo = strtolower(cddStr($b['sexo'] ?? '', 12));
        if ($sexo === 'masculino') {
            return cddErro(403, 'exclusivo_mulheres', 'O Código da Definição é um programa exclusivo para mulheres. A Intus Fit atende todos os públicos e vai te receber bem.', ['intus_url' => CDD_INTUS_URL]);
        }
        if ($sexo !== 'feminino') return cddErro(422, 'sexo_obrigatorio', 'Escolha uma opção em sexo.');
        $nome = cddStr($b['nome'] ?? '', 160); $email = cddEmail($b['email'] ?? ''); $senha = (string)($b['senha'] ?? '');
        if ($nome === '' || $email === '') return cddErro(422, 'dados_invalidos', 'Informe seu nome e um e-mail válido.');
        if (!cddSenhaValida($senha)) return cddErro(422, 'senha_curta', 'A senha precisa ter pelo menos 8 caracteres.');
        if (empty($b['aceite'])) return cddErro(422, 'aceite_obrigatorio', 'É preciso aceitar os termos para criar a conta.');
        cddRegistrarTentativa($pdo, 'ip:' . $ip, 'cadastro');
        $st = $pdo->prepare('SELECT idaluna FROM cdd_aluna WHERE email = ?'); $st->execute([$email]);
        if ($st->fetchColumn()) return cddErro(409, 'email_ja_cadastrado', 'Este e-mail já tem cadastro. Entre com a sua senha.');
        $pdo->prepare('INSERT INTO cdd_aluna (nome, email, senha_hash, sexo, papel, status, termos_versao, termos_em, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nome, $email, password_hash($senha, PASSWORD_BCRYPT), 'feminino', 'aluna', 'ativa', CDD_TERMOS_VERSAO, cddAgora(), cddAgora()]);
        $id = (int)$pdo->lastInsertId();
        cddRegistrarEvento($pdo, $id, 'cadastro', 'autocadastro');
        $t = cddCriarSessao($pdo, $id, $ip, 30);
        return cddResp(200, ['token' => $t, 'usuario' => ['idaluna' => $id, 'nome' => $nome, 'email' => $email, 'papel' => 'aluna']]);
    }

    if ($acao === 'login' && !$get) {
        $email = cddEmail($b['email'] ?? ''); $senha = (string)($b['senha'] ?? '');
        if (cddBloqueado($pdo, 'login', $ip, $email, 10, 8)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde alguns minutos e tente de novo.');
        $st = $pdo->prepare('SELECT idaluna, nome, email, senha_hash, papel, status FROM cdd_aluna WHERE email = ?'); $st->execute([$email]);
        $u = $email !== '' ? $st->fetch() : false;
        $ok = $u && !empty($u['senha_hash']) && password_verify($senha, $u['senha_hash']);
        if (!$u) password_verify($senha, '$2y$10$LEfKfatZCALxcBFd4WUo9uqXKsJIwHC5vvV1uQLUQuQExT.SWi9F.');   // custo igual quando a conta não existe
        if (!$ok || $u['status'] !== 'ativa') {
            cddRegistrarTentativa($pdo, 'ip:' . $ip, 'login'); if ($email !== '') cddRegistrarTentativa($pdo, 'c:' . $email, 'login');
            return cddErro(401, 'credenciais_invalidas', 'E-mail ou senha incorretos.');
        }
        cddLimparTentativas($pdo, 'c:' . $email, 'login');
        $t = cddCriarSessao($pdo, (int)$u['idaluna'], $ip, $u['papel'] === 'admin' ? 7 : 30);
        return cddResp(200, ['token' => $t, 'usuario' => ['idaluna' => (int)$u['idaluna'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel']]]);
    }

    if ($acao === 'ativar_info' && $get) {
        $st = $pdo->prepare('SELECT a.nome, v.expira_em, v.usado_em FROM cdd_ativacao v JOIN cdd_aluna a ON a.idaluna = v.idaluna WHERE v.token_hash = ?');
        $st->execute([cddHash((string)($q['t'] ?? ''))]); $r = $st->fetch();
        if (!$r || $r['usado_em'] || $r['expira_em'] < cddAgora()) return cddErro(404, 'link_invalido', 'Este link já foi usado ou expirou. Peça um novo à equipe do CDD.');
        return cddResp(200, ['nome' => $r['nome']]);
    }

    if ($acao === 'ativar' && !$get) {
        if (cddBloqueado($pdo, 'ativar', $ip, '', 12, 12)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde alguns minutos.');
        $senha = (string)($b['senha'] ?? '');
        if (!cddSenhaValida($senha)) return cddErro(422, 'senha_curta', 'A senha precisa ter pelo menos 8 caracteres.');
        $h = cddHash((string)($b['token'] ?? ''));
        $st = $pdo->prepare('SELECT v.id, v.idaluna, v.expira_em, v.usado_em, a.nome, a.email, a.papel, a.status FROM cdd_ativacao v JOIN cdd_aluna a ON a.idaluna = v.idaluna WHERE v.token_hash = ?');
        $st->execute([$h]); $r = $st->fetch();
        if (!$r || $r['usado_em'] || $r['expira_em'] < cddAgora() || $r['status'] !== 'ativa') {
            cddRegistrarTentativa($pdo, 'ip:' . $ip, 'ativar');
            return cddErro(404, 'link_invalido', 'Este link já foi usado ou expirou. Peça um novo à equipe do CDD.');
        }
        $pdo->prepare('UPDATE cdd_aluna SET senha_hash = ?, termos_versao = ?, termos_em = ? WHERE idaluna = ?')
            ->execute([password_hash($senha, PASSWORD_BCRYPT), CDD_TERMOS_VERSAO, cddAgora(), $r['idaluna']]);
        $pdo->prepare('UPDATE cdd_ativacao SET usado_em = ? WHERE id = ?')->execute([cddAgora(), $r['id']]);
        $pdo->prepare('DELETE FROM cdd_sessao WHERE idaluna = ?')->execute([$r['idaluna']]);   // senha nova derruba sessões antigas
        cddRegistrarEvento($pdo, (int)$r['idaluna'], 'ativacao', 'link');
        $t = cddCriarSessao($pdo, (int)$r['idaluna'], $ip, $r['papel'] === 'admin' ? 7 : 30);
        return cddResp(200, ['token' => $t, 'usuario' => ['idaluna' => (int)$r['idaluna'], 'nome' => $r['nome'], 'email' => $r['email'], 'papel' => $r['papel']]]);
    }

    /* ---- exigem sessão ---- */
    $eu = cddSessao($pdo, $token);
    if (!$eu) return cddErro(401, 'sessao_invalida', 'Sua sessão expirou. Entre de novo.');
    $idaluna = (int)$eu['idaluna'];
    $admin = ($eu['papel'] === 'admin');

    if ($acao === 'eu' && $get) {
        $mat = cddMatricula($pdo, $idaluna);
        return cddResp(200, ['usuario' => ['idaluna' => $idaluna, 'nome' => $eu['nome'], 'email' => $eu['email'], 'papel' => $eu['papel']],
                             'matricula' => $mat, 'acesso' => $admin || $mat['ativa']]);
    }
    if ($acao === 'logout' && !$get) {
        $pdo->prepare('DELETE FROM cdd_sessao WHERE token_hash = ?')->execute([cddHash($token)]);
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'trocar_senha' && !$get) {
        $st = $pdo->prepare('SELECT senha_hash FROM cdd_aluna WHERE idaluna = ?'); $st->execute([$idaluna]);
        $h = (string)$st->fetchColumn();
        if (cddBloqueado($pdo, 'trocar_senha', $ip, 'id' . $idaluna, 10, 6)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde alguns minutos.');
        if (!password_verify((string)($b['atual'] ?? ''), $h)) { cddRegistrarTentativa($pdo, 'c:id' . $idaluna, 'trocar_senha'); return cddErro(403, 'senha_atual_errada', 'A senha atual está incorreta.'); }
        if (!cddSenhaValida((string)($b['nova'] ?? ''))) return cddErro(422, 'senha_curta', 'A nova senha precisa ter pelo menos 8 caracteres.');
        $pdo->prepare('UPDATE cdd_aluna SET senha_hash = ? WHERE idaluna = ?')->execute([password_hash((string)$b['nova'], PASSWORD_BCRYPT), $idaluna]);
        $pdo->prepare('DELETE FROM cdd_sessao WHERE idaluna = ? AND token_hash <> ?')->execute([$idaluna, cddHash($token)]);
        return cddResp(200, ['ok' => true]);
    }

    $ra = cddRotaApp($pdo, $metodo, $acao, $q, $b, $eu, $ctx);   // anamnese e preferência do ranking: qualquer conta logada
    if ($ra !== null) return $ra;

    /* ---- conteúdo: exige matrícula ativa (ou admin) ---- */
    if (in_array($acao, ['meus_modulos', 'aula', 'concluir', 'treino', 'treino_salvar', 'treino_excluir', 'jornada', 'comentarios', 'comentar', 'comentario_excluir', 'avaliar', 'ranking', 'conquistas', 'cardio', 'cardio_salvar', 'cardio_excluir', 'nutri', 'agua', 'refeicao'], true)) {
        $mat = cddMatricula($pdo, $idaluna);
        if (!$admin && !$mat['ativa']) {
            $msg = $mat['status'] === 'expirada' ? 'Seu acesso expirou. Fale com a equipe para renovar.' : ($mat['status'] === 'sem_matricula' ? 'Sua conta está criada. Assim que a equipe liberar a sua matrícula, o conteúdo aparece aqui.' : 'Seu acesso está pausado. Fale com a equipe do CDD.');
            return cddErro(403, 'sem_matricula', $msg, ['status' => $mat['status']]);
        }
        $inicio = $mat['inicio'] ?? null;
        $rb = cddRotaBemestar($pdo, $metodo, $acao, $q, $b, $eu);
        if ($rb !== null) return $rb;
        $rm = cddRotaMembro($pdo, $metodo, $acao, $q, $eu);
        if ($rm !== null) return $rm;
        if ($acao === 'meus_modulos' && $get) {
            $mods = cddModulosParaAluna($pdo, $idaluna, $admin, $inicio);
            $seg = $pdo->prepare('SELECT COUNT(DISTINCT data) FROM cdd_treino WHERE idaluna = ? AND data >= ?'); $seg->execute([$idaluna, cddInicioSemana()]);
            $an = $pdo->prepare('SELECT enviado_em FROM cdd_anamnese WHERE idaluna = ?'); $an->execute([$idaluna]); $an = $an->fetch();
            $rk = cddRanking($pdo, $idaluna, 'semana');
            $cq = cddConquistasDaAluna($pdo, $idaluna);
            return cddResp(200, ['modulos' => $mods, 'treino_hoje' => cddProximoTreino($pdo, $idaluna, $mods), 'treinos_semana' => (int)$seg->fetchColumn(), 'inicio' => $inicio,
                                 'anamnese_enviada' => !empty($an['enviado_em']), 'pontos_semana' => $rk['eu']['pontos'], 'posicao_semana' => $rk['eu']['pos'], 'participantes_semana' => $rk['participantes'],
                                 'conquistas_ganhas' => count(array_filter($cq, function ($c) { return $c['conquistada']; })), 'conquistas_total' => count($cq),
                                 'ranking_visivel' => $rk['visivel'], 'proximas_conquistas' => array_slice(array_values(array_filter($cq, function ($c) { return !$c['conquistada']; })), 0, 3)]);
        }
        if ($acao === 'aula' && $get) {
            $a = cddAulaAcessivel($pdo, (int)($q['id'] ?? 0), $idaluna, $admin, $inicio);
            if (isset($a[0]) && is_int($a[0])) return $a;
            $m = $pdo->prepare('SELECT nota FROM cdd_avaliacao WHERE idaluna = ? AND idaula = ?'); $m->execute([$idaluna, $a['idaula']]); $n = $m->fetchColumn();
            $a['minha_nota'] = $n === false ? null : (int)$n;
            return cddResp(200, ['aula' => $a]);
        }
        if ($acao === 'concluir' && !$get) {
            $ida = (int)($b['id'] ?? 0);
            $a = cddAulaAcessivel($pdo, $ida, $idaluna, $admin, $inicio);
            if (isset($a[0]) && is_int($a[0])) return $a;
            if (!empty($b['feito'])) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_progresso WHERE idaluna = ? AND idaula = ?'); $st->execute([$idaluna, $ida]);
                if ((int)$st->fetchColumn() === 0) $pdo->prepare('INSERT INTO cdd_progresso (idaluna, idaula, concluida_em) VALUES (?, ?, ?)')->execute([$idaluna, $ida, cddAgora()]);
            } else {
                $pdo->prepare('DELETE FROM cdd_progresso WHERE idaluna = ? AND idaula = ?')->execute([$idaluna, $ida]);
            }
            return cddResp(200, ['ok' => true, 'concluida' => !empty($b['feito']), 'novas' => !empty($b['feito']) ? cddAtualizarConquistas($pdo, $idaluna) : []]);
        }
        $r = cddRotaAluna($pdo, $metodo, $acao, $q, $b, $eu, $mat, $ctx);
        if ($r !== null) return $r;
    }

    /* ---- administração ---- */
    if (strpos($acao, 'a_') === 0) {
        if (!$admin) return cddErro(403, 'sem_permissao', 'Esta área é só da equipe do CDD.');
        return cddAdmin($pdo, $metodo, $acao, $q, $b, $ctx, $idaluna);
    }

    return cddErro(404, 'rota_desconhecida', 'Rota desconhecida.');
}

function cddAdmin(PDO $pdo, string $metodo, string $acao, array $q, array $b, array $ctx, int $idadmin): array {
    $get = ($metodo === 'GET');
    $base = $ctx['base'] ?? '';

    $ld = cddLeadsAdmin($pdo, $metodo, $acao, $q, $b);
    if ($ld !== null) return $ld;
    $com = cddComercialAdmin($pdo, $metodo, $acao, $q, $b, $ctx);
    if ($com !== null) return $com;

    if ($acao === 'a_resumo' && $get) {
        $n = function ($sql) use ($pdo) { return (int)$pdo->query($sql)->fetchColumn(); };
        return cddResp(200, [
            'alunas' => $n("SELECT COUNT(*) FROM cdd_aluna WHERE papel = 'aluna'"),
            'matriculas_ativas' => $n("SELECT COUNT(*) FROM cdd_matricula WHERE status = 'ativa'"),
            'sem_matricula' => $n("SELECT COUNT(*) FROM cdd_aluna a WHERE a.papel = 'aluna' AND NOT EXISTS (SELECT 1 FROM cdd_matricula m WHERE m.idaluna = a.idaluna)"),
            'modulos' => $n('SELECT COUNT(*) FROM cdd_modulo'),
            'aulas' => $n('SELECT COUNT(*) FROM cdd_aula'),
            'aulas_sem_video' => $n("SELECT COUNT(*) FROM cdd_aula WHERE tipo = 'video' AND (youtube_id IS NULL OR youtube_id = '')"),
            'rascunhos' => $n('SELECT COUNT(*) FROM cdd_aula WHERE publicado = 0'),
            'cadastro_aberto' => cddConfig($pdo, 'cadastro_aberto', '1') === '1',
            'anamneses_enviadas' => $n('SELECT COUNT(*) FROM cdd_anamnese WHERE enviado_em IS NOT NULL'),
            'comentarios_sem_resposta' => $n("SELECT COUNT(*) FROM cdd_comentario c WHERE c.parent_id IS NULL AND c.oculto = 0 AND c.equipe = 0 AND NOT EXISTS (SELECT 1 FROM cdd_comentario r WHERE r.parent_id = c.idcomentario AND r.equipe = 1 AND r.oculto = 0)"),
            'treinos_7_dias' => $n("SELECT COUNT(*) FROM cdd_treino WHERE data >= '" . date('Y-m-d', time() - 6 * 86400) . "'"),
            'alunas_treinaram_7_dias' => $n("SELECT COUNT(DISTINCT idaluna) FROM cdd_treino WHERE data >= '" . date('Y-m-d', time() - 6 * 86400) . "'"),
        ]);
    }
    if ($acao === 'a_config' && !$get) {
        if (isset($b['cadastro_aberto'])) cddConfigGravar($pdo, 'cadastro_aberto', !empty($b['cadastro_aberto']) ? '1' : '0');
        return cddResp(200, ['cadastro_aberto' => cddConfig($pdo, 'cadastro_aberto', '1') === '1']);
    }

    /* alunas e matrículas */
    if ($acao === 'a_alunas' && $get) {
        $busca = cddStr($q['busca'] ?? '', 80);
        $sql = "SELECT a.idaluna, a.nome, a.email, a.papel, a.status, a.criado_em, a.ultimo_acesso,
                       m.status AS mat_status, m.origem AS mat_origem, m.inicio AS mat_inicio, m.expira_em AS mat_expira
                FROM cdd_aluna a LEFT JOIN cdd_matricula m ON m.idaluna = a.idaluna";
        $par = [];
        if ($busca !== '') { $sql .= ' WHERE a.nome LIKE ? OR a.email LIKE ?'; $par = ['%' . $busca . '%', '%' . $busca . '%']; }
        $sql .= ' ORDER BY a.idaluna DESC LIMIT 500';
        $st = $pdo->prepare($sql); $st->execute($par);
        $lista = $st->fetchAll();
        foreach ($lista as &$l) { $l['idaluna'] = (int)$l['idaluna']; $mm = cddMatricula($pdo, $l['idaluna']); $l['acesso'] = $mm['ativa']; $l['mat_situacao'] = $mm['status']; }
        return cddResp(200, ['alunas' => $lista]);
    }
    if ($acao === 'a_aluna_criar' && !$get) {
        $nome = cddStr($b['nome'] ?? '', 160); $email = cddEmail($b['email'] ?? '');
        if ($nome === '' || $email === '') return cddErro(422, 'dados_invalidos', 'Informe o nome e um e-mail válido.');
        $st = $pdo->prepare('SELECT idaluna FROM cdd_aluna WHERE email = ?'); $st->execute([$email]);
        if ($st->fetchColumn()) return cddErro(409, 'email_ja_cadastrado', 'Já existe uma conta com este e-mail. Use a lista para matricular ou gerar um novo link.');
        $pdo->prepare('INSERT INTO cdd_aluna (nome, email, senha_hash, sexo, papel, status, criado_em) VALUES (?, ?, NULL, ?, ?, ?, ?)')
            ->execute([$nome, $email, 'feminino', 'aluna', 'ativa', cddAgora()]);
        $id = (int)$pdo->lastInsertId();
        cddRegistrarEvento($pdo, $id, 'criada_pelo_painel', 'manual');
        if (!empty($b['matricular'])) cddAdminMatricular($pdo, $id, ['status' => 'ativa', 'origem' => cddStr($b['origem'] ?? 'manual', 20), 'expira_em' => $b['expira_em'] ?? null, 'obs' => $b['obs'] ?? '']);
        $lk = cddNovaAtivacao($pdo, $id, $base);
        return cddResp(200, ['idaluna' => $id, 'link_ativacao' => $lk['link'], 'link_expira_em' => $lk['expira_em']]);
    }
    if ($acao === 'a_aluna_link' && !$get) {
        $id = (int)($b['idaluna'] ?? 0);
        $st = $pdo->prepare("SELECT idaluna FROM cdd_aluna WHERE idaluna = ? AND status = 'ativa'"); $st->execute([$id]);
        if (!$st->fetchColumn()) return cddErro(404, 'aluna_nao_encontrada', 'Aluna não encontrada.');
        $lk = cddNovaAtivacao($pdo, $id, $base);
        cddRegistrarEvento($pdo, $id, 'novo_link', 'manual');
        return cddResp(200, ['link_ativacao' => $lk['link'], 'link_expira_em' => $lk['expira_em']]);
    }
    if ($acao === 'a_matricula' && !$get) {
        $id = (int)($b['idaluna'] ?? 0);
        $st = $pdo->prepare('SELECT idaluna FROM cdd_aluna WHERE idaluna = ?'); $st->execute([$id]);
        if (!$st->fetchColumn()) return cddErro(404, 'aluna_nao_encontrada', 'Aluna não encontrada.');
        $r = cddAdminMatricular($pdo, $id, $b);
        return $r === true ? cddResp(200, ['ok' => true, 'matricula' => cddMatricula($pdo, $id)]) : cddErro(422, 'dados_invalidos', $r);
    }
    if ($acao === 'a_aluna_status' && !$get) {
        $id = (int)($b['idaluna'] ?? 0); $sv = $b['status'] ?? ''; $status = in_array($sv, ['ativa', 'bloqueada'], true) ? $sv : '';
        if ($status === '' || $id === $idadmin) return cddErro(422, 'dados_invalidos', 'Não foi possível alterar esta conta.');
        $pdo->prepare('UPDATE cdd_aluna SET status = ? WHERE idaluna = ?')->execute([$status, $id]);
        if ($status === 'bloqueada') $pdo->prepare('DELETE FROM cdd_sessao WHERE idaluna = ?')->execute([$id]);
        cddRegistrarEvento($pdo, $id, 'conta_' . $status, 'manual');
        return cddResp(200, ['ok' => true]);
    }

    /* conteúdo */
    if ($acao === 'a_conteudo' && $get) {
        return cddResp(200, ['modulos' => cddModulosParaAluna($pdo, 0, true)]);
    }
    if ($acao === 'a_aula' && $get) {
        $a = cddAulaCompleta($pdo, (int)($q['id'] ?? 0), 0, true);
        return $a ? cddResp(200, ['aula' => $a]) : cddErro(404, 'aula_nao_encontrada', 'Aula não encontrada.');
    }
    if ($acao === 'a_modulo_salvar' && !$get) {
        $titulo = cddStr($b['titulo'] ?? '', 200);
        if ($titulo === '') return cddErro(422, 'dados_invalidos', 'Informe o título do módulo.');
        $g = $b['grupo'] ?? 'curso'; $grupo = in_array($g, ['curso', 'bonus', 'protocolo'], true) ? $g : 'curso';
        $pub = !empty($b['publicado']) ? 1 : 0; $desc = cddStr($b['descricao'] ?? '', 600);
        $ldRaw = trim((string)($b['liberacao_dias'] ?? '')); $ld = ($ldRaw === '') ? null : max(0, min(3650, (int)$ldRaw));
        $ldataRaw = trim((string)($b['liberacao_data'] ?? '')); $ldata = ($ldataRaw === '') ? null : cddData($ldataRaw);
        $emoji = cddStr($b['emoji'] ?? '', 8) ?: null; $cor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($b['cor'] ?? '')) ? $b['cor'] : null;
        $capaRaw = trim((string)($b['capa_url'] ?? '')); $capa = $capaRaw === '' ? null : (cddCapaUrl($capaRaw) ?: false);
        if ($capa === false) return cddErro(422, 'capa_invalida', 'A capa precisa ser um link https:// ou uma das capas prontas do app.');
        if ($ldataRaw !== '' && !$ldata) return cddErro(422, 'dados_invalidos', 'Data de liberação inválida. Use o formato AAAA-MM-DD.');
        $id = (int)($b['idmodulo'] ?? 0);
        if ($id) {
            $pdo->prepare('UPDATE cdd_modulo SET titulo = ?, descricao = ?, grupo = ?, publicado = ?, liberacao_dias = ?, liberacao_data = ?, emoji = ?, cor = ?, capa_url = ? WHERE idmodulo = ?')->execute([$titulo, $desc, $grupo, $pub, $ld, $ldata, $emoji, $cor, $capa, $id]);
        } else {
            $ordem = (int)$pdo->query('SELECT COALESCE(MAX(ordem),0) FROM cdd_modulo')->fetchColumn() + 10;
            $pdo->prepare('INSERT INTO cdd_modulo (titulo, descricao, grupo, ordem, publicado, liberacao_dias, liberacao_data, emoji, cor, capa_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$titulo, $desc, $grupo, $ordem, $pub, $ld, $ldata, $emoji, $cor, $capa]);
            $id = (int)$pdo->lastInsertId();
        }
        return cddResp(200, ['idmodulo' => $id]);
    }
    if ($acao === 'a_modulo_excluir' && !$get) {
        $id = (int)($b['idmodulo'] ?? 0);
        $ids = $pdo->prepare('SELECT idaula FROM cdd_aula WHERE idmodulo = ?'); $ids->execute([$id]);
        foreach ($ids->fetchAll() as $x) cddExcluirAula($pdo, (int)$x['idaula']);
        $pdo->prepare('DELETE FROM cdd_modulo WHERE idmodulo = ?')->execute([$id]);
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'a_aula_salvar' && !$get) {
        $titulo = cddStr($b['titulo'] ?? '', 250);
        if ($titulo === '') return cddErro(422, 'dados_invalidos', 'Informe o título da aula.');
        $yt = trim((string)($b['youtube'] ?? '')); $yid = cddYoutubeId($yt);
        if ($yt !== '' && !$yid) return cddErro(422, 'youtube_invalido', 'Link do YouTube inválido. Cole o link do vídeo ou só o código de 11 caracteres.');
        $tp = $b['tipo'] ?? 'video'; $tipo = in_array($tp, ['video', 'texto', 'ficha', 'link', 'anamnese'], true) ? $tp : 'video';
        $texto = cddStr($b['texto'] ?? '', 6000); $pub = !empty($b['publicado']) ? 1 : 0;
        $id = (int)($b['idaula'] ?? 0); $idm = (int)($b['idmodulo'] ?? 0);
        if ($id) {
            $pdo->prepare('UPDATE cdd_aula SET titulo = ?, tipo = ?, youtube_id = ?, texto = ?, publicado = ? WHERE idaula = ?')->execute([$titulo, $tipo, $yid, $texto ?: null, $pub, $id]);
        } else {
            $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_modulo WHERE idmodulo = ?'); $st->execute([$idm]);
            if (!(int)$st->fetchColumn()) return cddErro(422, 'dados_invalidos', 'Módulo não encontrado.');
            $st = $pdo->prepare('SELECT COALESCE(MAX(ordem),0) FROM cdd_aula WHERE idmodulo = ?'); $st->execute([$idm]);
            $pdo->prepare('INSERT INTO cdd_aula (idmodulo, titulo, tipo, youtube_id, texto, ordem, publicado) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idm, $titulo, $tipo, $yid, $texto ?: null, (int)$st->fetchColumn() + 10, $pub]);
            $id = (int)$pdo->lastInsertId();
        }
        // anexos: troca a lista inteira
        if (isset($b['anexos']) && is_array($b['anexos'])) {
            $pdo->prepare('DELETE FROM cdd_anexo WHERE idaula = ?')->execute([$id]);
            $o = 0;
            foreach ($b['anexos'] as $ax) {
                $u = cddUrl($ax['url'] ?? ''); if ($u === '') continue;
                $o += 10;
                $pdo->prepare('INSERT INTO cdd_anexo (idaula, titulo, url, ordem) VALUES (?, ?, ?, ?)')->execute([$id, cddStr($ax['titulo'] ?? 'Abrir', 200) ?: 'Abrir', $u, $o]);
            }
        }
        return cddResp(200, ['idaula' => $id]);
    }
    if ($acao === 'a_aula_excluir' && !$get) {
        cddExcluirAula($pdo, (int)($b['idaula'] ?? 0));
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'a_ordem' && !$get) {
        $tipo = ($b['tipo'] ?? '') === 'modulos' ? 'cdd_modulo' : (($b['tipo'] ?? '') === 'aulas' ? 'cdd_aula' : '');
        $col = $tipo === 'cdd_modulo' ? 'idmodulo' : 'idaula';
        if ($tipo === '' || !is_array($b['ids'] ?? null)) return cddErro(422, 'dados_invalidos', 'Pedido inválido.');
        $o = 0;
        foreach ($b['ids'] as $i) { $o += 10; $pdo->prepare("UPDATE $tipo SET ordem = ? WHERE $col = ?")->execute([$o, (int)$i]); }
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'a_ficha_salvar' && !$get) {
        $ida = (int)($b['idaula'] ?? 0);
        $st = $pdo->prepare('SELECT COUNT(*) FROM cdd_aula WHERE idaula = ?'); $st->execute([$ida]);
        if (!(int)$st->fetchColumn()) return cddErro(404, 'aula_nao_encontrada', 'Aula não encontrada.');
        $itens = is_array($b['itens'] ?? null) ? $b['itens'] : [];
        if (count($itens) > 200) return cddErro(422, 'dados_invalidos', 'Ficha com itens demais.');
        $pdo->prepare('DELETE FROM cdd_ficha_item WHERE idaula = ?')->execute([$ida]);
        $o = 0;
        foreach ($itens as $it) {
            $ex = cddStr($it['exercicio'] ?? '', 200); if ($ex === '') continue;
            $o += 10;
            cddGravarItemFicha($pdo, $ida, $o, $it);
        }
        return cddResp(200, ['ok' => true, 'itens' => $o / 10]);
    }
    if ($acao === 'a_ficha_colar' && !$get) {
        $itens = cddParseFicha((string)($b['texto'] ?? ''));
        return cddResp(200, ['itens' => $itens]);
    }
    /* base de exercícios */
    if ($acao === 'a_exercicios' && $get) {
        $busca = cddStr($q['busca'] ?? '', 80);
        $sql = 'SELECT idexercicio, nome, grupo, equipamento, descricao, video, origem, id_intus FROM cdd_exercicio';
        $par = [];
        if ($busca !== '') { $sql .= ' WHERE nome LIKE ? OR grupo LIKE ?'; $par = ['%' . $busca . '%', '%' . $busca . '%']; }
        $st = $pdo->prepare($sql . ' ORDER BY nome LIMIT 800'); $st->execute($par);
        $tot = (int)$pdo->query('SELECT COUNT(*) FROM cdd_exercicio')->fetchColumn();
        $intus = (int)$pdo->query("SELECT COUNT(*) FROM cdd_exercicio WHERE origem = 'intus'")->fetchColumn();
        return cddResp(200, ['exercicios' => $st->fetchAll(), 'total' => $tot, 'da_intus' => $intus]);
    }
    if ($acao === 'a_exercicio_salvar' && !$get) {
        $nome = cddStr($b['nome'] ?? '', 200); $norm = cddNorm($nome);
        if ($nome === '' || $norm === '') return cddErro(422, 'dados_invalidos', 'Informe o nome do exercício.');
        $vt = trim((string)($b['video'] ?? '')); $video = cddVideoCampo($vt);
        if ($vt !== '' && $video === '') return cddErro(422, 'video_invalido', 'Link de vídeo inválido. Cole um link do YouTube ou outro link https.');
        $id = (int)($b['idexercicio'] ?? 0);
        $st = $pdo->prepare('SELECT idexercicio FROM cdd_exercicio WHERE nome_norm = ?'); $st->execute([$norm]);
        $outro = (int)$st->fetchColumn();
        if ($outro && $outro !== $id) return cddErro(409, 'exercicio_repetido', 'Já existe um exercício com este nome.');
        $campos = [$nome, $norm, cddStr($b['grupo'] ?? '', 120) ?: null, cddStr($b['equipamento'] ?? '', 40) ?: null, cddStr($b['descricao'] ?? '', 4000) ?: null, $video ?: null, cddAgora()];
        if ($id) {
            $pdo->prepare('UPDATE cdd_exercicio SET nome = ?, nome_norm = ?, grupo = ?, equipamento = ?, descricao = ?, video = ?, atualizado_em = ? WHERE idexercicio = ?')->execute(array_merge($campos, [$id]));
        } else {
            $pdo->prepare("INSERT INTO cdd_exercicio (nome, nome_norm, grupo, equipamento, descricao, video, atualizado_em, origem) VALUES (?, ?, ?, ?, ?, ?, ?, 'cdd')")->execute($campos);
            $id = (int)$pdo->lastInsertId();
        }
        return cddResp(200, ['idexercicio' => $id]);
    }
    if ($acao === 'a_exercicio_excluir' && !$get) {
        $pdo->prepare('DELETE FROM cdd_exercicio WHERE idexercicio = ?')->execute([(int)($b['idexercicio'] ?? 0)]);
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'a_exercicios_sync_intus' && !$get) {
        $r = cddSincronizarIntus($pdo);
        if (isset($r['erro'])) return cddErro(404, 'intus_indisponivel', $r['erro']);
        cddRegistrarEvento($pdo, $idadmin, 'sync_exercicios_intus', 'manual', 'ok', json_encode($r));
        return cddResp(200, $r);
    }
    if ($acao === 'a_importar_seed' && !$get) {
        $f = $ctx['seed'] ?? '';
        if (!$f || !is_file($f)) return cddErro(404, 'seed_ausente', 'Arquivo de importação não encontrado no servidor.');
        $seed = json_decode((string)file_get_contents($f), true);
        if (!is_array($seed)) return cddErro(500, 'seed_invalido', 'Arquivo de importação inválido.');
        $r = cddImportarSeed($pdo, $seed);
        cddRegistrarEvento($pdo, $idadmin, 'importar_seed', 'manual', 'ok', json_encode($r));
        return cddResp(200, $r);
    }
    $ex = cddAdminExtra($pdo, $metodo, $acao, $q, $b, $idadmin);
    if ($ex !== null) return $ex;
    $an = cddAdminAnamnese($pdo, $metodo, $acao, $q);
    if ($an !== null) return $an;
    return cddErro(404, 'rota_desconhecida', 'Rota desconhecida.');
}

function cddExcluirAula(PDO $pdo, int $id): void {
    $pdo->prepare('DELETE FROM cdd_anexo WHERE idaula = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM cdd_ficha_item WHERE idaula = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM cdd_progresso WHERE idaula = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM cdd_aula WHERE idaula = ?')->execute([$id]);
}

/** Cria ou atualiza a matrícula. true se deu certo, texto do erro se não. */
function cddAdminMatricular(PDO $pdo, int $idaluna, array $b) {
    $sv = $b['status'] ?? 'ativa'; $status = in_array($sv, ['ativa', 'suspensa', 'encerrada'], true) ? $sv : 'ativa';
    $ov = $b['origem'] ?? 'manual'; $origem = in_array($ov, ['manual', 'cortesia', 'plataforma'], true) ? $ov : 'manual';
    $exp = trim((string)($b['expira_em'] ?? '')); $ini = trim((string)($b['inicio'] ?? ''));
    $exp = $exp === '' ? null : cddData($exp); $ini = $ini === '' ? null : cddData($ini);
    if ((trim((string)($b['expira_em'] ?? '')) !== '' && !$exp) || (trim((string)($b['inicio'] ?? '')) !== '' && !$ini)) return 'Data inválida. Use o formato AAAA-MM-DD.';
    if ($exp && $ini && $exp < $ini) return 'A data de fim é anterior à de início.';
    $obs = cddStr($b['obs'] ?? '', 300);
    $st = $pdo->prepare('SELECT idmatricula FROM cdd_matricula WHERE idaluna = ?'); $st->execute([$idaluna]);
    if ($st->fetchColumn()) {
        $pdo->prepare('UPDATE cdd_matricula SET status = ?, origem = ?, inicio = ?, expira_em = ?, obs = ? WHERE idaluna = ?')->execute([$status, $origem, $ini, $exp, $obs, $idaluna]);
    } else {
        $pdo->prepare('INSERT INTO cdd_matricula (idaluna, status, origem, inicio, expira_em, obs, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$idaluna, $status, $origem, $ini ?: cddHoje(), $exp, $obs, cddAgora()]);
    }
    cddRegistrarEvento($pdo, $idaluna, 'matricula_' . $status, $origem);
    return true;
}
