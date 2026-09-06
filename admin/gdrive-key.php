<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();

if (empty($_SESSION['auth']) || (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
    session_destroy();
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../app/config/db.php';
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE IF NOT EXISTS intus_secrets (
    chave VARCHAR(80) PRIMARY KEY,
    valor LONGTEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$msg = '';
$erro = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['json'])) {
    $raw = trim($_POST['json']);
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || empty($parsed['client_email']) || empty($parsed['private_key'])) {
        $msg = 'JSON inválido — confira se colou o conteúdo completo (precisa ter "client_email" e "private_key").';
        $erro = true;
    } elseif (empty($parsed['folder_id'])) {
        $msg = 'Falta o campo "folder_id" no JSON — adicione antes de salvar (é o ID da pasta do Google Drive).';
        $erro = true;
    } else {
        $st = $pdo->prepare("INSERT INTO intus_secrets (chave, valor) VALUES ('gdrive_config', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $st->execute([$raw]);
        $msg = 'Salvo no banco de dados. O backup de fotos no Drive já passa a usar esta chave.';
    }
}

$st = $pdo->prepare("SELECT updated_at FROM intus_secrets WHERE chave = 'gdrive_config'");
$st->execute();
$row = $st->fetch(PDO::FETCH_ASSOC);
$statusTxt = $row ? ('Configurado — última atualização em ' . $row['updated_at']) : 'Ainda não configurado';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chave do Google Drive — Intus</title>
<style>
  body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#0f0f0f;color:#f0f0f0;max-width:640px;margin:40px auto;padding:0 20px;}
  h2{font-size:18px;}
  textarea{width:100%;height:260px;background:#1a1a1a;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:8px;padding:12px;font-family:ui-monospace,monospace;font-size:12px;box-sizing:border-box;}
  button{background:#7FFF00;color:#111;border:none;border-radius:8px;padding:12px 20px;font-weight:800;cursor:pointer;margin-top:12px;font-size:14px;}
  .status{padding:10px 14px;border-radius:8px;background:#1a1a1a;border:1px solid #2a2a2a;margin-bottom:16px;font-size:13px;}
  .msg{padding:10px 14px;border-radius:8px;background:#1a2b0f;border:1px solid #3a5c1a;margin-bottom:16px;font-size:13px;}
  .msg.erro{background:#2b0f0f;border-color:#5c1a1a;}
  p{font-size:13px;color:#aaa;line-height:1.5;}
  code{background:#1a1a1a;padding:1px 5px;border-radius:4px;}
</style>
</head>
<body>
<h2>Chave do Google Drive (backup de fotos de avaliação)</h2>
<div class="status">Status: <?= htmlspecialchars($statusTxt) ?></div>
<?php if ($msg): ?><div class="msg<?= $erro ? ' erro' : '' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<p>Cole aqui o conteúdo completo do arquivo <code>.json</code> da conta de serviço do Google (o mesmo que você baixa do Cloud Console), já com a linha <code>"folder_id"</code> adicionada. Fica guardado no banco de dados — não em arquivo — por isso não some mais sozinho.</p>
<form method="post">
  <textarea name="json" placeholder='{"type":"service_account", "client_email":"...", "private_key":"...", "folder_id":"..."}'></textarea><br>
  <button type="submit">Salvar</button>
</form>
</body>
</html>
