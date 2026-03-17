<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\UserService;
use App\Services\ChatService;
use App\Core\TelegramApi;

$userId = (int) ($_GET['id'] ?? 0);
if (!$userId) { header('Location: users.php'); exit; }

$userService = new UserService();
$user = $userService->findById($userId);
if (!$user) { header('Location: users.php'); exit; }

$telegram = new TelegramApi();
$chatService = new ChatService($telegram);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$messages = $chatService->getMessages($userId, $page, $perPage);
$totalMessages = $chatService->getMessageCount($userId);
$totalPages = max(1, (int) ceil($totalMessages / $perPage));

adminHeader('Переписка: ' . htmlspecialchars($user['first_name']), 'users');
?>

<div style="margin-bottom: 16px;">
    <a href="user_view.php?id=<?= $userId ?>" class="btn btn-outline">← К профилю</a>
    <span style="margin-left: 12px; color: var(--text-secondary); font-size: 13px;">
        Всего сообщений: <?= $totalMessages ?> · Страница <?= $page ?>/<?= $totalPages ?>
    </span>
</div>

<div class="chat-container">
    <?php foreach ($messages as $m): ?>
    <div class="chat-bubble <?= $m['role'] ?>">
        <?php if ($m['media_type'] !== 'text'): ?>
            <div class="chat-media-badge">
                <?= $m['media_type'] === 'voice' ? '🎤 Голосовое' : '📷 Фото' ?>
            </div>
        <?php endif; ?>
        <?= nl2br(htmlspecialchars($m['content'])) ?>
        <div class="chat-time"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?id=<?= $userId ?>&page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php adminFooter(); ?>
