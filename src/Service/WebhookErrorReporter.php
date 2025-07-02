<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use DateTimeInterface;
use DateTimeImmutable;
use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\ValueObject\ErrorFingerprint;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class WebhookErrorReporter
{
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'key', 'api_key', 'authorization'];
    private const SENSITIVE_HEADERS = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];

    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $token,
        private readonly string $projectName,
        private readonly bool $enabled = true,
        private readonly array $ignoredExceptions = [],
        private readonly int $timeout = 5,
        private readonly int $maxRetries = 3,
        private readonly LogLevel $minimumLevel = LogLevel::ERROR,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?RequestStack $requestStack = null,
    )
    {
    }

    private function getHttpClient(): HttpClientInterface
    {
        return $this->httpClient ?? HttpClient::create();
    }

    public function reportError(
        Throwable $exception,
        string    $environment = 'prod',
        ?int      $httpStatus = null,
        ?Request  $request = null
    ): void
    {
        if (!$this->enabled || $this->shouldIgnoreException($exception)) {
            return;
        }

        try {
            $payload = $this->buildPayload($exception, $environment, $httpStatus, $request);
            $this->sendWebhook($payload);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to report error to Error Explorer', [
                'exception' => $e->getMessage(),
                'original_error' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function reportMessage(
        string          $message,
        string          $environment = 'prod',
        ?int            $httpStatus = null,
        ?Request        $request = null,
        LogLevel|string $level = LogLevel::ERROR,
        array           $context = []
    ): void
    {
        if (!$this->enabled) {
            return;
        }

        $logLevel = $level instanceof LogLevel ? $level : LogLevel::from($level);

        if ($logLevel->getPriority() < $this->minimumLevel->getPriority()) {
            return;
        }

        try {
            $payload = $this->buildMessagePayload($message, $environment, $httpStatus, $request, $logLevel, $context);
            $this->sendWebhook($payload);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to report message to Error Explorer', [
                'exception' => $e->getMessage(),
                'original_message' => $message,
            ]);
        }
    }

    private function shouldIgnoreException(Throwable $exception): bool
    {
        $exceptionClass = $exception::class;

        return array_reduce(
            $this->ignoredExceptions,
            fn(bool $carry, string $ignoredException): bool => $carry || $exceptionClass === $ignoredException || is_subclass_of($exceptionClass, $ignoredException),
            false
        );
    }

    private function buildPayload(
        Throwable $exception,
        string    $environment,
        ?int      $httpStatus,
        ?Request  $request = null
    ): array
    {
        $request ??= $this->requestStack?->getCurrentRequest();
        $fingerprint = ErrorFingerprint::fromException($exception);

        $payload = [
            'message' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'stack_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'project' => $this->projectName,
            'environment' => $environment,
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'fingerprint' => $fingerprint->toString(),
            'level' => LogLevel::ERROR->value,
        ];

        if ($httpStatus !== null) {
            $payload['http_status'] = $httpStatus;
        }

        if ($request !== null) {
            $payload['request'] = $this->buildRequestContext($request);
        }

        $payload['server'] = $this->buildServerContext();

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();
        if ($breadcrumbs !== []) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        return $payload;
    }

    private function buildMessagePayload(
        string   $message,
        string   $environment,
        ?int     $httpStatus,
        ?Request $request,
        LogLevel $level,
        array    $context
    ): array
    {
        $request ??= $this->requestStack?->getCurrentRequest();
        $fingerprint = ErrorFingerprint::fromMessage($message, $level->value);

        $payload = [
            'message' => $message,
            'exception_class' => 'CustomMessage',
            'stack_trace' => $this->generateStackTrace(),
            'file' => null,
            'line' => null,
            'project' => $this->projectName,
            'environment' => $environment,
            'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'fingerprint' => $fingerprint->toString(),
            'level' => $level->value,
            'context' => $context,
        ];

        if ($httpStatus !== null) {
            $payload['http_status'] = $httpStatus;
        }

        if ($request !== null) {
            $payload['request'] = $this->buildRequestContext($request);
        }

        $payload['server'] = $this->buildServerContext();

        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();
        if ($breadcrumbs !== []) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        return $payload;
    }

    private function buildRequestContext(Request $request): array
    {
        return [
            'url' => $request->getUri(),
            'method' => $request->getMethod(),
            'route' => $request->attributes->get('_route'),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'parameters' => $this->sanitizeParameters($request->request->all()),
            'query' => $this->sanitizeParameters($request->query->all()),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
        ];
    }

    private function buildServerContext(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage(),
            'server_time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    private function generateStackTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $trace = array_slice($trace, 3);

        return implode("\n", array_map(
            static fn(int $i, array $call): string => sprintf(
                '#%d %s(%d): %s()',
                $i,
                $call['file'] ?? '[internal]',
                $call['line'] ?? 0,
                isset($call['class']) ? $call['class'] . '::' . ($call['function'] ?? '') : ($call['function'] ?? '')
            ),
            array_keys($trace),
            $trace
        ));
    }

    private function sanitizeParameters(array $parameters): array
    {
        $sanitized = [];
        foreach ($parameters as $key => $value) {
            $sanitized[$key] = $this->isSensitiveKey($key) ? '[REDACTED]' : $value;
        }
        return $sanitized;
    }

    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), self::SENSITIVE_HEADERS, true)) {
                $sanitized[$key] = is_array($value) ? ['[REDACTED]'] : '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        return array_reduce(
            self::SENSITIVE_KEYS,
            static fn(bool $carry, string $sensitiveKey): bool => $carry || str_contains(strtolower($key), $sensitiveKey),
            false
        );
    }

    private function sendWebhook(array $payload): void
    {
        $url = rtrim($this->webhookUrl, '/') . '/webhook/error/' . $this->token;
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $this->getHttpClient()->request('POST', $url, [
                    'json' => $payload,
                    'timeout' => $this->timeout,
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'User-Agent' => 'ErrorExplorer-SDK/2.0-modern',
                        'X-Attempt' => (string)($attempt + 1),
                        'X-Max-Attempts' => (string)($this->maxRetries + 1),
                    ],
                ]);

                return;

            } catch (Throwable $e) {
                $lastException = $e;

                if ($attempt < $this->maxRetries) {
                    usleep(100_000 * ($attempt + 1));
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Webhook failed without exception');
    }
}
