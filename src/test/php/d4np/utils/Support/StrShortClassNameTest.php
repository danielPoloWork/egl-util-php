<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Support;

use D4np\Utils\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * `Str::shortClassName()` — spec r3 FR-31 (RFC-0002).
 *
 * String inputs are deliberately not required to name a loaded class (names arrive from
 * configuration and logs), so the contract is pure string surgery plus a refusal when no
 * name remains after the final separator.
 */
final class StrShortClassNameTest extends TestCase
{
    /**
     * @return iterable<string, array{object|string, string}>
     */
    public static function nameCases(): iterable
    {
        yield 'fully qualified name' => [Str::class, 'Str'];
        yield 'global name passes through' => ['ArrayObject', 'ArrayObject'];
        yield 'leading separator only' => ['\\ArrayObject', 'ArrayObject'];
        yield 'not-a-loaded-class string is fine' => ['Acme\Generated\ReportRow', 'ReportRow'];
        yield 'object instance uses its concrete class' => [new stdClass(), 'stdClass'];
    }

    #[DataProvider('nameCases')]
    public function testShortName(object|string $input, string $expected): void
    {
        self::assertSame($expected, Str::shortClassName($input));
    }

    public function testAnonymousClassYieldsTheLiteralAnonymousMarker(): void
    {
        // The runtime name embeds a NUL byte and the defining FILE PATH — backslash-separated
        // on Windows — so tail-after-last-separator would be a platform-dependent path
        // fragment. The contract is one deterministic answer on every platform.
        self::assertSame('class@anonymous', Str::shortClassName(new class () {}));
    }

    public function testEmptyStringIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Str::shortClassName('');
    }

    public function testTrailingSeparatorIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no class name after the final');

        Str::shortClassName('Acme\\Namespace\\');
    }
}
