<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Logger;
use App\Services\ProdamusService;
use App\Services\SubscriptionService;
use App\Core\TelegramApi;

$logger = Logger::getInstance();
$input = file_get_contents('php://input');

$logger->info('Prodamus callback received', ['body' => $input]);

// Get signature from header
$signature = $_SERVER['HTTP_SIGN'] ?? '';

if (empty($input) || empty($signature)) {
    $logger->error('Prodamus callback: missing body or signature');
    http_response_code(400);
    exit('Missing data');
}

$prodamus = new ProdamusService();
if (!$prodamus->verifySignature($input, $signature)) {
    $logger->error('Prodamus callback: invalid signature', ['received' => $signature]);
    http_response_code(403);
    exit('Invalid signature');
}

// Parse notification data
// Prodamus sends data as POST parameters (application/x-www-form-urlencoded usually, 
// but can be JSON if configured. Standard is form-data).
parse_str($input, $data);

$logger->info('Prodamus callback verified', $data);

// Required fields
$orderId = $data['order_id'] ?? '';
$userId = (int) ($data['customer_extra'] ?? 0);
$sum = (float) ($data['sum'] ?? 0);
$paymentStatus = $data['payment_status'] ?? ''; // 'success' is typical for successful payment

// Note: Prodamus might send different statuses. For successful payment, we check payment_status.
if ($paymentStatus === 'success' && $userId > 0 && !empty($orderId)) {
    $telegram = new TelegramApi();
    $subService = new SubscriptionService($telegram);
    
    try {
        $subService->handlePayment($userId, $orderId, $sum);
        echo 'OK'; // Tell Prodamus we processed it
    } catch (\Throwable $e) {
        $logger->error('Prodamus callback: processing error', [
            'message' => $e->getMessage(),
            'order_id' => $orderId
        ]);
        http_response_code(500);
        exit('Processing error');
    }
} else {
    $logger->info('Prodamus callback: ignored status or invalid data', ['status' => $paymentStatus]);
    echo 'OK'; // Still return 200 to avoid retries for non-success events
}
