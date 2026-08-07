<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http\Fixture;

/**
 * A local HTTPS origin holding a **self-signed** certificate, for spec §7's T-07 suite.
 *
 * ADR-0049's central claim — that {@see \D4np\Utils\Http\HttpClient} states its TLS policy in
 * every context instead of inheriting the process default — could only be asserted against a
 * *value* until there was a certificate to reject. This is that certificate.
 *
 * Three details were settled by probing:
 *
 * - **The certificate is generated per run, with an OpenSSL config this fixture writes itself.**
 *   A PHP build without an `openssl.cnf` (Windows, among others) fails `openssl_pkey_new()`
 *   outright — measured here — so relying on the ambient one would make the suite pass or fail
 *   by machine. Generating also means no certificate is committed, and none expires.
 * - **`php -S` cannot serve this**, since PHP's built-in server speaks no TLS. Hence a raw
 *   `stream_socket_server` with a crypto context, answering a fixed response by hand.
 * - **Crypto is enabled explicitly per connection** rather than by an `ssl://` transport, so a
 *   *failed* handshake — which is exactly what the client under test is supposed to cause —
 *   leaves the accept loop alive for the next connection instead of killing the origin.
 */
final class TlsOrigin
{
    private const READY_ATTEMPTS = 100;

    private const READY_INTERVAL_US = 50_000;

    /** How long the origin serves before exiting on its own, if the test forgets to stop it. */
    private const LIFETIME_SECONDS = 30;

    /** @var resource|null */
    private $process = null;

    private int $port = 0;

    private readonly string $workDir;

    public function __construct()
    {
        $this->workDir = \sys_get_temp_dir() . '/d4np-t07-tls-' . \bin2hex(\random_bytes(6));
    }

    /**
     * @return string the reason the origin could not start, or an empty string on success
     */
    public function start(): string
    {
        if (!\extension_loaded('openssl')) {
            return 'ext-openssl is not loaded, so no TLS origin can exist';
        }

        if (!@\mkdir($this->workDir) && !\is_dir($this->workDir)) {
            return "could not create {$this->workDir}";
        }

        $script = $this->workDir . '/origin.php';
        \file_put_contents($script, self::originScript());

        $portFile = $this->workDir . '/port';
        $logFile = $this->workDir . '/log';
        $null = DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null';

        $process = @\proc_open(
            [PHP_BINARY, $script, $this->workDir . '/origin.pem', $portFile, (string) self::LIFETIME_SECONDS],
            [
                0 => ['file', $null, 'r'],
                1 => ['file', $logFile, 'w'],
                2 => ['file', $logFile, 'a'],
            ],
            $pipes,
        );

        if (!\is_resource($process)) {
            return 'proc_open() could not spawn ' . PHP_BINARY;
        }

        $this->process = $process;

        for ($i = 0; $i < self::READY_ATTEMPTS; $i++) {
            $written = \is_file($portFile) ? \trim((string) \file_get_contents($portFile)) : '';

            if ($written !== '' && \ctype_digit($written)) {
                $this->port = (int) $written;

                return '';
            }

            if ($written !== '') {
                return "the origin reported: {$written}";
            }

            $status = \proc_get_status($process);

            if ($status['running'] === false) {
                return \sprintf(
                    'the origin exited with code %d: %s',
                    $status['exitcode'],
                    \trim((string) @\file_get_contents($logFile)) ?: '(no output)',
                );
            }

            \usleep(self::READY_INTERVAL_US);
        }

        return 'the origin never reported a port: ' . (\trim((string) @\file_get_contents($logFile)) ?: '(no output)');
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            \proc_terminate($this->process);
            \proc_close($this->process);
            $this->process = null;
        }

        foreach ((array) @\glob($this->workDir . '/*') as $file) {
            if (\is_string($file)) {
                @\unlink($file);
            }
        }

        @\rmdir($this->workDir);
    }

    public function url(): string
    {
        return "https://127.0.0.1:{$this->port}/";
    }

    /** What a client that skips verification receives, so a test can tell refusal from breakage. */
    public const BODY = 'tls-origin';

    /**
     * The origin runs in its own process, from a script written at start-up rather than committed:
     * it is a fixture *of* this fixture, and keeping it here means the certificate parameters and
     * the server that presents them cannot drift apart.
     */
    private static function originScript(): string
    {
        $script = <<<'PHP'
            <?php

            declare(strict_types=1);

            [, $pemPath, $portFile, $lifetime] = $argv;

            $configPath = $pemPath . '.cnf';
            file_put_contents($configPath, "[ req ]\ndistinguished_name = dn\n[ dn ]\n[ ext ]\nsubjectAltName = @alt\n[ alt ]\nIP.1 = 127.0.0.1\nDNS.1 = localhost\n");

            $config = [
                'config' => $configPath,
                'digest_alg' => 'sha256',
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ];

            $key = openssl_pkey_new($config);
            $csr = $key === false ? false : openssl_csr_new(['commonName' => 'localhost'], $key, $config + ['req_extensions' => 'ext']);
            $certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, $config + ['x509_extensions' => 'ext']);

            if ($certificate === false || $key === false) {
                file_put_contents($portFile, 'could not generate a certificate: ' . openssl_error_string());
                exit(1);
            }

            openssl_x509_export($certificate, $certificatePem);
            openssl_pkey_export($key, $keyPem, null, $config);
            file_put_contents($pemPath, $certificatePem . $keyPem);

            $server = @stream_socket_server(
                'tcp://127.0.0.1:0',
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                stream_context_create(['ssl' => ['local_cert' => $pemPath, 'allow_self_signed' => true, 'verify_peer' => false]]),
            );

            if ($server === false) {
                file_put_contents($portFile, "could not listen: {$errstr}");
                exit(1);
            }

            $name = (string) stream_socket_get_name($server, false);
            file_put_contents($portFile, substr($name, (int) strrpos($name, ':') + 1));

            $deadline = microtime(true) + (float) $lifetime;
            $body = '__BODY__';

            while (microtime(true) < $deadline) {
                $client = @stream_socket_accept($server, 1);

                if ($client === false) {
                    continue;
                }

                // A client that verifies will abort here. That is the point, so the failure is
                // swallowed and the loop goes on serving.
                if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                    fclose($client);

                    continue;
                }

                // The request MUST be drained before answering. Measured: closing a socket that
                // still holds unread inbound bytes resets the connection, and the reset destroys
                // the response the client has not finished reading — which showed up as an
                // intermittent "HTTP request failed!" in roughly two runs out of five.
                stream_set_timeout($client, 2);
                $request = '';

                while (!str_contains($request, "\r\n\r\n")) {
                    $line = fgets($client, 8192);

                    if ($line === false) {
                        break;
                    }

                    $request .= $line;
                }

                fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);

                // Half-close first, so the last record is flushed before the socket goes away.
                @stream_socket_shutdown($client, STREAM_SHUT_WR);
                fclose($client);
            }

            fclose($server);
            PHP;

        return \str_replace('__BODY__', self::BODY, $script) . "\n";
    }
}
