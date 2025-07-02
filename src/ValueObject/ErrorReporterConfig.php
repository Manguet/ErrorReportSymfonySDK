<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\ValueObject;

use ErrorExplorer\ErrorReporter\Enum\LogLevel;

class ErrorReporterConfig
{
    public function __construct(
        public string $webhookUrl,
        public string $token,
        public string $projectName,
        public bool $enabled = true,
        public LogLevel $minimumLevel = LogLevel::ERROR,
        public array $ignoredExceptions = [],
        public int $timeout = 5,
        public int $maxRetries = 3,
        public bool $verifySsl = true,
        public bool $breadcrumbsEnabled = true,
        public int $maxBreadcrumbs = 50,
    ) {}

    public static function fromArray(array $config): self
    {
        return new self(
            webhookUrl: $config['webhook_url'],
            token: $config['token'],
            projectName: $config['project_name'],
            enabled: $config['enabled'] ?? true,
            minimumLevel: LogLevel::from($config['minimum_level'] ?? 'error'),
            ignoredExceptions: $config['ignore_exceptions'] ?? [],
            timeout: $config['http_client']['timeout'] ?? 5,
            maxRetries: $config['http_client']['max_retries'] ?? 3,
            verifySsl: $config['http_client']['verify_ssl'] ?? true,
            breadcrumbsEnabled: $config['breadcrumbs']['enabled'] ?? true,
            maxBreadcrumbs: $config['breadcrumbs']['max_breadcrumbs'] ?? 50,
        );
    }

    public function toArray(): array
    {
        return [
            'webhook_url' => $this->webhookUrl,
            'token' => $this->token,
            'project_name' => $this->projectName,
            'enabled' => $this->enabled,
            'minimum_level' => $this->minimumLevel->value,
            'ignore_exceptions' => $this->ignoredExceptions,
            'http_client' => [
                'timeout' => $this->timeout,
                'max_retries' => $this->maxRetries,
                'verify_ssl' => $this->verifySsl,
            ],
            'breadcrumbs' => [
                'enabled' => $this->breadcrumbsEnabled,
                'max_breadcrumbs' => $this->maxBreadcrumbs,
            ],
        ];
    }

    public function isProductionReady(): bool
    {
        return $this->enabled
            && str_starts_with($this->webhookUrl, 'https://')
            && strlen($this->token) >= 10
            && $this->verifySsl;
    }
}
