<?php

declare(strict_types=1);

namespace D4np\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `packages/*-bridge/` boundaries, asserted from the core's suite (ADR-0033, spec 02/03 §2).
 *
 * **Why these live here rather than in each package's own suite.** A package's tests run only when
 * its CI job runs, and the invariant with the sharpest consequence — no `repositories` entry in the
 * committed manifest — breaks something nobody in this repository would ever see: a *standalone*
 * install of the published split package. A path repository pointing at `../../` resolves perfectly
 * inside the monorepo and is unresolvable everywhere else. So it is checked on every PR, from the
 * core suite, which always runs.
 *
 * These are file assertions, not imports: the core must never depend on a bridge, and deptrac's
 * `Bridge` layer makes that a build failure (verified — planting a real type reference produces
 * `Response must not depend on Psr7Bridge`).
 *
 * **Data-driven since issue #93 added the second bridge.** Every test takes the package as a
 * parameter, so a third one is a row in {@see self::packages()} and needs no new test. The
 * alternative — a copy of this file per package — is how one of them quietly stops being checked:
 * the copy nobody edits is the copy whose invariant rots.
 */
final class BridgePackageBoundaryTest extends TestCase
{
    /**
     * Every bridge package, and the facts about each that are its own rather than the pattern's.
     *
     * @return iterable<string, array{string, string, string, list<string>}>
     *         published name, path, namespace segment, PSR packages it owns and the core must not
     */
    public static function packages(): iterable
    {
        yield 'psr7' => [
            'egl/utils-psr7-bridge',
            'packages/utils-psr7-bridge',
            'Psr7',
            ['psr/http-message', 'psr/http-factory'],
        ];

        yield 'psr18' => [
            'egl/utils-psr18-bridge',
            'packages/utils-psr18-bridge',
            'Psr18',
            ['psr/http-client', 'psr/http-factory', 'psr/http-message'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifest(string $package): array
    {
        $path = self::path($package, 'composer.json');
        self::assertFileExists($path, "the {$package} manifest is missing");

        $decoded = \json_decode((string) \file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function path(string $package, string $relative): string
    {
        return \dirname(__DIR__, 5) . '/' . $package . '/' . $relative;
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
     * standalone `composer require` would fail, and nothing in this repository would notice.
     *
     * @param list<string> $psrPackages
     */
    #[DataProvider('packages')]
    public function testTheCommittedManifestHasNoRepositoriesEntry(
        string $name,
        string $package,
        string $namespace,
        array $psrPackages,
    ): void {
        self::assertArrayNotHasKey(
            'repositories',
            self::manifest($package),
            'a committed `repositories` entry breaks every standalone install of the published '
            . 'package while resolving fine inside the monorepo — see spec 02 §6',
        );
    }

    /**
     * The core is required by a released constraint, never `@dev`. `@dev` in the committed file
     * would publish a package pinned to a moving target.
     *
     * @param list<string> $psrPackages
     */
    #[DataProvider('packages')]
    public function testTheCoreIsRequiredByAReleasedConstraint(
        string $name,
        string $package,
        string $namespace,
        array $psrPackages,
    ): void {
        $require = self::section(self::manifest($package), 'require');
        self::assertArrayHasKey('egl/utils', $require, 'the bridge must declare its core dependency');

        $constraint = $require['egl/utils'];
        self::assertIsString($constraint);
        self::assertStringNotContainsString('@dev', $constraint, 'spec 02 §2: never `@dev` committed');
        self::assertStringNotContainsString('dev-', $constraint);
    }

    /**
     * The bridge must not narrow the core's runtime floor: a consumer on PHP 8.1 can use the core,
     * so they can use the bridge.
     *
     * @param list<string> $psrPackages
     */
    #[DataProvider('packages')]
    public function testTheBridgeDoesNotNarrowTheCoresPhpFloor(
        string $name,
        string $package,
        string $namespace,
        array $psrPackages,
    ): void {
        $bridge = self::section(self::manifest($package), 'require');
        $core = self::section(self::coreManifest(), 'require');

        self::assertSame($core['php'] ?? null, $bridge['php'] ?? null, 'the floors must agree');
    }

    /**
     * The PSR packages are each bridge's dependencies and must never appear in the core's — that is
     * NFR-08's "no third-party implementation dependencies in the core", and the entire reason a
     * bridge is a separate package (imported ADR-002).
     *
     * @param list<string> $psrPackages
     */
    #[DataProvider('packages')]
    public function testThePsrDependenciesBelongToTheBridgeAndNotTheCore(
        string $name,
        string $package,
        string $namespace,
        array $psrPackages,
    ): void {
        $bridge = self::section(self::manifest($package), 'require');
        $core = self::section(self::coreManifest(), 'require');

        foreach ($psrPackages as $psr) {
            self::assertArrayHasKey($psr, $bridge, "{$name} must declare {$psr}");
            self::assertArrayNotHasKey($psr, $core, "the core must never require {$psr}");
        }
    }

    /**
     * The namespace nests under `D4np\Utils\` so consumers read one vendor namespace; Composer
     * resolves the longest PSR-4 prefix, so this maps to the package and everything else under
     * `D4np\Utils\` stays the core's.
     *
     * @param list<string> $psrPackages
     */
    #[DataProvider('packages')]
    public function testThePsr4RootsMatchTheSpecifiedLayout(
        string $name,
        string $package,
        string $namespace,
        array $psrPackages,
    ): void {
        $manifest = self::manifest($package);
        $directory = 'src/main/php/d4np/utils/bridge/' . \strtolower($namespace);
        $testDirectory = 'src/test/php/d4np/utils/bridge/' . \strtolower($namespace);

        self::assertSame(
            ['D4np\\Utils\\Bridge\\' . $namespace . '\\' => $directory . '/'],
            self::section($manifest, 'autoload')['psr-4'] ?? null,
        );
        self::assertSame(
            ['D4np\\Utils\\Bridge\\' . $namespace . '\\Tests\\' => $testDirectory . '/'],
            self::section($manifest, 'autoload-dev')['psr-4'] ?? null,
        );

        self::assertDirectoryExists(self::path($package, $directory));
        self::assertDirectoryExists(self::path($package, $testDirectory));
    }

    /**
     * The scaffold ships the quality bar and the package's own changelog — the boundary spec 02 §2
     * calls "a complete Composer package", not a directory with a manifest in it.
     *
     * @param list<string> $psrPackages
     */
    #[DataProvider('packages')]
    public function testTheScaffoldIsACompletePackage(
        string $name,
        string $package,
        string $namespace,
        array $psrPackages,
    ): void {
        self::assertFileExists(self::path($package, 'README.md'));
        self::assertFileExists(self::path($package, 'CHANGELOG.md'));
        self::assertFileExists(self::path($package, 'LICENSE'));
        self::assertFileExists(self::path($package, 'phpstan.neon.dist'));
        self::assertFileExists(self::path($package, 'phpunit.xml.dist'));
    }

    /**
     * The package name is the one it will be published under. A rename after publication is a new
     * package, so this is pinned rather than derived from the directory.
     *
     * @param list<string> $psrPackages
     */
    #[DataProvider('packages')]
    public function testThePackageIsNamedAsPublished(
        string $name,
        string $package,
        string $namespace,
        array $psrPackages,
    ): void {
        self::assertSame($name, self::manifest($package)['name'] ?? null);
    }

    /**
     * **Every bridge directory is covered by a row above.**
     *
     * The provider is a hand-written list, and a hand-written list is exactly what silently omits
     * the package added next week — which would leave it with none of the invariants above and
     * nothing to say so. This walks `packages/` instead and fails if it finds one nobody listed.
     */
    public function testEveryPackageOnDiskIsCoveredByTheProvider(): void
    {
        $root = \dirname(__DIR__, 5) . '/packages';
        self::assertDirectoryExists($root);

        $listed = [];
        foreach (self::packages() as [$name, $package, $namespace, $psrPackages]) {
            $listed[] = \basename($package);
        }

        $onDisk = [];
        foreach ((array) \scandir($root) as $entry) {
            if (\is_string($entry) && $entry !== '.' && $entry !== '..' && \is_dir($root . '/' . $entry)) {
                $onDisk[] = $entry;
            }
        }

        \sort($listed);
        \sort($onDisk);

        self::assertSame(
            $onDisk,
            $listed,
            'a package directory exists that no row in packages() covers, so none of this file\'s '
            . 'boundary invariants are being asserted for it',
        );
    }
}
