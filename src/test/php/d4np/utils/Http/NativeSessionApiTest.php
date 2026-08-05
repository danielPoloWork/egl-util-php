<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Http;

use D4np\Utils\Http\NativeSessionApi;
use D4np\Utils\Http\SessionApi;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The production side of the seam (ADR-0026 §8).
 *
 * Four of its five methods cannot be called here — PHP returns `false` from them in CLI, which is
 * the whole reason the seam exists — so what this file can assert is that each one **delegates to
 * the session function it claims to**. That is a mechanism assertion for the same reason §7's is:
 * a delegation quietly pointing at the wrong function, or dropping an argument, produces no
 * observable difference anywhere the unit suite can look.
 *
 * The behaviour against a real server belongs to roadmap item **6.3**.
 */
#[Group('T-03')]
final class NativeSessionApiTest extends TestCase
{
    /**
     * The one method that can actually be called here.
     *
     * Its reach is limited and worth stating: CLI only ever reports `PHP_SESSION_NONE`, so this
     * cannot tell the real delegation apart from a hard-coded `PHP_SESSION_NONE` — verified, that
     * substitution leaves this test green and only the delegation assertion below catches it. It
     * earns its place by proving the method is callable and returns the right value in the one
     * state reachable here, not by pinning the implementation.
     */
    public function testStatusReportsPhpsOwnSessionStatus(): void
    {
        self::assertSame(session_status(), (new NativeSessionApi())->status());
    }

    /**
     * Each method against the function it must call.
     *
     * `session_regenerate_id(true)` earns its own entry: the `true` deletes the old session record,
     * and without it the session is merely renamed while the previous identifier keeps working —
     * which is the half of session fixation that matters. It is fixed here rather than exposed as a
     * parameter so that no caller can turn it off, and asserted because nothing observable would
     * reveal its loss.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function delegations(): iterable
    {
        yield 'status' => ['status', 'session_status()'];
        yield 'cookie params' => ['setCookieParams', 'session_set_cookie_params($params)'];
        yield 'start' => ['start', 'session_start()'];
        yield 'regenerate, deleting the old record' => ['regenerateId', 'session_regenerate_id(true)'];
        yield 'destroy' => ['destroy', 'session_destroy()'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('delegations')]
    public function testEachMethodDelegatesToItsSessionFunction(string $method, string $call): void
    {
        $reflected = new \ReflectionMethod(NativeSessionApi::class, $method);
        $source = (string) file_get_contents((string) $reflected->getFileName());
        $body = implode("\n", array_slice(
            explode("\n", $source),
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));

        self::assertStringContainsString("return {$call};", $body);
    }

    public function testItIsTheSeamsContract(): void
    {
        self::assertInstanceOf(SessionApi::class, new NativeSessionApi());
    }
}
