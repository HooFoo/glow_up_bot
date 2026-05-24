<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

use App\Services\UserService;
use App\Services\ProfileService;
use App\Services\SummaryService;
use App\Services\PersonaService;
use App\Services\ChatService;
use App\Services\TextService;
use App\Core\TelegramApi;
use App\Core\Database;

$userId = (int) ($_GET['id'] ?? 0);
if (!$userId) { header('Location: users.php'); exit; }

$userService = new UserService();
$user = $userService->findById($userId);
if (!$user) { header('Location: users.php'); exit; }

$textService = new TextService();
$telegram = new TelegramApi();
$db = Database::getInstance();

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
    } elseif ($_POST['action'] === 'send_warmup') {
        $key = $_POST['key'] ?? '';
        if ($key) {
            $warmupDefaults = [
                'msg_trial_day1' => "Привет 👀\nдавай сегодня направим фокус на твое питание?\n\nВсе, что ты ешь сегодня это реально даёт тебе энергию или наоборот сливает?\n\nскинь фото своей тарелки 👇 я разберу по макроэлементам и скажу, где ты теряешь ресурс",
                'msg_trial_day2' => "Привет привет! Сегодня направим фокус на кожу и сияние:\n\nТы знаешь что ты наносишь на свое лицо?\n90% того, что нам рекомендуют либо не работает либо делает хуже\n\nхочешь проверить свои средства?\nскинь фото крема или состав 👇 я скажу:\n— что в нём реально работает\n— а что просто маркетинг",
                'msg_trial_day3' => "Ты уже попробовала пару функций и, скорее всего, заметила разницу\nно есть нюанс:\n\nотдельные разборы — это не результат\nрезультат даёт система\n\nдавай соберу тебе мини-день под твоё состояние 👇",
                'msg_trial_day4' => "Смотри, как это обычно происходит:\nдевочки пробуют 1-2 функции\nвидят, что это работает\nно дальше возвращаются в хаос\nпотому что нет системы\n\nв полной версии я как раз собираю тебе это под тебя:\n— питание\n— уход\n— ритм\n— состояние\n\nчтобы ты не начинала заново",
                'msg_after_demo_followup' => "слушай, как тебе формат с промптом? 👀\n\nполучилось настроить или пока не очень?\n\nесли честно, в одиночку это часто чуть сложнее, чем кажется\n\nпоэтому если захочешь,\nможно собрать это вместе и сразу под тебя\n\nя рядом 🤍",
                'msg_return_offer' => "слушай, ты уже попробовала этот формат 👀\n\nи ты видишь, что это не просто «поболтать»\n\nесли хочешь реально выстроить систему и не откатываться назад\nможно сделать это вместе\n\nили подключить полный доступ к боту\n\nя рядом 🤍",
            ];
            $text = $textService->get($key, $warmupDefaults[$key] ?? '', true);
            if ($text) {
                $chatId = (int)$user['telegram_id'];
                $keyboard = null;
                // Specific keyboards for trial funnel messages
                if ($key === 'msg_trial_day1') {
                    $keyboard = \App\Core\TelegramApi::inlineKeyboard([[['text' => $textService->get('btn_trial_send_photo', '📸 Отправить фото'), 'callback_data' => 'mode_nutrition']]]);
                } elseif ($key === 'msg_trial_day2') {
                    $keyboard = \App\Core\TelegramApi::inlineKeyboard([[['text' => $textService->get('btn_trial_check_cosmetics', '🧴 Разобрать средство'), 'callback_data' => 'mode_cosmetics']]]);
                } elseif ($key === 'msg_trial_day3') {
                    $keyboard = \App\Core\TelegramApi::inlineKeyboard([[['text' => $textService->get('btn_trial_build_day', '🥗 Собрать мой день'), 'callback_data' => 'mode_beauty_assistant']]]);
                } elseif ($key === 'msg_trial_day4') {
                    $keyboard = \App\Core\TelegramApi::inlineKeyboard([[['text' => $textService->get('btn_trial_full_version', '💎 Хочу полную версию'), 'callback_data' => 'buy_subscription']]]);
                }
                
                if ($telegram->sendMessage($chatId, $text, $keyboard)) {
                    $db->execute('REPLACE INTO sent_mailings (user_id, mailing_key, sent_at) VALUES (:uid, :key, NOW())', [':uid' => $userId, ':key' => $key]);
                    
                    // Sync trial funnel step
                    $stepMap = ['msg_trial_day1' => 1, 'msg_trial_day2' => 2, 'msg_trial_day3' => 3, 'msg_trial_day4' => 4];
                    if (isset($stepMap[$key])) {
                        $db->execute("UPDATE users SET trial_funnel_step = :step, last_funnel_message_at = NOW() WHERE id = :id", [':step' => $stepMap[$key], ':id' => $userId]);
                    }
                }
            }
        }
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
            <?php elseif (!empty($user['quiz_completed_at']) && (strtotime($user['quiz_completed_at']) + App\Core\Config::getFreeDays() * 86400) > time()): ?>
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

    <div class="profile-card">
        <h3>🔥 Прогрев</h3>
        <div class="warmup-list">
            <?php
            $trialMessages = [
                'msg_trial_day1' => 'День 1: Питание',
                'msg_trial_day2' => 'День 2: Кожа',
                'msg_trial_day3' => 'День 3: Мини-день',
                'msg_trial_day4' => 'День 4: Оффер',
            ];
            $otherMessages = [
                'msg_after_demo_followup' => 'После демо (fol)',
                'msg_return_offer' => 'Возврат (offer)',
                'msg_active_day_2_nudge' => 'Активность Д2',
                'msg_active_day_4_upgrade' => 'Активность Д4',
            ];

            $funnelStep = (int)($user['trial_funnel_step'] ?? 0);
            $lastFunnelAt = $user['last_funnel_message_at'] ?? null;
            $sentKeys = array_column($automatedMailings, 'mailing_key');
            ?>

            <!-- Trial Funnel Progress Section -->
            <div style="margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 8px;">
                    <div style="font-size: 11px; font-weight: 600; color: var(--accent);">ПРОГРЕСС ВОРОНКИ</div>
                    <div style="font-size: 10px; color: var(--text-secondary);"><?= $funnelStep ?> / 4</div>
                </div>
                <div style="height: 8px; background: #2a2a3e; border-radius: 4px; overflow: hidden; display: flex; margin-bottom: 12px; border: 1px solid #3d3d5c;">
                    <?php for($i=1; $i<=4; $i++): ?>
                        <div style="flex: 1; border-right: 1px solid #1a1a2e; background: <?= $i <= $funnelStep ? 'var(--accent)' : 'transparent' ?>; opacity: <?= $i <= $funnelStep ? '1' : '0.3' ?>"></div>
                    <?php endfor; ?>
                </div>
                
                <?php foreach ($trialMessages as $key => $label): 
                    $isSent = in_array($key, $sentKeys);
                    $stepNum = (int)str_replace('msg_trial_day', '', $key);
                    $isNext = ($funnelStep + 1 === $stepNum);
                ?>
                    <div class="warmup-item" style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #2a2a3e; opacity: <?= ($isSent || $isNext) ? '1' : '0.5' ?>">
                        <div style="font-size: 12px;">
                            <div style="font-weight: 500; color: <?= $isSent ? 'var(--success)' : ($isNext ? 'var(--text-primary)' : 'var(--text-secondary)') ?>">
                                <?= $label ?> <?= $isSent ? '✓' : ($isNext ? '⚡' : '') ?>
                            </div>
                            <div style="font-size: 9px; color: var(--text-secondary);"><?= $key ?></div>
                        </div>
                        <form method="post" style="margin: 0;">
                            <input type="hidden" name="action" value="send_warmup">
                            <input type="hidden" name="key" value="<?= $key ?>">
                            <button type="submit" class="btn <?= $isSent ? 'btn-outline' : ($isNext ? 'btn-primary' : 'btn-outline') ?>" style="padding: 4px 10px; font-size: 10px; min-width: 80px;">
                                <?= $isSent ? 'Повтор' : ($isNext ? 'Отправить' : 'Тест') ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Other Warmup Messages Section -->
            <div style="margin-top: 10px; border-top: 1px solid #3d3d5c; padding-top: 10px;">
                <div style="font-size: 11px; font-weight: 600; color: var(--text-secondary); margin-bottom: 8px;">ДОПОЛНИТЕЛЬНЫЕ РАССЫЛКИ</div>
                <?php foreach ($otherMessages as $key => $label): 
                    $isSent = in_array($key, $sentKeys);
                ?>
                    <div class="warmup-item" style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px dotted #2a2a3e;">
                        <div style="font-size: 11px;">
                            <div style="font-weight: 500; color: <?= $isSent ? 'var(--success)' : 'var(--text-primary)' ?>"><?= $label ?> <?= $isSent ? '✓' : '' ?></div>
                            <div style="font-size: 8px; color: var(--text-secondary);"><?= $key ?></div>
                        </div>
                        <form method="post" style="margin: 0;">
                            <input type="hidden" name="action" value="send_warmup">
                            <input type="hidden" name="key" value="<?= $key ?>">
                            <button type="submit" class="btn btn-outline" style="padding: 2px 8px; font-size: 9px; border-color: #3d3d5c; opacity: 0.8;">
                                <?= $isSent ? 'Повтор' : 'Отправить' ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
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
                            <?php if ($m['type'] === 'Auto'): ?>
                                <form method="post" style="margin-top: 4px;">
                                    <input type="hidden" name="action" value="send_warmup">
                                    <input type="hidden" name="key" value="<?= $m['key'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding: 2px 6px; font-size: 9px; border-color: #5c6bc0; color: #9fa8da;">Повторить</button>
                                </form>
                            <?php endif; ?>
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
