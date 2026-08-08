<?php

declare(strict_types=1);

namespace D4np\Utils\Errors;

use D4np\Utils\Support\UtilsException;
use OutOfBoundsException;
use Psr\Log\LoggerInterface;

/**
 * Builds a named set of channels from one configuration array (spec FR-42, RFC-0002).
 *
 * This is the class that answers the surveyed estate's largest single duplication: eight static
 * logger properties per service class, re-created in every constructor, each pairing a destination
 * with a level and an enabled flag — 160 factory calls across twenty classes, all of it
 * copy-pasted, and a channel's floor changeable only by editing every class that mentioned it.
 * Here the wiring is data, resolved once:
 *
 * ```php
 * $channels = LoggerFactory::fromArray([
 *     'app'   => ['destination' => '/var/log/app.log', 'level' => 'info'],
 *     'audit' => ['destinations' => ['/var/log/audit.log', 'php://stdout'], 'level' => Level::Notice],
 *     'trace' => ['destination' => '/var/log/trace.log', 'enabled' => false],
 * ]);
 *
 * $container->singleton(LoggerInterface::class, fn () => $channels->channel('app'));
 * ```
 *
 * **Every channel is built eagerly, and that is the feature.** {@see Logger} refuses an unwritable
 * destination at construction so the failure lands where the misconfiguration is (ADR-0029);
 * deferring construction to the first record would undo exactly that, and the first record is
 * typically emitted while something else is already going wrong.
 *
 * **A disabled channel is still validated.** Its destination is constructed — which is what checks
 * writability, with no side effect on the filesystem — and then deliberately discarded in favour of
 * an empty {@see MultiLogger}. So `enabled: false` changes *where records go* and nothing else: it
 * cannot hide a bad path until the day someone turns the channel on. An unknown *level* is refused
 * on a disabled channel too, though not for the reason the empty fan-out suggests: the
 * {@see LevelFilteredLogger} wrapping the sink validates before the sink is reached, so a
 * `NullLogger` there would behave identically — planted, and the suite stayed green (ADR-0055 D4).
 * The empty fan-out is kept because one sink type differing only in breadth reads more plainly than
 * a branch that changes the class.
 *
 * **Unknown keys are refused rather than ignored** — the strict-hydration stance of ADR-0008 applied
 * to configuration. A logging config is exactly where a silently-ignored `levels: 'info'` typo is
 * worst: nothing fails, and the records are simply not there when they are needed.
 *
 * **Every channel gets its floor from a {@see LevelFilteredLogger}**, including single-destination
 * ones that {@see Logger} could have filtered by itself. One shape means one place where "below the
 * floor" is decided, and it is the shape NFR-14 measures.
 */
final class LoggerFactory
{
    /** Every key a channel may declare. Anything else is a typo, and typos are refused. */
    private const KEYS = ['destination', 'destinations', 'level', 'enabled'];

    /**
     * @param array<string, LoggerInterface> $channels
     */
    private function __construct(private readonly array $channels)
    {
    }

    /**
     * @param array<string, mixed> $config channel name => channel definition
     *
     * @throws UtilsException if a definition is malformed, or a destination cannot be written to
     */
    public static function fromArray(array $config): self
    {
        $channels = [];

        foreach ($config as $name => $definition) {
            if (!\is_array($definition)) {
                throw new UtilsException(\sprintf(
                    'Channel "%s" must be defined by an array of settings, got %s.',
                    $name,
                    \get_debug_type($definition),
                ));
            }

            /** @var array<string, mixed> $definition */
            $channels[$name] = self::channelFrom($name, $definition);
        }

        return new self($channels);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @throws UtilsException
     */
    private static function channelFrom(string $name, array $definition): LoggerInterface
    {
        foreach (\array_keys($definition) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                throw new UtilsException(\sprintf(
                    'Channel "%s" has an unknown setting "%s". Expected any of: %s. Refused rather '
                    . 'than ignored: a logging channel that silently drops a mistyped setting is '
                    . 'discovered by the records that are not there.',
                    $name,
                    $key,
                    \implode(', ', self::KEYS),
                ));
            }
        }

        $destinations = self::destinationsOf($name, $definition);
        $floor = self::floorOf($name, $definition['level'] ?? Level::Debug);
        $enabled = $definition['enabled'] ?? true;

        if (!\is_bool($enabled)) {
            throw new UtilsException(\sprintf(
                'Channel "%s" has a non-boolean "enabled" setting (%s). A string like "false" would '
                . 'be truthy, which is the failure this refusal exists to prevent.',
                $name,
                \get_debug_type($enabled),
            ));
        }

        // Constructed even when the channel is disabled: this is what validates the destinations,
        // and it must not depend on the flag. Nothing is written and no file is created.
        $writers = \array_map(
            static fn (string $destination): Logger => new Logger($destination),
            $destinations,
        );

        // An empty fan-out for a disabled channel: it still validates the level of every record it
        // is given, so turning the channel on cannot newly reveal a bad level. See MultiLogger.
        $sink = $enabled ? new MultiLogger(...$writers) : new MultiLogger();

        return new LevelFilteredLogger($sink, $floor);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return non-empty-list<string>
     *
     * @throws UtilsException
     */
    private static function destinationsOf(string $name, array $definition): array
    {
        $single = \array_key_exists('destination', $definition);
        $many = \array_key_exists('destinations', $definition);

        if ($single && $many) {
            throw new UtilsException(\sprintf(
                'Channel "%s" declares both "destination" and "destinations". Pick one: two answers '
                . 'to where records go is a question the library must not decide for you.',
                $name,
            ));
        }

        if ($single) {
            $value = $definition['destination'];

            if (!\is_string($value) || $value === '') {
                throw new UtilsException(\sprintf(
                    'Channel "%s" needs a non-empty string "destination", got %s.',
                    $name,
                    \get_debug_type($value),
                ));
            }

            return [$value];
        }

        if (!$many) {
            throw new UtilsException(\sprintf(
                'Channel "%s" declares no destination. Use "destination" for one, or "destinations" '
                . 'for a fan-out.',
                $name,
            ));
        }

        $values = $definition['destinations'];

        if (!\is_array($values) || $values === []) {
            throw new UtilsException(\sprintf(
                'Channel "%s" needs a non-empty list of "destinations", got %s. An empty list would '
                . 'be a channel that discards everything while claiming to be enabled — say '
                . '"enabled" => false instead, which says so.',
                $name,
                \get_debug_type($values),
            ));
        }

        $destinations = [];

        foreach ($values as $value) {
            if (!\is_string($value) || $value === '') {
                throw new UtilsException(\sprintf(
                    'Channel "%s" has a non-string entry in "destinations" (%s).',
                    $name,
                    \get_debug_type($value),
                ));
            }

            $destinations[] = $value;
        }

        return $destinations;
    }

    /**
     * @throws UtilsException
     */
    private static function floorOf(string $name, mixed $level): Level
    {
        if ($level instanceof Level) {
            return $level;
        }

        if (\is_string($level) && Level::rankOf($level) !== null) {
            return Level::from($level);
        }

        // A UtilsException, not PSR-3's InvalidArgumentException: this is a wiring failure being
        // reported at wiring time, not a bad level handed to a logging call.
        throw new UtilsException(\sprintf(
            'Channel "%s" has an unusable "level" (%s). Expected a Level or one of: %s.',
            $name,
            \is_scalar($level) ? \var_export($level, true) : \get_debug_type($level),
            \implode(', ', Level::names()),
        ));
    }

    /**
     * @throws OutOfBoundsException if no channel of that name was configured
     */
    public function channel(string $name): LoggerInterface
    {
        // Throws rather than returning a null logger: a channel nobody configured is a wiring bug,
        // and answering it with a working-looking logger would lose every record it was given.
        // Same explicit-miss stance as Support\Lookup::label().
        return $this->channels[$name] ?? throw new OutOfBoundsException(\sprintf(
            'No logging channel named "%s". Configured: %s.',
            $name,
            $this->channels === [] ? '(none)' : \implode(', ', $this->names()),
        ));
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return \array_keys($this->channels);
    }
}
