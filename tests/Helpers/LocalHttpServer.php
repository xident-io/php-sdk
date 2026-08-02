<?php

declare(strict_types=1);

namespace Xident\SDK\Tests\Helpers;

/**
 * A throwaway HTTP server on 127.0.0.1, backed by PHP's built-in web server.
 *
 * Used by the tests that must drive the SDK's real cURL transport. Everything
 * else injects a fake transport; this is the only way to reach curl_exec,
 * curl_getinfo and the response-header parser, so those paths get a real
 * socket rather than an ignore annotation.
 */
final class LocalHttpServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct(
        public readonly string $baseUrl,
        mixed $process,
        array $pipes,
    ) {
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * Boot the server on a free port and wait until it accepts connections.
     *
     * @param string $router Absolute path to the router script.
     */
    public static function start(string $router): self
    {
        $port = self::reserveFreePort();

        // -n skips php.ini so the child cannot inherit a coverage driver or
        // an output buffer that would corrupt its responses.
        $process = proc_open(
            [PHP_BINARY, '-n', '-S', '127.0.0.1:' . $port, $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the built-in web server');
        }

        $server = new self('http://127.0.0.1:' . $port, $process, $pipes);

        if (!$server->waitUntilAccepting(5.0)) {
            $server->stop();
            throw new \RuntimeException('Built-in web server did not come up on port ' . $port);
        }

        return $server;
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                // Drain so the child never blocks on a full pipe, then close.
                stream_set_blocking($pipe, false);
                stream_get_contents($pipe);
                fclose($pipe);
            }
        }
        $this->pipes = [];

        proc_terminate($this->process);
        proc_close($this->process);
    }

    /** A port on which nothing is listening — for the connection-refused path. */
    public static function closedPort(): int
    {
        return self::reserveFreePort();
    }

    private static function reserveFreePort(): int
    {
        // Bind to port 0, let the OS pick, then release it. The window between
        // release and re-bind is tiny and local to the loopback interface.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Could not reserve a local port: {$errstr} ({$errno})");
        }

        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr($name, (int)strrpos($name, ':') + 1);
    }

    private function waitUntilAccepting(float $timeoutSeconds): bool
    {
        $port = (int)parse_url($this->baseUrl, PHP_URL_PORT);
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($connection !== false) {
                fclose($connection);
                return true;
            }
            usleep(20_000);
        }

        return false;
    }
}
