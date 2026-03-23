<?php

declare(strict_types=1);

namespace Xident\SDK\Exceptions;

/** Thrown when the API rate limit is exceeded (HTTP 429). */
class RateLimitException extends XidentException
{
    protected ?int $retryAfter = null;

    public function setRetryAfter(?int $seconds): self
    {
        $this->retryAfter = $seconds;
        return $this;
    }

    /** Seconds to wait before retrying (from Retry-After header), or null if not provided. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
