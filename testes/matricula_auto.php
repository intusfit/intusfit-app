<?php
/**
 * Matrícula automática do checkout (app/api/_matricula_auto.php): paridade com API.gerarParcelas, aluno novo e aluno que já existe, Pix, cartão parcelado e falhas.
 * Rodar:  php testes/matricula_auto.php   (o arquivo matricula_auto_paridade.json vem de `node testes/matricula_auto.js gravar`)
 */
require_once __DIR__ . '/../app/api/_matricula_auto.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, senha TEXT, telefone TEXT, cpf TEXT, origem TEXT, dt_cadastro TEXT, ranking_optin TEXT, stativo TEXT, codacesso TEXT)");
    $pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, dshistorico TEXT, dtinicio TEXT, dtvencimento TEXT NOT NULL, vlpagar REAL DEFAULT 0, vldesconto REAL DEFAULT 0, vljuro REAL DEFAULT 0, stpgto TEXT DEFAULT 'N', dtpagamento TEXT, vlpagamento REAL, dspagamento TEXT, professor TEXT, tipo_plano TEXT, stmatricula TEXT DEFAULT 'ativa', idmatricula_origem INTEGER, parcela_num INTEGER, parcela_total INTEGER, forma_pgto TEXT, recorrencia TEXT)");
    return $pdo;
}

// ── Paridade com o api.js ──
$fx = json_decode(file_get_contents(__DIR__ . '/matricula_auto_paridade.json'), true);
foreach ($fx as $c) {
    $php = ima_gerar_parcelas($c['inicio'], $c['meses'], $c['parcelas'], (float)$c['valor']);
    confere('mesma série do api.js: ' . $c['inicio'] . ' ' . $c['meses'] . 'm em ' . $c['parcelas'] . 'x R$ ' . $c['valor'], json_encode($c['linhas']), json_encode($php));
}
confere('31/01 + 1 mês = 28/02 (mês curto)', '2027-02-28', ima_somar_meses('2027-01-31', 1));
confere('31/08 + 6 meses = 28/02', '2028-02-29', ima_somar_meses('2027-08-31', 6));
confere('soma de 12 meses', '2027-11-13', ima_somar_meses('2026-11-13', 12));

$ped = function ($extra = []) { return array_merge(['produto' => 'treino', 'periodo' => 'tri', 'meses' => 3, 'nome' => 'Ana Lima', 'email' => 'Ana.Lima@Exemplo.com', 'telefone' => '5545999990002', 'cpf' => '52998224725', 'forma' => 'pix', 'parcelas' => 1, 'valor' => 897.0], $extra); };

// ── Aluno novo, Pix ──
$pdo = banco();
$r = ima_matricular($pdo, $ped());
$a = $pdo->query("SELECT * FROM atleta")->fetch(); $m = $pdo->query("SELECT * FROM intus_mensalidade ORDER BY idmensalidade")->fetchAll();
confere('aluno novo: criado com e-mail em minúsculas e origem professor', [true, true, 'ana.lima@exemplo.com', 'professor', 'S'], [$r['ok'], $r['novo_aluno'], $a['email'], $a['origem'], $a['ranking_optin']]);
confere('Pix trimestral: ciclo pago e o seguinte em aberto, ambos trimestrais', [2, 'S', 'N', 'trimestral', 'trimestral', 'Treino'], [count($m), $m[0]['stpgto'], $m[1]['stpgto'], $m[0]['recorrencia'], $m[1]['recorrencia'], $m[0]['tipo_plano']]);
confere('datas: começa hoje, vence em 3 meses, o seguinte vence 3 meses depois', [ima_hoje(), ima_somar_meses(ima_hoje(), 3), ima_somar_meses(ima_hoje(), 6)], [$m[0]['dtinicio'], $m[0]['dtvencimento'], $m[1]['dtvencimento']]);
confere('o ciclo seguinte aponta para o primeiro e não tem pagamento', [(int)$m[0]['idmensalidade'], null, null], [(int)$m[1]['idmatricula_origem'], $m[1]['dtpagamento'], $m[1]['vlpagamento']]);
confere('o aviso fala do ciclo seguinte', true, (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'Ciclo seguinte') !== false; }));

// ── Aluno com plano de Treino paga Dieta: matrícula NOVA em paralelo, começando hoje, sem mexer na antiga ──
$r2 = ima_matricular($pdo, $ped(['produto' => 'dieta', 'periodo' => 'sem', 'meses' => 6, 'valor' => 1689.0]));
$m2 = $pdo->query("SELECT * FROM intus_mensalidade WHERE idmensalidade > " . (int)$m[1]['idmensalidade'] . " ORDER BY idmensalidade")->fetchAll();
confere('aluno existente: não duplica o cadastro', [false, 1], [$r2['novo_aluno'], (int)$pdo->query("SELECT COUNT(*) FROM atleta")->fetchColumn()]);
confere('plano de outro tipo: matrícula nova começando HOJE (não no fim do plano de Treino)', [ima_hoje(), 'Nutrição', 2, false], [$m2[0]['dtinicio'], $m2[0]['tipo_plano'], count($m2), $r2['renovacao']]);
confere('o aviso diz que é plano de outro tipo', true, (bool)array_filter($r2['avisos'], function ($t) { return strpos($t, 'OUTRO tipo') !== false; }));
$dep = $pdo->query("SELECT stpgto, dtvencimento FROM intus_mensalidade WHERE idmensalidade IN (" . (int)$m[0]['idmensalidade'] . ", " . (int)$m[1]['idmensalidade'] . ") ORDER BY idmensalidade")->fetchAll();
confere('nada do que existia foi alterado', [['S', $m[0]['dtvencimento']], ['N', $m[1]['dtvencimento']]], [[$dep[0]['stpgto'], $dep[0]['dtvencimento']], [$dep[1]['stpgto'], $dep[1]['dtvencimento']]]);

// ── Renovação: mesmo plano, datas batendo com o ciclo em aberto ──
$hoje = ima_hoje();
$monta = function (PDO $pdo, string $tipo, string $rec, string $inicioAberto, float $vl = 897.0): int {
    $pdo->exec("INSERT INTO atleta (nome, email, senha, cpf, origem) VALUES ('Renova Alguem', 'ana.lima@exemplo.com', 'x', '529.982.247-25', 'professor')");
    $id = (int)$pdo->lastInsertId();
    $meses = ['trimestral' => 3, 'semestral' => 6, 'anual' => 12][$rec];
    $pdo->exec("INSERT INTO intus_mensalidade (idatleta, dshistorico, dtinicio, dtvencimento, vlpagar, stpgto, dtpagamento, vlpagamento, tipo_plano, stmatricula, recorrencia, forma_pgto) VALUES ($id, 'Trimestral · $tipo', '" . ima_somar_meses($inicioAberto, -$meses) . "', '$inicioAberto', $vl, 'S', '" . ima_somar_meses($inicioAberto, -$meses) . "', $vl, '$tipo', 'ativa', '$rec', 'asaas_pix')");
    $raiz = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO intus_mensalidade (idatleta, dshistorico, dtinicio, dtvencimento, vlpagar, stpgto, tipo_plano, stmatricula, recorrencia, idmatricula_origem) VALUES ($id, 'Trimestral · $tipo', '$inicioAberto', '" . ima_somar_meses($inicioAberto, $meses) . "', $vl, 'N', '$tipo', 'ativa', '$rec', $raiz)");
    return $id;
};
$pdo = banco(); $ini = date('Y-m-d', strtotime($hoje . ' +5 days'));
$id = $monta($pdo, 'Treino', 'trimestral', $ini);
$r = ima_matricular($pdo, $ped());
$ms = $pdo->query("SELECT * FROM intus_mensalidade WHERE idatleta = $id ORDER BY idmensalidade")->fetchAll();
confere('renovação: o ciclo em aberto recebe a baixa em vez de criar matrícula nova', [true, 3, 'S', $hoje, 897.0, 'asaas_pix'], [$r['renovacao'], count($ms), $ms[1]['stpgto'], $ms[1]['dtpagamento'], (float)$ms[1]['vlpagamento'], $ms[1]['forma_pgto']]);
confere('renovação: o próximo ciclo nasce em aberto, logo depois do renovado, ligado à matrícula de origem', [$ms[1]['dtvencimento'], ima_somar_meses($ms[1]['dtvencimento'], 3), 'N', (int)$ms[0]['idmensalidade']], [$ms[2]['dtinicio'], $ms[2]['dtvencimento'], $ms[2]['stpgto'], (int)$ms[2]['idmatricula_origem']]);
confere('renovação: o aviso diz RENOVAÇÃO e o aluno segue o mesmo cadastro', [true, false], [(bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'RENOVAÇÃO') !== false; }), $r['novo_aluno']]);
$r = ima_matricular($pdo, $ped());
confere('uma segunda compra logo depois não renova de novo o mesmo ciclo (já foi pago): vira matrícula nova com aviso', [false, true], [$r['renovacao'], (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'não bate') !== false; })]);
// valor diferente do combinado
$pdo = banco(); $id = $monta($pdo, 'Treino', 'trimestral', $ini, 800.0);
$r = ima_matricular($pdo, $ped());
confere('renovação com valor diferente do combinado avisa para conferir o preço', true, (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'diferente do combinado') !== false; }));
// mesmo tipo, outra duração
$pdo = banco(); $id = $monta($pdo, 'Treino', 'trimestral', $ini);
$r = ima_matricular($pdo, $ped(['periodo' => 'anual', 'meses' => 12, 'valor' => 2987.0]));
confere('mesmo tipo mas outra duração: não é renovação, cria matrícula nova a partir do fim do pago e avisa', [false, true], [$r['renovacao'], (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'não bate') !== false; })]);
confere('a matrícula nova do mesmo tipo começa no fim do que já estava pago', $ini, $r['inicio']);
// mesmo plano mas o ciclo em aberto começa muito longe
$pdo = banco(); $id = $monta($pdo, 'Treino', 'trimestral', date('Y-m-d', strtotime($hoje . ' +100 days')));
$r = ima_matricular($pdo, $ped());
confere('datas longe do ciclo em aberto (100 dias): não renova sozinho', false, $r['renovacao']);
// tipo diferente (tem Combo, paga Treino): paralelo
$pdo = banco(); $id = $monta($pdo, 'Combo (Treino + Dieta)', 'trimestral', $ini);
$r = ima_matricular($pdo, $ped());
confere('tem Combo e paga Treino: matrícula nova em paralelo, sem baixa no ciclo do Combo', [false, 'N'], [$r['renovacao'], $pdo->query("SELECT stpgto FROM intus_mensalidade WHERE idatleta = $id AND tipo_plano LIKE 'Combo%' ORDER BY idmensalidade DESC LIMIT 1")->fetchColumn()]);
// renovação paga no cartão parcelado: a série paga o período e o ciclo em aberto é cancelado
$pdo = banco(); $id = $monta($pdo, 'Treino', 'trimestral', $ini);
$r = ima_matricular($pdo, $ped(['forma' => 'cartao', 'parcelas' => 3]));
confere('renovação parcelada: série de 3 parcelas e o ciclo em aberto fica cancelado (aviso)', [3, 'cancelada', true], [(int)$pdo->query("SELECT COUNT(*) FROM intus_mensalidade WHERE idatleta = $id AND parcela_total = 3")->fetchColumn(), $pdo->query("SELECT stmatricula FROM intus_mensalidade WHERE idatleta = $id AND stpgto = 'N' ORDER BY idmensalidade LIMIT 1")->fetchColumn(), (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'RENOVAÇÃO PARCELADA') !== false; })]);
// teste grátis antigo não conta como plano ativo
$pdo = banco();
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf, origem) VALUES ('Teste Antigo', 'ana.lima@exemplo.com', 'x', '529.982.247-25', 'app')");
$pdo->exec("INSERT INTO intus_mensalidade (idatleta, dshistorico, dtinicio, dtvencimento, vlpagar, stpgto, dtpagamento, tipo_plano, stmatricula) VALUES (1, 'Teste Grátis (3 dias)', '$hoje', '" . date('Y-m-d', strtotime($hoje . ' +3 days')) . "', 0, 'S', '$hoje', NULL, 'ativa')");
$r = ima_matricular($pdo, $ped());
confere('teste grátis anterior não conta como plano: a compra começa hoje sem avisos de plano', [$hoje, false], [$r['inicio'], (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'OUTRO tipo') !== false || strpos($t, 'não bate') !== false; })]);

// ── Cartão parcelado ──
$pdo = banco();
$r = ima_matricular($pdo, $ped(['produto' => 'treino-dieta', 'periodo' => 'anual', 'meses' => 12, 'forma' => 'cartao', 'parcelas' => 12, 'valor' => 4597.0, 'email' => 'bia@exemplo.com']));
$m = $pdo->query("SELECT * FROM intus_mensalidade ORDER BY idmensalidade")->fetchAll();
confere('12x: 12 linhas, todas pagas na data da compra, sem recorrência', [true, 12, ['S'], [ima_hoje()], [null]], [$r['ok'], count($m), array_values(array_unique(array_column($m, 'stpgto'))), array_values(array_unique(array_column($m, 'dtpagamento'))), array_values(array_unique(array_column($m, 'recorrencia')))]);
confere('12x: soma dos valores pagos = valor total e cada linha é uma parcela', [4597.0, 'Combo (Treino + Dieta)', 'asaas_cartao'], [round(array_sum(array_column($m, 'vlpagamento')), 2), $m[0]['tipo_plano'], $m[0]['forma_pgto']]);
confere('12x: a última janela termina no fim do plano', ima_somar_meses(ima_hoje(), 12), $m[11]['dtvencimento']);
confere('12x: o aviso lembra que parcelado renova com ↻', true, (bool)array_filter($r['avisos'], function ($t) { return strpos($t, '↻') !== false; }));
// ── Cartão à vista (1x) = igual ao Pix, com forma de cartão ──
$pdo = banco();
$r = ima_matricular($pdo, $ped(['forma' => 'cartao', 'parcelas' => 1, 'email' => 'c@exemplo.com']));
confere('cartão à vista: um ciclo pago + o seguinte, forma asaas_cartao', ['asaas_cartao', 2], [$pdo->query("SELECT forma_pgto FROM intus_mensalidade ORDER BY idmensalidade LIMIT 1")->fetchColumn(), (int)$pdo->query("SELECT COUNT(*) FROM intus_mensalidade")->fetchColumn()]);
// ── Documento manda: CPF já cadastrado (formatado) com outro e-mail ──
$pdo = banco();
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf, origem) VALUES ('Marcos Antigo', 'marcos@antigo.com', 'x', '529.982.247-25', 'professor')");
$r = ima_matricular($pdo, $ped(['email' => 'marcos@novo.com', 'nome' => 'Marcos A.']));
confere('documento já cadastrado: não cria outro aluno, liga a matrícula ao existente', [true, false, 1, 1], [$r['ok'], $r['novo_aluno'], (int)$pdo->query("SELECT COUNT(*) FROM atleta")->fetchColumn(), (int)$r['idatleta']]);
confere('e-mail diferente do cadastro: devolve o conflito para o aluno escolher (nada é trocado)', [['idatleta' => 1, 'email_atual' => 'marcos@antigo.com', 'email_novo' => 'marcos@novo.com'], 'marcos@antigo.com'], [$r['conflito'], $pdo->query("SELECT email FROM atleta WHERE idatleta = 1")->fetchColumn()]);
confere('o aviso conta que o documento já tinha cadastro', true, (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'JÁ TINHA cadastro') !== false; }));
$r = ima_matricular($pdo, $ped(['email' => 'MARCOS@antigo.com']));
confere('documento e e-mail do mesmo cadastro: sem conflito', null, $r['conflito']);
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf) VALUES ('Outra Pessoa', 'outra@exemplo.com', 'x', '111')");
$r = ima_matricular($pdo, $ped(['email' => 'outra@exemplo.com']));
confere('e-mail de outro cadastro: sem conflito automático e aviso de unificar', [null, true, 1], [$r['conflito'], (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'OUTRO cadastro') !== false; }), (int)$r['idatleta']]);
$pdo = banco();
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf) VALUES ('Sem Doc', 'ana.lima@exemplo.com', 'x', '')");
$r = ima_matricular($pdo, $ped());
confere('cadastro do e-mail sem documento: o documento da compra é preenchido', '52998224725', ima_doc_norm($pdo->query("SELECT cpf FROM atleta WHERE idatleta = 1")->fetchColumn()));
$pdo = banco();
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf) VALUES ('Outro Doc', 'ana.lima@exemplo.com', 'x', '12345678909')");
$r = ima_matricular($pdo, $ped());
confere('cadastro do e-mail com OUTRO documento: nada é alterado e a equipe é avisada', ['12345678909', true], [$pdo->query("SELECT cpf FROM atleta WHERE idatleta = 1")->fetchColumn(), (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'OUTRO documento') !== false; })]);
$pdo = banco();
$pdo->exec("INSERT INTO atleta (nome, email, senha, cpf) VALUES ('John Doe', 'john@old.com', 'x', 'ab 123456')");
$r = ima_matricular($pdo, $ped(['cpf' => 'AB-123456', 'email' => 'john@new.com']));
confere('documento estrangeiro é comparado sem máscara e sem diferença de maiúsculas', [1, 'john@old.com'], [(int)$r['idatleta'], $r['conflito']['email_atual'] ?? null]);
confere('doc_norm', ['AB123456', '52998224725'], [ima_doc_norm('ab 123-456'), ima_doc_norm('529.982.247-25')]);

// ── Falhas: nunca lança e não deixa matrícula pela metade sem avisar ──
$vazio = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = ima_matricular($vazio, $ped());
confere('sem tabela de alunos: devolve erro em vez de lançar', false, $r['ok']);
$pdo = banco(); $pdo->exec("DROP TABLE intus_mensalidade");
$r = ima_matricular($pdo, $ped(['email' => 'f@exemplo.com']));
confere('falha ao gravar a matrícula: erro claro (a equipe é avisada pelo webhook)', false, $r['ok']);

echo $ok . " ok\n";
if ($falhas) { echo count($falhas) . " FALHA(S):\n  " . implode("\n  ", $falhas) . "\n"; exit(1); }
