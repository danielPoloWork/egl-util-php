<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http\Fixture;

/**
 * A real `php -S` process, for spec §7's T-03 suite.
 *
 * Everything T-03 asserts needs an actual HTTP response: PHP returns `false` from every session
 * function in CLI, so a live `Set-Cookie` and a rotating session identifier exist nowhere else
 * (ADR-0026).
 *
 * Two things here were learned by probing rather than assumed:
 *
 * - **stdout and stderr go to a file, never a pipe.** Reading a live process's pipe blocks until
 *   EOF, and a server that is working correctly never sends one. The first attempt at this harness
 *   hung on exactly that.
 * - **The port is claimed by binding `:0` and reading back what the OS assigned**, rather than
 *   guessing a high number. A hard-coded port turns two concurrent runs — or a leftover process —
 *   into a confusing failure in whichever one loses.
 */
final class DevServer
{
    private const READY_ATTEMPTS = 100;
    private const READY_INTERVAL_US = 50_000;

    /** @var resource|null */
    private $process = null;

    private string $host = '';

    private readonly string $logFile;

    public function __construct(private readonly string $documentRoot)
    {
        $this->logFile = \sys_get_temp_dir() . '/d4np-t03-' . \bin2hex(\random_bytes(6)) . '.log';
    }

    /**
     * @return string the reason the server could not start, or an empty string on success
     */
    public function start(): string
    {
        $port = self::claimFreePort();
        if ($port === 0) {
            return 'could not claim a local port';
        }

        $this->host = "127.0.0.1:{$port}";
        $null = DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';

        $process = @\proc_open(
            [PHP_BINARY, '-S', $this->host, '-t', $this->documentRoot],
            [
                0 => ['file', $null, 'r'],
                1 => ['file', $this->logFile, 'w'],
                2 => ['file', $this->logFile, 'a'],
            ],
            $pipes,
        );

        if (!\is_resource($process)) {
            return 'proc_open() could not spawn ' . PHP_BINARY;
        }

        $this->process = $process;

        for ($i = 0; $i < self::READY_ATTEMPTS; $i++) {
            $socket = @\fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                \fclose($socket);

                return '';
            }

            $status = \proc_get_status($process);
            if ($status['running'] === false) {
                return \sprintf(
                    'the server exited with code %d: %s',
                    $status['exitcode'],
                    \trim($this->log()) ?: '(no output)',
                );
            }

            \usleep(self::READY_INTERVAL_US);
        }

        return \sprintf(
            'the server did not answer on %s within %.1fs: %s',
            $this->host,
            self::READY_ATTEMPTS * self::READY_INTERVAL_US / 1e6,
            \trim($this->log()) ?: '(no output)',
        );
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process);
            \proc_close($this->process);
            $this->process = null;
        }

        if (\is_file($this->logFile)) {
            @\unlink($this->logFile);
        }
    }

    public function url(string $query = ''): string
    {
        return "http://{$this->host}/" . ($query === '' ? '' : '?' . $query);
    }

    /**
     * The server's own output, which is where a fixture-script fatal shows up — a failing test that
     * printed only "expected 200, got 500" would send someone hunting in the wrong process.
     */
    public function log(): string
    {
        return (string) @\file_get_contents($this->logFile);
    }

    /**
     * Ask the OS for a port instead of picking one.
     *
     * Binding to `:0` and reading back the assignment leaves a gap between closing this socket and
     * the server binding it. That is unavoidable without handing a listening socket to `php -S`,
     * which it does not accept; the alternative — a fixed port — collides far more often.
     */
    private static function claimFreePort(): int
    {
        $socket = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            return 0;
        }

        $name = (string) \stream_socket_get_name($socket, false);
        \fclose($socket);

        $port = (int) \substr($name, (int) \strrpos($name, ':') + 1);

        return $port > 0 ? $port : 0;
    }
}
