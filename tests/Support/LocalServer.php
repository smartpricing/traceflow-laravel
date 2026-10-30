<?php

namespace Smartness\TraceFlow\Tests\Support;

/**
 * Real local Traceflow stand-ins for delivery tests, so outages are tested
 * against actual sockets rather than only Guzzle mocks.
 *
 * - LocalServer::start(): `php -S` with server-router.php (ok / 503 / 409 / 429 / jitter).
 * - LocalServer::hanging(): a listening socket that is never accepted: the
 *   kernel completes the TCP handshake, takes the request and never answers.
 * - LocalServer::refusedUrl(): a port nobody listens on.
 */
final class LocalServer
{
    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $socket;

    private ?string $dir = null;

    private function __construct(public readonly string $url) {}

    public static function start(string $mode = 'ok', int $workers = 4): self
    {
        $port = self::freePort();
        $dir = sys_get_temp_dir().'/traceflow-sdk-test-'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents("{$dir}/mode", $mode);
        touch("{$dir}/requests.log");

        $server = new self("http://127.0.0.1:{$port}");
        $server->dir = $dir;
        $server->process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/server-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$dir}/server.log", 'a']],
            $pipes,
            null,
            getenv() + ['TF_TEST_DIR' => $dir, 'PHP_CLI_SERVER_WORKERS' => (string) $workers],
        );

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return $server;
            }
            usleep(20_000);
        }

        $server->stop();
        throw new \RuntimeException("php -S did not start on port {$port}");
    }

    public static function hanging(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Cannot listen: {$errstr}");
        }

        $server = new self('http://'.stream_socket_get_name($socket, false));
        $server->socket = $socket;

        return $server;
    }

    public static function refusedUrl(): string
    {
        return 'http://127.0.0.1:'.self::freePort();
    }

    public function setMode(string $mode): void
    {
        file_put_contents("{$this->dir}/mode", $mode);
    }

    /**
     * Requests received so far, in arrival order.
     *
     * @return list<array{id: string, method: string, path: string, received: float, answered: ?float}>
     */
    public function requests(): array
    {
        if ($this->dir === null) {
            return [];
        }

        $requests = [];
        foreach (file("{$this->dir}/requests.log", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $entry = json_decode($line, true);
            if ($entry['event'] === 'received') {
                $requests[$entry['id']] = [
                    'id' => $entry['id'], 'method' => $entry['method'], 'path' => $entry['path'],
                    'received' => $entry['time'], 'answered' => null,
                ];
            } elseif (isset($requests[$entry['id']])) {
                $requests[$entry['id']]['answered'] = $entry['time'];
            }
        }

        return array_values($requests);
    }

    public function requestCount(): int
    {
        return count($this->requests());
    }

    public function stop(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;

        if (is_resource($this->process)) {
            // With PHP_CLI_SERVER_WORKERS the workers outlive a terminated
            // master (and keep inherited pipes open): stop them first.
            $pid = proc_get_status($this->process)['pid'];
            @exec('pkill -KILL -P '.(int) $pid.' 2>/dev/null');
            proc_terminate($this->process, 15);
            $deadline = microtime(true) + 2;
            while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
        }
        $this->process = null;

        if ($this->dir !== null && is_dir($this->dir)) {
            array_map('unlink', glob("{$this->dir}/*") ?: []);
            @rmdir($this->dir);
        }
        $this->dir = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Cannot find a free port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
