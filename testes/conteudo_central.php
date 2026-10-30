<?php
/**
 * Central de conteúdo (app/api/_conteudo.php): quem acessa, etapas e exigências, copy, calendário, reciclagem, painel e fila de postagem.
 * SQLite em memória, sem rede.
 *   php testes/conteudo_central.php
 */
require_once __DIR__ . '/../app/api/_atendimento.php';   // traz o analisador de copy que a Central usa
require_once __DIR__ . '/../app/api/_conteudo.php';

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
$PERMS = [5 => ['cut_ver' => true], 6 => []];
if (!function_exists('usuarioTemPermissao')) { function usuarioTemPermissao(PDO $pdo, int $id, string $k): bool { global $PERMS; return !empty($PERMS[$id][$k]); } }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$adm = ['idusuario' => 1, 'admin' => true, 'nmusuario' => 'Luiz Nunes'];
$eqp = ['idusuario' => 5, 'nmusuario' => 'Editora Ana'];
$HOJE = '2026-11-02';   // segunda-feira
function ids(array $l): array { return array_column($l, 'campo'); }
function nova(PDO $pdo, array $in, array $ctx, ?string $hoje = null, bool $forcar = false) { global $HOJE; return cont_salvar_peca($pdo, $in, $ctx, $forcar, $hoje ?? $HOJE); }

echo "\n══ Quem pode usar ══\n";
eq('administrador', cont_pode($pdo, $adm), true);
eq('equipe com cut_ver', cont_pode($pdo, $eqp), true);
eq('equipe sem a chave', cont_pode($pdo, ['idusuario' => 6]), false);
eq('aluno nunca', cont_pode($pdo, ['idusuario' => 1, 'admin' => true, 'is_aluno' => true]), false);

echo "\n══ Configuração ══\n";
$cfg = cont_cfg($pdo);
eq('3 perfis de fábrica', array_column($cfg['marcas'], 'id'), ['luiznunest', 'intusfit', 'codigodadefinicao']);
eq('Mentoria 4X e Sai Pochete não estão no catálogo', array_column(cont_ofertas($pdo), 'id') === array_values(array_diff(array_column(cont_ofertas($pdo), 'id'), ['mentoria-4x', 'sai-pochete'])), true);
eq('CDD anual no catálogo', in_array('cdd-anual', array_column(cont_ofertas($pdo), 'id'), true), true);
eq('Premium sem preço publicado', array_values(array_filter(cont_ofertas($pdo), fn($o) => $o['id'] === 'consultoria-premium'))[0]['preco'], 'Só com a equipe');
$c = cont_cfg_limpar(['marcas' => [], 'formatos' => $cfg['formatos'], 'tipos' => $cfg['tipos'], 'funis' => $cfg['funis']]);
eq('sem nenhuma marca é recusado', isset($c['erro']), true);
$c = cont_cfg_limpar(['marcas' => [['nome' => 'A'], ['nome' => 'a']], 'formatos' => $cfg['formatos'], 'tipos' => $cfg['tipos'], 'funis' => $cfg['funis']]);
eq('dois perfis com o mesmo nome são recusados', isset($c['erro']), true);
$c = cont_cfg_limpar(['marcas' => $cfg['marcas'], 'formatos' => $cfg['formatos'], 'tipos' => $cfg['tipos'], 'funis' => $cfg['funis'], 'pessoas' => [['nome' => 'Rosângela', 'papel' => 'participante'], ['nome' => 'Marcos', 'papel' => 'xx']], 'reciclar_dias' => 9999, 'parado_dias' => 0]);
eq('pessoas e papel inválido virá "outro"', array_column($c['pessoas'], 'papel'), ['participante', 'outro']);
eq('prazos ficam dentro dos limites', [$c['reciclar_dias'], $c['parado_dias']], [365, 1]);
$c = cont_cfg_limpar(['marcas' => $cfg['marcas'], 'formatos' => $cfg['formatos'], 'tipos' => $cfg['tipos'], 'funis' => $cfg['funis']]);
eq('pessoas ausentes não inventam ninguém', $c['pessoas'], []);
$c['marcas'][0]['meta_semana'] = 99;
eq('meta semanal limitada a 21', cont_cfg_limpar($c)['marcas'][0]['meta_semana'], 21);
cut_gravar($pdo, 'cont_cfg', cont_cfg_limpar(['marcas' => $cfg['marcas'], 'formatos' => $cfg['formatos'], 'tipos' => $cfg['tipos'], 'funis' => $cfg['funis'], 'pessoas' => []]));
eq('o que foi salvo vale, inclusive lista de pessoas vazia', cont_cfg($pdo)['pessoas'], []);
cut_apagar_chave($pdo, 'cont_cfg');
eq('restaurar volta à fábrica', cont_cfg($pdo)['pessoas'][0]['nome'], 'Luiz Nunes');

echo "\n══ Criar peça e etapas ══\n";
$r = nova($pdo, ['titulo' => ''], $eqp);
eq('sem título é recusado', $r['ok'], false);
$r = nova($pdo, ['titulo' => 'Treino de perna sem academia'], $eqp);
eq('ideia nasce livre (sem perfil ainda)', $r['ok'], true);
$id = $r['peca']['id'];
eq('quem criou fica registrado', [$r['peca']['criado_por_nome'], $r['peca']['responsavel'], $r['peca']['status']], ['Editora Ana', 'Editora Ana', 'ideia']);
eq('primeira linha do histórico', $r['peca']['historico'][0]['acao'], 'criou');
$r = nova($pdo, ['id' => $id, 'status' => 'roteiro'], $eqp);
eq('roteiro sem perfil: 422', [$r['ok'], $r['codigo'], ids($r['pendencias'])], [false, 422, ['marca']]);
$r = nova($pdo, ['id' => $id, 'status' => 'roteiro', 'marca' => 'intusfit'], $eqp);
eq('com perfil, vai para roteiro', $r['ok'], true);
$r = nova($pdo, ['id' => $id, 'status' => 'gravacao'], $eqp);
eq('gravação sem gancho nem roteiro: 422', [$r['ok'], ids($r['pendencias'])], [false, ['roteiro']]);
$r = nova($pdo, ['id' => $id, 'status' => 'gravacao', 'gancho' => 'Sua perna cresce sem equipamento?'], $eqp);
eq('com gancho, vai para gravação', $r['ok'], true);
$r = nova($pdo, ['id' => $id, 'status' => 'edicao', 'formato' => 'reel'], $eqp);
eq('edição com formato', $r['ok'], true);
$r = nova($pdo, ['id' => $id, 'status' => 'revisao'], $eqp);
eq('revisão sem vídeo: 422', [$r['ok'], ids($r['pendencias'])], [false, ['midia']]);
$r = nova($pdo, ['id' => $id, 'status' => 'revisao', 'cut_projeto' => 'ric-02-treino-em-casa'], $eqp);
eq('com projeto do Intus Cut, vai para revisão', $r['ok'], true);
$r = nova($pdo, ['id' => $id, 'status' => 'aprovado'], $eqp);
eq('aprovado sem legenda nem tipo: 422', [$r['ok'], ids($r['pendencias'])], [false, ['legenda', 'tipo']]);
$r = nova($pdo, ['id' => $id, 'status' => 'aprovado', 'tipo' => 'educativo', 'legenda' => "Treino de perna — sem equipamento."], $eqp);
eq('travessão na legenda trava a aprovação', [$r['ok'], ids($r['pendencias'])], [false, ['legenda']]);
$r = nova($pdo, ['id' => $id, 'status' => 'aprovado', 'legenda' => 'Treino de perna em casa. Não é sobre equipamento, é sobre intenção.'], $eqp);
eq('"não é X, é Y" trava a aprovação', $r['ok'], false);
$r = nova($pdo, ['id' => $id, 'status' => 'aprovado', 'tipo' => 'educativo', 'legenda' => 'Treino de perna em casa, com o peso do corpo. Salve para fazer depois.'], $eqp);
eq('legenda limpa aprova', $r['ok'], true);
eq('uma tentativa recusada não grava os campos enviados (a tela salva os campos antes de mover)', cont_pecas($pdo)[$id]['status'], 'aprovado');
$r = nova($pdo, ['id' => $id, 'status' => 'agendado'], $eqp);
eq('agendar sem dia e hora: 422', [$r['ok'], ids($r['pendencias'])], [false, ['data', 'hora']]);
$r = nova($pdo, ['id' => $id, 'status' => 'agendado', 'data' => '2026-11-05', 'hora' => '19:30'], $eqp);
eq('com dia e hora, agenda', $r['ok'], true);
eq('histórico guarda cada passo', count($r['peca']['historico']), 7);
$r = nova($pdo, ['id' => $id, 'status' => 'publicado'], $eqp);
eq('publicar marca o dia e avisa do link', [$r['ok'], $r['peca']['publicado_em'], ids($r['pendencias'])], [true, '2026-11-02', ['link_publicado']]);
$r = nova($pdo, ['id' => $id, 'status' => 'ideia'], $eqp);
eq('voltar a etapa limpa o "publicado em"', [$r['ok'], $r['peca']['publicado_em']], [true, '']);

echo "\n══ Regras que protegem a marca ══\n";
$r = nova($pdo, ['titulo' => 'Depoimento da aluna', 'marca' => 'codigodadefinicao', 'formato' => 'reel', 'tipo' => 'prova-social', 'cut_projeto' => 'dep-aluna-01', 'legenda' => 'Ela contou como foi a rotina dela.',
    'gancho' => 'A rotina dela mudou', 'pessoas' => [['nome' => 'Rosângela', 'papel' => 'participante']], 'status' => 'revisao'], $adm);
eq('peça com aluna na revisão', $r['ok'], true);
$dep = $r['peca']['id'];
$r = nova($pdo, ['id' => $dep, 'status' => 'aprovado'], $adm);
eq('aluna na peça sem decisão sobre a autorização: 422', [$r['ok'], ids($r['pendencias'])], [false, ['autorizacao_imagem']]);
$r = nova($pdo, ['id' => $dep, 'status' => 'aprovado', 'autorizacao_imagem' => 'pendente'], $adm);
eq('autorização pendente deixa aprovar', $r['ok'], true);
$r = nova($pdo, ['id' => $dep, 'status' => 'agendado', 'data' => '2026-11-06', 'hora' => '12:00'], $adm);
eq('mas não deixa agendar', [$r['ok'], ids($r['pendencias'])], [false, ['autorizacao_imagem']]);
$r = nova($pdo, ['id' => $dep, 'status' => 'agendado', 'data' => '2026-11-06', 'hora' => '12:00'], $adm, null, true);
eq('administrador pode forçar, e o histórico diz', [$r['ok'], end($r['peca']['historico'])['acao']], [true, 'moveu (forçado)']);
$r = nova($pdo, ['id' => $dep, 'autorizacao_imagem' => 'ok'], $adm);
eq('liberada a autorização, nada mais bloqueia', count(cont_erros(cont_pendencias($r['peca'], cont_cfg($pdo), cont_ofertas($pdo), 'agendado', $HOJE))), 0);
$r = nova($pdo, ['titulo' => 'Oferta CDD', 'marca' => 'intusfit', 'formato' => 'imagem', 'tipo' => 'oferta', 'drive_link' => 'https://drive.google.com/x', 'legenda' => 'Conheça o método.', 'oferta' => 'cdd-anual', 'status' => 'aprovado'], $adm);
$avisos = array_column(array_filter($r['pendencias'], fn($p) => $p['nivel'] === 'aviso'), 'msg');
eq('oferta de outro perfil gera aviso, sem bloquear', [$r['ok'], count($avisos)], [true, 1]);
$r = nova($pdo, ['titulo' => 'Venda sem oferta', 'marca' => 'intusfit', 'formato' => 'imagem', 'tipo' => 'oferta', 'drive_link' => 'https://drive.google.com/x', 'legenda' => 'Vem treinar.', 'status' => 'aprovado'], $adm);
eq('post de venda sem oferta avisa', array_column(array_filter($r['pendencias'], fn($p) => $p['nivel'] === 'aviso'), 'campo'), ['oferta']);
$r = nova($pdo, ['titulo' => 'Story rápido', 'marca' => 'intusfit', 'formato' => 'story', 'tipo' => 'bastidor', 'drive_link' => 'https://drive.google.com/s', 'status' => 'aprovado'], $adm);
eq('story pode ir sem legenda', $r['ok'], true);
$r = nova($pdo, ['titulo' => 'Live de sábado', 'marca' => 'luiznunest', 'formato' => 'live', 'tipo' => 'engajamento', 'gancho' => 'Tema da live', 'legenda' => 'Entra aqui', 'status' => 'revisao'], $adm);
eq('formato sem mídia (live) não exige vídeo', $r['ok'], true);
$r = nova($pdo, ['titulo' => 'Link ruim', 'drive_link' => 'javascript:alert(1)', 'oferta' => '../../x', 'cut_projeto' => '../x', 'arquivos' => ['ok-123', '../x', 'ok-123']], $adm);
eq('link e ids malformados são descartados', [$r['peca']['drive_link'], $r['peca']['oferta'], $r['peca']['cut_projeto'], $r['peca']['arquivos']], ['', '', '', ['ok-123']]);
$r = nova($pdo, ['titulo' => 'Datas', 'data' => '2026-02-31', 'hora' => '25:00'], $adm);
eq('data e hora impossíveis viram vazio', [$r['peca']['data'], $r['peca']['hora']], ['', '']);
$r = nova($pdo, ['id' => 'inexistente', 'titulo' => 'x'], $adm);
eq('atualizar peça inexistente: 404', [$r['ok'], $r['codigo']], [false, 404]);

echo "\n══ Copiar, excluir, eventos ══\n";
$r = cont_duplicar_peca($pdo, $id, $eqp);
eq('duplicar nasce em ideia, sem data', [$r['ok'], $r['peca']['status'], $r['peca']['data']], [true, 'ideia', '']);
$cop = $r['peca']['id'];
eq('excluir some', [cont_excluir_peca($pdo, $cop), isset(cont_pecas($pdo)[$cop]), cont_excluir_peca($pdo, $cop)], [true, false, false]);
$e = cont_salvar_evento($pdo, ['titulo' => 'Gravação LDA', 'tipo' => 'gravacao', 'data' => '2026-11-04', 'marca' => 'luiznunest', 'hora' => '09:00'], $adm);
eq('evento salvo', [$e['ok'], $e['evento']['tipo']], [true, 'gravacao']);
eq('evento sem dia é recusado', cont_salvar_evento($pdo, ['titulo' => 'x'], $adm)['ok'], false);
$e2 = cont_salvar_evento($pdo, ['id' => $e['evento']['id'], 'data_fim' => '2026-11-03', 'tipo' => 'bobagem'], $adm);
eq('fim antes do início é ignorado; tipo inválido vira "outro"', [$e2['evento']['data_fim'], $e2['evento']['tipo']], ['', 'outro']);
eq('apagar evento', [cont_excluir_evento($pdo, $e['evento']['id']), count(cont_eventos($pdo))], [true, 0]);
cont_salvar_evento($pdo, ['titulo' => 'Copa Oeste', 'tipo' => 'campeonato', 'data' => '2026-11-07', 'data_fim' => '2026-11-08'], $adm);

echo "\n══ Reciclar ══\n";
$pecas = cont_pecas($pdo);
$pub = nova($pdo, ['titulo' => 'Aquele reel que bombou', 'marca' => 'luiznunest', 'formato' => 'reel', 'tipo' => 'educativo', 'gancho' => 'g', 'cut_projeto' => 'reel-bom', 'legenda' => 'Salve este treino.', 'status' => 'publicado', 'data' => '2026-08-01',
    'resultado' => ['alcance' => 10000, 'curtidas' => 500, 'comentarios' => 100, 'salvos' => 300, 'compart' => 100]], $adm, null, true);
$pid = $pub['peca']['id'];
eq('pontuação de engajamento (curtir 1, comentar 2, salvar 3, enviar 3)', $pub['peca']['resultado']['salvos'] === 300 ? cont_score($pub['peca']) : -1, 19.0);
$rec = cont_reciclagem(cont_pecas($pdo), cont_cfg($pdo), $HOJE);
eq('publicada há mais de 60 dias e com bom resultado é sugerida', array_column($rec['sugeridas'], 'id'), [$pid]);
nova($pdo, ['id' => $pid, 'reciclar' => ['marcar' => 'sim']], $adm);
$rec = cont_reciclagem(cont_pecas($pdo), cont_cfg($pdo), $HOJE);
eq('marcada: pronta depois do prazo e deixa de ser sugestão', [count($rec['marcadas']), $rec['marcadas'][0]['pronta'], count($rec['sugeridas'])], [1, true, 0]);
nova($pdo, ['id' => $pid, 'reciclar' => ['marcar' => 'sim', 'em' => '2026-12-15']], $adm);
$rec = cont_reciclagem(cont_pecas($pdo), cont_cfg($pdo), $HOJE);
eq('data de volta escolhida pela pessoa vale', [$rec['marcadas'][0]['quando'], $rec['marcadas'][0]['pronta']], ['2026-12-15', false]);
eq('reciclar peça que não foi publicada: 422', cont_reciclar_peca($pdo, $dep, $adm, $HOJE)['codigo'], 422);
$rc = cont_reciclar_peca($pdo, $pid, $adm, $HOJE);
eq('reciclar cria ideia ligada à original', [$rc['ok'], $rc['peca']['status'], $rc['peca']['reciclar']['origem'], $rc['peca']['titulo']], [true, 'ideia', $pid, 'Aquele reel que bombou (reciclagem)']);
eq('a nova leva o vídeo e o resultado começa zerado', [$rc['peca']['cut_projeto'], $rc['peca']['resultado']['alcance']], ['reel-bom', 0]);
$orig = cont_pecas($pdo)[$pid];
eq('a original anota quando foi reciclada e sai da fila', [$orig['reciclar']['reciclada_em'], $orig['reciclar']['marcar']], [$HOJE, 'nao']);
$rec = cont_reciclagem(cont_pecas($pdo), cont_cfg($pdo), $HOJE);
eq('depois de reciclar a contagem recomeça (não é sugerida de novo)', count($rec['sugeridas']), 0);
$rc2 = cont_reciclar_peca($pdo, $pid, $adm, $HOJE);
eq('reciclar de novo não repete o sufixo', $rc2['peca']['titulo'], 'Aquele reel que bombou (reciclagem)');

echo "\n══ Painel ══\n";
$cfg = cont_cfg($pdo); $of = cont_ofertas($pdo); $pecas = cont_pecas($pdo); $ev = cont_eventos($pdo);
$p = cont_painel($pecas, $ev, $cfg, $of, $HOJE);
eq('semana de domingo a sábado', $p['semana']['atual'], ['2026-11-01', '2026-11-07']);
eq('próxima semana', $p['semana']['proxima'], ['2026-11-08', '2026-11-14']);
eq('total de peças bate com o banco', [$p['total'], array_sum($p['por_status'])], [count($pecas), count($pecas)]);
eq('cadência da semana: agendadas contam para o perfil e a meta vem da configuração', [$p['cadencia']['codigodadefinicao']['atual']['planejadas'], $p['cadencia']['codigodadefinicao']['meta']], [1, 3]);
eq('alerta de semana que vem sem posts', count(array_filter($p['alertas'], fn($a) => str_contains($a, 'semana que vem'))) >= 1, true);
eq('agenda traz o evento de fim de semana e as peças dos próximos dias', in_array('Copa Oeste', array_column($p['agenda'], 'titulo'), true) && in_array('Depoimento da aluna', array_column($p['agenda'], 'titulo'), true), true);
$ref = $pecas[$id];
$atras = nova($pdo, ['titulo' => 'Atrasada', 'marca' => 'intusfit', 'data' => '2026-10-20'], $adm);
$p = cont_painel(cont_pecas($pdo), cont_eventos($pdo), $cfg, $of, $HOJE);
eq('peça com dia vencido e ainda não publicada é atrasada', in_array($atras['peca']['id'], array_column($p['atrasadas'], 'id'), true), true);
// 5 posts de venda em 5 agendados: passa do limite de 30%
$pecasVenda = [];
for ($i = 0; $i < 6; $i++) {
    $x = nova($pdo, ['titulo' => "Venda $i", 'marca' => 'intusfit', 'formato' => 'imagem', 'tipo' => 'oferta', 'drive_link' => 'https://drive.google.com/v', 'legenda' => 'Vem.', 'oferta' => 'teste-gratis', 'status' => 'agendado', 'data' => '2026-10-' . (10 + $i), 'hora' => '10:00'], $adm);
    $pecasVenda[] = $x['ok'];
}
$p = cont_painel(cont_pecas($pdo), cont_eventos($pdo), $cfg, $of, $HOJE);
eq('muita venda na grade dispara o alerta', [count(array_filter($pecasVenda)) === 6, $p['venda_pct'] > 30, count(array_filter($p['alertas'], fn($a) => str_contains($a, 'de venda') || str_contains($a, 'Posts de venda'))) >= 1], [true, true, true]);
// peça parada
$pecasTodas = cont_pecas($pdo);
$pecasTodas[$id]['status'] = 'revisao'; $pecasTodas[$id]['status_em'] = '2026-10-01T10:00:00';
$p = cont_painel($pecasTodas, [], $cfg, $of, $HOJE);
eq('peça há mais de 7 dias na mesma etapa é parada', in_array($id, array_column($p['paradas'], 'id'), true), true);
$n = cont_negocio($pdo, $HOJE, 30);
eq('negócio: tabelas que não existem viram "sem dado", sem erro', [$n['leads_intus'], $n['leads_cdd'], $n['vendas_cdd']], [null, null, null]);
$pdo->exec('CREATE TABLE cdd_pagamento (idpagamento INTEGER PRIMARY KEY, status TEXT, valor REAL, pago_em TEXT)');
$pdo->exec("INSERT INTO cdd_pagamento (status, valor, pago_em) VALUES ('pago', 698, '2026-10-25 10:00:00'), ('pago', 119, '2026-10-30 09:00:00'), ('reembolsado', 119, '2026-10-30 09:00:00'), ('pago', 119, '2026-08-01 09:00:00')");
$n = cont_negocio($pdo, $HOJE, 30);
eq('vendas do CDD: só pagas dos últimos 30 dias', [$n['vendas_cdd'], $n['receita_cdd']], [2, 817.0]);

echo "\n══ Resumo da lista e fila de postagem ══\n";
$res = cont_peca_resumo(cont_pecas($pdo)[$id], $cfg, $of, $HOJE);
eq('a lista não leva roteiro, legenda nem histórico', array_intersect(['roteiro', 'legenda', 'historico', 'notas'], array_keys($res)), []);
eq('mas diz se tem legenda e vídeo', [$res['tem_legenda'], $res['tem_midia']], [true, true]);
$ex = cont_exportar($pdo, $HOJE);
eq('fila: só peças agendadas, em ordem de dia e hora', $ex['fila'][0]['data'] <= end($ex['fila'])['data'] && count($ex['fila']) >= 6, true);
$d = array_values(array_filter($ex['fila'], fn($x) => $x['id'] === $dep))[0];
eq('fila traz o perfil, o texto final e que a publicação é manual', [$d['perfil'], $d['publicacao']['modo'], str_contains($d['texto'], 'rotina')], ['@codigodadefinicao', 'manual', true]);
nova($pdo, ['id' => $dep, 'autorizacao_imagem' => 'pendente'], $adm);
$d = array_values(array_filter(cont_exportar($pdo, $HOJE)['fila'], fn($x) => $x['id'] === $dep))[0];
eq('peça agendada que perdeu uma exigência aparece como "não pronta"', [$d['pronta'], count($d['pendencias'])], [false, 1]);
$ex2 = nova($pdo, ['titulo' => 'Com oferta', 'marca' => 'codigodadefinicao', 'formato' => 'imagem', 'tipo' => 'oferta', 'drive_link' => 'https://drive.google.com/o', 'legenda' => 'Conheça.', 'oferta' => 'cdd-anual', 'status' => 'agendado', 'data' => '2026-11-09', 'hora' => '08:00'], $adm);
$d = array_values(array_filter(cont_exportar($pdo, $HOJE)['fila'], fn($x) => $x['id'] === $ex2['peca']['id']))[0];
eq('fila leva o link da oferta', $d['oferta']['link'], 'https://chk.eduzz.com/1344531');
$pub2 = cont_publicar_peca($pdo, $ex2['peca']['id'], ['link_publicado' => 'https://www.instagram.com/p/abc/'], $adm, $HOJE);
eq('publicar guarda o link e sai da fila', [$pub2['peca']['status'], $pub2['peca']['resultado']['link_publicado'], in_array($ex2['peca']['id'], array_column(cont_exportar($pdo, $HOJE)['fila'], 'id'), true)], ['publicado', 'https://www.instagram.com/p/abc/', false]);

echo "\n══ Ofertas e links ══\n";
eq('oferta com link inválido é recusada', isset(cont_ofertas_limpar([['nome' => 'X', 'link' => 'ftp://x']], $cfg)['erro']), true);
eq('duas ofertas com o mesmo nome são recusadas', isset(cont_ofertas_limpar([['nome' => 'X'], ['nome' => 'x']], $cfg)['erro']), true);
$o = cont_ofertas_limpar([['nome' => 'Nova', 'marca' => 'inexistente', 'link' => 'https://exemplo.com/a', 'ativo' => false]], $cfg);
eq('perfil inexistente vira "sem perfil" e o desligar vale', [$o['ok'][0]['marca'], $o['ok'][0]['ativo']], ['', false]);
eq('link sem endereço é recusado', isset(cont_links_limpar([['nome' => 'Pasta', 'url' => 'nao-e-link']])['erro']), true);
eq('links de fábrica incluem as pastas do Drive', count(array_filter(cont_links($pdo), fn($l) => $l['grupo'] === 'Pastas do Drive')), 6);
eq('pasta de aluno avisa sobre autorização', str_contains(array_values(array_filter(cont_links($pdo), fn($l) => $l['id'] === 'drive-antes-cdd'))[0]['obs'], 'autorização'), true);

echo "\n══ Intus Cut ══\n";
cut_salvar($pdo, ['id' => 'reel-bom', 'nome' => 'Reel bom', 'formato' => 'reel', 'trechos' => [['ini' => 0, 'fim' => 5]]], $adm);
cut_bib_salvar($pdo, ['id' => 'bib-exp-01', 'tipo' => 'video', 'nome' => 'Reel bom exportado', 'url' => '/app/api/midia.php?action=video&t=' . str_repeat('ab', 16), 'exportado' => true], $adm);
$c = cont_do_cut($pdo);
eq('a tela vê os projetos do Cut', array_column($c['projetos'], 'id'), ['reel-bom']);
eq('e os vídeos exportados da biblioteca', [$c['biblioteca'][0]['id'], $c['biblioteca'][0]['exportado']], ['bib-exp-01', true]);
$x = nova($pdo, ['titulo' => 'Com arquivo do Cut', 'marca' => 'intusfit', 'formato' => 'reel', 'gancho' => 'g', 'arquivos' => ['bib-exp-01'], 'status' => 'revisao'], $adm);
eq('arquivo da biblioteca conta como mídia pronta', $x['ok'], true);

echo "\n";
echo $ruim === 0 ? "TUDO OK ($ok conferências)\n" : "$ruim FALHA(S) ($ok ok)\n";
exit($ruim === 0 ? 0 : 1);
