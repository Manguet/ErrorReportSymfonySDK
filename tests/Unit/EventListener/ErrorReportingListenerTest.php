<?php

namespace ErrorExplorer\ErrorReporter\Tests\Unit\EventListener;

use ErrorExplorer\ErrorReporter\EventListener\ErrorReportingListener;
use ErrorExplorer\ErrorReporter\Service\WebhookErrorReporter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

// Test exception classes with specific patterns in names
class TestNotFoundError extends \Exception {}
class TestAccessDeniedError extends \Exception {}
class TestUnauthorizedError extends \Exception {}
class TestBadRequestError extends \Exception {}
class TestMethodNotAllowedError extends \Exception {}

// Exception classes that match the pattern detection
class EntityNotFoundError extends \Exception {}
class UserAccessDeniedError extends \Exception {}
class UnauthorizedUserError extends \Exception {}
class InvalidBadRequestError extends \Exception {}
class RouteMethodNotAllowedError extends \Exception {}

class ErrorReportingListenerTest extends TestCase
{
    private WebhookErrorReporter|MockObject $webhookReporter;
    private ErrorReportingListener $listener;
    private HttpKernelInterface|MockObject $kernel;

    protected function setUp(): void
    {
        $this->webhookReporter = $this->createMock(WebhookErrorReporter::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
        $this->listener = new ErrorReportingListener($this->webhookReporter, 'test');
    }


    public function testOnKernelExceptionWithBasicException(): void
    {
        $exception = new \Exception('Test error');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testOnKernelExceptionWithHttpException(): void
    {
        $exception = new NotFoundHttpException('Page not found');
        $request = Request::create('/missing-page');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                404, // NotFoundHttpException has getStatusCode()
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testDetermineHttpStatusWithNotFound(): void
    {
        $exception = new EntityNotFoundError('Entity not found');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                Response::HTTP_NOT_FOUND,
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testDetermineHttpStatusWithAccessDenied(): void
    {
        $exception = new UserAccessDeniedError('User access denied');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                Response::HTTP_FORBIDDEN,
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testDetermineHttpStatusWithUnauthorized(): void
    {
        $exception = new UnauthorizedUserError('Unauthorized user access');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                Response::HTTP_UNAUTHORIZED,
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testDetermineHttpStatusWithBadRequest(): void
    {
        $exception = new InvalidBadRequestError('Invalid bad request validation failed');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                Response::HTTP_BAD_REQUEST,
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testDetermineHttpStatusWithMethodNotAllowed(): void
    {
        $exception = new RouteMethodNotAllowedError('Route method not allowed for this route');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                Response::HTTP_METHOD_NOT_ALLOWED,
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testDetermineHttpStatusDefault(): void
    {
        $exception = new \RuntimeException('Unknown error type');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'test',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $request
            );

        $this->listener->onKernelException($event);
    }

    public function testEnvironmentIsPassedCorrectly(): void
    {
        $listener = new ErrorReportingListener($this->webhookReporter, 'production');

        $exception = new \Exception('Test error');
        $request = Request::create('/test');

        $event = new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );

        $this->webhookReporter->expects($this->once())
            ->method('reportError')
            ->with(
                $exception,
                'production', // Environment should be passed correctly
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $request
            );

        $listener->onKernelException($event);
    }
}
