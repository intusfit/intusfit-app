<?php
/**
 * CDD: checkout próprio (/app/checkout). A cliente escolhe o plano, preenche o mínimo (nome, e-mail, WhatsApp e CPF) e paga:
 *  - Pix: a cobrança é criada pela API do Asaas e o QR Code aparece na nossa página (a página consulta o pedido até o webhook marcar como pago);
 *  - cartão: a cobrança é criada pela API e a cliente é levada à tela segura do Asaas (os dados do cartão nunca passam por aqui), em até N vezes sem juros.
 * O plano mensal é uma assinatura (a cliente escolhe a forma na tela do Asaas, como no link de pagamento).
 * A venda é reconhecida pelo webhook de sempre: o aviso traz o id da cobrança, e o pedido (cdd_pedido) liga essa cobrança ao plano (oferta) e aos dados da compra.
 * Valor, desconto e bônus são sempre calculados aqui, nunca confiados ao navegador.
 */

const CDD_CHECKOUT_PLANOS = [12 => ['anual', 12], 6 => ['semestral', 6], 1 => ['mensal', 1]];   // meses => [apelido, máximo de parcelas]

function cddCheckoutDdl(string $c): array {
    return [
        'cdd_pedido' => "CREATE TABLE IF NOT EXISTS cdd_pedido (
            idpedido INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(40) NOT NULL,
            idoferta INT NOT NULL,
            nome VARCHAR(160) NOT NULL,
            email VARCHAR(190) NOT NULL,
            telefone VARCHAR(20) NULL,
            cpf VARCHAR(14) NULL,
            forma VARCHAR(10) NOT NULL DEFAULT 'pix',
            parcelas INT NOT NULL DEFAULT 1,
            valor DECIMAL(10,2) NOT NULL,
            bumps VARCHAR(500) NULL,
            cupom VARCHAR(40) NULL,
            utm VARCHAR(300) NULL,
            asaas_cliente VARCHAR(40) NULL,
            asaas_ref VARCHAR(60) NULL,
            asaas_tipo VARCHAR(12) NULL,
            asaas_parc VARCHAR(60) NULL,
            invoice_url VARCHAR(300) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'criado',
            criado_em DATETIME NOT NULL,
            pago_em DATETIME NULL,
            UNIQUE INDEX idx_token (token),
            INDEX idx_ref (asaas_ref),
            INDEX idx_parc (asaas_parc)
        ) $c",
    ];
}

/** CPF válido (dígitos verificadores). */
function cddCpfValido($v): bool {
    $d = preg_replace('/\D+/', '', (string)$v);
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) return false;
    for ($t = 9; $t < 11; $t++) {
        $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$d[$i] * (($t + 1) - $i);
        $r = ((10 * $s) % 11) % 10;
        if ((int)$d[$t] !== $r) return false;
    }
    return true;
}

/** Planos à venda no checkout: as ofertas do Asaas ligadas de 1, 6 e 12 meses (sem as de cupom e sem a de teste). */
function cddCheckoutPlanos(PDO $pdo): array {
    $l = $pdo->query("SELECT idoferta, nome, meses, valor FROM cdd_oferta o WHERE gateway = 'asaas' AND ativo = 1 AND valor IS NOT NULL AND valor > 0 AND meses IN (1, 6, 12)
                      AND nome NOT LIKE '%cupom%' AND nome NOT LIKE 'TESTE%' AND NOT EXISTS (SELECT 1 FROM cdd_cupom c WHERE c.idoferta = o.idoferta AND c.gateway = 'asaas') ORDER BY meses DESC, idoferta")->fetchAll();
    $r = [];
    foreach ($l as $o) {
        $m = (int)$o['meses'];
        if (isset($r[CDD_CHECKOUT_PLANOS[$m][0]])) continue;
        $r[CDD_CHECKOUT_PLANOS[$m][0]] = ['slug' => CDD_CHECKOUT_PLANOS[$m][0], 'idoferta' => (int)$o['idoferta'], 'nome' => (string)$o['nome'], 'meses' => $m,
                                          'valor' => round((float)$o['valor'], 2), 'parcelas_max' => CDD_CHECKOUT_PLANOS[$m][1]];
    }
    return $r;
}

/** Oferta de teste interna (só se chega a ela de propósito, por ?plano=teste, com a oferta de teste ligada). */
function cddCheckoutOfertaTeste(PDO $pdo): ?array {
    $o = $pdo->query("SELECT idoferta, nome, meses, valor FROM cdd_oferta WHERE gateway = 'asaas' AND ativo = 1 AND nome LIKE 'TESTE%' AND valor >= 5 ORDER BY idoferta DESC LIMIT 1")->fetch();
    if (!$o) return null;
    return ['slug' => 'teste', 'idoferta' => (int)$o['idoferta'], 'nome' => (string)$o['nome'], 'meses' => (int)$o['meses'], 'valor' => round((float)$o['valor'], 2), 'parcelas_max' => 1];
}

/** Bônus (order bump) cadastrados pela equipe: [{id, titulo, descricao, valor}]. Vazio por padrão. */
function cddCheckoutBumps(PDO $pdo): array {
    $j = json_decode(cddConfig($pdo, 'checkout_bumps', '[]'), true);
    $r = [];
    foreach (is_array($j) ? $j : [] as $b) {
        $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($b['id'] ?? '')));
        $v = round((float)($b['valor'] ?? 0), 2);
        if ($id === '' || $v <= 0 || trim((string)($b['titulo'] ?? '')) === '') continue;
        $r[$id] = ['id' => $id, 'titulo' => cddStr($b['titulo'], 80), 'descricao' => cddStr($b['descricao'] ?? '', 200), 'valor' => $v];
    }
    return $r;
}

/** Cupom do código (qualquer gateway do catálogo), valendo hoje. Só no anual. Devolve ['codigo', 'tipo', 'valor'] ou null. */
function cddCheckoutCupom(PDO $pdo, string $codigo, int $meses): ?array {
    $cod = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $codigo));
    if ($cod === '' || $meses !== 12) return null;
    $st = $pdo->prepare('SELECT codigo, tipo, valor, validade, limite_uso FROM cdd_cupom WHERE codigo = ? AND ativo = 1 ORDER BY idcupom DESC'); $st->execute([$cod]);
    foreach ($st->fetchAll() as $c) {
        if (!empty($c['validade']) && $c['validade'] < cddHoje()) continue;
        if (!empty($c['limite_uso'])) {
            $u = $pdo->prepare("SELECT COUNT(*) FROM cdd_pagamento WHERE cupom = ? AND status = 'pago'"); $u->execute([$cod]);
            if ((int)$u->fetchColumn() >= (int)$c['limite_uso']) continue;
        }
        return ['codigo' => $cod, 'tipo' => $c['tipo'] === 'valor' ? 'valor' : 'percentual', 'valor' => (float)$c['valor']];
    }
    return null;
}

/** Conta do pedido: base, desconto do cupom e bônus. Devolve ['total', 'base', 'desconto', 'bumps' => [...], 'cupom' => ...] ou ['erro' => texto]. */
function cddCheckoutCotar(PDO $pdo, array $plano, array $bumpIds, string $cupom): array {
    $base = $plano['valor']; $desc = 0.0; $cp = null;
    if ($cupom !== '') {
        $cp = cddCheckoutCupom($pdo, $cupom, $plano['meses']);
        if (!$cp) return ['erro' => 'Este cupom não vale para este plano ou já acabou.'];
        $desc = $cp['tipo'] === 'valor' ? $cp['valor'] : round($base * $cp['valor'] / 100, 2);
        $desc = min($desc, $base - 5);   // o Asaas não cria cobrança abaixo de R$ 5
    }
    $bumps = [];
    if ($bumpIds && $plano['meses'] !== 1) {
        $cat = cddCheckoutBumps($pdo);
        foreach (array_unique($bumpIds) as $id) if (isset($cat[$id])) $bumps[] = $cat[$id];
    }
    $total = round($base - $desc + array_sum(array_column($bumps, 'valor')), 2);
    return ['total' => $total, 'base' => $base, 'desconto' => round($desc, 2), 'bumps' => $bumps, 'cupom' => $cp ? $cp['codigo'] : ''];
}

function cddCheckoutPlanoDe(PDO $pdo, string $slug): ?array {
    if ($slug === 'teste') return cddCheckoutOfertaTeste($pdo);
    $p = cddCheckoutPlanos($pdo);
    return $p[$slug] ?? null;
}

/** Chamada ao Asaas que falha com erro claro. */
function cddCheckoutAsaas(PDO $pdo, string $metodo, string $caminho, ?array $corpo = null): array {
    [$st, $j] = cddAsaasChamar($pdo, $metodo, $caminho, $corpo);
    if ($st < 200 || $st >= 300) {
        $msg = (string)($j['errors'][0]['description'] ?? ('erro ' . $st));
        throw new RuntimeException('asaas: ' . $msg, 502);
    }
    return $j;
}

/** Rotas públicas do checkout. Devolve null se a rota não é daqui. */
function cddCheckoutPublico(PDO $pdo, string $acao, bool $get, array $q, array $b, array $ctx): ?array {
    $ip = $ctx['ip'] ?? '0.0.0.0';
    if ($acao === 'checkout_info' && $get) {
        $planos = array_values(cddCheckoutPlanos($pdo));
        $sel = cddStr($q['plano'] ?? '', 20);
        $teste = $sel === 'teste' ? cddCheckoutOfertaTeste($pdo) : null;
        if ($teste) $planos[] = $teste;
        $resp = ['planos' => $planos, 'bumps' => array_values(cddCheckoutBumps($pdo))];
        // cotação: plano, bônus e cupom escolhidos
        $plano = $sel !== '' ? cddCheckoutPlanoDe($pdo, $sel) : null;
        if ($plano) {
            $ids = array_filter(explode(',', cddStr($q['bumps'] ?? '', 200)));
            $cot = cddCheckoutCotar($pdo, $plano, $ids, cddStr($q['cupom'] ?? '', 40));
            $resp['cotacao'] = $cot;
        }
        return cddResp(200, $resp);
    }
    if ($acao === 'checkout_status' && $get) {
        $st = $pdo->prepare('SELECT status FROM cdd_pedido WHERE token = ?'); $st->execute([cddStr($q['t'] ?? '', 40)]);
        $s = $st->fetchColumn();
        if ($s === false) return cddErro(404, 'pedido_nao_encontrado', 'Pedido não encontrado.');
        return cddResp(200, ['status' => $s === 'pago' ? 'pago' : 'aguardando']);
    }
    if ($acao === 'checkout_criar' && !$get) {
        if (trim((string)($b['site'] ?? '')) !== '') return cddResp(200, ['ok' => true]);   // isca de robô
        if (cddBloqueado($pdo, 'checkout', $ip, '', 10, 99)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde alguns minutos.');
        cddRegistrarTentativa($pdo, 'ip:' . $ip, 'checkout');
        $plano = cddCheckoutPlanoDe($pdo, cddStr($b['plano'] ?? '', 20));
        if (!$plano) return cddErro(422, 'plano_invalido', 'Escolha um plano.');
        $nome = cddStr($b['nome'] ?? '', 160); $email = cddEmail($b['email'] ?? '');
        $tel = cddTelefoneBR($b['telefone'] ?? ''); $cpf = preg_replace('/\D+/', '', (string)($b['cpf'] ?? ''));
        if (strlen($nome) < 5 || strpos(trim($nome), ' ') === false) return cddErro(422, 'nome_invalido', 'Digite o seu nome completo.');
        if ($email === '') return cddErro(422, 'email_invalido', 'Confira o e-mail: é nele que você recebe o acesso.');
        if ($tel === '') return cddErro(422, 'telefone_invalido', 'Digite o WhatsApp com DDD.');
        if (!cddCpfValido($cpf)) return cddErro(422, 'cpf_invalido', 'Confira o CPF.');
        $forma = ($b['forma'] ?? '') === 'cartao' ? 'cartao' : 'pix';
        $parc = max(1, min($plano['parcelas_max'], (int)($b['parcelas'] ?? 1)));
        if ($forma === 'pix' || $plano['meses'] === 1) $parc = 1;
        $cot = cddCheckoutCotar($pdo, $plano, array_filter(array_map('strval', (array)($b['bumps'] ?? []))), cddStr($b['cupom'] ?? '', 40));
        if (isset($cot['erro'])) return cddErro(422, 'cupom_invalido', $cot['erro']);
        $total = $cot['total'];
        $utm = cddStr(json_encode(array_intersect_key((array)($b['utm'] ?? []), array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'lp']))), 300);
        $token = bin2hex(random_bytes(16));
        $bumpTxt = $cot['bumps'] ? cddStr(implode(', ', array_column($cot['bumps'], 'titulo')), 500) : null;
        $pdo->prepare('INSERT INTO cdd_pedido (token, idoferta, nome, email, telefone, cpf, forma, parcelas, valor, bumps, cupom, utm, status, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$token, $plano['idoferta'], $nome, $email, $tel, $cpf, $forma, $parc, $total, $bumpTxt, $cot['cupom'] ?: null, $utm, 'criado', cddAgora()]);
        $idp = (int)$pdo->lastInsertId();
        $retorno = 'https://intusfit.com.br/cdd/obrigado';   // domínio cadastrado no Asaas; redireciona para /app/obrigado
        $desc = 'Código da Definição, ' . strtolower(preg_replace('/^Código da Definição,\s*/', '', $plano['nome']));
        try {
            $cli = cddCheckoutAsaas($pdo, 'POST', '/customers', ['name' => $nome, 'email' => $email, 'cpfCnpj' => $cpf, 'mobilePhone' => preg_replace('/^55/', '', $tel),
                                                                 'externalReference' => 'cddc:' . $idp, 'notificationDisabled' => false]);
            $pdo->prepare('UPDATE cdd_pedido SET asaas_cliente = ? WHERE idpedido = ?')->execute([(string)$cli['id'], $idp]);
            $hoje = cddHoje();
            if ($plano['meses'] === 1) {
                $s = cddCheckoutAsaas($pdo, 'POST', '/subscriptions', ['customer' => $cli['id'], 'billingType' => 'UNDEFINED', 'value' => $total, 'nextDueDate' => $hoje, 'cycle' => 'MONTHLY',
                                                                      'description' => $desc, 'externalReference' => 'cddp:' . $idp]);
                $pdo->prepare('UPDATE cdd_pedido SET asaas_ref = ?, asaas_tipo = ? WHERE idpedido = ?')->execute([(string)$s['id'], 'assinatura', $idp]);
                $pg = cddCheckoutAsaas($pdo, 'GET', '/subscriptions/' . rawurlencode((string)$s['id']) . '/payments?limit=1');
                $inv = (string)($pg['data'][0]['invoiceUrl'] ?? '');
                if ($inv === '') throw new RuntimeException('asaas: sem link de pagamento da assinatura', 502);
                $pdo->prepare('UPDATE cdd_pedido SET invoice_url = ? WHERE idpedido = ?')->execute([$inv, $idp]);
                return cddResp(200, ['ok' => true, 'token' => $token, 'forma' => 'redirecionar', 'url' => $inv]);
            }
            $corpo = ['customer' => $cli['id'], 'billingType' => $forma === 'pix' ? 'PIX' : 'CREDIT_CARD', 'dueDate' => $hoje, 'description' => $desc,
                      'externalReference' => 'cddp:' . $idp, 'callback' => ['successUrl' => $retorno, 'autoRedirect' => true]];
            if ($parc > 1) { $corpo['installmentCount'] = $parc; $corpo['totalValue'] = $total; } else $corpo['value'] = $total;
            $p = cddCheckoutAsaas($pdo, 'POST', '/payments', $corpo);
            $pdo->prepare('UPDATE cdd_pedido SET asaas_ref = ?, asaas_tipo = ?, asaas_parc = ?, invoice_url = ? WHERE idpedido = ?')
                ->execute([(string)$p['id'], 'cobranca', (string)($p['installment'] ?? '') ?: null, (string)($p['invoiceUrl'] ?? '') ?: null, $idp]);
            if ($forma === 'cartao') {
                if (empty($p['invoiceUrl'])) throw new RuntimeException('asaas: sem link de pagamento', 502);
                return cddResp(200, ['ok' => true, 'token' => $token, 'forma' => 'redirecionar', 'url' => (string)$p['invoiceUrl']]);
            }
            $qr = cddCheckoutAsaas($pdo, 'GET', '/payments/' . rawurlencode((string)$p['id']) . '/pixQrCode');
            return cddResp(200, ['ok' => true, 'token' => $token, 'forma' => 'pix', 'valor' => $total, 'pix' => ['imagem' => (string)($qr['encodedImage'] ?? ''), 'copia' => (string)($qr['payload'] ?? ''),
                                                                                                                  'expira' => (string)($qr['expirationDate'] ?? '')]]);
        } catch (Throwable $e) {
            error_log('[cdd checkout] ' . $e->getMessage());
            $pdo->prepare("UPDATE cdd_pedido SET status = 'falhou' WHERE idpedido = ?")->execute([$idp]);
            $falta = $e->getMessage() === 'chave_api_ausente';
            return cddErro(502, 'checkout_indisponivel', $falta ? 'O pagamento não está disponível agora. Fale com a equipe.' : 'Não consegui gerar o pagamento. Tente de novo em instantes ou fale com a equipe.');
        }
    }
    return null;
}

/** Acha o pedido de um aviso do Asaas: pela referência (cddp:ID), pela cobrança, pelo parcelamento ou pela assinatura. */
function cddCheckoutPedidoDoEvento(PDO $pdo, array $ev): ?array {
    $ext = (string)($ev['_extref'] ?? '');
    if (preg_match('/^cddp:(\d+)$/', $ext, $m)) {
        $st = $pdo->prepare('SELECT * FROM cdd_pedido WHERE idpedido = ?'); $st->execute([(int)$m[1]]);
        if ($p = $st->fetch()) return $p;
    }
    foreach ([['asaas_ref', (string)($ev['_cobranca'] ?? '')], ['asaas_parc', (string)($ev['_parcelamento'] ?? '')], ['asaas_ref', (string)($ev['_assinatura'] ?? '')]] as [$col, $v]) {
        if ($v === '') continue;
        $st = $pdo->prepare("SELECT * FROM cdd_pedido WHERE $col = ? ORDER BY idpedido DESC LIMIT 1"); $st->execute([$v]);
        if ($p = $st->fetch()) return $p;
    }
    return null;
}

/** Marca o pedido como pago (a 1ª vez). */
function cddCheckoutMarcarPago(PDO $pdo, int $idpedido): void {
    $pdo->prepare("UPDATE cdd_pedido SET status = 'pago', pago_em = ? WHERE idpedido = ? AND status <> 'pago'")->execute([cddAgora(), $idpedido]);
}

/** Admin: bônus e pedidos. */
function cddCheckoutAdmin(PDO $pdo, string $acao, bool $get, array $b): ?array {
    if ($acao === 'a_checkout_bumps') {
        if ($get) return cddResp(200, ['bumps' => array_values(cddCheckoutBumps($pdo))]);
        $lista = [];
        foreach ((array)($b['bumps'] ?? []) as $x) {
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($x['id'] ?? '')));
            $v = round((float)str_replace(',', '.', (string)($x['valor'] ?? 0)), 2);
            $t = cddStr($x['titulo'] ?? '', 80);
            if ($id === '' || $t === '' || $v <= 0 || $v > 10000) return cddErro(422, 'dados_invalidos', 'Cada bônus precisa de um código (letras e números), um título e um valor maior que zero.');
            $lista[] = ['id' => $id, 'titulo' => $t, 'descricao' => cddStr($x['descricao'] ?? '', 200), 'valor' => $v];
        }
        if (count($lista) > 5) return cddErro(422, 'dados_invalidos', 'No máximo 5 bônus.');
        cddConfigGravar($pdo, 'checkout_bumps', json_encode($lista, JSON_UNESCAPED_UNICODE));
        return cddResp(200, ['ok' => true, 'bumps' => $lista]);
    }
    if ($acao === 'a_checkout_pedidos' && $get) {
        $l = $pdo->query('SELECT idpedido, nome, email, telefone, forma, parcelas, valor, bumps, cupom, status, criado_em, pago_em FROM cdd_pedido ORDER BY idpedido DESC LIMIT 200')->fetchAll();
        foreach ($l as &$p) { $p['idpedido'] = (int)$p['idpedido']; $p['valor'] = (float)$p['valor']; $p['parcelas'] = (int)$p['parcelas']; }
        return cddResp(200, ['pedidos' => $l]);
    }
    return null;
}
