<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

use InvalidArgumentException;

/**
 * A rolling counter persisted to a file, safe across processes (spec r3 FR-32, RFC-0002;
 * ADR-0038).
 *
 * The estate's version generated a daily identifier from a `.state` file holding
 * `window|counter`, checked its ceiling by hand, and — because the file sat beside each
 * deployed endpoint — kept **one counter per deploy folder** while every folder minted
 * identifiers into the same table. This class keeps the shape and fixes what made it unsafe:
 *
 * - **The whole read-modify-write happens under one exclusive lock** ({@see File::update()}).
 *   Read-then-write through two separately locked calls loses an increment whenever two
 *   processes interleave, and a lost increment in a sequence is a duplicate identifier.
 * - **The cap is enforced, and exceeding it throws** {@see SequenceExhaustedException}.
 *   Wrapping to `1` would re-issue live identifiers.
 * - **A corrupt state file is refused, not reset.** Resetting is the reflex, and it is the
 *   dangerous one: it re-issues every number in the window.
 *
 * The **window** is supplied by the caller as an opaque string — a date, a day-of-year, a
 * shift name. Keeping the calendar out of the class is deliberate: the estate's helper called
 * `date_default_timezone_set()` as a side effect of generating an identifier, which changed
 * the timezone for everything else in the request.
 *
 * **Recorded limit:** the class cannot order opaque windows, so *any* change of window resets
 * the counter — including a change to an earlier one. A caller that supplies a window going
 * backwards (a clock stepped back, a hand-passed constant) re-issues numbers already used.
 * Callers must supply a monotonically advancing window; {@see self::peek()} exists so this is
 * observable.
 */
final class FileSequence
{
    private const SEPARATOR = '|';

    /**
     * @param string $path where the state is kept. It holds one line, `window|counter`, and
     *                     nothing else; a sidecar `.lock` file lives beside it (ADR-0005).
     * @param int    $cap  the highest number this sequence may issue within one window
     *
     * @throws InvalidArgumentException if `$cap` is below 1
     */
    public function __construct(
        private readonly string $path,
        private readonly int $cap,
    ) {
        if ($cap < 1) {
            throw new InvalidArgumentException(sprintf('$cap must be >= 1, got %d.', $cap));
        }
    }

    /**
     * The next number in `$window`: one more than the last issued in that window, or `1` when
     * the window is new.
     *
     * @throws SequenceExhaustedException if the next number would exceed the cap
     * @throws FileException              if the state file cannot be read, parsed or replaced
     * @throws InvalidArgumentException   if `$window` is empty or holds a reserved character
     */
    public function next(string $window): int
    {
        self::guardWindow($window);

        $issued = 0;

        File::update($this->path, function (string $current) use ($window, &$issued): string {
            [$storedWindow, $counter] = $this->parse($current);

            $next = $storedWindow === $window ? $counter + 1 : 1;

            if ($next > $this->cap) {
                throw new SequenceExhaustedException(sprintf(
                    'Sequence "%s" is exhausted for window "%s": %d of %d issued. It will not '
                    . 'wrap, because wrapping re-issues identifiers that are already in use.',
                    $this->path,
                    $window,
                    $counter,
                    $this->cap,
                ));
            }

            $issued = $next;

            return $window . self::SEPARATOR . $next . "\n";
        });

        return $issued;
    }

    /**
     * The last number issued in `$window`, or `0` when none has been — without issuing one.
     *
     * Deliberately **not** locked: a peek is advisory by nature, and any value it returns may
     * be stale by the time the caller reads it. Do not branch on it to decide whether
     * {@see self::next()} will succeed; call `next()` and catch its refusal.
     *
     * @throws FileException if the state file exists but cannot be read or parsed
     */
    public function peek(string $window): int
    {
        if (!is_file($this->path)) {
            return 0;
        }

        [$storedWindow, $counter] = $this->parse(File::read($this->path));

        return $storedWindow === $window ? $counter : 0;
    }

    /**
     * How many numbers `$window` has left. Advisory, for the same reason as {@see self::peek()}.
     *
     * @throws FileException
     */
    public function remaining(string $window): int
    {
        return $this->cap - $this->peek($window);
    }

    /**
     * Decompose the stored record.
     *
     * An absent or blank file means "nothing issued yet", which is the legitimate first-run
     * state and also what a deploy script that touches the file produces. Anything else that
     * does not parse is **corrupt and refused**: silently treating it as a fresh start would
     * re-issue the whole window.
     *
     * @return array{string, int} the stored window (`''` when none) and its counter
     *
     * @throws FileException
     */
    private function parse(string $raw): array
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return ['', 0];
        }

        $parts = explode(self::SEPARATOR, $trimmed);

        if (\count($parts) !== 2 || $parts[0] === '' || preg_match('/\A\d+\z/', $parts[1]) !== 1) {
            throw new FileException(sprintf(
                'Sequence state file "%s" is corrupt: expected "window%scounter", found "%s". '
                . 'Refusing to treat it as a fresh start, which would re-issue every number '
                . 'in the window.',
                $this->path,
                self::SEPARATOR,
                $trimmed,
            ));
        }

        return [$parts[0], (int) $parts[1]];
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function guardWindow(string $window): void
    {
        if ($window === '') {
            throw new InvalidArgumentException('$window must not be empty.');
        }

        if (str_contains($window, self::SEPARATOR) || preg_match('/[\r\n]/', $window) === 1) {
            throw new InvalidArgumentException(sprintf(
                '$window must not contain "%s" or a line break: the state file stores '
                . '"window%scounter" on one line, and either would make it unparseable.',
                self::SEPARATOR,
                self::SEPARATOR,
            ));
        }
    }
}
