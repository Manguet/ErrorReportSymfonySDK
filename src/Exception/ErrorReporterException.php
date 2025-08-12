<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Exception;

use RuntimeException;

class ErrorReporterException extends RuntimeException
{
    public static function serviceNotInitialized(): self
    {
        return new self('ErrorReporter service not initialized. Make sure the bundle is properly configured.');
    }

    public static function invalidConfiguration(string $parameter, string $reason): self
    {
        return new self(sprintf('Invalid configuration for "%s": %s', $parameter, $reason));
    }

    public static function webhookFailed(string $url, string $reason): self
    {
        return new self(sprintf('Webhook request to "%s" failed: %s', $url, $reason));
    }

    public static function invalidLogLevel(string $level): self
    {
        return new self(sprintf('Invalid log level "%s". Must be one of: debug, info, warning, error, critical, alert, emergency', $level));
    }
}