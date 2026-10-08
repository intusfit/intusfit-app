<?php
/**
 * Atendimento de leads por WhatsApp (API oficial da Meta): biblioteca.
 *
 * Aqui mora TODA regra de negócio do atendimento (horário, janela de 24h,
 * consentimento, SAIR, agenda do teste grátis). Os endpoints só chamam estas
 * funções. Nada aqui imprime saída nem encerra o script.
 *
 * Decisões de desenho que não são óbvias:
 *  - Horário sempre em America/Sao_Paulo e gravado pelo PHP (nunca NOW() nem
 *    DEFAULT CURRENT_TIMESTAMP), porque o servidor MySQL pode estar em outro
 *    fuso e porque assim as mesmas funções rodam em teste com SQLite.
 *  - SQL portável de propósito (sem ON DUPLICATE KEY, sem INSERT IGNORE): a
 *    duplicidade é tratada pela exceção de chave única.
 *  - Chaves e tokens moram em intus_secrets (nunca em arquivo). Ver
 *    admin/whatsapp-keys.php.
 *  - Mensagem proativa (template) exige consentimento, horário de 8h às 21h e
 *    no máximo uma por dia. Resposta a quem escreveu primeiro (dentro da janela
 *    de 24h) não passa por essas travas.
 */

const WA_JANELA_SEGUNDOS = 86400;

// ─────────────────────────────────────────────────────────────────────────────
// Tempo
// ─────────────────────────────────────────────────────────────────────────────

function wa_tz(): DateTimeZone {
    static $tz = null;
    if ($tz === null) $tz = new DateTimeZone('America/Sao_Paulo');
    return $tz;
}

function wa_agora(): DateTimeImmutable {
    return new DateTimeImmutable('now', wa_tz());
}

function wa_fmt(DateTimeImmutable $t): string {
    return $t->setTimezone(wa_tz())->format('Y-m-d H:i:s');
}

function wa_de_texto(?string $s): ?DateTimeImmutable {
    if ($s === null || $s === '') return null;
    return new DateTimeImmutable($s, wa_tz());
}

/** Horário em que mensagem proativa pode sair: [ini, fim) em horas cheias. */
function wa_hora_permitida(DateTimeImmutable $agora, int $ini = 8, int $fim = 21): bool {
    $h = (int)$agora->setTimezone(wa_tz())->format('G');
    return $h >= $ini && $h < $fim;
}

/** Se $t cai fora do horário, empurra para o próximo início de horário. */
function wa_proximo_horario_valido(DateTimeImmutable $t, int $ini = 8, int $fim = 21): DateTimeImmutable {
    $t = $t->setTimezone(wa_tz());
    $h = (int)$t->format('G');
    if ($h < $ini) return $t->setTime($ini, 0, 0);
    if ($h >= $fim) return $t->modify('+1 day')->setTime($ini, 0, 0);
    return $t;
}

/** Janela de atendimento da Meta: 24h desde a última mensagem DO LEAD. */
function wa_janela_aberta(?string $ultimaEntradaEm, DateTimeImmutable $agora): bool {
    $u = wa_de_texto($ultimaEntradaEm);
    if (!$u) return false;
    return ($agora->getTimestamp() - $u->getTimestamp()) < WA_JANELA_SEGUNDOS;
}

// ─────────────────────────────────────────────────────────────────────────────
// Telefone e texto
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Deixa o telefone no formato que a Meta usa: só dígitos, com DDI.
 * Celular brasileiro antigo, sem o 9 (12 dígitos), ganha o 9, porque a Meta
 * às vezes devolve o número de um jeito e a landing grava de outro, e sem isto
 * o mesmo aluno viraria dois contatos.
 */
function wa_normalizar_telefone($raw): string {
    $d = preg_replace('/\D+/', '', (string)$raw);
    if ($d === '' || $d === null) return '';
    $d = ltrim($d, '0');
    if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;
    if (strlen($d) === 12 && substr($d, 0, 2) === '55' && preg_match('/^[6-9]$/', $d[4])) {
        $d = substr($d, 0, 4) . '9' . substr($d, 4);
    }
    if (strlen($d) < 10 || strlen($d) > 15) return '';
    return $d;
}

function wa_normalizar_texto($t): string {
    $t = mb_strtolower(trim((string)$t), 'UTF-8');
    $t = strtr($t, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    $t = preg_replace('/[^a-z0-9 ]+/', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

/**
 * Pedido de saída. Conservador de propósito: só a mensagem inteira conta, para
 * "vou parar de treinar" não descadastrar ninguém. Errar para o lado de sair é
 * aceitável; errar para o lado de insistir não é.
 */
function wa_eh_pedido_de_saida($texto): bool {
    $t = wa_normalizar_texto($texto);
    if ($t === '') return false;
    static $exatos = [
        'sair', 'parar', 'pare', 'stop', 'cancelar', 'cancele', 'descadastrar', 'remover',
        'sair da lista', 'me remova', 'me tire da lista', 'parar de receber', 'pare de enviar',
        'pare de enviar mensagens', 'nao quero mais', 'nao quero receber', 'nao quero mais receber',
        'nao quero receber mensagens', 'nao quero mais mensagens', 'nao me envie mais',
        'nao envie mais mensagens', 'nao quero mais receber mensagens',
    ];
    if (in_array($t, $exatos, true)) return true;
    return (bool)preg_match('/^(por favor )?(quero )?(sair|parar|cancelar)( de receber)?( as| essas| suas)?( mensagens?)?( por favor)?$/', $t);
}

// ─────────────────────────────────────────────────────────────────────────────
// Regras de envio
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Pode mandar agora? Devolve [bool, motivo].
 *
 * $o: tipo 'template' | 'texto'; proativa bool; automatica bool;
 *     horario_ini, horario_fim, max_proativas_dia.
 */
function wa_decidir_envio(array $c, DateTimeImmutable $agora, array $o): array {
    $o += ['tipo' => 'template', 'proativa' => true, 'automatica' => true,
           'horario_ini' => 8, 'horario_fim' => 21, 'max_proativas_dia' => 1];

    if (!empty($c['saiu_em'])) return [false, 'saiu'];
    if ($o['automatica'] && ($c['modo'] ?? 'robo') !== 'robo') return [false, 'modo_' . ($c['modo'] ?? '')];

    $janela = wa_janela_aberta($c['ultima_entrada_em'] ?? null, $agora);
    if ($o['tipo'] === 'texto' && !$janela) return [false, 'fora_da_janela'];

    if ($o['proativa']) {
        if (empty($c['optin_em'])) return [false, 'sem_consentimento'];
        if (!wa_hora_permitida($agora, (int)$o['horario_ini'], (int)$o['horario_fim'])) return [false, 'fora_do_horario'];
        $ult = wa_de_texto($c['ultima_proativa_em'] ?? null);
        if ($ult && (int)$o['max_proativas_dia'] > 0) {
            if ($ult->format('Y-m-d') === $agora->setTimezone(wa_tz())->format('Y-m-d')) return [false, 'limite_diario'];
        }
    }
    return [true, 'ok'];
}

/**
 * Agenda da sequência do teste grátis, a partir do momento do cadastro.
 * $diasTeste é a duração do teste (o admin pode mudar em Planos).
 * Devolve lista de [passo, DateTimeImmutable, condicao].
 * Condições (avaliadas na hora de enviar): todos, sem_treino, com_treino, sem_resposta.
 */
function wa_agenda_do_teste(DateTimeImmutable $cadastro, int $diasTeste = 3, int $ini = 8, int $fim = 21): array {
    $cadastro = $cadastro->setTimezone(wa_tz());
    $dia = $cadastro->setTime(0, 0, 0);
    $em = function (int $dias, int $hora) use ($dia) {
        return $dia->modify('+' . $dias . ' day')->setTime($hora, 0, 0);
    };
    return [
        ['t1', wa_proximo_horario_valido($cadastro, $ini, $fim), 'todos'],
        ['t2', $em(1, 10), 'sem_treino'],
        ['t3', $em(1, 20), 'com_treino'],
        ['t4', $em(2, 9), 'todos'],
        ['t5', $em($diasTeste, 9), 'todos'],
        ['t6', $em($diasTeste + 1, 18), 'sem_resposta'],
        ['t7', $em($diasTeste + 5, 18), 'sem_resposta'],
    ];
}

/** Confere a assinatura que a Meta manda em X-Hub-Signature-256. */
function wa_assinatura_valida(string $corpoBruto, string $cabecalho, string $appSecret): bool {
    if ($appSecret === '' || strpos($cabecalho, 'sha256=') !== 0) return false;
    $esperada = 'sha256=' . hash_hmac('sha256', $corpoBruto, $appSecret);
    return hash_equals($esperada, $cabecalho);
}

// ─────────────────────────────────────────────────────────────────────────────
// Banco
// ─────────────────────────────────────────────────────────────────────────────

function wa_log_erro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus-wa ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

/** Mesma conexão que os outros endpoints usam (app/config/db.php). */
function wa_conectar(): ?PDO {
    try {
        $cfg = @include __DIR__ . '/../config/db.php';
        if (is_array($cfg) && isset($cfg['host'])) {
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
            return new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        if (defined('DB_HOST')) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            return new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
    } catch (Throwable $e) {
        wa_log_erro($e);
    }
    return null;
}

function wa_ddl(string $driver): array {
    if ($driver === 'sqlite') {
        return [
            "CREATE TABLE IF NOT EXISTS intus_wa_contato (
                idcontato INTEGER PRIMARY KEY AUTOINCREMENT, telefone TEXT NOT NULL, nome TEXT, idatleta INTEGER, idlead INTEGER,
                genero TEXT, objetivo TEXT, etapa TEXT NOT NULL DEFAULT 'novo', modo TEXT NOT NULL DEFAULT 'robo',
                optin_em TEXT, optin_origem TEXT, saiu_em TEXT, ultima_entrada_em TEXT, ultima_proativa_em TEXT,
                precisa_humano INTEGER NOT NULL DEFAULT 0, motivo_humano TEXT, resumo TEXT, criado_em TEXT NOT NULL, atualizado_em TEXT NOT NULL)",
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_wa_telefone ON intus_wa_contato (telefone)",
            "CREATE TABLE IF NOT EXISTS intus_wa_mensagem (
                idmensagem INTEGER PRIMARY KEY AUTOINCREMENT, idcontato INTEGER NOT NULL, direcao TEXT NOT NULL, origem TEXT NOT NULL,
                tipo TEXT NOT NULL DEFAULT 'text', texto TEXT, template TEXT, wa_id TEXT, status TEXT NOT NULL DEFAULT 'recebida',
                erro TEXT, midia_id TEXT, tokens_in INTEGER, tokens_out INTEGER, processada INTEGER NOT NULL DEFAULT 0, criado_em TEXT NOT NULL)",
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_wa_waid ON intus_wa_mensagem (wa_id)",
            "CREATE TABLE IF NOT EXISTS intus_wa_agenda (
                idagenda INTEGER PRIMARY KEY AUTOINCREMENT, idcontato INTEGER NOT NULL, passo TEXT NOT NULL, enviar_em TEXT NOT NULL,
                condicao TEXT NOT NULL DEFAULT 'todos', status TEXT NOT NULL DEFAULT 'pendente', motivo TEXT, enviada_em TEXT)",
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_wa_agenda ON intus_wa_agenda (idcontato, passo)",
            "CREATE TABLE IF NOT EXISTS intus_secrets (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS intus_wa_contato (
            idcontato INT AUTO_INCREMENT PRIMARY KEY,
            telefone VARCHAR(20) NOT NULL,
            nome VARCHAR(150) NULL,
            idatleta INT NULL,
            idlead INT NULL,
            genero CHAR(1) NULL,
            objetivo VARCHAR(100) NULL,
            etapa VARCHAR(30) NOT NULL DEFAULT 'novo',
            modo VARCHAR(10) NOT NULL DEFAULT 'robo',
            optin_em DATETIME NULL,
            optin_origem VARCHAR(50) NULL,
            saiu_em DATETIME NULL,
            ultima_entrada_em DATETIME NULL,
            ultima_proativa_em DATETIME NULL,
            precisa_humano TINYINT(1) NOT NULL DEFAULT 0,
            motivo_humano VARCHAR(100) NULL,
            resumo TEXT NULL,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL,
            UNIQUE KEY uk_telefone (telefone),
            KEY idx_etapa (etapa),
            KEY idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS intus_wa_mensagem (
            idmensagem BIGINT AUTO_INCREMENT PRIMARY KEY,
            idcontato INT NOT NULL,
            direcao CHAR(1) NOT NULL,
            origem VARCHAR(12) NOT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'text',
            texto TEXT NULL,
            template VARCHAR(60) NULL,
            wa_id VARCHAR(100) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'recebida',
            erro VARCHAR(255) NULL,
            midia_id VARCHAR(100) NULL,
            tokens_in INT NULL,
            tokens_out INT NULL,
            processada TINYINT(1) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL,
            UNIQUE KEY uk_wa_id (wa_id),
            KEY idx_contato (idcontato, idmensagem),
            KEY idx_pendente (direcao, processada)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS intus_wa_agenda (
            idagenda INT AUTO_INCREMENT PRIMARY KEY,
            idcontato INT NOT NULL,
            passo VARCHAR(30) NOT NULL,
            enviar_em DATETIME NOT NULL,
            condicao VARCHAR(20) NOT NULL DEFAULT 'todos',
            status VARCHAR(12) NOT NULL DEFAULT 'pendente',
            motivo VARCHAR(100) NULL,
            enviada_em DATETIME NULL,
            UNIQUE KEY uk_contato_passo (idcontato, passo),
            KEY idx_fila (status, enviar_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS intus_secrets (
            chave VARCHAR(80) PRIMARY KEY,
            valor LONGTEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

function wa_garantir_tabelas(PDO $pdo): void {
    static $feito = null;
    if ($feito === null) $feito = new WeakMap();
    if (isset($feito[$pdo])) return;
    foreach (wa_ddl((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) as $sql) $pdo->exec($sql);
    $feito[$pdo] = true;
}

function wa_segredo(PDO $pdo, string $chave): ?array {
    try {
        $st = $pdo->prepare('SELECT valor FROM intus_secrets WHERE chave = ? LIMIT 1');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        if ($v === false || $v === null) return null;
        $a = json_decode((string)$v, true);
        return is_array($a) ? $a : null;
    } catch (Throwable $e) {
        wa_log_erro($e);
        return null;
    }
}

function wa_segredo_gravar(PDO $pdo, string $chave, array $dados): void {
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare('SELECT 1 FROM intus_secrets WHERE chave = ?');
    $st->execute([$chave]);
    if ($st->fetchColumn()) {
        $pdo->prepare('UPDATE intus_secrets SET valor = ? WHERE chave = ?')->execute([$json, $chave]);
    } else {
        $pdo->prepare('INSERT INTO intus_secrets (chave, valor) VALUES (?, ?)')->execute([$chave, $json]);
    }
}

function wa_ajustes_padrao(): array {
    return [
        'nome_atendente' => 'Cora',
        'modelo' => 'claude-opus-5-5',
        'horario_ini' => 8,
        'horario_fim' => 21,
        'max_proativas_dia' => 1,
        'dias_teste' => 3,
    ];
}

function wa_ajustes(PDO $pdo): array {
    $salvo = wa_segredo($pdo, 'wa_ajustes') ?: [];
    return array_merge(wa_ajustes_padrao(), array_intersect_key($salvo, wa_ajustes_padrao()));
}

function wa_config_whatsapp(PDO $pdo): ?array {
    $c = wa_segredo($pdo, 'whatsapp_config');
    if (!$c || empty($c['phone_number_id']) || empty($c['access_token'])) return null;
    $c += ['api_version' => 'v23.0', 'verify_token' => '', 'app_secret' => ''];
    return $c;
}

function wa_contato_por_telefone(PDO $pdo, string $tel): ?array {
    $st = $pdo->prepare('SELECT * FROM intus_wa_contato WHERE telefone = ? LIMIT 1');
    $st->execute([$tel]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function wa_contato_por_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM intus_wa_contato WHERE idcontato = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function wa_contato_obter_ou_criar(PDO $pdo, string $tel, ?string $nome, DateTimeImmutable $agora): array {
    $c = wa_contato_por_telefone($pdo, $tel);
    if ($c) {
        if ($nome !== null && $nome !== '' && empty($c['nome'])) {
            wa_contato_atualizar($pdo, (int)$c['idcontato'], ['nome' => mb_substr($nome, 0, 150)], $agora);
            $c['nome'] = mb_substr($nome, 0, 150);
        }
        return $c;
    }
    try {
        $pdo->prepare('INSERT INTO intus_wa_contato (telefone, nome, etapa, modo, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$tel, $nome !== null && $nome !== '' ? mb_substr($nome, 0, 150) : null, 'novo', 'robo', wa_fmt($agora), wa_fmt($agora)]);
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;
    }
    return wa_contato_por_telefone($pdo, $tel);
}

function wa_contato_atualizar(PDO $pdo, int $id, array $campos, DateTimeImmutable $agora): void {
    static $permitidos = ['nome', 'idatleta', 'idlead', 'genero', 'objetivo', 'etapa', 'modo', 'optin_em', 'optin_origem',
        'saiu_em', 'ultima_entrada_em', 'ultima_proativa_em', 'precisa_humano', 'motivo_humano', 'resumo'];
    $campos = array_intersect_key($campos, array_flip($permitidos));
    $campos['atualizado_em'] = wa_fmt($agora);
    $sets = []; $vals = [];
    foreach ($campos as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
    $vals[] = $id;
    $pdo->prepare('UPDATE intus_wa_contato SET ' . implode(', ', $sets) . ' WHERE idcontato = ?')->execute($vals);
}

/** Grava a mensagem. Devolve o id, ou null se o wa_id já existia (reentrega da Meta). */
function wa_msg_registrar(PDO $pdo, array $d, DateTimeImmutable $agora): ?int {
    $d += ['tipo' => 'text', 'texto' => null, 'template' => null, 'wa_id' => null, 'status' => 'recebida', 'erro' => null,
           'midia_id' => null, 'tokens_in' => null, 'tokens_out' => null, 'processada' => 0, 'criado_em' => wa_fmt($agora)];
    try {
        $pdo->prepare('INSERT INTO intus_wa_mensagem (idcontato, direcao, origem, tipo, texto, template, wa_id, status, erro, midia_id, tokens_in, tokens_out, processada, criado_em)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$d['idcontato'], $d['direcao'], $d['origem'], $d['tipo'], $d['texto'], $d['template'], $d['wa_id'], $d['status'],
                       $d['erro'] !== null ? mb_substr((string)$d['erro'], 0, 255) : null, $d['midia_id'], $d['tokens_in'], $d['tokens_out'],
                       $d['processada'], $d['criado_em']]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') return null;
        throw $e;
    }
}

/** Status só avança: enviada < entregue < lida. 'falhou' vale sempre. */
function wa_msg_atualizar_status(PDO $pdo, string $waId, string $status, ?string $erro = null): void {
    static $ordem = ['enviando' => 0, 'enviada' => 1, 'entregue' => 2, 'lida' => 3];
    $st = $pdo->prepare('SELECT idmensagem, status FROM intus_wa_mensagem WHERE wa_id = ? LIMIT 1');
    $st->execute([$waId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return;
    if ($status !== 'falhou' && isset($ordem[$m['status']]) && isset($ordem[$status]) && $ordem[$status] <= $ordem[$m['status']]) return;
    $pdo->prepare('UPDATE intus_wa_mensagem SET status = ?, erro = ? WHERE idmensagem = ?')
        ->execute([$status, $erro !== null ? mb_substr($erro, 0, 255) : null, $m['idmensagem']]);
}

function wa_agenda_cancelar_pendentes(PDO $pdo, int $idcontato, string $motivo): void {
    $pdo->prepare("UPDATE intus_wa_agenda SET status = 'cancelada', motivo = ? WHERE idcontato = ? AND status = 'pendente'")
        ->execute([mb_substr($motivo, 0, 100), $idcontato]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Entrada: o que a Meta manda para o webhook
// ─────────────────────────────────────────────────────────────────────────────

const WA_TEXTO_SAIDA_CONFIRMADA = 'Pronto, não vou mais te enviar mensagens por aqui. Se quiser voltar a conversar, é só me chamar.';

/** Texto legível de uma mensagem recebida, e o id da mídia se houver. */
function wa_extrair_mensagem(array $m): array {
    $tipo = (string)($m['type'] ?? 'unsupported');
    $texto = ''; $midia = null;
    switch ($tipo) {
        case 'text': $texto = (string)($m['text']['body'] ?? ''); break;
        case 'button': $texto = (string)($m['button']['text'] ?? ''); break;
        case 'interactive':
            $i = $m['interactive'] ?? [];
            $texto = (string)($i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '');
            break;
        case 'image': case 'video': case 'audio': case 'document': case 'sticker':
            $texto = (string)($m[$tipo]['caption'] ?? '');
            $midia = isset($m[$tipo]['id']) ? (string)$m[$tipo]['id'] : null;
            break;
    }
    return [$tipo, $texto, $midia];
}

/**
 * Processa o corpo de um webhook. Idempotente: a Meta reentrega, e o wa_id
 * único garante que a mesma mensagem não vira duas.
 *
 * $ctx: agora (DateTimeImmutable), enviar (callable(array $contato, string $texto): void) usado
 *       para confirmar a saída. Sem 'enviar', a confirmação não é mandada.
 */
function wa_processar_payload(PDO $pdo, array $payload, array $ctx = []): array {
    $agora = $ctx['agora'] ?? wa_agora();
    $enviar = $ctx['enviar'] ?? null;
    $res = ['mensagens' => 0, 'duplicadas' => 0, 'saidas' => 0, 'status' => 0, 'midias' => 0];

    foreach (($payload['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $ch) {
            $v = $ch['value'] ?? [];

            $nomes = [];
            foreach (($v['contacts'] ?? []) as $ct) {
                if (isset($ct['wa_id'])) $nomes[wa_normalizar_telefone($ct['wa_id'])] = (string)($ct['profile']['name'] ?? '');
            }

            foreach (($v['messages'] ?? []) as $m) {
                $tel = wa_normalizar_telefone($m['from'] ?? '');
                if ($tel === '') continue;
                $c = wa_contato_obter_ou_criar($pdo, $tel, $nomes[$tel] ?? null, $agora);
                [$tipo, $texto, $midia] = wa_extrair_mensagem($m);
                $quando = isset($m['timestamp']) ? (new DateTimeImmutable('@' . (int)$m['timestamp']))->setTimezone(wa_tz()) : $agora;
                $saida = $texto !== '' && wa_eh_pedido_de_saida($texto);
                $waIdEntrada = (string)($m['id'] ?? '');

                $id = wa_msg_registrar($pdo, [
                    'idcontato' => (int)$c['idcontato'], 'direcao' => 'E', 'origem' => 'lead', 'tipo' => $tipo,
                    'texto' => $texto !== '' ? $texto : null, 'wa_id' => $waIdEntrada !== '' ? $waIdEntrada : null, 'status' => 'recebida',
                    'midia_id' => $midia, 'processada' => $saida ? 1 : 0, 'criado_em' => wa_fmt($quando),
                ], $agora);
                if ($id === null) { $res['duplicadas']++; continue; }
                $res['mensagens']++;

                $upd = ['ultima_entrada_em' => wa_fmt($quando)];
                if ($midia !== null) {
                    $upd['precisa_humano'] = 1;
                    $upd['motivo_humano'] = 'midia_recebida';
                    $res['midias']++;
                }
                if ($saida) {
                    $upd['saiu_em'] = wa_fmt($agora);
                    $upd['etapa'] = 'saiu';
                    $upd['modo'] = 'pausado';
                    wa_agenda_cancelar_pendentes($pdo, (int)$c['idcontato'], 'saiu');
                    $res['saidas']++;
                }
                wa_contato_atualizar($pdo, (int)$c['idcontato'], $upd, $agora);

                if ($saida && $enviar) {
                    try { $enviar(wa_contato_por_id($pdo, (int)$c['idcontato']), WA_TEXTO_SAIDA_CONFIRMADA); }
                    catch (Throwable $e) { wa_log_erro($e); }
                }
            }

            static $mapa = ['sent' => 'enviada', 'delivered' => 'entregue', 'read' => 'lida', 'failed' => 'falhou'];
            foreach (($v['statuses'] ?? []) as $s) {
                $novo = $mapa[$s['status'] ?? ''] ?? null;
                if ($novo === null || empty($s['id'])) continue;
                $erro = null;
                if ($novo === 'falhou') $erro = trim(($s['errors'][0]['title'] ?? '') . ' ' . ($s['errors'][0]['message'] ?? '')) ?: 'falha sem detalhe';
                wa_msg_atualizar_status($pdo, (string)$s['id'], $novo, $erro);
                $res['status']++;
            }
        }
    }
    return $res;
}

// ─────────────────────────────────────────────────────────────────────────────
// Saída: chamadas à API da Meta
// ─────────────────────────────────────────────────────────────────────────────

function wa_url_api(array $cfg, string $caminho): string {
    return 'https://graph.facebook.com/' . $cfg['api_version'] . '/' . $caminho;
}

/** POST JSON. Devolve ['status' => int, 'json' => ?array, 'erro' => string]. */
function wa_http_post(string $url, array $corpo, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($corpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
    ]);
    $out = curl_exec($ch);
    $erro = $out === false ? curl_error($ch) : '';
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => is_string($out) ? json_decode($out, true) : null, 'erro' => $erro];
}

function wa_http_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $out = curl_exec($ch);
    $erro = $out === false ? curl_error($ch) : '';
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => is_string($out) ? json_decode($out, true) : null, 'erro' => $erro];
}

function wa_erro_da_resposta(array $r): string {
    if ($r['erro'] !== '') return 'rede: ' . $r['erro'];
    $m = $r['json']['error']['message'] ?? null;
    return $m ? ('Meta ' . $r['status'] . ': ' . $m) : ('Meta ' . $r['status']);
}

/**
 * Envia uma mensagem e registra no histórico (mesmo quando falha).
 * $corpo é o que vai para /messages (sem messaging_product e to, que entram aqui).
 * Devolve ['ok' => bool, 'wa_id' => ?string, 'erro' => ?string].
 */
function wa_enviar_bruto(PDO $pdo, array $cfg, array $contato, array $corpo, array $registro, ?callable $http = null, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?? wa_agora();
    $corpo = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $contato['telefone']] + $corpo;
    $r = ($http ?? 'wa_http_post')(wa_url_api($cfg, $cfg['phone_number_id'] . '/messages'), $corpo, $cfg['access_token']);
    $waId = $r['json']['messages'][0]['id'] ?? null;
    $ok = $r['status'] >= 200 && $r['status'] < 300 && $waId !== null;
    $erro = $ok ? null : wa_erro_da_resposta($r);
    wa_msg_registrar($pdo, $registro + [
        'idcontato' => (int)$contato['idcontato'], 'direcao' => 'S', 'wa_id' => $waId,
        'status' => $ok ? 'enviada' : 'falhou', 'erro' => $erro, 'processada' => 1,
    ], $agora);
    return ['ok' => $ok, 'wa_id' => $waId, 'erro' => $erro];
}

/** Texto livre. Só vale dentro da janela de 24h; quem chama confere com wa_decidir_envio. */
function wa_enviar_texto(PDO $pdo, array $cfg, array $contato, string $texto, string $origem, ?callable $http = null, ?DateTimeImmutable $agora = null): array {
    return wa_enviar_bruto($pdo, $cfg, $contato,
        ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $texto]],
        ['origem' => $origem, 'tipo' => 'text', 'texto' => $texto], $http, $agora);
}

/** Template aprovado. $textoRenderizado é o que a pessoa vai ler (para o histórico e o painel). */
function wa_enviar_template(PDO $pdo, array $cfg, array $contato, string $nomeTemplate, array $parametros, string $textoRenderizado, string $origem = 'sequencia', string $idioma = 'pt_BR', ?callable $http = null, ?DateTimeImmutable $agora = null): array {
    $params = [];
    foreach ($parametros as $p) $params[] = ['type' => 'text', 'text' => (string)$p];
    $componentes = $params ? [['type' => 'body', 'parameters' => $params]] : [];
    return wa_enviar_bruto($pdo, $cfg, $contato,
        ['type' => 'template', 'template' => ['name' => $nomeTemplate, 'language' => ['code' => $idioma], 'components' => $componentes]],
        ['origem' => $origem, 'tipo' => 'template', 'texto' => $textoRenderizado, 'template' => $nomeTemplate], $http, $agora);
}

/** Confere token e número perguntando à Meta. Não mostra nem grava o token. */
function wa_testar_conexao(array $cfg, ?callable $http = null): array {
    $url = wa_url_api($cfg, $cfg['phone_number_id'] . '?fields=display_phone_number,verified_name,quality_rating');
    $r = ($http ?? 'wa_http_get')($url, $cfg['access_token']);
    if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($r['json'])) return ['ok' => false, 'erro' => wa_erro_da_resposta($r)];
    return ['ok' => true, 'numero' => $r['json']['display_phone_number'] ?? '', 'nome' => $r['json']['verified_name'] ?? '', 'qualidade' => $r['json']['quality_rating'] ?? ''];
}
