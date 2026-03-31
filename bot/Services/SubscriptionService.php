<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TelegramApi;
use App\Core\Config;

class SubscriptionService
{
    private Database $db;
    private TelegramApi $telegram;

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
    }

    /**
     * Check if user has access (subscription or free period).
     * Returns true if access is granted.
     */
    public function checkAccess(array $user): bool
    {
        // Quiz not completed → no access (should go to quiz)
        if (empty($user['quiz_completed_at'])) {
            return false;
        }

        // Active subscription?
        if (!empty($user['subscription_end']) && strtotime($user['subscription_end']) > time()) {
            return true;
        }

        // Free trial period?
        $freeDays = Config::getFreeDays();
        $quizCompletedAt = strtotime($user['quiz_completed_at']);
        $freeUntil = $quizCompletedAt + ($freeDays * 86400);

        if (time() < $freeUntil) {
            return true;
        }

        return false;
    }

    /**
     * Send paywall message.
     */
    public function sendPaywall(int $chatId): void
    {
        $price = Config::getTelegramStarsPrice();

        $text = "Твой бесплатный период завершён 🌙\n\n";
        $text .= "Чтобы я продолжала быть рядом — питание, кожа, энергия и поддержка — оформи подписку\.\n\n";
        $text .= "Это инвестиция в себя, которая окупается ежедневно 💎";

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => "✨ Продолжить — {$price} Stars ⭐️", 'callback_data' => 'buy_subscription']],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * Send invoice for subscription purchase.
     */
    public function sendInvoice(int $chatId, int $userId): void
    {
        $price = Config::getTelegramStarsPrice();
        $days = Config::getSubscriptionDays();

        $this->telegram->sendInvoice(
            $chatId,
            'Prime Glow подписка',
            "Полный доступ к AI-ассистенту на {$days} дней: питание, косметика, коучинг",
            "sub_{$userId}_" . time(),
            $price,
            'XTR'
        );
    }

    /**
     * Handle successful payment.
     */
    public function handlePayment(int $userId, array $payment): void
    {
        $chargeId = $payment['telegram_payment_charge_id'] ?? '';
        $amount = $payment['total_amount'] ?? 0;
        $days = Config::getSubscriptionDays();

        $userService = new UserService();
        $user = $userService->findById($userId);

        // Calculate subscription period
        $startsAt = date('Y-m-d H:i:s');
        $currentEnd = $user['subscription_end'] ?? null;
        if ($currentEnd && strtotime($currentEnd) > time()) {
            // Extend existing subscription
            $endsAt = date('Y-m-d H:i:s', strtotime($currentEnd) + ($days * 86400));
        } else {
            $endsAt = date('Y-m-d H:i:s', time() + ($days * 86400));
        }

        // Save subscription record
        $this->db->insert(
            'INSERT INTO subscriptions (user_id, telegram_payment_charge_id, stars_amount, starts_at, ends_at) VALUES (:uid, :charge, :amount, :start, :end)',
            [
                ':uid'    => $userId,
                ':charge' => $chargeId,
                ':amount' => $amount,
                ':start'  => $startsAt,
                ':end'    => $endsAt,
            ]
        );

        // Update user
        $userService->setSubscriptionEnd($userId, $endsAt);
    }

    // ─── Admin queries ───────────────────────────────────────────

    public function getSubscriptionsPerDay(int $days = 10): array
    {
        return $this->db->fetchAll(
            'SELECT DATE(created_at) AS day, COUNT(*) AS count FROM subscriptions WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY) GROUP BY DATE(created_at) ORDER BY day ASC',
            [':days' => $days]
        );
    }
}
