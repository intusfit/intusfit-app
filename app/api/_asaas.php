<?php
/**
 * Cobrança da Intus pelo Asaas (Pix, boleto e cartão numa página do Asaas) e aviso automático de pagamento.
 * Biblioteca interna (o "_" no nome bloqueia o acesso direto); o endpoint é asaas.php.
 *
 * Como funciona (decisões de 06/11/2026):
 *  - O painel gera a cobrança de UMA mensalidade (intus_mensalidade): cria o cliente no Asaas (pelo CPF), cria a cobrança com
 *    externalReference "intus:mens:<idmensalidade>" e devolve o link de pagamento (invoiceUrl), que a equipe manda pelo WhatsApp.
 *    Cartão, Pix e boleto acontecem na página do Asaas: nada disso passa por aqui.
 *  - Quando o Asaas avisa que foi pago (webhook), este arquivo SÓ REGISTRA o recebimento em intus_asaas_cobranca. A BAIXA da mensalidade
 *    (marcar paga e gerar o ciclo seguinte) continua sendo feita pelo painel, no mesmo formulário de sempre, com um toque: a regra de baixa mora
 *    no cliente (api.js e mensalidades.html) e este arquivo não a duplica. Nada altera mensalidade ou dado de aluno sozinho.
 *  - Segredos (chave de API e token do webhook) ficam no banco (intus_secrets, chave asaas_config), gravados por admin/asaas-keys.php.
 *    Nenhuma rota devolve esses valores.
 *  - A conta do Asaas também recebe as vendas do CDD (outro webhook, outra URL). Cobrança que não é "intus:mens:" é ignorada aqui.
 * SQL portável de propósito (roda igual em SQLite nos testes e no MySQL 5.6 de produção).
 */

const ASAAS_URLS = ['producao' => 'https://api.asaas.com/v3', 'sandbox' => 'https://api-sandbox.asaas.com/v3'];
const ASAAS_VALOR_MINIMO = 5.00;   // o Asaas não cria cobrança abaixo disso

function asaas_ddl(string $driver): array {
    if ($driver === 'sqlite') {
        return [
            "CREATE TABLE IF NOT EXISTS intus_secrets (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS intus_asaas_cobranca (
                idcobranca INTEGER PRIMARY KEY AUTOINCREMENT, idmensalidade INTEGER NOT NULL, idatleta INTEGER NOT NULL,
                asaas_payment_id TEXT NOT NULL, asaas_customer_id TEXT, valor REAL NOT NULL, vencimento TEXT, url TEXT,
                status TEXT NOT NULL DEFAULT 'criada', forma TEXT, valor_recebido REAL, recebido_em TEXT, baixado_em TEXT,
                criado_por INTEGER, criado_em TEXT NOT NULL, atualizado_em TEXT NOT NULL)",
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_asaas_pay ON intus_asaas_cobranca (asaas_payment_id)",
            "CREATE INDEX IF NOT EXISTS ix_asaas_mens ON intus_asaas_cobranca (idmensalidade)",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS intus_secrets (chave VARCHAR(80) PRIMARY KEY, valor LONGTEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS intus_asaas_cobranca (
            idcobranca INT AUTO_INCREMENT PRIMARY KEY,
            idmensalidade INT NOT NULL,
            idatleta INT NOT NULL,
            asaas_payment_id VARCHAR(40) NOT NULL,
            asaas_customer_id VARCHAR(40) NULL,
            valor DECIMAL(10,2) NOT NULL,
            vencimento DATE NULL,
            url VARCHAR(300) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'criada',
            forma VARCHAR(12) NULL,
            valor_recebido DECIMAL(10,2) NULL,
            recebido_em DATE NULL,
            baixado_em DATETIME NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL,
            UNIQUE KEY uk_asaas_pay (asaas_payment_id),
            INDEX ix_asaas_mens (idmensalidade),
            INDEX ix_asaas_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}
function asaas_garantir_tabelas(PDO $pdo): void {
    static $feito = null;
    if ($feito === null) $feito = new WeakMap();
    if (isset($feito[$pdo])) return;
    foreach (asaas_ddl((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) as $sql) $pdo->exec($sql);
    $feito[$pdo] = true;
}
function asaas_agora(): string { return date('Y-m-d H:i:s'); }

/* ───────────────────────── Configuração (segredos) ───────────────────────── */

function asaas_cfg(PDO $pdo): array {
    try {
        $st = $pdo->prepare('SELECT valor FROM intus_secrets WHERE chave = ? LIMIT 1'); $st->execute(['asaas_config']);
        $v = $st->fetchColumn();
        $a = ($v === false || $v === null) ? null : json_decode((string)$v, true);
    } catch (Throwable $e) { $a = null; }
    $a = is_array($a) ? $a : [];
    return ['apikey' => (string)($a['apikey'] ?? ''), 'token' => (string)($a['token'] ?? ''), 'ambiente' => (($a['ambiente'] ?? '') === 'sandbox') ? 'sandbox' : 'producao'];
}
function asaas_cfg_gravar(PDO $pdo, array $cfg): void {
    $json = json_encode(['apikey' => (string)($cfg['apikey'] ?? ''), 'token' => (string)($cfg['token'] ?? ''), 'ambiente' => (($cfg['ambiente'] ?? '') === 'sandbox') ? 'sandbox' : 'producao'], JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare('SELECT 1 FROM intus_secrets WHERE chave = ?'); $st->execute(['asaas_config']);
    if ($st->fetchColumn()) $pdo->prepare('UPDATE intus_secrets SET valor = ? WHERE chave = ?')->execute([$json, 'asaas_config']);
    else $pdo->prepare('INSERT INTO intus_secrets (chave, valor) VALUES (?, ?)')->execute(['asaas_config', $json]);
}

/* ───────────────────────── Chamadas ao Asaas ───────────────────────── */

/** Em teste, $GLOBALS['asaasHttpFake'] (callable) responde no lugar da internet. Devolve [status HTTP, json]. Lança RuntimeException em falha de rede. */
function asaas_http(PDO $pdo, string $metodo, string $caminho, ?array $corpo = null): array {
    $cfg = asaas_cfg($pdo);
    if ($cfg['apikey'] === '') throw new RuntimeException('chave_api_ausente', 503);
    $url = ASAAS_URLS[$cfg['ambiente']] . $caminho;
    if (!empty($GLOBALS['asaasHttpFake']) && is_callable($GLOBALS['asaasHttpFake'])) return call_user_func($GLOBALS['asaasHttpFake'], $metodo, $url, $corpo);
    if (!function_exists('curl_init')) throw new RuntimeException('asaas_sem_curl', 502);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'access_token: ' . $cfg['apikey'], 'User-Agent: Intus-Fit'],
        CURLOPT_POSTFIELDS => $corpo === null ? null : json_encode($corpo, JSON_UNESCAPED_UNICODE)]);
    $r = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($r === false) throw new RuntimeException('asaas_fora_do_ar', 502);
    $j = json_decode((string)$r, true);
    return [$st, is_array($j) ? $j : []];
}

/** Mensagem curta do erro devolvido pelo Asaas ({"errors":[{"description":"..."}]}). */
function asaas_erro_texto(array $j, int $st): string {
    $d = (string)($j['errors'][0]['description'] ?? '');
    if ($st === 401 || $st === 403) return 'O Asaas recusou a chave de API. Confira o ambiente (produção ou testes) e a chave em admin/asaas-keys.php.';
    return $d !== '' ? 'O Asaas recusou: ' . $d : 'O Asaas respondeu com erro ' . $st . '.';
}

/* ───────────────────────── Regras ───────────────────────── */

/** CPF (11 dígitos) ou CNPJ (14) com dígitos verificadores corretos. Devolve só os dígitos, ou '' se inválido. */
function asaas_documento(string $v): string {
    $d = preg_replace('/\D/', '', $v);
    if (strlen($d) === 11) {
        if (preg_match('/^(\d)\1{10}$/', $d)) return '';
        for ($t = 9; $t < 11; $t++) {
            $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$d[$i] * (($t + 1) - $i);
            $dv = ((10 * $s) % 11) % 10;
            if ((int)$d[$t] !== $dv) return '';
        }
        return $d;
    }
    if (strlen($d) === 14) {
        if (preg_match('/^(\d)\1{13}$/', $d)) return '';
        foreach ([[12, [5,4,3,2,9,8,7,6,5,4,3,2]], [13, [6,5,4,3,2,9,8,7,6,5,4,3,2]]] as [$n, $pesos]) {
            $s = 0; for ($i = 0; $i < $n; $i++) $s += (int)$d[$i] * $pesos[$i];
            $r = $s % 11; $dv = $r < 2 ? 0 : 11 - $r;
            if ((int)$d[$n] !== $dv) return '';
        }
        return $d;
    }
    return '';
}

/** Valor a cobrar de uma mensalidade: combinado menos desconto (juros por atraso a equipe decide na baixa). */
function asaas_valor_mensalidade(array $m): float {
    return round(max(0.0, (float)($m['vlpagar'] ?? 0) - (float)($m['vldesconto'] ?? 0)), 2);
}

/** Vencimento da cobrança: o início do ciclo (a data em que se paga), ou hoje se já passou. O Asaas não aceita data no passado. */
function asaas_vencimento(array $m, ?string $hoje = null): string {
    $hoje = $hoje ?: date('Y-m-d');
    $d = substr((string)($m['dtinicio'] ?? ''), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = substr((string)($m['dtvencimento'] ?? ''), 0, 10);
    return (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d > $hoje) ? $d : $hoje;
}

function asaas_forma(string $billing): string {
    $b = strtoupper($billing);
    if ($b === 'PIX') return 'pix';
    if ($b === 'CREDIT_CARD' || $b === 'DEBIT_CARD') return 'cartao';
    if ($b === 'BOLETO') return 'boleto';
    return $b === 'RECEIVED_IN_CASH' ? 'dinheiro' : 'pix';
}

/** Acha o cliente do Asaas pelo CPF/CNPJ ou cria. Notificações do Asaas (e-mail e SMS, que são cobrados) ficam desligadas: a equipe manda o link. */
function asaas_cliente_garantir(PDO $pdo, array $atleta): string {
    $doc = (string)$atleta['documento'];
    [$st, $j] = asaas_http($pdo, 'GET', '/customers?cpfCnpj=' . rawurlencode($doc));
    if ($st < 200 || $st >= 300) throw new RuntimeException(asaas_erro_texto($j, $st), 502);
    if (!empty($j['data'][0]['id'])) return (string)$j['data'][0]['id'];
    $corpo = ['name' => (string)$atleta['nome'], 'cpfCnpj' => $doc, 'externalReference' => 'intus:atleta:' . (int)$atleta['idatleta'], 'notificationDisabled' => true];
    if (!empty($atleta['email']) && filter_var($atleta['email'], FILTER_VALIDATE_EMAIL)) $corpo['email'] = (string)$atleta['email'];
    $tel = preg_replace('/\D/', '', (string)($atleta['tel'] ?? ''));
    if (preg_match('/^(55)?(\d{2})(\d{8,9})$/', $tel, $mm)) $corpo['mobilePhone'] = $mm[2] . $mm[3];
    [$st, $j] = asaas_http($pdo, 'POST', '/customers', $corpo);
    if ($st < 200 || $st >= 300 || empty($j['id'])) throw new RuntimeException(asaas_erro_texto($j, $st), 422);
    return (string)$j['id'];
}

/**
 * Gera (ou devolve a que já existe) a cobrança de uma mensalidade. Devolve ['url' =>, 'valor' =>, 'vencimento' =>, 'nova' => bool].
 * Lança RuntimeException com texto para a equipe e o código HTTP como "code".
 * $m = linha de intus_mensalidade; $atleta = ['idatleta','nome','email','tel','cpf'].
 */
function asaas_criar_cobranca(PDO $pdo, array $m, array $atleta, int $idusuario): array {
    asaas_garantir_tabelas($pdo);
    $idm = (int)$m['idmensalidade'];
    if (($m['stpgto'] ?? 'N') === 'S') throw new RuntimeException('Esta mensalidade já está paga.', 409);
    $valor = asaas_valor_mensalidade($m);
    if ($valor < ASAAS_VALOR_MINIMO) throw new RuntimeException('O Asaas só cobra a partir de R$ 5,00. Confira o valor desta mensalidade.', 422);
    $doc = asaas_documento((string)($atleta['cpf'] ?? ''));
    if ($doc === '') throw new RuntimeException('Este cliente não tem um CPF válido no cadastro. Corrija em Clientes e tente de novo.', 422);
    $venc = asaas_vencimento($m);

    $st = $pdo->prepare("SELECT * FROM intus_asaas_cobranca WHERE idmensalidade = ? AND status = 'criada' ORDER BY idcobranca DESC LIMIT 1"); $st->execute([$idm]);
    $ja = $st->fetch(PDO::FETCH_ASSOC);
    if ($ja && abs((float)$ja['valor'] - $valor) < 0.005 && !empty($ja['url'])) return ['url' => (string)$ja['url'], 'valor' => $valor, 'vencimento' => (string)$ja['vencimento'], 'nova' => false];
    // valor mudou desde o último link: o antigo é cancelado no Asaas para não ficar duas cobranças abertas da mesma mensalidade
    if ($ja) {
        try { asaas_http($pdo, 'DELETE', '/payments/' . rawurlencode((string)$ja['asaas_payment_id'])); } catch (Throwable $e) { /* se não deu, o aviso do Asaas ainda registra qual foi paga */ }
        $pdo->prepare("UPDATE intus_asaas_cobranca SET status = 'cancelada', atualizado_em = ? WHERE idcobranca = ?")->execute([asaas_agora(), (int)$ja['idcobranca']]);
    }

    $atleta['documento'] = $doc;
    $cliente = asaas_cliente_garantir($pdo, $atleta);
    $desc = 'Intus Fit: ' . trim((string)($m['dshistorico'] ?? 'Mensalidade'));
    [$s, $j] = asaas_http($pdo, 'POST', '/payments', ['customer' => $cliente, 'billingType' => 'UNDEFINED', 'value' => $valor, 'dueDate' => $venc,
        'description' => mb_substr($desc, 0, 200), 'externalReference' => 'intus:mens:' . $idm]);
    if ($s < 200 || $s >= 300 || empty($j['id'])) throw new RuntimeException(asaas_erro_texto($j, $s), 422);
    $url = (string)($j['invoiceUrl'] ?? '');
    $agora = asaas_agora();
    $pdo->prepare('INSERT INTO intus_asaas_cobranca (idmensalidade, idatleta, asaas_payment_id, asaas_customer_id, valor, vencimento, url, status, criado_por, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$idm, (int)$atleta['idatleta'], (string)$j['id'], $cliente, $valor, $venc, $url, 'criada', $idusuario ?: null, $agora, $agora]);
    return ['url' => $url, 'valor' => $valor, 'vencimento' => $venc, 'nova' => true];
}

/* ───────────────────────── Webhook ───────────────────────── */

/**
 * Aviso do Asaas. $tokenRecebido = cabeçalho asaas-access-token. Devolve [status HTTP, corpo].
 * Só registra: recebida, estornada ou cancelada. Nunca mexe em mensalidade. "Pelo menos uma vez": repetir o mesmo aviso não muda nada.
 */
function asaas_webhook(PDO $pdo, array $p, string $tokenRecebido): array {
    $cfg = asaas_cfg($pdo);
    if ($cfg['token'] === '') return [503, ['ok' => false, 'erro' => 'webhook_nao_configurado']];
    if ($tokenRecebido === '' || !hash_equals($cfg['token'], $tokenRecebido)) return [401, ['ok' => false, 'erro' => 'nao_autorizado']];
    asaas_garantir_tabelas($pdo);
    $ev = strtoupper(trim((string)($p['event'] ?? '')));
    $pay = isset($p['payment']) && is_array($p['payment']) ? $p['payment'] : [];
    $idPay = (string)($pay['id'] ?? '');
    if ($idPay === '') return [200, ['ok' => true, 'resultado' => 'ignorado']];
    $novo = null;
    if (in_array($ev, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true)) $novo = 'recebida';
    elseif (in_array($ev, ['PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK_REQUESTED'], true)) $novo = 'estornada';
    elseif ($ev === 'PAYMENT_DELETED') $novo = 'cancelada';
    if ($novo === null) return [200, ['ok' => true, 'resultado' => 'ignorado']];

    $st = $pdo->prepare('SELECT * FROM intus_asaas_cobranca WHERE asaas_payment_id = ?'); $st->execute([$idPay]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    $ref = (string)($pay['externalReference'] ?? '');
    $agora = asaas_agora();
    if (!$c) {
        // cobrança criada fora do painel (ex.: direto no Asaas) mas marcada para uma mensalidade da Intus
        if (!preg_match('/^intus:mens:(\d+)$/', $ref, $mm)) return [200, ['ok' => true, 'resultado' => 'outra_origem']];
        $m = $pdo->prepare('SELECT idmensalidade, idatleta FROM intus_mensalidade WHERE idmensalidade = ?'); $m->execute([(int)$mm[1]]);
        $mens = $m->fetch(PDO::FETCH_ASSOC);
        if (!$mens) return [200, ['ok' => true, 'resultado' => 'mensalidade_inexistente']];
        try {
            $pdo->prepare('INSERT INTO intus_asaas_cobranca (idmensalidade, idatleta, asaas_payment_id, asaas_customer_id, valor, vencimento, url, status, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([(int)$mens['idmensalidade'], (int)$mens['idatleta'], $idPay, (string)($pay['customer'] ?? ''), (float)($pay['value'] ?? 0), substr((string)($pay['dueDate'] ?? ''), 0, 10) ?: null, (string)($pay['invoiceUrl'] ?? ''), 'criada', $agora, $agora]);
        } catch (Throwable $e) { /* dois avisos ao mesmo tempo: o índice único segura o segundo */ }
        $st->execute([$idPay]); $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return [200, ['ok' => true, 'resultado' => 'ignorado']];
    }
    if ($novo === 'recebida') {
        if (in_array($c['status'], ['baixada'], true)) return [200, ['ok' => true, 'resultado' => 'repetido']];   // já deram baixa: não volta a pendente
        $d = (string)($pay['confirmedDate'] ?? ($pay['paymentDate'] ?? ($pay['clientPaymentDate'] ?? '')));
        $pdo->prepare("UPDATE intus_asaas_cobranca SET status = 'recebida', forma = ?, valor_recebido = ?, recebido_em = ?, atualizado_em = ? WHERE idcobranca = ?")
            ->execute([asaas_forma((string)($pay['billingType'] ?? '')), (float)($pay['value'] ?? $c['valor']), preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d'), $agora, (int)$c['idcobranca']]);
        return [200, ['ok' => true, 'resultado' => 'recebida']];
    }
    if ($novo === 'cancelada' && $c['status'] !== 'criada') return [200, ['ok' => true, 'resultado' => 'ignorado']];   // cobrança paga não "some" por exclusão posterior
    $pdo->prepare('UPDATE intus_asaas_cobranca SET status = ?, atualizado_em = ? WHERE idcobranca = ?')->execute([$novo, $agora, (int)$c['idcobranca']]);
    return [200, ['ok' => true, 'resultado' => $novo]];
}

/* ───────────────────────── Leitura para o painel ───────────────────────── */

/**
 * Para a tela de Matrículas: pagamentos recebidos esperando baixa, estornos recentes (de pagamento que já tinha baixa ou estava esperando) e
 * links abertos por mensalidade. $idsPermitidos = null (admin) ou a lista de alunos do professor.
 */
function asaas_para_painel(PDO $pdo, ?array $idsPermitidos): array {
    asaas_garantir_tabelas($pdo);
    $filtro = ''; $par = [];
    if ($idsPermitidos !== null) {
        if (!$idsPermitidos) return ['recebidas' => [], 'estornos' => [], 'abertas' => new stdClass()];
        $filtro = ' AND idatleta IN (' . implode(',', array_fill(0, count($idsPermitidos), '?')) . ')'; $par = array_map('intval', $idsPermitidos);
    }
    $q = function (string $where, array $extra = []) use ($pdo, $filtro, $par) {
        $st = $pdo->prepare("SELECT idcobranca, idmensalidade, idatleta, valor, valor_recebido, forma, recebido_em, url, status, vencimento FROM intus_asaas_cobranca WHERE $where$filtro ORDER BY idcobranca DESC LIMIT 200");
        $st->execute(array_merge($extra, $par));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $abertas = new stdClass();
    foreach ($q("status = 'criada'") as $r) { $k = (string)(int)$r['idmensalidade']; if (!isset($abertas->$k)) $abertas->$k = ['url' => $r['url'], 'valor' => (float)$r['valor'], 'vencimento' => $r['vencimento']]; }
    $desde = date('Y-m-d H:i:s', time() - 30 * 86400);
    return ['recebidas' => $q("status = 'recebida'"), 'estornos' => $q("status = 'estornada' AND atualizado_em >= ?", [$desde]), 'abertas' => $abertas];
}

/** Depois que a equipe dá a baixa (qualquer forma): as cobranças recebidas dessa mensalidade deixam de ser pendência. Devolve quantas mudaram. */
function asaas_marcar_baixado(PDO $pdo, int $idmensalidade): int {
    asaas_garantir_tabelas($pdo);
    $st = $pdo->prepare("UPDATE intus_asaas_cobranca SET status = 'baixada', baixado_em = ?, atualizado_em = ? WHERE idmensalidade = ? AND status = 'recebida'");
    $a = asaas_agora(); $st->execute([$a, $a, $idmensalidade]);
    $mudou = $st->rowCount();
    // links ainda abertos de uma mensalidade que acabou de ser paga por outro meio são cancelados no Asaas (senão o aluno ainda poderia pagar de novo)
    $ab = $pdo->prepare("SELECT idcobranca, asaas_payment_id FROM intus_asaas_cobranca WHERE idmensalidade = ? AND status = 'criada'"); $ab->execute([$idmensalidade]);
    foreach ($ab->fetchAll(PDO::FETCH_ASSOC) as $r) {
        try { asaas_http($pdo, 'DELETE', '/payments/' . rawurlencode((string)$r['asaas_payment_id'])); } catch (Throwable $e) { /* sem a chave ou sem rede: só some da lista */ }
        $pdo->prepare("UPDATE intus_asaas_cobranca SET status = 'cancelada', atualizado_em = ? WHERE idcobranca = ?")->execute([$a, (int)$r['idcobranca']]);
    }
    return $mudou;
}
