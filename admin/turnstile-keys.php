<?php
/**
 * Chaves do Cloudflare Turnstile (verificação extra do login do aluno), na área logada do admin.
 *
 * Mesmo padrão de admin/asaas-keys.php: as chaves moram no BANCO (intus_secrets, chave turnstile_config), nunca no código nem em arquivo.
 * Quem preenche é o Luiz. Esta página nunca mostra de volta a chave secreta salva, só diz se está configurada.
 * Sem chaves, o login continua protegido pelo teto global com limites mais apertados (ver app/api/_login_protecao.php).
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

require_once __DIR__ . '/../app/api/_login_protecao.php';
$cfgBanco = @include __DIR__ . '/../app/config/db.php';
try {
    // o db.php pode devolver um array (host, database...) ou definir constantes (DB_HOST...): o mesmo que admin/asaas-keys.php aceita
    if (is_array($cfgBanco) && isset($cfgBanco['host'])) {
        $pdo = new PDO("mysql:host={$cfgBanco['host']};dbname={$cfgBanco['database']};charset=utf8mb4", $cfgBanco['username'], $cfgBanco['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) { @error_log('[turnstile-keys] ' . $e->getMessage()); http_response_code(500); exit('Falha ao conectar no banco.'); }
intusLoginSecretsDdl($pdo);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['turnstile_csrf'])) $_SESSION['turnstile_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['turnstile_csrf'];

$avisos = [];   // [texto, erro?]

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Sessão expirada. Volte e recarregue a página.'); }
    $acao = (string)($_POST['acao'] ?? '');
    $cfg = intusLoginCfg($pdo);

    if ($acao === 'salvar') {
        $site = trim((string)($_POST['site_key'] ?? ''));
        $sec = trim((string)($_POST['secret_key'] ?? ''));
        $teto = (int)($_POST['teto'] ?? INTUS_LOGIN_TETO_PADRAO);
        $erro = false;
        if ($site !== '') {
            if (!preg_match('/^[0-9A-Za-z_-]{10,100}$/', $site)) { $avisos[] = ['Chave do site: de 10 a 100 letras, números, - ou _, sem espaços.', true]; $erro = true; }
            else $cfg['site_key'] = $site;
        }
        if ($sec !== '') {
            if (!preg_match('/^[0-9A-Za-z_-]{10,100}$/', $sec)) { $avisos[] = ['Chave secreta: de 10 a 100 letras, números, - ou _, sem espaços.', true]; $erro = true; }
            else $cfg['secret_key'] = $sec;
        }
        if ($teto < 5 || $teto > 1000) { $avisos[] = ['Teto de falhas por minuto: entre 5 e 1000.', true]; $erro = true; }
        else $cfg['teto'] = $teto;
        if (!$erro) { intusLoginCfgGravar($pdo, $cfg); $avisos[] = ['Salvo no banco de dados.', false]; }
    }
    if ($acao === 'testar') {
        if (!intusTurnstileAtivo($cfg)) $avisos[] = ['Salve as duas chaves antes de testar.', true];
        else {
            // um token inventado com a chave secreta certa volta "token inválido"; com a chave errada volta "chave secreta inválida"
            $v = intusTurnstileVerificar($cfg['secret_key'], 'teste-de-configuracao', intusClientIp());
            if (in_array('invalid-input-secret', $v['codigos'], true) || in_array('missing-input-secret', $v['codigos'], true)) $avisos[] = ['O Cloudflare recusou a chave secreta. Confira se é a secreta (não a do site) do mesmo widget.', true];
            elseif ($v['indisponivel']) $avisos[] = ['Não consegui falar com o Cloudflare agora (' . implode(', ', $v['codigos']) . ').', true];
            else $avisos[] = ['O Cloudflare aceitou a chave secreta. Falta só conferir se o widget da tela de login aparece (o domínio intusfit.com.br precisa estar na lista do widget).', false];
        }
    }
    if ($acao === 'remover') {
        $cfg['site_key'] = ''; $cfg['secret_key'] = '';
        intusLoginCfgGravar($pdo, $cfg);
        $avisos[] = ['Chaves removidas. O login volta a usar só os limites mais apertados no modo de verificação.', false];
    }
}

$cfg = intusLoginCfg($pdo);
$st = intusLoginStatus($pdo);
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
<title>Turnstile (verificação do login) · Intus</title>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;max-width:680px;margin:32px auto;padding:0 20px 60px;}
  h2{font-size:20px;margin:0 0 6px;} h3{font-size:14px;letter-spacing:.08em;text-transform:uppercase;color:#aaa;margin:32px 0 10px;}
  p,li{font-size:13px;color:#aaa;line-height:1.55;margin:6px 0;} code{background:#1a1a1a;padding:1px 6px;border-radius:4px;color:#7FFF00;word-break:break-all;}
  label{display:block;font-size:12px;font-weight:600;color:#bbb;margin:14px 0 5px;}
  input[type=text],input[type=password],input[type=number]{width:100%;box-sizing:border-box;background:#1a1a1a;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:8px;padding:11px 12px;font:14px ui-monospace,Consolas,monospace;}
  input:focus{outline:2px solid #7FFF00;outline-offset:1px;}
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
<h2>Turnstile: verificação extra do login do aluno</h2>
<p>Quando muitas tentativas de login erradas chegam de IPs diferentes ao mesmo tempo, o login pede uma verificação do Cloudflare Turnstile (quase sempre invisível). Quem é pessoa entra normalmente; robô não passa. As chaves ficam guardadas no banco de dados, não no código. Esta página nunca mostra de volta a chave secreta salva. Campo em branco mantém o valor atual.</p>

<?php foreach ($avisos as [$txt, $err]): ?><div class="aviso<?= $err ? ' erro' : '' ?>"><?= h($txt) ?></div><?php endforeach; ?>

<h3>Situação agora</h3>
<div class="caixa">
  <p>Falhas de login no último minuto (todos os IPs somados): <b><?= (int)$st['falhas_ultimo_minuto'] ?></b> de um teto de <b><?= (int)$st['teto'] ?></b>.</p>
  <p>Modo de verificação: <b><?= $st['pressao'] ? 'LIGADO até ' . h(date('H:i', strtotime((string)$st['pressao_ate']))) . ' (' . h(date_default_timezone_get()) . ')' : 'desligado' ?></b>.
     <?= $st['pressao'] ? ($st['turnstile'] ? 'Todo login precisa do Turnstile.' : 'Sem chaves do Turnstile: valem os limites mais apertados (2 erros por IP e 3 por conta em 15 min).') : '' ?></p>
</div>
<div class="chips"><?= chip($cfg['site_key'] !== '', 'chave do site') ?><?= chip($cfg['secret_key'] !== '', 'chave secreta') ?></div>

<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">

<h3>1. Criar o widget no Cloudflare</h3>
<p>No painel do Cloudflare: Turnstile &gt; Adicionar widget. Nome: <code>Login Intus</code>. Domínios: <code>intusfit.com.br</code> (e <code>localhost</code>, se for usar o app das lojas). Modo: <b>Gerenciado</b>. O Cloudflare mostra duas chaves: a do <b>site</b> (pública) e a <b>secreta</b>.</p>

<h3>2. Colar as chaves</h3>
<label for="site_key">Chave do site (pública)</label>
<input type="text" id="site_key" name="site_key" value="<?= h($cfg['site_key']) ?>" placeholder="0x4AAAAAAA...">
<label for="secret_key">Chave secreta</label>
<input type="password" id="secret_key" name="secret_key" autocomplete="new-password" placeholder="<?= $cfg['secret_key'] !== '' ? 'salva (em branco mantém)' : 'cole aqui' ?>">

<h3>3. Teto de falhas por minuto</h3>
<p>Quantas senhas erradas por minuto, somando todos os IPs, ligam o modo de verificação por 10 minutos. Padrão 20 (com poucas centenas de alunos, falha normal fica bem abaixo disso).</p>
<label for="teto">Teto (5 a 1000)</label>
<input type="number" id="teto" name="teto" min="5" max="1000" value="<?= (int)$cfg['teto'] ?>">

<button type="submit" name="acao" value="salvar">Salvar</button>
<button type="submit" name="acao" value="testar" class="sec">Testar a chave secreta</button>
<button type="submit" name="acao" value="remover" class="sec" onclick="return confirm('Remover as duas chaves? O login volta a usar só os limites mais apertados.')">Remover as chaves</button>
</form>

<h3>Como funciona</h3>
<ul>
  <li>Normalmente nada muda para o aluno: o widget só aparece na tela de login quando o modo de verificação está ligado.</li>
  <li>Sem as chaves (ou com o Cloudflare fora do ar), o modo de verificação aperta os limites de erro por IP e por conta, sem derrubar quem acerta a senha de primeira. Isso é mais fraco: só o Turnstile barra robô de verdade.</li>
  <li>O app das lojas (Capacitor) precisa ser testado com o Turnstile antes de publicar.</li>
</ul>
</body>
</html>
