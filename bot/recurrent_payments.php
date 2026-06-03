<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\TelegramApi;
use App\Services\ProdamusService;
use App\Services\SubscriptionService;
use App\Services\TextService;

$db = Database::getInstance();
$logger = Logger::getInstance();
$telegram = new TelegramApi();
$prodamus = new ProdamusService();
$subService = new SubscriptionService($telegram);
$textService = new TextService();

$logger->info("Starting recurrent payments check...");

// 1. Get price of the subscription
$price = (float) Config::getProdamusPrice();
if ($price <= 0) {
    $logger->error("Recurrent payments aborted: Invalid subscription price", ['price' => $price]);
    exit;
}

// 2. Fetch users whose subscription has expired, have card binding token,
// are not blocked, and no charge attempt has been made in the last 23 hours.
$users = $db->fetchAll(
    'SELECT * FROM users 
     WHERE subscription_end <= NOW() 
       AND prodamus_binding_id IS NOT NULL 
       AND is_blocked = 0 
       AND (last_recurrent_attempt IS NULL OR last_recurrent_attempt < DATE_SUB(NOW(), INTERVAL 23 HOUR))'
);

$logger->info(sprintf("Found %d users with active bindings pending renewal", count($users)));

foreach ($users as $user) {
    $userId = (int) $user['id'];
    $bindingId = (string) $user['prodamus_binding_id'];
    $telegramId = (int) $user['telegram_id'];

    $logger->info("Attempting recurrent charge for user {$userId}...", ['user_id' => $userId, 'telegram_id' => $telegramId]);

    // Update last attempt timestamp immediately to prevent double charges from concurrent runs
    $db->execute('UPDATE users SET last_recurrent_attempt = NOW() WHERE id = :id', [':id' => $userId]);

    // Generate a unique order ID for the recurrent payment
    $orderId = 'rec_sub_' . $userId . '_' . time();

    // Create a record in payment_logs with status 'link_sent'
    $db->insert(
        'INSERT INTO payment_logs (user_id, order_id, amount, status, is_renewal, created_at) 
         VALUES (:uid, :oid, :amount, "link_sent", 1, NOW())',
        [
            ':uid' => $userId,
            ':oid' => $orderId,
            ':amount' => $price
        ]
    );

    // Call Prodamus Recurrent API
    $result = $prodamus->chargeRecurrent($userId, $bindingId, $orderId, $price);

    if (!empty($result['success']) && $result['success'] === true) {
        $logger->info("Recurrent charge successful for user {$userId}!", ['user_id' => $userId, 'order_id' => $orderId]);
        
        try {
            // This method updates the log status to 'paid', adds a subscription record,
            // updates users.subscription_end, and notifies the user via Telegram.
            $subService->handlePayment($userId, $orderId, $price, $bindingId);
        } catch (\Throwable $e) {
            $logger->error("Error finalizing subscription for user {$userId} after successful charge", [
                'user_id' => $userId,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
        }
    } else {
        $errorMsg = $result['error'] ?? 'Unknown Prodamus error';
        $logger->warning("Recurrent charge failed for user {$userId}", ['user_id' => $userId, 'order_id' => $orderId, 'error' => $errorMsg]);

        // Update payment log status to 'recurrent_failed'
        $db->execute('UPDATE payment_logs SET status = "recurrent_failed" WHERE order_id = :oid', [':oid' => $orderId]);

        // Notify user about failed auto-renewal and prompt to renew manually
        try {
            // Generate manual paywall link
            $manualOrderId = 'sub_' . $userId . '_' . time();
            
            // Log the manual link attempt in payment_logs
            $db->insert(
                'INSERT INTO payment_logs (user_id, order_id, amount, status, is_renewal, created_at) 
                 VALUES (:uid, :oid, :amount, "link_sent", 1, NOW())',
                [
                    ':uid' => $userId,
                    ':oid' => $manualOrderId,
                    ':amount' => $price
                ]
            );

            $payLink = $prodamus->generatePaymentLink($userId, $manualOrderId, $price);
            
            $text = $textService->get('msg_autorenewal_failed', "Автопродление не удалось (ошибка списания с карты). 🌙 Чтобы сохранить доступ к питанию, косметике и практикам, пожалуйста, оплатите подписку вручную:", true);
            
            $keyboard = TelegramApi::inlineKeyboard([
                [['text' => $textService->get('btn_pay_manually', '✨ Оплатить подписку', true), 'url' => $payLink]]
            ]);

            $telegram->sendMessage($telegramId, $text, $keyboard);
        } catch (\Throwable $e) {
            $logger->error("Error sending manual payment link to user {$userId} after failed recurrent charge", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}

$logger->info("Recurrent payments check completed.");
