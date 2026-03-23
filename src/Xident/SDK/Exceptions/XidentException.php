<?php

declare(strict_types=1);

namespace Xident\SDK\Exceptions;

/**
 * Base exception for all Xident SDK errors.
 *
 * Every exception carries the API error code and request ID for debugging.
 */
class XidentException extends \Exception
{
    public function __construct(
        string $message,
        protected string $errorCode = '',
        protected ?string $requestId = null,
        int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /** API error code (e.g. "INVALID_REQUEST", "UNAUTHORIZED"). */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Request ID from meta.request_id — include in support tickets. */
    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    /** HTTP status code (0 for network errors). */
    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
