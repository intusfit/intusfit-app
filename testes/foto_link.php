<?php
// Links assinados das fotos de avaliacao (Drive e arquivo local): prazo, adulteracao e caminho de volta.
//   php testes/foto_link.php
// Carrega _gdrive.php sem banco: o segredo vem de um dublê.
$src = file_get_contents(__DIR__ . '/../app/api/_gdrive.php');
$src = preg_replace('/^<\?php/', '', $src, 1);
$src = str_replace('function gdriveFotoSegredo() {', 'function gdriveFotoSegredo_original() {', $src);
eval($src);
function gdriveFotoSegredo() { return str_repeat('k', 64); }
$_SERVER['SCRIPT_NAME'] = '/app/api/catalogo.php';
$ok = 0; $ruim = 0;
function t($rot, $cond) { global $ok, $ruim; if ($cond) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot\n"; } }

$arq = 'av_1787157741_0e0f3c41922a.jpg';
$u = avFotoUrl('https://intusfit.com.br/app/img/avaliacoes/' . $arq);
t('foto local vira foto_av.php com prazo e assinatura', (bool)preg_match('#^/app/api/foto_av\.php\?f=' . preg_quote($arq) . '&e=\d{10}&s=[0-9a-f]{32}$#', (string)$u));
parse_str(parse_url($u, PHP_URL_QUERY), $q);
t('assinatura confere', fotoLinkConfere('a', $q['f'], $q['e'], $q['s']));
t('outro arquivo com a mesma assinatura e recusado', !fotoLinkConfere('a', 'av_1_aaaa.jpg', $q['e'], $q['s']));
t('prazo estendido e recusado', !fotoLinkConfere('a', $q['f'], $q['e'] + 86400, $q['s']));
t('tipo trocado (link de arquivo usado como Drive) e recusado', !fotoLinkConfere('d', $q['f'], $q['e'], $q['s']));
$exp = time() - 5; $sig = fotoLinkAssinar('a', $arq, $exp);
t('link vencido e recusado', !fotoLinkConfere('a', $arq, $exp, $sig));
$exp = time() + 10 * 86400; $sig = fotoLinkAssinar('a', $arq, $exp);
t('prazo muito longo (forjado com segredo certo) e recusado', !fotoLinkConfere('a', $arq, $exp, $sig));
t('caminho de volta: link assinado vira o nome do arquivo', avIdDeLinkAssinado($u) === $arq);
t('caminho de volta funciona com o endereco completo do site', avIdDeLinkAssinado('https://intusfit.com.br' . $u) === $arq);
t('endereco guardado que nao e de avaliacao nao vira link', avFotoUrl('https://intusfit.com.br/app/img/feed/x.jpg') === null && avFotoUrl('../../etc/passwd') === null);
t('nome com barra ou ponto-ponto nao passa', avFotoUrl('/img/avaliacoes/../config/db.php') === null && avFotoUrl('/img/avaliacoes/av_1/../x.jpg') === null);

$d = gdriveFotoUrl('drive:1AbCdEfGhIjKlMnOp');
parse_str(parse_url($d, PHP_URL_QUERY), $qd);
t('Drive tambem ganha prazo', isset($qd['e']) && fotoLinkConfere('d', $qd['f'], $qd['e'], $qd['s']));
t('link do Drive sem prazo (formato antigo) nao tem como conferir', !fotoLinkConfere('d', $qd['f'], 0, $qd['s']));
t('caminho de volta do Drive', gdriveIdDeLinkAssinado($d) === '1AbCdEfGhIjKlMnOp');

$lista = fotosParaCliente(['https://intusfit.com.br/app/img/avaliacoes/' . $arq, 'drive:1AbCdEfGhIjKlMnOp', ['url' => '/app/img/avaliacoes/' . $arq], 'https://outro.site/x.jpg']);
t('lista: local, Drive e objeto viram links; URL externa fica como esta', strpos($lista[0], 'foto_av.php') !== false && strpos($lista[1], 'foto_drive.php') !== false && strpos($lista[2], 'foto_av.php') !== false && $lista[3] === 'https://outro.site/x.jpg');
echo "\n" . ($ruim ? "$ruim FALHA(S), " : '') . "$ok ok\n";
exit($ruim ? 1 : 0);
