<?php
/**
 * CDD: parte comercial (ofertas, cupons, pagamentos) e a ligação com o gateway de pagamento.
 * Biblioteca interna (o "_" no nome bloqueia o acesso direto), carregada por _nucleo.php.
 *
 * Desenho para trocar de gateway sem refazer o sistema:
 *   1. Cada gateway tem um ADAPTADOR (cddGatewayEduzz) que traduz o aviso dele para um EVENTO COMUM:
 *        pago | reembolsado | contrato_cancelado | atrasado | ignorado
 *      com os mesmos campos (e-mail, nome, produto, valor, fatura, cupom...).
 *   2. O processamento (cddComercialProcessar) só conhece o evento comum: cria a aluna, matricula, registra o pagamento e manda o link de acesso.
 *   3. Cada produto do gateway vira uma OFERTA (cdd_oferta: gateway + código do produto no gateway, duração em meses, valor, link de venda).
 *      Para outro gateway basta escrever outro adaptador e cadastrar as ofertas dele; a matrícula e o financeiro não mudam.
 * A matrícula (cdd_matricula, uma por aluna) continua decidindo o acesso ao app. Venda paga renova a data de fim; reembolso suspende.
 * O mesmo aviso nunca é processado duas vezes (cdd_evento.id_externo é único). Produto desconhecido é só registrado (a conta do Eduzz
 * manda avisos de todos os produtos da Intus para o mesmo endereço).
 */

const CDD_CARENCIA_DIAS = 3;   // dias de folga depois do fim do período pago (pagamento por cartão/boleto às vezes atrasa um pouco)

function cddComercialDdl(string $c): array {
    return [
        'cdd_oferta' => "CREATE TABLE IF NOT EXISTS cdd_oferta (
            idoferta INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            gateway VARCHAR(20) NOT NULL DEFAULT 'eduzz',
            ref_gateway VARCHAR(40) NOT NULL DEFAULT '',
            meses INT NOT NULL DEFAULT 1,
            valor DECIMAL(10,2) NULL,
            link VARCHAR(300) NULL,
            ativo TINYINT NOT NULL DEFAULT 1,
            ordem INT NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL,
            UNIQUE INDEX idx_gw_ref (gateway, ref_gateway)
        ) $c",
        'cdd_cupom' => "CREATE TABLE IF NOT EXISTS cdd_cupom (
            idcupom INT AUTO_INCREMENT PRIMARY KEY,
            codigo VARCHAR(40) NOT NULL,
            gateway VARCHAR(20) NOT NULL DEFAULT 'eduzz',
            tipo VARCHAR(10) NOT NULL DEFAULT 'percentual',
            valor DECIMAL(10,2) NOT NULL DEFAULT 0,
            idoferta INT NULL,
            validade DATE NULL,
            limite_uso INT NULL,
            ativo TINYINT NOT NULL DEFAULT 1,
            obs VARCHAR(200) NULL,
            criado_em DATETIME NOT NULL,
            INDEX idx_codigo (codigo)
        ) $c",
        'cdd_pagamento' => "CREATE TABLE IF NOT EXISTS cdd_pagamento (
            idpagamento INT AUTO_INCREMENT PRIMARY KEY,
            idaluna INT NULL,
            idoferta INT NULL,
            gateway VARCHAR(20) NOT NULL,
            ref_externa VARCHAR(80) NOT NULL,
            contrato_ref VARCHAR(80) NULL,
            status VARCHAR(14) NOT NULL,
            valor DECIMAL(10,2) NOT NULL DEFAULT 0,
            forma VARCHAR(20) NULL,
            cupom VARCHAR(40) NULL,
            utm VARCHAR(80) NULL,
            pago_em DATETIME NULL,
            criado_em DATETIME NOT NULL,
            UNIQUE INDEX idx_gw_fatura (gateway, ref_externa),
            INDEX idx_aluna (idaluna)
        ) $c",
    ];
}

/* ───────────────────────── Adaptador do Eduzz ───────────────────────── */

/** Forma de pagamento do Eduzz (trans_paymentmethod) em texto. Só os códigos documentados; o resto vira "outro". */
function cddEduzzForma($v): string {
    $m = [1 => 'boleto', 32 => 'pix', 13 => 'cartao', 12 => 'cartao', 14 => 'cartao', 15 => 'cartao'];
    $n = (int)$v;
    return $m[$n] ?? ($n > 0 ? 'outro' : '');
}

/** Traduz um aviso (webhook) do Eduzz para o evento comum. Devolve null se não for um aviso reconhecível. */
function cddGatewayEduzz(array $p): ?array {
    $nome = strtolower(trim((string)($p['event_name'] ?? '')));
    $status = (int)($p['trans_status'] ?? 0);
    if ($nome === '' && $status === 0 && empty($p['recurrence_status'])) return null;
    $tipo = 'ignorado';
    if ($nome === 'invoice_paid' || ($nome === '' && $status === 3)) $tipo = 'pago';
    elseif ($nome === 'invoice_refunded' || ($nome === '' && $status === 7)) $tipo = 'reembolsado';
    elseif ($nome === 'contract_canceled') $tipo = 'contrato_cancelado';
    elseif (in_array($nome, ['contract_overdue', 'contract_delayed', 'contract_suspended'], true)) $tipo = 'atrasado';
    $prod = (string)($p['product_cod'] ?? '');
    if ($prod === '' && !empty($p['trans_items'][0]['item_product_id'])) $prod = (string)$p['trans_items'][0]['item_product_id'];
    $fatura = (string)($p['trans_cod'] ?? '');
    $contrato = (string)($p['recurrence_cod'] ?? '');
    $cupom = (string)($p['discount_coupon_code'] ?? '');
    if ($cupom === '' && !empty($p['trans_items'][0]['item_coupon_code'])) $cupom = (string)$p['trans_items'][0]['item_coupon_code'];
    $pago = trim((string)($p['trans_paiddate'] ?? '') . ' ' . (string)($p['trans_paidtime'] ?? ''));
    return [
        'gateway' => 'eduzz', 'tipo' => $tipo, 'nome_evento' => $nome !== '' ? $nome : ('status_' . $status),
        'id_externo' => 'eduzz:' . ($fatura !== '' ? $fatura : ('c' . $contrato)) . ':' . ($nome !== '' ? $nome : ('s' . $status)),
        'produto' => $prod, 'fatura' => $fatura, 'contrato' => $contrato,
        'nome' => (string)($p['cus_name'] ?? $p['student_name'] ?? ''),
        'email' => (string)($p['cus_email'] ?? $p['student_email'] ?? ''),
        'telefone' => (string)($p['cus_cel'] ?? $p['student_cel'] ?? ''),
        'valor' => (float)($p['trans_paid'] ?? $p['trans_value'] ?? 0),
        'forma' => cddEduzzForma($p['trans_paymentmethod'] ?? 0),
        'cupom' => $cupom,
        'utm' => cddStr((string)($p['tracker_utm_source'] ?? ''), 80),
        'pago_em' => preg_match('/^\d{4}-\d{2}-\d{2}/', $pago) ? substr($pago . ':00', 0, 19) : '',
    ];
}


/** Forma de pagamento do Eduzz v3 (data.paymentMethod). */
function cddEduzz3Forma($v): string {
    $v = strtolower((string)$v);
    if ($v === '') return '';
    if (strpos($v, 'credit') !== false || strpos($v, 'card') !== false || strpos($v, 'cart') !== false) return 'cartao';
    if (strpos($v, 'pix') !== false) return 'pix';
    if (strpos($v, 'slip') !== false || strpos($v, 'boleto') !== false) return 'boleto';
    return 'outro';
}

/** Data ISO (UTC, como o Eduzz envia) para o horário local do servidor. Vazio se não for uma data. */
function cddIsoParaLocal($iso): string {
    $iso = (string)$iso;
    if ($iso === '') return '';
    try { $d = new DateTime($iso); $d->setTimezone(new DateTimeZone(date_default_timezone_get())); return $d->format('Y-m-d H:i:s'); }
    catch (Throwable $e) { return ''; }
}

/**
 * Adaptador do webhook NOVO do Eduzz (Developer Hub, "v3"): {id, event: "myeduzz.invoice_paid", sentDate, data: {...}}, assinado em x-signature.
 * Fatura: data.id, data.buyer, data.price.value, data.items[].productId. Contrato: contract.*, products[].id, customer.* (pode vir dentro de data).
 * Gera o MESMO evento comum do adaptador antigo (gateway 'eduzz'), então as ofertas valem para os dois e o mesmo aviso nunca é processado duas vezes.
 */
function cddGatewayEduzz3(array $p): ?array {
    $nome = strtolower(trim((string)($p['event'] ?? '')));
    $nome = preg_replace('/^myeduzz\./', '', $nome);
    $d = isset($p['data']) && is_array($p['data']) ? $p['data'] : $p;   // contrato pode vir sem o envelope
    if ($nome === '' && empty($d['contract']) && empty($d['items'])) return null;
    $contrato = isset($d['contract']) && is_array($d['contract']) ? $d['contract'] : [];
    $statusC = strtolower((string)($contrato['status'] ?? ''));
    $tipo = 'ignorado';
    if ($nome === 'invoice_paid') $tipo = 'pago';
    elseif ($nome === 'invoice_refunded' || $nome === 'invoice_chargeback') $tipo = 'reembolsado';   // chargeback tira o acesso como o reembolso
    elseif (strpos($nome, 'contract_') === 0 || $contrato) {
        if (strpos($statusC, 'cancel') !== false) $tipo = 'contrato_cancelado';
        elseif (preg_match('/overdue|delayed|late|suspend|defaulter|inadimpl/', $statusC)) $tipo = 'atrasado';
    }
    // produto: o primeiro item que o CDD conhece fica por conta do processamento; aqui devolvemos a lista e o primeiro
    $prods = [];
    foreach ((array)($d['items'] ?? []) as $it) if (is_array($it) && isset($it['productId'])) $prods[] = (string)$it['productId'];
    foreach ((array)($d['products'] ?? []) as $it) if (is_array($it) && isset($it['id'])) $prods[] = (string)$it['id'];
    $comprador = isset($d['buyer']) && is_array($d['buyer']) ? $d['buyer'] : (isset($d['customer']) && is_array($d['customer']) ? $d['customer'] : []);
    $tel = '';
    if (!empty($comprador['cellphone'])) $tel = (string)$comprador['cellphone'];
    elseif (!empty($comprador['phone'])) $tel = is_array($comprador['phone']) ? ((string)($comprador['phone']['countryCode'] ?? '') . (string)($comprador['phone']['areaCode'] ?? '') . (string)($comprador['phone']['number'] ?? '')) : (string)$comprador['phone'];
    $fatura = $tipo === 'pago' || $tipo === 'reembolsado' ? (string)($d['id'] ?? '') : '';
    $idContrato = (string)($contrato['id'] ?? '');
    $cupom = '';
    foreach (['coupon', 'couponCode', 'discountCoupon'] as $k) { if (!empty($d[$k])) { $cupom = is_array($d[$k]) ? (string)($d[$k]['code'] ?? '') : (string)$d[$k]; break; } }
    $pagoEm = $tipo === 'pago' ? cddIsoParaLocal($d['paidAt'] ?? ($p['sentDate'] ?? '')) : '';
    $chave = $fatura !== '' ? $fatura : ('c' . $idContrato . ':' . $statusC);
    return [
        'gateway' => 'eduzz', 'tipo' => $tipo, 'nome_evento' => $nome,
        'id_externo' => 'eduzz:' . $chave . ':' . $nome,
        'produto' => $prods[0] ?? '', 'produtos' => $prods, 'fatura' => $fatura, 'contrato' => $idContrato,
        'nome' => (string)($comprador['name'] ?? ''), 'email' => (string)($comprador['email'] ?? ''), 'telefone' => $tel,
        'valor' => (float)($d['price']['value'] ?? 0),
        'forma' => cddEduzz3Forma($d['paymentMethod'] ?? ($contrato['payment']['method'] ?? '')),
        'cupom' => cddStr($cupom, 40), 'utm' => cddStr((string)($d['utm']['source'] ?? ''), 80),
        'pago_em' => $pagoEm,
    ];
}

/** Confere a assinatura do aviso: HMAC-SHA256 do corpo exato com a chave secreta, em hexadecimal ou base64. */
function cddAssinaturaConfere(string $raw, string $chave, string $enviada): bool {
    $enviada = trim($enviada);
    if ($raw === '' || $chave === '' || $enviada === '') return false;
    $bin = hash_hmac('sha256', $raw, $chave, true);
    return hash_equals(bin2hex($bin), strtolower($enviada)) || hash_equals(base64_encode($bin), $enviada);
}

/** Adaptadores disponíveis. Para um gateway novo: escreva cddGatewayXxx($payload) e liste aqui, com o campo de segredo dele. */
function cddGatewaysConhecidos(): array {
    return [
        // webhook novo do Eduzz (Developer Hub): assinado com HMAC no cabeçalho x-signature. É o que vale daqui para frente.
        'eduzz3' => ['adaptador' => 'cddGatewayEduzz3', 'auth' => 'hmac', 'config' => 'eduzz3_chave'],
        // webhook antigo (token no corpo). O Eduzz o desliga em 11/01/2027 e já não deixa cadastrar novos.
        'eduzz' => ['adaptador' => 'cddGatewayEduzz', 'auth' => 'campo', 'campo_segredo' => 'origin_secret', 'config' => 'eduzz_origin'],
    ];
}

/* ───────────────────────── Processamento ───────────────────────── */

function cddEnviarEmail(string $para, string $assunto, string $texto): bool {
    $lib = __DIR__ . '/../../app/api/_smtp_seguro.php';   // existe enquanto o CDD roda junto do código da Intus
    if (!is_file($lib)) return false;
    try { require_once $lib; return function_exists('intusEnviarEmailSimples') && intusEnviarEmailSimples($para, $assunto, $texto); }
    catch (Throwable $e) { return false; }
}

function cddEmailAcesso(string $nome, string $link): array {
    $primeiro = trim(explode(' ', trim($nome))[0] ?? '');
    $texto = "Oi" . ($primeiro !== '' ? ", $primeiro" : '') . "! Seu acesso ao Código da Definição está liberado.\n\n"
        . "Crie a sua senha por este link (vale por 7 dias e só pode ser usado uma vez):\n$link\n\n"
        . "Depois é só entrar com este mesmo e-mail e a senha que você criou, em https://codigodadefinicao.com.br/app/\n\n"
        . "Qualquer dúvida, chame a equipe no WhatsApp: https://wa.me/5545991156006\n\nUm abraço,\nEquipe Código da Definição";
    return ['Seu acesso ao Código da Definição está liberado', $texto];
}

function cddSomarMeses(string $dataBase, int $meses, int $dias): string {
    $d = new DateTime($dataBase);
    $dia = (int)$d->format('j');
    $d->modify('first day of this month')->modify('+' . max(0, $meses) . ' months');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($dia, (int)$d->format('t')));   // 31/01 + 1 mês = 28/02, nunca março
    if ($dias) $d->modify('+' . $dias . ' days');
    return $d->format('Y-m-d');
}

/**
 * Processa um evento comum. Devolve [ok, resultado]. Idempotente: o mesmo id_externo não roda duas vezes.
 * @param array $ev evento comum de um adaptador
 * @param string $base endereço público do app (para o link de acesso)
 */
function cddComercialProcessar(PDO $pdo, array $ev, string $base): array {
    $resultado = 'ok'; $idaluna = null;
    $oferta = false;
    foreach (array_unique(array_merge([(string)$ev['produto']], (array)($ev['produtos'] ?? []))) as $refProd) {
        $st = $pdo->prepare('SELECT * FROM cdd_oferta WHERE gateway = ? AND ref_gateway = ? ORDER BY ativo DESC'); $st->execute([$ev['gateway'], $refProd]);
        $achou = $st->fetch();
        if ($achou) { $oferta = $achou; if ((int)$achou['ativo'] === 1) break; }
    }
    if (!$oferta || (int)$oferta['ativo'] !== 1) {
        // sem id_externo de propósito: se a oferta for cadastrada depois, o mesmo aviso (reenviado pelo gateway) ainda pode ser processado
        $res = $oferta ? 'oferta_inativa' : 'produto_desconhecido';
        try { $pdo->prepare('INSERT INTO cdd_evento (idaluna, tipo, origem, id_externo, payload, resultado, criado_em) VALUES (NULL, ?, ?, NULL, ?, ?, ?)')->execute(['venda_' . $ev['tipo'], $ev['gateway'], cddStr($ev['produto'], 40), $res, cddAgora()]); } catch (Throwable $e) {}
        return [true, $res];
    }
    $jaFoi = $pdo->prepare('SELECT 1 FROM cdd_evento WHERE id_externo = ?'); $jaFoi->execute([cddStr($ev['id_externo'], 120)]);
    if ($jaFoi->fetchColumn()) return [true, 'duplicado'];
    try {
        $pdo->prepare('INSERT INTO cdd_evento (idaluna, tipo, origem, id_externo, payload, resultado, criado_em) VALUES (NULL, ?, ?, ?, ?, ?, ?)')
            ->execute(['venda_' . $ev['tipo'], $ev['gateway'], cddStr($ev['id_externo'], 120), null, 'recebido', cddAgora()]);
    } catch (Throwable $e) { return [true, 'duplicado']; }   // dois avisos iguais ao mesmo tempo: o índice único segura o segundo
    $idevento = (int)$pdo->lastInsertId();
    $fecha = function (string $res, ?int $ida) use ($pdo, $idevento) {
        try { $pdo->prepare('UPDATE cdd_evento SET resultado = ?, idaluna = ? WHERE idevento = ?')->execute([$res, $ida, $idevento]); } catch (Throwable $e) {}
    };

    $email = cddEmail($ev['email']);
    if ($ev['tipo'] === 'ignorado') { $fecha('ignorado', null); return [true, 'ignorado']; }
    if ($email === '') { $fecha('sem_email', null); return [false, 'sem_email']; }

    $st = $pdo->prepare('SELECT idaluna, nome, senha_hash, status, papel FROM cdd_aluna WHERE email = ?'); $st->execute([$email]);
    $aluna = $st->fetch();

    if ($ev['tipo'] === 'pago') {
        $novo = false;
        if (!$aluna) {
            $nome = cddStr($ev['nome'], 160); if ($nome === '') $nome = $email;
            $pdo->prepare('INSERT INTO cdd_aluna (nome, email, senha_hash, sexo, papel, status, criado_em) VALUES (?, ?, NULL, ?, ?, ?, ?)')
                ->execute([$nome, $email, 'feminino', 'aluna', 'ativa', cddAgora()]);
            $idaluna = (int)$pdo->lastInsertId(); $novo = true;
            $aluna = ['idaluna' => $idaluna, 'nome' => $nome, 'senha_hash' => null, 'status' => 'ativa', 'papel' => 'aluna'];
        } else {
            $idaluna = (int)$aluna['idaluna'];
            if ($aluna['status'] !== 'ativa') $pdo->prepare("UPDATE cdd_aluna SET status = 'ativa' WHERE idaluna = ?")->execute([$idaluna]);
        }
        // pagamento (um por fatura)
        $pago = $ev['pago_em'] !== '' ? $ev['pago_em'] : cddAgora();
        $st = $pdo->prepare('SELECT idpagamento FROM cdd_pagamento WHERE gateway = ? AND ref_externa = ?'); $st->execute([$ev['gateway'], $ev['fatura']]);
        if ($ev['fatura'] !== '' && !$st->fetchColumn()) {
            $pdo->prepare('INSERT INTO cdd_pagamento (idaluna, idoferta, gateway, ref_externa, contrato_ref, status, valor, forma, cupom, utm, pago_em, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idaluna, (int)$oferta['idoferta'], $ev['gateway'], $ev['fatura'], $ev['contrato'] ?: null, 'pago', $ev['valor'], $ev['forma'] ?: null, $ev['cupom'] ?: null, $ev['utm'] ?: null, $pago, cddAgora()]);
        }
        // matrícula: renova a partir do fim atual (se ainda vale) ou de hoje
        $st = $pdo->prepare('SELECT status, inicio, expira_em, origem FROM cdd_matricula WHERE idaluna = ?'); $st->execute([$idaluna]);
        $m = $st->fetch();
        $hoje = cddHoje();
        $baseData = $hoje;
        if ($m && $m['status'] === 'ativa' && !empty($m['expira_em']) && $m['expira_em'] > $hoje) $baseData = $m['expira_em'];
        if ($m && $m['status'] === 'ativa' && empty($m['expira_em'])) { $novoFim = null; }   // acesso sem fim (cortesia vitalícia): venda não limita
        else $novoFim = cddSomarMeses($baseData, (int)$oferta['meses'], CDD_CARENCIA_DIAS);
        if ($m) {
            $pdo->prepare('UPDATE cdd_matricula SET status = ?, origem = ?, ref_externa = ?, expira_em = ?, obs = ? WHERE idaluna = ?')
                ->execute(['ativa', $m['origem'] === 'cortesia' ? 'cortesia' : $ev['gateway'], cddStr($ev['contrato'] ?: $ev['fatura'], 80), $novoFim, cddStr('Venda: ' . $oferta['nome'], 300), $idaluna]);
        } else {
            $pdo->prepare('INSERT INTO cdd_matricula (idaluna, status, origem, ref_externa, inicio, expira_em, obs, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idaluna, 'ativa', $ev['gateway'], cddStr($ev['contrato'] ?: $ev['fatura'], 80), $hoje, $novoFim, cddStr('Venda: ' . $oferta['nome'], 300), cddAgora()]);
        }
        cddRegistrarEvento($pdo, $idaluna, 'matricula_venda', $ev['gateway']);
        cddLeadGanho($pdo, $email, $idaluna);   // o lead com este e-mail vira "ganho"
        // link de acesso: só para quem ainda não tem senha
        if ($novo || empty($aluna['senha_hash'])) {
            $lk = cddNovaAtivacao($pdo, $idaluna, $base);
            [$assunto, $texto] = cddEmailAcesso($aluna['nome'], $lk['link']);
            $resultado = cddEnviarEmail($email, $assunto, $texto) ? ($novo ? 'aluna_criada_email_enviado' : 'liberada_email_enviado') : ($novo ? 'aluna_criada_email_falhou' : 'liberada_email_falhou');
        } else $resultado = 'renovada';
        $fecha($resultado, $idaluna);
        return [true, $resultado];
    }

    if (!$aluna) { $fecha('aluna_nao_encontrada', null); return [true, 'aluna_nao_encontrada']; }
    $idaluna = (int)$aluna['idaluna'];
    if ($ev['tipo'] === 'reembolsado') {
        $pdo->prepare("UPDATE cdd_pagamento SET status = 'reembolsado' WHERE gateway = ? AND ref_externa = ?")->execute([$ev['gateway'], $ev['fatura']]);
        $pdo->prepare("UPDATE cdd_matricula SET status = 'suspensa', obs = ? WHERE idaluna = ? AND origem = ?")
            ->execute([cddStr('Reembolso em ' . date('d/m/Y'), 300), $idaluna, $ev['gateway']]);
        $resultado = 'suspensa_por_reembolso';
    } elseif ($ev['tipo'] === 'contrato_cancelado') {
        $pdo->prepare('UPDATE cdd_matricula SET obs = ? WHERE idaluna = ? AND origem = ?')
            ->execute([cddStr('Assinatura cancelada em ' . date('d/m/Y') . ': o acesso vai até o fim do período já pago.', 300), $idaluna, $ev['gateway']]);
        $resultado = 'assinatura_cancelada';
    } else {
        $pdo->prepare('UPDATE cdd_matricula SET obs = ? WHERE idaluna = ? AND origem = ?')
            ->execute([cddStr('Cobrança em atraso desde ' . date('d/m/Y') . '. O acesso termina na data de fim.', 300), $idaluna, $ev['gateway']]);
        $resultado = 'cobranca_atrasada';
    }
    $fecha($resultado, $idaluna);
    return [true, $resultado];
}

/** Entrada do aviso (webhook). Autentica pelo segredo guardado, traduz pelo adaptador e processa. */
function cddComercialWebhook(PDO $pdo, string $gateway, array $payload, string $base, array $ctx = []): array {
    $gws = cddGatewaysConhecidos();
    if (!isset($gws[$gateway])) return cddErro(404, 'gateway_desconhecido', 'Gateway desconhecido.');
    $g = $gws[$gateway];
    $segredo = cddConfig($pdo, $g['config'], '');
    if ($segredo === '') return cddErro(503, 'gateway_nao_configurado', 'O segredo deste gateway ainda não foi cadastrado no painel.');
    if (($g['auth'] ?? 'campo') === 'hmac') {
        if (!cddAssinaturaConfere((string)($ctx['raw'] ?? ''), $segredo, (string)($ctx['assinatura'] ?? ''))) return cddErro(401, 'nao_autorizado', 'Não autorizado.');
    } else {
        $enviado = (string)($payload[$g['campo_segredo']] ?? '');
        if ($enviado === '' || !hash_equals($segredo, $enviado)) return cddErro(401, 'nao_autorizado', 'Não autorizado.');
    }
    $ev = call_user_func($g['adaptador'], $payload);
    if (!$ev) return cddResp(200, ['ok' => true, 'resultado' => 'sem_evento']);
    try { [$ok, $res] = cddComercialProcessar($pdo, $ev, $base); }
    catch (Throwable $e) { error_log('[cdd webhook] ' . $e->getMessage()); return cddErro(500, 'falha_ao_processar', 'Falha ao processar o aviso.'); }   // 500 faz o Eduzz tentar de novo
    return cddResp(200, ['ok' => $ok, 'resultado' => $res]);
}

/* ───────────────────────── Painel (admin) ───────────────────────── */

function cddComercialAdmin(PDO $pdo, string $metodo, string $acao, array $q, array $b, array $ctx): ?array {
    if (strpos($acao, 'a_com_') !== 0) return null;
    $get = ($metodo === 'GET');
    $base = $ctx['base'] ?? '';

    if ($acao === 'a_com_painel' && $get) {
        $n = function ($sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };
        $desde = date('Y-m-d H:i:s', time() - 30 * 86400);
        $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(valor), 0) FROM cdd_pagamento WHERE status = 'pago' AND pago_em >= ?"); $st->execute([$desde]);
        [$qt30, $rec30] = $st->fetch(PDO::FETCH_NUM);
        $st = $pdo->prepare("SELECT COUNT(*) FROM cdd_pagamento WHERE status = 'reembolsado' AND pago_em >= ?"); $st->execute([$desde]);
        $ref30 = (int)$st->fetchColumn();
        $hoje = cddHoje(); $em7 = date('Y-m-d', time() + 7 * 86400);
        $st = $pdo->prepare("SELECT COUNT(*) FROM cdd_matricula WHERE status = 'ativa' AND expira_em IS NOT NULL AND expira_em BETWEEN ? AND ?"); $st->execute([$hoje, $em7]);
        $vencendo = (int)$st->fetchColumn();
        $eventos = $pdo->query('SELECT idevento, tipo, origem, resultado, criado_em FROM cdd_evento WHERE origem <> \'manual\' ORDER BY idevento DESC LIMIT 15')->fetchAll();
        return cddResp(200, [
            'vendas_30d' => (int)$qt30, 'receita_30d' => round((float)$rec30, 2), 'reembolsos_30d' => $ref30,
            'matriculas_ativas' => (int)$n("SELECT COUNT(*) FROM cdd_matricula WHERE status = 'ativa'"),
            'vencendo_7d' => $vencendo,
            'gateways' => ['eduzz3' => ['segredo_cadastrado' => cddConfig($pdo, 'eduzz3_chave', '') !== '', 'url_aviso' => rtrim($base, '/') . '/api/api.php?a=webhook&g=eduzz3']],
            'eventos' => $eventos,
        ]);
    }
    if ($acao === 'a_com_gateway' && !$get) {
        $gw = cddStr($b['gateway'] ?? '', 20); $gws = cddGatewaysConhecidos();
        if (!isset($gws[$gw])) return cddErro(422, 'gateway_desconhecido', 'Gateway desconhecido.');
        $seg = trim((string)($b['segredo'] ?? ''));
        if (strlen($seg) < 8 || strlen($seg) > 200 || preg_match('/\s/', $seg)) return cddErro(422, 'segredo_invalido', 'O segredo precisa ter de 8 a 200 caracteres, sem espaços.');
        cddConfigGravar($pdo, $gws[$gw]['config'], $seg);   // nunca é devolvido pela API
        cddRegistrarEvento($pdo, null, 'gateway_segredo', 'painel');
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'a_com_ofertas') {
        if ($get) {
            $l = $pdo->query('SELECT idoferta, nome, gateway, ref_gateway, meses, valor, link, ativo, ordem FROM cdd_oferta ORDER BY ordem, idoferta')->fetchAll();
            foreach ($l as &$o) { $o['idoferta'] = (int)$o['idoferta']; $o['meses'] = (int)$o['meses']; $o['ativo'] = (int)$o['ativo']; $o['valor'] = $o['valor'] === null ? null : (float)$o['valor']; }
            return cddResp(200, ['ofertas' => $l]);
        }
        $nome = cddStr($b['nome'] ?? '', 120); $gw = cddStr($b['gateway'] ?? 'eduzz', 20); $ref = cddStr($b['ref_gateway'] ?? '', 40);
        $meses = (int)($b['meses'] ?? 1); $valor = ($b['valor'] ?? '') === '' ? null : round((float)str_replace(',', '.', (string)$b['valor']), 2);
        $link = cddUrl($b['link'] ?? ''); $ativo = !empty($b['ativo']) ? 1 : 0;
        if ($nome === '' || $meses < 1 || $meses > 60) return cddErro(422, 'dados_invalidos', 'Informe o nome e uma duração de 1 a 60 meses.');
        if (!preg_match('/^[a-z0-9_]{2,20}$/', $gw)) return cddErro(422, 'dados_invalidos', 'Gateway inválido.');
        $id = (int)($b['idoferta'] ?? 0);
        $dup = $pdo->prepare('SELECT idoferta FROM cdd_oferta WHERE gateway = ? AND ref_gateway = ?'); $dup->execute([$gw, $ref]);
        $outro = (int)$dup->fetchColumn();
        if ($outro && $outro !== $id) return cddErro(409, 'oferta_repetida', 'Já existe uma oferta com este código no mesmo gateway.');
        if ($id) {
            $pdo->prepare('UPDATE cdd_oferta SET nome = ?, gateway = ?, ref_gateway = ?, meses = ?, valor = ?, link = ?, ativo = ? WHERE idoferta = ?')->execute([$nome, $gw, $ref, $meses, $valor, $link ?: null, $ativo, $id]);
        } else {
            $pdo->prepare('INSERT INTO cdd_oferta (nome, gateway, ref_gateway, meses, valor, link, ativo, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$nome, $gw, $ref, $meses, $valor, $link ?: null, $ativo, cddAgora()]);
            $id = (int)$pdo->lastInsertId();
        }
        return cddResp(200, ['idoferta' => $id]);
    }
    if ($acao === 'a_com_cupons') {
        if ($get) {
            $l = $pdo->query('SELECT c.idcupom, c.codigo, c.gateway, c.tipo, c.valor, c.idoferta, o.nome AS oferta, c.validade, c.limite_uso, c.ativo, c.obs,
                              (SELECT COUNT(*) FROM cdd_pagamento p WHERE p.cupom = c.codigo AND p.status = \'pago\') AS usos
                              FROM cdd_cupom c LEFT JOIN cdd_oferta o ON o.idoferta = c.idoferta ORDER BY c.ativo DESC, c.idcupom DESC')->fetchAll();
            foreach ($l as &$c) { $c['idcupom'] = (int)$c['idcupom']; $c['valor'] = (float)$c['valor']; $c['ativo'] = (int)$c['ativo']; $c['usos'] = (int)$c['usos']; }
            return cddResp(200, ['cupons' => $l]);
        }
        $cod = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['codigo'] ?? ''))); $tipo = ($b['tipo'] ?? '') === 'valor' ? 'valor' : 'percentual';
        $valor = round((float)str_replace(',', '.', (string)($b['valor'] ?? 0)), 2);
        if ($cod === '' || strlen($cod) > 40 || $valor <= 0 || ($tipo === 'percentual' && $valor > 100)) return cddErro(422, 'dados_invalidos', 'Informe o código e um desconto válido.');
        $val = trim((string)($b['validade'] ?? '')); $val = $val === '' ? null : cddData($val);
        $lim = ($b['limite_uso'] ?? '') === '' ? null : max(1, (int)$b['limite_uso']);
        $of = (int)($b['idoferta'] ?? 0) ?: null; $ativo = array_key_exists('ativo', $b) ? (!empty($b['ativo']) ? 1 : 0) : 1;
        $obs = cddStr($b['obs'] ?? '', 200); $gw = cddStr($b['gateway'] ?? 'eduzz', 20);
        $id = (int)($b['idcupom'] ?? 0);
        if ($id) $pdo->prepare('UPDATE cdd_cupom SET codigo = ?, gateway = ?, tipo = ?, valor = ?, idoferta = ?, validade = ?, limite_uso = ?, ativo = ?, obs = ? WHERE idcupom = ?')->execute([$cod, $gw, $tipo, $valor, $of, $val, $lim, $ativo, $obs ?: null, $id]);
        else { $pdo->prepare('INSERT INTO cdd_cupom (codigo, gateway, tipo, valor, idoferta, validade, limite_uso, ativo, obs, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$cod, $gw, $tipo, $valor, $of, $val, $lim, $ativo, $obs ?: null, cddAgora()]); $id = (int)$pdo->lastInsertId(); }
        return cddResp(200, ['idcupom' => $id]);
    }
    if ($acao === 'a_com_pagamentos' && $get) {
        $l = $pdo->query('SELECT p.idpagamento, p.idaluna, a.nome, a.email, o.nome AS oferta, p.gateway, p.ref_externa, p.status, p.valor, p.forma, p.cupom, p.pago_em
                          FROM cdd_pagamento p LEFT JOIN cdd_aluna a ON a.idaluna = p.idaluna LEFT JOIN cdd_oferta o ON o.idoferta = p.idoferta
                          ORDER BY p.idpagamento DESC LIMIT 200')->fetchAll();
        foreach ($l as &$p) { $p['valor'] = (float)$p['valor']; }
        return cddResp(200, ['pagamentos' => $l]);
    }
    if ($acao === 'a_com_padroes' && !$get) {
        // Ofertas e cupons do CDD no Eduzz, lidos do painel do Eduzz em 30/10/2026. Idempotente: não repete nem altera o que já existe.
        $of = [['Código da Definição, plano anual', '1344531', 12, 698.00, 'https://sun.eduzz.com/1344531', 1, 1],
               ['Código da Definição, plano mensal', '2411829', 1, 119.00, 'https://sun.eduzz.com/2411829', 1, 3],
               ['Código da Definição, plano semestral', '2947166', 6, null, 'https://sun.eduzz.com/2947166', 0, 2]];
        $novas = 0;
        foreach ($of as $o) {
            $st = $pdo->prepare('SELECT idoferta FROM cdd_oferta WHERE gateway = ? AND ref_gateway = ?'); $st->execute(['eduzz', $o[1]]);
            if ($st->fetchColumn()) continue;
            $pdo->prepare('INSERT INTO cdd_oferta (nome, gateway, ref_gateway, meses, valor, link, ativo, ordem, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$o[0], 'eduzz', $o[1], $o[2], $o[3], $o[4], $o[5], $o[6], cddAgora()]);
            $novas++;
        }
        $cup = [['CDD20OFF', 'percentual', 20, '20% de desconto'], ['MARLENE20', 'percentual', 20, '20% de desconto'], ['CDD40OFF', 'percentual', 40, '40% de desconto'],
                ['SOS', 'percentual', 30, '30% de desconto'], ['CAMIDIVA', 'percentual', 30, '30% de desconto'], ['ESPECIALCAMILA', 'valor', 379.20, 'R$ 379,20 de desconto']];
        $nc = 0;
        foreach ($cup as $c) {
            $st = $pdo->prepare("SELECT idcupom FROM cdd_cupom WHERE codigo = ? AND gateway = 'eduzz'"); $st->execute([$c[0]]);
            if ($st->fetchColumn()) continue;
            $pdo->prepare('INSERT INTO cdd_cupom (codigo, gateway, tipo, valor, ativo, obs, criado_em) VALUES (?, ?, ?, ?, 1, ?, ?)')
                ->execute([$c[0], 'eduzz', $c[1], $c[2], cddStr($c[3] . '. Conferir no Eduzz a quais produtos se aplica.', 200), cddAgora()]);
            $nc++;
        }
        return cddResp(200, ['ofertas_novas' => $novas, 'cupons_novos' => $nc]);
    }
    return cddErro(404, 'rota_desconhecida', 'Rota desconhecida.');
}
