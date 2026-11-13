<?php
/**
 * Testes do checkout próprio da Intus (app/api/_ck_intus.php): catálogo, pedido, Pix, cartão parcelado, aviso de pagamento e o que NÃO acontece sozinho.
 *
 * Rodar (PHP 8.2 com mbstring, openssl, curl e pdo_sqlite):  php testes/checkout_intus.php
 * O banco é SQLite em memória e o Asaas é simulado ($GLOBALS['asaasHttpFake']): nenhuma chamada sai daqui e nenhum e-mail é enviado.
 */
require_once __DIR__ . '/../app/api/_ck_intus.php';
require_once __DIR__ . '/../app/api/_matricula_auto.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    asaas_garantir_tabelas($pdo);
    $pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, senha TEXT, telefone TEXT, cpf TEXT, origem TEXT, dt_cadastro TEXT, ranking_optin TEXT, stbloqueio TEXT, codacesso TEXT)");
    $pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, dshistorico TEXT, dtinicio TEXT, dtvencimento TEXT NOT NULL, vlpagar REAL DEFAULT 0, vldesconto REAL DEFAULT 0, vljuro REAL DEFAULT 0, stpgto TEXT DEFAULT 'N', dtpagamento TEXT, vlpagamento REAL, dspagamento TEXT, professor TEXT, tipo_plano TEXT, stmatricula TEXT DEFAULT 'ativa', idmatricula_origem INTEGER, parcela_num INTEGER, parcela_total INTEGER, forma_pgto TEXT, recorrencia TEXT)");
    return $pdo;
}

$pdo = banco();
asaas_cfg_gravar($pdo, ['apikey' => '$aact_FAKE_chave_de_teste_1234567890', 'token' => 'tok-' . str_repeat('b', 44), 'ambiente' => 'producao']);
$chamadas = []; $corpos = [];
$GLOBALS['asaasHttpFake'] = function ($m, $url, $corpo) use (&$chamadas, &$corpos) {
    $c = $m . ' ' . preg_replace('~^https://[^/]+/v3~', '', $url);
    $chamadas[] = $c; $corpos[$c] = $corpo;
    if ($c === 'POST /customers') return [200, ['id' => 'cus_i' . count($chamadas)]];
    if ($c === 'POST /payments') return [200, ['id' => 'pay_i' . count($chamadas), 'invoiceUrl' => 'https://asaas.test/i/' . count($chamadas), 'installment' => isset($corpo['installmentCount']) ? 'ins_i' . count($chamadas) : null]];
    if (strpos($c, '/pixQrCode') !== false) return [200, ['encodedImage' => 'QUJD', 'payload' => '00020126INTUSPIX', 'expirationDate' => '2026-12-31 23:59:59']];
    return [404, ['errors' => [['description' => 'nao mockado ' . $c]]]];
};
$dados = ['produto' => 'treino-dieta', 'periodo' => 'anual', 'nome' => 'Carlos Pereira', 'email' => 'Carlos.P@Exemplo.com', 'telefone' => '(45) 99999-0001', 'cpf' => '529.982.247-25', 'forma' => 'pix'];

// ── Catálogo ──
$info = ick_info();
confere('3 produtos', 3, count($info['produtos']));
$td = array_values(array_filter($info['produtos'], function ($p) { return $p['produto'] === 'treino-dieta'; }))[0];
confere('Treino e Dieta anual: R$ 4.597 em até 12x', [4597.0, 12], [$td['periodos'][2]['valor'], $td['periodos'][2]['parcelas_max']]);

// ── Validações ──
[$st, $r] = ick_criar($pdo, array_merge($dados, ['produto' => 'inexistente']), '10.1.1.1');
confere('produto que não existe: 422', 422, $st);
[$st, $r] = ick_criar($pdo, array_merge($dados, ['cpf' => '111.111.111-11']), '10.1.1.1');
confere('cpf inválido: 422', 'cpf_invalido', $r['erro']);
[$st, $r] = ick_criar($pdo, array_merge($dados, ['nome' => 'Carlos']), '10.1.1.1');
confere('nome sem sobrenome: 422', 'nome_invalido', $r['erro']);
[$st, $r] = ick_criar($pdo, array_merge($dados, ['telefone' => '123']), '10.1.1.1');
confere('telefone inválido: 422', 'telefone_invalido', $r['erro']);
[$st, $r] = ick_criar($pdo, array_merge($dados, ['site' => 'spam']), '10.1.1.1');
confere('isca de robô não cria pedido nem chama o Asaas', [0, []], [(int)$pdo->query("SELECT COUNT(*) FROM intus_checkout_pedido")->fetchColumn(), $chamadas]);

confere('o produto de teste não aparece na lista pública', 3, count(ick_info()['produtos']));
confere('com ?teste=1 aparece (R$ 5)', [4, 5.0], [count(ick_info(true)['produtos']), ick_info(true)['produtos'][3]['periodos'][0]['valor']]);
[$st, $r] = ick_criar($pdo, array_merge($dados, ['produto' => 'teste', 'periodo' => 'tri', 'email' => 'teste@exemplo.com']), '10.1.9.9');
confere('o produto de teste cria cobrança de R$ 5', [200, 5.0], [$st, (float)$corpos['POST /payments']['value']]);
$chamadas = []; $corpos = [];
// ── Pix ──
[$st, $r] = ick_criar($pdo, $dados, '10.1.1.2');
$p = $pdo->query("SELECT * FROM intus_checkout_pedido ORDER BY idpedido DESC LIMIT 1")->fetch();
confere('Pix devolve o QR Code e o copia e cola', ['pix', '00020126INTUSPIX', 4597.0], [$r['forma'], $r['pix']['copia'], $r['valor']]);
confere('pedido guarda valor do catálogo, CPF em dígitos e telefone com DDI', [4597.0, '52998224725', '5545999990001', 'carlos.p@exemplo.com'], [(float)$p['valor'], $p['cpf'], $p['telefone'], $p['email']]);
$corpo = $corpos['POST /payments'];
confere('cobrança Pix: valor do catálogo, referência e retorno', ['PIX', 4597.0, 'intus:ck:' . $p['idpedido']], [$corpo['billingType'], (float)$corpo['value'], $corpo['externalReference']]);
confere('o retorno é no domínio cadastrado no Asaas', true, strpos($corpo['callback']['successUrl'], 'https://intusfit.com.br/') === 0);
confere('o valor não vem do navegador (um valor falso é ignorado)', 4597.0, (function () use ($pdo, $dados) { ick_criar($pdo, array_merge($dados, ['valor' => 1, 'email' => 'v@exemplo.com']), '10.1.1.3'); return (float)$GLOBALS['corpos']['POST /payments']['value']; })());
[$st, $s] = ick_status($pdo, $p['token']);
confere('antes do pagamento: aguardando', 'aguardando', $s['status']);

// ── Cartão parcelado ──
[$st, $r] = ick_criar($pdo, array_merge($dados, ['cpf' => '111.444.777-35', 'email' => 'c2@exemplo.com', 'forma' => 'cartao', 'parcelas' => 12, 'periodo' => 'anual']), '10.1.1.4');
$corpo = $corpos['POST /payments'];
confere('cartão: vai para a tela do Asaas em 12x sem juros', ['redirecionar', 'CREDIT_CARD', 12, 4597.0, false], [$r['forma'], $corpo['billingType'], $corpo['installmentCount'], (float)$corpo['totalValue'], isset($corpo['value'])]);
$pc = $pdo->query("SELECT * FROM intus_checkout_pedido ORDER BY idpedido DESC LIMIT 1")->fetch();
[$st, $r] = ick_criar($pdo, array_merge($dados, ['email' => 'c3@exemplo.com', 'forma' => 'cartao', 'parcelas' => 12, 'periodo' => 'tri']), '10.1.1.5');
confere('o máximo de parcelas segue o período (trimestral: 3)', 3, (int)$corpos['POST /payments']['installmentCount']);

// ── Aviso de pagamento: marca o pedido, avisa a equipe e NÃO mexe em aluno nem matrícula ──
$aviso = function ($ev, $pay) { return ['event' => $ev, 'payment' => $pay]; };
$tok = 'tok-' . str_repeat('b', 44);
[$st, $r] = asaas_webhook($pdo, $aviso('PAYMENT_RECEIVED', ['id' => $p['asaas_ref'], 'externalReference' => 'intus:ck:' . $p['idpedido'], 'billingType' => 'PIX', 'value' => 4597.0]), $tok);
confere('aviso de Pix pago marca o pedido (o e-mail não sai no teste)', 200, $st);
confere('resultado do checkout: matriculada', true, strpos($r['resultado'], 'checkout_paga_matriculada') === 0);
[$st, $s] = ick_status($pdo, $p['token']);
confere('depois do aviso: pago', 'pago', $s['status']);
$at = $pdo->query("SELECT * FROM atleta WHERE email = 'carlos.p@exemplo.com'")->fetch();
confere('o aluno novo foi criado com os dados do pedido, origem professor e senha que ninguém conhece', [true, 'Carlos Pereira', '5545999990001', 'professor', true], [(bool)$at, $at['nome'], $at['telefone'], $at['origem'], strlen($at['senha']) > 40 && !password_verify('', $at['senha'])]);
$ms = $pdo->query("SELECT * FROM intus_mensalidade WHERE idatleta = " . (int)$at['idatleta'] . " ORDER BY idmensalidade")->fetchAll();
confere('Pix: um ciclo pago e o ciclo seguinte em aberto', [2, 'S', 'N', 'anual', 'anual', 4597.0, 'asaas_pix'], [count($ms), $ms[0]['stpgto'], $ms[1]['stpgto'], $ms[0]['recorrencia'], $ms[1]['recorrencia'], (float)$ms[0]['vlpagamento'], $ms[0]['forma_pgto']]);
confere('o ciclo seguinte começa onde o pago termina e dura o mesmo período', [$ms[0]['dtvencimento'], ima_somar_meses($ms[0]['dtvencimento'], 12)], [$ms[1]['dtinicio'], $ms[1]['dtvencimento']]);
confere('o pedido guarda o aluno e a hora da matrícula', true, (int)$pdo->query("SELECT idatleta FROM intus_checkout_pedido WHERE idpedido = " . (int)$p['idpedido'])->fetchColumn() === (int)$at['idatleta']);
[$st, $r] = asaas_webhook($pdo, $aviso('PAYMENT_CONFIRMED', ['id' => $p['asaas_ref'], 'externalReference' => 'intus:ck:' . $p['idpedido'], 'value' => 4597.0]), $tok);
confere('o mesmo pedido avisado de novo é "repetido" e não duplica a matrícula', [ 'repetido', 2], [$r['resultado'], (int)$pdo->query("SELECT COUNT(*) FROM intus_mensalidade WHERE idatleta = " . (int)$at['idatleta'])->fetchColumn()]);
// parcela de cartão: achada pelo parcelamento, mesmo sem a referência
[$st, $r] = asaas_webhook($pdo, $aviso('PAYMENT_RECEIVED', ['id' => 'pay_parcela_x', 'installment' => $pc['asaas_parc'], 'billingType' => 'CREDIT_CARD', 'value' => 383.08]), $tok);
confere('parcela do cartão é achada pelo parcelamento', true, strpos($r['resultado'], 'checkout_paga') === 0);
$pc2 = $pdo->query("SELECT status FROM intus_checkout_pedido WHERE idpedido = " . (int)$pc['idpedido'])->fetchColumn();
confere('pedido do cartão fica pago', 'pago', $pc2);
$atC = $pdo->query("SELECT idatleta FROM atleta WHERE email = 'c2@exemplo.com'")->fetchColumn();
$msC = $pdo->query("SELECT * FROM intus_mensalidade WHERE idatleta = " . (int)$atC . " ORDER BY idmensalidade")->fetchAll();
confere('cartão 12x: 12 parcelas, todas com baixa no dia, valor total fechando e sem ciclo novo sozinho', [12, ['S'], 4597.0, [null]], [count($msC), array_values(array_unique(array_column($msC, 'stpgto'))), round(array_sum(array_column($msC, 'vlpagamento')), 2), array_values(array_unique(array_column($msC, 'recorrencia')))]);
confere('as parcelas levam a marca do checkout e a ligação com a primeira', [true, (int)$msC[0]['idmensalidade'], 12], [strpos($msC[3]['dspagamento'], 'Asaas checkout') === 0, (int)$msC[5]['idmatricula_origem'], (int)$msC[0]['parcela_total']]);
[$st, $r] = asaas_webhook($pdo, $aviso('PAYMENT_REFUNDED', ['id' => $p['asaas_ref'], 'externalReference' => 'intus:ck:' . $p['idpedido']]), $tok);
confere('estorno marca o pedido', 'checkout_estornada', $r['resultado']);
// cobrança que não é do checkout continua no caminho de sempre
[$st, $r] = asaas_webhook($pdo, $aviso('PAYMENT_RECEIVED', ['id' => 'pay_outra', 'externalReference' => 'outra:coisa', 'value' => 100]), $tok);
confere('cobrança de outra origem segue ignorada', 'outra_origem', $r['resultado']);
// ── Documento estrangeiro: sem CPF no Asaas e só cartão ──
$chamadas = []; $corpos = [];
$GLOBALS['asaasHttpFake'] = function ($m, $url, $corpo) use (&$chamadas, &$corpos) {
    $c = $m . ' ' . preg_replace('~^https://[^/]+/v3~', '', $url); $chamadas[] = $c; $corpos[$c] = $corpo;
    if ($c === 'POST /customers') return [200, ['id' => 'cus_e']];
    if ($c === 'POST /payments') return [200, ['id' => 'pay_e', 'invoiceUrl' => 'https://asaas.test/i/e']];
    return [404, []];
};
[$st, $r] = ick_criar($pdo, array_merge($dados, ['doc_tipo' => 'estrangeiro', 'cpf' => 'ab 123456', 'forma' => 'pix', 'email' => 'estr@exemplo.com', 'periodo' => 'tri', 'parcelas' => 3]), '10.1.2.2');
$pe = $pdo->query("SELECT * FROM intus_checkout_pedido WHERE email = 'estr@exemplo.com'")->fetch();
confere('documento estrangeiro: vai para o cartão mesmo pedindo Pix e o Asaas não recebe cpfCnpj', ['redirecionar', 'AB123456', 'cartao', false], [$r['forma'], $pe['cpf'], $pe['forma'], isset($corpos['POST /customers']['cpfCnpj'])]);
[$st, $r] = ick_criar($pdo, array_merge($dados, ['doc_tipo' => 'estrangeiro', 'cpf' => '12', 'email' => 'e2@exemplo.com']), '10.1.2.2');
confere('documento estrangeiro curto demais: 422', 'cpf_invalido', $r['erro']);

// ── Documento já cadastrado com outro e-mail: o link vai só ao e-mail do cadastro e o aluno escolhe ──
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf, origem) VALUES ('Marcos Antigo', 'marcos@antigo.com', 'x', '529.982.247-25', 'professor')");
$pedC = ['idpedido' => 77, 'nome' => 'Marcos A.', 'email' => 'marcos@novo.com'];
$idMarcos = (int)$pdo->query("SELECT idatleta FROM atleta WHERE email = 'marcos@antigo.com'")->fetchColumn();
$tokenConf = ick_pedir_confirmacao_email($pdo, $pedC, ['idatleta' => $idMarcos, 'email_atual' => 'marcos@antigo.com', 'email_novo' => 'marcos@novo.com']);
confere('o pedido de confirmação guarda só o hash do token', [true, 0], [strlen($tokenConf) === 48, (int)$pdo->query("SELECT COUNT(*) FROM intus_email_confirmacao WHERE token_hash = '" . $tokenConf . "'")->fetchColumn()]);
confere('e-mail mascarado', 'm***@a***.com', ick_mascarar_email('marcos@antigo.com'));
[$st, $r] = ick_conf_info($pdo, $tokenConf);
confere('a página vê os dois e-mails', [200, 'marcos@antigo.com', 'marcos@novo.com'], [$st, $r['email_atual'], $r['email_novo']]);
confere('token inválido: 404', 404, ick_conf_info($pdo, str_repeat('a', 48))[0]);
[$st, $r] = ick_conf_escolher($pdo, $tokenConf, 'qualquer');
confere('escolha inválida: 422 e nada muda', [422, 'marcos@antigo.com'], [$st, $pdo->query("SELECT email FROM atleta WHERE idatleta = $idMarcos")->fetchColumn()]);
[$st, $r] = ick_conf_escolher($pdo, $tokenConf, 'novo');
confere('escolher o e-mail novo troca o e-mail do cadastro', [200, 'marcos@novo.com'], [$st, $pdo->query("SELECT email FROM atleta WHERE idatleta = $idMarcos")->fetchColumn()]);
confere('o link só vale uma vez', 404, ick_conf_escolher($pdo, $tokenConf, 'atual')[0]);
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf) VALUES ('Outra', 'ocupado@exemplo.com', 'x', '')");
$t2 = ick_pedir_confirmacao_email($pdo, $pedC, ['idatleta' => $idMarcos, 'email_atual' => 'marcos@novo.com', 'email_novo' => 'ocupado@exemplo.com']);
[$st, $r] = ick_conf_escolher($pdo, $t2, 'novo');
confere('e-mail que já é de outra conta: 409 e nada muda', [409, 'marcos@novo.com'], [$st, $pdo->query("SELECT email FROM atleta WHERE idatleta = $idMarcos")->fetchColumn()]);
[$st, $r] = ick_conf_escolher($pdo, $t2, 'atual');
confere('manter o atual não altera nada', [200, 'marcos@novo.com'], [$st, $pdo->query("SELECT email FROM atleta WHERE idatleta = $idMarcos")->fetchColumn()]);
$t3 = ick_pedir_confirmacao_email($pdo, $pedC, ['idatleta' => 1, 'email_atual' => 'a@a.com', 'email_novo' => 'b@b.com']);
$pdo->exec("UPDATE intus_email_confirmacao SET expira_em = '2000-01-01 00:00:00' WHERE token_hash = '" . hash('sha256', $t3) . "'");
confere('link vencido: 404', 404, ick_conf_info($pdo, $t3)[0]);

// falha do Asaas
$GLOBALS['asaasHttpFake'] = function () { throw new RuntimeException('asaas_fora_do_ar', 502); };
[$st, $r] = ick_criar($pdo, array_merge($dados, ['email' => 'rede@exemplo.com']), '10.1.1.6');
confere('Asaas fora do ar: 502 e pedido marcado como falhou', [502, 'falhou'], [$st, $pdo->query("SELECT status FROM intus_checkout_pedido WHERE email = 'rede@exemplo.com'")->fetchColumn()]);
// limite por IP
$GLOBALS['asaasHttpFake'] = function ($m, $url, $corpo) { return strpos($url, 'customers') !== false ? [200, ['id' => 'c']] : (strpos($url, 'pixQrCode') !== false ? [200, ['encodedImage' => '', 'payload' => 'x']] : [200, ['id' => 'p', 'invoiceUrl' => 'u']]); };
for ($i = 0; $i < 12; $i++) [$st] = ick_criar($pdo, array_merge($dados, ['email' => "l$i@exemplo.com"]), '10.1.1.9');
confere('muitos pedidos do mesmo IP: 429', 429, $st);

echo $ok . " ok\n";
if ($falhas) { echo count($falhas) . " FALHA(S):\n  " . implode("\n  ", $falhas) . "\n"; exit(1); }
