<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validates and sanitizes error data for security
 */
readonly class SecurityValidator
{
    private array $defaultSensitivePatterns;

    public function __construct(
        private LoggerInterface $logger,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private ?Security $security = null,
        private ?ValidatorInterface $validator = null,
        private array $config = []
    ) {
        $this->defaultSensitivePatterns = [
            // Credit card numbers
            '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
            // Social Security Numbers
            '/\b\d{3}-\d{2}-\d{4}\b/',
            // Email addresses (in some contexts might be sensitive)
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            // Phone numbers
            '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
            // IP addresses
            '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/',
            // JWT tokens
            '/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]*\b/',
            // API keys (common patterns)
            '/\b[Aa]pi[_-]?[Kk]ey[:\s]*[A-Za-z0-9_-]{20,}\b/',
            // Passwords (in URLs or JSON)
            '/["\']?password["\']?\s*[:\s=]\s*["\'][^"\']*["\']?/i',
            // Access tokens
            '/\b[Aa]ccess[_-]?[Tt]oken[:\s]*[A-Za-z0-9_-]{20,}\b/'
        ];

        $this->config = array_merge([
            'requireHttps' => true,
            'validateToken' => true,
            'maxPayloadSize' => 1024 * 1024, // 1MB
            'allowedDomains' => [],
            'sensitiveDataPatterns' => $this->defaultSensitivePatterns
        ], $config);
    }

    /**
     * Validate a webhook URL
     */
    public function validateWebhookUrl(string $url): bool
    {
        try {
            // Basic URL validation
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->warning('Invalid webhook URL format', ['url' => $url]);
                return false;
            }
            
            $parsedUrl = parse_url($url);
            
            // Must have a host
            if (!isset($parsedUrl['host'])) {
                $this->logger->warning('Webhook URL missing host', ['url' => $url]);
                return false;
            }
            
            // Require HTTPS if configured
            if ($this->config['requireHttps'] && ($parsedUrl['scheme'] ?? '') !== 'https') {
                $this->logger->warning('HTTPS required for webhook URL', ['url' => $url]);
                return false;
            }
            
            // Check allowed domains if configured
            if (!empty($this->config['allowedDomains'])) {
                $allowed = false;
                foreach ($this->config['allowedDomains'] as $domain) {
                    if (str_contains($parsedUrl['host'], $domain)) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    $this->logger->warning('Domain not in allowed list', [
                        'url' => $url,
                        'host' => $parsedUrl['host'],
                        'allowedDomains' => $this->config['allowedDomains']
                    ]);
                    return false;
                }
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error validating webhook URL', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Validate configuration settings
     */
    public function validateConfiguration(array $config): array
    {
        $errors = [];
        $warnings = [];

        try {
            // Validate webhook URL
            if (empty($config['webhookUrl'])) {
                $errors[] = 'Webhook URL is required';
            } else {
                $parsedUrl = parse_url($config['webhookUrl']);
                
                if (!$parsedUrl || empty($parsedUrl['host'])) {
                    $errors[] = 'Invalid webhook URL format';
                } else {
                    if ($this->config['requireHttps'] && ($parsedUrl['scheme'] !== 'https')) {
                        $errors[] = 'HTTPS is required for webhook URL in production';
                    }

                    if (!empty($this->config['allowedDomains']) && 
                        !in_array($parsedUrl['host'], $this->config['allowedDomains'])) {
                        $errors[] = sprintf('Domain %s is not in allowed domains list', $parsedUrl['host']);
                    }
                }
            }

            // Validate project name
            if (empty($config['projectName']) || empty(trim($config['projectName']))) {
                $errors[] = 'Project name is required';
            }

            // Validate environment
            if (!empty($config['environment']) && 
                !in_array($config['environment'], ['development', 'staging', 'production'])) {
                $warnings[] = 'Environment should be one of: development, staging, production';
            }

            // Validate retry configuration
            if (isset($config['retries']) && ($config['retries'] < 0 || $config['retries'] > 10)) {
                $warnings[] = 'Retry count should be between 0 and 10';
            }

            if (isset($config['timeout']) && ($config['timeout'] < 1000 || $config['timeout'] > 30000)) {
                $warnings[] = 'Timeout should be between 1000ms and 30000ms';
            }

            // Use Symfony Validator if available
            if ($this->validator) {
                $constraints = new Assert\Collection([
                    'webhookUrl' => [new Assert\Url(), new Assert\NotBlank()],
                    'projectName' => [new Assert\NotBlank(), new Assert\Length(min: 1, max: 255)],
                    'environment' => new Assert\Choice(['development', 'staging', 'production'])
                ]);

                $violations = $this->validator->validate($config, $constraints);
                
                foreach ($violations as $violation) {
                    $errors[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings
            ];
        } catch (\Exception $e) {
            $this->logger->error('Configuration validation failed', [
                'error' => $e->getMessage(),
                'config' => $config
            ]);
            
            return [
                'valid' => false,
                'errors' => ['Configuration validation failed: ' . $e->getMessage()],
                'warnings' => $warnings
            ];
        }
    }

    /**
     * Validate error payload
     */
    public function validatePayload(array $errorData): array
    {
        $errors = [];
        $warnings = [];

        try {
            // Check payload size
            $payloadSize = $this->calculatePayloadSize($errorData);
            if ($payloadSize > $this->config['maxPayloadSize']) {
                $errors[] = sprintf(
                    'Payload size (%d bytes) exceeds maximum allowed size (%d bytes)',
                    $payloadSize,
                    $this->config['maxPayloadSize']
                );
            }

            // Check for sensitive data
            $sensitiveDataFound = $this->detectSensitiveData($errorData);
            if (!empty($sensitiveDataFound)) {
                $warnings[] = sprintf('Potential sensitive data detected: %s', implode(', ', $sensitiveDataFound));
            }

            // Validate required fields
            if (empty($errorData['message'])) {
                $errors[] = 'Error message is required';
            }

            if (empty($errorData['project'])) {
                $errors[] = 'Project name is required';
            }

            if (empty($errorData['timestamp'])) {
                $errors[] = 'Timestamp is required';
            }

            // Validate data types
            if (isset($errorData['line']) && !is_numeric($errorData['line'])) {
                $errors[] = 'Line number must be numeric';
            }

            if (isset($errorData['breadcrumbs']) && !is_array($errorData['breadcrumbs'])) {
                $errors[] = 'Breadcrumbs must be an array';
            }

            // Use Symfony Validator if available
            if ($this->validator) {
                $constraints = new Assert\Collection([
                    'message' => [new Assert\NotBlank(), new Assert\Type('string')],
                    'project' => [new Assert\NotBlank(), new Assert\Type('string')],
                    'timestamp' => [new Assert\NotBlank()],
                    'line' => new Assert\Optional(new Assert\Type('numeric')),
                    'breadcrumbs' => new Assert\Optional(new Assert\Type('array'))
                ]);

                $violations = $this->validator->validate($errorData, $constraints);
                
                foreach ($violations as $violation) {
                    $errors[] = sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings
            ];
        } catch (\Exception $e) {
            $this->logger->error('Payload validation failed', [
                'error' => $e->getMessage(),
                'errorData' => $errorData
            ]);
            
            return [
                'valid' => false,
                'errors' => ['Payload validation failed: ' . $e->getMessage()],
                'warnings' => $warnings
            ];
        }
    }

    /**
     * Sanitize error data by removing/masking sensitive information
     */
    public function sanitizeErrorData(array $errorData): array
    {
        try {
            $sanitized = $errorData;

            // Sanitize message
            if (!empty($sanitized['message'])) {
                $sanitized['message'] = $this->sanitizeText($sanitized['message']);
            }

            // Sanitize stack trace
            if (!empty($sanitized['stack_trace'])) {
                $sanitized['stack_trace'] = $this->sanitizeText($sanitized['stack_trace']);
            }

            // Sanitize context data
            if (!empty($sanitized['context'])) {
                $sanitized['context'] = $this->sanitizeObject($sanitized['context']);
            }

            // Sanitize breadcrumbs
            if (!empty($sanitized['breadcrumbs']) && is_array($sanitized['breadcrumbs'])) {
                $sanitized['breadcrumbs'] = array_map(function($breadcrumb) {
                    if (!empty($breadcrumb['message'])) {
                        $breadcrumb['message'] = $this->sanitizeText($breadcrumb['message']);
                    }
                    if (!empty($breadcrumb['data'])) {
                        $breadcrumb['data'] = $this->sanitizeObject($breadcrumb['data']);
                    }
                    return $breadcrumb;
                }, $sanitized['breadcrumbs']);
            }

            // Sanitize user data
            if (!empty($sanitized['user'])) {
                $sanitized['user'] = $this->sanitizeObject($sanitized['user']);
            }

            // Sanitize additional data fields
            if (!empty($sanitized['additional_data'])) {
                $sanitized['additional_data'] = $this->sanitizeObject($sanitized['additional_data']);
            }

            return $sanitized;
        } catch (\Exception $e) {
            $this->logger->error('Error data sanitization failed', [
                'error' => $e->getMessage(),
                'errorData' => $errorData
            ]);
            
            // Return original data if sanitization fails
            return $errorData;
        }
    }

    /**
     * Calculate payload size in bytes
     */
    private function calculatePayloadSize(array $data): int
    {
        return strlen(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Detect sensitive data in error data
     */
    private function detectSensitiveData(array $errorData): array
    {
        $sensitiveDataTypes = [];
        $textToCheck = implode(' ', [
            $errorData['message'] ?? '',
            $errorData['stack_trace'] ?? '',
            json_encode($errorData['context'] ?? []),
            json_encode($errorData['user'] ?? []),
            json_encode($errorData['breadcrumbs'] ?? []),
            json_encode($errorData['additional_data'] ?? [])
        ]);

        foreach ($this->config['sensitiveDataPatterns'] as $pattern) {
            if (preg_match($pattern, $textToCheck)) {
                $sensitiveDataTypes[] = match (true) {
                    str_contains($pattern, '\d{4}[-\s]?\d{4}') => 'Credit Card',
                    str_contains($pattern, '\d{3}-\d{2}-\d{4}') => 'SSN',
                    str_contains($pattern, '@') => 'Email',
                    str_contains($pattern, 'eyJ') => 'JWT Token',
                    str_contains($pattern, '[Aa]pi') => 'API Key',
                    str_contains($pattern, 'password') => 'Password',
                    default => 'PII'
                };
            }
        }

        return array_unique($sensitiveDataTypes);
    }

    /**
     * Validate error data (alias for validatePayload)
     */
    public function validateErrorData(array $errorData): bool
    {
        $result = $this->validatePayload($errorData);
        return $result['valid'];
    }
    
    /**
     * Sanitize sensitive data (alias for sanitizeErrorData)
     */
    public function sanitizeSensitiveData(array $errorData): array
    {
        return $this->sanitizeErrorData($errorData);
    }

    /**
     * Sanitize text by removing sensitive patterns
     */
    private function sanitizeText(string $text): string
    {
        $sanitized = $text;
        
        foreach ($this->config['sensitiveDataPatterns'] as $pattern) {
            $sanitized = preg_replace($pattern, '[REDACTED]', $sanitized);
        }
        
        return $sanitized;
    }

    /**
     * Sanitize object by removing sensitive keys and values
     */
    private function sanitizeObject(mixed $obj): mixed
    {
        if (!is_array($obj) && !is_object($obj)) {
            if (is_string($obj)) {
                return $this->sanitizeText($obj);
            }
            return $obj;
        }

        if (is_object($obj)) {
            $obj = (array) $obj;
        }

        if (is_array($obj)) {
            $sanitized = [];
            
            foreach ($obj as $key => $value) {
                // Check if key might contain sensitive data
                $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth', 'credential', 'api_key', 'access_token'];
                $isSensitiveKey = false;
                
                foreach ($sensitiveKeys as $sensitiveKey) {
                    if (stripos((string) $key, $sensitiveKey) !== false) {
                        $isSensitiveKey = true;
                        break;
                    }
                }
                
                if ($isSensitiveKey) {
                    $sanitized[$key] = '[REDACTED]';
                } elseif (is_string($value)) {
                    $sanitized[$key] = $this->sanitizeText($value);
                } elseif (is_array($value) || is_object($value)) {
                    $sanitized[$key] = $this->sanitizeObject($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }
            
            return $sanitized;
        }

        return $obj;
    }

    /**
     * Add a custom sensitive data pattern
     */
    public function addSensitivePattern(string $pattern): void
    {
        $this->config['sensitiveDataPatterns'][] = $pattern;
        $this->logger->info('Sensitive data pattern added', ['pattern' => $pattern]);
    }

    /**
     * Remove a sensitive data pattern
     */
    public function removeSensitivePattern(string $pattern): void
    {
        $key = array_search($pattern, $this->config['sensitiveDataPatterns']);
        if ($key !== false) {
            unset($this->config['sensitiveDataPatterns'][$key]);
            // Re-index array
            $this->config['sensitiveDataPatterns'] = array_values($this->config['sensitiveDataPatterns']);
            
            $this->logger->info('Sensitive data pattern removed', ['pattern' => $pattern]);
        }
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $updates): void
    {
        $this->config = array_merge($this->config, $updates);
        $this->logger->info('Security validator configuration updated', ['config' => $this->config]);
    }

    /**
     * Get current configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Validate CSRF token for security
     */
    public function validateCsrfToken(string $token, string $intention = 'error_explorer_action'): bool
    {
        if (!$this->csrfTokenManager) {
            $this->logger->warning('CSRF token manager not available');
            return true; // Don't fail if CSRF manager is not available
        }

        try {
            $csrfToken = new CsrfToken($intention, $token);
            $isValid = $this->csrfTokenManager->isTokenValid($csrfToken);
            
            if (!$isValid) {
                $this->logger->warning('Invalid CSRF token', [
                    'intention' => $intention,
                    'token' => substr($token, 0, 8) . '...'
                ]);
            }
            
            return $isValid;
        } catch (\Exception $e) {
            $this->logger->error('CSRF token validation failed', [
                'error' => $e->getMessage(),
                'intention' => $intention
            ]);
            return false;
        }
    }

    /**
     * Check if current user has required roles
     */
    public function validateUserRoles(array $requiredRoles = ['ROLE_USER']): bool
    {
        if (!$this->security) {
            $this->logger->warning('Security component not available');
            return true; // Don't fail if security is not available
        }

        try {
            $user = $this->security->getUser();
            
            if (!$user) {
                $this->logger->warning('No authenticated user found');
                return false;
            }

            foreach ($requiredRoles as $role) {
                if ($this->security->isGranted($role)) {
                    return true;
                }
            }
            
            $this->logger->warning('User does not have required roles', [
                'requiredRoles' => $requiredRoles,
                'userRoles' => $user->getRoles()
            ]);
            
            return false;
        } catch (\Exception $e) {
            $this->logger->error('User role validation failed', [
                'error' => $e->getMessage(),
                'requiredRoles' => $requiredRoles
            ]);
            return false;
        }
    }
}