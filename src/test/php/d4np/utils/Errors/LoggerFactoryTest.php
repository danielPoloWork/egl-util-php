<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Errors;

use D4np\Utils\Errors\Level;
use D4np\Utils\Errors\LevelFilteredLogger;
use D4np\Utils\Errors\LoggerFactory;
use D4np\Utils\Support\UtilsException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Channels from configuration (spec FR-42, suite T-12): what a config may say, and what it may not.
 */
#[CoversClass(LoggerFactory::class)]
#[Group('T-12')]
final class LoggerFactoryTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = \sys_get_temp_dir() . '/d4np-channels-' . \bin2hex(\random_bytes(4));
        \mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        if ($this->directory === '' || !\is_dir($this->directory)) {
            return;
        }

        foreach ((array) \glob($this->directory . '/*') as $file) {
            if (\is_string($file)) {
                \unlink($file);
            }
        }

        \rmdir($this->directory);
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name . '.log';
    }

    public function testBuildsOneLoggerPerNamedChannel(): void
    {
        $channels = LoggerFactory::fromArray([
            'app' => ['destination' => $this->path('app')],
            'audit' => ['destination' => $this->path('audit'), 'level' => 'notice'],
        ]);

        self::assertSame(['app', 'audit'], $channels->names());
        self::assertTrue($channels->has('app'));
        self::assertInstanceOf(LoggerInterface::class, $channels->channel('app'));
    }

    public function testAnEmptyConfigurationIsLegalAndYieldsNoChannels(): void
    {
        $channels = LoggerFactory::fromArray([]);

        self::assertSame([], $channels->names());
        self::assertFalse($channels->has('app'));
    }

    public function testAnUnknownChannelThrowsRatherThanReturningSomethingThatDiscards(): void
    {
        $channels = LoggerFactory::fromArray(['app' => ['destination' => $this->path('app')]]);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('No logging channel named "audit"');

        $channels->channel('audit');
    }

    public function testTheUnknownChannelMessageNamesWhatWasConfigured(): void
    {
        $channels = LoggerFactory::fromArray([
            'app' => ['destination' => $this->path('app')],
            'audit' => ['destination' => $this->path('audit')],
        ]);

        try {
            $channels->channel('trace');
            self::fail('expected a miss');
        } catch (OutOfBoundsException $e) {
            self::assertStringContainsString('app, audit', $e->getMessage());
        }
    }

    public function testTheFloorIsAppliedAndReadableAsAValue(): void
    {
        $channels = LoggerFactory::fromArray([
            'app' => ['destination' => $this->path('app'), 'level' => 'warning'],
            'typed' => ['destination' => $this->path('typed'), 'level' => Level::Error],
            'default' => ['destination' => $this->path('default')],
        ]);

        foreach (['app' => Level::Warning, 'typed' => Level::Error, 'default' => Level::Debug] as $name => $floor) {
            $logger = $channels->channel($name);
            self::assertInstanceOf(LevelFilteredLogger::class, $logger);
            self::assertSame($floor, $logger->floor(), "channel {$name}");
        }
    }

    public function testTheFloorActuallyFiltersWhatReachesTheFile(): void
    {
        $channels = LoggerFactory::fromArray([
            'app' => ['destination' => $this->path('app'), 'level' => 'warning'],
        ]);

        $channels->channel('app')->debug('below the floor');
        $channels->channel('app')->error('above the floor');

        $written = (string) \file_get_contents($this->path('app'));

        self::assertStringNotContainsString('below the floor', $written);
        self::assertStringContainsString('above the floor', $written);
    }

    public function testAFanOutChannelWritesToEveryDestination(): void
    {
        $channels = LoggerFactory::fromArray([
            'audit' => ['destinations' => [$this->path('one'), $this->path('two')]],
        ]);

        $channels->channel('audit')->notice('to both files');

        self::assertStringContainsString('to both files', (string) \file_get_contents($this->path('one')));
        self::assertStringContainsString('to both files', (string) \file_get_contents($this->path('two')));
    }

    public function testADisabledChannelWritesNothing(): void
    {
        $channels = LoggerFactory::fromArray([
            'trace' => ['destination' => $this->path('trace'), 'enabled' => false],
        ]);

        $channels->channel('trace')->emergency('must not be written');

        self::assertFileDoesNotExist($this->path('trace'));
    }

    /**
     * The decision that makes `enabled` safe to flip: turning a channel off changes *where records
     * go* and nothing else. With PSR-3's `NullLogger` as the disabled channel — the obvious
     * implementation — this level would be accepted in silence for as long as the channel stayed
     * off, and would start throwing the day somebody enabled it.
     */
    public function testADisabledChannelStillRejectsAnUnknownLevel(): void
    {
        $channels = LoggerFactory::fromArray([
            'trace' => ['destination' => $this->path('trace'), 'enabled' => false],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $channels->channel('trace')->log('verbose', 'a record');
    }

    /**
     * The same rule applied to the destination: a disabled channel is *built*, so a bad path is a
     * wiring failure now rather than a surprise on the day the channel is switched on.
     */
    public function testADisabledChannelStillValidatesItsDestination(): void
    {
        $this->expectException(UtilsException::class);

        LoggerFactory::fromArray([
            'trace' => [
                'destination' => $this->directory . '/no-such-directory/trace.log',
                'enabled' => false,
            ],
        ]);
    }

    public function testAnUnwritableDestinationFailsAtWiringTimeNotAtTheFirstRecord(): void
    {
        $this->expectException(UtilsException::class);

        LoggerFactory::fromArray([
            'app' => ['destination' => $this->directory . '/no-such-directory/app.log'],
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedChannels(): iterable
    {
        yield 'unknown setting' => [['destination' => '', 'levels' => 'info'], 'unknown setting "levels"'];
        yield 'no destination at all' => [['level' => 'info'], 'declares no destination'];
        yield 'both destination forms' => [
            ['destination' => '', 'destinations' => []],
            'declares both "destination" and "destinations"',
        ];
        yield 'empty destinations list' => [['destinations' => []], 'non-empty list of "destinations"'];
        yield 'destinations is not a list' => [['destinations' => 'one.log'], 'non-empty list of "destinations"'];
        yield 'a non-string destination entry' => [['destinations' => [42]], 'non-string entry'];
        yield 'an empty destination string' => [['destination' => ''], 'non-empty string "destination"'];
        yield 'a non-string destination' => [['destination' => 42], 'non-empty string "destination"'];
        yield 'an unknown level' => [['destination' => 'x.log', 'level' => 'verbose'], 'unusable "level"'];
        yield 'a non-string level' => [['destination' => 'x.log', 'level' => 3], 'unusable "level"'];
        yield 'a string enabled flag' => [['destination' => 'x.log', 'enabled' => 'false'], 'non-boolean "enabled"'];
        yield 'an integer enabled flag' => [['destination' => 'x.log', 'enabled' => 1], 'non-boolean "enabled"'];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[DataProvider('malformedChannels')]
    public function testAMalformedChannelIsRefusedAtWiringTime(array $definition, string $expected): void
    {
        // Paths are relative to the temp directory so the destination itself is valid where the
        // case is about something else; the cases about paths use values no directory can satisfy.
        if (isset($definition['destination']) && \is_string($definition['destination']) && $definition['destination'] !== '') {
            $definition['destination'] = $this->path('app');
        }

        $this->expectException(UtilsException::class);
        $this->expectExceptionMessageMatches('/' . \preg_quote($expected, '/') . '/');

        LoggerFactory::fromArray(['app' => $definition]);
    }

    public function testAChannelDefinedByANonArrayIsRefused(): void
    {
        $this->expectException(UtilsException::class);
        $this->expectExceptionMessage('must be defined by an array of settings, got string');

        LoggerFactory::fromArray(['app' => 'app.log']);
    }

    /**
     * `'false'` is the case worth its own test: every other malformed value is obviously wrong,
     * while a string `'false'` is *truthy*, so ignoring the type would leave a channel the operator
     * believes is off and which is writing every record.
     */
    public function testTheStringFalseIsRefusedRatherThanBeingTruthy(): void
    {
        try {
            LoggerFactory::fromArray([
                'app' => ['destination' => $this->path('app'), 'enabled' => 'false'],
            ]);
            self::fail('a string "enabled" must be refused');
        } catch (UtilsException $e) {
            self::assertStringContainsString('would be truthy', $e->getMessage());
        }
    }

    public function testTheUnknownSettingMessageNamesEveryAcceptedSetting(): void
    {
        try {
            LoggerFactory::fromArray(['app' => ['destination' => $this->path('app'), 'lvl' => 'info']]);
            self::fail('expected a refusal');
        } catch (UtilsException $e) {
            foreach (['destination', 'destinations', 'level', 'enabled'] as $key) {
                self::assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    public function testAStreamDestinationIsAccepted(): void
    {
        $channels = LoggerFactory::fromArray(['console' => ['destination' => 'php://output']]);

        \ob_start();
        $channels->channel('console')->info('to the console');

        self::assertStringContainsString('to the console', (string) \ob_get_clean());
    }

    /**
     * The estate's shape, end to end: one config array, several channels, each with its own floor
     * and destination — replacing eight logger properties per class re-created in every constructor.
     */
    public function testTheWholeWiringIsOneArray(): void
    {
        $channels = LoggerFactory::fromArray([
            'app' => ['destination' => $this->path('app'), 'level' => 'info'],
            'audit' => ['destinations' => [$this->path('audit'), 'php://output'], 'level' => Level::Notice],
            'trace' => ['destination' => $this->path('trace'), 'enabled' => false],
        ]);

        \ob_start();
        $channels->channel('app')->debug('dropped: below info');
        $channels->channel('app')->info('kept');
        $channels->channel('audit')->info('dropped: below notice');
        $channels->channel('audit')->alert('audited');
        $channels->channel('trace')->emergency('discarded: channel off');
        $console = (string) \ob_get_clean();

        $app = (string) \file_get_contents($this->path('app'));
        $audit = (string) \file_get_contents($this->path('audit'));

        self::assertStringNotContainsString('dropped', $app);
        self::assertStringContainsString('kept', $app);
        self::assertStringNotContainsString('dropped', $audit);
        self::assertStringContainsString('audited', $audit);
        self::assertStringContainsString('audited', $console, 'the fan-out reaches the stream too');
        self::assertFileDoesNotExist($this->path('trace'));
    }
}
