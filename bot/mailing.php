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

function sendMessageSafe(TelegramApi $telegram, TextService $textService, int $chatId, string $key, array $buttons = []): void
{
    $text = $textService->get($key);
    if (!empty($text)) {
        $text = TelegramApi::escapeMarkdownV2($text); // Mailing texts from DB are plain text/HTML, converting to safe V2
        if (!empty($buttons)) {
            $telegram->sendMessage($chatId, $text, TelegramApi::inlineKeyboard($buttons));
        } else {
            $telegram->sendMessage($chatId, $text);
        }
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
        sendMessageSafe($telegram, $textService, $chatId, 'msg_day_1_value');
    } elseif ($daysSince == 2) {
        // Send Day 2 value
        sendMessageSafe($telegram, $textService, $chatId, 'msg_day_2_value');
        
        // Final Trial notice
        sendMessageSafe($telegram, $textService, $chatId, 'msg_trial_end');
        sendMessageSafe($telegram, $textService, $chatId, 'msg_trial_end_paths', [
            [['text' => 'С Настей (10 000р)', 'callback_data' => 'trial_nastya'], ['text' => 'С ботом (1990р)', 'callback_data' => 'trial_bot']],
            [['text' => 'Гайд', 'callback_data' => 'trial_pdf'], ['text' => 'Демо-промпт', 'callback_data' => 'trial_demo']]
        ]);
        
        // Also send limited mode intro if not paid
        if (!$isPaid) {
            sendMessageSafe($telegram, $textService, $chatId, 'msg_limited_mode_intro');
        }
    }

    // Post-trial FREE logic
    if ($daysSince > 2 && !$isPaid) {
        $postTrialDays = $daysSince - 2;
        
        if ($state === 'demo_prompt') {
            if ($postTrialDays == 1) {
                sendMessageSafe($telegram, $textService, $chatId, 'msg_after_demo_followup');
            } elseif ($postTrialDays == 2) {
                sendMessageSafe($telegram, $textService, $chatId, 'msg_return_offer');
            }
        } elseif ($isActive) {
            if ($postTrialDays == 2) {
                sendMessageSafe($telegram, $textService, $chatId, 'msg_active_day_2_nudge');
            } elseif ($postTrialDays == 4) {
                sendMessageSafe($telegram, $textService, $chatId, 'msg_active_day_4_upgrade');
            }
        } else {
            if ($postTrialDays == 2) {
                sendMessageSafe($telegram, $textService, $chatId, 'msg_return_day_3');
            } elseif ($postTrialDays == 4) {
                sendMessageSafe($telegram, $textService, $chatId, 'msg_return_day_5');
            }
        }
    }

    // PAID logic
    if ($isPaid && $daysSince > 0 && $daysSince % 30 == 0) {
        sendMessageSafe($telegram, $textService, $chatId, 'msg_paid_month_offer');
    }
}

echo "Mailing completed successfully.\n";
