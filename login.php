<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (too_many_attempts()) {
        $error = 'Zu viele Fehlversuche. Bitte warte ein paar Minuten.';
    } else {
        $password = $_POST['password'] ?? '';
        if (password_verify($password, APP_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['login_attempts'] = 0;
            header('Location: index.php');
            exit;
        }
        register_failed_attempt();
        $error = 'Falsches Passwort.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Anmelden – <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body class="login-body">
    <div class="login-card">
        <div class="login-mark">🔐</div>
        <h1><?= htmlspecialchars(APP_NAME) ?></h1>
        <p class="login-sub">Bitte melde dich an, um fortzufahren.</p>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" class="login-form">
            <input type="password" name="password" placeholder="Passwort" autofocus required>
            <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
        </form>
    </div>
</body>
</html>
