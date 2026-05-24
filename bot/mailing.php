<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TelegramApi;
use App\Core\Config;
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


    $text = $textService->get($contentKey, '', true);

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

    $freeDays = Config::getFreeDays();

    // Post-trial FREE logic
    if ($daysSince > $freeDays && !$isPaid) {
        $postTrialDays = $daysSince - $freeDays;
        
        if ($state === 'demo_prompt') {
            if ($postTrialDays == 1) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_after_demo_followup');
            } elseif ($postTrialDays == 2) {
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_return_offer');
            }
        } elseif ($isActive) {
            if ($postTrialDays == 2) {
                $coursePrice = Config::getProdamusCoursePrice();
                $subPrice = Config::getProdamusSubscriptionPrice();
                
                $buttons = [
                    [
                        ['text' => sprintf($textService->get('btn_funnel_nastya', 'С Настей (%d Р)', true), $coursePrice), 'callback_data' => 'funnel_path_nastya'], 
                        ['text' => sprintf($textService->get('btn_funnel_bot', 'С ботом (%d Р)', true), $subPrice), 'callback_data' => 'funnel_path_self']
                    ],
                    [
                        ['text' => $textService->get('btn_step_5_direct_pay', 'Оплатить участие', true), 'callback_data' => 'funnel_direct_pay']
                    ],
                    [
                        ['text' => $textService->get('btn_skip_course', 'Продолжить в бесплатной версии', true), 'callback_data' => 'funnel_skip_course']
                    ]
                ];
                
                sendMessageSafe($db, $telegram, $textService, $userId, $chatId, 'msg_step_5_soft_offer', 'msg_active_day_2_nudge', $buttons);
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
