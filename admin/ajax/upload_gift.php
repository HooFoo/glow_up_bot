<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
requireAuth();

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\Settings;
use App\Core\Config;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$persona = $_POST['persona'] ?? '';
$personas = ['shadow_queen', 'iron_controller', 'sleeping_muse', 'magnetic_prime'];

if (!in_array($persona, $personas)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid persona']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error: ' . ($_FILES['file']['error'] ?? 'empty')]);
    exit;
}

$file = $_FILES['file'];
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
if (strtolower($ext) !== 'pdf') {
    echo json_encode(['ok' => false, 'error' => 'Файл должен быть в формате PDF']);
    exit;
}

$newName = $persona . '_' . time() . '.pdf';
$targetPath = Config::getProjectRoot() . '/bot/assets/gifts/' . $newName;

// Ensure directory exists
$dir = dirname($targetPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    Settings::set('gift_pdf_' . $persona, $newName);
    echo json_encode(['ok' => true, 'filename' => $newName]);
} else {
    echo json_encode(['ok' => false, 'error' => 'Не удалось переместить файл в ' . $targetPath]);
}
