<?php
/**
 * Checkout próprio da Intus (checkout.html na raiz do site): o cliente escolhe produto e período, preenche o mínimo (nome, e-mail, WhatsApp e CPF) e paga:
 *  - Pix à vista: a cobrança nasce pela API do Asaas e o QR Code aparece na nossa página;
 *  - cartão: o cliente vai para a tela segura do Asaas (os dados do cartão nunca passam por aqui), em até N vezes sem juros (3, 6 ou 12 conforme o período).
 * Biblioteca interna (o "_" no nome bloqueia o acesso direto); o endpoint é checkout.php. Reaproveita a configuração e as chamadas de _asaas.php.
 *
 * O que acontece depois do pagamento (decisão de segurança de 13/11/2026): o webhook (asaas_webhook, em _asaas.php) marca o pedido como pago e AVISA A EQUIPE por e-mail
 * com os dados completos e o botão do WhatsApp. NADA é criado em intus_atleta nem em intus_mensalidade sozinho: a matrícula continua sendo feita pela equipe no painel.
 * (Criar a matrícula sozinho mexe em dado de aluno e duplica regras que moram no api.js; fica para uma etapa própria, com a descrição aprovada pelo Luiz.)
 * Valor e prazo vêm SEMPRE do catálogo abaixo, nunca do navegador. Os preços são os dos links de pagamento dos planos no Asaas (seção 132 do CLAUDE.md).
 */

require_once __DIR__ . '/_asaas.php';

const INTUS_CK_CATALOGO = [
    'treino'       => ['nome' => 'Treino',           'periodos' => ['tri' => [3, 897.00],  'sem' => [6, 1678.00], 'anual' => [12, 2987.00]]],
    'treino-dieta' => ['nome' => 'Treino e Dieta',   'periodos' => ['tri' => [3, 1497.00], 'sem' => [6, 2597.00], 'anual' => [12, 4597.00]]],
    'dieta'        => ['nome' => 'Dieta',            'periodos' => ['tri' => [3, 997.00],  'sem' => [6, 1689.00], 'anual' => [12, 2997.00]]],
];
const INTUS_CK_PERIODOS = ['tri' => 'Trimestral', 'sem' => 'Semestral', 'anual' => 'Anual'];
const INTUS_CK_RETORNO = 'https://intusfit.com.br/compra-confirmada.html';   // domínio cadastrado no Asaas

function ick_ddl(string $driver): array {
    if ($driver === 'sqlite') {
        return [
            "CREATE TABLE IF NOT EXISTS intus_checkout_pedido (
                idpedido INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT NOT NULL, produto TEXT NOT NULL, periodo TEXT NOT NULL, meses INTEGER NOT NULL,
                nome TEXT NOT NULL, email TEXT NOT NULL, telefone TEXT, cpf TEXT, forma TEXT NOT NULL, parcelas INTEGER NOT NULL DEFAULT 1, valor REAL NOT NULL,
                ip TEXT, asaas_customer TEXT, asaas_ref TEXT, asaas_parc TEXT, url TEXT, status TEXT NOT NULL DEFAULT 'criado',
                criado_em TEXT NOT NULL, pago_em TEXT, avisado_em TEXT, idatleta INTEGER, matriculado_em TEXT)",
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_ick_token ON intus_checkout_pedido (token)",
        ];
    }
    return ["CREATE TABLE IF NOT EXISTS intus_checkout_pedido (
        idpedido INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(40) NOT NULL,
        produto VARCHAR(20) NOT NULL,
        periodo VARCHAR(8) NOT NULL,
        meses INT NOT NULL,
        nome VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL,
        telefone VARCHAR(20) NULL,
        cpf VARCHAR(14) NULL,
        forma VARCHAR(10) NOT NULL,
        parcelas INT NOT NULL DEFAULT 1,
        valor DECIMAL(10,2) NOT NULL,
        ip VARCHAR(45) NULL,
        asaas_customer VARCHAR(40) NULL,
        asaas_ref VARCHAR(60) NULL,
        asaas_parc VARCHAR(60) NULL,
        url VARCHAR(300) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'criado',
        criado_em DATETIME NOT NULL,
        pago_em DATETIME NULL,
        avisado_em DATETIME NULL,
        idatleta INT NULL,
        matriculado_em DATETIME NULL,
        UNIQUE KEY uk_ick_token (token),
        INDEX ix_ick_ref (asaas_ref),
        INDEX ix_ick_parc (asaas_parc),
        INDEX ix_ick_ip (ip, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"];
}
function ick_garantir_tabelas(PDO $pdo): void {
    static $feito = null;
    if ($feito === null) $feito = new WeakMap();
    if (isset($feito[$pdo])) return;
    foreach (ick_ddl((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) as $sql) $pdo->exec($sql);
    // tabela criada antes da matrícula automática: só ADD COLUMN, nunca altera nem apaga dado
    foreach ([['idatleta', 'INT NULL'], ['matriculado_em', 'DATETIME NULL']] as [$col, $def]) {
        try { $pdo->query("SELECT $col FROM intus_checkout_pedido LIMIT 0"); }
        catch (Throwable $e) { try { $pdo->exec("ALTER TABLE intus_checkout_pedido ADD COLUMN $col $def"); } catch (Throwable $e2) {} }
    }
    $feito[$pdo] = true;
}

/** Telefone BR em dígitos com DDI 55, ou '' se não parecer um número brasileiro. */
function ick_telefone($t): string {
    $d = preg_replace('/\D+/', '', (string)$t);
    if (strpos($d, '55') === 0 && (strlen($d) === 12 || strlen($d) === 13)) return $d;
    if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
    return '';
}

/** Catálogo para a página: produtos, períodos, valor e parcelas. */
function ick_info(): array {
    $r = [];
    foreach (INTUS_CK_CATALOGO as $slug => $p) {
        $per = [];
        foreach ($p['periodos'] as $k => [$meses, $valor]) $per[] = ['periodo' => $k, 'nome' => INTUS_CK_PERIODOS[$k], 'meses' => $meses, 'valor' => $valor, 'parcelas_max' => $meses];
        $r[] = ['produto' => $slug, 'nome' => $p['nome'], 'periodos' => $per];
    }
    return ['produtos' => $r];
}

/**
 * Cria o pedido e a cobrança. Devolve [status HTTP, corpo].
 * $b: produto, periodo, nome, email, telefone, cpf, forma (pix|cartao), parcelas, site (isca).
 */
function ick_criar(PDO $pdo, array $b, string $ip): array {
    ick_garantir_tabelas($pdo);
    if (trim((string)($b['site'] ?? '')) !== '') return [200, ['ok' => true]];   // isca de robô
    $produto = (string)($b['produto'] ?? ''); $periodo = (string)($b['periodo'] ?? '');
    if (!isset(INTUS_CK_CATALOGO[$produto]['periodos'][$periodo])) return [422, ['ok' => false, 'erro' => 'plano_invalido', 'mensagem' => 'Escolha o plano.']];
    [$meses, $valor] = INTUS_CK_CATALOGO[$produto]['periodos'][$periodo];
    $nome = trim(preg_replace('/\s+/', ' ', strip_tags((string)($b['nome'] ?? ''))));
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $tel = ick_telefone($b['telefone'] ?? '');
    $cpf = asaas_documento((string)($b['cpf'] ?? ''));
    if (mb_strlen($nome) < 5 || strpos($nome, ' ') === false) return [422, ['ok' => false, 'erro' => 'nome_invalido', 'mensagem' => 'Digite o seu nome completo.']];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return [422, ['ok' => false, 'erro' => 'email_invalido', 'mensagem' => 'Confira o e-mail.']];
    if ($tel === '') return [422, ['ok' => false, 'erro' => 'telefone_invalido', 'mensagem' => 'Digite o WhatsApp com DDD.']];
    if (strlen($cpf) !== 11) return [422, ['ok' => false, 'erro' => 'cpf_invalido', 'mensagem' => 'Confira o CPF.']];
    $forma = (($b['forma'] ?? '') === 'cartao') ? 'cartao' : 'pix';
    $parc = $forma === 'pix' ? 1 : max(1, min($meses, (int)($b['parcelas'] ?? 1)));
    $desde = date('Y-m-d H:i:s', time() - 900);
    $n = $pdo->prepare('SELECT COUNT(*) FROM intus_checkout_pedido WHERE ip = ? AND criado_em >= ?'); $n->execute([$ip, $desde]);
    if ((int)$n->fetchColumn() >= 10) return [429, ['ok' => false, 'erro' => 'muitas_tentativas', 'mensagem' => 'Muitas tentativas. Aguarde alguns minutos.']];
    $token = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO intus_checkout_pedido (token, produto, periodo, meses, nome, email, telefone, cpf, forma, parcelas, valor, ip, status, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$token, $produto, $periodo, $meses, $nome, $email, $tel, $cpf, $forma, $parc, $valor, $ip, 'criado', asaas_agora()]);
    $id = (int)$pdo->lastInsertId();
    $desc = 'Intus Fit, Consultoria Premium, ' . INTUS_CK_CATALOGO[$produto]['nome'] . ', plano ' . strtolower(INTUS_CK_PERIODOS[$periodo]);
    $chamar = function (string $m, string $c, ?array $corpo = null) use ($pdo) {
        [$st, $j] = asaas_http($pdo, $m, $c, $corpo);
        if ($st < 200 || $st >= 300) throw new RuntimeException(asaas_erro_texto($j, $st), 502);
        return $j;
    };
    try {
        $cli = $chamar('POST', '/customers', ['name' => $nome, 'email' => $email, 'cpfCnpj' => $cpf, 'mobilePhone' => preg_replace('/^55/', '', $tel), 'externalReference' => 'intus:ckc:' . $id]);
        $corpo = ['customer' => $cli['id'], 'billingType' => $forma === 'pix' ? 'PIX' : 'CREDIT_CARD', 'dueDate' => date('Y-m-d'), 'description' => $desc,
                  'externalReference' => 'intus:ck:' . $id, 'callback' => ['successUrl' => INTUS_CK_RETORNO, 'autoRedirect' => true]];
        if ($parc > 1) { $corpo['installmentCount'] = $parc; $corpo['totalValue'] = $valor; } else $corpo['value'] = $valor;
        $p = $chamar('POST', '/payments', $corpo);
        $pdo->prepare('UPDATE intus_checkout_pedido SET asaas_customer = ?, asaas_ref = ?, asaas_parc = ?, url = ? WHERE idpedido = ?')
            ->execute([(string)$cli['id'], (string)$p['id'], ((string)($p['installment'] ?? '')) ?: null, ((string)($p['invoiceUrl'] ?? '')) ?: null, $id]);
        if ($forma === 'cartao') {
            if (empty($p['invoiceUrl'])) throw new RuntimeException('sem link de pagamento', 502);
            return [200, ['ok' => true, 'token' => $token, 'forma' => 'redirecionar', 'url' => (string)$p['invoiceUrl']]];
        }
        $qr = $chamar('GET', '/payments/' . rawurlencode((string)$p['id']) . '/pixQrCode');
        return [200, ['ok' => true, 'token' => $token, 'forma' => 'pix', 'valor' => $valor, 'pix' => ['imagem' => (string)($qr['encodedImage'] ?? ''), 'copia' => (string)($qr['payload'] ?? ''), 'expira' => (string)($qr['expirationDate'] ?? '')]]];
    } catch (Throwable $e) {
        @error_log('[checkout intus] ' . $e->getMessage());
        $pdo->prepare("UPDATE intus_checkout_pedido SET status = 'falhou' WHERE idpedido = ?")->execute([$id]);
        $semChave = $e->getMessage() === 'chave_api_ausente';
        return [502, ['ok' => false, 'erro' => 'checkout_indisponivel', 'mensagem' => $semChave ? 'O pagamento não está disponível agora. Fale com a equipe pelo WhatsApp.' : 'Não consegui gerar o pagamento. Tente de novo em instantes ou fale com a equipe pelo WhatsApp.']];
    }
}

function ick_status(PDO $pdo, string $token): array {
    ick_garantir_tabelas($pdo);
    $st = $pdo->prepare('SELECT status FROM intus_checkout_pedido WHERE token = ?'); $st->execute([$token]);
    $s = $st->fetchColumn();
    if ($s === false) return [404, ['ok' => false, 'erro' => 'pedido_nao_encontrado']];
    return [200, ['ok' => true, 'status' => $s === 'pago' ? 'pago' : 'aguardando']];
}

/** Envia o aviso à equipe (cai em e-mail; falha de envio nunca derruba o webhook). */
function ick_enviar_email(string $para, string $assunto, string $texto): bool {
    $lib = __DIR__ . '/_smtp_seguro.php';
    if (!is_file($lib)) return false;
    try { require_once $lib; return function_exists('intusEnviarEmailSimples') && intusEnviarEmailSimples($para, $assunto, $texto); }
    catch (Throwable $e) { return false; }
}

/** E-mail de boas-vindas ao aluno novo: como criar a senha e o que acontece em seguida. */
function ick_texto_aluno(array $p): string {
    $prim = explode(' ', trim($p['nome']))[0];
    return "Oi, $prim! Bem-vindo(a) à Intus Fit. O seu plano está ativo.\n\n"
        . "1) Crie a sua senha (1 minuto)\nAbra https://intusfit.com.br/app/painel/login.html, toque em \"Esqueci a senha\", digite este e-mail ({$p['email']}) e use o código que chegar para criar a senha.\n\n"
        . "2) Coloque o app na tela inicial do celular\niPhone: abra o endereço no Safari, toque em Compartilhar e depois em \"Adicionar à Tela de Início\". Android: abra no Chrome, toque nos três pontinhos e em \"Instalar app\".\n\n"
        . "3) Fale com a equipe\nOs profissionais vão chamar você no WhatsApp para montar o seu treino com as suas informações. Se preferir, chame você: https://wa.me/5545991156006\n\n"
        . "Um abraço,\nEquipe Intus Fit";
}

function ick_texto_equipe(array $p, string $situacao, ?array $mat = null): string {
    $prod = INTUS_CK_CATALOGO[$p['produto']]['nome'] ?? $p['produto'];
    $per = INTUS_CK_PERIODOS[$p['periodo']] ?? $p['periodo'];
    $wa = 'https://wa.me/' . $p['telefone'] . '?text=' . rawurlencode('Oi, ' . explode(' ', $p['nome'])[0] . '! Aqui é a equipe da Intus Fit. Recebemos o seu pagamento e já vamos liberar o seu acesso. Qualquer dúvida é só responder aqui.');
    return $situacao . "\n\nNome: {$p['nome']}\nE-mail: {$p['email']}\nWhatsApp: +{$p['telefone']}\nCPF: {$p['cpf']}\nPlano: $prod, $per\n"
        . 'Valor: R$ ' . number_format((float)$p['valor'], 2, ',', '.') . ($p['forma'] === 'cartao' ? ' no cartão em ' . (int)$p['parcelas'] . 'x' : ' no Pix') . "\n\n"
        . ($mat && !empty($mat['ids']) ? "\nO QUE FOI GRAVADO: " . ($mat['novo_aluno'] ? 'aluno NOVO criado (a senha ele cria pelo "Esqueci a senha"; recebeu o e-mail de boas-vindas)' : 'aluno que já existia') . ', ' . count($mat['ids']) . " linha(s) em Matrículas.\n"
            . 'Abrir a matrícula no painel: https://intusfit.com.br/app/painel/mensalidades.html?m=' . $mat['ids'][0] . "\n"
            . ($mat['avisos'] ? "CONFIRA:\n- " . implode("\n- ", $mat['avisos']) . "\n" : '') : '')
        . "\nPróximos passos: atribuir o professor, montar a primeira ficha e falar com o aluno. Boas-vindas no WhatsApp com a mensagem pronta:\n$wa\n";
}

/**
 * Chamado pelo webhook do Asaas. $novo = recebida | estornada | cancelada. Devolve o resultado (texto) se a cobrança é de um pedido do checkout, ou null se não é.
 */
function ick_webhook(PDO $pdo, string $novo, array $pay): ?string {
    $ref = (string)($pay['externalReference'] ?? '');
    $p = null;
    if (preg_match('/^intus:ck:(\d+)$/', $ref, $m)) {
        ick_garantir_tabelas($pdo);
        $st = $pdo->prepare('SELECT * FROM intus_checkout_pedido WHERE idpedido = ?'); $st->execute([(int)$m[1]]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$p) {
        $inst = (string)($pay['installment'] ?? ''); $id = (string)($pay['id'] ?? '');
        if ($inst === '' && $id === '') return null;
        try {   // sem criar a tabela aqui: se ela não existe, não há pedido do checkout
            $st = $pdo->prepare('SELECT * FROM intus_checkout_pedido WHERE (asaas_ref = ? AND asaas_ref <> \'\') OR (asaas_parc = ? AND asaas_parc <> \'\') ORDER BY idpedido DESC LIMIT 1'); $st->execute([$id, $inst]);
            $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { return null; }
    }
    if (!$p) return null;
    if ($novo === 'recebida') {
        if ($p['status'] === 'pago') return 'repetido';
        $pdo->prepare("UPDATE intus_checkout_pedido SET status = 'pago', pago_em = ? WHERE idpedido = ? AND status <> 'pago'")->execute([asaas_agora(), (int)$p['idpedido']]);
        // matrícula automática (liberada na hora; a equipe recebe o aviso "REVISAR" para conferir)
        require_once __DIR__ . '/_matricula_auto.php';
        $mat = ima_matricular($pdo, $p);
        if ($mat['ok']) $pdo->prepare('UPDATE intus_checkout_pedido SET idatleta = ?, matriculado_em = ? WHERE idpedido = ?')->execute([(int)$mat['idatleta'], asaas_agora(), (int)$p['idpedido']]);
        if ($mat['ok'] && $mat['novo_aluno']) ick_enviar_email($p['email'], 'Seu acesso à Intus Fit', ick_texto_aluno($p));
        $ok = ick_enviar_email('contato@intusfit.com.br', ($mat['ok'] ? 'REVISAR matrícula automática: ' : 'ATENÇÃO, matrícula NÃO criada: ') . $p['nome'], ick_texto_equipe($p, $mat['ok']
            ? 'COMPRA PAGA no checkout da Intus. A matrícula foi criada e liberada AUTOMATICAMENTE. Confira se está certo.'
            : 'COMPRA PAGA no checkout da Intus, mas a matrícula automática FALHOU (' . ($mat['erro'] ?? 'erro') . '). O pagamento está confirmado: faça a matrícula à mão no painel.', $mat));
        if ($ok) $pdo->prepare('UPDATE intus_checkout_pedido SET avisado_em = ? WHERE idpedido = ?')->execute([asaas_agora(), (int)$p['idpedido']]);
        if (!$mat['ok']) return 'checkout_paga_matricula_falhou';
        return $ok ? 'checkout_paga_matriculada_equipe_avisada' : 'checkout_paga_matriculada_aviso_falhou';
    }
    if ($novo === 'estornada') {
        $pdo->prepare("UPDATE intus_checkout_pedido SET status = 'estornado' WHERE idpedido = ?")->execute([(int)$p['idpedido']]);
        ick_enviar_email('contato@intusfit.com.br', 'Estorno no checkout da Intus: ' . $p['nome'], ick_texto_equipe($p, 'ESTORNO de uma compra do checkout da Intus. Se o aluno já foi matriculado, confira o acesso.'));
        return 'checkout_estornada';
    }
    return 'checkout_ignorado';
}
