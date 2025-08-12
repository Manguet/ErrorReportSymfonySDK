<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use ErrorExplorer\ErrorReporter\ErrorReporter;

final class ErrorReporterInitializer
{
    public function __construct(
        private readonly WebhookErrorReporter $webhookErrorReporter
    ) {
        // Initialize the static facade with dependency injection
        ErrorReporter::setInstance($this->webhookErrorReporter);
    }

    public function getWebhookErrorReporter(): WebhookErrorReporter
    {
        return $this->webhookErrorReporter;
    }
}
