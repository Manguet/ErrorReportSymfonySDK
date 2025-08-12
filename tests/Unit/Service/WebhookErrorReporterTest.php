<?php

declare(strict_types=1);

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

use ErrorExplorer\ErrorReporter\Enum\LogLevel;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

#[CoversClass(WebhookErrorReporter::class)]
final class WebhookErrorReporterTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;
    private RequestStack $requestStack;
    private WebhookErrorReporter $reporter;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->reporter = new WebhookErrorReporter(
            webhookUrl: 'https://example.com',
            token: 'test-token',
            projectName: 'test-project',
            enabled: true,
            ignoredExceptions: [],
            timeout: 5,
            maxRetries: 0,
            minimumLevel: LogLevel::ERROR,
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack
        );

        // Clear breadcrumbs before each test
        BreadcrumbManager::clearBreadcrumbs();
    }

    public function testReportErrorWithException(): void
    {
        $exception = new \Exception('Test error', 500);
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/webhook/error/test-token',
                $this->callback(function ($options) {
                    $this->assertArrayHasKey('json', $options);
                    $payload = $options['json'];

                    $this->assertEquals('Test error', $payload['message']);
                    $this->assertEquals('Exception', $payload['exception_class']);
                    $this->assertEquals('test-project', $payload['project']);
                    $this->assertArrayHasKey('fingerprint', $payload);
                    $this->assertArrayHasKey('timestamp', $payload);

                    return true;
                })
            )
            ->willReturn($response);

        $this->reporter->reportError($exception);
    }

    public function testReportErrorWithIgnoredException(): void
    {
        $reporter = new WebhookErrorReporter(
            webhookUrl: 'https://example.com',
            token: 'test-token',
            projectName: 'test-project',
            enabled: true,
            ignoredExceptions: ['Exception'],
            timeout: 5,
            maxRetries: 0,
            minimumLevel: LogLevel::ERROR,
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack
        );

        $exception = new \Exception('Ignored error');

        $this->httpClient->expects($this->never())
            ->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWhenDisabled(): void
    {
        $reporter = new WebhookErrorReporter(
            webhookUrl: 'https://example.com',
            token: 'test-token',
            projectName: 'test-project',
            enabled: false, // disabled
            ignoredExceptions: [],
            timeout: 5,
            maxRetries: 0,
            minimumLevel: LogLevel::ERROR,
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack
        );

        $exception = new \Exception('Test error');

        $this->httpClient->expects($this->never())
            ->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWithRequest(): void
    {
        $request = Request::create('https://example.com/test', 'POST', ['param' => 'value']);
        $request->headers->set('User-Agent', 'Test Agent');

        $exception = new \Exception('Test error');
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/webhook/error/test-token',
                $this->callback(function ($options) {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('request', $payload);
                    $this->assertEquals('https://example.com/test', $payload['request']['url']);
                    $this->assertEquals('POST', $payload['request']['method']);
                    $this->assertEquals('Test Agent', $payload['request']['user_agent']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->reporter->reportError($exception, 'prod', 500, $request);
    }

    public function testReportErrorWithBreadcrumbs(): void
    {
        BreadcrumbManager::addBreadcrumb('Test breadcrumb');
        BreadcrumbManager::logUserAction('Test action');

        $exception = new \Exception('Test error');
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/webhook/error/test-token',
                $this->callback(function ($options) {
                    $payload = $options['json'];

                    $this->assertArrayHasKey('breadcrumbs', $payload);
                    $this->assertCount(2, $payload['breadcrumbs']);
                    $this->assertEquals('Test breadcrumb', $payload['breadcrumbs'][0]['message']);
                    $this->assertEquals('User action: Test action', $payload['breadcrumbs'][1]['message']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->reporter->reportError($exception);
    }

    public function testReportMessage(): void
    {
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/webhook/error/test-token',
                $this->callback(function ($options) {
                    $payload = $options['json'];

                    $this->assertEquals('Custom error message', $payload['message']);
                    $this->assertEquals('CustomMessage', $payload['exception_class']);
                    $this->assertEquals('error', $payload['level']);
                    $this->assertEquals(['key' => 'value'], $payload['context']);
                    $this->assertArrayHasKey('stack_trace', $payload);
                    $this->assertArrayHasKey('fingerprint', $payload);

                    return true;
                })
            )
            ->willReturn($response);

        $this->reporter->reportMessage('Custom error message', 'prod', 500, null, LogLevel::ERROR, ['key' => 'value']);
    }

    public function testReportMessageWhenDisabled(): void
    {
        $reporter = new WebhookErrorReporter(
            webhookUrl: 'https://example.com',
            token: 'test-token',
            projectName: 'test-project',
            enabled: false, // disabled
            ignoredExceptions: [],
            timeout: 5,
            maxRetries: 0,
            minimumLevel: LogLevel::ERROR,
            httpClient: $this->httpClient,
            logger: $this->logger,
            requestStack: $this->requestStack
        );

        $this->httpClient->expects($this->never())
            ->method('request');

        $reporter->reportMessage('Custom error message');
    }

    public function testHttpClientException(): void
    {
        $exception = new \Exception('Test error');
        $httpException = new \Exception('HTTP error');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($httpException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to report error to Error Explorer',
                [
                    'exception' => 'HTTP error',
                    'original_error' => 'Test error',
                    'exception_class' => 'Exception'
                ]
            );

        $this->reporter->reportError($exception);
    }

    public function testHttpClientExceptionWithMessage(): void
    {
        $httpException = new \Exception('HTTP error');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($httpException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to report message to Error Explorer',
                [
                    'exception' => 'HTTP error',
                    'original_message' => 'Custom message'
                ]
            );

        $this->reporter->reportMessage('Custom message');
    }

    public function testSanitizeParameters(): void
    {
        $request = Request::create('https://example.com/test', 'POST', [
            'username' => 'testuser',
            'password' => 'secret123',
            'api_key' => 'key123',
            'normal_param' => 'value'
        ]);

        $exception = new \Exception('Test error');
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/webhook/error/test-token',
                $this->callback(function ($options) {
                    $payload = $options['json'];
                    $params = $payload['request']['parameters'];

                    $this->assertEquals('testuser', $params['username']);
                    $this->assertEquals('[REDACTED]', $params['password']);
                    $this->assertEquals('[REDACTED]', $params['api_key']);
                    $this->assertEquals('value', $params['normal_param']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->reporter->reportError($exception, 'prod', 500, $request);
    }

    public function testSanitizeHeaders(): void
    {
        $request = Request::create('https://example.com/test');
        $request->headers->set('Authorization', 'Bearer secret-token');
        $request->headers->set('Cookie', 'session=abc123');
        $request->headers->set('Content-Type', 'application/json');

        $exception = new \Exception('Test error');
        $response = $this->createMock(ResponseInterface::class);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://example.com/webhook/error/test-token',
                $this->callback(function ($options) {
                    $payload = $options['json'];
                    $headers = $payload['request']['headers'];

                    $this->assertEquals(['[REDACTED]'], $headers['authorization']);
                    $this->assertEquals(['[REDACTED]'], $headers['cookie']);
                    $this->assertEquals(['application/json'], $headers['content-type']);

                    return true;
                })
            )
            ->willReturn($response);

        $this->reporter->reportError($exception, 'prod', 500, $request);
    }
}
