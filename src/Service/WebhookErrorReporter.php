<?php

namespace ErrorExplorer\ErrorReporter\Service;

use DateTimeInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;

class WebhookErrorReporter
{
    /** @var HttpClientInterface */
    private $httpClient;
    /** @var LoggerInterface|null */
    private $logger;
    /** @var RequestStack|null */
    private $requestStack;
    /** @var string */
    private $webhookUrl;
    /** @var string */
    private $token;
    /** @var string */
    private $projectName;
    /** @var bool */
    private $enabled;
    /** @var array */
    private $ignoredExceptions;

    public function __construct(
        $webhookUrl,
        $token,
        $projectName,
        $enabled = true,
        array $ignoredExceptions = [],
        HttpClientInterface $httpClient = null,
        LoggerInterface $logger = null,
        RequestStack $requestStack = null
    ) {
        $this->webhookUrl = $webhookUrl;
        $this->token = $token;
        $this->projectName = $projectName;
        $this->enabled = $enabled;
        $this->ignoredExceptions = $ignoredExceptions;
        $this->httpClient = $httpClient ?: HttpClient::create();
        $this->logger = $logger;
        $this->requestStack = $requestStack;
    }

    public function reportError(\Throwable $exception, $environment = 'prod', $httpStatus = null, Request $request = null)
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->shouldIgnoreException($exception)) {
            return;
        }

        try {
            $payload = $this->buildPayload($exception, $environment, $httpStatus, $request);
            $this->sendWebhook($payload);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Failed to report error to Error Explorer', [
                    'exception' => $e->getMessage(),
                    'original_error' => $exception->getMessage()
                ]);
            }
        }
    }

    /**
     * Report a custom message (not an exception)
     */
    public function reportMessage($message, $environment = 'prod', $httpStatus = null, Request $request = null, $level = 'error', array $context = [])
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $payload = $this->buildMessagePayload($message, $environment, $httpStatus, $request, $level, $context);
            $this->sendWebhook($payload);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Failed to report message to Error Explorer', [
                    'exception' => $e->getMessage(),
                    'original_message' => $message
                ]);
            }
        }
    }

    private function shouldIgnoreException(\Throwable $exception)
    {
        $exceptionClass = get_class($exception);

        foreach ($this->ignoredExceptions as $ignoredException) {
            if ($exceptionClass === $ignoredException || is_subclass_of($exceptionClass, $ignoredException)) {
                return true;
            }
        }

        return false;
    }

    private function buildPayload(\Throwable $exception, $environment, $httpStatus, Request $request = null)
    {
        $request = $request ?: ($this->requestStack ? $this->requestStack->getCurrentRequest() : null);

        $payload = [
            'message' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'stack_trace' => $exception->getTraceAsString(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'project' => $this->projectName,
            'environment' => $environment,
            'timestamp' => (new \DateTime())->format(DateTimeInterface::ATOM),
            'fingerprint' => $this->generateFingerprint($exception)
        ];

        if ($httpStatus) {
            $payload['http_status'] = $httpStatus;
        }

        if ($request) {
            $payload['request'] = [
                'url' => $request->getUri(),
                'method' => $request->getMethod(),
                'route' => $request->attributes->get('_route'),
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'parameters' => $this->sanitizeParameters($request->request->all()),
                'query' => $this->sanitizeParameters($request->query->all()),
                'headers' => $this->sanitizeHeaders($request->headers->all())
            ];
        }

        $payload['server'] = [
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage()
        ];

        // Add breadcrumbs/events for context
        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();
        if (!empty($breadcrumbs)) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        return $payload;
    }

    private function buildMessagePayload($message, $environment, $httpStatus, Request $request = null, $level = 'error', array $context = [])
    {
        $request = $request ?: ($this->requestStack ? $this->requestStack->getCurrentRequest() : null);
        
        $payload = [
            'message' => $message,
            'exception_class' => 'CustomMessage',
            'stack_trace' => $this->generateStackTrace(),
            'file' => null,
            'line' => null,
            'project' => $this->projectName,
            'environment' => $environment,
            'timestamp' => (new \DateTime())->format(DateTimeInterface::ATOM),
            'fingerprint' => $this->generateMessageFingerprint($message, $level),
            'level' => $level,
            'context' => $context
        ];

        if ($httpStatus) {
            $payload['http_status'] = $httpStatus;
        }

        if ($request) {
            $payload['request'] = [
                'url' => $request->getUri(),
                'method' => $request->getMethod(),
                'route' => $request->attributes->get('_route'),
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'parameters' => $this->sanitizeParameters($request->request->all()),
                'query' => $this->sanitizeParameters($request->query->all()),
                'headers' => $this->sanitizeHeaders($request->headers->all())
            ];
        }

        $payload['server'] = [
            'php_version' => PHP_VERSION,
            'memory_usage' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage()
        ];

        // Add breadcrumbs/events for context
        $breadcrumbs = BreadcrumbManager::getBreadcrumbs();
        if (!empty($breadcrumbs)) {
            $payload['breadcrumbs'] = $breadcrumbs;
        }

        return $payload;
    }

    private function generateStackTrace()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        
        // Remove the first few internal calls
        $trace = array_slice($trace, 3);
        
        $stackTrace = '';
        foreach ($trace as $i => $call) {
            $file = isset($call['file']) ? $call['file'] : '[internal]';
            $line = isset($call['line']) ? $call['line'] : 0;
            $function = isset($call['function']) ? $call['function'] : '';
            $class = isset($call['class']) ? $call['class'] : '';
            
            if ($class) {
                $function = $class . '::' . $function;
            }
            
            $stackTrace .= "#{$i} {$file}({$line}): {$function}()\n";
        }
        
        return $stackTrace;
    }

    private function generateMessageFingerprint($message, $level)
    {
        $fingerprint = sprintf(
            'CustomMessage:%s:%s',
            $level,
            md5($message)
        );
        
        return md5($fingerprint);
    }

    private function generateFingerprint(\Throwable $exception)
    {
        $fingerprint = sprintf(
            '%s:%s:%d',
            get_class($exception),
            $exception->getFile(),
            $exception->getLine()
        );

        return md5($fingerprint);
    }

    private function sanitizeParameters(array $parameters)
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'api_key', 'authorization'];

        foreach ($parameters as $key => $value) {
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (stripos($key, $sensitiveKey) !== false) {
                    $parameters[$key] = '[REDACTED]';
                    break;
                }
            }
        }

        return $parameters;
    }

    private function sanitizeHeaders(array $headers)
    {
        $sensitiveHeaders = ['authorization', 'cookie', 'x-api-key', 'x-auth-token'];

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitiveHeaders)) {
                $headers[$key] = is_array($value) ? ['[REDACTED]'] : '[REDACTED]';
            }
        }

        return $headers;
    }

    private function sendWebhook(array $payload)
    {
        $url = rtrim($this->webhookUrl, '/') . '/webhook/error/' . $this->token;

        $this->httpClient->request('POST', $url, [
            'json' => $payload,
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'ErrorExplorer-SDK/1.0'
            ]
        ]);
    }
}
