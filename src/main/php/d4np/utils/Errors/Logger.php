<?php

declare(strict_types=1);

namespace D4np\Utils\Errors;

use D4np\Utils\Support\Json;
use D4np\Utils\Support\UtilsException;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Throwable;

/**
 * A PSR-3 logger that appends one line per record to a file or a stream (spec FR-17, ADR-0029).
 *
 * `AbstractLogger` supplies the eight level methods, so the whole implementation is {@see log()}.
 *
 * **One line per record, always.** A log that sometimes spans lines cannot be read with `grep`,
 * `tail` or anything else that assumes a line is a record — and the moment that stops being true is
 * always the moment something has gone wrong and someone is in a hurry. The context is JSON, which
 * escapes newlines, so even an exception trace stays on its line (verified).
 *
 * **A `Throwable` in the context is converted explicitly, and that is not tidiness.**
 * `json_encode()` serialises an exception's *public* properties, of which there are none, so a
 * throwable passed through untouched encodes to `{}` — verified. Not an error, not a warning: every
 * detail silently gone, in the one record anybody would want to read.
 *
 * **The severity ordering is {@see Level}'s, and only Level's.** It used to be a private map here,
 * which was right while nothing else needed it — and would have become two copies of one rule the
 * moment {@see LevelFilteredLogger} arrived. That is the failure item 10.5 found in the identifier
 * allowlist, where the second copy was the weaker one.
 *
 * **The destination is validated at construction; writing never throws.** PSR-3 permits a throw only
 * for a bad level, and for good reason — a logger that throws while an exception handler is using it
 * turns a handled failure into a fatal one. So an unwritable destination is refused at wiring time,
 * where the stack trace points at the misconfiguration, and a later write failure is not allowed to
 * escalate.
 */
final class Logger extends AbstractLogger
{
    private readonly int $threshold;

    /**
     * True for `php://stdout` and friends, which **cannot be locked** — `file_put_contents()` with
     * `LOCK_EX` on a `php://` stream returns `false` and writes nothing, verified. A logger that
     * locked unconditionally would discard every console record in silence.
     */
    private readonly bool $isStream;

    /**
     * @param string      $destination  a file path, or a stream like `php://stdout`
     * @param Level|string $minimumLevel records less severe than this are dropped
     *
     * @throws UtilsException           if the destination cannot be written to
     * @throws InvalidArgumentException if `$minimumLevel` is not a PSR-3 level
     */
    public function __construct(
        private readonly string $destination,
        Level|string $minimumLevel = Level::Debug,
    ) {
        // Level|string rather than string: the enum is the vocabulary this library now speaks
        // (item 12.3), and widening is additive — every existing `new Logger($path, 'warning')`
        // call keeps working, since a PSR-3 level string is still accepted.
        $this->threshold = $minimumLevel instanceof Level
            ? $minimumLevel->rank()
            : Level::rankOf($minimumLevel) ?? throw Level::invalid($minimumLevel);
        $this->isStream = \str_contains($destination, '://');

        if (!$this->isStream && !self::isWritable($destination)) {
            throw new UtilsException(\sprintf(
                'The log destination "%s" cannot be written to. Checked at construction rather than '
                . 'at the first write, so this surfaces where the logger is wired instead of during '
                . 'the incident it was supposed to record.',
                $destination,
            ));
        }
    }

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     *
     * @throws InvalidArgumentException if `$level` is not a PSR-3 level — the one throw PSR-3 allows
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        // Normalised, then ranked-and-validated in one lookup — so the severity is known before
        // anything else reads the level, and an unknown one cannot reach the filter. Written as
        // three narrowing steps rather than a cast: PHPStan max is right that a `mixed` reaching
        // `strtoupper()` is unproven, and the proof belongs in the code, not in a suppression.
        $name = $level instanceof Level ? $level->value : $level;

        // PSR-3 requires exactly this: an unknown level throws, rather than being logged at some
        // guessed severity where it would be filtered by a rule nobody wrote. Two checks rather
        // than one condition, so the narrowing survives to `strtoupper()` below without a cast —
        // PHPStan max is right that a `mixed` arriving there is unproven, and the proof belongs in
        // the code rather than in a suppression.
        if (!\is_string($name)) {
            throw Level::invalid($level);
        }

        $rank = Level::rankOf($name);

        if ($rank === null) {
            throw Level::invalid($level);
        }

        if ($rank > $this->threshold) {
            return;
        }

        $line = \sprintf(
            '[%s] %s: %s',
            \gmdate('Y-m-d\TH:i:s\Z'),
            \strtoupper($name),
            self::interpolate((string) $message, $context),
        );

        if ($context !== []) {
            $line .= ' ' . Json::encode(self::encodable($context));
        }

        // Deliberately unchecked. A failure here must not escalate: see the class docblock.
        @\file_put_contents(
            $this->destination,
            $line . "\n",
            $this->isStream ? FILE_APPEND : FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * PSR-3's `{placeholder}` substitution.
     *
     * Only scalars and `Stringable`s are substituted; anything else is left as the literal
     * placeholder rather than becoming `Array` or a class name, so the record shows that a value was
     * expected there and did not arrive.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!\str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value === null || $value instanceof \Stringable) {
                $replacements['{' . $key . '}'] = match (true) {
                    $value === null => 'null',
                    \is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
            }
        }

        return $replacements === [] ? $message : \strtr($message, $replacements);
    }

    /**
     * The context, with anything JSON cannot represent faithfully replaced by something it can.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function encodable(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $safe[$key] = $value instanceof Throwable ? self::describe($value) : $value;
        }

        return $safe;
    }

    /**
     * A throwable as data.
     *
     * The trace is included: this is a server-side log, which is the one place a trace belongs — and
     * the contrast with {@see ExceptionHandler}, which withholds it from responses, is the whole
     * point of having both.
     *
     * @return array<string, mixed>
     */
    private static function describe(Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'previous' => $e->getPrevious() === null ? null : self::describe($e->getPrevious()),
        ];
    }


    private static function isWritable(string $path): bool
    {
        if (\is_file($path)) {
            return \is_writable($path);
        }

        $directory = \dirname($path);

        return \is_dir($directory) && \is_writable($directory);
    }
}
