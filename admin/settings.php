<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Settings;

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $infoEnabled = isset($_POST['log_info_enabled']) ? '1' : '0';
        $debugEnabled = isset($_POST['log_debug_enabled']) ? '1' : '0';

        Settings::set('log_info_enabled', $infoEnabled);
        Settings::set('log_debug_enabled', $debugEnabled);

        $success = 'Настройки успешно сохранены.';
    } catch (\Throwable $e) {
        $error = 'Ошибка при сохранении: ' . $e->getMessage();
    }
}

$infoEnabled = Settings::isEnabled('log_info_enabled');
$debugEnabled = Settings::isEnabled('log_debug_enabled');

adminHeader('Настройки', 'settings');
?>

<style>
    .settings-container {
        max-width: 600px;
    }
    .settings-card {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 32px;
        margin-bottom: 24px;
    }
    .settings-group {
        margin-bottom: 24px;
    }
    .settings-group h3 {
        font-size: 16px;
        margin-bottom: 16px;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .toggle-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: var(--bg-card-alt);
        border-radius: 8px;
        margin-bottom: 8px;
        border: 1px solid transparent;
        transition: border-color 0.2s;
    }
    .toggle-row:hover {
        border-color: var(--border);
    }
    .toggle-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .toggle-label {
        font-size: 14px;
        font-weight: 500;
        color: var(--text-primary);
    }
    .toggle-desc {
        font-size: 12px;
        color: var(--text-secondary);
    }

    /* Simple Switch Style */
    .switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
    }
    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #333;
        transition: .4s;
        border-radius: 24px;
    }
    .slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }
    input:checked + .slider {
        background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
    }
    input:checked + .slider:before {
        transform: translateX(20px);
    }

    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    .alert-success { background: rgba(102,187,106,0.15); color: var(--success); border: 1px solid rgba(102,187,106,0.2); }
    .alert-error { background: rgba(239,83,80,0.15); color: var(--danger); border: 1px solid rgba(239,83,80,0.2); }

    .form-actions {
        display: flex;
        justify-content: flex-end;
    }
</style>

<div class="settings-container">
    <?php if ($success): ?>
        <div class="alert alert-success">✅ <?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="settings-card">
            <div class="settings-group">
                <h3>📋 Логирование</h3>
                
                <div class="toggle-row">
                    <div class="toggle-info">
                        <span class="toggle-label">Уровень Info</span>
                        <span class="toggle-desc">Логировать токены, модели и ID запросов к LLM</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="log_info_enabled" <?= $infoEnabled ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="toggle-row">
                    <div class="toggle-info">
                        <span class="toggle-label">Уровень Debug</span>
                        <span class="toggle-desc">Логировать полные тексты запросов/ответов LLM и Telegram</span>
                    </div>
                    <label class="switch">
                        <input type="checkbox" name="log_debug_enabled" <?= $debugEnabled ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Сохранить изменения</button>
            </div>
        </div>
    </form>

    <div class="settings-card" style="opacity: 0.7;">
        <div class="settings-group" style="margin-bottom: 0;">
            <h3>ℹ️ Информация</h3>
            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.6;">
                Уровни логирования применяются мгновенно. Логи доступны в разделе <a href="logs.php" style="color: var(--accent-primary);">📋 Логи</a>.
                Использование уровня Debug может значительно увеличить размер лог-файлов при большом потоке сообщений.
            </p>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
