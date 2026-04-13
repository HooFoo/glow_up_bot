<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TelegramApi;
use App\Services\TextService;
use App\Services\SubscriptionService;

$db = Database::getInstance();
$telegram = new TelegramApi();
$textService = new TextService();
$subService = new SubscriptionService($telegram);

function sendMessageSafe(Database $db, TelegramApi $telegram, TextService $textService, int $userId, int $chatId, string $contentKey, string $trackingKey = null, array $buttons = []): void
{
    $trackingKey = $trackingKey ?: $contentKey;

    // Check if already sent
    $exists = $db->fetchOne(
        'SELECT id FROM sent_mailings WHERE user_id = :uid AND mailing_key = :key',
        [':uid' => $userId, ':key' => $trackingKey]
    );
    if ($exists) {
        return;
    }

    $text = $textService->get($contentKey);
    if (!empty($text)) {
        // TextService::get() already escapes for MarkdownV2
        if (!empty($buttons)) {
            $telegram->sendMessage($chatId, $text, TelegramApi::inlineKeyboard($buttons));
        } else {
            $telegram->sendMessage($chatId, $text);
        }

        // Record as sent
        $db->insert(
            'INSERT INTO sent_mailings (user_id, mailing_key) VALUES (:uid, :key)',
            [':uid' => $userId, ':key' => $trackingKey]
        );
    }
}

// Get all users
$users = $db->fetchAll('SELECT * FROM users WHERE state != "new"');

foreach ($users as $user) {
    $userId = (int) $user['id'];
    $chatId = (int) $user['telegram_id'];
    $isPaid = $subService->checkAccess($user);
    $isActive = ((int)$user['message_count'] > 2); // basic activity metric
    $state = $user['state'] ?? '';

    // Calculate days since registration or onboarding
    $createdAt = new \DateTime($user['created_at']);
    $now = new \DateTime();
    $daysSince = $createdAt->diff($now)->days;

    // Trial Period (Days 1 to 2)
    if ($daysSince == 1) {
        sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_day_1_value');
    } elseif ($daysSince == 2) {
        // Send Day 2 value
        sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_day_2_value');
        
        // Final Trial notice
        sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_trial_end');
        sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_trial_end_paths', null, [
            [['text' => 'С Настей (10 000р)', 'callback_data' => 'trial_nastya'], ['text' => 'С ботом (1990р)', 'callback_data' => 'trial_bot']],
            [['text' => 'Гайд', 'callback_data' => 'trial_pdf'], ['text' => 'Демо-промпт', 'callback_data' => 'trial_demo']]
        ]);
        
        // Also send limited mode intro if not paid
        if (!$isPaid) {
            sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_limited_mode_intro');
        }
    }

    // Post-trial FREE logic
    if ($daysSince > 2 && !$isPaid) {
        $postTrialDays = $daysSince - 2;
        
        if ($state === 'demo_prompt') {
            if ($postTrialDays == 1) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_after_demo_followup');
            } elseif ($postTrialDays == 2) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_return_offer');
            }
        } elseif ($isActive) {
            if ($postTrialDays == 2) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_active_day_2_nudge');
            } elseif ($postTrialDays == 4) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_active_day_4_upgrade');
            }
        } else {
            if ($postTrialDays == 2) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_return_day_3');
            } elseif ($postTrialDays == 4) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_return_day_5');
            }
        }
    }

    // PAID logic
    if ($isPaid && $daysSince > 0 && $daysSince % 30 == 0) {
        // For recurring messages, we include the day number in the tracking key
        sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_paid_month_offer', 'msg_paid_month_offer_' . $daysSince);
    }
}

echo "Mailing completed successfully.\n";
