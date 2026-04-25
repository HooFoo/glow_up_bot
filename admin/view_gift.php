<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Settings;

$persona = $_GET['persona'] ?? '';
$personas = ['shadow_queen', 'iron_controller', 'sleeping_muse', 'magnetic_prime'];

if (!in_array($persona, $personas)) {
    die('Invalid persona');
}

$defaults = [
    'shadow_queen'    => 'shadow_queen_sos.pdf',
    'iron_controller' => 'iron_controller_delegation.pdf',
    'sleeping_muse'   => 'sleeping_muse_tracker.pdf',
    'magnetic_prime'  => 'magnetic_prime_longevity.pdf',
];

$filename = Settings::get('gift_pdf_' . $persona, $defaults[$persona] ?? null);

if (!$filename) {
    die('File not found in settings');
}

$filePath = Config::getProjectRoot() . '/bot/assets/gifts/' . $filename;

if (!file_exists($filePath)) {
    die('File not found on disk: ' . $filePath);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
