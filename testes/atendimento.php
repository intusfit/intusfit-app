<?php
/**
 * Testes da Central de atendimento (app/api/_atendimento.php e atendimento.php).
 *
 * Rodar (PHP 8.2 com mbstring, openssl, curl e pdo_sqlite):
 *     php testes/atendimento.php
 * No Windows, sem php.ini, passe um ini com as extensoes: php -c php-teste.ini testes/atendimento.php
 *
 * Banco de teste: SQLite em memoria. Nada de rede: o Claude e as paginas buscadas sao simulados.
 */

require_once __DIR__ . '/../app/api/_atendimento.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function verdade(string $nome, $cond): void { confere($nome, true, (bool)$cond); }
function em(string $s): DateTimeImmutable { return new DateTimeImmutable($s, wa_tz()); }
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    wa_garantir_tabelas($pdo);
    atd_garantir_config($pdo);
    return $pdo;
}
function regras(array $r): array { return array_column($r['itens'], 'nivel', 'regra'); }
function tem(array $r, string $regra, ?string $nivel = null): bool {
    foreach ($r['itens'] as $i) { if ($i['regra'] === $regra && ($nivel === null || $i['nivel'] === $nivel)) return true; }
    return false;
}

// ── Identificadores ─────────────────────────────────────────────────────────
confere('slug: acentos e espacos', 'acao-de-verao', atd_slug('Ação de Verão!'));
confere('slug: limite', 'abcde', atd_slug('abcdefgh', 5));
confere('passo: teste gratis mantem t1', 't1', atd_passo('teste-gratis', 't1'));
confere('passo: outro funil leva prefixo', 'indicacao:i1', atd_passo('indicacao', 'i1'));
confere('passo: cabe em 30 caracteres', 30, strlen(atd_passo(str_repeat('a', 24), str_repeat('b', 12))));
confere('decodificar: antigo', ['teste-gratis', 't3'], atd_passo_decodificar('t3'));
confere('decodificar: novo', ['indicacao', 'i2'], atd_passo_decodificar('indicacao:i2'));

// ── Conteúdo de fábrica ─────────────────────────────────────────────────────
$padrao = atd_funis_padrao();
$ids = array_column($padrao, 'id');
confere('padrao: ids de funil sem repeticao', count($ids), count(array_unique($ids)));
confere('padrao: funis esperados', ['teste-gratis', 'chamou-whatsapp', 'indicacao', 'reativacao'], $ids);
confere('padrao: teste gratis tem 7 mensagens', ['t1', 't2', 't3', 't4', 't5', 't6', 't7'], array_column($padrao[0]['mensagens'], 'id'));
$tpls = [];
foreach ($padrao as $f) {
    $mids = array_column($f['mensagens'], 'id');
    confere("padrao {$f['id']}: ids de mensagem unicos", count($mids), count(array_unique($mids)));
    foreach ($f['mensagens'] as $m) {
        if (!empty($m['template'])) $tpls[] = $m['template'];
        confere("padrao {$f['id']}/{$m['id']}: passo cabe na coluna", true, strlen(atd_passo($f['id'], $m['id'])) <= 30);
        confere("padrao {$f['id']}/{$m['id']}: texto cabe no template", true, mb_strlen($m['texto']) <= ATD_MAX_TEXTO_MENSAGEM);
        confere("padrao {$f['id']}/{$m['id']}: sem erro nas regras de copy", [], array_values(array_filter($GLOBALS['__x'] = atd_analisar_copy($m['texto'], ['template' => !empty($m['template'])])['itens'], fn($i) => $i['nivel'] === 'erro')));
    }
}
confere('padrao: nomes de template unicos', count($tpls), count(array_unique($tpls)));
foreach ($tpls as $t) confere("template $t: so minusculas, numeros e sublinhado", 1, preg_match('/^[a-z0-9_]+$/', $t));
$todoTexto = json_encode([$padrao, atd_conceitos_padrao()], JSON_UNESCAPED_UNICODE);
confere('padrao: nenhum travessao no conteudo de fabrica', 0, preg_match('/[\x{2014}\x{2013}]/u', $todoTexto));
confere('padrao: teste gratis nao promete dieta', 0, preg_match('/dieta incluída|inclui dieta e|com dieta/iu', $padrao[0]['mensagens'][0]['texto']));
foreach (atd_conceitos_padrao() as $c) {
    verdade("conceito {$c['id']}: tem resumo e como aplicar", $c['resumo'] !== '' && $c['como_aplicar'] !== '');
}
confere('conceitos: ids esperados', ['narrativa', 'dor', 'desejo', 'objecao', 'produtos', 'metodo', 'persuasao', 'estrutura', 'tom'], array_column(atd_conceitos_padrao(), 'id'));

// ── A agenda do funil tem de reproduzir exatamente a agenda do teste grátis ──
foreach (['2026-10-08 14:10:00', '2026-10-08 23:30:00', '2026-10-08 05:00:00', '2026-12-30 20:59:00'] as $quando) {
    $cad = em($quando);
    $velha = array_map(fn($x) => [$x[0], wa_fmt($x[1]), $x[2]], wa_agenda_do_teste($cad, 3));
    $nova = array_map(fn($x) => [$x[0], wa_fmt($x[1]), $x[2]], atd_agenda_do_funil($padrao[0], $cad));
    confere("agenda igual a wa_agenda_do_teste ($quando)", $velha, $nova);
}
$ind = atd_agenda_do_funil($padrao[2], em('2026-10-08 14:10:00'));
confere('agenda indicacao: passos', ['indicacao:i1', 'indicacao:i2', 'indicacao:i3'], array_column($ind, 0));
confere('agenda indicacao: i1 logo', '2026-10-08 14:10:00', wa_fmt($ind[0][1]));
confere('agenda indicacao: i2 no dia seguinte as 10h', '2026-10-09 10:00:00', wa_fmt($ind[1][1]));
$conv = atd_agenda_do_funil($padrao[1], em('2026-10-08 14:10:00'));
confere('agenda conversa: mensagens de resposta e silencio nao entram na agenda por data', [], $conv);
$reat = atd_agenda_do_funil($padrao[3], em('2026-10-08 14:10:00'));
confere('agenda reativacao: r1 14 dias depois as 10h', '2026-10-22 10:00:00', wa_fmt($reat[0][1]));
$noite = ['id' => 'x', 'mensagens' => [['id' => 'a', 'tipo' => 'agendada', 'dia' => 1, 'hora' => 23, 'condicao' => 'todos']]];
confere('agenda: hora fora da janela vai para as 8h do dia seguinte', '2026-10-10 08:00:00', wa_fmt(atd_agenda_do_funil($noite, em('2026-10-08 10:00:00'))[0][1]));

// ── Renderizar ──────────────────────────────────────────────────────────────
confere('renderizar: troca variaveis', 'Oi Ana, veja http://x', atd_renderizar('Oi {{1}}, veja {{2}}', ['1' => 'Ana', '2' => 'http://x']));
confere('renderizar: variavel sem valor fica visivel', 'Oi Ana, veja {{2}}', atd_renderizar('Oi {{1}}, veja {{2}}', ['1' => 'Ana']));
confere('renderizar: valor vazio nao apaga a variavel', '{{1}}', atd_renderizar('{{1}}', ['1' => '']));

// ── Analisador de copy ──────────────────────────────────────────────────────
$a = atd_analisar_copy("Oi Ana — vamos treinar?");
verdade('copy: travessao é erro', tem($a, 'travessao', 'erro'));
verdade('copy: en dash é erro', tem(atd_analisar_copy('Treino – hoje'), 'travessao', 'erro'));
verdade('copy: hifen com espacos é aviso', tem(atd_analisar_copy('Treino - hoje'), 'hifen', 'aviso'));
verdade('copy: palavra com hifen nao dispara', !tem(atd_analisar_copy('Bom dia, tudo bem? Seu pré-treino está pronto.'), 'hifen'));
foreach (['Isso não é um gasto, é um investimento.', 'Não é sobre treinar mais, é sobre treinar melhor.', 'Não é só um app, é um método.',
          'O plano não é genérico, mas sim feito para você e é seu.', 'Não é moda; é rotina.'] as $t) {
    verdade("copy: padrao nao e X e Y: $t", tem(atd_analisar_copy($t), 'nao_e_x_e_y', 'erro'));
}
foreach (['Se preferir não receber mais mensagens, responda SAIR.', 'Você não precisa decidir agora.', 'Hoje é o último dia do seu teste grátis.',
          'Não consegui entender, pode repetir?', 'Se travou em alguma parte, me conta aqui que a gente resolve.'] as $t) {
    verdade("copy: frase normal nao dispara nao_e_x_e_y: $t", !tem(atd_analisar_copy($t), 'nao_e_x_e_y'));
}
foreach (['Resultado garantido em 30 dias', 'Perca 5 kg rápido', 'Você vai emagrecer', 'Garantimos sua evolução', 'Em 3 semanas você muda'] as $t) {
    verdade("copy: promessa: $t", tem(atd_analisar_copy($t), 'promessa', 'erro'));
}
verdade('copy: teste de 3 dias nao e promessa', !tem(atd_analisar_copy('Seu teste grátis de treino está liberado por 3 dias.'), 'promessa'));
verdade('copy: urgencia falsa', tem(atd_analisar_copy('Corra, últimas vagas!'), 'urgencia', 'aviso'));
verdade('copy: ultimo dia do teste e prazo real', !tem(atd_analisar_copy('Hoje é o último dia do seu teste.'), 'urgencia'));
verdade('copy: dieta perto do teste', tem(atd_analisar_copy('No teste você recebe dieta também.'), 'dieta_no_teste', 'aviso'));
verdade('copy: dieta sem falar de teste nao dispara', !tem(atd_analisar_copy('A consultoria inclui plano alimentar.'), 'dieta_no_teste'));
verdade('copy: preco', tem(atd_analisar_copy('A Premium custa R$ 199 por mês.'), 'preco', 'aviso'));
verdade('copy: saude', tem(atd_analisar_copy('Esse remédio ajuda na dor?'), 'saude', 'aviso'));
verdade('copy: cliche de IA', tem(atd_analisar_copy('Espero que esteja bem. Vamos transformar sua vida nessa jornada.'), 'cliche', 'aviso'));
verdade('copy: duas perguntas', tem(atd_analisar_copy('Já treinou? E como foi?'), 'perguntas', 'aviso'));
verdade('copy: uma pergunta ok', !tem(atd_analisar_copy('Já conseguiu fazer o primeiro treino?'), 'perguntas'));
verdade('copy: muitas exclamacoes', tem(atd_analisar_copy('Oi! Bora! Vamos! Agora!'), 'exclamacoes', 'aviso'));
verdade('copy: emojis demais', tem(atd_analisar_copy('Bora treinar 💪🔥🚀'), 'emojis', 'aviso'));
verdade('copy: um emoji tudo bem', !tem(atd_analisar_copy('Bora treinar 💪'), 'emojis'));
verdade('copy: caixa alta', tem(atd_analisar_copy('Aproveite AGORA essa chance'), 'caixa_alta', 'aviso'));
verdade('copy: SAIR em caixa alta e permitido', !tem(atd_analisar_copy('Para parar, responda SAIR.'), 'caixa_alta'));
verdade('copy: texto longo', tem(atd_analisar_copy(str_repeat('Treinar com acompanhamento faz diferença. ', 25)), 'tamanho', 'aviso'));
verdade('copy: primeira mensagem sem identificacao', tem(atd_analisar_copy('Oi, tudo bem?', ['primeiro' => true]), 'identificacao', 'aviso'));
verdade('copy: primeira mensagem identificada', !tem(atd_analisar_copy('Oi! Aqui é a Cora, assistente virtual da Intus.', ['primeiro' => true]), 'identificacao'));
verdade('copy: ultima sem SAIR', tem(atd_analisar_copy('Fico por aqui.', ['ultima' => true]), 'saida', 'aviso'));
verdade('copy: ultima com SAIR', !tem(atd_analisar_copy('Se quiser sair, responda SAIR.', ['ultima' => true]), 'saida'));
verdade('copy: vazio', tem(atd_analisar_copy('   '), 'vazio', 'erro'));
confere('copy: vazio vale zero', 0, atd_analisar_copy('')['pontos']);
confere('copy: texto limpo vale 100', 100, atd_analisar_copy('Oi {{1}}, já conseguiu fazer o primeiro treino? Se travou, me conta aqui.', ['template' => true])['pontos']);
verdade('copy: texto limpo tem item ok', tem(atd_analisar_copy('Oi, já treinou hoje?'), 'tudo_certo', 'ok'));
verdade('copy: erro derruba a nota', atd_analisar_copy('Oi — tudo bem?')['pontos'] <= 75);
// template
verdade('template: comeca com variavel', tem(atd_analisar_copy('{{1}}, parabéns pelo treino.', ['template' => true]), 'template_inicio', 'aviso'));
verdade('template: termina com variavel', tem(atd_analisar_copy('Seu link: {{2}}', ['template' => true]), 'template_fim', 'aviso'));
verdade('template: variavel no meio ok', !tem(atd_analisar_copy('Oi {{1}}, tudo bem?', ['template' => true]), 'template_inicio'));
verdade('template: variaveis fora de sequencia', tem(atd_analisar_copy('Oi {{1}}, veja {{3}} agora.', ['template' => true]), 'template_variaveis', 'erro'));
verdade('template: variaveis em sequencia', !tem(atd_analisar_copy('Oi {{1}}, veja {{2}} agora.', ['template' => true]), 'template_variaveis'));
verdade('template: passa de 1024', tem(atd_analisar_copy(str_repeat('a', 1030), ['template' => true]), 'template_tamanho', 'erro'));
verdade('template: linhas em branco demais', tem(atd_analisar_copy("Oi {{1}}\n\n\n\nTudo bem?", ['template' => true]), 'template_espacos', 'aviso'));
verdade('sem template as regras da Meta nao valem', !tem(atd_analisar_copy('{{1}}, parabéns.'), 'template_inicio'));
// o texto aprovado pelo Luiz comeca com variavel em 4 mensagens: o aviso tem de aparecer
$an = atd_analisar_funil($padrao[0]);
confere('funil teste gratis: t1 sem aviso de Meta', false, tem($an['t1'], 'template_inicio'));
foreach (['t3', 't5', 't6', 't7'] as $id) verdade("funil teste gratis: $id avisa variavel no comeco", tem($an[$id], 'template_inicio', 'aviso'));
verdade('funil teste gratis: t1 se identifica', !tem($an['t1'], 'identificacao'));
verdade('funil teste gratis: t7 oferece SAIR', !tem($an['t7'], 'saida'));

// ── Funis: armazenamento ────────────────────────────────────────────────────
$pdo = banco();
confere('funis: sem nada salvo devolve o padrao', $ids, array_column(atd_funis($pdo), 'id'));
confere('funis: ler nao grava nada', false, atd_cfg_existe($pdo, 'atd_funis'));
$novo = atd_funil_salvar($pdo, ['nome' => 'Funil novo', 'mensagens' => [['nome' => 'Primeira', 'texto' => "  Oi {{1}}, tudo bem?  ", 'hora' => 99, 'dia' => -3, 'condicao' => 'invalida', 'tipo' => 'xyz']]]);
confere('funil novo: id vem do nome', 'funil-novo', $novo['id']);
confere('funil novo: status padrao rascunho', 'rascunho', $novo['status']);
confere('funil novo: texto aparado', 'Oi {{1}}, tudo bem?', $novo['mensagens'][0]['texto']);
confere('funil novo: hora limitada a 23', 23, $novo['mensagens'][0]['hora']);
confere('funil novo: dia negativo vira 0', 0, $novo['mensagens'][0]['dia']);
confere('funil novo: condicao invalida vira todos', 'todos', $novo['mensagens'][0]['condicao']);
confere('funil novo: tipo invalido vira agendada', 'agendada', $novo['mensagens'][0]['tipo']);
confere('funis: salvar passa a guardar tudo (padroes + novo)', 5, count(atd_funis($pdo)));
$editado = atd_funil_por_id($pdo, 'teste-gratis');
$editado['mensagens'][0]['texto'] = 'Oi {{1}}, texto editado.';
atd_funil_salvar($pdo, $editado);
confere('funil editado: grava', 'Oi {{1}}, texto editado.', atd_funil_por_id($pdo, 'teste-gratis')['mensagens'][0]['texto']);
confere('funil editado: nao duplica', 5, count(atd_funis($pdo)));
atd_funil_restaurar($pdo, 'teste-gratis');
confere('funil restaurado: volta ao texto de fabrica', $padrao[0]['mensagens'][0]['texto'], atd_funil_por_id($pdo, 'teste-gratis')['mensagens'][0]['texto']);
confere('funil restaurar: id que nao e de fabrica', null, atd_funil_restaurar($pdo, 'funil-novo'));
$dup = atd_funil_duplicar($pdo, 'teste-gratis', 'Teste grátis versão B');
confere('duplicar: novo id (cortado em 20 caracteres)', 'teste-gratis-versao', $dup['id']);
confere('duplicar: nasce rascunho', 'rascunho', $dup['status']);
confere('duplicar: mensagens voltam a nao aprovadas', false, $dup['mensagens'][0]['aprovada']);
confere('duplicar: template ganha nome proprio', 'teste_gratis_v_t1', $dup['mensagens'][0]['template']);
confere('duplicar: original intacto e aprovado', true, atd_funil_por_id($pdo, 'teste-gratis')['mensagens'][0]['aprovada']);
$dup2 = atd_funil_duplicar($pdo, 'teste-gratis', 'Teste grátis versão B');
verdade('duplicar duas vezes nao repete id', $dup2['id'] !== $dup['id']);
confere('excluir funil', true, atd_funil_excluir($pdo, 'funil-novo'));
confere('excluir funil que nao existe', false, atd_funil_excluir($pdo, 'funil-novo'));
try { atd_funil_salvar($pdo, ['mensagens' => []]); confere('funil sem nome recusado', true, false); }
catch (InvalidArgumentException $e) { confere('funil sem nome recusado', true, true); }
$grande = ['nome' => 'Grande', 'mensagens' => array_fill(0, 50, ['id' => 'm', 'texto' => 'x'])];
$g = atd_limpar_funil($grande);
confere('funil: limite de mensagens', ATD_MAX_MENSAGENS_FUNIL, count($g['mensagens']));
confere('funil: ids de mensagem repetidos ficam unicos', count($g['mensagens']), count(array_unique(array_column($g['mensagens'], 'id'))));
$longo = atd_limpar_funil(['nome' => 'L', 'mensagens' => [['texto' => str_repeat('a', 5000)]]]);
confere('funil: texto limitado a 1024', ATD_MAX_TEXTO_MENSAGEM, mb_strlen($longo['mensagens'][0]['texto']));

// ── Conceitos ───────────────────────────────────────────────────────────────
$pdo = banco();
confere('conceitos: padrao', 9, count(atd_conceitos($pdo)));
atd_conceito_salvar($pdo, ['id' => 'dor', 'titulo' => 'Dor', 'resumo' => 'texto novo', 'como_aplicar' => 'x']);
confere('conceito editado', 'texto novo', array_values(array_filter(atd_conceitos($pdo), fn($c) => $c['id'] === 'dor'))[0]['resumo']);
confere('conceito editado: nao duplica', 9, count(atd_conceitos($pdo)));
atd_conceito_salvar($pdo, ['titulo' => 'Meu conceito', 'resumo' => 'abc']);
confere('conceito novo', 10, count(atd_conceitos($pdo)));
atd_conceito_restaurar($pdo, 'dor');
verdade('conceito restaurado', strpos(array_values(array_filter(atd_conceitos($pdo), fn($c) => $c['id'] === 'dor'))[0]['resumo'], 'texto novo') === false);
confere('conceito excluido', true, atd_conceito_excluir($pdo, 'meu-conceito'));
try { atd_conceito_salvar($pdo, ['resumo' => 'sem titulo']); confere('conceito sem titulo recusado', true, false); }
catch (InvalidArgumentException $e) { confere('conceito sem titulo recusado', true, true); }

// ── Referências: URL segura e leitura ───────────────────────────────────────
$dnsPublico = fn($h) => ['93.184.216.34'];
$dnsInterno = fn($h) => ['10.0.0.5'];
confere('url: https publica', 'https://exemplo.com/a', atd_url_segura('https://exemplo.com/a', $dnsPublico));
foreach (['ftp://exemplo.com', 'file:///etc/passwd', 'javascript:alert(1)', 'http://localhost/x', 'http://127.0.0.1/', 'http://10.1.2.3/', 'http://192.168.0.1/',
          'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'https://user:pass@exemplo.com/', 'https://exemplo.com:8080/', 'http://servidor.local/', '', 'nada'] as $u) {
    confere("url bloqueada: $u", null, atd_url_segura($u, $dnsPublico));
}
confere('url: dominio que resolve para IP interno', null, atd_url_segura('https://parece-publico.com', $dnsInterno));
confere('url: dominio que nao resolve', null, atd_url_segura('https://nao-existe.com', fn($h) => []));
confere('url: grande demais', null, atd_url_segura('https://exemplo.com/' . str_repeat('a', 600), $dnsPublico));
$html = '<html><head><title>x</title><style>.a{}</style><script>var x=1;</script></head><body><h1>Título</h1><p>Primeira   linha &amp; mais.</p><p>Segunda</p></body></html>';
$txt = atd_html_para_texto($html);
verdade('html: tira script, estilo e tags', strpos($txt, 'var x') === false && strpos($txt, '<p>') === false && strpos($txt, '.a{}') === false);
verdade('html: preserva texto e entidades', strpos($txt, 'Primeira linha & mais.') !== false && strpos($txt, 'Segunda') !== false);
$pagina = '<html><body><p>' . str_repeat('Conteúdo útil sobre vendas consultivas e objeções. ', 5) . '</p></body></html>';
$r = atd_buscar_url('https://exemplo.com/a', fn($u) => ['status' => 200, 'tipo' => 'text/html; charset=utf-8', 'corpo' => $pagina, 'local' => null], $dnsPublico);
verdade('buscar: ok', $r['ok'] && strpos($r['texto'], 'vendas consultivas') !== false);
$r = atd_buscar_url('https://exemplo.com/a', fn($u) => ['status' => 404, 'tipo' => 'text/html', 'corpo' => '', 'local' => null], $dnsPublico);
verdade('buscar: erro 404 explicado', !$r['ok'] && strpos($r['erro'], '404') !== false);
$r = atd_buscar_url('https://exemplo.com/a', fn($u) => ['status' => 200, 'tipo' => 'image/png', 'corpo' => 'x', 'local' => null], $dnsPublico);
verdade('buscar: tipo que nao e texto', !$r['ok']);
$r = atd_buscar_url('https://exemplo.com/a', fn($u) => ['status' => 200, 'tipo' => 'text/html', 'corpo' => '<p>oi</p>', 'local' => null], $dnsPublico);
verdade('buscar: pagina sem texto (video, JavaScript)', !$r['ok'] && strpos($r['erro'], 'texto') !== false);
$chamadas = [];
$r = atd_buscar_url('https://exemplo.com/a', function ($u) use (&$chamadas, $pagina) {
    $chamadas[] = $u;
    return $u === 'https://exemplo.com/a' ? ['status' => 302, 'tipo' => '', 'corpo' => '', 'local' => 'https://outro.com/b'] : ['status' => 200, 'tipo' => 'text/html', 'corpo' => $pagina, 'local' => null];
}, $dnsPublico);
verdade('buscar: segue redirecionamento seguro', $r['ok'] && $chamadas === ['https://exemplo.com/a', 'https://outro.com/b']);
$visitadas = [];
$r = atd_buscar_url('https://exemplo.com/a', function ($u) use (&$visitadas, $pagina) {
    $visitadas[] = $u;
    return $u === 'https://exemplo.com/a' ? ['status' => 302, 'tipo' => '', 'corpo' => '', 'local' => 'http://169.254.169.254/latest/meta-data/'] : ['status' => 200, 'tipo' => 'text/html', 'corpo' => $pagina, 'local' => null];
}, $dnsPublico);
verdade('buscar: redirecionamento para rede interna e bloqueado', !$r['ok']);
confere('buscar: o endereco interno nunca chega a ser buscado', ['https://exemplo.com/a'], $visitadas);
$r = atd_buscar_url('https://exemplo.com/a', fn($u) => ['status' => 302, 'tipo' => '', 'corpo' => '', 'local' => 'https://exemplo.com/a'], $dnsPublico);
verdade('buscar: laco de redirecionamento', !$r['ok']);
$r = atd_buscar_url('http://localhost/', fn($u) => ['status' => 200, 'tipo' => 'text/html', 'corpo' => $pagina, 'local' => null], $dnsPublico);
verdade('buscar: nem chama a rede para endereco bloqueado', !$r['ok']);

// ── Referências: links e arquivos ───────────────────────────────────────────
$pdo = banco();
$httpOk = fn($u) => ['status' => 200, 'tipo' => 'text/html', 'corpo' => $pagina, 'local' => null];
$l = atd_ref_link_adicionar($pdo, ['titulo' => 'Artigo de vendas', 'url' => 'https://exemplo.com/artigo', 'nota' => 'ver a abertura', 'tag' => 'vendas', 'ler' => true], $httpOk, $dnsPublico);
confere('link: titulo', 'Artigo de vendas', $l['titulo']);
verdade('link: texto lido guardado', strpos($l['texto'], 'vendas consultivas') !== false && $l['lido_em'] !== null);
confere('link: tag valida', 'vendas', $l['tag']);
$l2 = atd_ref_link_adicionar($pdo, ['url' => 'https://exemplo.com/outro', 'tag' => 'invalida'], $httpOk, $dnsPublico);
confere('link: tag invalida vira geral', 'geral', $l2['tag']);
confere('link: sem leitura nao tem texto', '', $l2['texto']);
confere('link: titulo padrao e o endereco', 'https://exemplo.com/outro', $l2['titulo']);
try { atd_ref_link_adicionar($pdo, ['url' => 'http://127.0.0.1/admin'], $httpOk, $dnsPublico); confere('link interno recusado', true, false); }
catch (InvalidArgumentException $e) { confere('link interno recusado', true, true); }
$lf = atd_ref_link_adicionar($pdo, ['url' => 'https://exemplo.com/youtube', 'ler' => true], fn($u) => ['status' => 200, 'tipo' => 'text/html', 'corpo' => '<p>x</p>', 'local' => null], $dnsPublico);
verdade('link: leitura que falha guarda o erro e o link', $lf['erro'] !== '' && $lf['texto'] === '');
$resumo = atd_refs_resumo(atd_refs($pdo));
verdade('resumo nao devolve o texto inteiro', !isset($resumo[0]['texto']) && $resumo[0]['tem_texto'] === true && $resumo[0]['chars'] > 0);
$re = atd_ref_ler_de_novo($pdo, $lf['id'], $httpOk, $dnsPublico);
verdade('link: ler de novo atualiza', $re['erro'] === '' && $re['texto'] !== '');
$atu = atd_ref_atualizar($pdo, $l['id'], ['nota' => 'nota nova', 'usar' => false, 'tag' => 'copy']);
confere('referencia: atualizar nota', 'nota nova', $atu['nota']);
confere('referencia: desmarcar uso', false, $atu['usar']);
confere('referencia: atualizar id inexistente', null, atd_ref_atualizar($pdo, 'zzzz', ['nota' => 'x']));
$txtb64 = base64_encode("Regra 1: perguntar antes de afirmar.\nRegra 2: uma pergunta por mensagem. Texto de apoio com acentuação.");
$af = atd_ref_arquivo_adicionar($pdo, ['nome' => 'playbook.txt', 'base64' => $txtb64, 'nota' => 'playbook']);
verdade('arquivo txt: texto extraido', strpos($af['texto'], 'Regra 2') !== false);
$pdf = "%PDF-1.4\n" . str_repeat('x', 200);
$ap = atd_ref_arquivo_adicionar($pdo, ['nome' => 'manual.pdf', 'base64' => base64_encode($pdf)]);
confere('arquivo pdf: sem texto na lista', '', $ap['texto']);
verdade('arquivo pdf: conteudo guardado no banco', is_array(atd_cfg_ler($pdo, 'atd_arq_' . $ap['id'])));
foreach ([['nome' => 'virus.exe', 'base64' => $txtb64], ['nome' => 'a.php', 'base64' => $txtb64], ['nome' => 'a.html', 'base64' => $txtb64], ['nome' => 'semextensao', 'base64' => $txtb64]] as $x) {
    try { atd_ref_arquivo_adicionar($pdo, $x); confere("arquivo recusado: {$x['nome']}", true, false); }
    catch (InvalidArgumentException $e) { confere("arquivo recusado: {$x['nome']}", true, true); }
}
foreach ([['nome' => 'falso.pdf', 'base64' => base64_encode('isto nao e pdf')], ['nome' => 'vazio.txt', 'base64' => ''], ['nome' => 'x.txt', 'base64' => '###'],
          ['nome' => 'codigo.txt', 'base64' => base64_encode('ola <?php system("ls"); ?>')], ['nome' => 'grande.txt', 'base64' => base64_encode(str_repeat('a', ATD_MAX_ARQUIVO_BYTES + 1))]] as $x) {
    try { atd_ref_arquivo_adicionar($pdo, $x); confere("arquivo recusado: {$x['nome']}", true, false); }
    catch (InvalidArgumentException $e) { confere("arquivo recusado: {$x['nome']}", true, true); }
}
confere('excluir referencia pdf apaga o conteudo', true, atd_ref_excluir($pdo, $ap['id']));
confere('excluir referencia pdf: blob some', false, atd_cfg_existe($pdo, 'atd_arq_' . $ap['id']));
confere('excluir referencia inexistente', false, atd_ref_excluir($pdo, 'zzzz'));

// ── Claude: pedido, resposta, erros ─────────────────────────────────────────
function com_chave(PDO $pdo): void { wa_segredo_gravar($pdo, 'anthropic_config', ['api_key' => 'sk-ant-TESTE-NAO-VALE']); }
function resposta_ok(array $variantes, array $uso = ['input_tokens' => 1000, 'output_tokens' => 500]): array {
    return ['status' => 200, 'erro' => '', 'json' => ['stop_reason' => 'end_turn', 'usage' => $uso,
        'content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => json_encode(['variantes' => $variantes, 'observacoes' => 'tudo certo'], JSON_UNESCAPED_UNICODE)]]]];
}
$pdo = banco();
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], fn() => resposta_ok([]));
verdade('claude: sem chave explica onde cadastrar', !$r['ok'] && strpos($r['erro'], 'whatsapp-keys') !== false);
com_chave($pdo);
foreach ([['gerar', []], ['reescrever', []], ['criticar', []], ['funil', []], ['objecao', []]] as [$modo, $extra]) {
    $r = atd_gerar($pdo, ['modo' => $modo], fn() => resposta_ok([]));
    verdade("claude: $modo sem o campo obrigatorio e recusado antes de gastar", !$r['ok']);
}
$corpos = [];
$ref = atd_ref_link_adicionar($pdo, ['titulo' => 'Aula de copy', 'url' => 'https://exemplo.com/copy', 'nota' => 'abertura curta', 'ler' => true], $httpOk, $dnsPublico);
$pdfRef = atd_ref_arquivo_adicionar($pdo, ['titulo' => 'Manual', 'nome' => 'manual.pdf', 'base64' => base64_encode($pdf)]);
$fake = function ($corpo, $chave, $fb) use (&$corpos) {
    $corpos[] = ['corpo' => json_decode($corpo, true), 'chave' => $chave, 'fb' => $fb];
    return resposta_ok([
        ['texto' => 'Oi {{1}}, já conseguiu fazer o primeiro treino?', 'angulo' => 'redução de atrito', 'por_que' => 'pergunta fácil'],
        ['texto' => 'Oi — tudo bem? Resultado garantido.', 'angulo' => 'ruim de propósito', 'por_que' => 'teste'],
    ]);
};
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'reativar quem não treinou', 'foco' => 'dor', 'quantidade' => 2, 'conceitos' => ['dor', 'tom'], 'template' => true], $fake);
verdade('claude: ok', $r['ok']);
confere('claude: duas variantes', 2, count($r['variantes']));
confere('claude: variante boa sem erro', true, atd_analisar_copy($r['variantes'][0]['texto'], ['template' => true])['pontos'] >= 90);
verdade('claude: variante ruim vem marcada com erros', tem($r['variantes'][1]['analise'], 'travessao', 'erro') && tem($r['variantes'][1]['analise'], 'promessa', 'erro'));
confere('claude: tokens', [1000, 500], [$r['tokens_in'], $r['tokens_out']]);
confere('claude: custo em dolar (Opus 5.5)', 0.014, $r['custo_usd']);
$c = $corpos[0]['corpo'];
confere('claude: modelo', 'claude-opus-5-5', $c['model']);
confere('claude: esforco explicito', 'medium', $c['output_config']['effort']);
confere('claude: fallback ligado', 'default', $c['fallbacks']);
confere('claude: header de fallback enviado', true, $corpos[0]['fb']);
verdade('claude: nao desliga o pensamento (Opus 5.5 recusa)', !isset($c['thinking']));
verdade('claude: chave vai so no cabecalho, nao no corpo', strpos(json_encode($c), 'sk-ant') === false && $corpos[0]['chave'] === 'sk-ant-TESTE-NAO-VALE');
verdade('claude: regras no sistema', strpos($c['system'], 'Nunca use travessão') !== false && strpos($c['system'], 'não é X, é Y') !== false && strpos($c['system'], 'Não prometa resultado') !== false);
verdade('claude: conceito escolhido entra no sistema', strpos($c['system'], '## Dor') !== false && strpos($c['system'], '## Tom de voz') !== false && strpos($c['system'], '## Narrativa') === false);
verdade('claude: fatos do produto sempre entram', strpos($c['system'], 'Teste grátis: 3 dias') !== false);
$txtUsuario = '';
$tipos = [];
foreach ($c['messages'][0]['content'] as $b) { $tipos[] = $b['type']; if ($b['type'] === 'text') $txtUsuario = $b['text']; }
verdade('claude: texto da referencia vai no pedido', strpos($txtUsuario, 'vendas consultivas') !== false && strpos($txtUsuario, 'abertura curta') !== false);
confere('claude: pdf vai como documento base64, antes do texto', ['document', 'text'], $tipos);
verdade('claude: objetivo no pedido', strpos($txtUsuario, 'reativar quem não treinou') !== false);
confere('claude: uso do dia contado', 1, atd_uso_hoje($pdo)['chamadas']);
// refs escolhidas explicitamente
$corpos = [];
atd_ref_atualizar($pdo, $ref['id'], ['usar' => false]);
atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], $fake);
$t1 = json_encode($corpos[0]['corpo']['messages']);
verdade('claude: referencia desmarcada nao vai', strpos($t1, 'vendas consultivas') === false);
$corpos = [];
atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x', 'refs' => [$ref['id']]], $fake);
verdade('claude: referencia pedida explicitamente vai mesmo desmarcada', strpos(json_encode($corpos[0]['corpo']['messages']), 'vendas consultivas') !== false);
// modos
foreach ([['reescrever', ['texto' => 'Oi tudo bem', 'instrucao' => 'mais curto'], 'MENSAGEM'], ['criticar', ['texto' => 'Oi tudo bem'], 'Critique'], ['objecao', ['objecao' => 'está caro'], 'está caro'],
          ['funil', ['texto' => '1. Oi'], 'SEQUÊNCIA']] as [$modo, $extra, $marca]) {
    $corpos = [];
    $r = atd_gerar($pdo, ['modo' => $modo] + $extra, $fake);
    $u = json_encode($corpos[0]['corpo']['messages'], JSON_UNESCAPED_UNICODE);
    verdade("claude: modo $modo monta o pedido certo", $r['ok'] && strpos($u, $marca) !== false);
}
// 400 por causa do fallback: tenta de novo sem ele
$n = 0; $corpos = [];
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], function ($corpo, $chave, $fb) use (&$n, &$corpos) {
    $n++; $corpos[] = json_decode($corpo, true);
    if ($n === 1) return ['status' => 400, 'erro' => '', 'json' => ['error' => ['message' => 'unknown field fallbacks / beta not enabled']]];
    return resposta_ok([['texto' => 'Oi, tudo bem?', 'angulo' => 'a', 'por_que' => 'b']]);
});
verdade('claude: 400 de fallback tenta de novo sem o parametro', $r['ok'] && $n === 2 && !isset($corpos[1]['fallbacks']) && isset($corpos[0]['fallbacks']));
// erros da API
foreach ([[401, 'chave do Claude foi recusada'], [429, 'Limite de uso'], [500, 'HTTP 500'], [0, 'não respondeu']] as [$cod, $trecho]) {
    $r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], fn() => ['status' => $cod, 'erro' => 'sem rede', 'json' => $cod ? ['error' => ['message' => 'falhou']] : null]);
    verdade("claude: erro $cod explicado", !$r['ok'] && stripos($r['erro'], $trecho) !== false);
}
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], fn() => ['status' => 200, 'erro' => '', 'json' => ['stop_reason' => 'refusal', 'content' => []]]);
verdade('claude: recusa tratada', !$r['ok'] && strpos($r['erro'], 'recusou') !== false);
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], function () { throw new RuntimeException('boom'); });
verdade('claude: excecao nao derruba', !$r['ok']);
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], fn() => ['status' => 200, 'erro' => '', 'json' => ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    'content' => [['type' => 'text', 'text' => 'Aqui está um texto solto sem JSON.']]]]);
verdade('claude: resposta fora do formato volta como texto bruto', $r['ok'] && $r['variantes'] === [] && strpos($r['bruto'], 'texto solto') !== false);
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], fn() => ['status' => 200, 'erro' => '', 'json' => ['stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    'content' => [['type' => 'text', 'text' => "```json\n{\"variantes\":[{\"texto\":\"Oi!\",\"angulo\":\"a\",\"por_que\":\"b\"}],\"observacoes\":\"\"}\n```"]]]]);
verdade('claude: json dentro de cerca de codigo', $r['ok'] && count($r['variantes']) === 1);
// limite diario
$u = ['chamadas' => ATD_MAX_CHAMADAS_DIA, 'tin' => 0, 'tout' => 0];
atd_cfg_gravar($pdo, 'atd_uso', [wa_agora()->format('Y-m-d') => $u]);
$r = atd_gerar($pdo, ['modo' => 'gerar', 'objetivo' => 'x'], fn() => resposta_ok([]));
verdade('claude: limite diario trava', !$r['ok'] && strpos($r['erro'], 'Limite diário') !== false);
confere('extrair json: texto cercado', ['a' => 1], atd_extrair_json('veja: {"a":1} fim'));
confere('extrair json: invalido', null, atd_extrair_json('sem json'));
confere('custo: 1M entrada', 4.0, atd_custo_usd(1000000, 0));
confere('custo: 1M saida', 20.0, atd_custo_usd(0, 1000000));

// ── Testes A/B ──────────────────────────────────────────────────────────────
$pdo = banco();
atd_funil_salvar($pdo, atd_funil_duplicar($pdo, 'teste-gratis', 'Variante B'));
$b = atd_teste_salvar($pdo, ['nome' => 'Abertura curta', 'gatilho' => 'teste-gratis', 'funil_a' => 'teste-gratis', 'funil_b' => 'variante-b', 'pct_b' => 50, 'status' => 'rodando', 'hipotese' => 'mensagem curta responde mais']);
confere('ab: inicio marcado ao rodar', true, $b['inicio'] !== null);
confere('ab: nome', 'Abertura curta', $b['nome']);
foreach ([['funil_a' => 'teste-gratis', 'funil_b' => 'teste-gratis'], ['funil_a' => 'teste-gratis', 'funil_b' => 'nao-existe'], ['funil_a' => 'x', 'funil_b' => 'y']] as $x) {
    try { atd_teste_salvar($pdo, $x); confere('ab recusado: ' . json_encode($x), true, false); }
    catch (InvalidArgumentException $e) { confere('ab recusado: ' . json_encode($x), true, true); }
}
try { atd_teste_salvar($pdo, ['gatilho' => 'teste-gratis', 'funil_a' => 'teste-gratis', 'funil_b' => 'indicacao', 'status' => 'rodando']); confere('ab: dois rodando no mesmo gatilho recusado', true, false); }
catch (InvalidArgumentException $e) { confere('ab: dois rodando no mesmo gatilho recusado', true, true); }
confere('ab: pct limitado', 95, atd_teste_salvar($pdo, ['id' => 'outro', 'gatilho' => 'indicacao', 'funil_a' => 'indicacao', 'funil_b' => 'reativacao', 'pct_b' => 300])['pct_b']);
$t = ['id' => 'abc', 'pct_b' => 30];
$lados = [];
for ($i = 0; $i < 2000; $i++) { $tel = '55459' . str_pad((string)$i, 8, '0', STR_PAD_LEFT); $lados[$tel] = atd_variante($t, $tel); }
$nb = count(array_filter($lados, fn($x) => $x === 'b'));
verdade('ab: divisao perto de 30% (' . $nb . ' de 2000)', $nb > 500 && $nb < 700);
$mesmo = true; foreach ($lados as $tel => $l) { if (atd_variante($t, $tel) !== $l) $mesmo = false; }
verdade('ab: a mesma pessoa cai sempre do mesmo lado', $mesmo);
confere('ab: 0% manda todo mundo para A', 0, count(array_filter(array_map(fn($tel) => atd_variante(['id' => 'z', 'pct_b' => 0], $tel), array_keys($lados)), fn($x) => $x === 'b')));
confere('ab: 100% manda todo mundo para B', 2000, count(array_filter(array_map(fn($tel) => atd_variante(['id' => 'z', 'pct_b' => 100], $tel), array_keys($lados)), fn($x) => $x === 'b')));
$esc = atd_funil_para_contato($pdo, 'teste-gratis', '5545991156006', 'teste-gratis');
verdade('ab: contato recebe um dos dois funis do teste', in_array($esc['funil'], ['teste-gratis', 'variante-b'], true) && $esc['teste'] === $b['id']);
$sem = atd_funil_para_contato($pdo, 'indicacao', '5545991156006', 'indicacao');
confere('ab: gatilho sem teste rodando usa o padrao', ['funil' => 'indicacao', 'teste' => null, 'lado' => null], $sem);
atd_teste_salvar($pdo, ['id' => $b['id']] + ['gatilho' => 'teste-gratis', 'funil_a' => 'teste-gratis', 'funil_b' => 'variante-b', 'status' => 'encerrado']);
$enc = array_values(array_filter(atd_testes($pdo), fn($x) => $x['id'] === $b['id']))[0];
verdade('ab: encerrar marca o fim e preserva o inicio', $enc['fim'] !== null && $enc['inicio'] === $b['inicio']);
confere('ab: teste encerrado nao sorteia mais', null, atd_funil_para_contato($pdo, 'teste-gratis', '5545991156006', 'teste-gratis')['teste']);
confere('ab: excluir', true, atd_teste_excluir($pdo, $b['id']));
// estatística
confere('cdf(0)', 0.5, round(atd_normal_cdf(0), 3));
confere('cdf(1.96)', 0.975, round(atd_normal_cdf(1.96), 3));
confere('cdf(-1.96)', 0.025, round(atd_normal_cdf(-1.96), 3));
confere('taxas: amostra pequena', 'amostra_pequena', atd_comparar_taxas(3, 10, 5, 10)['veredito']);
confere('taxas: sem envios', 'sem_dados', atd_comparar_taxas(0, 0, 0, 0)['veredito']);
confere('taxas: A vence', 'a_vence', atd_comparar_taxas(60, 100, 40, 100)['veredito']);
confere('taxas: B vence', 'b_vence', atd_comparar_taxas(40, 100, 60, 100)['veredito']);
confere('taxas: diferenca pequena nao e conclusiva', 'sem_diferenca', atd_comparar_taxas(50, 100, 52, 100)['veredito']);
confere('taxas: iguais', 'sem_diferenca', atd_comparar_taxas(50, 100, 50, 100)['veredito']);
confere('taxas: percentuais', [60.0, 40.0], [atd_comparar_taxas(60, 100, 40, 100)['taxa_a'], atd_comparar_taxas(60, 100, 40, 100)['taxa_b']]);
verdade('taxas: p-valor pequeno quando a diferenca e grande', atd_comparar_taxas(60, 100, 40, 100)['p_valor'] < 0.01);

// ── Métricas do dashboard ───────────────────────────────────────────────────
$pdo = banco();
$ag = em('2026-10-20 12:00:00');
function contato(PDO $pdo, string $tel, string $criado, array $c = []): int {
    $c += ['etapa' => 'novo', 'optin' => $criado, 'saiu' => null, 'humano' => 0];
    $pdo->prepare('INSERT INTO intus_wa_contato (telefone, etapa, modo, optin_em, saiu_em, precisa_humano, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$tel, $c['etapa'], 'robo', $c['optin'], $c['saiu'], $c['humano'], $criado, $criado]);
    return (int)$pdo->lastInsertId();
}
function msg(PDO $pdo, int $idc, string $dir, string $quando, array $m = []): void {
    $m += ['origem' => $dir === 'E' ? 'lead' : 'sequencia', 'template' => null, 'status' => $dir === 'E' ? 'recebida' : 'enviada', 'tin' => null, 'tout' => null];
    $pdo->prepare('INSERT INTO intus_wa_mensagem (idcontato, direcao, origem, tipo, texto, template, status, tokens_in, tokens_out, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$idc, $dir, $m['origem'], 'text', 'x', $m['template'], $m['status'], $m['tin'], $m['tout'], $quando]);
}
function passo(PDO $pdo, int $idc, string $passo, string $status, ?string $motivo = null): void {
    $pdo->prepare('INSERT INTO intus_wa_agenda (idcontato, passo, enviar_em, condicao, status, motivo) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$idc, $passo, '2026-10-18 10:00:00', 'todos', $status, $motivo]);
}
$c1 = contato($pdo, '5545900000001', '2026-10-18 09:00:00');
msg($pdo, $c1, 'S', '2026-10-18 10:00:00', ['template' => 'intus_t1_boas_vindas', 'status' => 'lida']);
msg($pdo, $c1, 'E', '2026-10-18 10:30:00');
passo($pdo, $c1, 't1', 'enviada');
$c2 = contato($pdo, '5545900000002', '2026-10-19 08:00:00');
msg($pdo, $c2, 'S', '2026-10-19 09:00:00', ['template' => 'intus_t1_boas_vindas', 'status' => 'entregue']);
passo($pdo, $c2, 't1', 'enviada');
$c3 = contato($pdo, '5545900000003', '2026-10-19 07:00:00', ['saiu' => '2026-10-19 08:20:00']);
msg($pdo, $c3, 'S', '2026-10-19 08:00:00', ['template' => 'intus_t1_boas_vindas', 'status' => 'entregue']);
msg($pdo, $c3, 'E', '2026-10-19 08:10:00');
passo($pdo, $c3, 't1', 'enviada');
passo($pdo, $c3, 't2', 'cancelada', 'saiu');
$c4 = contato($pdo, '5545900000004', '2026-08-01 10:00:00', ['etapa' => 'convertido']);
$c5 = contato($pdo, '5545900000005', '2026-10-19 10:00:00', ['humano' => 1]);
msg($pdo, $c5, 'S', '2026-10-19 11:00:00', ['template' => 'intus_i1_indicacao', 'status' => 'falhou']);
passo($pdo, $c5, 'indicacao:i1', 'enviada');
passo($pdo, $c5, 'indicacao:i2', 'pulada', 'sem_treino_condicao');
msg($pdo, $c5, 'S', '2026-10-19 12:00:00', ['origem' => 'robo', 'tin' => 1000, 'tout' => 200]);
msg($pdo, $c1, 'E', '2026-10-17 09:00:00'); // fora do periodo de 2 dias, dentro de 30
$m = atd_metricas($pdo, 30, $ag);
$k = $m['kpis'];
confere('metricas: contatos total', 5, $k['contatos_total']);
confere('metricas: novos no periodo', 4, $k['novos']);
confere('metricas: com consentimento', 4, $k['com_consentimento']);
confere('metricas: sairam', 1, $k['sairam']);
confere('metricas: taxa de saida', 25.0, $k['taxa_saida']);
confere('metricas: enviadas (inclui a do robo)', 5, $k['enviadas']);
confere('metricas: recebidas', 3, $k['recebidas']);
confere('metricas: entregues contam lidas', 3, $k['entregues']);
confere('metricas: lidas', 1, $k['lidas']);
confere('metricas: falhas', 1, $k['falhas']);
confere('metricas: taxa de entrega', 60.0, $k['taxa_entrega']);
confere('metricas: taxa de leitura', 33.3, $k['taxa_leitura']);
confere('metricas: templates enviados', 4, $k['templates_enviados']);
confere('metricas: templates respondidos em ate 24h', 2, $k['templates_respondidos']);
confere('metricas: taxa de resposta', 50.0, $k['taxa_resposta']);
confere('metricas: mediana de resposta em minutos', 10.0, $k['mediana_resposta_min']);
confere('metricas: precisam de humano', 1, $k['precisam_humano']);
confere('metricas: convertidos', 1, $k['convertidos']);
confere('metricas: etapas', ['novo' => 4, 'convertido' => 1], $m['etapas']);
confere('metricas: serie tem um ponto por dia', 30, count($m['serie']));
$s19 = array_values(array_filter($m['serie'], fn($x) => $x['dia'] === '2026-10-19'))[0];
confere('metricas: dia 19', [3, 4, 1], [$s19['novos'], $s19['enviadas'], $s19['recebidas']]);
$s20 = array_values(array_filter($m['serie'], fn($x) => $x['dia'] === '2026-10-20'))[0];
confere('metricas: dia sem movimento aparece zerado', [0, 0, 0], [$s20['novos'], $s20['enviadas'], $s20['recebidas']]);
confere('metricas: horas das respostas', [1, 1, 1], [$m['horas_resposta'][8], $m['horas_resposta'][10], $m['horas_resposta'][9]]);
confere('metricas: agenda por status', ['enviada' => 4, 'cancelada' => 1, 'pulada' => 1], $m['agenda']['status']);
confere('metricas: motivos de nao envio', ['saiu' => 1, 'sem_treino_condicao' => 1], $m['agenda']['motivos']);
confere('metricas: funil teste gratis', [3, 3, 2, 66.7, 1], [$m['funis']['teste-gratis']['contatos'], $m['funis']['teste-gratis']['receberam'], $m['funis']['teste-gratis']['responderam'], $m['funis']['teste-gratis']['taxa_resposta'], $m['funis']['teste-gratis']['sairam']]);
confere('metricas: funil indicacao', [1, 1, 0, 0.0, 1], [$m['funis']['indicacao']['contatos'], $m['funis']['indicacao']['receberam'], $m['funis']['indicacao']['responderam'], $m['funis']['indicacao']['taxa_resposta'], $m['funis']['indicacao']['humano']]);
confere('metricas: template t1', [3, 3, 1, 0, 2], [$m['templates'][0]['enviadas'], $m['templates'][0]['entregues'], $m['templates'][0]['lidas'], $m['templates'][0]['falhas'], $m['templates'][0]['respondidas']]);
confere('metricas: passos do funil', 1, $m['passos']['teste-gratis']['t2']['cancelada']);
confere('metricas: uso do Claude', [1000, 200, 0.008], [$m['claude']['tokens_in'], $m['claude']['tokens_out'], $m['claude']['custo_usd']]);
$m7 = atd_metricas($pdo, 1, $ag);
confere('metricas: periodo de 1 dia pega ontem e hoje', [3, 4], [$m7['kpis']['novos'], $m7['kpis']['enviadas']]);
$mLonge = atd_metricas($pdo, 1, em('2026-10-25 12:00:00'));
confere('metricas: periodo sem movimento', [0, 0], [$mLonge['kpis']['novos'], $mLonge['kpis']['enviadas']]);
// resposta so conta ate 24h depois da mensagem
foreach ([[23, 1], [25, 0]] as [$horas, $esperado]) {
    $pj = banco();
    $cx = contato($pj, '5545911111111', '2026-10-19 08:00:00');
    msg($pj, $cx, 'S', '2026-10-19 09:00:00', ['template' => 'intus_t1_boas_vindas', 'status' => 'lida']);
    msg($pj, $cx, 'E', wa_fmt(em('2026-10-19 09:00:00')->modify("+$horas hour")));
    confere("metricas: resposta $horas h depois conta como resposta?", $esperado, atd_metricas($pj, 30, em('2026-10-22 12:00:00'))['kpis']['templates_respondidos']);
}
$vazio = atd_metricas(banco(), 30, $ag);
confere('metricas: banco vazio nao quebra', [0, null, null], [$vazio['kpis']['contatos_total'], $vazio['kpis']['taxa_resposta'], $vazio['kpis']['taxa_entrega']]);
confere('metricas: dias fora do limite sao corrigidos', 365, atd_metricas(banco(), 9999, $ag)['periodo']['dias']);
// teste A/B sobre os mesmos dados
atd_funil_salvar($pdo, ['id' => 'indicacao', 'nome' => 'Indicação', 'mensagens' => [['id' => 'i1', 'texto' => 'x']]]);
atd_cfg_gravar($pdo, 'atd_testes', [['id' => 'tt', 'nome' => 'A contra B', 'gatilho' => 'teste-gratis', 'funil_a' => 'teste-gratis', 'funil_b' => 'indicacao', 'pct_b' => 50, 'status' => 'rodando', 'inicio' => '2026-10-19 00:00:00']]);
$res = atd_resultados_testes($pdo)['tt'];
confere('ab resultado: lado A so conta contatos depois do inicio', [2, 1], [$res['a']['receberam'], $res['a']['responderam']]);
confere('ab resultado: lado B', [1, 0], [$res['b']['receberam'], $res['b']['responderam']]);
confere('ab resultado: poucos dados nao concluem', 'amostra_pequena', $res['comparacao']['veredito']);

// ── Exportação e prontidão ──────────────────────────────────────────────────
$pdo = banco();
$ex = atd_exportar($pdo);
confere('exportar: versao', 1, $ex['versao']);
confere('exportar: funis', 4, count($ex['funis']));
confere('exportar: cada mensagem leva o passo da agenda', 'indicacao:i1', $ex['funis'][2]['mensagens'][0]['passo']);
$tplNomes = array_column($ex['templates_meta'], 'nome');
verdade('exportar: templates da Meta listados', in_array('intus_t1_boas_vindas', $tplNomes, true) && in_array('intus_c7_retomada', $tplNomes, true));
$t1m = array_values(array_filter($ex['templates_meta'], fn($x) => $x['nome'] === 'intus_t1_boas_vindas'))[0];
confere('exportar: t1 e utilidade', 'UTILITY', $t1m['categoria']);
confere('exportar: t1 tem duas variaveis', 2, $t1m['variaveis']);
confere('exportar: t1 aprovada pelo Luiz', true, $t1m['aprovada_pelo_luiz']);
$t2m = array_values(array_filter($ex['templates_meta'], fn($x) => $x['nome'] === 'intus_t2_primeiro_treino'))[0];
confere('exportar: t2 e marketing', 'MARKETING', $t2m['categoria']);
$i1m = array_values(array_filter($ex['templates_meta'], fn($x) => $x['nome'] === 'intus_i1_indicacao'))[0];
confere('exportar: rascunho nao esta aprovado', false, $i1m['aprovada_pelo_luiz']);
confere('exportar: regras de horario', [8, 21], $ex['regras']['horario']);
confere('exportar: atendente', 'Cora', $ex['atendente']);
confere('exportar: sem teste rodando', [], $ex['testes_ab']);
verdade('exportar: nenhum segredo no pacote', strpos(json_encode($ex), 'sk-ant') === false && strpos(json_encode($ex), 'access_token') === false);
$pr = atd_prontidao($pdo);
confere('prontidao: sem chave', [false, false, false], [$pr['claude_chave'], $pr['whatsapp_configurado'], $pr['cron_token']]);
com_chave($pdo);
wa_segredo_gravar($pdo, 'cron_config', ['token' => 'segredo-do-cron']);
$pr = atd_prontidao($pdo);
confere('prontidao: com chave e cron', [true, true], [$pr['claude_chave'], $pr['cron_token']]);
verdade('prontidao: nunca devolve o valor das chaves', strpos(json_encode($pr), 'sk-ant') === false && strpos(json_encode($pr), 'segredo-do-cron') === false);
confere('prontidao: automacao ainda desligada e dita com franqueza', false, $pr['automacao_ligada']);

// ── Estrutura do endpoint e da biblioteca ───────────────────────────────────
$ep = file_get_contents(__DIR__ . '/../app/api/atendimento.php');
$lib = file_get_contents(__DIR__ . '/../app/api/_atendimento.php');
verdade('endpoint: exige sessao e administrador', strpos($ep, "empty(\$ctx['admin'])") !== false && strpos($ep, 'is_aluno') !== false);
verdade('endpoint: a checagem de admin vem antes de qualquer acao', strpos($ep, "empty(\$ctx['admin'])") < strpos($ep, 'switch ($action)'));
verdade('endpoint: acoes que gravam so por POST', strpos($ep, "'funil_salvar'") !== false && strpos($ep, 'Use POST') !== false);
verdade('endpoint: nao devolve telefone nem texto de conversa', strpos($ep, "'telefone'") === false && strpos($ep, 'intus_wa_mensagem') === false);
verdade('biblioteca: sem NOW() nem funcao so do MySQL', !preg_match('/\bNOW\(\)|ON DUPLICATE KEY|INSERT IGNORE|CURDATE\(\)/i', $lib));
verdade('biblioteca: chave da API so sai de intus_secrets', strpos($lib, 'sk-ant') === false && strpos($lib, "wa_segredo(\$pdo, 'anthropic_config')") !== false);
verdade('biblioteca: modelo vem dos ajustes, nao fixo no pedido', strpos($lib, "wa_ajustes(\$pdo)['modelo']") !== false);
verdade('biblioteca: nao desliga o pensamento do Opus 5.5', strpos($lib, "'thinking'") === false);
$pagina = __DIR__ . '/../atendimento/index.html';
if (is_file($pagina)) {
    $h = file_get_contents($pagina);
    verdade('pagina: noindex', strpos($h, 'noindex') !== false);
    verdade('pagina: usa a API certa', strpos($h, '/app/api/atendimento.php') !== false);
    verdade('pagina: escapa HTML antes de inserir texto', strpos($h, 'function esc(') !== false);
    verdade('pagina: sem travessao no texto', !preg_match('/[\x{2014}\x{2013}]/u', $h));
}

echo "\n";
if ($falhas) {
    echo count($falhas) . " FALHA(S) de " . ($ok + count($falhas)) . " conferencias:\n";
    foreach ($falhas as $f) echo "  - $f\n";
    exit(1);
}
echo "OK: $ok conferencias passaram.\n";
