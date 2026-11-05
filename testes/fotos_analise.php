<?php
// Análise das fotos de avaliação (app/api/_fotos_analise.php), em PHP de verdade com SQLite.
//   php -c <php.ini com pdo_sqlite, sqlite3> testes/fotos_analise.php
require __DIR__ . '/../app/api/_fotos_analise.php';
$ok = 0; $ruim = 0;
function t($nome, $cond) { global $ok, $ruim; if ($cond) { $ok++; } else { $ruim++; echo "FALHOU: $nome\n"; } }

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lm = []; for ($i = 0; $i < 33; $i++) $lm[] = [0.5, 0.1 + $i * 0.02, 0.0, 0.9];
$item = function ($chave, $pose, $conf = 0.9, $origem = 'auto') use ($lm) {
    return ['chave' => $chave, 'idavaliacao' => 7, 'pose' => $pose, 'conf' => $conf, 'origem' => $origem, 'w' => 900, 'h' => 1200, 'bb' => [0.2, 0.05, 0.8, 0.97], 'lm' => $lm];
};

// grava e lê de volta
t('grava duas fotos', fotoAnaliseGravar($pdo, 5, [$item('a:av_1_aaaaaa.jpg', 1), $item('a:av_2_bbbbbb.jpg', 8)]) === 2);
$l = fotoAnaliseListar($pdo, 5);
t('lista as duas', count($l) === 2);
t('pose lida', $l[0]['pose'] === 1 && $l[1]['pose'] === 8);
t('caixa lida como números', $l[0]['bb'] === [0.2, 0.05, 0.8, 0.97]);
t('33 pontos lidos', is_array($l[0]['lm']) && count($l[0]['lm']) === 33 && count($l[0]['lm'][0]) === 3);
t('outro aluno não enxerga', count(fotoAnaliseListar($pdo, 6)) === 0);

// regravar a mesma foto atualiza, não duplica
fotoAnaliseGravar($pdo, 5, [$item('a:av_1_aaaaaa.jpg', 4, 0.8)]);
$l = fotoAnaliseListar($pdo, 5);
t('não duplica', count($l) === 2);
t('atualiza a posição automática', $l[0]['pose'] === 4 && abs($l[0]['conf'] - 0.8) < 0.001);

// correção manual vale mais que a automática
t('define pose manual', fotoAnaliseDefinirPose($pdo, 5, 'a:av_1_aaaaaa.jpg', 9));
fotoAnaliseGravar($pdo, 5, [$item('a:av_1_aaaaaa.jpg', 2, 0.99)]);
$l = fotoAnaliseListar($pdo, 5);
t('análise automática NÃO sobrescreve a manual', $l[0]['pose'] === 9 && $l[0]['origem'] === 'manual');
t('manual tem confiança 1', abs($l[0]['conf'] - 1.0) < 0.001);
fotoAnaliseGravar($pdo, 5, [$item('a:av_1_aaaaaa.jpg', 3, 1, 'manual')]);
t('manual sobrescreve manual', fotoAnaliseListar($pdo, 5)[0]['pose'] === 3);

// pose manual numa foto que ainda não tinha análise
t('manual em foto nova', fotoAnaliseDefinirPose($pdo, 5, 'd:1AbCdEfGhIjKlM', 12, 7));
t('foto nova listada', count(fotoAnaliseListar($pdo, 5)) === 3);
t('"sem posição" (null)', fotoAnaliseDefinirPose($pdo, 5, 'd:1AbCdEfGhIjKlM', null) && fotoAnaliseListar($pdo, 5)[2]['pose'] === null);

// validações
t('pose 19 recusada', fotoAnaliseDefinirPose($pdo, 5, 'a:av_9_cccccc.jpg', 19) === false);
t('pose 0 recusada', fotoAnaliseDefinirPose($pdo, 5, 'a:av_9_cccccc.jpg', 0) === false);
t('chave com ../ recusada', fotoAnaliseDefinirPose($pdo, 5, 'a:../../etc/passwd', 3) === false);
t('chave sem prefixo recusada', fotoAnaliseDefinirPose($pdo, 5, 'av_9_cccccc.jpg', 3) === false);
t('item lixo ignorado', fotoAnaliseGravar($pdo, 5, ['lixo', ['chave' => 'x'], $item('a:av_3_dddddd.jpg', 99)]) === 1);
t('pose fora de 1..18 vira sem posição', fotoAnaliseListar($pdo, 5)[3]['pose'] === null);
$ruimLm = $item('a:av_4_eeeeee.jpg', 1); $ruimLm['lm'] = [[0, 0, 0, 1]];
fotoAnaliseGravar($pdo, 5, [$ruimLm]);
$ult = array_values(array_filter(fotoAnaliseListar($pdo, 5), function ($x) { return $x['chave'] === 'a:av_4_eeeeee.jpg'; }))[0];
t('pontos incompletos descartados', $ult['lm'] === null && $ult['pose'] === 1);
$foraDaFaixa = $item('a:av_5_ffffff.jpg', 1); $foraDaFaixa['bb'] = [0, 0, 99, 1];
fotoAnaliseGravar($pdo, 5, [$foraDaFaixa]);
$ult = array_values(array_filter(fotoAnaliseListar($pdo, 5), function ($x) { return $x['chave'] === 'a:av_5_ffffff.jpg'; }))[0];
t('caixa fora da faixa descartada', $ult['bb'] === null);
$muitos = []; for ($i = 0; $i < 80; $i++) $muitos[] = $item("a:av_m{$i}_zzzzzz.jpg", 1);
t('limite de itens por chamada', fotoAnaliseGravar($pdo, 8, $muitos) === FOTO_ANALISE_MAX_ITENS);

// remover
fotoAnaliseRemover($pdo, 5, 'a:av_2_bbbbbb.jpg');
t('remove só a foto pedida', count(array_filter(fotoAnaliseListar($pdo, 5), function ($x) { return $x['chave'] === 'a:av_2_bbbbbb.jpg'; })) === 0);
fotoAnaliseRemover($pdo, 6, 'a:av_1_aaaaaa.jpg');
t('remover de outro aluno não apaga', count(array_filter(fotoAnaliseListar($pdo, 5), function ($x) { return $x['chave'] === 'a:av_1_aaaaaa.jpg'; })) === 1);

echo "$ok ok, $ruim falhas\n";
exit($ruim ? 1 : 0);
