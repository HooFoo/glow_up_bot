<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Database;

$db = Database::getInstance();

// 1. Daily reset (Requests)
echo "Resetting daily limits (free_request_count)...\n";
$db->execute('UPDATE users SET free_request_count = 0');

// 2. Weekly reset (Meals, Cosmetics) - only if it's Monday
// date('N') returns 1 for Monday
if (date('N') === '1') {
    echo "Resetting weekly limits (free_meal_count, free_cosmetic_count)...\n";
    $db->execute('UPDATE users SET free_meal_count = 0, free_cosmetic_count = 0');
}

echo "Reset limits completed successfully.\n";
