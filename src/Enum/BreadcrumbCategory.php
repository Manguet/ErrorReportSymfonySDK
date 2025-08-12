<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Enum;

enum BreadcrumbCategory: string
{
    case NAVIGATION = 'navigation';
    case USER_ACTION = 'user';
    case HTTP_REQUEST = 'http';
    case DATABASE = 'query';
    case SYSTEM = 'system';
    case CUSTOM = 'custom';
    case PERFORMANCE = 'performance';
    case SECURITY = 'security';
    case BUSINESS_LOGIC = 'business';

    public function getIcon(): string
    {
        return match ($this) {
            self::NAVIGATION => '🧭',
            self::USER_ACTION => '👤',
            self::HTTP_REQUEST => '🌐',
            self::DATABASE => '🗄️',
            self::SYSTEM => '⚙️',
            self::CUSTOM => '🏷️',
            self::PERFORMANCE => '⚡',
            self::SECURITY => '🔒',
            self::BUSINESS_LOGIC => '💼',
        };
    }
}