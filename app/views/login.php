<?php /** Вход по одному паролю. */ ?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Вход · Civi</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="login-page">
<form method="post" action="<?= h(url('login')) ?>" class="login-box">
  <h1>Civi · админка</h1>
  <?php if (!empty($error)): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>
  <?php if (empty($config['admin_password_hash'])): ?>
    <div class="flash error">
      В config/config.php не задан admin_password_hash — сгенерируйте его командой
      <code>php tools/make-password-hash.php</code>
    </div>
  <?php endif; ?>
  <label>Пароль
    <input type="password" name="password" autocomplete="current-password" autofocus required>
  </label>
  <button type="submit" class="primary">Войти</button>
</form>
</body>
</html>
