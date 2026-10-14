<?php
/**
 * Servidor de e-mail do site (SMTP), na área logada do admin.
 *
 * Mesmo padrão de gdrive-key.php e whatsapp-keys.php: o valor de verdade mora no BANCO (intus_secrets, chave smtp_config), não em arquivo. O arquivo
 * app/config/smtp.php (rastreado no git, com a senha no histórico) fica só como reserva até o banco ser preenchido. Esta página NUNCA mostra a senha
 * salva de volta; só diz se está configurada. Campo em branco mantém o valor atual.
 *
 * Importar: copia host, usuário e senha do arquivo antigo para o banco no servidor, sem a senha passar pela tela nem por quem edita o código.
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
require_once __DIR__ . '/../app/api/_smtp_config.php';
require_once __DIR__ . '/../app/api/_backup_admin.php';
$pdo = wa_conectar();
if (!$pdo) { http_response_code(500); exit('Falha ao conectar no banco.'); }
wa_garantir_tabelas($pdo);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['smtp_csrf'])) $_SESSION['smtp_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['smtp_csrf'];

$avisos = [];   // [texto, erro?]
$teste = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirada. Volte e recarregue a página.');
    }
    $acao = (string)($_POST['acao'] ?? '');
    $atual = wa_segredo($pdo, 'smtp_config') ?: [];

    if ($acao === 'importar') {
        $arq = intusSmtpConfigArquivo();
        if (empty($arq['host']) || empty($arq['user']) || empty($arq['pass'])) {
            $avisos[] = ['O arquivo antigo (app/config/smtp.php) não tem host, usuário e senha completos. Preencha os campos abaixo.', true];
        } else {
            $novo = ['host' => (string)$arq['host'], 'port' => (int)($arq['port'] ?? 465), 'user' => (string)$arq['user'], 'pass' => (string)$arq['pass'], 'from_name' => (string)($arq['from_name'] ?? 'Intus Fit')];
            if (!empty($arq['notificar'])) $novo['notificar'] = (string)$arq['notificar'];
            wa_segredo_gravar($pdo, 'smtp_config', $novo);
            $avisos[] = ['Importado do arquivo antigo para o banco. A senha não passou pela tela. Próximo passo: troque a senha do e-mail no painel da KingHost e digite a nova aqui.', false];
        }
    }

    if ($acao === 'salvar') {
        $erros = [];
        $base = $atual ?: (function () { $a = intusSmtpConfigArquivo(); return $a ? ['host' => (string)($a['host'] ?? ''), 'port' => (int)($a['port'] ?? 465), 'user' => (string)($a['user'] ?? ''), 'pass' => (string)($a['pass'] ?? ''), 'from_name' => (string)($a['from_name'] ?? 'Intus Fit')] : []; })();
        $host = trim((string)($_POST['host'] ?? ''));
        if ($host !== '') { if (!preg_match('/^[A-Za-z0-9.\-]{3,120}$/', $host)) $erros[] = 'Servidor: só letras, números, ponto e hífen (ex.: smtp.intusfit.com.br).'; else $base['host'] = $host; }
        $user = trim((string)($_POST['user'] ?? ''));
        if ($user !== '') { if (!filter_var($user, FILTER_VALIDATE_EMAIL)) $erros[] = 'Usuário: precisa ser o e-mail completo (ex.: contato@intusfit.com.br).'; else $base['user'] = $user; }
        $pass = (string)($_POST['pass'] ?? '');
        if ($pass !== '') { if (strlen($pass) < 4 || preg_match('/[\r\n\0]/', $pass)) $erros[] = 'Senha: pelo menos 4 caracteres e sem quebra de linha.'; else $base['pass'] = $pass; }
        $nome = trim((string)($_POST['from_name'] ?? ''));
        if ($nome !== '') { if (mb_strlen($nome) > 60 || preg_match('/[<>"\r\n]/', $nome)) $erros[] = 'Nome do remetente: até 60 caracteres, sem aspas nem sinais de maior ou menor.'; else $base['from_name'] = $nome; }
        $not = trim((string)($_POST['notificar'] ?? ''));
        if ($not !== '') { if (!filter_var($not, FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail dos avisos de novo cadastro: endereço inválido.'; else $base['notificar'] = $not; }
        $base['port'] = 465;
        if (!$erros && (empty($base['host']) || empty($base['user']) || empty($base['pass']))) $erros[] = 'Faltam servidor, usuário ou senha. Use "Importar do arquivo atual" ou preencha os três.';
        if ($erros) foreach ($erros as $e) $avisos[] = [$e, true];
        else { wa_segredo_gravar($pdo, 'smtp_config', $base); $avisos[] = ['Salvo no banco de dados. Faça o teste abaixo para confirmar.', false]; }
    }

    if ($acao === 'testar') {
        intusSmtpConfig(true);   // relê o banco (a tela pode ter acabado de salvar)
        $para = trim((string)($_POST['para'] ?? ''));
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) $avisos[] = ['Digite um e-mail válido para receber o teste.', true];
        else { [$ok, $msg] = backupSmtpEnviar($para, 'Teste do e-mail do site - Intus Fit', "Teste do servidor de e-mail configurado em admin/smtp-config.php.\n" . date('d/m/Y H:i:s')); $teste = [$ok, $msg]; }
    }
}

intusSmtpConfig(true);
$origem = intusSmtpConfigOrigem();
$db = wa_segredo($pdo, 'smtp_config') ?: [];
$cfg = intusSmtpConfig();
$arqTem = !empty(intusSmtpConfigArquivo()['pass']);
$destPadrao = _backupEmailConfigurado() ?: (string)($cfg['user'] ?? '');

function quando(PDO $pdo, string $chave): string {
    try {
        $st = $pdo->prepare('SELECT updated_at FROM intus_secrets WHERE chave = ?');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        return $v ? ('atualizado em ' . $v) : 'ainda não configurado no banco';
    } catch (Throwable $e) { return 'configurado'; }
}
function chip(bool $ok, string $rotulo): string {
    return '<span class="chip ' . ($ok ? 'ok' : 'falta') . '">' . ($ok ? 'configurado' : 'falta') . ' · ' . h($rotulo) . '</span>';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>E-mail do site · Intus</title>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;max-width:680px;margin:32px auto;padding:0 20px 60px;}
  h2{font-size:20px;margin:0 0 6px;} h3{font-size:14px;letter-spacing:.08em;text-transform:uppercase;color:#aaa;margin:32px 0 10px;}
  p{font-size:13px;color:#aaa;line-height:1.55;margin:6px 0;} code{background:#1a1a1a;padding:1px 6px;border-radius:4px;color:#7FFF00;word-break:break-all;}
  label{display:block;font-size:12px;font-weight:600;color:#bbb;margin:14px 0 5px;}
  input[type=text],input[type=password],input[type=email]{width:100%;box-sizing:border-box;background:#1a1a1a;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:8px;padding:11px 12px;font:14px ui-monospace,Menlo,Consolas,monospace;}
  input:focus{outline:2px solid #7FFF00;outline-offset:1px;}
  button{background:#7FFF00;color:#111;border:none;border-radius:8px;padding:12px 20px;font-weight:800;cursor:pointer;margin-top:18px;font-size:14px;}
  button.sec{background:transparent;color:#f0f0f0;border:1px solid #2a2a2a;}
  .aviso{padding:10px 14px;border-radius:8px;background:#1a2b0f;border:1px solid #3a5c1a;margin:10px 0;font-size:13px;line-height:1.5;}
  .aviso.erro{background:#2b0f0f;border-color:#5c1a1a;}
  .chips{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 2px;}
  .chip{font-size:11px;padding:3px 9px;border-radius:99px;border:1px solid #2a2a2a;background:#1a1a1a;}
  .chip.ok{color:#7FFF00;border-color:#3a5c1a;} .chip.falta{color:#ffb84d;border-color:#5c4a1a;}
  .caixa{background:#161616;border:1px solid #2a2a2a;border-radius:10px;padding:14px 16px;margin:10px 0;}
</style>
</head>
<body>
<h2>E-mail do site (SMTP)</h2>
<p>Servidor que envia os e-mails do app: código de acesso, redefinição de senha, avisos de cadastro e de backup. Fica guardado no banco de dados, não em arquivo. Esta página nunca mostra a senha salva. Campo em branco mantém o valor atual.</p>

<?php foreach ($avisos as [$txt, $err]): ?><div class="aviso<?= $err ? ' erro' : '' ?>"><?= h($txt) ?></div><?php endforeach; ?>
<?php if ($teste !== null): ?><div class="aviso<?= $teste[0] ? '' : ' erro' ?>"><?= $teste[0] ? 'Funcionou. ' : 'Não funcionou. ' ?><?= h($teste[1]) ?></div><?php endif; ?>

<div class="caixa">
  <p><b>De onde vem a configuração em uso agora:</b>
  <?php if ($origem === 'banco'): ?><span style="color:#7FFF00;">banco de dados</span> (<?= h(quando($pdo, 'smtp_config')) ?>)
  <?php elseif ($origem === 'arquivo'): ?><span style="color:#ffb84d;">arquivo antigo app/config/smtp.php</span>. Use "Importar do arquivo atual" abaixo para passar para o banco.
  <?php else: ?><span style="color:#ff8a8a;">nenhuma</span>. Preencha abaixo.<?php endif; ?></p>
</div>

<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">

<?php if ($origem !== 'banco' && $arqTem): ?>
<h3>Passar do arquivo para o banco</h3>
<p>Copia servidor, usuário e senha do arquivo antigo para o banco, direto no servidor, sem a senha aparecer na tela.</p>
<button type="submit" name="acao" value="importar">Importar do arquivo atual</button>
<?php endif; ?>

<h3>Servidor de e-mail</h3>
<div class="chips">
  <?= chip(!empty($cfg['host']), 'servidor') ?>
  <?= chip(!empty($cfg['user']), 'usuário') ?>
  <?= chip(!empty($cfg['pass']), 'senha') ?>
</div>
<label for="host">Servidor (SMTP, porta 465 com SSL)</label>
<input type="text" id="host" name="host" placeholder="<?= h($cfg['host'] ?? 'smtp.intusfit.com.br') ?>">
<label for="user">Usuário (o e-mail completo)</label>
<input type="email" id="user" name="user" placeholder="<?= h($cfg['user'] ?? 'contato@intusfit.com.br') ?>">
<label for="pass">Senha do e-mail</label>
<input type="password" id="pass" name="pass" autocomplete="new-password" placeholder="<?= !empty($cfg['pass']) ? 'salva (em branco mantém)' : 'digite aqui' ?>">
<label for="from_name">Nome que aparece como remetente</label>
<input type="text" id="from_name" name="from_name" maxlength="60" placeholder="<?= h($cfg['from_name'] ?? 'Intus Fit') ?>">
<label for="notificar">E-mail que recebe o aviso de novo cadastro (opcional)</label>
<input type="email" id="notificar" name="notificar" placeholder="<?= h($cfg['notificar'] ?? 'contato@intusfit.com.br') ?>">
<button type="submit" name="acao" value="salvar">Salvar</button>

<h3>Testar</h3>
<p>Manda uma mensagem de teste pelo servidor configurado, com o certificado verificado, e mostra o motivo exato se falhar.</p>
<label for="para">Enviar o teste para</label>
<input type="email" id="para" name="para" value="<?= h($destPadrao) ?>">
<button type="submit" name="acao" value="testar" class="sec">Enviar e-mail de teste</button>
</form>

<h3>Trocando a senha do e-mail</h3>
<div class="caixa">
  <p>1. No painel da KingHost, troque a senha do e-mail <code><?= h($cfg['user'] ?? 'contato@intusfit.com.br') ?></code>.</p>
  <p>2. Aqui, digite a senha nova no campo "Senha do e-mail", clique em Salvar e depois em "Enviar e-mail de teste".</p>
  <p>3. Só depois do teste passar o arquivo <code>app/config/smtp.php</code> pode sair do repositório (a senha antiga fica inútil).</p>
</div>
</body>
</html>
