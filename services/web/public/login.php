<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/UserManager.php';
require_once __DIR__ . '/../src/Auth.php';

use App\Database;
use App\UserManager;
use App\Auth;

$db = Database::getInstance();
$userManager = new UserManager($db);

Auth::start();

// Already logged in — go to dashboard
if (Auth::isLoggedIn()) {
    header('Location: /');
    exit;
}

// No users yet — skip login entirely
if (!$userManager->hasUsers()) {
    header('Location: /');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (Auth::login($username, $password, $userManager)) {
        header('Location: /');
        exit;
    }
    $error = 'Invalid username or password.';
}

$settings = $db->getSettings();
$systemName = htmlspecialchars($settings['system_name'] ?? 'Lightbox-Server');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | <?= $systemName ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-main); }
        .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .login-logo { text-align: center; margin-bottom: 28px; }
        .login-logo h1 { font-size: 1.5rem; color: var(--text-primary); margin: 0 0 4px; }
        .login-logo p { color: var(--text-secondary); font-size: 0.875rem; margin: 0; }
        .login-error {
            background: rgba(244, 63, 94, 0.12);
            border: 1px solid rgba(244, 63, 94, 0.35);
            color: var(--color-red);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 0.875rem;
            margin-bottom: 16px;
        }
        .login-card .form-group { margin-bottom: 16px; }
        .login-card label { display: block; font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 6px; }
        .login-card .btn { width: 100%; margin-top: 8px; padding: 12px; font-size: 1rem; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-logo">
            <h1>Lightbox Server</h1>
            <p>Sign in to <?= $systemName ?></p>
        </div>

        <?php if ($error): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/login.php" autocomplete="on">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                       autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>
    </div>
</body>
</html>
