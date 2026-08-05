<?php

/**
 * The front controller spec §7's T-03 suite drives over a real `php -S` process.
 *
 * This exists because none of what it exercises can happen in CLI: PHP returns `false` from
 * `session_start()`, `session_set_cookie_params()` and `session_regenerate_id()` there, so the unit
 * suite can assert the cookie *policy* and the call *sequence* (ADR-0026 §1, §8) but never that a
 * real `Set-Cookie` carries the flags or that a real identifier rotates.
 *
 * It stays a thin dispatcher on purpose. Every assertion belongs in the test; anything clever here
 * would be untested code deciding whether tested code passes.
 *
 * **Nothing may be emitted before the session starts** — output commits the headers, and the
 * `Set-Cookie` this whole suite inspects would never be sent.
 */

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use D4np\Utils\Http\CsrfToken;
use D4np\Utils\Http\SameSite;
use D4np\Utils\Http\Session;
use D4np\Utils\Support\UtilsThrowable;

/** Read a query parameter as a string, refusing arrays the way `Request` does (ADR-0025). */
$query = static function (string $key, string $default = ''): string {
    $value = $_GET[$key] ?? null;

    return is_string($value) ? $value : $default;
};

header('Content-Type: text/plain; charset=UTF-8');

try {
    $session = new Session(
        secure: $query('secure', '1') !== '0',
        sameSite: SameSite::from($query('samesite', 'Lax')),
        path: $query('path', '/'),
    );

    $session->start();

    $csrf = new CsrfToken($session);
    $scope = $query('scope', 'default');

    switch ($query('action', 'id')) {
        case 'id':
            echo session_id();
            break;

        case 'regenerate':
            $session->regenerate();
            echo session_id();
            break;

        case 'set':
            $session->set($query('key'), $query('value'));
            echo 'ok';
            break;

        case 'get':
            echo $session->get($query('key')) ?? '(null)';
            break;

        case 'destroy':
            $session->destroy();
            echo 'destroyed';
            break;

        case 'issue':
            echo $csrf->issue($scope);
            break;

        case 'validate':
            echo $csrf->validate($query('token'), $scope) ? 'valid' : 'invalid';
            break;

        case 'rotate':
            echo $csrf->rotate($scope);
            break;

        default:
            http_response_code(400);
            echo 'unknown action';
    }
} catch (UtilsThrowable $e) {
    // Surfaced as a body rather than a 500 so a test can assert on the refusal itself.
    http_response_code(422);
    echo get_class($e) . ': ' . $e->getMessage();
}
