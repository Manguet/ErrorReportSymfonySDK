<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Enum;

enum LogLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
    case ALERT = 'alert';
    case EMERGENCY = 'emergency';

    public function getPriority(): int
    {
        return match ($this) {
            self::DEBUG => 100,
            self::INFO => 200,
            self::WARNING => 300,
            self::ERROR => 400,
            self::CRITICAL => 500,
            self::ALERT => 550,
            self::EMERGENCY => 600,
        };
    }

    public function isHighSeverity(): bool
    {
        return match ($this) {
            self::ERROR, self::CRITICAL, self::ALERT, self::EMERGENCY => true,
            default => false,
        };
    }
}