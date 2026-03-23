<?php

declare(strict_types=1);

namespace Xident\SDK\Exceptions;

/** Thrown when the API key is invalid, expired, or missing (HTTP 401). */
class AuthenticationException extends XidentException {}
