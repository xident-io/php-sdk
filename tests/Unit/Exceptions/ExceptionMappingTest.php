<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Xident\SDK\Exceptions\AuthenticationException;
use Xident\SDK\Exceptions\NetworkException;
use Xident\SDK\Exceptions\NotFoundException;
use Xident\SDK\Exceptions\RateLimitException;
use Xident\SDK\Exceptions\ServerException;
use Xident\SDK\Exceptions\ValidationException;
use Xident\SDK\Exceptions\XidentException;

final class ExceptionMappingTest extends TestCase
{
    public function testBaseExceptionCarriesErrorCode(): void
    {
        $e = new XidentException('test message', 'TEST_CODE', 'req_123', 400);

        $this->assertSame('test message', $e->getMessage());
        $this->assertSame('TEST_CODE', $e->getErrorCode());
        $this->assertSame('req_123', $e->getRequestId());
        $this->assertSame(400, $e->getHttpStatus());
        $this->assertSame(400, $e->getCode());
    }

    public function testAllExceptionsExtendBase(): void
    {
        $exceptions = [
            new AuthenticationException('auth', 'UNAUTHORIZED', null, 401),
            new ValidationException('valid', 'INVALID', null, 400),
            new NotFoundException('not found', 'NOT_FOUND', null, 404),
            new RateLimitException('rate', 'TOO_MANY', null, 429),
            new ServerException('server', 'INTERNAL', null, 500),
            new NetworkException('network', 'NETWORK', null, 0),
        ];

        foreach ($exceptions as $e) {
            $this->assertInstanceOf(XidentException::class, $e);
            $this->assertInstanceOf(\Exception::class, $e);
        }
    }

    public function testRateLimitExceptionCarriesRetryAfter(): void
    {
        $e = new RateLimitException('Too many requests', 'TOO_MANY_REQUESTS', null, 429);
        $e->setRetryAfter(60);

        $this->assertSame(60, $e->getRetryAfter());
    }

    public function testRateLimitExceptionRetryAfterDefaultNull(): void
    {
        $e = new RateLimitException('Too many requests', 'TOO_MANY_REQUESTS', null, 429);
        $this->assertNull($e->getRetryAfter());
    }

    public function testExceptionWithNullRequestId(): void
    {
        $e = new ValidationException('bad', 'BAD', null, 400);
        $this->assertNull($e->getRequestId());
    }

    public function testExceptionWithDefaultValues(): void
    {
        $e = new XidentException('message');

        $this->assertSame('', $e->getErrorCode());
        $this->assertNull($e->getRequestId());
        $this->assertSame(0, $e->getHttpStatus());
    }

    public function testExceptionChaining(): void
    {
        $previous = new \RuntimeException('original');
        $e = new ServerException('wrapped', 'SERVER_ERROR', null, 500, $previous);

        $this->assertSame($previous, $e->getPrevious());
    }

    public function testNetworkExceptionHasZeroHttpStatus(): void
    {
        $e = new NetworkException('timeout', 'NETWORK_ERROR', null, 0);
        $this->assertSame(0, $e->getHttpStatus());
    }
}
