<?php
/**
 * BACKUP DE CARONA — dispara o backup diário sem depender do CronJob pago.
 *
 * Vai em /app/api/_carona_backup.php  (o "_" faz o .htaccess bloquear o acesso
 * direto pelo navegador; este arquivo só existe para ser incluído).
 *
 * Para ativar, acrescente esta linha no FIM de /app/api/dashboard.php:
 *
 *     @include __DIR__ . '/_carona_backup.php';
 *
 * Como se comporta
 * ----------------
 * 99% das vezes ele não faz nada: lê um arquivinho com a data do último
 * backup, vê que já rodou hoje e devolve na mesma hora. No primeiro acesso do
 * dia, espera a resposta do dashboard chegar ao professor (fastcgi_finish_
 * request) e só então executa o backup, com a conexão já encerrada. Quem está
 * do outro lado não percebe nada: a tela dele já carregou.
 *
 * O limite honesto deste método: ele depende de alguém abrir o painel. Se a
 * equipe passar cinco dias sem entrar, são cinco dias sem cópia nova. Para
 * backup que roda sozinho todo dia no horário, é o CronJob do painel.
 */

(function () {
    // Sem sessão de professor válida não há por que sequer olhar a data.
    // O dashboard já autenticou antes de chegar aqui; esta é só a garantia de
    // que o include não faz nada se um dia for parar em outro arquivo.
    if (PHP_SAPI === 'cli') return;

    $marca = __DIR__ . '/../config/backup-ultimo.txt';
    $hoje  = date('Y-m-d');
    $ultimo = is_file($marca) ? trim((string)@file_get_contents($marca)) : '';
    if ($ultimo === $hoje) return;      // já rodou hoje — caminho normal, custo zero

    // Marca ANTES de rodar. Se duas requisições chegarem juntas, a segunda vê a
    // data de hoje e desiste; e se o backup falhar, ele tenta amanhã em vez de
    // ficar reexecutando a cada clique do professor num dia de servidor ruim.
    @file_put_contents($marca, $hoje);

    // Entrega a resposta do dashboard antes de gastar tempo com o backup.
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    @ignore_user_abort(true);
    @set_time_limit(0);

    define('BACKUP_CARONA', true);
    try {
        include __DIR__ . '/cron-backup.php';
    } catch (Throwable $e) {
        @file_put_contents(
            __DIR__ . '/../config/backup.log',
            '[' . date('Y-m-d H:i:s') . '] ERRO na carona: ' . $e->getMessage() . PHP_EOL,
            FILE_APPEND
        );
    }
})();
