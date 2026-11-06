<?php
/**
 * Testes da cobrança pelo Asaas (app/api/_asaas.php): link de pagamento por mensalidade, aviso de pagamento e o que aparece no painel.
 *
 * Rodar (PHP 8.2 com mbstring, openssl, curl e pdo_sqlite):  php testes/asaas.php
 * O banco é SQLite em memória (a biblioteca usa SQL portável) e o Asaas é simulado ($GLOBALS['asaasHttpFake']): nenhuma chamada sai daqui.
 */
require_once __DIR__ . '/../app/api/_asaas.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    asaas_garantir_tabelas($pdo);
    $pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY, idatleta INTEGER NOT NULL, dshistorico TEXT, dtinicio TEXT, dtvencimento TEXT, vlpagar REAL, vldesconto REAL DEFAULT 0, stpgto TEXT DEFAULT 'N')");
    return $pdo;
}
function dispara(callable $f) { try { return [true, $f()]; } catch (Throwable $e) { return [false, $e]; } }

// ── Documento ──
confere('cpf válido (com máscara)', '52998224725', asaas_documento('529.982.247-25'));
confere('cpf com dígito errado', '', asaas_documento('529.982.247-26'));
confere('cpf de dígitos repetidos', '', asaas_documento('111.111.111-11'));
confere('cnpj válido', '11222333000181', asaas_documento('11.222.333/0001-81'));
confere('cnpj com dígito errado', '', asaas_documento('11.222.333/0001-82'));
confere('curto demais', '', asaas_documento('1234'));
confere('vazio', '', asaas_documento(''));

// ── Valor e vencimento ──
confere('valor = combinado menos desconto', 270.0, asaas_valor_mensalidade(['vlpagar' => 300, 'vldesconto' => 30]));
confere('valor nunca negativo', 0.0, asaas_valor_mensalidade(['vlpagar' => 10, 'vldesconto' => 30]));
confere('vencimento: início do ciclo no futuro', '2026-11-20', asaas_vencimento(['dtinicio' => '2026-11-20', 'dtvencimento' => '2026-12-20'], '2026-11-06'));
confere('vencimento: início já passou vira hoje', '2026-11-06', asaas_vencimento(['dtinicio' => '2026-10-20', 'dtvencimento' => '2026-11-20'], '2026-11-06'));
confere('vencimento: sem início usa o vencimento', '2026-11-30', asaas_vencimento(['dtinicio' => null, 'dtvencimento' => '2026-11-30'], '2026-11-06'));
confere('forma: Pix', 'pix', asaas_forma('PIX'));
confere('forma: cartão', 'cartao', asaas_forma('CREDIT_CARD'));
confere('forma: boleto', 'boleto', asaas_forma('BOLETO'));

// ── Configuração e segredos ──
$pdo = banco();
confere('sem configuração tudo vazio', ['apikey' => '', 'token' => '', 'ambiente' => 'producao'], asaas_cfg($pdo));
[$o, $e] = dispara(function () use ($pdo) { return asaas_http($pdo, 'GET', '/customers'); });
confere('sem chave de API não chama o Asaas', 'chave_api_ausente', $e->getMessage());
asaas_cfg_gravar($pdo, ['apikey' => '$aact_hmlg_FAKE_chave_de_teste_1234567890', 'token' => 'tok-' . str_repeat('a', 44), 'ambiente' => 'sandbox']);
confere('configuração gravada e lida', ['sandbox', 'tok-' . str_repeat('a', 44)], [asaas_cfg($pdo)['ambiente'], asaas_cfg($pdo)['token']]);
asaas_cfg_gravar($pdo, array_merge(asaas_cfg($pdo), ['ambiente' => 'qualquer']));
confere('ambiente inválido volta para produção', 'producao', asaas_cfg($pdo)['ambiente']);
asaas_cfg_gravar($pdo, array_merge(asaas_cfg($pdo), ['ambiente' => 'sandbox']));

// ── Simulador do Asaas ──
$chamadas = []; $clienteExiste = false; $falha = '';
$GLOBALS['asaasHttpFake'] = function ($metodo, $url, $corpo) use (&$chamadas, &$clienteExiste, &$falha) {
    $c = $metodo . ' ' . preg_replace('~^https://[^/]+/v3~', '', $url);
    $chamadas[] = ['c' => $c, 'corpo' => $corpo, 'url' => $url];
    if ($falha === 'rede') throw new RuntimeException('asaas_fora_do_ar', 502);
    if ($falha === 'recusa') return [400, ['errors' => [['code' => 'invalid_action', 'description' => 'O valor mínimo para cobrança é R$ 5,00']]]];
    if ($c === 'GET /customers?cpfCnpj=52998224725') return [200, ['data' => $clienteExiste ? [['id' => 'cus_existente']] : []]];
    if ($c === 'POST /customers') return [200, ['id' => 'cus_novo']];
    if ($c === 'POST /payments') { static $seq = 0; $seq++; return [200, ['id' => 'pay_ger' . $seq, 'invoiceUrl' => 'https://sandbox.asaas.com/i/abc' . $seq]]; }
    if (strpos($c, 'DELETE /payments/') === 0) return [200, ['deleted' => true]];
    return [404, []];
};
$atleta = ['idatleta' => 7, 'nome' => 'Maria Teste', 'email' => 'maria@exemplo.com', 'tel' => '(45) 99115-6006', 'cpf' => '529.982.247-25'];
$pdo->exec("INSERT INTO intus_mensalidade (idmensalidade, idatleta, dshistorico, dtinicio, dtvencimento, vlpagar, vldesconto) VALUES (5, 7, 'Plano Mensal', '2099-01-10', '2099-02-10', 300, 30)");
$mens = $pdo->query('SELECT * FROM intus_mensalidade WHERE idmensalidade = 5')->fetch();

// ── Gerar cobrança ──
$r = asaas_criar_cobranca($pdo, $mens, $atleta, 1);
confere('cobrança nova devolve o link do Asaas', [true, 270.0, '2099-01-10'], [$r['nova'], $r['valor'], $r['vencimento']]);
confere('link vem do Asaas', true, strpos($r['url'], 'https://sandbox.asaas.com/i/') === 0);
confere('chamadas: procura o cliente, cria o cliente e cria a cobrança', ['GET /customers?cpfCnpj=52998224725', 'POST /customers', 'POST /payments'], array_column($chamadas, 'c'));
confere('usa o ambiente de testes', true, strpos($chamadas[0]['url'], 'api-sandbox.asaas.com') !== false);
$cli = $chamadas[1]['corpo'];
confere('cliente novo: notificações do Asaas desligadas, referência e telefone sem o 55', [true, 'intus:atleta:7', '45991156006', '52998224725'], [$cli['notificationDisabled'], $cli['externalReference'], $cli['mobilePhone'], $cli['cpfCnpj']]);
$pg = $chamadas[2]['corpo'];
confere('cobrança: tipo livre (cliente escolhe Pix, boleto ou cartão), valor, vencimento e referência da mensalidade', ['UNDEFINED', 270.0, '2099-01-10', 'intus:mens:5', 'cus_novo'], [$pg['billingType'], $pg['value'], $pg['dueDate'], $pg['externalReference'], $pg['customer']]);
confere('gravada como criada', ['criada', 5, 7], array_values($pdo->query("SELECT status, idmensalidade, idatleta FROM intus_asaas_cobranca")->fetch()));

$chamadas = [];
$r2 = asaas_criar_cobranca($pdo, $mens, $atleta, 1);
confere('pedir de novo com o mesmo valor devolve o mesmo link, sem falar com o Asaas', [false, $r['url'], []], [$r2['nova'], $r2['url'], $chamadas]);

$chamadas = []; $clienteExiste = true;
$mens2 = $mens; $mens2['vlpagar'] = 350;
$r3 = asaas_criar_cobranca($pdo, $mens2, $atleta, 1);
confere('valor mudou: cancela a cobrança antiga e cria outra com o cliente que já existe', ['DELETE', 'GET', 'POST /payments'], [substr($chamadas[0]['c'], 0, 6), substr($chamadas[1]['c'], 0, 3), $chamadas[2]['c']]);
confere('cliente existente não é criado de novo', 0, count(array_filter($chamadas, function ($x) { return $x['c'] === 'POST /customers'; })));
confere('uma só aberta (a nova)', [1, 1], [(int)$pdo->query("SELECT COUNT(*) FROM intus_asaas_cobranca WHERE status = 'criada'")->fetchColumn(), (int)$pdo->query("SELECT COUNT(*) FROM intus_asaas_cobranca WHERE status = 'cancelada'")->fetchColumn()]);
confere('valor novo = 320', 320.0, $r3['valor']);

// erros com texto para a equipe
$paga = $mens; $paga['stpgto'] = 'S';
[$o, $e] = dispara(function () use ($pdo, $paga, $atleta) { return asaas_criar_cobranca($pdo, $paga, $atleta, 1); });
confere('mensalidade paga não gera cobrança (409)', [false, 409], [$o, $e->getCode()]);
$baixa = $mens; $baixa['vlpagar'] = 3; $baixa['vldesconto'] = 0;
[$o, $e] = dispara(function () use ($pdo, $baixa, $atleta) { return asaas_criar_cobranca($pdo, $baixa, $atleta, 1); });
confere('valor abaixo de R$ 5 é recusado (422)', [false, 422], [$o, $e->getCode()]);
$semCpf = $atleta; $semCpf['cpf'] = '123';
[$o, $e] = dispara(function () use ($pdo, $mens, $semCpf) { return asaas_criar_cobranca($pdo, $mens, $semCpf, 1); });
confere('CPF inválido: pede para corrigir o cadastro (422)', [false, 422, true], [$o, $e->getCode(), strpos($e->getMessage(), 'CPF') !== false]);
$pdo->exec("UPDATE intus_asaas_cobranca SET status = 'cancelada'");
$falha = 'recusa'; $chamadas = [];
[$o, $e] = dispara(function () use ($pdo, $mens, $atleta) { return asaas_criar_cobranca($pdo, $mens, $atleta, 1); });
confere('erro do Asaas chega com o motivo dele', [false, true], [$o, strpos($e->getMessage(), 'valor mínimo') !== false]);
$falha = 'rede';
[$o, $e] = dispara(function () use ($pdo, $mens, $atleta) { return asaas_criar_cobranca($pdo, $mens, $atleta, 1); });
confere('Asaas fora do ar sobe como falha de rede (nada é gravado)', [false, 'asaas_fora_do_ar'], [$o, $e->getMessage()]);
$falha = '';
confere('nenhuma cobrança "criada" sobrou das falhas', 0, (int)$pdo->query("SELECT COUNT(*) FROM intus_asaas_cobranca WHERE status = 'criada'")->fetchColumn());

// ── Webhook ──
$pdo = banco();
$pdo->exec("INSERT INTO intus_mensalidade (idmensalidade, idatleta, dshistorico, dtinicio, dtvencimento, vlpagar) VALUES (5, 7, 'Plano Mensal', '2099-01-10', '2099-02-10', 300), (6, 8, 'Plano Mensal', '2099-01-10', '2099-02-10', 300)");
$ev = function ($nome, $id = 'pay_1', $extra = []) { return ['id' => 'evt_1', 'event' => $nome, 'payment' => array_merge(['id' => $id, 'customer' => 'cus_1', 'billingType' => 'PIX', 'value' => 270.0, 'confirmedDate' => '2026-11-06', 'externalReference' => 'intus:mens:5'], $extra)]; };
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED'), 'qualquer');
confere('sem token configurado: 503', 503, $s);
$tok = 'tok-' . str_repeat('b', 44);
asaas_cfg_gravar($pdo, ['apikey' => 'x', 'token' => $tok, 'ambiente' => 'sandbox']);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED'), '');
confere('sem o cabeçalho: 401', 401, $s);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED'), 'tok-errado');
confere('token errado: 401', 401, $s);
confere('nada foi gravado pelos avisos recusados', 0, (int)$pdo->query('SELECT COUNT(*) FROM intus_asaas_cobranca')->fetchColumn());
$agora = '2026-11-06 10:00:00';
$pdo->prepare("INSERT INTO intus_asaas_cobranca (idmensalidade, idatleta, asaas_payment_id, valor, vencimento, url, status, criado_em, atualizado_em) VALUES (5, 7, 'pay_1', 270, '2099-01-10', 'https://x/i/1', 'criada', ?, ?)")->execute([$agora, $agora]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_CREATED'), $tok);
confere('evento que não usamos é ignorado', [200, 'ignorado'], [$s, $d['resultado']]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_CONFIRMED'), $tok);
$l = $pdo->query('SELECT * FROM intus_asaas_cobranca WHERE asaas_payment_id = \'pay_1\'')->fetch();
confere('pagamento confirmado vira "recebida" com forma, valor e data', [200, 'recebida', 'recebida', 'pix', 270.0, '2026-11-06'], [$s, $d['resultado'], $l['status'], $l['forma'], (float)$l['valor_recebido'], $l['recebido_em']]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED'), $tok);
confere('o mesmo pagamento "recebido" depois não duplica nada', [200, 1], [$s, (int)$pdo->query('SELECT COUNT(*) FROM intus_asaas_cobranca')->fetchColumn()]);
confere('o webhook NUNCA mexe na mensalidade', 'N', $pdo->query('SELECT stpgto FROM intus_mensalidade WHERE idmensalidade = 5')->fetchColumn());
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED', 'pay_X', ['externalReference' => 'cdd:qualquer', 'billingType' => 'CREDIT_CARD']), $tok);
confere('cobrança de outra origem (CDD, na mesma conta) é ignorada', [200, 'outra_origem', 1], [$s, $d['resultado'], (int)$pdo->query('SELECT COUNT(*) FROM intus_asaas_cobranca')->fetchColumn()]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED', 'pay_ext', ['externalReference' => 'intus:mens:6', 'billingType' => 'CREDIT_CARD', 'value' => 300.0]), $tok);
$l = $pdo->query("SELECT * FROM intus_asaas_cobranca WHERE asaas_payment_id = 'pay_ext'")->fetch();
confere('cobrança criada direto no Asaas, marcada para uma mensalidade, também é registrada', ['recebida', 6, 8, 'cartao'], [$l['status'], (int)$l['idmensalidade'], (int)$l['idatleta'], $l['forma']]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED', 'pay_fantasma', ['externalReference' => 'intus:mens:999']), $tok);
confere('referência de mensalidade que não existe é ignorada', 'mensalidade_inexistente', $d['resultado']);

// painel
$p = asaas_para_painel($pdo, null);
confere('painel: 2 recebidas esperando baixa', 2, count($p['recebidas']));
confere('painel (professor sem alunos) não vê nada', 0, count(asaas_para_painel($pdo, [])['recebidas']));
$so7 = asaas_para_painel($pdo, [7]);
confere('painel (professor do aluno 7) vê só os dele', [1, 7], [count($so7['recebidas']), (int)$so7['recebidas'][0]['idatleta']]);

// baixa e depois
$chamadas = [];
$pdo->prepare("INSERT INTO intus_asaas_cobranca (idmensalidade, idatleta, asaas_payment_id, valor, status, criado_em, atualizado_em) VALUES (5, 7, 'pay_aberta', 270, 'criada', ?, ?)")->execute([$agora, $agora]);
confere('antes da baixa o link aberto aparece para a mensalidade', true, isset(asaas_para_painel($pdo, null)['abertas']->{'5'}));
$mudou = asaas_marcar_baixado($pdo, 5);
confere('dar a baixa tira a pendência', [1, 'baixada'], [$mudou, $pdo->query("SELECT status FROM intus_asaas_cobranca WHERE asaas_payment_id = 'pay_1'")->fetchColumn()]);
confere('e cancela no Asaas o link que ainda estava aberto', [true, 'cancelada'], [in_array('DELETE /payments/pay_aberta', array_column($chamadas, 'c'), true), $pdo->query("SELECT status FROM intus_asaas_cobranca WHERE asaas_payment_id = 'pay_aberta'")->fetchColumn()]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_RECEIVED'), $tok);
confere('aviso repetido depois da baixa não volta a ser pendência', ['repetido', 'baixada'], [$d['resultado'], $pdo->query("SELECT status FROM intus_asaas_cobranca WHERE asaas_payment_id = 'pay_1'")->fetchColumn()]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_REFUNDED'), $tok);
confere('estorno de pagamento já baixado aparece como estorno', ['estornada', 1], [$pdo->query("SELECT status FROM intus_asaas_cobranca WHERE asaas_payment_id = 'pay_1'")->fetchColumn(), count(asaas_para_painel($pdo, null)['estornos'])]);
$pdo->prepare("INSERT INTO intus_asaas_cobranca (idmensalidade, idatleta, asaas_payment_id, valor, status, criado_em, atualizado_em) VALUES (6, 8, 'pay_del', 100, 'criada', ?, ?)")->execute([$agora, $agora]);
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_DELETED', 'pay_del', ['externalReference' => 'intus:mens:6']), $tok);
confere('cobrança excluída no Asaas vira cancelada', 'cancelada', $pdo->query("SELECT status FROM intus_asaas_cobranca WHERE asaas_payment_id = 'pay_del'")->fetchColumn());
[$s, $d] = asaas_webhook($pdo, $ev('PAYMENT_DELETED', 'pay_ext', ['externalReference' => 'intus:mens:6']), $tok);
confere('exclusão posterior não apaga um pagamento recebido', 'recebida', $pdo->query("SELECT status FROM intus_asaas_cobranca WHERE asaas_payment_id = 'pay_ext'")->fetchColumn());

// ── Endpoint: nada devolve segredo e o webhook usa o cabeçalho certo ──
$fonte = file_get_contents(__DIR__ . '/../app/api/asaas.php');
confere('o endpoint lê o token do cabeçalho asaas-access-token', true, strpos($fonte, "HTTP_ASAAS_ACCESS_TOKEN") !== false);
confere('nenhuma rota devolve a chave de API nem o token (só compara com vazio)', 0, preg_match('/=>\s*\$(c|cfg)\[.(apikey|token).\]\s*[,\]\)]/', $fonte));
confere('cobrar só para administrador ou quem edita matrículas', true, strpos($fonte, "usuarioTemPermissao(\$pdo, \$idu, 'mt_editar')") !== false);
confere('professor só cobra os próprios alunos', true, strpos($fonte, 'Este cliente não é seu.') !== false);

echo $falhas ? "\nFALHOU (" . count($falhas) . "):\n  " . implode("\n  ", $falhas) . "\n" : "\nTudo certo: $ok conferências.\n";
exit($falhas ? 1 : 0);
