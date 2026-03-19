<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();

$logDir = dirname(__DIR__) . '/logs';
$logLevel = $_GET['level'] ?? 'error';
$allowedLevels = ['error', 'info', 'debug'];

if (!in_array($logLevel, $allowedLevels)) {
    $logLevel = 'error';
}

$logFile = $logDir . '/' . $logLevel . '.log';
$logContent = "Лог-файл ({$logLevel}.log) не найден или пуст.";

if (file_exists($logFile)) {
    $escapedPath = escapeshellarg($logFile);
    // On Linux we can use tail efficiently
    $logContent = shell_exec("tail -n 100 $escapedPath") ?: "Не удалось прочитать содержимое лога.";
}

adminHeader('Логи', 'logs');
?>

<style>
    .log-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .log-tabs {
        display: flex;
        gap: 12px;
    }
    .log-tab {
        padding: 8px 16px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 13px;
        color: var(--text-secondary);
        background: var(--bg-card);
        border: 1px solid var(--border);
        transition: all 0.2s;
    }
    .log-tab.active {
        background: var(--bg-card-alt);
        color: var(--accent-primary);
        border-color: var(--accent-primary);
    }
    .log-tab:hover:not(.active) {
        border-color: var(--text-secondary);
    }
    .log-viewer {
        background: #0d0d0d;
        color: #e0e0e0;
        font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
        padding: 24px;
        border-radius: 12px;
        border: 1px solid var(--border);
        height: calc(100vh - 250px);
        overflow-y: auto;
        font-size: 13px;
        line-height: 1.6;
        white-space: pre-wrap;
        word-wrap: break-word;
        box-shadow: inset 0 2px 10px rgba(0,0,0,0.5);
    }
    .log-viewer::-webkit-scrollbar { width: 8px; }
    .log-viewer::-webkit-scrollbar-track { background: #111; border-radius: 4px; }
    .log-viewer::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
    
    .log-line-error { color: #f87171; }
    .log-line-info { color: #60a5fa; }
    .log-line-debug { color: #9ca3af; }
    .timestamp { color: #6b7280; font-weight: 500; }
</style>

<div class="log-actions">
    <div class="log-tabs">
        <a href="logs.php?level=error" class="log-tab <?= $logLevel === 'error' ? 'active' : '' ?>">🔴 Error</a>
        <a href="logs.php?level=info" class="log-tab <?= $logLevel === 'info' ? 'active' : '' ?>">🔵 Info</a>
        <a href="logs.php?level=debug" class="log-tab <?= $logLevel === 'debug' ? 'active' : '' ?>">⚪ Debug</a>
    </div>
    <div class="log-buttons">
        <a href="logs.php?level=<?= $logLevel ?>" class="btn btn-outline">🔄 Обновить</a>
    </div>
</div>

<div class="log-viewer"><?php
    $lines = explode(PHP_EOL, trim((string)$logContent));
    foreach ($lines as $line) {
        if (empty($line)) continue;
        
        // Simple highlighting for common log parts
        $line = htmlspecialchars($line);
        // Highlight timestamps if in [YYYY-MM-DD HH:MM:SS] format
        $line = preg_replace('/^(\[.*?\])/', '<span class="timestamp">$1</span>', $line);
        
        $class = '';
        if (stripos($line, '[ERROR]') !== false) $class = 'log-line-error';
        elseif (stripos($line, '[INFO]') !== false) $class = 'log-line-info';
        elseif (stripos($line, '[DEBUG]') !== false) $class = 'log-line-debug';
        
        echo "<div class='{$class}'>{$line}</div>";
    }
?></div>

<script>
    // Scroll to bottom on load
    const viewer = document.querySelector('.log-viewer');
    viewer.scrollTop = viewer.scrollHeight;
</script>

<?php adminFooter(); ?>
