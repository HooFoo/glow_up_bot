<?php

declare(strict_types=1);

namespace App\Core;

class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'error' => 2];

    private static ?self $instance = null;
    private string $logDir;
    private int $minLevel;

    private function __construct()
    {
        $this->logDir = Config::getProjectRoot() . '/logs';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0777, true);
        }
        $this->minLevel = self::LEVELS[Config::getLogLevel()] ?? 2;
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

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < $this->minLevel) {
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
