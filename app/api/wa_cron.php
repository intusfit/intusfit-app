<?php
/**
 * Roda a fila do WhatsApp (mensagens de funil que venceram e estão em modo automático).
 *
 * Feito para ser chamado por um agendador externo a cada poucos minutos (CronJob da KingHost, ou um agendador gratuito do Cloudflare):
 *   POST/GET https://intusfit.com.br/app/api/wa_cron.php   com o cabeçalho  X-Cron-Auth: <token cadastrado em admin/whatsapp-keys.php>
 *
 * Sem token cadastrado responde 503 e não faz nada; token errado responde 403. A resposta não traz nome nem telefone de ninguém.
 * Com a chave geral da automação desligada (tela /atendimento/teste.html) a chamada é inofensiva: não envia nada.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/_wa_fila.php';

$pdo = wa_conectar();
if (!$pdo) { http_response_code(503); echo '{"ok":false,"erro":"banco"}'; exit; }

try {
    wa_garantir_tabelas($pdo);
    $cron = wa_segredo($pdo, 'cron_config') ?: [];
    $esperado = (string)($cron['token'] ?? '');
    if ($esperado === '') { http_response_code(503); echo '{"ok":false,"erro":"cron nao configurado"}'; exit; }
    $recebido = (string)($_SERVER['HTTP_X_CRON_AUTH'] ?? '');
    if ($recebido === '' || !hash_equals($esperado, $recebido)) { http_response_code(403); echo '{"ok":false,"erro":"nao autorizado"}'; exit; }

    $r = waf_processar($pdo, wa_config_whatsapp($pdo));
    $itens = array_map(fn($i) => ['passo' => $i['passo'], 'resultado' => $i['resultado']], $r['itens']);
    echo json_encode(['ok' => true, 'mestre' => $r['mestre'], 'processadas' => $r['processadas'], 'enviadas' => $r['enviadas'], 'manuais_vencidas' => $r['manuais_vencidas'], 'itens' => $itens], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'erro interno (' . wa_log_erro($e) . ')']);
}
