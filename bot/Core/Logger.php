<?php

declare(strict_types=1);

namespace App\Core;

class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private static ?self $instance = null;
    private string $logDir;
    private int $minLevel;

    private function __construct()
    {
        $this->logDir = Config::getProjectRoot() . '/logs';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0777, true);
        }
        $this->minLevel = self::LEVELS[Config::getLogLevel()] ?? 3;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $minLevel = self::LEVELS[$level] ?? 0;
        
        // Check if level is enabled by environment config
        $enabledByConfig = $minLevel >= $this->minLevel;
        
        // Check if level is force-enabled via Database settings
        $enabledBySettings = false;
        if ($level === 'info') {
            $enabledBySettings = Settings::isEnabled('log_info_enabled');
        } elseif ($level === 'debug') {
            $enabledBySettings = Settings::isEnabled('log_debug_enabled');
        }

        if (!$enabledByConfig && !$enabledBySettings && $level !== 'error') {
            return;
        }

        $datetime = date('Y-m-d H:i:s');
        $levelUp = strtoupper($level);
        $line = "[{$datetime}] [{$levelUp}] {$message}";

        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line .= PHP_EOL;

        $file = $this->logDir . '/' . $level . '.log';
        if (is_dir($this->logDir)) {
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }
}
