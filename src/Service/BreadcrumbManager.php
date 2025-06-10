<?php

namespace ErrorExplorer\ErrorReporter\Service;

/**
 * Manages breadcrumbs and events for error context
 */
class BreadcrumbManager
{
    /** @var array */
    private static $breadcrumbs = [];
    
    /** @var int */
    private static $maxBreadcrumbs = 50;

    /**
     * Add a breadcrumb/event
     */
    public static function addBreadcrumb($message, $category = 'custom', $level = 'info', array $data = [])
    {
        $breadcrumb = [
            'timestamp' => microtime(true),
            'message' => $message,
            'category' => $category,
            'level' => $level,
            'data' => $data
        ];
        
        self::$breadcrumbs[] = $breadcrumb;
        
        // Keep only the last N breadcrumbs
        if (count(self::$breadcrumbs) > self::$maxBreadcrumbs) {
            array_shift(self::$breadcrumbs);
        }
    }

    /**
     * Get all breadcrumbs
     */
    public static function getBreadcrumbs()
    {
        return self::$breadcrumbs;
    }

    /**
     * Clear all breadcrumbs
     */
    public static function clearBreadcrumbs()
    {
        self::$breadcrumbs = [];
    }

    /**
     * Set maximum number of breadcrumbs to keep
     */
    public static function setMaxBreadcrumbs($max)
    {
        self::$maxBreadcrumbs = $max;
    }

    /**
     * Log a navigation event
     */
    public static function logNavigation($from, $to, array $data = [])
    {
        self::addBreadcrumb("Navigation: {$from} -> {$to}", 'navigation', 'info', $data);
    }

    /**
     * Log a user action
     */
    public static function logUserAction($action, array $data = [])
    {
        self::addBreadcrumb("User action: {$action}", 'user', 'info', $data);
    }

    /**
     * Log an HTTP request
     */
    public static function logHttpRequest($method, $url, $statusCode = null, array $data = [])
    {
        $message = "HTTP {$method} {$url}";
        if ($statusCode) {
            $message .= " [{$statusCode}]";
        }
        
        self::addBreadcrumb($message, 'http', 'info', $data);
    }

    /**
     * Log a database query
     */
    public static function logQuery($query, $duration = null, array $data = [])
    {
        $message = "Query: {$query}";
        if ($duration) {
            $message .= " ({$duration}ms)";
        }
        
        self::addBreadcrumb($message, 'database', 'info', $data);
    }
}