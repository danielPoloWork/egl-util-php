<?php

declare(strict_types=1);

namespace D4np\Utils\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

/**
 * Roadmap item 1.2 smoke test: proves the test harness itself is wired correctly —
 * the PHP version floor, and that Composer's autoloader resolves the package's PSR-4
 * prefix to the expected source tree. Deliberately silent on any component's behavior;
 * that belongs to each group's own milestone (M2-M6).
 */
final class BootstrapTest extends TestCase
{
    public function testPhpVersionMeetsTheDeclaredFloor(): void
    {
        self::assertTrue(
            \version_compare(PHP_VERSION, '8.1.0', '>='),
            \sprintf('PHP %s does not meet the composer.json floor of >=8.1', PHP_VERSION)
        );
    }

    public function testComposerAutoloadsThePsr4Prefix(): void
    {
        // PHPUnit's own bootstrap already required vendor/autoload.php, so a second
        // require returns `true`, not the ClassLoader — recover the already-registered
        // instance from the SPL autoload stack instead of relying on first-load semantics.
        $loader = null;
        foreach (\spl_autoload_functions() as $callback) {
            if (\is_array($callback) && $callback[0] instanceof ClassLoader) {
                $loader = $callback[0];
                break;
            }
        }

        self::assertInstanceOf(ClassLoader::class, $loader, 'no Composer ClassLoader is registered');

        $prefixes = $loader->getPrefixesPsr4();

        self::assertArrayHasKey('D4np\\Utils\\', $prefixes, 'the production PSR-4 prefix is not registered');

        $mainDir = \realpath($prefixes['D4np\\Utils\\'][0]);
        $expected = \realpath(\dirname(__DIR__, 5) . '/src/main/php/d4np/utils');

        self::assertSame($expected, $mainDir, 'D4np\\Utils\\ does not map to src/main/php/d4np/utils/');
    }
}
