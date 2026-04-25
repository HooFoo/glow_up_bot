<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\TelegramApi;
use App\Services\TrialFunnelService;

$telegram = new TelegramApi();
$trialService = new TrialFunnelService($telegram);

echo "Starting trial funnel worker...\n";
$trialService->process();
echo "Done.\n";
