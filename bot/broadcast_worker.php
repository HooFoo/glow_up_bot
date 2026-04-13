<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;
use App\Core\TelegramApi;
use App\Services\BroadcastService;

$db = Database::getInstance();
$telegram = new TelegramApi();
$broadcastService = new BroadcastService();

// Find a pending or sending broadcast
$broadcast = $db->fetchOne("SELECT * FROM broadcasts WHERE status IN ('pending', 'sending') ORDER BY id ASC LIMIT 1");

if (!$broadcast) {
    exit("No broadcasts to process.\n");
}

$broadcastId = (int)$broadcast['id'];
$messageText = $broadcast['message_text'];
$filters = json_decode($broadcast['filters'], true);

// Update status to sending if it was pending
if ($broadcast['status'] === 'pending') {
    $db->execute("UPDATE broadcasts SET status = 'sending' WHERE id = :id", [':id' => $broadcastId]);
}

// Get users who haven't received this broadcast yet
// We'll use broadcast_logs to track who already got it
$sql = "SELECT DISTINCT u.telegram_id, u.id as user_id 
        FROM users u 
        LEFT JOIN user_profiles up ON u.id = up.user_id 
        WHERE u.id NOT IN (SELECT user_id FROM broadcast_logs WHERE broadcast_id = :bid)
        AND u.is_blocked = 0";

// Re-apply the same filters
$params = [':bid' => $broadcastId];
$filterSql = "";
// (This is a simplified re-application of filters logic from BroadcastService)
// In a real app, I'd move the user fetching logic entirely to BroadcastService
$userIds = $broadcastService->getUserIds($filters);

if (empty($userIds)) {
    $db->execute("UPDATE broadcasts SET status = 'completed' WHERE id = :id", [':id' => $broadcastId]);
    exit("Done. No users match filters.\n");
}

// Filter out those who already received
$recipients = $db->fetchAll("SELECT user_id FROM broadcast_logs WHERE broadcast_id = :bid", [':bid' => $broadcastId]);
$alreadyReceivedIds = array_column($recipients, 'user_id');
$remainingUserIds = array_diff($userIds, $alreadyReceivedIds);

if (empty($remainingUserIds)) {
    $db->execute("UPDATE broadcasts SET status = 'completed' WHERE id = :id", [':id' => $broadcastId]);
    exit("Done. All users already processed.\n");
}

echo "Starting broadcast #{$broadcastId} for " . count($remainingUserIds) . " users...\n";

foreach ($remainingUserIds as $userId) {
    // Check if broadcast was paused or cancelled by admin
    $currentStatus = $db->fetchColumn("SELECT status FROM broadcasts WHERE id = :id", [':id' => $broadcastId]);
    if ($currentStatus === 'paused') {
        echo "Broadcast paused.\n";
        break;
    }

    $user = $db->fetchOne("SELECT telegram_id FROM users WHERE id = :id", [':id' => $userId]);
    if (!$user) continue;

    $chatId = (int)$user['telegram_id'];
    
    $result = $telegram->sendMessage($chatId, $messageText);

    if ($result['ok']) {
        $db->insert("INSERT INTO broadcast_logs (broadcast_id, user_id, status) VALUES (:bid, :uid, 'sent')", [
            ':bid' => $broadcastId,
            ':uid' => $userId
        ]);
        $broadcastService->updateProgress($broadcastId, 1, 0);
    } else {
        $errorCode = $result['error_code'] ?? 0;
        $description = $result['description'] ?? 'Unknown error';

        if ($errorCode == 429) {
            // Rate limit!
            $retryAfter = $result['parameters']['retry_after'] ?? 30;
            echo "Rate limited. Waiting for {$retryAfter} seconds...\n";
            sleep($retryAfter + 1);
            // Retry this user
            $userId--; // Hacky retry in loop if needed, better to just continue and let next cron run handle it or repeat
            // For simplicity in this script, let's just wait and continuing with the next user is safer. 
            // The current user will be picked up in the next run because they didn't get a log entry.
            continue; 
        }

        $db->insert("INSERT INTO broadcast_logs (broadcast_id, user_id, status, error_message) VALUES (:bid, :uid, 'failed', :err)", [
            ':bid' => $broadcastId,
            ':uid' => $userId,
            ':err' => $description
        ]);
        $broadcastService->updateProgress($broadcastId, 0, 1);

        if (str_contains($description, 'bot was blocked') || str_contains($description, 'user is deactivated')) {
            $db->execute("UPDATE users SET is_blocked = 1 WHERE id = :id", [':id' => $userId]);
        }
    }

    // Small delay to avoid hitting limits too fast
    usleep(50000); // 50ms
}

// Final check
$stats = $db->fetchOne("SELECT sent_users + failed_users as processed, total_users FROM broadcasts WHERE id = :id", [':id' => $broadcastId]);
if ($stats && $stats['processed'] >= $stats['total_users']) {
    $db->execute("UPDATE broadcasts SET status = 'completed' WHERE id = :id", [':id' => $broadcastId]);
    echo "Broadcast completed.\n";
}
