<?php
// Segurança dos envios de imagem: o conteúdo precisa ser MESMO uma imagem (catalogo.php, _intusExtImagem).
//   php -c <php.ini com gd> testes/seguranca.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }
$cod = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/catalogo.php'));
$i = strpos($cod, 'function _intusExtImagem'); $j = strpos($cod, "\n}\n", $i);
require_once __DIR__ . '/../app/api/_antivirus.php';
eval(substr($cod, $i, $j - $i + 3));

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
eq('PNG de verdade é aceito e volta "png"', _intusExtImagem($png) === 'png');
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(4, 4); ob_start(); imagejpeg($im); $jpg = ob_get_clean();
    eq('JPEG de verdade é aceito e volta "jpg"', _intusExtImagem($jpg) === 'jpg');
    eq('JPEG declarado como PNG recebe a extensão real (jpg)', _intusExtImagem($jpg) !== 'png');
}
eq('texto disfarçado de imagem é recusado', _intusExtImagem('isto nao e uma imagem') === '');
eq('código PHP disfarçado de imagem é recusado', _intusExtImagem("<?php system(\$_GET['c']); ?>") === '');
eq('HTML com script é recusado', _intusExtImagem('<html><script>alert(1)</script></html>') === '');
eq('SVG não é aceito (pode carregar script)', _intusExtImagem('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') === '');
eq('PNG com código PHP escondido no fim é recusado (poliglota)', _intusExtImagem($png . '<?php echo 1; ?>') === '');
eq('PNG com <script> escondido é recusado', _intusExtImagem($png . '<script>alert(1)</script>') === '');
eq('PNG com o arquivo de teste de antivírus (EICAR) é recusado', _intusExtImagem($png . 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*') === '');
eq('intusAntivirus: imagem limpa passa', intusAntivirus($png) === '');
eq('intusAntivirus: motivo em português quando recusa', strpos(intusAntivirus($png . '<?php x ?>'), 'código escondido') !== false);
eq('vazio é recusado', _intusExtImagem('') === '');

echo "\n── Foto legítima não pode ser recusada por acaso ──\n";
foreach (['<?=', '<%=', '<%@'] as $m) {
    eq("marca $m solta no meio dos bytes de uma foto passa", !intusCodigoEscondido("\xFF\xD8\x8a\x01" . $m . "\x9c\x00\xe3ab\xf1\x10") && !intusCodigoEscondido("\x00" . $m . "Kd3\x80\x91zz\x02"));
}
mt_srand(2711); $falsas = 0;
for ($n = 0; $n < 120; $n++) {   // 120 "fotos" de 256 KB com bytes aleatórios (repetível pela semente)
    $b = ''; for ($k = 0; $k < 65536; $k++) $b .= pack('N', (mt_rand(0, 0xFFFF) << 16) | mt_rand(0, 0xFFFF));
    if (intusCodigoEscondido($b)) $falsas++;
}
eq('nenhuma de 120 fotos simuladas é recusada', $falsas === 0, "$falsas recusadas");
foreach (['<?=`$_GET[0]`?>', '<?=`id`?>', '<?= system($_GET["c"]);', '<?=`id`;?>', '<%= Request("c") %>', '<%@ Page Language="C#" %>', '<%=x%>', '<?PHP eval($x);', '<SCRIPT src=x>', "#!/bin/sh\nrm x"] as $p) {
    eq('código escondido continua recusado: ' . trim(strtok($p, "\n")), intusCodigoEscondido("\xFF\xD8\x8a\x01" . $p) && intusCodigoEscondido("\xFF\xD8" . $p . "\xFF\xD9"));
}
echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
