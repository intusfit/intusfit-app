<?php
/**
 * Intus Cut (app/api/_intuscut.php): quem acessa, o que o projeto pode guardar, biblioteca, envio e IA, em SQLite na memória.
 *   php testes/intuscut.php
 */
require_once __DIR__ . '/../app/api/_intuscut.php';

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
// permissão de mentira (a de verdade lê o cadastro do usuário)
$PERMS = [5 => ['cut_ver' => true], 6 => []];
function usuarioTemPermissao(PDO $pdo, int $id, string $k): bool { global $PERMS; return !empty($PERMS[$id][$k]); }
function wa_segredo($pdo, $k) { global $CHAVE; return $CHAVE ? ['api_key' => $CHAVE] : null; }
function wa_ajustes($pdo) { return ['modelo' => 'claude-opus-5-5']; }
$CHAVE = '';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$adm = ['idusuario' => 1, 'admin' => true, 'nmusuario' => 'Luiz Nunes'];
$prof = ['idusuario' => 5, 'nmusuario' => 'Prof Ana'];

echo "\n══ Quem pode usar ══\n";
eq('administrador', cut_pode($pdo, $adm), true);
eq('professor com cut_ver', cut_pode($pdo, $prof), true);
eq('professor sem cut_ver', cut_pode($pdo, ['idusuario' => 6]), false);
eq('aluno nunca, nem com id de admin', cut_pode($pdo, ['idusuario' => 1, 'admin' => true, 'is_aluno' => true]), false);
eq('sem login', cut_pode($pdo, []), false);

echo "\n══ Endereços que o projeto pode guardar ══\n";
$tok = str_repeat('a1', 16);
eq('vídeo do Drive da Intus', cut_url_ok('/app/api/midia.php?action=video&t=' . $tok), true);
eq('imagem enviada', cut_url_ok('/app/img/cut/cut_20261027_ab12.png'), true);
eq('logo do painel', cut_url_ok('/app/painel/logo-intus-branco.png'), true);
eq('link externo recusado', cut_url_ok('https://exemplo.com/v.mp4'), false);
eq('blob do aparelho recusado', cut_url_ok('blob:https://intusfit.com.br/x'), false);
eq('caminho que sai da pasta recusado', cut_url_ok('/app/img/cut/../../config/db.php'), false);
eq('script do painel recusado', cut_url_ok('/app/painel/api.js'), false);
eq('token curto recusado', cut_url_ok('/app/api/midia.php?action=video&t=abc'), false);

echo "\n══ Projetos ══\n";
$mid = ['video' => '/app/api/midia.php?action=video&t=' . $tok, 'voz' => '/app/api/midia.php?action=video&t=' . $tok, 'musica' => ''];
$p = ['id' => 'ric-02-treino-em-casa', 'nome' => 'Treino em casa', 'formato' => 'reel', 'trechos' => [['ini' => 1, 'fim' => 5]], 'midia' => $mid, 'palavras' => [['p' => 'Olá', 'i' => 1, 'f' => 1.3, 'fq' => 1]], 'render' => ['x' => 1]];
eq('salva', cut_salvar($pdo, $p, $prof)['ok'], true);
$lido = cut_projeto($pdo, 'ric-02-treino-em-casa');
eq('lê com a legenda editada', $lido['palavras'][0]['p'] ?? null, 'Olá');
eq('não guarda o render antigo', isset($lido['render']), false);
eq('lista mostra quem salvou', cut_lista($pdo)[0]['por_nome'] ?? null, 'Prof Ana');
eq('id inválido', cut_salvar($pdo, ['id' => '../x'] + $p, $prof)['ok'], false);
eq('sem trechos', cut_salvar($pdo, ['trechos' => []] + $p, $prof)['ok'], false);
eq('vídeo de fora do sistema recusado', cut_salvar($pdo, ['midia' => ['video' => 'https://x.com/a.mp4']] + $p, $prof)['ok'], false);
eq('vídeo de fora numa fonte de montagem recusado', cut_salvar($pdo, ['fontes' => ['k' => ['midia' => ['voz' => 'http://x/a.m4a']]]] + $p, $prof)['ok'], false);
eq('imagem de fora recusada', cut_salvar($pdo, ['imagens' => [['url' => 'https://x/a.png']]] + $p, $prof)['ok'], false);
$grande = $p; $grande['palavras'] = array_fill(0, 90000, ['p' => 'palavra', 'i' => 1, 'f' => 2]);
eq('projeto acima de 2 MB recusado', cut_salvar($pdo, $grande, $prof)['ok'], false);
$p2 = $p; $p2['nome'] = 'Outro'; cut_salvar($pdo, $p2, $adm);
eq('quem criou continua o mesmo ao outro salvar', cut_lista($pdo)[0]['criado_por'] ?? null, 5);
eq('excluir', cut_excluir($pdo, 'ric-02-treino-em-casa'), true);
eq('some da lista', count(cut_lista($pdo)), 0);
eq('e do banco', cut_projeto($pdo, 'ric-02-treino-em-casa'), null);

echo "\n══ Biblioteca ══\n";
$r = cut_bib_salvar($pdo, ['tipo' => 'video', 'nome' => 'React', 'url' => $mid['video'], 'dur' => 12.345, 'react' => 1, 'rascunho' => 1], $prof);
eq('guarda', $r['ok'], true);
eq('rascunho e react', [$r['item']['rascunho'], $r['item']['react']], [true, true]);
$r2 = cut_bib_salvar($pdo, ['rascunho' => false] + $r['item'], $adm);
eq('atualizar mantém o autor', $r2['item']['por_nome'], 'Prof Ana');
eq('atualizar não duplica', count(cut_bib($pdo)), 1);
eq('tipo inválido', cut_bib_salvar($pdo, ['tipo' => 'exe', 'url' => $mid['video']], $prof)['ok'], false);
eq('link externo', cut_bib_salvar($pdo, ['tipo' => 'foto', 'url' => 'https://x/a.png'], $prof)['ok'], false);
eq('tira', cut_bib_excluir($pdo, $r['item']['id'])['nome'] ?? null, 'React');
eq('biblioteca vazia', count(cut_bib($pdo)), 0);

echo "\n══ Painel de vídeos: situação ══\n";
cut_salvar($pdo, $p, $prof);
eq('situação inválida recusada', cut_status($pdo, ['id' => $p['id'], 'status' => 'xyz'], $adm)['ok'], false);
eq('projeto que não existe recusado', cut_status($pdo, ['id' => 'nao-existe', 'status' => 'pronto'], $adm)['ok'], false);
eq('agendado sem data recusado', cut_status($pdo, ['id' => $p['id'], 'status' => 'agendado'], $adm)['ok'], false);
$r = cut_status($pdo, ['id' => $p['id'], 'status' => 'postado', 'redes' => ['instagram', 'orkut'], 'link' => 'https://instagram.com/p/abc', 'postado_em' => '2026-10-30'], $adm);
eq('marca como postado', $r['ok'], true);
eq('rede desconhecida descartada', $r['item']['redes'] ?? null, ['instagram']);
eq('link http recusado vira vazio', cut_status($pdo, ['id' => $p['id'], 'status' => 'postado', 'link' => 'javascript:alert(1)'], $adm)['item']['link'] ?? null, '');
cut_status($pdo, ['id' => $p['id'], 'status' => 'postado', 'link' => 'https://instagram.com/p/abc'], $adm);
$p3 = $p; $p3['nome'] = 'Editado de novo'; cut_salvar($pdo, $p3, $prof);
$l = cut_lista($pdo)[0];
eq('salvar o projeto não apaga a situação', $l['status'] ?? null, 'postado');
eq('salvar o projeto não apaga o link', $l['link'] ?? null, 'https://instagram.com/p/abc');
eq('nome atualizado', $l['nome'] ?? null, 'Editado de novo');
cut_excluir($pdo, $p['id']);

echo "\n══ Envio de vídeo ══\n";
eq('mp4 do celular', cut_validar_envio(['mime' => 'video/mp4', 'tamanho' => 5e8, 'duracao' => 60])[0] ?? null, 'video/mp4');
eq('gravação webm com codecs', cut_validar_envio(['mime' => 'video/webm;codecs=vp9,opus', 'tamanho' => 1000])[0] ?? null, 'video/webm');
eq('mov do iPhone', cut_validar_envio(['mime' => 'video/quicktime', 'tamanho' => 1000])[0] ?? null, 'video/quicktime');
eq('formato estranho', is_string(cut_validar_envio(['mime' => 'application/x-php', 'tamanho' => 10])), true);
eq('acima de 2 GB', is_string(cut_validar_envio(['mime' => 'video/mp4', 'tamanho' => 3e9])), true);
eq('vazio', is_string(cut_validar_envio(['mime' => 'video/mp4', 'tamanho' => 0])), true);
eq('token da url', cut_token_de_url($mid['video']), $tok);

echo "\n══ IA ══\n";
eq('sem chave cadastrada', cut_ia($pdo, 'monte', fn() => [])['ok'], false);
$CHAVE = 'sk-teste';
$chamadas = [];
$http = function ($corpo, $k, $fb) use (&$chamadas) { $chamadas[] = json_decode($corpo, true); return ['status' => 200, 'json' => ['content' => [['type' => 'text', 'text' => '{"nome":"Corte","trechos":[{"fonte":"","ini":1,"fim":4}]}']]]]; };
$r = cut_ia($pdo, 'monte um reel', $http);
eq('responde o JSON', $r['resposta']['nome'] ?? null, 'Corte');
eq('modelo da Central', $chamadas[0]['model'], 'claude-opus-5-5');
eq('conta o uso do dia', cut_ia_uso_hoje($pdo), 1);
$n = 0; $http400 = function ($c, $k, $fb) use (&$n) { $n++; return $fb ? ['status' => 400, 'json' => ['error' => ['message' => 'unknown beta fallbacks']]] : ['status' => 200, 'json' => ['content' => [['type' => 'text', 'text' => '{"a":1}']]]]; };
eq('repete sem o fallback quando a API recusa', cut_ia($pdo, 'x', $http400)['ok'] && $n === 2, true);
eq('resposta que não é JSON', cut_ia($pdo, 'x', fn() => ['status' => 200, 'json' => ['content' => [['type' => 'text', 'text' => 'oi']]]])['ok'], false);
eq('chave recusada', cut_ia($pdo, 'x', fn() => ['status' => 401, 'json' => []])['erro'], 'A chave do Claude foi recusada.');
echo "\n══ IA com quadros do vídeo ══\n";
$jpg = 'data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF" . str_repeat('a', 300));
$png = 'data:image/png;base64,' . base64_encode("\x89PNG" . str_repeat('b', 300));
$falso = 'data:image/jpeg;base64,' . base64_encode('<?php echo 1; ?>' . str_repeat('c', 300));
$ims = cut_ia_imagens([$jpg, $png, $falso, 'https://x/a.jpg', 123, 'data:image/svg+xml;base64,AAAA']);
eq('aceita só JPEG e PNG de verdade', count($ims), 2);
eq('formato da imagem para o Claude', $ims[0]['source']['media_type'] ?? null, 'image/jpeg');
eq('no máximo ' . CUT_IA_QUADROS . ' quadros', count(cut_ia_imagens(array_fill(0, 40, $jpg))), CUT_IA_QUADROS);
eq('lista que não é lista', cut_ia_imagens('x'), []);
$chamadas = [];
cut_ia($pdo, 'monte com imagens', $http, [$jpg, $png]);
$cont = $chamadas[0]['messages'][0]['content'] ?? [];
eq('o pedido leva as imagens antes do texto', [$cont[0]['type'] ?? '', $cont[1]['type'] ?? '', $cont[2]['type'] ?? ''], ['image', 'image', 'text']);
eq('o texto do pedido vai junto', $cont[2]['text'] ?? '', 'monte com imagens');
cut_gravar($pdo, 'cut_ia_uso', [gmdate('Y-m-d') => CUT_IA_DIA]);
eq('limite do dia', cut_ia($pdo, 'x', $http)['ok'], false);

echo "\n══ O endpoint confere o login em toda ação ══\n";
$src = file_get_contents(__DIR__ . '/../app/api/intuscut.php');
eq('endpoint tem a rota de situação', strpos($src, "'status'") !== false && strpos($src, 'cut_status(') !== false, true);
eq('usa o contexto de login do painel', strpos($src, 'getAuthContext') !== false, true);
eq('recusa sem acesso ao Intus Cut', strpos($src, 'cut_pode(') !== false, true);
$posPode = strpos($src, 'cut_pode('); $posAcao = strpos($src, "'inicio'");
eq('a checagem vem antes das ações', $posPode !== false && $posAcao !== false && $posPode < $posAcao, true);

echo "\n$ok ok, $ruim falha(s)\n";
exit($ruim ? 1 : 0);
