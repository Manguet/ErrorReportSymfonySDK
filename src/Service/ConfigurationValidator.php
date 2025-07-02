<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use ErrorExplorer\ErrorReporter\Exception\ErrorReporterException;

final class ConfigurationValidator
{
    public function validateWebhookUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw ErrorReporterException::invalidConfiguration('webhook_url', 'must be a valid URL');
        }

        if (!str_starts_with($url, 'https://')) {
            throw ErrorReporterException::invalidConfiguration('webhook_url', 'must use HTTPS protocol');
        }
    }

    public function validateToken(string $token): void
    {
        if (strlen($token) < 10) {
            throw ErrorReporterException::invalidConfiguration('token', 'must be at least 10 characters long');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $token)) {
            throw ErrorReporterException::invalidConfiguration('token', 'must contain only alphanumeric characters, hyphens and underscores');
        }
    }

    public function validateProjectName(string $projectName): void
    {
        if (strlen($projectName) < 2) {
            throw ErrorReporterException::invalidConfiguration('project_name', 'must be at least 2 characters long');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectName)) {
            throw ErrorReporterException::invalidConfiguration('project_name', 'must contain only alphanumeric characters, hyphens and underscores');
        }
    }

    public function validateTimeout(int $timeout): void
    {
        if ($timeout < 1 || $timeout > 30) {
            throw ErrorReporterException::invalidConfiguration('timeout', 'must be between 1 and 30 seconds');
        }
    }

    public function validateMaxRetries(int $maxRetries): void
    {
        if ($maxRetries < 0 || $maxRetries > 10) {
            throw ErrorReporterException::invalidConfiguration('max_retries', 'must be between 0 and 10');
        }
    }
}
