<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter;

use ErrorExplorer\ErrorReporter\Enum\BreadcrumbCategory;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

final class ErrorReporter
{
    private static ?WebhookErrorReporter $instance = null;

    public static function setInstance(WebhookErrorReporter $instance): void
    {
        self::$instance = $instance;
    }

    public static function reportError(
        Throwable $exception,
        string $environment = 'prod',
        ?int $httpStatus = null,
        ?Request $request = null
    ): void {
        if (self::$instance === null) {
            error_log('ErrorReporter: Service not initialized. Make sure the bundle is properly configured.');
            return;
        }

        self::$instance->reportError($exception, $environment, $httpStatus, $request);
    }

    public static function isConfigured(): bool
    {
        return self::$instance !== null;
    }

    public static function report(Throwable $exception): void
    {
        self::reportError($exception);
    }

    public static function reportWithContext(
        Throwable $exception,
        string $environment = 'prod',
        ?int $httpStatus = null
    ): void {
        self::reportError($exception, $environment, $httpStatus);
    }

    public static function reportMessage(
        string $message,
        string $environment = 'prod',
        ?int $httpStatus = null,
        ?Request $request = null,
        LogLevel|string $level = LogLevel::ERROR,
        array $context = []
    ): void {
        if (self::$instance === null) {
            error_log('ErrorReporter: Service not initialized. Make sure the bundle is properly configured.');
            return;
        }

        self::$instance->reportMessage($message, $environment, $httpStatus, $request, $level, $context);
    }

    public static function addBreadcrumb(
        string $message,
        BreadcrumbCategory|string $category = BreadcrumbCategory::CUSTOM,
        LogLevel|string $level = LogLevel::INFO,
        array $data = []
    ): void {
        BreadcrumbManager::addBreadcrumb($message, $category, $level, $data);
    }

    public static function logNavigation(string $from, string $to, array $data = []): void
    {
        BreadcrumbManager::logNavigation($from, $to, $data);
    }

    public static function logUserAction(string $action, array $data = []): void
    {
        BreadcrumbManager::logUserAction($action, $data);
    }

    public static function logHttpRequest(
        string $method,
        string $url,
        ?int $statusCode = null,
        array $data = []
    ): void {
        BreadcrumbManager::logHttpRequest($method, $url, $statusCode, $data);
    }

    public static function logQuery(string $query, ?float $duration = null, array $data = []): void
    {
        BreadcrumbManager::logQuery($query, $duration, $data);
    }

    public static function logPerformance(string $metric, float $value, string $unit = 'ms'): void
    {
        BreadcrumbManager::logPerformance($metric, $value, $unit);
    }

    public static function logSecurity(string $event, LogLevel $level = LogLevel::WARNING, array $data = []): void
    {
        BreadcrumbManager::logSecurity($event, $level, $data);
    }

    public static function clearBreadcrumbs(): void
    {
        BreadcrumbManager::clearBreadcrumbs();
    }

    public static function setMaxBreadcrumbs(int $max): void
    {
        BreadcrumbManager::setMaxBreadcrumbs($max);
    }

    public static function getMaxBreadcrumbs(): int
    {
        return BreadcrumbManager::getMaxBreadcrumbs();
    }

    public static function getBreadcrumbCount(): int
    {
        return BreadcrumbManager::getBreadcrumbCount();
    }

    public static function getBreadcrumbs(): array
    {
        return BreadcrumbManager::getBreadcrumbs();
    }

    // Convenience methods for different error levels
    public static function reportDebug(string $message, array $context = []): void
    {
        self::reportMessage($message, level: LogLevel::DEBUG, context: $context);
    }

    public static function reportInfo(string $message, array $context = []): void
    {
        self::reportMessage($message, level: LogLevel::INFO, context: $context);
    }

    public static function reportWarning(string $message, array $context = []): void
    {
        self::reportMessage($message, level: LogLevel::WARNING, context: $context);
    }

    public static function reportCritical(string $message, array $context = []): void
    {
        self::reportMessage($message, level: LogLevel::CRITICAL, context: $context);
    }

    public static function reportAlert(string $message, array $context = []): void
    {
        self::reportMessage($message, level: LogLevel::ALERT, context: $context);
    }

    public static function reportEmergency(string $message, array $context = []): void
    {
        self::reportMessage($message, level: LogLevel::EMERGENCY, context: $context);
    }
}
