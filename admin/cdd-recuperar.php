<?php
/**
 * Recuperar a senha do administrador do CDD (Código da Definição), na área logada do admin da Intus.
 *
 * O painel do CDD (cdd/painel.html) tem login próprio e não manda e-mail de troca de senha para a equipe. Quando a senha do administrador do
 * CDD se perde, esta página troca: entra com o login do admin da Intus, escolhe a conta e digita a senha nova duas vezes. A senha nunca é
 * mostrada nem gravada em texto: só o hash (bcrypt) vai para a tabela cdd_aluna. Só mexe em contas de administrador do CDD.
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

require_once __DIR__ . '/../app/config/db.php';          // só as constantes DB_* (a conexão é montada aqui)
require_once __DIR__ . '/../cdd/api/_recuperar.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) { http_response_code(500); exit('Falha ao conectar no banco.'); }

if (empty($_SESSION['cddrec_csrf'])) $_SESSION['cddrec_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['cddrec_csrf'];

$aviso = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Sessão expirada. Volte e recarregue a página.'); }
    try {
        $aviso = cddRecuperarTrocarSenha($pdo, (int)($_POST['idaluna'] ?? 0), (string)($_POST['nova'] ?? ''), (string)($_POST['confirma'] ?? ''), !empty($_POST['encerrar']));
    } catch (Throwable $e) { $aviso = [false, 'Não consegui gravar. Confira se o CDD já foi instalado (a tabela cdd_aluna precisa existir).']; }
}

try { $admins = cddRecuperarAdmins($pdo); } catch (Throwable $e) { $admins = []; $semTabela = true; }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Senha do admin do CDD</title>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;max-width:560px;margin:32px auto;padding:0 20px 60px;}
  h2{font-size:20px;margin:0 0 6px;}
  p{font-size:13px;color:#aaa;line-height:1.55;margin:6px 0;}
  label{display:block;font-size:12px;font-weight:600;color:#bbb;margin:14px 0 5px;}
  input[type=password],select{width:100%;box-sizing:border-box;background:#1a1a1a;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:8px;padding:11px 12px;font:14px system-ui,sans-serif;}
  input:focus,select:focus{outline:2px solid #7FFF00;outline-offset:1px;}
  .chk{display:flex;gap:8px;align-items:flex-start;font-size:13px;color:#ddd;margin:16px 0 0;font-weight:400;}
  button{background:#7FFF00;color:#111;border:none;border-radius:8px;padding:12px 20px;font-weight:800;cursor:pointer;margin-top:18px;font-size:14px;}
  .aviso{padding:10px 14px;border-radius:8px;background:#1a2b0f;border:1px solid #3a5c1a;margin:12px 0;font-size:13px;line-height:1.5;}
  .aviso.erro{background:#2b0f0f;border-color:#5c1a1a;}
  .caixa{background:#161616;border:1px solid #2a2a2a;border-radius:10px;padding:14px 16px;margin:12px 0;}
  a{color:#7FFF00;}
  .pw{position:relative;display:block} .pw input{padding-right:50px}
  .pw-ver{position:absolute;right:5px;top:50%;transform:translateY(-50%);width:40px;height:40px;border:0;background:transparent;font-size:20px;line-height:1;cursor:pointer;border-radius:8px;margin:0;padding:0;display:grid;place-items:center}
  .pw-ver:hover{background:#2a2a2a}
</style>
</head>
<body>
<h2>Senha do administrador do CDD</h2>
<p>Use quando a senha do painel do CDD (<code>/cdd/painel.html</code>) se perder. Você digita a senha nova duas vezes. Ela não aparece em lugar nenhum depois e não é enviada por e-mail.</p>

<?php if ($aviso): ?><div class="aviso<?= $aviso[0] ? '' : ' erro' ?>"><?= h($aviso[1]) ?><?php if ($aviso[0]): ?> Agora entre em <a href="/cdd/painel.html">/cdd/painel.html</a> com o e-mail da conta e a senha nova. Se aparecer "muitas tentativas", espere 15 minutos.<?php endif; ?></div><?php endif; ?>

<?php if (!empty($semTabela)): ?>
  <div class="aviso erro">O CDD ainda não foi instalado neste banco (a tabela cdd_aluna não existe). Abra <a href="/cdd/painel.html">/cdd/painel.html</a> e use "Primeiro acesso" com o e-mail do dono.</div>
<?php elseif (!$admins): ?>
  <div class="aviso erro">Não existe conta de administrador do CDD. Abra <a href="/cdd/painel.html">/cdd/painel.html</a> e use "Primeiro acesso" (só funciona se a instalação ainda estiver aberta).</div>
<?php else: ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <label for="idaluna">Conta</label>
  <select id="idaluna" name="idaluna">
    <?php foreach ($admins as $a): ?><option value="<?= (int)$a['idaluna'] ?>"><?= h($a['email']) ?> (<?= h($a['nome']) ?><?= $a['status'] !== 'ativa' ? ', ' . h($a['status']) : '' ?>)</option><?php endforeach; ?>
  </select>
  <label for="nova">Senha nova (mínimo 8 caracteres)</label>
  <input type="password" id="nova" name="nova" minlength="8" maxlength="200" autocomplete="new-password" required>
  <label for="confirma">Repita a senha nova</label>
  <input type="password" id="confirma" name="confirma" minlength="8" maxlength="200" autocomplete="new-password" required>
  <label class="chk"><input type="checkbox" name="encerrar" value="1" checked> <span>Encerrar as sessões já abertas dessa conta (recomendado: derruba quem estiver logado com a senha antiga)</span></label>
  <button type="submit">Trocar a senha</button>
</form>
<?php endif; ?>

<div class="caixa"><p>Esta página só altera contas de <b>administrador</b> do CDD. Contas de alunas continuam sendo tratadas no painel do CDD (Alunas, botão Link). O login desta página é o do admin da Intus, não o do CDD.</p></div>
<p><a href="painel.php">Voltar ao admin da Intus</a></p>
<script>
// ver/ocultar a senha digitada (só no navegador; nada é enviado antes de clicar em Trocar a senha)
document.querySelectorAll('input[type=password]').forEach(function (inp) {
  var cx = document.createElement('span'); cx.className = 'pw';
  inp.parentNode.insertBefore(cx, inp); cx.appendChild(inp);
  var b = document.createElement('button'); b.type = 'button'; b.className = 'pw-ver'; b.setAttribute('aria-label', 'Mostrar a senha'); b.title = 'Mostrar a senha'; b.textContent = '👁️';
  b.onclick = function () {
    var vendo = inp.type === 'text';
    inp.type = vendo ? 'password' : 'text';
    b.textContent = vendo ? '👁️' : '🙈';
    b.setAttribute('aria-label', vendo ? 'Mostrar a senha' : 'Ocultar a senha'); b.title = b.getAttribute('aria-label');
    inp.focus();
  };
  cx.appendChild(b);
});
</script>
</body>
</html>
