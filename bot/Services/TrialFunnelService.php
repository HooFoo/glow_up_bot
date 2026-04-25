<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TelegramApi;
use App\Services\TextService;

class TrialFunnelService
{
    private Database $db;
    private TelegramApi $telegram;
    private TextService $textService;

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
        $this->textService = new TextService();
    }

    public function process(): void
    {
        $this->processStep1();
        $this->processStep2();
        $this->processStep3();
        $this->processStep4();
    }

    private function processStep1(): void
    {
        // Day 1: 3-6 hours after onboarding_completed_at
        $users = $this->db->fetchAll("
            SELECT * FROM users 
            WHERE trial_funnel_step = 0 
            AND onboarding_completed_at IS NOT NULL 
            AND onboarding_completed_at <= DATE_SUB(NOW(), INTERVAL 3 HOUR)
            AND onboarding_completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND is_blocked = 0
            AND subscription_end IS NULL OR subscription_end < NOW()
        ");

        foreach ($users as $user) {
            $this->sendDay1($user);
        }
    }

    private function processStep2(): void
    {
        // Day 2: 24 hours after step 1
        $users = $this->db->fetchAll("
            SELECT * FROM users 
            WHERE trial_funnel_step = 1 
            AND last_funnel_message_at <= DATE_SUB(NOW(), INTERVAL 20 HOUR)
            AND is_blocked = 0
            AND subscription_end IS NULL OR subscription_end < NOW()
        ");

        foreach ($users as $user) {
            $this->sendDay2($user);
        }
    }

    private function processStep3(): void
    {
        // Day 3: 24 hours after step 2
        $users = $this->db->fetchAll("
            SELECT * FROM users 
            WHERE trial_funnel_step = 2 
            AND last_funnel_message_at <= DATE_SUB(NOW(), INTERVAL 20 HOUR)
            AND is_blocked = 0
            AND subscription_end IS NULL OR subscription_end < NOW()
        ");

        foreach ($users as $user) {
            $this->sendDay3($user);
        }
    }

    private function processStep4(): void
    {
        // Day 4: 24 hours after step 3
        $users = $this->db->fetchAll("
            SELECT * FROM users 
            WHERE trial_funnel_step = 3 
            AND last_funnel_message_at <= DATE_SUB(NOW(), INTERVAL 20 HOUR)
            AND is_blocked = 0
            AND subscription_end IS NULL OR subscription_end < NOW()
        ");

        foreach ($users as $user) {
            $this->sendDay4($user);
        }
    }

    private function sendDay1(array $user): void
    {
        $text = $this->textService->get('msg_trial_day1', "Привет 👀\nдавай сегодня направим фокус на твое питание?\n\nВсе, что ты ешь сегодня это реально даёт тебе энергию или наоборот сливает?\n\nскинь фото своей тарелки 👇 я разберу по макроэлементам и скажу, где ты теряешь ресурс");
        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_trial_send_photo', '📸 Отправить фото'), 'callback_data' => 'mode_nutrition']],
        ]);

        if ($this->telegram->sendMessage((int)$user['telegram_id'], $text, $keyboard)) {
            $this->updateStep($user['id'], 1);
        }
    }

    private function sendDay2(array $user): void
    {
        $text = $this->textService->get('msg_trial_day2', "Привет привет! Сегодня направим фокус на кожу и сияние:\n\nТы знаешь что ты наносишь на свое лицо?\n90% того, что нам рекомендуют либо не работает либо делает хуже\n\nхочешь проверить свои средства?\nскинь фото крема или состав 👇 я скажу:\n— что в нём реально работает\n— а что просто маркетинг");
        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_trial_check_cosmetics', '🧴 Разобрать средство'), 'callback_data' => 'mode_cosmetics']],
        ]);

        if ($this->telegram->sendMessage((int)$user['telegram_id'], $text, $keyboard)) {
            $this->updateStep($user['id'], 2);
        }
    }

    private function sendDay3(array $user): void
    {
        $text = $this->textService->get('msg_trial_day3', "Ты уже попробовала пару функций и, скорее всего, заметила разницу\nно есть нюанс:\n\nотдельные разборы — это не результат\nрезультат даёт система\n\nдавай соберу тебе мини-день под твоё состояние 👇");
        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_trial_build_day', '🥗 Собрать мой день'), 'callback_data' => 'mode_beauty_assistant']],
        ]);

        if ($this->telegram->sendMessage((int)$user['telegram_id'], $text, $keyboard)) {
            $this->updateStep($user['id'], 3);
        }
    }

    private function sendDay4(array $user): void
    {
        $text = $this->textService->get('msg_trial_day4', "Смотри, как это обычно происходит:\nдевочки пробуют 1-2 функции\nвидят, что это работает\nно дальше возвращаются в хаос\nпотому что нет системы\n\nв полной версии я как раз собираю тебе это под тебя:\n— питание\n— уход\n— ритм\n— состояние\n\nчтобы ты не начинала заново");
        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_trial_full_version', '💎 Хочу полную версию'), 'callback_data' => 'buy_subscription']],
        ]);

        if ($this->telegram->sendMessage((int)$user['telegram_id'], $text, $keyboard)) {
            $this->updateStep($user['id'], 4);
        }
    }

    private function updateStep(int $userId, int $step): void
    {
        $this->db->execute(
            "UPDATE users SET trial_funnel_step = :step, last_funnel_message_at = NOW() WHERE id = :id",
            [':step' => $step, ':id' => $userId]
        );
    }
}
