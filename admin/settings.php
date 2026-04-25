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
        $priceSub = $_POST['price_subscription'] ?? '1990';
        $priceCourse = $_POST['price_course'] ?? '10000';
 
        Settings::set('log_info_enabled', $infoEnabled);
        Settings::set('log_debug_enabled', $debugEnabled);
        Settings::set('price_subscription', $priceSub);
        Settings::set('price_course', $priceCourse);
 
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
 
    .input-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: var(--bg-card-alt);
        border-radius: 8px;
        margin-bottom: 8px;
        border: 1px solid transparent;
    }
    .settings-input {
        background: var(--bg-body);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 8px 12px;
        border-radius: 6px;
        width: 120px;
        font-family: inherit;
        font-size: 14px;
        text-align: right;
    }
    .settings-input:focus {
        border-color: var(--accent-primary);
        outline: none;
    }

    .file-row {
        align-items: center;
    }
    .file-actions {
        display: flex;
        gap: 8px;
    }
    .btn-upload {
        background: var(--bg-body);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s;
    }
    .btn-upload:hover {
        border-color: var(--accent-primary);
        color: var(--accent-primary);
    }
    .btn-upload.uploading {
        opacity: 0.6;
        pointer-events: none;
    }
    .btn-view {
        text-decoration: none;
        background: var(--bg-body);
        border: 1px solid var(--border);
        color: var(--text-primary);
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        transition: all 0.2s;
    }
    .btn-view:hover {
        border-color: var(--accent-primary);
        color: var(--accent-primary);
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
 
            <div class="settings-group">
                <h3>💰 Стоимость (руб.)</h3>
                
                <div class="input-row">
                    <div class="toggle-info">
                        <span class="toggle-label">Подписка на бота</span>
                    </div>
                    <input type="number" name="price_subscription" value="<?= htmlspecialchars(Settings::get('price_subscription', '1990')) ?>" class="settings-input">
                </div>
 
                <div class="input-row">
                    <div class="toggle-info">
                        <span class="toggle-label">Курс с Настей</span>
                    </div>
                    <input type="number" name="price_course" value="<?= htmlspecialchars(Settings::get('price_course', '10000')) ?>" class="settings-input">
                </div>
            </div>

            <div class="settings-group">
                <h3>🎁 Подарки (PDF)</h3>
                <p style="font-size: 12px; color: var(--text-secondary); margin-bottom: 16px;">
                    Эти PDF-файлы отправляются пользователям после прохождения квиза в зависимости от их архетипа.
                    Файлы загружаются мгновенно при выборе.
                </p>

                <?php 
                $personaMeta = [
                    'shadow_queen'    => ['label' => 'Тенистая Королева', 'emoji' => '👑'],
                    'iron_controller' => ['label' => 'Железный Контролер', 'emoji' => '⚙️'],
                    'sleeping_muse'   => ['label' => 'Спящая Муза', 'emoji' => '🎨'],
                    'magnetic_prime'  => ['label' => 'Магнитная Прайм', 'emoji' => '💎'],
                ];
                foreach ($personaMeta as $p => $meta): 
                    $currentFile = Settings::get('gift_pdf_' . $p);
                ?>
                <div class="input-row file-row" id="row_<?= $p ?>">
                    <div class="toggle-info">
                        <span class="toggle-label"><?= $meta['emoji'] ?> <?= $meta['label'] ?></span>
                        <span class="toggle-desc current-filename">Файл: <?= htmlspecialchars($currentFile ?: 'по умолчанию') ?></span>
                    </div>
                    <div class="file-actions">
                        <a href="view_gift.php?persona=<?= $p ?>" target="_blank" class="btn-view" title="Просмотреть">👁️</a>
                        <label class="btn-upload" id="label_<?= $p ?>">
                            📁 Изменить
                            <input type="file" accept="application/pdf" style="display: none;" onchange="uploadFile('<?= $p ?>', this)">
                        </label>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Сохранить общие настройки</button>
            </div>
        </div>
    </form>

    <script>
    async function uploadFile(persona, input) {
        const file = input.files[0];
        if (!file) return;

        const label = document.getElementById('label_' + persona);
        const row = document.getElementById('row_' + persona);
        const desc = row.querySelector('.current-filename');
        const originalText = label.innerText;

        // Show loading state
        label.classList.add('uploading');
        label.innerText = '⌛ Загрузка...';

        const formData = new FormData();
        formData.append('persona', persona);
        formData.append('file', file);

        try {
            const response = await fetch('ajax/upload_gift.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.ok) {
                label.innerText = '✅ Готово';
                desc.innerText = 'Файл: ' + result.filename;
                label.style.borderColor = 'var(--success)';
                setTimeout(() => {
                    label.innerText = '📁 Изменить';
                    label.classList.remove('uploading');
                    label.style.borderColor = '';
                }, 2000);
            } else {
                alert('Ошибка: ' + result.error);
                label.innerText = '❌ Ошибка';
                setTimeout(() => {
                    label.innerText = '📁 Изменить';
                    label.classList.remove('uploading');
                }, 2000);
            }
        } catch (e) {
            alert('Сетевая ошибка при загрузке');
            label.innerText = '📁 Изменить';
            label.classList.remove('uploading');
        }
    }
    </script>

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
