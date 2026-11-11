<?php
/**
 * Chaves e ajustes do atendimento por WhatsApp (área logada do admin).
 *
 * Mesmo padrão de admin/gdrive-key.php: as chaves moram no BANCO (intus_secrets),
 * nunca em arquivo (arquivo em app/config já sumiu do servidor uma vez).
 * Quem preenche é o Luiz. Esta página nunca mostra de volta uma chave salva,
 * só diz se está configurada. Campo em branco mantém o valor que já existe.
 */
require_once 'config.php';
session_name(SESSION_KEY);
session_start();

if (empty($_SESSION['auth']) || (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
    session_destroy();
    header('Location: index.php');
    exit;
}

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/../app/api/_wa.php';
require_once __DIR__ . '/../app/api/_ensaio_modelos.php';   // carrega _atd_robo.php, _wa.php e _atendimento.php
$pdo = wa_conectar();
if (!$pdo) { http_response_code(500); exit('Falha ao conectar no banco.'); }
wa_garantir_tabelas($pdo);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['wa_csrf'])) $_SESSION['wa_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['wa_csrf'];

$avisos = [];   // [texto, erro?]
$resultadoTeste = null;
$resultadoClaude = null;
$resultadoEnsaio = null;
$comparacao = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirada. Volte e recarregue a página.');
    }
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        $erros = [];

        // WhatsApp (Meta)
        $wa = wa_segredo($pdo, 'whatsapp_config') ?: [];
        $regras = [
            'phone_number_id' => ['/^\d{6,25}$/', 'ID do número: só dígitos.'],
            'waba_id'         => ['/^\d{6,25}$/', 'ID da conta do WhatsApp Business: só dígitos.'],
            'access_token'    => ['/^\S{20,}$/', 'Token de acesso: pelo menos 20 caracteres, sem espaços.'],
            'app_secret'      => ['/^\S{16,}$/', 'Segredo do app: pelo menos 16 caracteres, sem espaços.'],
            'verify_token'    => ['/^\S{12,}$/', 'Token de verificação: pelo menos 12 caracteres, sem espaços.'],
            'api_version'     => ['/^v\d{1,2}\.\d$/', 'Versão da API no formato v23.0.'],
        ];
        $mexeuWa = false;
        foreach ($regras as $campo => [$re, $msgErro]) {
            $v = trim((string)($_POST['wa_' . $campo] ?? ''));
            if ($v === '') continue;
            if (!preg_match($re, $v)) { $erros[] = $msgErro; continue; }
            $wa[$campo] = $v; $mexeuWa = true;
        }
        if ($mexeuWa && empty($wa['verify_token'])) $wa['verify_token'] = bin2hex(random_bytes(16));
        if ($mexeuWa && !$erros) wa_segredo_gravar($pdo, 'whatsapp_config', $wa);

        // Claude (Anthropic)
        $an = trim((string)($_POST['an_api_key'] ?? ''));
        if ($an !== '') {
            if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $an)) $erros[] = 'Chave do Claude: precisa começar com sk-ant- e estar completa.';
            else wa_segredo_gravar($pdo, 'anthropic_config', ['api_key' => $an]);
        }

        // CronJob da KingHost
        $cr = trim((string)($_POST['cron_token'] ?? ''));
        if ($cr !== '') {
            if (!preg_match('/^\S{8,}$/', $cr)) $erros[] = 'Token do CronJob: pelo menos 8 caracteres, sem espaços.';
            else wa_segredo_gravar($pdo, 'cron_config', ['token' => $cr]);
        }

        // Ajustes (não são segredo)
        $aj = wa_ajustes($pdo);
        $nome = trim((string)($_POST['aj_nome_atendente'] ?? ''));
        if ($nome !== '') {
            if (mb_strlen($nome) > 30 || preg_match('/[<>"\']/', $nome)) $erros[] = 'Nome do atendente: até 30 caracteres, sem aspas nem sinais de maior ou menor.';
            else $aj['nome_atendente'] = $nome;
        }
        $modelo = trim((string)($_POST['aj_modelo'] ?? ''));
        if ($modelo !== '') {
            if (!preg_match('/^claude-[a-z0-9.\-]{2,60}$/', $modelo)) $erros[] = 'Modelo: precisa começar com claude-, por exemplo claude-opus-5-5.';
            else $aj['modelo'] = $modelo;
        }
        $rb = (string)($_POST['aj_robo'] ?? $aj['robo']);
        if (!in_array($rb, ROBO_MODOS, true)) $erros[] = 'Robô de conversa: escolha desligado, só teste ou todos.';
        elseif ($rb !== 'desligado' && $rb !== ($aj['robo'] ?? 'desligado') && (!(wa_segredo($pdo, 'anthropic_config')['api_key'] ?? '') || !wa_config_whatsapp($pdo))) {
            $erros[] = 'Antes de ligar o robô, salve a chave do Claude e os dados da Meta (ID do número e token de acesso).';
        } else $aj['robo'] = $rb;
        $mr = (int)($_POST['aj_robo_max_respostas_dia'] ?? $aj['robo_max_respostas_dia']);
        if ($mr < 1 || $mr > 50) $erros[] = 'Respostas do robô por contato e por dia: de 1 a 50.';
        else $aj['robo_max_respostas_dia'] = $mr;
        $mt = (int)($_POST['aj_robo_max_dia_total'] ?? $aj['robo_max_dia_total']);
        if ($mt < 1 || $mt > 2000) $erros[] = 'Respostas do robô por dia, no total: de 1 a 2000.';
        else $aj['robo_max_dia_total'] = $mt;
        $em = trim((string)($_POST['aj_robo_email'] ?? ''));
        if ($em !== '') {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail do aviso do robô: endereço inválido.';
            else $aj['robo_email'] = $em;
        }
        $ini = (int)($_POST['aj_horario_ini'] ?? $aj['horario_ini']);
        $fim = (int)($_POST['aj_horario_fim'] ?? $aj['horario_fim']);
        if ($ini < 0 || $ini > 23 || $fim < 1 || $fim > 24 || $fim <= $ini) $erros[] = 'Horário de envio: início entre 0 e 23 e fim maior que o início, até 24.';
        else { $aj['horario_ini'] = $ini; $aj['horario_fim'] = $fim; }
        $max = (int)($_POST['aj_max_proativas_dia'] ?? $aj['max_proativas_dia']);
        if ($max < 0 || $max > 5) $erros[] = 'Mensagens proativas por dia: de 0 a 5 (0 desliga o limite).';
        else $aj['max_proativas_dia'] = $max;
        $dias = (int)($_POST['aj_dias_teste'] ?? $aj['dias_teste']);
        if ($dias < 1 || $dias > 30) $erros[] = 'Dias do teste grátis: de 1 a 30.';
        else $aj['dias_teste'] = $dias;
        if (!$erros) wa_segredo_gravar($pdo, 'wa_ajustes', $aj);

        if ($erros) foreach ($erros as $e) $avisos[] = [$e, true];
        else $avisos[] = ['Salvo no banco de dados.', false];
    }

    if ($acao === 'testar') {
        $cfg = wa_config_whatsapp($pdo);
        if (!$cfg) $avisos[] = ['Antes de testar, salve o ID do número e o token de acesso.', true];
        else $resultadoTeste = wa_testar_conexao($cfg);
    }

    if ($acao === 'testar_claude') $resultadoClaude = atd_testar_claude($pdo);

    if ($acao === 'comparar_robo' || $acao === 'comparar_cut') {
        @set_time_limit(240);   // cada modelo leva alguns segundos; o ensaio chama vários em sequência
        if ($acao === 'comparar_cut') {
            $pedido = ensaio_cut_pedido();
            $linhas = [];
            foreach (ENSAIO_MODELOS as $m) {
                $r = ensaio_claude($pdo, $m, 'Você edita vídeos curtos para as redes da Intus Fit. Responda sempre só com o JSON pedido, sem texto antes ou depois.', [['role' => 'user', 'content' => $pedido]], 8000, 'medium');
                $linhas[] = $r + ['avaliacao' => $r['ok'] ? ensaio_cut_avaliar($r['texto']) : null];
            }
            $comparacao = ['tipo' => 'cut', 'linhas' => $linhas];
        } else {
            $txt = atd_texto_limpo($_POST['ensaio_texto'] ?? '', 500);
            $casos = $txt !== '' ? [$txt] : ensaio_robo_casos();
            $linhas = [];
            foreach ($casos as $caso) {
                $obvio = robo_motivo_obvio($caso);
                $porModelo = [];
                if ($obvio === null) foreach (ENSAIO_MODELOS as $m) $porModelo[$m] = robo_decidir($pdo, ['nome' => 'Lead de teste'], [['role' => 'user', 'content' => $caso]], null, $m);
                $linhas[] = ['caso' => $caso, 'regra' => $obvio, 'modelos' => $porModelo];
            }
            $comparacao = ['tipo' => 'robo', 'linhas' => $linhas];
        }
    }

    if ($acao === 'ensaio_robo') {
        $txt = atd_texto_limpo($_POST['ensaio_texto'] ?? '', 500);
        if ($txt === '') $avisos[] = ['Escreva a mensagem que o lead mandaria.', true];
        else {
            $obvio = robo_motivo_obvio($txt);
            if ($obvio !== null) $resultadoEnsaio = ['regra' => $obvio, 'texto' => $txt];
            else $resultadoEnsaio = ['d' => robo_decidir($pdo, ['nome' => 'Lead de teste'], [['role' => 'user', 'content' => $txt]]), 'texto' => $txt];
        }
    }
}

// ── Estado para exibir (nunca os valores secretos) ──
$wa = wa_segredo($pdo, 'whatsapp_config') ?: [];
$an = wa_segredo($pdo, 'anthropic_config') ?: [];
$cr = wa_segredo($pdo, 'cron_config') ?: [];
$aj = wa_ajustes($pdo);

function quando(PDO $pdo, string $chave): string {
    try {
        $st = $pdo->prepare('SELECT updated_at FROM intus_secrets WHERE chave = ?');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        return $v ? ('atualizado em ' . $v) : 'ainda não configurado';
    } catch (Throwable $e) { return 'configurado'; }
}
function chip(bool $ok, string $rotulo): string {
    return '<span class="chip ' . ($ok ? 'ok' : 'falta') . '">' . ($ok ? 'configurado' : 'falta') . ' · ' . h($rotulo) . '</span>';
}
$urlWebhook = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'intusfit.com.br') . '/app/api/whatsapp_webhook.php';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Atendimento por WhatsApp · Intus</title>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;max-width:680px;margin:32px auto;padding:0 20px 60px;}
  h2{font-size:20px;margin:0 0 6px;} h3{font-size:14px;letter-spacing:.08em;text-transform:uppercase;color:#aaa;margin:32px 0 10px;}
  p{font-size:13px;color:#aaa;line-height:1.55;margin:6px 0;} code{background:#1a1a1a;padding:1px 6px;border-radius:4px;color:#7FFF00;word-break:break-all;}
  label{display:block;font-size:12px;font-weight:600;color:#bbb;margin:14px 0 5px;}
  input[type=text],input[type=password],input[type=number]{width:100%;box-sizing:border-box;background:#1a1a1a;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:8px;padding:11px 12px;font:14px ui-monospace,Consolas,monospace;}
  input:focus{outline:2px solid #7FFF00;outline-offset:1px;}
  .linha{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;}
  button{background:#7FFF00;color:#111;border:none;border-radius:8px;padding:12px 20px;font-weight:800;cursor:pointer;margin-top:18px;font-size:14px;}
  button.sec{background:transparent;color:#f0f0f0;border:1px solid #2a2a2a;}
  .aviso{padding:10px 14px;border-radius:8px;background:#1a2b0f;border:1px solid #3a5c1a;margin:10px 0;font-size:13px;}
  .aviso.erro{background:#2b0f0f;border-color:#5c1a1a;}
  .chips{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 2px;}
  .chip{font-size:11px;padding:3px 9px;border-radius:99px;border:1px solid #2a2a2a;background:#1a1a1a;}
  .chip.ok{color:#7FFF00;border-color:#3a5c1a;} .chip.falta{color:#ffb84d;border-color:#5c4a1a;}
  .caixa{background:#161616;border:1px solid #2a2a2a;border-radius:10px;padding:14px 16px;margin:10px 0;}
</style>
</head>
<body>
<h2>Atendimento por WhatsApp</h2>
<p>Chaves e ajustes do robô de atendimento. Ficam guardadas no banco de dados, não em arquivo. Esta página nunca mostra de volta uma chave salva. Campo em branco mantém o valor atual.</p>

<?php foreach ($avisos as [$txt, $err]): ?><div class="aviso<?= $err ? ' erro' : '' ?>"><?= h($txt) ?></div><?php endforeach; ?>
<?php if ($resultadoTeste !== null): ?>
  <?php if ($resultadoTeste['ok']): ?>
    <div class="aviso">Conexão com a Meta funcionando. Nome verificado: <b><?= h($resultadoTeste['nome']) ?></b> · número: <b><?= h($resultadoTeste['numero']) ?></b> · qualidade: <b><?= h($resultadoTeste['qualidade'] ?: 'sem dado') ?></b></div>
  <?php else: ?>
    <div class="aviso erro">Não conectou: <?= h($resultadoTeste['erro']) ?></div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($resultadoClaude !== null): ?>
  <?php if ($resultadoClaude['ok']): ?>
    <div class="aviso">Claude respondeu: a chave e o saldo da API estão funcionando. Modelo: <b><?= h($resultadoClaude['modelo']) ?></b> · este teste usou <?= (int)$resultadoClaude['tin'] ?> + <?= (int)$resultadoClaude['tout'] ?> tokens (cerca de US$ <?= h(number_format((float)$resultadoClaude['custo_usd'], 5, ',', '')) ?>). A edição automática do Intus Cut e o laboratório de atendimento usam esta mesma chave.</div>
  <?php else: ?>
    <div class="aviso erro">Claude não funcionou: <?= h($resultadoClaude['erro']) ?><?php if (!empty($resultadoClaude['detalhe'])): ?><br><span style="color:#aaa">Resposta da Anthropic: <?= h($resultadoClaude['detalhe']) ?></span><?php endif; ?></div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($resultadoEnsaio !== null): ?>
  <?php if (isset($resultadoEnsaio['regra'])): ?>
    <div class="aviso">Ensaio: com "<?= h($resultadoEnsaio['texto']) ?>" o robô passaria a conversa para uma pessoa por regra fixa (motivo: <b><?= h($resultadoEnsaio['regra']) ?></b>), sem chamar o Claude. Mensagem ao lead: <i><?= h(robo_texto_passagem($resultadoEnsaio['regra'], $aj['nome_atendente'] ?? 'Cora')) ?></i></div>
  <?php elseif (!$resultadoEnsaio['d']['ok']): ?>
    <div class="aviso erro">Ensaio: o Claude não respondeu. <?= h($resultadoEnsaio['d']['erro']) ?></div>
  <?php else: $d = $resultadoEnsaio['d']; ?>
    <div class="aviso">Ensaio com "<?= h($resultadoEnsaio['texto']) ?>": ação <b><?= h($d['acao']) ?></b><?= $d['acao'] === 'passar_para_humano' ? ' (motivo: ' . h($d['motivo']) . ')' : '' ?>.
      <?php if ($d['acao'] === 'responder'): ?>Resposta: <i><?= h($d['texto']) ?></i><?php else: ?>Mensagem ao lead: <i><?= h(robo_texto_passagem($d['motivo'], $aj['nome_atendente'] ?? 'Cora')) ?></i><?php endif; ?>
      <br><span style="color:#aaa">Resumo do robô: <?= h($d['resumo']) ?> · <?= (int)$d['tin'] ?> + <?= (int)$d['tout'] ?> tokens (cerca de US$ <?= h(number_format(atd_custo_usd((int)$d['tin'], (int)$d['tout']), 4, ',', '')) ?>). Nada foi enviado a ninguém.</span></div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($comparacao !== null && $comparacao['tipo'] === 'robo'): ?>
  <div class="caixa"><p><b>Comparação dos modelos no robô de atendimento</b> (nada foi enviado a ninguém; "por regra fixa" quer dizer que o robô nem chamaria o Claude)</p>
  <?php $tot = array_fill_keys(ENSAIO_MODELOS, ['custo' => 0.0, 'ms' => 0, 'n' => 0]); foreach ($comparacao['linhas'] as $L): ?>
    <p style="margin-top:12px;color:#f0f0f0"><b>Lead:</b> <?= h($L['caso']) ?></p>
    <?php if ($L['regra'] !== null): ?><p>Por regra fixa passa para uma pessoa (<?= h($L['regra']) ?>), sem chamar o Claude.</p><?php else: foreach ($L['modelos'] as $m => $d): ?>
      <p style="margin:6px 0 0"><b style="color:#7FFF00"><?= h($m) ?></b>
      <?php if (!$d['ok']): ?>falhou: <?= h($d['erro']) ?><?php else: $c = ensaio_custo_usd($m, (int)$d['tin'], (int)$d['tout']); $tot[$m]['custo'] += $c; $tot[$m]['ms'] += $d['ms']; $tot[$m]['n']++; ?>
        · <?= (int)$d['ms'] ?> ms · US$ <?= h(number_format($c, 5, ',', '')) ?> · ação <b><?= h($d['acao']) ?></b><?= $d['acao'] === 'passar_para_humano' ? ' (' . h($d['motivo']) . ')' : '' ?><br>
        <i><?= h($d['acao'] === 'responder' ? $d['texto'] : '(passa para uma pessoa) ' . $d['resumo']) ?></i><?php endif; ?></p>
    <?php endforeach; endif; ?>
  <?php endforeach; ?>
  <p style="margin-top:14px"><b>Total e tempo médio por modelo:</b>
  <?php foreach ($tot as $m => $t): ?><br><?= h($m) ?>: US$ <?= h(number_format($t['custo'], 5, ',', '')) ?> em <?= (int)$t['n'] ?> respostas<?= $t['n'] ? ' · ' . (int)round($t['ms'] / $t['n']) . ' ms em média' : '' ?><?php endforeach; ?></p></div>
<?php elseif ($comparacao !== null): ?>
  <div class="caixa"><p><b>Comparação dos modelos na edição automática do Intus Cut</b> (entrevista de exemplo de 92 s, pedido de 30 a 45 s; sem imagens)</p>
  <?php foreach ($comparacao['linhas'] as $d): ?>
    <p style="margin-top:12px"><b style="color:#7FFF00"><?= h($d['modelo']) ?></b>
    <?php if (!$d['ok']): ?>falhou: <?= h($d['erro']) ?><?php else: $a = $d['avaliacao']; ?>
      · <?= (int)$d['ms'] ?> ms · <?= (int)$d['tin'] ?> + <?= (int)$d['tout'] ?> tokens · US$ <?= h(number_format((float)$d['custo_usd'], 5, ',', '')) ?> · conferências <b><?= (int)$a['nota'] ?>/<?= (int)$a['de'] ?></b><br>
      <?php foreach ($a['itens'] as [$okI, $frase]): ?><span style="color:<?= $okI ? '#7FFF00' : '#ff6b6b' ?>"><?= $okI ? '✓' : '✗' ?> <?= h($frase) ?></span><br><?php endforeach; ?>
      <?php if (!empty($a['resumo'])): ?><span style="color:#aaa">Gancho: <?= h($a['resumo']['gancho']) ?> · <?= (int)$a['resumo']['trechos'] ?> trechos, <?= h((string)$a['resumo']['segundos']) ?> s · <?= h($a['resumo']['explicacao']) ?></span><?php endif; ?>
    <?php endif; ?></p>
  <?php endforeach; ?></div>
<?php endif; ?>

<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">

<h3>WhatsApp (Meta)</h3>
<p><?= h(quando($pdo, 'whatsapp_config')) ?></p>
<div class="chips">
  <?= chip(!empty($wa['phone_number_id']), 'ID do número') ?>
  <?= chip(!empty($wa['waba_id']), 'ID da conta') ?>
  <?= chip(!empty($wa['access_token']), 'token de acesso') ?>
  <?= chip(!empty($wa['app_secret']), 'segredo do app') ?>
  <?= chip(!empty($wa['verify_token']), 'token de verificação') ?>
</div>
<div class="caixa">
  <p>Para cadastrar o webhook no painel da Meta, use estes dois valores:</p>
  <p>URL de retorno: <code><?= h($urlWebhook) ?></code></p>
  <p>Token de verificação: <?= !empty($wa['verify_token']) ? '<code>' . h($wa['verify_token']) . '</code>' : 'será gerado ao salvar, se você deixar o campo abaixo em branco' ?></p>
</div>
<label for="wa_phone_number_id">ID do número de telefone</label>
<input type="text" id="wa_phone_number_id" name="wa_phone_number_id" inputmode="numeric" placeholder="<?= !empty($wa['phone_number_id']) ? 'salvo (em branco mantém)' : 'só dígitos' ?>">
<label for="wa_waba_id">ID da conta do WhatsApp Business</label>
<input type="text" id="wa_waba_id" name="wa_waba_id" inputmode="numeric" placeholder="<?= !empty($wa['waba_id']) ? 'salvo (em branco mantém)' : 'só dígitos' ?>">
<label for="wa_access_token">Token de acesso permanente</label>
<input type="password" id="wa_access_token" name="wa_access_token" autocomplete="new-password" placeholder="<?= !empty($wa['access_token']) ? 'salvo (em branco mantém)' : 'cole aqui' ?>">
<label for="wa_app_secret">Segredo do app (App Secret)</label>
<input type="password" id="wa_app_secret" name="wa_app_secret" autocomplete="new-password" placeholder="<?= !empty($wa['app_secret']) ? 'salvo (em branco mantém)' : 'cole aqui' ?>">
<label for="wa_verify_token">Token de verificação (opcional, é gerado se ficar em branco)</label>
<input type="text" id="wa_verify_token" name="wa_verify_token" placeholder="<?= !empty($wa['verify_token']) ? 'salvo (em branco mantém)' : 'em branco gera um aleatório' ?>">
<label for="wa_api_version">Versão da API da Meta</label>
<input type="text" id="wa_api_version" name="wa_api_version" placeholder="<?= h($wa['api_version'] ?? 'v23.0') ?>">

<h3>Claude (Anthropic)</h3>
<p><?= h(quando($pdo, 'anthropic_config')) ?></p>
<div class="chips"><?= chip(!empty($an['api_key']), 'chave da API') ?></div>
<label for="an_api_key">Chave da API do Claude</label>
<input type="password" id="an_api_key" name="an_api_key" autocomplete="new-password" placeholder="<?= !empty($an['api_key']) ? 'salva (em branco mantém)' : 'sk-ant-...' ?>">

<h3>CronJob da KingHost</h3>
<p><?= h(quando($pdo, 'cron_config')) ?></p>
<div class="chips"><?= chip(!empty($cr['token']), 'token X-Cron-Auth') ?></div>
<label for="cron_token">Token do cabeçalho de validação (X-Cron-Auth)</label>
<input type="password" id="cron_token" name="cron_token" autocomplete="new-password" placeholder="<?= !empty($cr['token']) ? 'salvo (em branco mantém)' : 'copie do painel da KingHost' ?>">

<h3>Ajustes</h3>
<p>Valem na hora, sem precisar publicar nada.</p>
<label for="aj_nome_atendente">Nome do atendente virtual</label>
<input type="text" id="aj_nome_atendente" name="aj_nome_atendente" value="<?= h($aj['nome_atendente']) ?>" maxlength="30">
<label for="aj_modelo">Modelo do Claude</label>
<input type="text" id="aj_modelo" name="aj_modelo" value="<?= h($aj['modelo']) ?>">
<h3>Robô de conversa</h3>
<p>Responde sozinho ao que o lead escreve (dentro das 24 horas da Meta) e passa para uma pessoa nos casos de preço, saúde, reclamação, pedido de pessoa e foto ou vídeo. Nasce desligado. "Só teste" responde apenas ao contato de teste (Fulano Teste). "Todos" responde a qualquer lead que escrever.</p>
<div class="linha">
  <div><label for="aj_robo">Robô</label><select id="aj_robo" name="aj_robo" style="width:100%;box-sizing:border-box;background:#1a1a1a;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:8px;padding:11px 12px;font:14px system-ui;">
    <?php foreach (['desligado' => 'Desligado', 'teste' => 'Só o contato de teste', 'todos' => 'Todos os leads'] as $v => $rot): ?><option value="<?= h($v) ?>"<?= ($aj['robo'] ?? 'desligado') === $v ? ' selected' : '' ?>><?= h($rot) ?></option><?php endforeach; ?></select></div>
  <div><label for="aj_robo_max_respostas_dia">Respostas por contato ao dia</label><input type="number" id="aj_robo_max_respostas_dia" name="aj_robo_max_respostas_dia" value="<?= (int)$aj['robo_max_respostas_dia'] ?>" min="1" max="50"></div>
  <div><label for="aj_robo_max_dia_total">Respostas por dia, no total</label><input type="number" id="aj_robo_max_dia_total" name="aj_robo_max_dia_total" value="<?= (int)$aj['robo_max_dia_total'] ?>" min="1" max="2000"></div>
</div>
<label for="aj_robo_email">E-mail que recebe o aviso quando uma conversa passa para uma pessoa</label>
<input type="text" id="aj_robo_email" name="aj_robo_email" value="<?= h($aj['robo_email']) ?>" inputmode="email">
<label for="ensaio_texto">Ensaiar o robô (não envia nada a ninguém e gasta um pouco do saldo do Claude)</label>
<input type="text" id="ensaio_texto" name="ensaio_texto" placeholder="Ex.: oi, como funciona o teste grátis?" maxlength="500">
<button type="submit" name="acao" value="ensaio_robo" class="sec">Ensaiar</button>
<button type="submit" name="acao" value="comparar_robo" class="sec">Comparar modelos (robô)</button>
<button type="submit" name="acao" value="comparar_cut" class="sec">Comparar modelos (edição automática)</button>
<p>"Comparar modelos (robô)" usa a mensagem acima ou, se estiver em branco, cinco mensagens de exemplo. Cada comparação gasta alguns centavos do saldo e pode levar um minuto.</p>

<h3>Horários e teste grátis</h3>
<div class="linha">
  <div><label for="aj_horario_ini">Enviar a partir das (h)</label><input type="number" id="aj_horario_ini" name="aj_horario_ini" value="<?= (int)$aj['horario_ini'] ?>" min="0" max="23"></div>
  <div><label for="aj_horario_fim">Até as (h)</label><input type="number" id="aj_horario_fim" name="aj_horario_fim" value="<?= (int)$aj['horario_fim'] ?>" min="1" max="24"></div>
  <div><label for="aj_max_proativas_dia">Mensagens ativas por dia</label><input type="number" id="aj_max_proativas_dia" name="aj_max_proativas_dia" value="<?= (int)$aj['max_proativas_dia'] ?>" min="0" max="5"></div>
  <div><label for="aj_dias_teste">Dias do teste grátis</label><input type="number" id="aj_dias_teste" name="aj_dias_teste" value="<?= (int)$aj['dias_teste'] ?>" min="1" max="30"></div>
</div>

<button type="submit" name="acao" value="salvar">Salvar</button>
<button type="submit" name="acao" value="testar" class="sec">Testar conexão com a Meta</button>
<button type="submit" name="acao" value="testar_claude" class="sec">Testar o Claude (chave e saldo)</button>
</form>
</body>
</html>
