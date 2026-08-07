<?php

declare(strict_types=1);

namespace D4np\Utils\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The `packages/utils-psr7-bridge/` boundary, asserted from the core's suite (ADR-0033, spec 02 §2).
 *
 * **Why these live here rather than in the package's own suite.** The package's tests run only when
 * its CI job runs, and the invariant with the sharpest consequence — no `repositories` entry in the
 * committed manifest — breaks something nobody in this repository would ever see: a *standalone*
 * install of the published split package. A path repository pointing at `../../` resolves perfectly
 * inside the monorepo and is unresolvable everywhere else. So it is checked on every PR, from the
 * core suite, which always runs.
 *
 * These are file assertions, not imports: the core must never depend on the bridge, and deptrac's
 * `Bridge` layer makes that a build failure (verified — planting a real type reference produces
 * `Response must not depend on Psr7Bridge`).
 */
final class BridgePackageBoundaryTest extends TestCase
{
    private const PACKAGE = 'packages/utils-psr7-bridge';

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $path = self::path('composer.json');
        self::assertFileExists($path, 'the bridge package manifest is missing');

        $decoded = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 5) . '/' . self::PACKAGE . '/' . $relative;
    }

    /**
     * The core's manifest, for the comparisons that only mean something against it.
     *
     * @return array<string, mixed>
     */
    private static function coreManifest(): array
    {
        $decoded = \json_decode(
            (string) \file_get_contents(\dirname(__DIR__, 5) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * One top-level section of a manifest, narrowed to an array.
     *
     * Decoded JSON is `mixed` all the way down, and PHPStan at max is right to insist: a manifest
     * whose `require` is not an array is a manifest this test cannot reason about, and asserting
     * that once here beats casting at every call site.
     *
     * @param array<string, mixed> $manifest
     *
     * @return array<string, mixed>
     */
    private static function section(array $manifest, string $key): array
    {
        $value = $manifest[$key] ?? null;
        self::assertIsArray($value, "the manifest's `{$key}` section is missing or not an object");

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * **The one that protects consumers.** Spec 02 §6: the committed manifest never carries a
     * `repositories` entry. CI's PR mode injects a path repository into the *workspace* so the
     * contract suite runs against the working tree — and if that injection were ever committed, the
     * published package would point at `../../`, which exists only inside this monorepo. Every
     * standalone `composer require egl/utils-psr7-bridge` would fail, and nothing in this
     * repository would notice.
     */
    public function testTheCommittedManifestHasNoRepositoriesEntry(): void
    {
        self::assertArrayNotHasKey(
            'repositories',
            self::manifest(),
            'a committed `repositories` entry breaks every standalone install of the published '
            . 'package while resolving fine inside the monorepo — see spec 02 §6',
        );
    }

    /**
     * The core is required by a released constraint, never `@dev`. `@dev` in the committed file
     * would publish a package pinned to a moving target.
     */
    public function testTheCoreIsRequiredByAReleasedConstraint(): void
    {
        $require = self::section(self::manifest(), 'require');
        self::assertArrayHasKey('egl/utils', $require, 'the bridge must declare its core dependency');

        $constraint = $require['egl/utils'];
        self::assertIsString($constraint);
        self::assertStringNotContainsString('@dev', $constraint, 'spec 02 §2: never `@dev` committed');
        self::assertStringNotContainsString('dev-', $constraint);
    }

    /**
     * The bridge must not narrow the core's runtime floor: a consumer on PHP 8.1 can use the core,
     * so they can use the bridge.
     */
    public function testTheBridgeDoesNotNarrowTheCoresPhpFloor(): void
    {
        $bridge = self::section(self::manifest(), 'require');
        $core = self::section(self::coreManifest(), 'require');

        self::assertSame($core['php'] ?? null, $bridge['php'] ?? null, 'the floors must agree');
    }

    /**
     * PSR-7 and PSR-17 are the bridge's dependencies and must never appear in the core's — that is
     * NFR-08's "no third-party implementation dependencies in the core", and the entire reason the
     * bridge is a separate package (imported ADR-002).
     */
    public function testThePsrHttpDependenciesBelongToTheBridgeAndNotTheCore(): void
    {
        $bridge = self::section(self::manifest(), 'require');
        self::assertArrayHasKey('psr/http-message', $bridge);
        self::assertArrayHasKey('psr/http-factory', $bridge);

        $core = self::section(self::coreManifest(), 'require');
        self::assertArrayNotHasKey('psr/http-message', $core);
        self::assertArrayNotHasKey('psr/http-factory', $core);
    }

    /**
     * The namespace nests under `D4np\Utils\` so consumers read one vendor namespace; Composer
     * resolves the longest PSR-4 prefix, so this maps to the package and everything else under
     * `D4np\Utils\` stays the core's.
     */
    public function testThePsr4RootsMatchTheSpecifiedLayout(): void
    {
        $manifest = self::manifest();

        self::assertSame(
            ['D4np\\Utils\\Bridge\\Psr7\\' => 'src/main/php/d4np/utils/bridge/psr7/'],
            self::section($manifest, 'autoload')['psr-4'] ?? null,
        );
        self::assertSame(
            ['D4np\\Utils\\Bridge\\Psr7\\Tests\\' => 'src/test/php/d4np/utils/bridge/psr7/'],
            self::section($manifest, 'autoload-dev')['psr-4'] ?? null,
        );

        self::assertDirectoryExists(self::path('src/main/php/d4np/utils/bridge/psr7'));
        self::assertDirectoryExists(self::path('src/test/php/d4np/utils/bridge/psr7'));
    }

    /**
     * The scaffold ships the quality bar and the package's own changelog — the boundary spec 02 §2
     * calls "a complete Composer package", not a directory with a manifest in it.
     */
    public function testTheScaffoldIsACompletePackage(): void
    {
        self::assertFileExists(self::path('README.md'));
        self::assertFileExists(self::path('CHANGELOG.md'));
        self::assertFileExists(self::path('phpstan.neon.dist'));
    }

    /**
     * The package name is the one RFC-0001 mapped imported ADR-002's `d4np/php-psr7-bridge` to, and
     * the one item 8.3 will register on Packagist. A rename after publication is a new package.
     */
    public function testThePackageIsNamedAsPublished(): void
    {
        self::assertSame('egl/utils-psr7-bridge', self::manifest()['name'] ?? null);
    }
}
