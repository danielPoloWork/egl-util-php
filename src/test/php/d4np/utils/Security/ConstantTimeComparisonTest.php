<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Security;

use D4np\Utils\Http\CsrfToken;
use D4np\Utils\Security\ArrayRateLimitStore;
use D4np\Utils\Security\FileRateLimitStore;
use D4np\Utils\Security\Hash;
use D4np\Utils\Security\Hmac;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Spec §6/T-03 (revision **r2**): constant-time comparison, asserted as a **mechanism**.
 *
 * The specification originally asked for a timing test here. It was measured before it was changed,
 * and the signal such a test needs is not present to be measured: the `===` early-vs-late gradient
 * is **+2.8 ns/op** against **38 ns/op** of measurement noise — about 13× below the noise floor on
 * an idle machine, and six orders of magnitude below request latency over HTTP. The full numbers
 * are in the spec's r2 rationale and [ADR-0027].
 *
 * What is left is a scoping point rather than a compromise. Whether `hash_equals()` is itself
 * constant-time is **PHP's** contract, verified in PHP's own test suite; re-deriving it here would
 * be testing someone else's implementation through a worse instrument. The property that exists at
 * *this* layer is **which comparator this code invokes** — and that is decidable exactly, from the
 * source, with no measurement at all.
 *
 * So each secret-comparison path is asserted in both directions the spec now requires: that the
 * constant-time comparator is present, and that the two secrets are never compared directly. The
 * registry is guarded for completeness, so a path added later cannot quietly go unasserted.
 *
 * This matters because the defect is invisible to behaviour. `hash_equals($a, $b)` and `$a === $b`
 * return identical values for every input; when the substitution was planted as a probe during item
 * 6.2, the entire suite passed.
 */
#[Group('T-03')]
final class ConstantTimeComparisonTest extends TestCase
{
    /**
     * The comparators that are constant-time, and therefore legitimate on a secret path.
     *
     * `password_verify()` earns its place alongside `hash_equals()`: it re-derives the hash and
     * compares in constant time, and is the *correct* mechanism for a password hash — insisting on
     * `hash_equals()` everywhere would be cargo-culting one name rather than the property.
     *
     * @var list<string>
     */
    private const CONSTANT_TIME = ['hash_equals', 'password_verify'];

    /**
     * Every path in the library that compares a caller-supplied secret against a stored one.
     *
     * @return iterable<string, array{class-string, string, string, string, string}>
     */
    public static function secretComparisonPaths(): iterable
    {
        yield 'CsrfToken::validate()' => [CsrfToken::class, 'validate', 'hash_equals', 'stored', 'token'];
        yield 'Hash::verify()' => [Hash::class, 'verify', 'password_verify', 'password', 'hash'];
        yield 'Hmac::verify()' => [Hmac::class, 'verify', 'hash_equals', 'mac', 'expected'];

        // The two below are **not** credential comparisons: they compare a compare-and-swap version
        // token, which no user supplies and whose matching prefix length reveals nothing an
        // attacker does not already know. They are registered anyway, because this registry's value
        // is completeness — every constant-time call in the library accounted for — and because the
        // comparator is still the right one there: a version is an opaque token, and comparing
        // opaque tokens obliviously costs nothing. Registered rather than downgraded to `===`:
        // weakening code to quiet a guard is the inversion of item 11.2's rule.
        yield 'ArrayRateLimitStore::writeIfVersion()' => [
            ArrayRateLimitStore::class, 'writeIfVersion', 'hash_equals', 'currentVersion', 'expectedVersion',
        ];
        yield 'FileRateLimitStore::writeIfVersion()' => [
            FileRateLimitStore::class, 'writeIfVersion', 'hash_equals', 'currentVersion', 'expectedVersion',
        ];
    }

    /**
     * Positively: the constant-time comparator is the one being called.
     *
     * @param class-string $class
     */
    #[DataProvider('secretComparisonPaths')]
    public function testTheConstantTimeComparatorIsPresent(
        string $class,
        string $method,
        string $comparator,
        string $secretA,
        string $secretB,
    ): void {
        self::assertStringContainsString(
            $comparator . '(',
            self::sourceOf($class, $method),
            \sprintf(
                '%s::%s() must compare with %s(); a variable-time comparison returns the same value '
                . 'for every input, so no behavioural test in this suite would notice its absence.',
                $class,
                $method,
                $comparator,
            ),
        );
    }

    /**
     * Negatively: the two secrets are never handed to a variable-time comparison.
     *
     * The check is deliberately narrow — it targets a comparison *between the two secrets*, not
     * every `==` in the method. `CsrfToken::validate()` legitimately tests its stored value against
     * `null` and `''` before comparing, and a blanket ban would either fail on that or force it to
     * be written worse.
     *
     * @param class-string $class
     */
    #[DataProvider('secretComparisonPaths')]
    public function testTheSecretsAreNeverComparedDirectly(
        string $class,
        string $method,
        string $comparator,
        string $secretA,
        string $secretB,
    ): void {
        $body = self::sourceOf($class, $method);

        self::assertDoesNotMatchRegularExpression(
            \sprintf(
                '/\$%1$s\s*(?:={2,3}|!={1,2}|<=>)\s*\$%2$s|\$%2$s\s*(?:={2,3}|!={1,2}|<=>)\s*\$%1$s/',
                \preg_quote($secretA, '/'),
                \preg_quote($secretB, '/'),
            ),
            $body,
            \sprintf(
                'comparing $%s with $%s directly leaks the matching prefix length through timing, '
                . 'which is enough to reconstruct the secret byte by byte given enough attempts.',
                $secretA,
                $secretB,
            ),
        );

        foreach (['strcmp', 'strncmp', 'substr_compare'] as $variableTime) {
            self::assertStringNotContainsString(
                $variableTime . '(',
                $body,
                "{$variableTime}() short-circuits on the first differing byte, exactly like ===.",
            );
        }
    }

    /**
     * The registry above must name **every** such path.
     *
     * Without this, adding a new secret comparison and forgetting to register it would leave it
     * unasserted while this file still reported green — the same failure mode as the gap item 6.2
     * wrote up as acceptable.
     *
     * Comments are stripped via `token_get_all()` before searching, because the docblocks in these
     * very classes discuss `hash_equals()` at length and a plain text search would match prose.
     */
    public function testTheRegistryNamesEverySecretComparisonInTheLibrary(): void
    {
        $registered = [];
        foreach (self::secretComparisonPaths() as $path) {
            [$class, $method] = $path;
            $reflected = new \ReflectionMethod($class, $method);
            $registered[] = [
                'file' => (string) $reflected->getFileName(),
                'from' => $reflected->getStartLine(),
                'to' => $reflected->getEndLine(),
            ];
        }

        $unregistered = [];
        foreach (self::libraryFiles() as $file) {
            foreach (self::constantTimeCallsIn($file) as [$name, $line]) {
                $covered = false;
                foreach ($registered as $path) {
                    if ($path['file'] === $file && $line >= $path['from'] && $line <= $path['to']) {
                        $covered = true;
                        break;
                    }
                }

                if (!$covered) {
                    $unregistered[] = \sprintf('%s() at %s:%d', $name, \basename($file), $line);
                }
            }
        }

        self::assertSame(
            [],
            $unregistered,
            'a constant-time comparison exists outside every registered secret-comparison path; add '
            . 'it to secretComparisonPaths() so both directions of the spec r2 assertion cover it',
        );
    }

    /**
     * The scanner must be able to SEE the comparisons, not merely find no unregistered ones.
     *
     * This is BUG-0001's regression guard, and it exists because the failure it guards against was
     * **green**, not red: when ADR-0048 prefixed every internal call, the scanner's token filter
     * stopped matching and `assertSame([], $unregistered)` above compared an empty list against an
     * empty list — for ten items, across two security items. A test that passes because it found
     * nothing to check is indistinguishable, from the outside, from one that passes because
     * everything checked out.
     *
     * Asserting `>=` rather than `===` on purpose: a legitimately unregistered call would be caught
     * by the test above, and pinning an exact count here would make this file fail twice for one
     * cause.
     */
    public function testTheScannerCanSeeEveryRegisteredComparison(): void
    {
        $seen = 0;
        foreach (self::libraryFiles() as $file) {
            $seen += \count(self::constantTimeCallsIn($file));
        }

        $registered = \count([...self::secretComparisonPaths()]);

        self::assertGreaterThanOrEqual(
            $registered,
            $seen,
            \sprintf(
                'the source scanner sees %d constant-time comparison(s) but %d path(s) are '
                . 'registered, so it has gone blind to at least one and the completeness check '
                . 'above is passing on an empty set (BUG-0001: \\hash_equals tokenizes as '
                . 'T_NAME_FULLY_QUALIFIED, not T_STRING)',
                $seen,
                $registered,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private static function libraryFiles(): array
    {
        $root = \dirname(__DIR__, 5) . '/main/php/d4np/utils';
        $found = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $found[] = (string) $entry->getRealPath();
            }
        }

        \sort($found);

        return $found;
    }

    /**
     * Calls to a constant-time comparator in `$file`, ignoring comments and method names.
     *
     * **Both token shapes, and that is BUG-0001.** PHP tokenizes `hash_equals(…)` as `T_STRING`
     * but `\hash_equals(…)` as `T_NAME_FULLY_QUALIFIED` carrying the leading backslash. This
     * matched only the former until 2026-08-20, and ADR-0048's `native_function_invocation` rule
     * had prefixed the entire tree at item 10.12 — so the scanner saw **0** of the library's 3
     * comparisons and {@see testTheRegistryNamesEverySecretComparisonInTheLibrary()} passed on an
     * empty set for ten items. The two text-searching tests in this file were unaffected, because
     * `\hash_equals(` still contains `hash_equals(`, which is why the file looked healthy.
     *
     * Item 10.12's audit checked the other source-inspecting tests and called them safe because
     * they "match patterns, not spellings". This one matched a **token type** — a category that
     * audit did not have, and the one the prefixing changed.
     *
     * @return list<array{string, int}>
     */
    private static function constantTimeCallsIn(string $file): array
    {
        $tokens = \token_get_all((string) \file_get_contents($file));
        $calls = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || !\in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            // Compare on the bare name: this must be indifferent to whether the call is prefixed,
            // which is the whole of BUG-0001.
            $name = \ltrim($token[1], '\\');
            if (!\in_array($name, self::CONSTANT_TIME, true)) {
                continue;
            }

            // `->hash_equals` or `::hash_equals` would be a method, not the PHP function. Only
            // reachable for T_STRING; a T_NAME_FULLY_QUALIFIED can never follow either operator.
            $previous = $tokens[$index - 1] ?? null;
            if (\is_array($previous) && \in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $calls[] = [$name, $token[2]];
        }

        return $calls;
    }

    /**
     * @param class-string $class
     */
    private static function sourceOf(string $class, string $method): string
    {
        $reflected = new \ReflectionMethod($class, $method);
        $lines = \file((string) $reflected->getFileName());
        self::assertIsArray($lines);

        return \implode('', \array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));
    }
}
