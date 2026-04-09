<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TelegramApi;
use App\Core\Config;
use App\Services\ProdamusService;

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
    public function sendPaywall(int $chatId, int $userId): void
    {
        $price = Config::getProdamusPrice();
        $orderId = 'sub_' . $userId . '_' . time();

        // Log the sent link
        $isRenewal = $this->isUserRenewal($userId);
        $this->db->insert(
            'INSERT INTO payment_logs (user_id, order_id, amount, status, is_renewal) VALUES (:uid, :oid, :amount, "link_sent", :renewal)',
            [
                ':uid' => $userId,
                ':oid' => $orderId,
                ':amount' => $price,
                ':renewal' => $isRenewal ? 1 : 0
            ]
        );

        $prodamus = new ProdamusService();
        $payUrl = $prodamus->generatePaymentLink($userId, $orderId, (float) $price);

        $text = "Твой бесплатный период завершён 🌙\n\n";
        $text .= "Чтобы я продолжала быть рядом — питание, кожа, энергия и поддержка — оформи подписку\.\n\n";
        $text .= "Это инвестиция в себя, которая окупается ежедневно 💎";

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => "✨ Оформить подписку — {$price} руб.", 'url' => $payUrl]],
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
     * Handle successful payment (Prodamus).
     */
    public function handlePayment(int $userId, string $orderId, float $amount): void
    {
        $days = Config::getSubscriptionDays();

        $userService = new UserService();
        $user = $userService->findById($userId);

        // Update log
        $this->db->execute(
            'UPDATE payment_logs SET status = "paid", paid_at = NOW() WHERE order_id = :oid',
            [':oid' => $orderId]
        );

        // Check if renewal
        $isRenewal = $this->isUserRenewal($userId);

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
                ':charge' => $orderId, // Reuse charge_id field for order_id
                ':amount' => (int) $amount,
                ':start'  => $startsAt,
                ':end'    => $endsAt,
            ]
        );

        // Update user
        $userService->setSubscriptionEnd($userId, $endsAt);

        // Notify user
        $text = "✨ *Оплата прошла успешно\!*\n\n";
        $text .= "Твой доступ продлён до " . date('d\.m\.Y', strtotime($endsAt)) . "\n";
        $text .= "Я продолжаю работать для тебя 💎";
        
        $this->telegram->sendMessage((int) $user['telegram_id'], $text);
    }

    private function isUserRenewal(int $userId): bool
    {
        $count = $this->db->fetchColumn(
            'SELECT COUNT(*) FROM subscriptions WHERE user_id = :uid',
            [':uid' => $userId]
        );
        return $count > 0;
    }

    // ─── Admin queries ───────────────────────────────────────────

    public function getPaymentLogs(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, u.username, u.first_name, u.last_name 
             FROM payment_logs p 
             JOIN users u ON p.user_id = u.id 
             ORDER BY p.created_at DESC 
             LIMIT :limit OFFSET :offset',
            [':limit' => $limit, ':offset' => $offset]
        );
    }

    public function getLinksPerDay(int $days = 10): array
    {
        return $this->db->fetchAll(
            'SELECT DATE(created_at) AS day, COUNT(*) AS count 
             FROM payment_logs 
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY) 
             GROUP BY DATE(created_at) ORDER BY day ASC',
            [':days' => $days]
        );
    }

    public function getPaidPerDay(int $days = 10): array
    {
        return $this->db->fetchAll(
            'SELECT DATE(paid_at) AS day, COUNT(*) AS count 
             FROM payment_logs 
             WHERE status = "paid" AND paid_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY) 
             GROUP BY DATE(paid_at) ORDER BY day ASC',
            [':days' => $days]
        );
    }

    public function getStatsAllTime(): array
    {
        return $this->db->fetchOne(
            'SELECT 
                COUNT(*) as total_links,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) as total_revenue,
                AVG(CASE WHEN is_renewal = 1 AND status = "paid" THEN 1 WHEN is_renewal = 0 AND status = "paid" THEN 0 ELSE NULL END) * 100 as renewal_rate
             FROM payment_logs'
        ) ?: [];
    }

    public function getStatsLastMonth(): array
    {
        return $this->db->fetchOne(
            'SELECT 
                COUNT(*) as total_links,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) as total_revenue
             FROM payment_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)'
        ) ?: [];
    }

    public function getRenewalPercentage(): float
    {
        // Percentage of users who have more than 1 subscription
        $totalUsersWithSub = $this->db->fetchColumn('SELECT COUNT(DISTINCT user_id) FROM subscriptions');
        if ($totalUsersWithSub == 0) return 0.0;
        
        $usersWithRenewals = $this->db->fetchColumn(
            'SELECT COUNT(*) FROM (SELECT user_id FROM subscriptions GROUP BY user_id HAVING COUNT(*) > 1) as t'
        );
        
        return round(($usersWithRenewals / $totalUsersWithSub) * 100, 2);
    }
}
