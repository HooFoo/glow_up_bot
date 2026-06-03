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
    private TextService $textService;

    public function __construct(TelegramApi $telegram)
    {
        $this->db = Database::getInstance();
        $this->telegram = $telegram;
        $this->textService = new TextService();
    }

    /**
     * Check if user has an active paid subscription.
     */
    public function hasActiveSubscription(array $user): bool
    {
        return !empty($user['subscription_end']) && strtotime($user['subscription_end']) > time();
    }

    /**
     * Check if user is currently in the trial period.
     */
    public function hasActiveTrial(array $user): bool
    {
        $freeDays = Config::getFreeDays();
        return !empty($user['quiz_completed_at']) && (strtotime($user['quiz_completed_at']) + $freeDays * 86400) > time();
    }

    /**
     * Try to consume a free action. Returns true if successful or if user is paid / on trial.
     */
    public function consumeFreeAction(array $user, string $actionType): bool
    {
        if ($this->hasActiveSubscription($user) || $this->hasActiveTrial($user)) {
            return true;
        }

        $userService = new UserService();
        return match ($actionType) {
            'meal'     => $userService->tryIncrementFreeMealCount((int)$user['id'], 3),
            'cosmetic' => $userService->tryIncrementFreeCosmeticCount((int)$user['id'], 1),
            'request'  => $userService->tryIncrementFreeRequestCount((int)$user['id'], 1),
            default    => false,
        };
    }

    /**
     * Send message when limit is reached.
     */
    public function sendLimitReachedMessage(int $chatId, string $actionType): void
    {
        $textKey = match ($actionType) {
            'meal'     => 'msg_limit_reached_meal',
            'cosmetic' => 'msg_limit_reached_cosmetic',
            'request'  => 'msg_limit_reached_request',
            default    => 'msg_limit_reached_request',
        };

        $text = $this->textService->get($textKey, "Лимит исчерпан. Попробуй позже или оформи подписку ✨", true);
        
        $price = Config::getProdamusPrice();
        $prodamus = new ProdamusService();
        $orderId = 'sub_limit_' . $chatId . '_' . time();
        $payUrl = $prodamus->generatePaymentLink($chatId, $orderId, (float) $price);

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => $this->textService->get('btn_limit_buy_sub', '✨ Перейти в Prime', true), 'url' => $payUrl]],
        ]);

        $this->telegram->sendMessage($chatId, $text, $keyboard);
    }

    /**
     * DEPRECATED: Use canPerformAction instead.
     * Kept for backward compatibility if needed during transition.
     */
    public function checkAccess(array $user): bool
    {
        return $this->hasActiveSubscription($user);
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

        $text = $this->textService->get('msg_paywall_text', "Твой бесплатный период завершён 🌙\n\nЧтобы я продолжала быть рядом — питание, кожа, энергия и поддержка — оформи подписку.\n\nЭто инвестиция в себя, которая окупается ежедневно 💎\n\n_Оплата возможна в разных валютах_", true);

        $keyboard = TelegramApi::inlineKeyboard([
            [['text' => sprintf($this->textService->get('btn_subscribe_stars', "✨ Оформить подписку — %d ₽", true), $price), 'url' => $payUrl]],
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
            $this->textService->get('invoice_title', 'Prime Glow подписка', true),
            sprintf($this->textService->get('invoice_description', "Полный доступ к AI-ассистенту на %d дней: питание, косметика, коучинг", true), $days),
            "sub_{$userId}_" . time(),
            $price,
            'XTR'
        );
    }

    /**
     * Handle successful payment (Prodamus).
     */
    public function handlePayment(int $userId, string $orderId, float $amount, ?string $bindingId = null): void
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
        if ($bindingId !== null) {
            $userService->updateProdamusBindingId($userId, $bindingId);
        }

        // Notify user
        $text = sprintf($this->textService->get('msg_payment_success', "✨ *Оплата прошла успешно!*\n\nТвой доступ продлён до %s\nЯ продолжаю работать для тебя 💎", true), date('d.m.Y', strtotime($endsAt)));
        
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

    public function getSubscriptionsPerDay(int $days = 10): array
    {
        return $this->db->fetchAll(
            'SELECT DATE(starts_at) AS day, COUNT(*) AS count 
             FROM subscriptions 
             WHERE starts_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY) 
             GROUP BY DATE(starts_at) ORDER BY day ASC',
            [':days' => $days]
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

    public function getPaidByLinkDate(int $days = 10): array
    {
        return $this->db->fetchAll(
            'SELECT DATE(created_at) AS day, COUNT(*) AS count 
             FROM payment_logs 
             WHERE status = "paid" AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY) 
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
