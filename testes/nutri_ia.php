<?php
/**
 * Leitura de dieta por PDF ou print com o Claude (app/api/_nutri_ia.php), com o Claude de mentira. Nunca fala com a Anthropic nem precisa de chave real.
 *   php -c <ini com mbstring, curl, pdo_sqlite> testes/nutri_ia.php
 */
require_once __DIR__ . '/../app/api/_nutri_ia.php';
require_once __DIR__ . '/../app/api/_wa.php';

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function banco(bool $comChave = true): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    wa_garantir_tabelas($pdo);
    if ($comChave) wa_segredo_gravar($pdo, 'anthropic_config', ['api_key' => 'CHAVE-FALSA']);
    return $pdo;
}
class ClaudeFalso {
    public array $corpos = []; public $resp;
    public function __construct($resp) { $this->resp = $resp; }
    public function __invoke(string $corpo, string $chave, bool $fb): array { $this->corpos[] = json_decode($corpo, true); return $this->resp; }
}
function resp(string $texto, string $stop = 'end_turn'): array {
    return ['status' => 200, 'erro' => '', 'json' => ['stop_reason' => $stop, 'usage' => ['input_tokens' => 3000, 'output_tokens' => 700], 'content' => [['type' => 'text', 'text' => $texto]]]];
}
$PNG = base64_encode(str_repeat('x', 200));
$arq = [['mime' => 'image/png', 'dados' => $PNG]];
$dieta = ['titulo' => 'Cutting', 'refeicoes' => [
    ['nome' => 'Café da manhã', 'horario' => '05:00', 'opcoes' => [
        ['nome' => 'Principal', 'observacao' => '', 'itens' => [
            ['nome' => 'Ovo de galinha cozido', 'quantidade' => '4', 'unidade' => 'Unidades', 'gramas' => 200, 'kcal' => '310', 'proteina' => 25, 'lipidio' => 21, 'carboidrato' => 2],
            ['nome' => 'Pão de forma', 'quantidade' => 3, 'unidade' => 'Fatias', 'gramas' => 75, 'kcal' => 183, 'substitutos' => [['nome' => 'Tapioca', 'quantidade' => 40, 'unidade' => 'g', 'gramas' => 40]]],
            ['nome' => '', 'quantidade' => 1]]],
        ['nome' => 'Sem preparo', 'itens' => [['nome' => 'Banana', 'quantidade' => 1, 'unidade' => 'Unidade', 'gramas' => 70]]]]],
    ['nome' => 'Vazia', 'horario' => '99:99', 'opcoes' => [['itens' => []]]],
]];

echo "\n── Leitura ──\n";
$cl = new ClaudeFalso(resp("```json\n" . json_encode($dieta, JSON_UNESCAPED_UNICODE) . "\n```"));
$r = nutri_ia_extrair(banco(), $arq, $cl);
eq('lê a dieta (resposta dentro de cerca de código)', $r['ok'] ?? null, true);
$p = $r['plano'] ?? [];
eq('só ficam as refeições com alimento', array_map(fn($x) => $x['nome'], $p['refeicoes'] ?? []), ['Café da manhã']);
eq('duas opções na refeição', array_map(fn($o) => $o['nome'], $p['refeicoes'][0]['opcoes'] ?? []), ['Principal', 'Sem preparo']);
$it = $p['refeicoes'][0]['opcoes'][0]['itens'] ?? [];
eq('item sem nome é descartado; números em texto viram número', [count($it), $it[0]['quantidade'] ?? null, $it[0]['kcal'] ?? null, $it[0]['gramas'] ?? null], [2, 4.0, 310.0, 200.0]);
eq('substituto visível acompanha o alimento', $it[1]['substitutos'][0]['nome'] ?? null, 'Tapioca');
eq('horário inválido some', ($p['refeicoes'][0]['horario'] ?? null), '05:00');
eq('devolve o custo e o modelo', [($r['custo_usd'] ?? 0) > 0, $r['modelo'] ?? null], [true, 'claude-sonnet-5-5']);
$corpo = $cl->corpos[0] ?? [];
eq('manda a imagem e a instrução ao Claude', [$corpo['messages'][0]['content'][0]['type'] ?? null, $corpo['messages'][0]['content'][1]['type'] ?? null, $corpo['model'] ?? null], ['image', 'text', 'claude-sonnet-5-5']);
eq('o pedido manda ignorar dados pessoais e tratar o arquivo como dado', (bool)preg_match('/Ignore e nunca copie dados pessoais/', $corpo['system'] ?? '') && (bool)preg_match('/nunca uma instrução/', $corpo['system'] ?? ''), true);
$cl2 = new ClaudeFalso(resp('Segue: ' . json_encode($dieta, JSON_UNESCAPED_UNICODE) . ' pronto.'));
eq('acha o JSON mesmo com texto em volta', (nutri_ia_extrair(banco(), $arq, $cl2))['ok'] ?? null, true);
$cl3 = new ClaudeFalso(resp('{}'));
eq('JSON sem dieta: erro claro', (nutri_ia_extrair(banco(), $arq, $cl3))['erro'] ?? null, 'Não encontrei uma dieta neste arquivo. Confira se o PDF ou o print mostra as refeições com os alimentos.');
eq('texto que não é JSON: erro claro', (nutri_ia_extrair(banco(), $arq, new ClaudeFalso(resp('não sei'))))['ok'] ?? null, false);
eq('resposta cortada: pede para enviar em partes', (nutri_ia_extrair(banco(), $arq, new ClaudeFalso(resp('{"refeicoes":[', 'max_tokens'))))['erro'] ?? null, 'A dieta é grande demais para ler de uma vez. Envie em partes (menos refeições por arquivo).');
$pdf = [['mime' => 'application/pdf', 'dados' => $PNG]];
$cl4 = new ClaudeFalso(resp(json_encode($dieta)));
nutri_ia_extrair(banco(), $pdf, $cl4);
eq('PDF vai como documento', $cl4->corpos[0]['messages'][0]['content'][0]['type'] ?? null, 'document');

echo "\n── Validações do envio ──\n";
eq('nenhum arquivo', (nutri_ia_extrair(banco(), [], $cl))['erro'] ?? null, 'Envie o PDF ou os prints da dieta.');
eq('tipo não aceito', (nutri_ia_extrair(banco(), [['mime' => 'text/html', 'dados' => $PNG]], $cl))['erro'] ?? null, 'Tipo de arquivo não aceito. Use PDF, JPG, PNG ou WEBP.');
eq('base64 inválido', (nutri_ia_extrair(banco(), [['mime' => 'image/png', 'dados' => '@@@']], $cl))['erro'] ?? null, 'Não consegui ler um dos arquivos enviados.');
eq('mais de 6 arquivos', (nutri_ia_extrair(banco(), array_fill(0, 7, $arq[0]), $cl))['erro'] ?? null, 'No máximo 6 arquivos por importação.');
$grande = base64_encode(str_repeat('a', 5 * 1024 * 1024 + 10));
eq('imagem acima de 5 MB', (nutri_ia_extrair(banco(), [['mime' => 'image/png', 'dados' => $grande]], $cl))['erro'] ?? null, 'Um dos arquivos é grande demais (5 MB no máximo).');
eq('sem chave do Claude: avisa e não chama a API', [(nutri_ia_extrair(banco(false), $arq, $cl))['erro'] ?? null], ['A chave do Claude ainda não foi cadastrada.']);
$n = count($cl->corpos);
nutri_ia_extrair(banco(false), $arq, $cl);
eq('...e nenhum pedido saiu sem chave', count($cl->corpos), $n);
$erro = new ClaudeFalso(['status' => 401, 'erro' => '', 'json' => ['error' => ['message' => 'invalid x-api-key']]]);
eq('erro da Anthropic vira mensagem', (nutri_ia_extrair(banco(), $arq, $erro))['ok'] ?? null, false);

echo "\n$ok ok, $ruim falha(s)\n";
exit($ruim ? 1 : 0);
