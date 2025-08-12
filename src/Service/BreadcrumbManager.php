<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use DateTimeImmutable;
use DateTimeInterface;
use ErrorExplorer\ErrorReporter\Enum\BreadcrumbCategory;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;

final class BreadcrumbManager
{
    private static array $breadcrumbs = [];
    private static int $maxBreadcrumbs = 50;

    public static function addBreadcrumb(
        string $message,
        BreadcrumbCategory|string $category = BreadcrumbCategory::CUSTOM,
        LogLevel|string $level = LogLevel::INFO,
        array $data = []
    ): void {
        $breadcrumbCategory = $category instanceof BreadcrumbCategory ? $category : BreadcrumbCategory::from($category);
        $logLevel = $level instanceof LogLevel ? $level : LogLevel::from($level);

        $breadcrumb = [
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'message' => $message,
            'category' => $breadcrumbCategory->value,
            'level' => $logLevel->value,
            'data' => $data,
            'icon' => $breadcrumbCategory->getIcon(),
        ];
        
        self::$breadcrumbs[] = $breadcrumb;
        
        if (count(self::$breadcrumbs) > self::$maxBreadcrumbs) {
            array_shift(self::$breadcrumbs);
        }
    }

    public static function getBreadcrumbs(): array
    {
        return self::$breadcrumbs;
    }

    public static function clearBreadcrumbs(): void
    {
        self::$breadcrumbs = [];
    }

    public static function setMaxBreadcrumbs(int $max): void
    {
        if ($max < 10 || $max > 100) {
            throw new \InvalidArgumentException('Max breadcrumbs must be between 10 and 100');
        }
        
        self::$maxBreadcrumbs = $max;
    }

    public static function getMaxBreadcrumbs(): int
    {
        return self::$maxBreadcrumbs;
    }

    public static function getBreadcrumbCount(): int
    {
        return count(self::$breadcrumbs);
    }

    public static function logNavigation(string $from, string $to, array $data = []): void
    {
        self::addBreadcrumb(
            message: "Navigation: {$from} → {$to}",
            category: BreadcrumbCategory::NAVIGATION,
            level: LogLevel::INFO,
            data: array_merge($data, ['from' => $from, 'to' => $to])
        );
    }

    public static function logUserAction(string $action, array $data = []): void
    {
        self::addBreadcrumb(
            message: "User action: {$action}",
            category: BreadcrumbCategory::USER_ACTION,
            level: LogLevel::INFO,
            data: array_merge($data, ['action' => $action])
        );
    }

    public static function logHttpRequest(
        string $method,
        string $url,
        ?int $statusCode = null,
        array $data = []
    ): void {
        $level = match (true) {
            $statusCode === null => LogLevel::INFO,
            $statusCode >= 400 && $statusCode < 500 => LogLevel::WARNING,
            $statusCode >= 500 => LogLevel::ERROR,
            default => LogLevel::INFO,
        };

        $message = "HTTP {$method} {$url}";
        if ($statusCode !== null) {
            $message .= " [{$statusCode}]";
        }
        
        self::addBreadcrumb(
            message: $message,
            category: BreadcrumbCategory::HTTP_REQUEST,
            level: $level,
            data: array_merge($data, [
                'method' => $method,
                'url' => $url,
                'status_code' => $statusCode,
            ])
        );
    }

    public static function logQuery(
        string $query,
        ?float $duration = null,
        array $data = []
    ): void {
        $level = match (true) {
            $duration === null => LogLevel::INFO,
            $duration > 1000 => LogLevel::WARNING, // > 1 second
            $duration > 5000 => LogLevel::ERROR,   // > 5 seconds
            default => LogLevel::INFO,
        };

        $message = "Query: " . (strlen($query) > 100 ? substr($query, 0, 100) . '...' : $query);
        if ($duration !== null) {
            $message .= " ({$duration}ms)";
        }
        
        self::addBreadcrumb(
            message: $message,
            category: BreadcrumbCategory::DATABASE,
            level: $level,
            data: array_merge($data, [
                'query' => $query,
                'duration_ms' => $duration,
            ])
        );
    }

    public static function logPerformance(string $metric, float $value, string $unit = 'ms'): void
    {
        $level = match ($unit) {
            'ms' => $value > 1000 ? LogLevel::WARNING : LogLevel::INFO,
            'mb' => $value > 100 ? LogLevel::WARNING : LogLevel::INFO,
            default => LogLevel::INFO,
        };

        self::addBreadcrumb(
            message: "Performance: {$metric} = {$value}{$unit}",
            category: BreadcrumbCategory::PERFORMANCE,
            level: $level,
            data: [
                'metric' => $metric,
                'value' => $value,
                'unit' => $unit,
            ]
        );
    }

    public static function logSecurity(string $event, LogLevel $level = LogLevel::WARNING, array $data = []): void
    {
        self::addBreadcrumb(
            message: "Security: {$event}",
            category: BreadcrumbCategory::SECURITY,
            level: $level,
            data: array_merge($data, ['event' => $event])
        );
    }
}