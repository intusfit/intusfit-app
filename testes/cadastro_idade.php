<?php
/**
 * Idade minima e aceite dos Termos no autocadastro (app/api/_cadastro_aluno.php e aluno_login.php?action=criar).
 *   php -c <ini> testes/cadastro_idade.php        (INTUS_API aponta para uma copia de app/api; usado nas quebras de proposito)
 */
$API = getenv('INTUS_API') ?: dirname(__DIR__) . '/app/api';
$RAIZ = dirname($API === dirname(__DIR__) . '/app/api' ? $API : dirname(__DIR__) . '/app/api');
require_once $API . '/_cadastro_aluno.php';

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; return; }
    $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado) . "\n          obtido   " . json_encode($obtido) . "\n";
}
function ver($rot, $c) { eq($rot, (bool)$c, true); }

echo "\n== Idade em anos completos ==\n";
$H = '2026-11-12';
eq('aniversario de 18 anos e hoje: tem 18', intusIdadeDe('2008-11-12', $H), 18);
eq('falta 1 dia para os 18: tem 17', intusIdadeDe('2008-11-13', $H), 17);
eq('fez 18 ontem: tem 18', intusIdadeDe('2008-11-11', $H), 18);
eq('mes ainda nao chegou: tem 17', intusIdadeDe('2008-12-01', $H), 17);
eq('mes ja passou: tem 18', intusIdadeDe('2008-01-31', $H), 18);
eq('adulto comum', intusIdadeDe('1990-05-20', $H), 36);
eq('nascida em 29/02 (ano bissexto) em 28/02: ainda nao fez', intusIdadeDe('2008-02-29', '2026-02-28'), 17);
eq('nascida em 29/02 em 01/03: ja fez 18', intusIdadeDe('2008-02-29', '2026-03-01'), 18);
eq('hoje mesmo: 0 anos', intusIdadeDe($H, $H), 0);
eq('data futura nao vale', intusIdadeDe('2026-11-13', $H), null);
eq('29/02 de ano nao bissexto nao existe', intusIdadeDe('2009-02-29', $H), null);
eq('mes 13 nao existe', intusIdadeDe('2000-13-01', $H), null);
eq('antes de 1900 nao vale', intusIdadeDe('1899-12-31', $H), null);
eq('formato dd/mm/aaaa nao vale (o servidor so aceita AAAA-MM-DD)', intusIdadeDe('12/11/1990', $H), null);
eq('texto qualquer nao vale', intusIdadeDe('abc', $H), null);
eq('vazio nao vale', intusIdadeDe('', $H), null);
eq('espacos em volta sao ignorados', intusIdadeDe(' 1990-05-20 ', $H), 36);

echo "\n== Regra de cadastro ==\n";
$bom = ['dtnascimento' => '1990-05-20', 'aceite_termos' => true];
eq('adulto que aceitou passa', intusValidarIdadeEAceite($bom, $H), null);
eq('exatamente 18 passa', intusValidarIdadeEAceite(['dtnascimento' => '2008-11-12', 'aceite_termos' => true], $H), null);
$r = intusValidarIdadeEAceite(['dtnascimento' => '2008-11-13', 'aceite_termos' => true], $H);
eq('17 anos e 364 dias: recusa com 403 idade_minima', [$r['http'] ?? 0, $r['codigo'] ?? ''], [403, 'idade_minima']);
$r = intusValidarIdadeEAceite(['dtnascimento' => '2015-01-01', 'aceite_termos' => true], $H);
eq('crianca: 403 idade_minima', [$r['http'] ?? 0, $r['codigo'] ?? ''], [403, 'idade_minima']);
$r = intusValidarIdadeEAceite(['aceite_termos' => true], $H);
eq('sem data de nascimento: 400 nascimento_invalido', [$r['http'] ?? 0, $r['codigo'] ?? ''], [400, 'nascimento_invalido']);
$r = intusValidarIdadeEAceite(['dtnascimento' => '   ', 'aceite_termos' => true], $H);
eq('data so com espacos: 400 nascimento_invalido', [$r['http'] ?? 0, $r['codigo'] ?? ''], [400, 'nascimento_invalido']);
$r = intusValidarIdadeEAceite(['dtnascimento' => '2099-01-01', 'aceite_termos' => true], $H);
eq('data no futuro: 400 nascimento_invalido (nao vira "maior de idade")', [$r['http'] ?? 0, $r['codigo'] ?? ''], [400, 'nascimento_invalido']);
$r = intusValidarIdadeEAceite(['dtnascimento' => '1990-05-20'], $H);
eq('sem aceite: 400 aceite_necessario', [$r['http'] ?? 0, $r['codigo'] ?? ''], [400, 'aceite_necessario']);
foreach ([false, 0, '0', 'false', '', null, 'sim', 2, []] as $v) {
    $r = intusValidarIdadeEAceite(['dtnascimento' => '1990-05-20', 'aceite_termos' => $v], $H);
    eq('aceite ' . json_encode($v) . ' nao vale', $r['codigo'] ?? '', 'aceite_necessario');
}
foreach ([true, 1, '1', 'true'] as $v) eq('aceite ' . json_encode($v) . ' vale', intusValidarIdadeEAceite(['dtnascimento' => '1990-05-20', 'aceite_termos' => $v], $H), null);
$r = intusValidarIdadeEAceite(['dtnascimento' => '2015-01-01'], $H);
eq('menor sem aceite: a idade vem primeiro (nao pede aceite a quem nao pode se cadastrar)', $r['codigo'] ?? '', 'idade_minima');
$r = intusValidarIdadeEAceite(['dtnascimento' => '2015-01-01', 'aceite_termos' => true], $H);
eq('a mensagem diz o minimo e o caminho (professor + responsavel)', [strpos($r['erro'], '18') !== false, strpos($r['erro'], 'responsavel') !== false, strpos($r['erro'], 'professor') !== false], [true, true, true]);
eq('a idade minima e 18', INTUS_IDADE_MINIMA, 18);
ver('versao dos Termos no formato AAAA-MM', preg_match('/^\d{4}-\d{2}$/', INTUS_TERMOS_VERSAO));
ver('hoje (sem data forcada) e uma data de Brasilia valida', preg_match('/^\d{4}-\d{2}-\d{2}$/', intusCadHoje()));

echo "\n== O endpoint usa a regra, antes de gravar nada ==\n";
$fonte = file_get_contents($API . '/aluno_login.php');
ver('aluno_login.php carrega a biblioteca', strpos($fonte, "require_once __DIR__ . '/_cadastro_aluno.php'") !== false);
$posCriar = strpos($fonte, "if (\$action === 'criar')");
$posValida = strpos($fonte, 'intusValidarIdadeEAceite($body)');
$posDup = strpos($fonte, 'Duplicidade', $posCriar);
$posInsert = strpos($fonte, 'INSERT INTO `$tabela`', $posCriar);
ver('a validacao existe dentro do bloco "criar"', $posCriar !== false && $posValida !== false && $posValida > $posCriar);
ver('a validacao vem antes da checagem de e-mail duplicado', $posValida < $posDup);
ver('a validacao vem antes do INSERT do aluno', $posValida < $posInsert);
$trecho = substr($fonte, $posValida, 400);
ver('recusa responde com o codigo HTTP da regra e para (exit)', strpos($trecho, "http_response_code(\$falhaCad['http'])") !== false && strpos($trecho, 'exit;') !== false);
ver('guarda quando e qual versao dos Termos foi aceita', strpos($fonte, "'termos_aceitos_em'") !== false && strpos($fonte, "\$vals[]=INTUS_TERMOS_VERSAO") !== false);
ver('o cadastro feito por professor (atletas.php) nao passa por esta regra', strpos(file_get_contents($API . '/atletas.php'), '_cadastro_aluno') === false);

echo "\n== As telas dizem a mesma idade e enviam o que o servidor exige ==\n";
$raiz = getenv('INTUS_RAIZ') ?: dirname(__DIR__);
foreach (['teste-gratis.html' => ['IDADE_MINIMA = 18', 'name="dtnascimento"', 'aceite_termos: true', 'dtnascimento,'], 'app/painel/login.html' => ['NC_IDADE_MINIMA = 18', 'id="nc-dtnascimento"', 'aceite_termos: true', 'nc-aceite']] as $arq => $pecas) {
    $h = file_get_contents($raiz . '/' . $arq);
    foreach ($pecas as $p) ver("$arq contem: $p", strpos($h, $p) !== false);
    ver("$arq: os Termos e a Privacidade abrem de um link no aceite", strpos($h, 'termos.html') !== false && strpos($h, 'privacidade.html') !== false);
}
$tg = file_get_contents($raiz . '/teste-gratis.html');
$posRecusa = strpos($tg, "res.codigo === 'idade_minima'");
$posFalha = strpos($tg, "throw new Error('falha ao criar conta')");
ver('teste-gratis: recusa por idade/aceite volta ANTES do plano B de lead (contato de menor nao e guardado)', $posRecusa !== false && $posFalha !== false && $posRecusa < $posFalha);

echo "\n== Textos legais dizem o que o sistema faz ==\n";
$priv = file_get_contents($raiz . '/app/painel/privacidade.html');
$termos = file_get_contents($raiz . '/app/painel/termos.html');
$app = file_get_contents($raiz . '/app/painel/aluno.html');
ver('a politica nao promete mais "visiveis apenas a voce e ao professor" (o Feed e o ranking mostram a outros alunos)', strpos($priv, 'visíveis apenas a você') === false);
ver('a politica nao diz mais que nao ha compartilhamento com terceiros', strpos($priv, 'Não há compartilhamento com terceiros') === false);
foreach (['dados pessoais sensíveis' => 'dados de saude', 'ranking' => 'ranking', 'Feed' => 'Feed', 'Transferência internacional' => 'transferencia internacional', 'Google' => 'Google', 'Cloudflare' => 'Cloudflare', 'KingHost' => 'KingHost', 'Asaas' => 'plataforma de pagamento', 'Anthropic' => 'IA', 'OpenStreetMap' => 'OpenStreetMap', 'ANPD' => 'ANPD', 'portabilidade' => 'portabilidade', '45 dias' => 'copias de seguranca', 'registros de acesso' => 'logs de acesso', 'consentimento' => 'bases legais'] as $trecho => $rotulo)
    ver("a politica trata de: $rotulo", strpos($priv, $trecho) !== false);
ver('a politica fala da idade minima de ' . INTUS_IDADE_MINIMA . ' anos e da autorizacao do responsavel', strpos($priv, INTUS_IDADE_MINIMA . ' anos ou mais') !== false && strpos($priv, 'responsável legal') !== false);
ver('a politica e os Termos citam a mesma versao do aceite', strpos($priv, 'versão ' . INTUS_TERMOS_VERSAO) !== false);
ver('a politica traz o canal do titular com e-mail', preg_match('/mailto:contato@/', $priv) === 1);
foreach (['termos.html' => $termos, 'aluno.html (copia dos Termos dentro do app)' => $app] as $rot => $txt) {
    ver("$rot: idade minima e responsavel legal", strpos($txt, 'maiores de ' . INTUS_IDADE_MINIMA . ' anos') !== false && strpos($txt, 'autorização do responsável legal') !== false);
    ver("$rot: nao diz mais que os dados nao sao compartilhados com terceiros", strpos($txt, 'não são compartilhados com terceiros sem seu consentimento expresso') === false);
    ver("$rot: nao diz mais que a plataforma nao processa pagamentos", strpos($txt, 'não processa pagamentos nem intermedia') === false);
}

echo "\n" . ($ruim ? "FALHARAM $ruim de " . ($ok + $ruim) : "Todas as $ok conferencias passaram") . "\n";
exit($ruim ? 1 : 0);
