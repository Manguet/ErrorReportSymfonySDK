<?php

namespace ErrorExplorer\ErrorReporter\Tests\Unit\Service;

use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use ErrorExplorer\ErrorReporter\Service\BreadcrumbManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Psr\Log\LoggerInterface;

class WebhookErrorReporterTest extends TestCase
{
    /** @var HttpClientInterface */
    private $httpClient;

    /** @var LoggerInterface */
    private $logger;

    /** @var RequestStack */
    private $requestStack;

    /** @var WebhookErrorReporter */
    private $reporter;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);

        $this->reporter = new WebhookErrorReporter(
            'https://example.com',
            'test-token',
            'test-project',
            true,
            [],
            $this->httpClient,
            $this->logger,
            $this->requestStack
        );

        // Clear breadcrumbs before each test
        BreadcrumbManager::clearBreadcrumbs();
    }

    public function testReportErrorWithException()
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

    public function testReportErrorWithIgnoredException()
    {
        $reporter = new WebhookErrorReporter(
            'https://example.com',
            'test-token',
            'test-project',
            true,
            ['Exception'],
            $this->httpClient,
            $this->logger,
            $this->requestStack
        );

        $exception = new \Exception('Ignored error');

        $this->httpClient->expects($this->never())
            ->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWhenDisabled()
    {
        $reporter = new WebhookErrorReporter(
            'https://example.com',
            'test-token',
            'test-project',
            false, // disabled
            [],
            $this->httpClient,
            $this->logger,
            $this->requestStack
        );

        $exception = new \Exception('Test error');

        $this->httpClient->expects($this->never())
            ->method('request');

        $reporter->reportError($exception);
    }

    public function testReportErrorWithRequest()
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

    public function testReportErrorWithBreadcrumbs()
    {
        BreadcrumbManager::addBreadcrumb('Test breadcrumb', 'test', 'info');
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

    public function testReportMessage()
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

        $this->reporter->reportMessage('Custom error message', 'prod', 500, null, 'error', ['key' => 'value']);
    }

    public function testReportMessageWhenDisabled()
    {
        $reporter = new WebhookErrorReporter(
            'https://example.com',
            'test-token',
            'test-project',
            false, // disabled
            [],
            $this->httpClient,
            $this->logger,
            $this->requestStack
        );

        $this->httpClient->expects($this->never())
            ->method('request');

        $reporter->reportMessage('Custom error message');
    }

    public function testHttpClientException()
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
                    'original_error' => 'Test error'
                ]
            );

        $this->reporter->reportError($exception);
    }

    public function testHttpClientExceptionWithMessage()
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

    public function testSanitizeParameters()
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

    public function testSanitizeHeaders()
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
