<?php

declare(strict_types=1);

namespace Xident\SDK\Exceptions;

/** Thrown when a network error occurs (DNS, timeout, SSL, connection refused). */
class NetworkException extends XidentException {}
