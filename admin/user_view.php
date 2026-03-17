<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\UserService;
use App\Services\ProfileService;
use App\Services\SummaryService;
use App\Services\PersonaService;
use App\Services\ChatService;
use App\Core\TelegramApi;

$userId = (int) ($_GET['id'] ?? 0);
if (!$userId) { header('Location: users.php'); exit; }

$userService = new UserService();
$user = $userService->findById($userId);
if (!$user) { header('Location: users.php'); exit; }

$profileService = new ProfileService();
$profile = $profileService->getProfile($userId);

$summaryService = new SummaryService();
$summaries = [];
foreach (['nutrition', 'cosmetics', 'coach', 'general'] as $mode) {
    $s = $summaryService->getSummary($userId, $mode);
    if ($s) $summaries[$mode] = $s;
}

$telegram = new TelegramApi();
$chatService = new ChatService($telegram);
$messageCount = $chatService->getMessageCount($userId);

adminHeader('Пользователь: ' . htmlspecialchars($user['first_name']), 'users');
?>

<div style="margin-bottom: 16px;">
    <a href="users.php" class="btn btn-outline">← Назад</a>
    <a href="conversation.php?id=<?= $userId ?>" class="btn btn-primary" style="margin-left: 8px;">💬 Переписка</a>
</div>

<div class="profile-grid">
    <div class="profile-card">
        <h3>📱 Telegram</h3>
        <div class="field"><span class="field-label">Telegram ID:</span> <span class="field-value"><?= $user['telegram_id'] ?></span></div>
        <div class="field"><span class="field-label">Username:</span> <span class="field-value"><?= $user['username'] ? '@' . htmlspecialchars($user['username']) : '—' ?></span></div>
        <div class="field"><span class="field-label">Имя:</span> <span class="field-value"><?= htmlspecialchars($user['first_name'] . ' ' . ($user['last_name'] ?? '')) ?></span></div>
        <div class="field"><span class="field-label">Сообщений:</span> <span class="field-value"><?= $messageCount ?></span></div>
        <div class="field"><span class="field-label">Состояние:</span> <span class="field-value"><?= htmlspecialchars($user['state'] ?? '—') ?></span></div>
        <div class="field"><span class="field-label">Регистрация:</span> <span class="field-value"><?= date('d.m.Y H:i', strtotime($user['created_at'])) ?></span></div>
    </div>

    <div class="profile-card">
        <h3>💳 Подписка</h3>
        <div class="field">
            <span class="field-label">Статус:</span>
            <?php if (!empty($user['subscription_end']) && strtotime($user['subscription_end']) > time()): ?>
                <span class="badge badge-active">Активна</span>
            <?php elseif (!empty($user['quiz_completed_at']) && (strtotime($user['quiz_completed_at']) + 2 * 86400) > time()): ?>
                <span class="badge badge-trial">Триал</span>
            <?php else: ?>
                <span class="badge badge-inactive">Неактивна</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($user['subscription_end'])): ?>
        <div class="field"><span class="field-label">До:</span> <span class="field-value"><?= date('d.m.Y H:i', strtotime($user['subscription_end'])) ?></span></div>
        <?php endif; ?>
        <div class="field"><span class="field-label">Квиз пройден:</span> <span class="field-value"><?= $user['quiz_completed_at'] ? date('d.m.Y H:i', strtotime($user['quiz_completed_at'])) : '—' ?></span></div>
        <div class="field"><span class="field-label">Архетип:</span> <span class="field-value"><?= $user['persona'] ? PersonaService::getPersonaEmoji($user['persona']) . ' ' . PersonaService::getPersonaLabel($user['persona']) : '—' ?></span></div>
        <div class="field"><span class="field-label">Режим:</span> <span class="field-value"><?= $user['active_mode'] ?? '—' ?></span></div>
    </div>
</div>

<?php if ($profile): ?>
<div class="profile-card" style="margin-bottom: 24px;">
    <h3>🧬 Профиль</h3>
    <div class="field"><span class="field-label">Цель:</span> <span class="field-value"><?= htmlspecialchars($profile['goal'] ?? '—') ?></span></div>
    <div class="field"><span class="field-label">Вес/Рост:</span> <span class="field-value"><?= ($profile['weight_kg'] ?? '?') . ' кг / ' . ($profile['height_cm'] ?? '?') . ' см' ?></span></div>
    <div class="field"><span class="field-label">BMI:</span> <span class="field-value"><?= $profile['bmi'] ?? '—' ?></span></div>
    <div class="field"><span class="field-label">Здоровье:</span> <span class="field-value"><?= htmlspecialchars($profile['health_features'] ?? '—') ?></span></div>
    <div class="field"><span class="field-label">Цикл:</span> <span class="field-value"><?= htmlspecialchars($profile['cycle_phase'] ?? '—') ?></span></div>
    <?php if (!empty($profile['known_facts'])): ?>
    <div class="field">
        <span class="field-label">Известные факты:</span>
        <ul style="margin-top: 6px; padding-left: 16px;">
            <?php foreach ($profile['known_facts'] as $fact): ?>
                <li style="font-size: 13px; margin-bottom: 4px;"><?= htmlspecialchars($fact) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($summaries)): ?>
<h2 style="font-size: 18px; margin-bottom: 12px;">📝 Резюме переписок</h2>
<?php foreach ($summaries as $mode => $summary): ?>
<div class="summary-block">
    <strong><?= ucfirst($mode) ?>:</strong><br>
    <?= nl2br(htmlspecialchars($summary)) ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php adminFooter(); ?>
