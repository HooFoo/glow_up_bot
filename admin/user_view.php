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

// Handle Subscription Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_sub') {
        $subType = $_POST['sub_type'] ?? '';
        if ($subType === 'paid') {
            $userService->setSubscriptionEnd($userId, date('Y-m-d H:i:s', time() + (30 * 86400)));
        } elseif ($subType === 'trial') {
            $userService->setSubscriptionEnd($userId, null);
            $userService->setQuizCompletedAt($userId, date('Y-m-d H:i:s'));
        } elseif ($subType === 'revoke') {
            $userService->setSubscriptionEnd($userId, null);
            $userService->setQuizCompletedAt($userId, date('Y-m-d H:i:s', time() - (14 * 86400)));
        }
    } elseif ($_POST['action'] === 'reset_session') {
        $userService->resetUserSession($userId);
    }
    header("Location: user_view.php?id={$userId}");
    exit;
}

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

// Fetch Automated Mailings (from bot/mailing.php)
$db = \App\Core\Database::getInstance();
$automatedMailings = $db->fetchAll(
    'SELECT * FROM sent_mailings WHERE user_id = :uid ORDER BY sent_at DESC',
    [':uid' => $userId]
);

// Fetch Manual Broadcasts
$manualBroadcasts = $db->fetchAll(
    'SELECT bl.*, b.message_text 
     FROM broadcast_logs bl 
     JOIN broadcasts b ON bl.broadcast_id = b.id 
     WHERE bl.user_id = :uid 
     ORDER BY bl.sent_at DESC',
    [':uid' => $userId]
);

adminHeader('Пользователь: ' . htmlspecialchars($user['first_name']), 'users');
?>

<div style="margin-bottom: 16px;">
    <a href="users.php" class="btn btn-outline">← Назад</a>
    <a href="conversation.php?id=<?= $userId ?>" class="btn btn-primary" style="margin-left: 8px;">💬 Переписка</a>
    <form method="post" style="display:inline-block; margin-left: 8px;" onsubmit="return confirm('Вы уверены? Это действие навсегда удалит всю переписку, профиль и профильную информацию пользователя, вернув его в состояние до ввода /start.');">
        <input type="hidden" name="action" value="reset_session">
        <button type="submit" class="btn btn-outline" style="color: #ef5350; border-color: #ef5350;">🧨 Полный сброс</button>
    </form>
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
        <div class="field">
            <span class="field-label">Управление:</span>
            <form method="post" style="display:inline-block; margin-top: 5px;">
                <input type="hidden" name="action" value="update_sub">
                <select name="sub_type" style="padding: 4px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px;">
                    <option value="">Действие...</option>
                    <option value="paid">Выдать подписку (30 дней)</option>
                    <option value="trial">Начать триал заново</option>
                    <option value="revoke">Завершить доступ</option>
                </select>
                <button type="submit" class="btn btn-primary" style="padding: 4px 8px; font-size: 13px;">Применить</button>
            </form>
        </div>
        <?php if (!empty($user['subscription_end'])): ?>
        <div class="field"><span class="field-label">До:</span> <span class="field-value"><?= date('d.m.Y H:i', strtotime($user['subscription_end'])) ?></span></div>
        <?php endif; ?>
        <div class="field"><span class="field-label">Квиз пройден:</span> <span class="field-value"><?= $user['quiz_completed_at'] ? date('d.m.Y H:i', strtotime($user['quiz_completed_at'])) : '—' ?></span></div>
        <div class="field"><span class="field-label">Архетип:</span> <span class="field-value"><?= $user['persona'] ? PersonaService::getPersonaEmoji($user['persona']) . ' ' . PersonaService::getPersonaLabel($user['persona']) : '—' ?></span></div>
        <div class="field"><span class="field-label">Режим:</span> <span class="field-value"><?= $user['active_mode'] ?? '—' ?></span></div>
    </div>
</div>

<div class="profile-grid">
    <div class="profile-card">
        <h3>📢 История рассылок</h3>
        <div class="mailing-list">
            <?php
            $allMailings = [];
            foreach ($automatedMailings as $am) $allMailings[] = ['type' => 'Auto', 'key' => $am['mailing_key'], 'date' => $am['sent_at'], 'status' => 'sent'];
            foreach ($manualBroadcasts as $mb) $allMailings[] = ['type' => 'Manual', 'key' => mb_strimwidth($mb['message_text'], 0, 40, "..."), 'date' => $mb['sent_at'], 'status' => $mb['status']];
            
            usort($allMailings, fn($a, $b) => strcmp($b['date'], $a['date']));

            if (empty($allMailings)): ?>
                <div class="no-data">Сообщений еще не было</div>
            <?php else: ?>
                <?php foreach ($allMailings as $m): ?>
                    <div class="mailing-item">
                        <div class="m-info">
                            <span class="m-type"><?= $m['type'] ?></span>
                            <span class="m-key"><?= htmlspecialchars($m['key']) ?></span>
                        </div>
                        <div class="m-meta">
                            <span class="m-date"><?= date('d.m.y H:i', strtotime($m['date'])) ?></span>
                            <span class="m-status status-<?= $m['status'] ?>"><?= $m['status'] ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.mailing-list { max-height: 400px; overflow-y: auto; }
.mailing-item { border-bottom: 1px solid #333; padding: 10px 0; display: flex; justify-content: space-between; align-items: flex-start; }
.mailing-item:last-child { border-bottom: none; }
.m-info { display: flex; flex-direction: column; gap: 4px; }
.m-type { font-size: 10px; text-transform: uppercase; background: #333; color: #fff; padding: 2px 4px; border-radius: 4px; width: fit-content; }
.m-key { font-size: 13px; color: #e0e0e0; }
.m-meta { text-align: right; display: flex; flex-direction: column; gap: 4px; }
.m-date { font-size: 11px; color: #888; }
.m-status { font-size: 10px; padding: 2px 6px; border-radius: 4px; }
.status-sent { background: rgba(76, 175, 80, 0.2); color: #4caf50; }
.status-failed { background: rgba(244, 67, 54, 0.2); color: #f44336; }
.no-data { padding: 20px; text-align: center; color: #666; font-style: italic; }
</style>

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
