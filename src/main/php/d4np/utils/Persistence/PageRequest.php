<?php

declare(strict_types=1);

namespace D4np\Utils\Persistence;

use D4np\Utils\Support\DatabaseException;

/**
 * One window over a result set: which page, how large, and whether to count the whole (spec r19
 * FR-47, RFC-0003; ADR-0064).
 *
 * Pages are **1-based**, because that is what a page number means everywhere a human reads one —
 * a URL, a pagination control, a report footer. The 0-based offset the database wants is derived
 * here, once, so the off-by-one that every hand-rolled version eventually gets wrong lives in one
 * tested place instead of at each call site.
 *
 * **Nonsense is refused, never clamped.** A page of `0`, a negative page, a size of `0` — each
 * throws rather than being quietly rounded to something usable. Clamping is how an unvalidated
 * `?page=0` becomes page 1 and nobody ever learns the request was malformed; it is the same
 * reasoning that makes {@see TableGateway::findBy()} refuse an empty criteria array rather than
 * treat it as "match everything".
 */
final class PageRequest
{
    private function __construct(
        public readonly int $page,
        public readonly int $size,
        public readonly bool $withTotal,
    ) {
    }

    /**
     * A window of `$size` rows at 1-based page `$page`.
     *
     * `$withTotal` defaults to **true** because the overwhelmingly common reason to paginate is to
     * render "page 3 of 47", and a `Page` that could not answer that would send every consumer to
     * hand-write the count query this exists to remove. It costs a **second statement** per read —
     * see {@see Page} — so a caller who only needs the rows (an export loop, an infinite scroll)
     * turns it off deliberately rather than paying for an answer nobody reads.
     *
     * @throws DatabaseException if the page is below 1, the size is below 1, or the resulting
     *                            offset overflows PHP's integer range
     */
    public static function of(int $page, int $size, bool $withTotal = true): self
    {
        if ($page < 1) {
            throw new DatabaseException(\sprintf(
                'Page numbers are 1-based; got %d. It is refused rather than clamped to 1 because '
                . 'a page below 1 is what an unvalidated request parameter produces, and silently '
                . 'correcting it means nobody ever learns the request was malformed.',
                $page,
            ));
        }

        if ($size < 1) {
            throw new DatabaseException(\sprintf(
                'A page size must be at least 1; got %d. A size of 0 would produce a window that '
                . 'returns nothing while reporting a total, which is a harder bug to see than a '
                . 'refusal here.',
                $size,
            ));
        }

        // PHP does not trap integer overflow — it silently yields a float — so a page and size
        // whose product leaves the integer range would otherwise reach the driver as something
        // that is no longer an offset. Detected rather than assumed impossible: `?page=` accepts
        // whatever a caller passes it.
        //
        // Asked BEFORE multiplying, by division, rather than after by `is_int()` on the product:
        // PHPStan models `int * int` as `int` and therefore proves an after-the-fact check
        // always true, so that formulation reads as a guard while being one only at runtime. This
        // one is exact integer arithmetic the analyser cannot narrow away — and `$size >= 1` is
        // already established above, so `intdiv()` cannot divide by zero here.
        if ($page - 1 > \intdiv(\PHP_INT_MAX, $size)) {
            throw new DatabaseException(\sprintf(
                'Page %d at size %d overflows the largest offset PHP can represent (%d).',
                $page,
                $size,
                \PHP_INT_MAX,
            ));
        }

        return new self($page, $size, $withTotal);
    }

    /** The same window with the total suppressed — no second statement, no total on the {@see Page}. */
    public function withoutTotal(): self
    {
        return new self($this->page, $this->size, false);
    }

    /** The 0-based offset this page starts at — the form `LIMIT`/`OFFSET` wants. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }
}
