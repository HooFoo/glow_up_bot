<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Settings;

$filename = Settings::get('offer_pdf');

if (!$filename) {
    die('Файл оферты не загружен');
}

$filePath = Config::getProjectRoot() . '/bot/assets/documents/' . $filename;

if (!file_exists($filePath)) {
    die('Файл не найден на диске: ' . $filePath);
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
