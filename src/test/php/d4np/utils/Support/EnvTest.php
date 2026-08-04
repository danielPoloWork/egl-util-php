<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Env::get()` — spec §2 item 24, and the T-05 boolean-coercion property test spec §7 names.
 *
 * Every variable used here carries a per-test unique name (a random suffix) so parallel or
 * repeated runs never collide over process-global environment state, and `tearDown()` always
 * `putenv()`-unsets whatever the test set — including on failure, since PHPUnit still calls it.
 */
final class EnvTest extends TestCase
{
    /** @var list<string> */
    private array $setKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->setKeys as $key) {
            putenv($key);
        }
        $this->setKeys = [];
    }

    private function withEnv(string $value): string
    {
        $key = 'D4NP_UTILS_ENV_TEST_' . bin2hex(random_bytes(8));
        putenv("{$key}={$value}");
        $this->setKeys[] = $key;

        return $key;
    }

    /**
     * The T-05 property test (spec §7): the coercion table. One row per recognised token, both
     * cases, so a regression that stops recognising "On" but keeps "on" is caught.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function booleanTokens(): iterable
    {
        foreach (['true', 'True', 'TRUE', '1', 'yes', 'Yes', 'on', 'On'] as $token) {
            yield "\"{$token}\" is true" => [$token, true];
        }
        foreach (['false', 'False', 'FALSE', '0', 'no', 'No', 'off', 'Off'] as $token) {
            yield "\"{$token}\" is false" => [$token, false];
        }
    }

    #[DataProvider('booleanTokens')]
    public function testBooleanCoercionTable(string $rawValue, bool $expected): void
    {
        $key = $this->withEnv($rawValue);

        self::assertSame($expected, Env::get($key));
    }

    public function testTheClassicBugIsFixedStringFalseIsNotTruthy(): void
    {
        // The bug this method exists to fix, spelled out: getenv() alone returns the STRING
        // "false", and `if ($value)` is true for it. Env::get() must not repeat that mistake.
        $key = $this->withEnv('false');

        self::assertFalse(Env::get($key));
        // Not asserted separately: casting the result to bool. PHPStan already knows the
        // result is `false` from the assertion above (an already-narrowed, statically
        // decidable check) — a redundant runtime assertion would prove nothing more.
    }

    public function testSurroundingWhitespaceIsTrimmedBeforeCoercion(): void
    {
        $key = $this->withEnv('  true  ');

        self::assertTrue(Env::get($key));
    }

    public function testAnUnrecognisedValueIsReturnedAsTheRawString(): void
    {
        $key = $this->withEnv('postgres://localhost/db');

        self::assertSame('postgres://localhost/db', Env::get($key));
    }

    public function testANumericNonBooleanValueIsReturnedUnchanged(): void
    {
        // "2" is not a recognised boolean token (only "0"/"1" are), so it must pass through as
        // the string "2" rather than being coerced or silently mangled.
        $key = $this->withEnv('2');

        self::assertSame('2', Env::get($key));
    }

    public function testAnExplicitlyEmptyValueIsReturnedAsEmptyStringNotFalse(): void
    {
        // The deliberate exception: filter_var('', FILTER_VALIDATE_BOOLEAN, ...) returns false,
        // which would silently turn an intentional FOO="" into the boolean false. Verified
        // directly against filter_var before writing Env::get() this way.
        $key = $this->withEnv('');

        self::assertSame('', Env::get($key));
    }

    public function testAnUnsetVariableReturnsTheDefault(): void
    {
        $key = 'D4NP_UTILS_ENV_TEST_DEFINITELY_UNSET_' . bin2hex(random_bytes(8));

        self::assertSame('fallback', Env::get($key, 'fallback'));
        self::assertNull(Env::get($key));
    }

    public function testAnUnsetVariableIsNotConflatedWithAnEmptyOne(): void
    {
        // getenv() itself distinguishes these: false (bool) for unset, '' (string) for
        // set-but-empty. This is the pair of assertions that would fail if Env::get() used a
        // loose (==) comparison instead of ===.
        $unset = 'D4NP_UTILS_ENV_TEST_UNSET_' . bin2hex(random_bytes(8));
        $empty = $this->withEnv('');

        self::assertNull(Env::get($unset), 'unset must fall through to the default (null here)');
        self::assertSame('', Env::get($empty), 'set-but-empty must return the empty string, not the default');
    }
}
