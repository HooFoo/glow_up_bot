<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Logger;
use App\Services\ProdamusService;
use App\Services\SubscriptionService;
use App\Core\TelegramApi;

$logger = Logger::getInstance();
$requestId = uniqid('prdm_', true);

// If it's a GET request, it's likely a user redirect from Prodamus.
// We redirect them to the beautiful success/fail pages we created.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = $_GET['payment_status'] ?? '';
    if ($status === 'success') {
        header('Location: success.php');
    } else {
        header('Location: fail.php');
    }
    exit;
}

$input = file_get_contents('php://input');

// Prodamus can send data as JSON or as a query string.
// Based on the screenshots, we should support both.
$data = json_decode($input, true);
if ($data === null) {
    parse_str($input, $data);
}

$paymentStatus = $data['payment_status'] ?? 'unknown';

// 1. Info log: basic info
$logger->info("Prodamus webhook received [ID: {$requestId}]", [
    'id' => $requestId,
    'status' => $paymentStatus,
    'order_id' => $data['order_id'] ?? 'n/a'
]);

// 2. Debug log: full body
$logger->debug("Prodamus webhook full body [ID: {$requestId}]", [
    'id' => $requestId,
    'raw_body' => $input
]);

// Get signature from header. Prodamus sends it in the 'Sign' header.
$signature = $_SERVER['HTTP_SIGN'] ?? '';

// Fallback for different server configurations
if (empty($signature) && function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'sign') {
            $signature = $value;
            break;
        }
    }
}

if (empty($input) || empty($signature)) {
    $logger->error("Prodamus callback error [ID: {$requestId}]: missing body or signature", [
        'has_input' => !empty($input),
        'has_signature' => !empty($signature)
    ]);
    http_response_code(400);
    exit('Missing data');
}

$prodamus = new ProdamusService();
if (!$prodamus->verifySignature($input, $signature)) {
    $logger->error("Prodamus callback error [ID: {$requestId}]: invalid signature", [
        'received' => $signature,
        'body' => $input
    ]);
    http_response_code(403);
    exit('Invalid signature');
}

$logger->info("Prodamus callback signature verified [ID: {$requestId}]");

// Required fields
$orderId = (string)($data['order_id'] ?? '');
$userId = (int) ($data['customer_extra'] ?? 0);
$sum = (float) ($data['sum'] ?? 0);

// For successful payment, we check payment_status.
if ($paymentStatus === 'success' && $userId > 0 && !empty($orderId)) {
    $telegram = new TelegramApi();
    $subService = new SubscriptionService($telegram);
    
    try {
        $subService->handlePayment($userId, $orderId, $sum);
        $logger->info("Prodamus payment processed successfully [ID: {$requestId}]", ['order_id' => $orderId, 'user_id' => $userId]);
        echo 'OK';
    } catch (\Throwable $e) {
        $logger->error("Prodamus processing failed [ID: {$requestId}]", [
            'message' => $e->getMessage(),
            'order_id' => $orderId,
            'trace' => $e->getTraceAsString()
        ]);
        http_response_code(500);
        exit('Processing error');
    }
} else {
    $logger->info("Prodamus callback ignored [ID: {$requestId}]", [
        'reason' => 'non-success status or invalid data',
        'status' => $paymentStatus,
        'user_id' => $userId,
        'order_id' => $orderId
    ]);
    echo 'OK';
}
