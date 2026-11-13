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

/* ───────────────────────── Adaptador do Asaas ───────────────────────── */
/*
 * Como a venda chega: a aluna paga num LINK DE PAGAMENTO do Asaas (criado no painel do Asaas, um por plano). Cartão, Pix e boleto
 * ficam na página do Asaas, nada disso passa por aqui. O Asaas avisa (webhook de cobranças) e este adaptador traduz para o evento comum.
 *  - O produto da oferta é o ID do link de pagamento (a parte final de https://www.asaas.com/c/<id>), que vem em payment.paymentLink.
 *  - O aviso só traz o id do cliente (payment.customer); nome, e-mail e telefone saem de GET /v3/customers/<id> (chave de API no painel).
 *  - Aviso e consulta: o webhook é autenticado pelo cabeçalho asaas-access-token (token que o próprio painel gera); a chave de API
 *    só serve para consultar. Nenhum dos dois é devolvido pela API do CDD depois de salvo.
 *  - Cartão parcelado gera um aviso por parcela, todos com o mesmo payment.installment: só o primeiro libera (as demais viram "duplicado").
 *  - O Asaas entrega "pelo menos uma vez": PAYMENT_CONFIRMED e PAYMENT_RECEIVED da mesma cobrança usam o mesmo id_externo.
 */
const CDD_ASAAS_URL = ['producao' => 'https://api.asaas.com/v3', 'sandbox' => 'https://api-sandbox.asaas.com/v3'];

function cddAsaasForma($v): string {
    $v = strtoupper((string)$v);
    if ($v === 'PIX') return 'pix';
    if ($v === 'CREDIT_CARD' || $v === 'DEBIT_CARD') return 'cartao';
    if ($v === 'BOLETO') return 'boleto';
    return $v === '' ? '' : 'outro';
}

/** Traduz um aviso do Asaas para o evento comum. Devolve null para o que o CDD não usa (criada, visualizada, etc.). */
function cddGatewayAsaas(array $p): ?array {
    $nome = strtoupper(trim((string)($p['event'] ?? '')));
    if ($nome === '') return null;
    $pay = isset($p['payment']) && is_array($p['payment']) ? $p['payment'] : [];
    $sub = isset($p['subscription']) && is_array($p['subscription']) ? $p['subscription'] : [];
    $tipo = null;
    if (in_array($nome, ['PAYMENT_CONFIRMED', 'PAYMENT_RECEIVED'], true)) $tipo = 'pago';
    elseif (in_array($nome, ['PAYMENT_REFUNDED', 'PAYMENT_CHARGEBACK_REQUESTED'], true)) $tipo = 'reembolsado';   // chargeback tira o acesso como o reembolso
    elseif ($nome === 'PAYMENT_OVERDUE') $tipo = 'atrasado';
    elseif (in_array($nome, ['SUBSCRIPTION_DELETED', 'SUBSCRIPTION_INACTIVATED'], true)) $tipo = 'contrato_cancelado';
    if ($tipo === null) return null;

    $idPag = (string)($pay['id'] ?? '');
    $idSub = (string)($sub['id'] ?? ($pay['subscription'] ?? ''));
    $idParc = (string)($pay['installment'] ?? '');
    if ($tipo === 'contrato_cancelado') { if ($idSub === '') return null; }
    elseif ($idPag === '') return null;

    // fatura = a cobrança; no cartão parcelado é o parcelamento inteiro (as parcelas são a mesma compra)
    $fatura = $tipo === 'contrato_cancelado' ? '' : ($idParc !== '' ? 'i' . $idParc : $idPag);
    if ($tipo === 'pago') $chave = $fatura;
    elseif ($tipo === 'contrato_cancelado') $chave = 's' . $idSub;
    else $chave = $idPag;
    $dataPago = (string)($pay['confirmedDate'] ?? ($pay['paymentDate'] ?? ($pay['clientPaymentDate'] ?? '')));
    $link = (string)($pay['paymentLink'] ?? ($sub['paymentLink'] ?? ''));
    return [
        'gateway' => 'asaas', 'tipo' => $tipo, 'nome_evento' => strtolower($nome),
        'id_externo' => 'asaas:' . $chave . ':' . ($tipo === 'pago' ? 'pago' : strtolower($nome)),
        'produto' => $link, 'produtos' => [], 'fatura' => $fatura, 'contrato' => $idSub,
        'nome' => '', 'email' => '', 'telefone' => '',
        'valor' => (float)($pay['value'] ?? 0),
        'forma' => cddAsaasForma($pay['billingType'] ?? ''),
        'cupom' => '', 'utm' => '',
        'pago_em' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPago) ? $dataPago . ' 00:00:00' : '',
        // o que o enriquecimento precisa para consultar o Asaas
        '_cliente' => (string)($pay['customer'] ?? ($sub['customer'] ?? '')),
        '_assinatura' => $idSub, '_parcelamento' => $idParc, '_cobranca' => $idPag, '_extref' => (string)($pay['externalReference'] ?? ($sub['externalReference'] ?? '')),
    ];
}

/** Chamada ao Asaas. Em teste, $GLOBALS['cddAsaasHttpFake'] (callable) responde no lugar da internet. Devolve [status HTTP, json]. */
function cddAsaasChamar(PDO $pdo, string $metodo, string $caminho, ?array $corpo = null): array {
    $chave = cddConfig($pdo, 'asaas_apikey', '');
    if ($chave === '') throw new RuntimeException('chave_api_ausente', 503);
    $amb = cddConfig($pdo, 'asaas_ambiente', 'producao') === 'sandbox' ? 'sandbox' : 'producao';
    $url = CDD_ASAAS_URL[$amb] . $caminho;
    if (!empty($GLOBALS['cddAsaasHttpFake']) && is_callable($GLOBALS['cddAsaasHttpFake'])) return call_user_func($GLOBALS['cddAsaasHttpFake'], $metodo, $url, $corpo);
    if (!function_exists('curl_init')) throw new RuntimeException('asaas_sem_curl', 502);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'access_token: ' . $chave, 'User-Agent: CDD-Intus'],
        CURLOPT_POSTFIELDS => $corpo === null ? null : json_encode($corpo)]);
    $r = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($r === false) throw new RuntimeException('asaas_fora_do_ar', 502);
    $j = json_decode((string)$r, true);
    return [$st, is_array($j) ? $j : []];
}

/**
 * Completa o evento com o que o aviso não traz: o ID do link (quando a cobrança é de uma assinatura), o comprador (nome, e-mail, telefone) e o valor
 * total do cartão parcelado. Só consulta o Asaas quando a cobrança é de uma oferta do CDD: a conta serve também a outras vendas (as da Intus),
 * e dados de cliente alheio não devem ser buscados. Falha de rede lança exceção (o Asaas tenta de novo; perder uma venda é pior).
 */
function cddAsaasEnriquecer(PDO $pdo, array $ev): array {
    $get = function (string $caminho) use ($pdo) {
        [$st, $j] = cddAsaasChamar($pdo, 'GET', $caminho);
        if ($st === 401 || $st === 403) throw new RuntimeException('chave_api_recusada', 503);
        if ($st < 200 || $st >= 300) throw new RuntimeException('asaas_erro_' . $st, 502);
        return $j;
    };
    $ofertaDe = function (string $ref) use ($pdo) {
        if ($ref === '') return false;
        $st = $pdo->prepare("SELECT idoferta FROM cdd_oferta WHERE gateway = 'asaas' AND ref_gateway = ? AND ativo = 1"); $st->execute([$ref]);
        return (bool)$st->fetchColumn();
    };
    // Venda do checkout próprio: o pedido (cdd_pedido) já sabe o plano e quem comprou, então não precisa consultar o Asaas.
    if ($ev['produto'] === '' && ($ped = cddCheckoutPedidoDoEvento($pdo, $ev))) {
        $of = $pdo->prepare('SELECT ref_gateway FROM cdd_oferta WHERE idoferta = ?'); $of->execute([(int)$ped['idoferta']]);
        $ref = (string)$of->fetchColumn();
        if ($ref !== '') {
            $ev['produto'] = $ref; $ev['nome'] = (string)$ped['nome']; $ev['email'] = (string)$ped['email']; $ev['telefone'] = (string)$ped['telefone'];
            $ev['cupom'] = (string)$ped['cupom']; $ev['_pedido'] = (int)$ped['idpedido']; $ev['_bumps'] = (string)$ped['bumps']; $ev['_cliente'] = '';
            if ($ev['tipo'] === 'pago' && (int)$ped['parcelas'] > 1 && $ev['_parcelamento'] !== '') $ev['valor'] = (float)$ped['valor'];   // o aviso traz o valor de UMA parcela
            return $ev;
        }
    }
    if ($ev['produto'] === '' && !empty($ev['_assinatura'])) {
        $a = $get('/subscriptions/' . rawurlencode($ev['_assinatura']));
        $ev['produto'] = (string)($a['paymentLink'] ?? '');
    }
    if (!$ofertaDe($ev['produto'])) return $ev;   // não é do CDD: o processamento só registra
    if (!empty($ev['_cliente'])) {
        $c = $get('/customers/' . rawurlencode($ev['_cliente']));
        $ev['nome'] = (string)($c['name'] ?? ''); $ev['email'] = (string)($c['email'] ?? '');
        $ev['telefone'] = (string)(($c['mobilePhone'] ?? '') !== '' ? $c['mobilePhone'] : ($c['phone'] ?? ''));
    }
    if ($ev['tipo'] === 'pago' && $ev['_parcelamento'] !== '') {
        try {
            $i = $get('/installments/' . rawurlencode($ev['_parcelamento']));
            $n = (int)($i['installmentCount'] ?? 0);
            if ($n > 1) $ev['valor'] = round($ev['valor'] * $n, 2);   // o aviso traz o valor de UMA parcela
        } catch (Throwable $e) { /* sem o total, fica o valor da parcela */ }
    }
    return $ev;
}

/** Adaptadores disponíveis. Para um gateway novo: escreva cddGatewayXxx($payload) e liste aqui, com o campo de segredo dele. */
function cddGatewaysConhecidos(): array {
    return [
        // Asaas: aviso autenticado por token no cabeçalho asaas-access-token (gerado pelo painel); a chave de API só consulta.
        'asaas' => ['adaptador' => 'cddGatewayAsaas', 'auth' => 'token', 'config' => 'asaas_token', 'enriquecer' => 'cddAsaasEnriquecer'],
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

function cddPrimeiroNome(string $nome): string { return trim(explode(' ', trim($nome))[0] ?? ''); }

/**
 * E-mail de boas-vindas com o link para criar a senha. $reenvio: pedido pela própria aluna na página de obrigado ou no login;
 * $jaTemSenha: a conta já tinha senha (o link serve para criar uma nova).
 */
function cddEmailAcesso(string $nome, string $link, bool $reenvio = false, bool $jaTemSenha = false): array {
    $primeiro = cddPrimeiroNome($nome);
    $oi = 'Oi' . ($primeiro !== '' ? ", $primeiro" : '') . '! ';
    if ($reenvio) {
        $abre = $oi . ($jaTemSenha ? 'Aqui está o link para você criar uma senha nova no Código da Definição.' : 'Aqui está o seu novo link de acesso ao Código da Definição.');
        $assunto = $jaTemSenha ? 'Link para criar uma senha nova no Código da Definição' : 'Seu novo link de acesso ao Código da Definição';
    } else {
        $abre = $oi . 'Bem-vinda ao Código da Definição. O seu acesso está liberado.';
        $assunto = 'Seu acesso ao Código da Definição está liberado';
    }
    $texto = $abre . "\n\n"
        . "1) Crie a sua senha (leva 1 minuto)\n"
        . "Abra o link abaixo, escolha uma senha e você já entra no app. Ele vale por 7 dias e só pode ser usado uma vez:\n$link\n\n"
        . "2) Coloque o app na tela inicial do celular\n"
        . "iPhone: abra o endereço no Safari, toque em Compartilhar e depois em \"Adicionar à Tela de Início\".\n"
        . "Android: abra no Chrome, toque nos três pontinhos e em \"Instalar app\" ou \"Adicionar à tela inicial\".\n\n"
        . "3) Comece pelo módulo \"Comece por aqui\"\n"
        . "Ele mostra como o programa funciona e como registrar o seu treino. Para entrar nas próximas vezes, use este mesmo e-mail e a senha que você criou em https://codigodadefinicao.com.br/app/\n\n"
        . "Se o link expirar ou não abrir, peça outro em https://codigodadefinicao.com.br/app/obrigado (é só digitar este e-mail) ou chame a equipe no WhatsApp: https://wa.me/5545991156006\n\n"
        . "Um abraço,\nEquipe Código da Definição";
    return [$assunto, $texto];
}

/** Telefone do comprador em dígitos com DDI 55 (celular ou fixo BR). Vazio se não parecer um número brasileiro. */
function cddTelefoneBR($t): string {
    $d = preg_replace('/\D+/', '', (string)$t);
    if ($d === '') return '';
    if (strpos($d, '55') === 0 && (strlen($d) === 12 || strlen($d) === 13)) return $d;
    if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
    return '';
}

/** Texto das boas-vindas por WhatsApp. Sem o link de senha de propósito: o link vai por e-mail e aqui a aluna tem o caminho para pedir outro. */
function cddZapBoasVindas(string $nome, string $email): string {
    $primeiro = cddPrimeiroNome($nome);
    return 'Oi' . ($primeiro !== '' ? ", $primeiro" : '') . '! Aqui é a equipe do Código da Definição. O seu acesso está liberado. '
        . "Enviamos o link para criar a sua senha para o e-mail $email (olhe também o spam). Se não achar, peça outro em https://codigodadefinicao.com.br/app/obrigado . "
        . 'Depois de criar a senha, coloque o app na tela inicial do celular e comece pelo módulo "Comece por aqui". Qualquer dúvida, é só responder esta mensagem.';
}

/** Aviso à equipe de uma aluna nova, com o botão pronto de boas-vindas no WhatsApp (1 toque), enquanto o envio automático pela Meta não existe. */
function cddAvisarEquipeVenda(PDO $pdo, string $nome, string $email, string $telefone, string $oferta, $valor, string $bumps = ''): void {
    $para = cddEmail(cddConfig($pdo, 'email_equipe', 'contato@codigodadefinicao.com.br'));
    if ($para === '') return;
    $linhas = ['Aluna nova no Código da Definição.', '', "Nome: $nome", "E-mail: $email", 'Telefone: ' . ($telefone !== '' ? '+' . $telefone : 'não veio na compra'),
               'Plano: ' . $oferta . ($valor !== null && $valor !== '' ? ' (R$ ' . number_format((float)$valor, 2, ',', '.') . ')' : '')];
    if ($bumps !== '') { $linhas[] = ''; $linhas[] = 'BÔNUS COMPRADOS (entregar à mão): ' . $bumps; }
    if ($telefone !== '') {
        $linhas[] = ''; $linhas[] = 'Boas-vindas no WhatsApp, com a mensagem pronta (toque, confira e envie):';
        $linhas[] = 'https://wa.me/' . $telefone . '?text=' . rawurlencode(cddZapBoasVindas($nome, $email));
    }
    $linhas[] = ''; $linhas[] = 'O e-mail com o link para criar a senha já foi enviado para a aluna.';
    cddEnviarEmail($para, 'Aluna nova no CDD: ' . $nome, implode("\n", $linhas));
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
            // Asaas não tem cupom no link: o desconto é um link próprio, ligado ao cupom pela oferta. O código sai daí, para o contador de vendas do cupom.
            $cupomPg = (string)$ev['cupom'];
            if ($cupomPg === '') {
                $sc = $pdo->prepare('SELECT codigo FROM cdd_cupom WHERE gateway = ? AND idoferta = ? ORDER BY ativo DESC, idcupom LIMIT 1'); $sc->execute([$ev['gateway'], (int)$oferta['idoferta']]);
                $cupomPg = (string)$sc->fetchColumn();
            }
            $pdo->prepare('INSERT INTO cdd_pagamento (idaluna, idoferta, gateway, ref_externa, contrato_ref, status, valor, forma, cupom, utm, pago_em, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$idaluna, (int)$oferta['idoferta'], $ev['gateway'], $ev['fatura'], $ev['contrato'] ?: null, 'pago', $ev['valor'], $ev['forma'] ?: null, $cupomPg ?: null, $ev['utm'] ?: null, $pago, cddAgora()]);
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
        if (!empty($ev['_pedido'])) cddCheckoutMarcarPago($pdo, (int)$ev['_pedido']);
        $tel = cddTelefoneBR($ev['telefone'] ?? '');
        if ($tel !== '') $pdo->prepare("UPDATE cdd_aluna SET telefone = ? WHERE idaluna = ? AND (telefone IS NULL OR telefone = '')")->execute([$tel, $idaluna]);
        cddRegistrarEvento($pdo, $idaluna, 'matricula_venda', $ev['gateway']);
        cddLeadGanho($pdo, $email, $idaluna);   // o lead com este e-mail vira "ganho"
        // link de acesso: só para quem ainda não tem senha
        if ($novo || empty($aluna['senha_hash'])) {
            $lk = cddNovaAtivacao($pdo, $idaluna, $base);
            [$assunto, $texto] = cddEmailAcesso($aluna['nome'], $lk['link']);
            $resultado = cddEnviarEmail($email, $assunto, $texto) ? ($novo ? 'aluna_criada_email_enviado' : 'liberada_email_enviado') : ($novo ? 'aluna_criada_email_falhou' : 'liberada_email_falhou');
            if ($novo) cddAvisarEquipeVenda($pdo, (string)$aluna['nome'], $email, $tel, (string)$oferta['nome'], $ev['valor'] ?? null, (string)($ev['_bumps'] ?? ''));
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
    } elseif ($g['auth'] === 'token') {
        $enviado = (string)($ctx['token_gateway'] ?? '');
        if ($enviado === '' || !hash_equals($segredo, $enviado)) return cddErro(401, 'nao_autorizado', 'Não autorizado.');
    } else {
        $enviado = (string)($payload[$g['campo_segredo']] ?? '');
        if ($enviado === '' || !hash_equals($segredo, $enviado)) return cddErro(401, 'nao_autorizado', 'Não autorizado.');
    }
    $ev = call_user_func($g['adaptador'], $payload);
    if (!$ev) return cddResp(200, ['ok' => true, 'resultado' => 'sem_evento']);
    if (!empty($g['enriquecer'])) {
        try { $ev = call_user_func($g['enriquecer'], $pdo, $ev); }
        catch (Throwable $e) {
            // sem a chave de API (ou recusada) o painel mostra o motivo; falha de rede devolve erro para o gateway tentar de novo
            error_log('[cdd webhook ' . $gateway . '] ' . $e->getMessage());
            $m = $e->getMessage();
            if ($m === 'chave_api_ausente') return cddErro(503, 'chave_api_nao_configurada', 'A chave de API do gateway ainda não foi cadastrada no painel.');
            if ($m === 'chave_api_recusada') return cddErro(503, 'chave_api_recusada', 'O gateway recusou a chave de API cadastrada.');
            return cddErro(502, 'gateway_indisponivel', 'Não foi possível consultar o gateway agora.');
        }
    }
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
            'gateways' => [
                'eduzz3' => ['segredo_cadastrado' => cddConfig($pdo, 'eduzz3_chave', '') !== '', 'url_aviso' => rtrim($base, '/') . '/api/api.php?a=webhook&g=eduzz3'],
                // nunca devolve token nem chave de API, só se estão cadastrados
                'asaas' => ['token_cadastrado' => cddConfig($pdo, 'asaas_token', '') !== '', 'apikey_cadastrada' => cddConfig($pdo, 'asaas_apikey', '') !== '',
                            'ambiente' => cddConfig($pdo, 'asaas_ambiente', 'producao') === 'sandbox' ? 'sandbox' : 'producao', 'url_aviso' => rtrim($base, '/') . '/api/api.php?a=webhook&g=asaas'],
            ],
            'eventos' => $eventos,
        ]);
    }
    if ($acao === 'a_com_asaas_token' && !$get) {
        // O token do webhook é nosso (não é credencial do Luiz): o painel gera e mostra UMA vez para colar no Asaas. Gerar de novo troca o token.
        $tok = bin2hex(random_bytes(24));   // 48 caracteres hexadecimais: dentro dos 32 a 255 que o Asaas exige
        cddConfigGravar($pdo, 'asaas_token', $tok);
        cddRegistrarEvento($pdo, null, 'gateway_segredo', 'painel');
        return cddResp(200, ['ok' => true, 'token' => $tok]);
    }
    if ($acao === 'a_com_asaas_retorno' && !$get) {
        // Página de obrigado: manda o comprador, depois de pagar, para /obrigado. Usa a chave de API guardada (nunca devolvida) e só mexe no callback do link.
        $refs = [];
        $ref1 = cddStr($b['ref_gateway'] ?? '', 40);
        if ($ref1 !== '') $refs[] = $ref1;
        else foreach ($pdo->query("SELECT ref_gateway FROM cdd_oferta WHERE gateway = 'asaas' AND ativo = 1 AND ref_gateway <> ''")->fetchAll() as $o) $refs[] = (string)$o['ref_gateway'];
        // O Asaas só aceita retorno no domínio cadastrado em Minha Conta (www.intusfit.com.br). O endereço abaixo, nesse domínio, redireciona para /obrigado do CDD.
        $url = cddUrl($b['url'] ?? '') ?: 'https://intusfit.com.br/cdd/obrigado';
        $r = [];
        foreach ($refs as $ref) {
            try { [$st, $j] = cddAsaasChamar($pdo, 'PUT', '/paymentLinks/' . rawurlencode($ref), ['callback' => ['successUrl' => $url, 'autoRedirect' => true]]); }
            catch (Throwable $e) { return cddResp(200, ['ok' => false, 'motivo' => $e->getMessage() === 'chave_api_ausente' ? 'Cadastre a chave de API primeiro.' : 'Não consegui falar com o Asaas agora.']); }
            $erro = $st >= 200 && $st < 300 ? '' : cddStr((string)($j['errors'][0]['description'] ?? ('erro ' . $st)), 200);
            $r[] = ['ref' => $ref, 'ok' => $erro === '', 'erro' => $erro];
        }
        return cddResp(200, ['ok' => true, 'url' => $url, 'links' => $r]);
    }
    if ($acao === 'a_com_asaas_testar' && !$get) {
        try { [$st] = cddAsaasChamar($pdo, 'GET', '/customers?limit=1'); }
        catch (Throwable $e) {
            $m = $e->getMessage();
            return cddResp(200, ['ok' => false, 'motivo' => $m === 'chave_api_ausente' ? 'Cadastre a chave de API primeiro.' : 'Não consegui falar com o Asaas agora.']);
        }
        if ($st === 401 || $st === 403) return cddResp(200, ['ok' => false, 'motivo' => 'O Asaas recusou a chave. Confira se ela é do mesmo ambiente (produção ou testes) escolhido aqui.']);
        return cddResp(200, ['ok' => $st >= 200 && $st < 300, 'motivo' => $st >= 200 && $st < 300 ? 'Conectado ao Asaas.' : 'O Asaas respondeu com erro ' . $st . '.']);
    }
    if ($acao === 'a_com_gateway' && !$get && cddStr($b['gateway'] ?? '', 20) === 'asaas') {
        $campo = cddStr($b['campo'] ?? '', 10); $val = trim((string)($b['segredo'] ?? ''));
        if ($campo === 'ambiente') {
            if ($val !== 'sandbox' && $val !== 'producao') return cddErro(422, 'dados_invalidos', 'Ambiente inválido.');
            cddConfigGravar($pdo, 'asaas_ambiente', $val);
            return cddResp(200, ['ok' => true]);
        }
        if ($campo !== 'apikey') return cddErro(422, 'dados_invalidos', 'Campo inválido.');
        if (strlen($val) < 20 || strlen($val) > 400 || preg_match('/\s/', $val)) return cddErro(422, 'segredo_invalido', 'A chave de API parece incompleta (sem espaços, ao menos 20 caracteres).');
        cddConfigGravar($pdo, 'asaas_apikey', $val);   // nunca é devolvida pela API
        cddRegistrarEvento($pdo, null, 'gateway_segredo', 'painel');
        return cddResp(200, ['ok' => true]);
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
        if ($gw === 'asaas') {
            // o código da oferta no Asaas é o ID do link de pagamento: aceita o link inteiro (https://www.asaas.com/c/<id>) colado em qualquer dos dois campos
            foreach ([(string)($b['ref_gateway'] ?? ''), $link] as $cand) { if (preg_match('~asaas\.com/c/([A-Za-z0-9]+)~', (string)$cand, $mm)) { $ref = $mm[1]; if ($link === '') $link = cddUrl($cand); break; } }
            if ($ref === '' && $ativo) return cddErro(422, 'dados_invalidos', 'Cole o link de pagamento do Asaas (https://www.asaas.com/c/...) para ligar a oferta.');
        }
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
        if ($gw === 'asaas') {
            // o link de pagamento do Asaas não tem campo de cupom: o desconto é um link próprio (com o valor já descontado), guardado numa oferta do Asaas.
            // Neste caso "idoferta" é essa oferta com o desconto, e o link do cupom só vale com ela ligada.
            $og = $of ? $pdo->prepare('SELECT gateway FROM cdd_oferta WHERE idoferta = ?') : null;
            if ($og) $og->execute([$of]);
            if (!$of || $og->fetchColumn() !== 'asaas') return cddErro(422, 'dados_invalidos', 'No Asaas, escolha a oferta do Asaas que tem o link já com este desconto.');
        }
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
        // Gateway, nome, código no gateway, meses, valor, link, ligada, ordem. As do Asaas (links de pagamento criados em 07/11/2026) nascem DESLIGADAS: ligar uma oferta do
        // Asaas troca o link das páginas de venda, e isso só pode acontecer depois que a chave de API e o webhook estiverem cadastrados (senão a venda não libera a aluna).
        $A = 'https://www.asaas.com/c/';
        $of = [['eduzz', 'Código da Definição, plano anual', '1344531', 12, 698.00, 'https://sun.eduzz.com/1344531', 1, 1],
               ['eduzz', 'Código da Definição, plano mensal', '2411829', 1, 119.00, 'https://sun.eduzz.com/2411829', 1, 3],
               ['eduzz', 'Código da Definição, plano semestral', '2947166', 6, 447.00, 'https://sun.eduzz.com/2947166', 0, 2],
               ['asaas', 'Código da Definição, plano anual', 'r8eb58ytv6woigu2', 12, 698.00, $A . 'r8eb58ytv6woigu2', 0, 1],
               ['asaas', 'Código da Definição, plano semestral', 'qtvk2ixljil0qqp8', 6, 447.00, $A . 'qtvk2ixljil0qqp8', 0, 2],
               ['asaas', 'Código da Definição, plano mensal', 'gblzqkgx3ruv4mhe', 1, 119.00, $A . 'gblzqkgx3ruv4mhe', 0, 3],
               ['asaas', 'Código da Definição, plano anual, cupom CDD20OFF', 'joy0ay7gvincodyi', 12, 558.40, $A . 'joy0ay7gvincodyi', 0, 11],
               ['asaas', 'Código da Definição, plano anual, cupom MARLENE20', '7sj7f4xxg7y722jl', 12, 558.40, $A . '7sj7f4xxg7y722jl', 0, 12],
               ['asaas', 'Código da Definição, plano anual, cupom CDD40OFF', 'l67yzditsvrdvj51', 12, 418.80, $A . 'l67yzditsvrdvj51', 0, 13],
               ['asaas', 'Código da Definição, plano anual, cupom SOS', 'l9b35l4qm2wjzfrn', 12, 488.60, $A . 'l9b35l4qm2wjzfrn', 0, 14]];
        $novas = 0;
        foreach ($of as $o) {
            $st = $pdo->prepare('SELECT idoferta FROM cdd_oferta WHERE gateway = ? AND ref_gateway = ?'); $st->execute([$o[0], $o[2]]);
            if ($st->fetchColumn()) continue;
            $pdo->prepare('INSERT INTO cdd_oferta (nome, gateway, ref_gateway, meses, valor, link, ativo, ordem, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$o[1], $o[0], $o[2], $o[3], $o[4], $o[5], $o[6], $o[7], cddAgora()]);
            $novas++;
        }
        // o semestral do Eduzz já existia sem valor (R$ 447 foi definido em 07/11/2026); só preenche o que está vazio
        $pdo->exec("UPDATE cdd_oferta SET valor = 447.00 WHERE gateway = 'eduzz' AND ref_gateway = '2947166' AND valor IS NULL");
        $cup = [['CDD20OFF', 'percentual', 20, '20% de desconto'], ['MARLENE20', 'percentual', 20, '20% de desconto'], ['CDD40OFF', 'percentual', 40, '40% de desconto'],
                ['SOS', 'percentual', 30, '30% de desconto']];
        $nc = 0;
        foreach ($cup as $c) {
            $st = $pdo->prepare("SELECT idcupom FROM cdd_cupom WHERE codigo = ? AND gateway = 'eduzz'"); $st->execute([$c[0]]);
            if ($st->fetchColumn()) continue;
            $pdo->prepare('INSERT INTO cdd_cupom (codigo, gateway, tipo, valor, ativo, obs, criado_em) VALUES (?, ?, ?, ?, 1, ?, ?)')
                ->execute([$c[0], 'eduzz', $c[1], $c[2], cddStr($c[3] . '. Conferir no Eduzz a quais produtos se aplica.', 200), cddAgora()]);
            $nc++;
        }
        // Cupons no Asaas: um link por cupom, só no plano anual (o do mensal valeria em toda cobrança do mês, para sempre). O cupom aponta para a oferta que tem o link já descontado.
        $refCupom = ['CDD20OFF' => 'joy0ay7gvincodyi', 'MARLENE20' => '7sj7f4xxg7y722jl', 'CDD40OFF' => 'l67yzditsvrdvj51', 'SOS' => 'l9b35l4qm2wjzfrn'];
        foreach ($cup as $c) {
            $so = $pdo->prepare("SELECT idoferta FROM cdd_oferta WHERE gateway = 'asaas' AND ref_gateway = ?"); $so->execute([$refCupom[$c[0]]]);
            $idof = (int)$so->fetchColumn();
            if (!$idof) continue;
            $st = $pdo->prepare("SELECT idcupom FROM cdd_cupom WHERE codigo = ? AND gateway = 'asaas'"); $st->execute([$c[0]]);
            if ($st->fetchColumn()) continue;
            $pdo->prepare('INSERT INTO cdd_cupom (codigo, gateway, tipo, valor, idoferta, ativo, obs, criado_em) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
                ->execute([$c[0], 'asaas', $c[1], $c[2], $idof, cddStr($c[3] . ' no plano anual. Link próprio no Asaas.', 200), cddAgora()]);
            $nc++;
        }
        // Cupons cancelados pelo Luiz (11/11/2026): saem do painel junto com a oferta do link do Asaas, a menos que já exista pagamento ligado a ela (aí só ficam desligados).
        $fora = ['CAMIDIVA', 'ESPECIALCAMILA']; $refsFora = ['pttozkp4hcrl9cyx', 'zo15nqi1r8h6t5ih'];
        foreach ($fora as $cod) $pdo->prepare('DELETE FROM cdd_cupom WHERE codigo = ?')->execute([$cod]);
        foreach ($refsFora as $ref) {
            $pdo->prepare("UPDATE cdd_oferta SET ativo = 0 WHERE gateway = 'asaas' AND ref_gateway = ?")->execute([$ref]);
            $pdo->prepare("DELETE FROM cdd_oferta WHERE gateway = 'asaas' AND ref_gateway = ? AND idoferta NOT IN (SELECT idoferta FROM cdd_pagamento WHERE idoferta IS NOT NULL) AND idoferta NOT IN (SELECT idoferta FROM cdd_cupom WHERE idoferta IS NOT NULL)")->execute([$ref]);
        }
        return cddResp(200, ['ofertas_novas' => $novas, 'cupons_novos' => $nc]);
    }
    return cddErro(404, 'rota_desconhecida', 'Rota desconhecida.');
}
