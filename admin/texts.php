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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'title';
$dir = isset($_GET['dir']) ? $_GET['dir'] : 'ASC';

function sortLink(string $column, string $label, string $currentSort, string $currentDir, string $search): string
{
    $nextDir = ($column === $currentSort && $currentDir === 'ASC') ? 'DESC' : 'ASC';
    $activeClass = ($column === $currentSort) ? 'active' : '';
    $icon = '';
    if ($column === $currentSort) {
        $icon = $currentDir === 'ASC' ? ' ↑' : ' ↓';
    }
    
    $url = "?sort={$column}&dir={$nextDir}";
    if ($search) {
        $url .= "&search=" . urlencode($search);
    }
    
    return "<a href=\"{$url}\" class=\"sort-link {$activeClass}\">" . htmlspecialchars($label) . "<span class=\"sort-icon\">{$icon}</span></a>";
}

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

$texts = $textService->getAll($search, $sort, $dir);
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

    <!-- EasyMDE Integration (Better for Markdown) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.css">
    <script src="https://cdn.jsdelivr.net/npm/easymde/dist/easymde.min.js"></script>
    <style>
        .CodeMirror {
            background: #16213e !important;
            color: #e0e0e0 !important;
            border: 1px solid #2a2a3e !important;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
            font-family: 'JetBrains Mono', 'Fira Code', monospace;
            font-size: 14px;
        }
        .editor-toolbar {
            background: #1a1a2e !important;
            border: 1px solid #2a2a3e !important;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            opacity: 1 !important;
        }
        .editor-toolbar i { color: #9e9e9e !important; }
        .editor-toolbar a:hover, .editor-toolbar a.active {
            background: #2a2a3e !important;
            color: var(--accent-primary) !important;
            border: none !important;
        }
        .editor-preview {
            background: #0f0f1a !important;
            color: #e0e0e0 !important;
        }
        .editor-statusbar { color: #9e9e9e !important; }
    </style>
    <script>
      const easyMDE = new EasyMDE({
        element: document.getElementById('content-editor'),
        spellChecker: false,
        status: false,
        minHeight: "500px",
        maxHeight: "600px",
        placeholder: "Пишите в формате Markdown...",
        toolbar: ["bold", "italic", "heading", "|", "quote", "unordered-list", "ordered-list", "|", "link", "image", "|", "preview", "side-by-side", "fullscreen", "|", "guide"]
      });
    </script>

<?php else: ?>
    <div class="filter-bar">
        <form method="GET" class="search-form">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Поиск по ключу или тексту..." class="search-input">
            <button type="submit" class="btn btn-primary">Найти</button>
            <?php if ($search): ?>
                <a href="texts.php" class="btn btn-outline">Сброс</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 60px;"><?= sortLink('id', 'ID', $sort, $dir, $search) ?></th>
                    <th><?= sortLink('title', 'Название / Превью', $sort, $dir, $search) ?></th>
                    <th><?= sortLink('key', 'Ключ', $sort, $dir, $search) ?></th>
                    <th><?= sortLink('updated_at', 'Обновлено', $sort, $dir, $search) ?></th>
                    <th style="width: 120px; text-align: right;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($texts)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-secondary);">
                        Тексты не найдены
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($texts as $text): ?>
                <tr>
                    <td><?= $text['id'] ?></td>
                    <td>
                        <div style="font-weight: 600; margin-bottom: 4px; color: var(--text-primary);"><?= htmlspecialchars($text['title']) ?></div>
                        <div style="color: var(--text-secondary); font-size: 12px;"><?= htmlspecialchars(mb_strlen($text['content']) > 100 ? mb_substr($text['content'], 0, 100) . '...' : $text['content']) ?></div>
                    </td>
                    <td style="font-family: monospace; color: var(--accent-primary); font-size: 12px;"><?= htmlspecialchars($text['key']) ?></td>
                    <td style="color: var(--text-secondary); font-size: 13px; white-space: nowrap;"><?= date('d.m.Y H:i', strtotime($text['updated_at'])) ?></td>
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
