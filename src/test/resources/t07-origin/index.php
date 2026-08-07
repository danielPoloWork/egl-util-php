<?php

/**
 * The origin spec §7's T-07 suite drives {@see D4np\Utils\Http\HttpClient} against.
 *
 * Everything here is a behaviour the client's unit suite cannot reach, because that suite
 * replaces the transport with a fake: a body arriving in pieces, a header repeated on the wire,
 * a redirect whose target can record whether it was contacted, an origin that stops answering
 * mid-response. The client under test runs in the *test* process, so unlike T-03 this suite does
 * produce coverage of {@see D4np\Utils\Http\StreamTransport} — which had never executed against
 * a real socket before item 11.4.
 *
 * A thin dispatcher on purpose (T-03's rule): every assertion lives in the test. The only state
 * kept here is the redirect target's hit marker, and it is a file because the test needs to read
 * it from another process.
 *
 * `flush()` after a partial write is load-bearing — without it PHP holds the bytes and the
 * "arrives in pieces" modes would arrive whole.
 */

declare(strict_types=1);

$mode = \is_string($_GET['mode'] ?? null) ? $_GET['mode'] : 'plain';
$marker = \sys_get_temp_dir() . '/d4np-t07-target-' . (\is_string($_GET['run'] ?? null) ? \preg_replace('/[^a-f0-9]/', '', $_GET['run']) : 'x') . '.txt';

switch ($mode) {
    // Answers, then dribbles one byte per window for longer than any test's budget. The
    // per-phase timeout never fires — each window delivers bytes — so only a wall-clock ceiling
    // ends this.
    case 'drip':
        \header('Content-Type: text/plain');
        echo 'start';
        \flush();

        for ($i = 0; $i < 12; $i++) {
            \usleep(150_000);
            echo '.';
            \flush();
        }

        break;

    // Accepts the connection and says nothing at all until well past a short per-phase timeout.
    case 'silent':
        \usleep(1_600_000);
        echo 'eventually';

        break;

    // Slow, but legal: two chunks with a pause between them, inside any generous budget.
    case 'pause':
        \header('Content-Type: text/plain');
        echo 'first';
        \flush();
        \usleep(250_000);
        echo 'second';

        break;

    // The same header twice, which is how Set-Cookie actually arrives.
    case 'repeated':
        \header('Set-Cookie: first=1; Path=/');
        \header('Set-Cookie: second=2; Path=/', false);
        echo 'repeated';

        break;

    // Larger than the transport's read chunk, and binary — NUL bytes included, so a
    // string-terminating read would be visible.
    case 'binary':
        \header('Content-Type: application/octet-stream');
        $block = '';

        for ($byte = 0; $byte < 256; $byte++) {
            $block .= \chr($byte);
        }

        echo \str_repeat($block, 160);   // 40 960 bytes, five read chunks

        break;

    // Reports what the origin actually received, which is the only way to check that the
    // context options became a real request.
    case 'echo':
        \header('Content-Type: application/json');
        echo \json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'protocol' => $_SERVER['SERVER_PROTOCOL'] ?? '',
            'contentType' => $_SERVER['CONTENT_TYPE'] ?? '',
            'probe' => $_SERVER['HTTP_X_PROBE'] ?? '',
            'agent' => $_SERVER['HTTP_X_AGENT'] ?? '',
            'body' => \file_get_contents('php://input'),
        ], JSON_THROW_ON_ERROR);

        break;

    case 'redirect':
        $to = \is_string($_GET['to'] ?? null) ? $_GET['to'] : 'target';
        $run = \is_string($_GET['run'] ?? null) ? $_GET['run'] : '';
        \header("Location: /?mode={$to}&run={$run}", true, 302);
        echo 'moved';

        break;

    // Records that it was reached. The file is the proof that a refused redirect refused to
    // travel — an assertion about the response alone could not tell the difference.
    case 'target':
        \file_put_contents($marker, 'reached', FILE_APPEND);
        echo 'target';

        break;

    case 'missing':
        \http_response_code(404);
        echo 'no such thing';

        break;

    case 'error':
        \http_response_code(503);
        echo 'the body of an error is still a body';

        break;

    default:
        echo 'plain';
}
