<?php
/**
 * MODELO — renomeie para  cron.php  e coloque em  /app/config/cron.php
 *
 * Só é necessário se você contratar o pacote de CronJob da KingHost.
 * O token está no painel: Gerenciar intusfit.com.br → CronJob →
 * "HEADER DE VALIDAÇÃO → X-Cron-Auth". Copie o valor e cole abaixo.
 *
 * A pasta /app/config já é bloqueada pelo .htaccess, então este arquivo não é
 * acessível pelo navegador — do mesmo jeito que db.php e gdrive.json.
 *
 * Se você for pelo caminho da carona (sem custo), não precisa deste arquivo.
 */

return [
    'token' => 'COLE_AQUI_O_TOKEN_X_CRON_AUTH_DO_PAINEL',
];
