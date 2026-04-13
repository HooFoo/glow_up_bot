<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
// We don't call requireAuth() as a function here because it might redirect, 
// but we check the session directly or ensure it's handled.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

use App\Services\BroadcastService;

$broadcastService = new BroadcastService();

$filters = [
    'payment_status' => $_GET['payment_status'] ?? '',
    'reg_from'        => $_GET['reg_from'] ?? '',
    'reg_to'          => $_GET['reg_to'] ?? '',
    'last_msg_before' => $_GET['last_msg_before'] ?? '',
    'goal'            => $_GET['goal'] ?? '',
    'health'          => $_GET['health'] ?? '',
    'persona'         => $_GET['persona'] ?? '',
];

$count = $broadcastService->countUsers($filters);

echo json_encode(['count' => $count]);
