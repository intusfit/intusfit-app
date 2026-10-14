<?php
// Segurança dos envios de imagem: o conteúdo precisa ser MESMO uma imagem (catalogo.php, _intusExtImagem).
//   php -c <php.ini com gd> testes/seguranca.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }
$cod = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/catalogo.php'));
$i = strpos($cod, 'function _intusExtImagem'); $j = strpos($cod, "\n}\n", $i);
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
eq('PNG com lixo PHP no fim continua PNG (o arquivo é guardado como .png e nunca executa)', _intusExtImagem($png . '<?php echo 1; ?>') === 'png');
eq('vazio é recusado', _intusExtImagem('') === '');
echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
