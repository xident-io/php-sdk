<?php

declare(strict_types=1);

namespace Xident\SDK\Exceptions;

/** Thrown when the Xident API returns a server error (HTTP 5xx). */
class ServerException extends XidentException {}
