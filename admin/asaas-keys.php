<?php
/**
 * Chaves do Asaas (cobrança das mensalidades da Intus), na área logada do admin.
 *
 * Mesmo padrão de admin/whatsapp-keys.php: as chaves moram no BANCO (intus_secrets, chave asaas_config), nunca em arquivo.
 * Quem preenche é o Luiz. Esta página nunca mostra de volta a chave de API salva, só diz se está configurada.
 * O token do webhook é gerado aqui e mostrado UMA vez, para colar no Asaas.
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

require_once __DIR__ . '/../app/api/_asaas.php';
$cfgBanco = @include __DIR__ . '/../app/config/db.php';
try {
    // o db.php pode devolver um array (host, database...) ou definir constantes (DB_HOST...): o mesmo que app/api/asaas.php e app/api/catalogo.php aceitam
    if (is_array($cfgBanco) && isset($cfgBanco['host'])) {
        $pdo = new PDO("mysql:host={$cfgBanco['host']};dbname={$cfgBanco['database']};charset=utf8mb4", $cfgBanco['username'], $cfgBanco['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) { @error_log('[asaas-keys] ' . $e->getMessage()); http_response_code(500); exit('Falha ao conectar no banco.'); }
asaas_garantir_tabelas($pdo);

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['asaas_csrf'])) $_SESSION['asaas_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['asaas_csrf'];

$avisos = [];   // [texto, erro?]
$tokenNovo = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Sessão expirada. Volte e recarregue a página.'); }
    $acao = (string)($_POST['acao'] ?? '');
    $cfg = asaas_cfg($pdo);

    if ($acao === 'salvar') {
        $amb = (string)($_POST['ambiente'] ?? $cfg['ambiente']);
        $cfg['ambiente'] = $amb === 'sandbox' ? 'sandbox' : 'producao';
        $key = trim((string)($_POST['apikey'] ?? ''));
        if ($key !== '') {
            if (!preg_match('/^\S{20,400}$/', $key)) $avisos[] = ['Chave de API: pelo menos 20 caracteres, sem espaços.', true];
            else $cfg['apikey'] = $key;
        }
        if (!array_filter($avisos, function ($a) { return $a[1]; })) { asaas_cfg_gravar($pdo, $cfg); $avisos[] = ['Salvo no banco de dados.', false]; }
    }
    if ($acao === 'token') {
        $cfg['token'] = bin2hex(random_bytes(24));   // 48 caracteres: dentro dos 32 a 255 que o Asaas exige
        asaas_cfg_gravar($pdo, $cfg);
        $tokenNovo = $cfg['token'];
        $avisos[] = ['Token gerado. Copie agora e cole no webhook do Asaas: ele não aparece de novo.', false];
    }
    if ($acao === 'testar') {
        try {
            [$st] = asaas_http($pdo, 'GET', '/customers?limit=1');
            if ($st >= 200 && $st < 300) $avisos[] = ['Conectado ao Asaas (' . ($cfg['ambiente'] === 'sandbox' ? 'ambiente de testes' : 'produção') . ').', false];
            elseif ($st === 401 || $st === 403) $avisos[] = ['O Asaas recusou a chave. Confira se ela é do mesmo ambiente escolhido aqui.', true];
            else $avisos[] = ['O Asaas respondeu com erro ' . $st . '.', true];
        } catch (Throwable $e) {
            $avisos[] = [$e->getMessage() === 'chave_api_ausente' ? 'Salve a chave de API antes de testar.' : 'Não consegui falar com o Asaas agora.', true];
        }
    }
}

$cfg = asaas_cfg($pdo);
$urlAviso = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'intusfit.com.br') . '/app/api/asaas.php?action=webhook';
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
<title>Asaas (cobrança) · Intus</title>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;max-width:680px;margin:32px auto;padding:0 20px 60px;}
  h2{font-size:20px;margin:0 0 6px;} h3{font-size:14px;letter-spacing:.08em;text-transform:uppercase;color:#aaa;margin:32px 0 10px;}
  p,li{font-size:13px;color:#aaa;line-height:1.55;margin:6px 0;} code{background:#1a1a1a;padding:1px 6px;border-radius:4px;color:#7FFF00;word-break:break-all;}
  label{display:block;font-size:12px;font-weight:600;color:#bbb;margin:14px 0 5px;}
  input[type=text],input[type=password],select{width:100%;box-sizing:border-box;background:#1a1a1a;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:8px;padding:11px 12px;font:14px ui-monospace,Consolas,monospace;}
  input:focus,select:focus{outline:2px solid #7FFF00;outline-offset:1px;}
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
<h2>Asaas: cobrança das mensalidades</h2>
<p>Gera o link de pagamento (Pix, boleto e cartão) de uma mensalidade e avisa o painel quando o pagamento entra. As chaves ficam guardadas no banco de dados, não em arquivo. Esta página nunca mostra de volta a chave salva. Campo em branco mantém o valor atual.</p>

<?php foreach ($avisos as [$txt, $err]): ?><div class="aviso<?= $err ? ' erro' : '' ?>"><?= h($txt) ?></div><?php endforeach; ?>

<div class="chips"><?= chip($cfg['apikey'] !== '', 'chave de API') ?><?= chip($cfg['token'] !== '', 'token do webhook') ?></div>

<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= h($csrf) ?>">

<h3>1. Chave de API</h3>
<p>No Asaas: Integrações &gt; Chave de API. A chave de testes (sandbox) e a de produção são diferentes: escolha o ambiente certo.</p>
<label for="ambiente">Ambiente</label>
<select id="ambiente" name="ambiente">
  <option value="producao"<?= $cfg['ambiente'] === 'producao' ? ' selected' : '' ?>>Produção (cobranças de verdade)</option>
  <option value="sandbox"<?= $cfg['ambiente'] === 'sandbox' ? ' selected' : '' ?>>Testes (sandbox)</option>
</select>
<label for="apikey">Chave de API</label>
<input type="password" id="apikey" name="apikey" autocomplete="new-password" placeholder="<?= $cfg['apikey'] !== '' ? 'salva (em branco mantém)' : 'cole aqui' ?>">
<button type="submit" name="acao" value="salvar">Salvar</button>
<button type="submit" name="acao" value="testar" class="sec">Testar conexão</button>

<h3>2. Aviso de pagamento (webhook)</h3>
<p>No Asaas: Integrações &gt; Webhooks &gt; criar webhook de <b>cobranças</b> com estes dados (o webhook do CDD é outro, com outro endereço):</p>
<div class="caixa">
  <p>URL: <code><?= h($urlAviso) ?></code></p>
  <p>Token de autenticação: <?= $tokenNovo !== '' ? '<code>' . h($tokenNovo) . '</code>' : ($cfg['token'] !== '' ? 'já gerado (por segurança não é mostrado de novo; gere outro se precisar)' : 'ainda não gerado') ?></p>
  <p>Eventos: cobrança confirmada, recebida, estornada, chargeback solicitado e excluída.</p>
</div>
<button type="submit" name="acao" value="token" class="sec"><?= $cfg['token'] !== '' ? 'Gerar um novo token (o webhook atual para de funcionar até colar o novo)' : 'Gerar token' ?></button>
</form>

<h3>Como funciona</h3>
<ul>
  <li>Em Matrículas, o botão <b>Cobrar</b> gera o link da mensalidade e a equipe manda pelo WhatsApp. O cliente precisa ter um CPF válido no cadastro.</li>
  <li>Quando o pagamento entra, aparece um aviso no topo de Matrículas e a equipe dá a baixa com um toque. O ciclo seguinte nasce na baixa, como sempre.</li>
  <li>As notificações do Asaas (e-mail e SMS, estes cobrados) ficam desligadas para esses clientes: quem avisa é a equipe.</li>
</ul>
</body>
</html>
