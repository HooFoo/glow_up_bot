<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\TextService;

$textService = new TextService();
$message = '';
$error = '';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $id = (int)$_POST['id'];
    $title = trim($_POST['title']);
    $content = $_POST['content'];

    if (empty($title)) {
        $error = 'Название не может быть пустым';
    } else {
        if ($textService->update($id, $content, $title)) {
            $message = 'Текст успешно сохранен';
            $action = 'list';
        } else {
            $error = 'Ошибка при сохранении текста';
        }
    }
}

$texts = $textService->getAll();
$editingText = $id ? $textService->findById($id) : null;

adminHeader('Управление текстами', 'texts');
?>

<?php if ($message): ?>
    <div style="background: rgba(102,187,106,0.15); color: #66bb6a; padding: 12px; border-radius: 8px; margin-bottom: 24px; font-size: 14px;">
        ✅ <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: rgba(239,83,80,0.15); color: #ef5350; padding: 12px; border-radius: 8px; margin-bottom: 24px; font-size: 14px;">
        ❌ <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($action === 'edit' && $editingText): ?>
    <div class="profile-card" style="width: 100%; max-width: 900px;">
        <form method="POST">
            <input type="hidden" name="id" value="<?= $editingText['id'] ?>">
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; color: #9e9e9e; margin-bottom: 6px;">Ключ (системное имя)</label>
                <div style="font-family: monospace; color: #e040fb; font-size: 14px;"><?= htmlspecialchars($editingText['key']) ?></div>
            </div>

            <div class="form-group" style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; color: #9e9e9e; margin-bottom: 6px;">Название (отображается в списке)</label>
                <input type="text" name="title" value="<?= htmlspecialchars($editingText['title']) ?>" 
                       style="width: 100%; padding: 10px; background: #16213e; border: 1px solid #2a2a3e; border-radius: 6px; color: #e0e0e0;" required>
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label style="display: block; font-size: 13px; color: #9e9e9e; margin-bottom: 6px;">Текст (Markdown)</label>
                <textarea name="content" id="content-editor" style="width: 100%; height: 500px;"><?= htmlspecialchars($editingText['content']) ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" name="save" class="btn btn-primary" style="border: none; cursor: pointer;">Сохранить изменения</button>
                <a href="texts.php" class="btn btn-outline">Отмена</a>
            </div>
        </form>
    </div>

    <!-- TinyMCE Integration -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
    <script>
      tinymce.init({
        selector: '#content-editor',
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen insertdatetime media table code help wordcount',
        toolbar: 'undo redo | blocks | bold italic forecolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | removeformat | code help',
        skin: 'oxide-dark',
        content_css: 'dark',
        height: 600,
        promotion: false,
        branding: false,
        setup: function (editor) {
            editor.on('change', function () {
                editor.save();
            });
        }
      });
    </script>

<?php else: ?>
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Название</th>
                    <th>Ключ</th>
                    <th>Последнее обновление</th>
                    <th style="width: 120px; text-align: right;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($texts as $text): ?>
                <tr>
                    <td><?= $text['id'] ?></td>
                    <td><strong><?= htmlspecialchars($text['title']) ?></strong></td>
                    <td style="font-family: monospace; color: #9e9e9e; font-size: 12px;"><?= htmlspecialchars($text['key']) ?></td>
                    <td style="color: #9e9e9e; font-size: 13px;"><?= date('d.m.Y H:i', strtotime($text['updated_at'])) ?></td>
                    <td style="text-align: right;">
                        <a href="?action=edit&id=<?= $text['id'] ?>" class="btn btn-outline" style="padding: 4px 10px; font-size: 12px;">Изменить</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php adminFooter(); ?>
