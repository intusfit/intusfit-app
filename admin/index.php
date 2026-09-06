<?php
require_once 'config.php';
session_name(SESSION_KEY);
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['user'] ?? '');
    $pass = $_POST['pass'] ?? '';
    if ($user === ADMIN_USER && password_verify($pass, ADMIN_PASS)) {
        $_SESSION['auth'] = true;
        $_SESSION['login_time'] = time();
        header('Location: painel.php');
        exit;
    }
    $error = 'Usuário ou senha incorretos.';
    sleep(1); // brute-force protection
}

// Já logado
if (!empty($_SESSION['auth'])) {
    header('Location: painel.php');
    exit;
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Intus Fit</title>
<link rel="icon" type="image/png" href="/app/painel/logo-intus-dark.png">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;background:#0A0A0A;color:#F5F5F5;min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-box{background:#111;border:1px solid #222;border-radius:16px;padding:48px 40px;width:100%;max-width:380px}
.logo{text-align:center;margin-bottom:32px}
.logo img{height:32px;filter:brightness(1.1)}
h1{font-size:20px;font-weight:700;margin-bottom:6px;text-align:center}
.sub{font-size:13px;color:#7A7A7A;text-align:center;margin-bottom:32px}
label{display:block;font-size:12px;font-weight:600;color:#C4C4C4;margin-bottom:6px;letter-spacing:.5px;text-transform:uppercase}
input{width:100%;background:#161616;border:1px solid #2a2a2a;border-radius:8px;padding:12px 14px;color:#F5F5F5;font-size:15px;outline:none;transition:border-color .2s;margin-bottom:20px}
input:focus{border-color:#CC2936}
.btn{width:100%;background:#CC2936;color:#fff;border:none;border-radius:8px;padding:13px;font-size:15px;font-weight:700;cursor:pointer;transition:background .2s}
.btn:hover{background:#991F2A}
.error{background:rgba(204,41,54,.12);border:1px solid rgba(204,41,54,.3);border-radius:8px;padding:10px 14px;font-size:13px;color:#ff6b7a;margin-bottom:20px}
.lock{text-align:center;margin-bottom:24px;opacity:.4;font-size:36px}
</style>
</head>
<body>
<div class="login-box">
  <div class="logo"><img src="/app/painel/logo-intus-dark.png" alt="Intus Fit"></div>
  <div class="lock">🔒</div>
  <h1>Área Administrativa</h1>
  <p class="sub">Acesso restrito — somente equipe autorizada</p>
  <?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST" autocomplete="off">
    <label>Usuário</label>
    <input type="text" name="user" placeholder="usuário" required autofocus autocomplete="username">
    <label>Senha</label>
    <input type="password" name="pass" placeholder="••••••••••" required autocomplete="current-password">
    <button type="submit" class="btn">Entrar no painel</button>
  </form>
</div>
</body>
</html>
