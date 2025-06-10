<?php

namespace ErrorExplorer\ErrorReporter\Service;

use ErrorExplorer\ErrorReporter\ErrorReporter;

/**
 * Service to initialize the static ErrorReporter facade
 */
class ErrorReporterInitializer
{
    /** @var WebhookErrorReporter */
    private $webhookErrorReporter;

    public function __construct(WebhookErrorReporter $webhookErrorReporter)
    {
        $this->webhookErrorReporter = $webhookErrorReporter;
        
        // Initialize the static facade
        ErrorReporter::setInstance($this->webhookErrorReporter);
    }

    /**
     * Get the underlying WebhookErrorReporter service
     */
    public function getWebhookErrorReporter()
    {
        return $this->webhookErrorReporter;
    }
}