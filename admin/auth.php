<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;

session_start();

function requireAuth(): void
{
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'], $_POST['password'])) {
        if ($_POST['login'] === Config::getAdminLogin() && $_POST['password'] === Config::getAdminPassword()) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }

    // Show login form
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head><meta charset="UTF-8"><title>Admin Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #0f0f1a; color: #e0e0e0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-box { background: linear-gradient(135deg, #1a1a2e, #16213e); padding: 40px; border-radius: 16px; box-shadow: 0 8px 32px rgba(0,0,0,0.5); width: 360px; }
        .login-box h2 { text-align: center; margin-bottom: 24px; background: linear-gradient(135deg, #e040fb, #7c4dff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .login-box input { width: 100%; padding: 12px 16px; margin-bottom: 16px; border-radius: 8px; border: 1px solid #333; background: #0f0f1a; color: #e0e0e0; font-size: 14px; }
        .login-box button { width: 100%; padding: 12px; border: none; border-radius: 8px; background: linear-gradient(135deg, #e040fb, #7c4dff); color: white; font-weight: 600; cursor: pointer; font-size: 15px; }
        .login-box button:hover { opacity: 0.9; }
    </style></head><body>
    <div class="login-box">
        <h2>💎 Prime/Glow Admin</h2>
        <form method="POST">
            <input type="text" name="login" placeholder="Login" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Войти</button>
        </form>
    </div>
    </body></html>
    <?php
    exit;
}

function adminHeader(string $title, string $activePage = ''): void
{
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — Prime/Glow Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
    <link rel="stylesheet" href="assets/css/admin.css">
    </head><body>
    <nav class="sidebar">
        <div class="sidebar-brand">💎 Prime/Glow</div>
        <a href="index.php" class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">📊 Dashboard</a>
        <a href="users.php" class="nav-link <?= $activePage === 'users' ? 'active' : '' ?>">👥 Пользователи</a>
        <a href="payments.php" class="nav-link <?= $activePage === 'payments' ? 'active' : '' ?>">💳 Платежи</a>
        <a href="texts.php" class="nav-link <?= $activePage === 'texts' ? 'active' : '' ?>">📝 Тексты</a>
        <a href="logs.php" class="nav-link <?= $activePage === 'logs' ? 'active' : '' ?>">📋 Логи</a>
        <a href="settings.php" class="nav-link <?= $activePage === 'settings' ? 'active' : '' ?>">⚙️ Настройки</a>
        <a href="?logout=1" class="nav-link logout">🚪 Выход</a>
    </nav>
    <main class="content">
    <h1><?= htmlspecialchars($title) ?></h1>
    <?php
}

function adminFooter(): void
{
    ?>
    </main></body></html>
    <?php
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}
