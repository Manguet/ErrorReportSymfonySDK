<?php

namespace ErrorExplorer\ErrorReporter;

use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Static facade for easy error reporting
 * 
 * Usage:
 * ErrorReporter::reportError($exception);
 * ErrorReporter::reportError($exception, 'prod', 500);
 */
class ErrorReporter
{
    /** @var WebhookErrorReporter|null */
    private static $instance;

    /**
     * Set the service instance (called by DI container)
     */
    public static function setInstance(WebhookErrorReporter $instance)
    {
        self::$instance = $instance;
    }

    /**
     * Report an error using the static interface
     *
     * @param \Throwable $exception
     * @param string $environment
     * @param int|null $httpStatus
     * @param Request|null $request
     */
    public static function reportError(\Throwable $exception, $environment = 'prod', $httpStatus = null, Request $request = null)
    {
        if (self::$instance === null) {
            // Fallback: try to create a basic instance if not initialized
            // This shouldn't happen in a properly configured Symfony app
            error_log('ErrorReporter: Service not initialized. Make sure the bundle is properly configured.');
            return;
        }

        self::$instance->reportError($exception, $environment, $httpStatus, $request);
    }

    /**
     * Check if the error reporter is enabled and configured
     */
    public static function isConfigured()
    {
        return self::$instance !== null;
    }

    /**
     * Quick helper to report an exception with minimal parameters
     */
    public static function report(\Throwable $exception)
    {
        self::reportError($exception);
    }

    /**
     * Report an error with custom context
     */
    public static function reportWithContext(\Throwable $exception, $environment = 'prod', $httpStatus = null)
    {
        self::reportError($exception, $environment, $httpStatus);
    }

    /**
     * Report a custom message (not an exception)
     */
    public static function reportMessage($message, $environment = 'prod', $httpStatus = null, Request $request = null, $level = 'error', array $context = [])
    {
        if (self::$instance === null) {
            error_log('ErrorReporter: Service not initialized. Make sure the bundle is properly configured.');
            return;
        }

        self::$instance->reportMessage($message, $environment, $httpStatus, $request, $level, $context);
    }

    /**
     * Add a breadcrumb/event to track user journey
     */
    public static function addBreadcrumb($message, $category = 'custom', $level = 'info', array $data = [])
    {
        BreadcrumbManager::addBreadcrumb($message, $category, $level, $data);
    }

    /**
     * Log a navigation event
     */
    public static function logNavigation($from, $to, array $data = [])
    {
        BreadcrumbManager::logNavigation($from, $to, $data);
    }

    /**
     * Log a user action
     */
    public static function logUserAction($action, array $data = [])
    {
        BreadcrumbManager::logUserAction($action, $data);
    }

    /**
     * Log an HTTP request
     */
    public static function logHttpRequest($method, $url, $statusCode = null, array $data = [])
    {
        BreadcrumbManager::logHttpRequest($method, $url, $statusCode, $data);
    }

    /**
     * Log a database query
     */
    public static function logQuery($query, $duration = null, array $data = [])
    {
        BreadcrumbManager::logQuery($query, $duration, $data);
    }

    /**
     * Clear all breadcrumbs
     */
    public static function clearBreadcrumbs()
    {
        BreadcrumbManager::clearBreadcrumbs();
    }
}