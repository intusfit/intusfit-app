<?php
/**
 * ROTINAS AUTOMÁTICAS DE SEGURANÇA E MANUTENÇÃO (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Quem dispara: o sino do painel (catalogo.php?action=notificacoes, consultado em toda tela da equipe), depois que a resposta já foi entregue, no máximo uma vez a
 * cada 6 horas (marca em config/rotinas-ultima.txt; nas outras vezes custa a leitura de um arquivinho). Não depende do CronJob pago da KingHost, mas, como o
 * backup, só roda se alguém da equipe abrir o painel. O resultado de cada rotina vai para o e-mail do aviso de backup (Configurações > Servidor > Backups).
 *
 *   1. Vigia de backup parado   2 dias ou mais sem cópia terminar bem  -> e-mail (no máximo 1 por dia)
 *   2. Conferência semanal      baixa a última cópia do Drive e confere (gzip, cabeçalho, rodapé, tabelas, dados) -> e-mail SÓ se falhar
 *   3. Vigia do certificado     o certificado que a KingHost instalou no servidor vence em menos de 15 dias -> e-mail (no máximo 1 a cada 3 dias)
 *   4. Resumo mensal            backup, conferência, certificado, acessos dos últimos 30 dias e travas ligadas -> e-mail 1 vez a cada 30 dias
 * Estado em config/rotinas.json. Nada aqui apaga ou altera dado de aluno.
 */

function intusRotinasEstado(): array
{
    $arq = __DIR__ . '/../config/rotinas.json';
    $j = is_file($arq) ? json_decode((string)@file_get_contents($arq), true) : null;
    return is_array($j) ? $j : [];
}

function intusRotinasGravar(array $e): void
{
    @file_put_contents(__DIR__ . '/../config/rotinas.json', json_encode($e, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/** Dias que faltam para o certificado instalado neste servidor vencer (null se não deu para ler). $ip = endereço do servidor; sem ele, tenta o de quem atendeu a requisição. */
function intusRotinasDiasCertificado(string $host = 'intusfit.com.br', ?string $ip = null): ?int
{
    $ip = $ip ?: ((string)($_SERVER['SERVER_ADDR'] ?? '') ?: '127.0.0.1');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host, 'SNI_server_name' => $host]]);
    $s = @stream_socket_client("ssl://{$ip}:443", $en, $es, 8, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) return null;
    $p = stream_context_get_params($s);
    @fclose($s);
    $c = $p['options']['ssl']['peer_certificate'] ?? null;
    $i = $c ? @openssl_x509_parse($c) : null;
    if (!is_array($i) || empty($i['validTo_time_t'])) return null;
    return (int)floor(((int)$i['validTo_time_t'] - time()) / 86400);
}

/** Decide o que fazer agora. Recebe "agora" e o estado para poder ser testada sem rede nem banco: devolve a lista de ações. */
function intusRotinasPlano(int $agora, array $estado, ?int $diasSemBackup, ?int $diasCert): array
{
    $acoes = [];
    $faz = fn(string $k, int $seg) => ($agora - (int)($estado[$k] ?? 0)) >= $seg;
    if ($diasSemBackup !== null && $diasSemBackup >= 2 && $faz('backup_alerta_em', 82800)) $acoes[] = 'alerta_backup';
    if ($faz('verificacao_em', 7 * 86400)) $acoes[] = 'conferir_copia';
    if ($diasCert !== null && $diasCert < 15 && $faz('cert_alerta_em', 3 * 86400)) $acoes[] = 'alerta_certificado';
    if ($faz('resumo_em', 30 * 86400)) $acoes[] = 'resumo_mensal';
    return $acoes;
}

function intusRotinasEmail(string $assunto, string $texto): bool
{
    require_once __DIR__ . '/_smtp_seguro.php';
    require_once __DIR__ . '/_backup_admin.php';
    $cfg = intusSmtpConfig();
    $para = _backupEmailConfigurado() ?: (string)($cfg['user'] ?? '');
    return $para !== '' && intusEnviarEmailSimples($para, $assunto, $texto);
}

function intusRotinasRodar(PDO $pdo): array
{
    require_once __DIR__ . '/_backup_admin.php';
    $feito = [];
    $estado = intusRotinasEstado();
    $agora = time();
    $st = backupStatus($pdo, false);
    $dias = $st['dias_sem_backup'] ?? null;
    $diasCert = intusRotinasDiasCertificado();
    $estado['cert_dias'] = $diasCert; $estado['cert_lido_em'] = $agora;
    $site = 'intusfit.com.br';

    foreach (intusRotinasPlano($agora, $estado, $dias, $diasCert) as $a) {
        if ($a === 'alerta_backup') {
            $ok = intusRotinasEmail('ALERTA: backup do Intus Fit parado', "O último backup do banco terminou bem há " . ($dias ?? '?') . " dia(s). Abra o painel > Configurações > Servidor > Backups e clique em \"Fazer backup agora\"; se falhar, a tela mostra o motivo.\n\nEste aviso se repete no máximo 1 vez por dia enquanto o problema durar.");
            $estado['backup_alerta_em'] = $agora; $feito[] = 'alerta_backup:' . ($ok ? 'enviado' : 'e-mail falhou');
        }
        if ($a === 'conferir_copia') {
            try { $r = backupConferir($pdo); } catch (Throwable $e) { $r = ['ok' => false, 'etapas' => [['Conferência', false, $e->getMessage()]]]; }
            $estado['verificacao_em'] = $agora; $estado['verificacao_ok'] = !empty($r['ok']); $estado['verificacao_arquivo'] = $r['arquivo'] ?? null;
            $estado['verificacao_etapas'] = array_map(fn($e) => ($e[1] ? 'ok' : 'FALHOU') . ': ' . $e[0] . ' - ' . $e[2], $r['etapas'] ?? []);
            if (empty($r['ok'])) intusRotinasEmail('ALERTA: a última cópia do banco NÃO passou na conferência', "A conferência semanal da cópia do banco no Google Drive reprovou.\n\n" . implode("\n", $estado['verificacao_etapas']) . "\n\nFaça um backup novo em Configurações > Servidor > Backups e confira de novo.");
            $feito[] = 'conferir_copia:' . (!empty($r['ok']) ? 'ok' : 'REPROVOU');
        }
        if ($a === 'alerta_certificado') {
            $ok = intusRotinasEmail('ALERTA: o certificado do servidor vence em ' . $diasCert . ' dia(s)', "O certificado instalado pela KingHost no servidor de {$site} vence em {$diasCert} dia(s). A renovação deveria ser automática. Se não renovar, o Cloudflare segue servindo o site (modo Full), mas abra um chamado na KingHost pedindo a renovação.");
            $estado['cert_alerta_em'] = $agora; $feito[] = 'alerta_certificado:' . ($ok ? 'enviado' : 'e-mail falhou');
        }
        if ($a === 'resumo_mensal') {
            $linhas = [];
            $linhas[] = 'Último backup do banco: ' . ($st['ultimo_banco_ok'] ?? 'sem registro') . ($dias !== null ? " (há {$dias} dia(s))" : '');
            $linhas[] = 'Última conferência da cópia: ' . (isset($estado['verificacao_em']) ? date('d/m/Y', (int)$estado['verificacao_em']) . ' - ' . (!empty($estado['verificacao_ok']) ? 'aprovada' : 'REPROVADA') : 'ainda não feita');
            $linhas[] = 'Certificado do servidor: ' . ($diasCert !== null ? "vence em {$diasCert} dia(s)" : 'não consegui ler');
            try {
                $q = $pdo->prepare("SELECT user_tipo, COUNT(*) AS n, COUNT(DISTINCT user_id) AS p FROM intus_acesso WHERE evento = 'login' AND criado_em > ? GROUP BY user_tipo");
                $q->execute([gmdate('Y-m-d H:i:s', $agora - 30 * 86400)]);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $linhas[] = 'Logins em 30 dias (' . ($r['user_tipo'] === 'staff' ? 'equipe' : 'alunos') . '): ' . $r['n'] . ' de ' . $r['p'] . ' pessoa(s)';
                $q = $pdo->prepare("SELECT pais, COUNT(*) AS n FROM intus_acesso WHERE user_tipo = 'staff' AND evento = 'login' AND pais IS NOT NULL AND pais <> '' AND criado_em > ? GROUP BY pais ORDER BY n DESC LIMIT 5");
                $q->execute([gmdate('Y-m-d H:i:s', $agora - 30 * 86400)]);
                $paises = array_map(fn($r) => $r['pais'] . ' (' . $r['n'] . ')', $q->fetchAll(PDO::FETCH_ASSOC));
                $linhas[] = 'Países dos logins da equipe: ' . ($paises ? implode(', ', $paises) : 'sem localização (Cloudflare)');
                $linhas[] = 'Registros de acesso guardados: ' . (int)$pdo->query('SELECT COUNT(*) FROM intus_acesso')->fetchColumn() . ' (o painel tem botão para apagar os com mais de 180 dias)';
            } catch (Throwable $e) {}
            try { $c = intusSegConfig($pdo); $linhas[] = 'Travas opcionais: mensagens ' . (!empty($c['mensagens']) ? 'LIGADA' : 'desligada') . ', desafios só do administrador ' . (!empty($c['desafios_admin']) ? 'LIGADA' : 'desligada'); } catch (Throwable $e) {}
            $linhas[] = ''; $linhas[] = 'Lembretes mensais seus: baixar a cópia do Drive e rodar a conferência; a cada 3 meses, restaurar num banco de teste (tools/backup/RESTAURAR.md); conferir o painel da KingHost (serviços bloqueados, chamados).';
            $ok = intusRotinasEmail('Resumo mensal de segurança - Intus Fit', "Resumo automático do site {$site}:\n\n" . implode("\n", $linhas));
            $estado['resumo_em'] = $agora; $feito[] = 'resumo_mensal:' . ($ok ? 'enviado' : 'e-mail falhou');
        }
    }
    $estado['ultima_rodada'] = $agora;
    intusRotinasGravar($estado);
    return $feito;
}
