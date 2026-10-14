<?php
/**
 * VERIFICAÇÃO DE ARQUIVOS ENVIADOS (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Camadas, da mais forte para a mais simples (honestidade sobre a hospedagem compartilhada: não há antivírus instalado nela, então a camada 1
 * só entra quando existir um ClamAV acessível; as outras funcionam em qualquer servidor):
 *   1. ClamAV (clamd, por socket local ou 127.0.0.1:3310), se existir no servidor. Usa as assinaturas reais de vírus.
 *   2. Assinatura do teste padrão de antivírus (EICAR), para provar que a verificação está ligada.
 *   3. Código escondido dentro do arquivo: abertura de PHP, script, ASP, shell. Foto de verdade nunca contém isso; um arquivo que contém
 *      é um "poliglota" (foto por fora, programa por dentro) e é recusado.
 * Os arquivos de upload ainda ficam em pastas que NÃO executam script (app/.htaccess) e só são aceitos se o conteúdo for imagem de verdade
 * (_intusExtImagem). Este arquivo é a terceira trava.
 *
 * intusAntivirus($bin): string   '' se o arquivo passou; senão o motivo (em português) da recusa.
 * intusAntivirusStatus(): array  o que está ativo neste servidor (para o painel).
 */

function _avClamd(string $bin): ?string
{
    if ($bin === '') return '';
    foreach (['unix:///var/run/clamav/clamd.ctl', 'unix:///run/clamav/clamd.ctl', 'unix:///var/run/clamd.scan/clamd.sock', 'unix:///tmp/clamd.socket', 'tcp://127.0.0.1:3310'] as $end) {
        $s = @stream_socket_client($end, $en, $es, 1);
        if (!$s) continue;
        stream_set_timeout($s, 10);
        fwrite($s, "zINSTREAM\0");
        foreach (str_split($bin, 65536) as $c) fwrite($s, pack('N', strlen($c)) . $c);
        fwrite($s, pack('N', 0));
        $r = trim((string)stream_get_contents($s));
        fclose($s);
        if (preg_match('/:\s*(.+?)\s+FOUND/', $r, $m)) return 'o antivírus identificou ' . $m[1];
        if (strpos($r, 'OK') !== false) return '';
    }
    return null;   // nenhum ClamAV acessível
}

function intusAntivirus(string $bin): string
{
    $av = _avClamd($bin);
    if ($av !== null && $av !== '') return $av;
    if (strpos($bin, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') !== false) return 'arquivo de teste de antivírus (EICAR)';
    // Código escondido: só aceito em arquivo que não é programa. Os marcadores abaixo não aparecem em foto.
    if (preg_match('/<\?php|<\?=|<script[\s>]|<%@|<%=|#!\/(?:usr\/)?bin\/(?:ba)?sh/i', $bin)) return 'o arquivo contém código escondido (script ou programa)';
    return '';
}

function intusAntivirusStatus(): array
{
    $clam = false;
    foreach (['unix:///var/run/clamav/clamd.ctl', 'unix:///run/clamav/clamd.ctl', 'unix:///var/run/clamd.scan/clamd.sock', 'unix:///tmp/clamd.socket', 'tcp://127.0.0.1:3310'] as $end) {
        $s = @stream_socket_client($end, $en, $es, 1);
        if ($s) { fwrite($s, "zPING\0"); $r = trim((string)stream_get_contents($s)); fclose($s); if (strpos($r, 'PONG') !== false) { $clam = true; break; } }
    }
    return ['clamav' => $clam, 'assinaturas_basicas' => true];
}
