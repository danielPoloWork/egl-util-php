<?php

declare(strict_types=1);

namespace D4np\Utils\Persistence;

use D4np\Utils\Dto\DataTransferObject;
use LogicException;

/**
 * One window of hydrated rows, plus what is known about the whole (spec r19 FR-47, RFC-0003;
 * ADR-0064).
 *
 * `@template` genericity is **static-analysis only** — PHPStan at max level checks that a
 * `Page<Customer>` yields `Customer` instances, and nothing enforces it at runtime. The same
 * honest limit {@see \D4np\Utils\Dto\Collection} carries, and stated here for the same reason:
 * a generic that a reader assumes is checked at runtime is worse than one they know is not.
 *
 * **The total is optional, and asking for one that was never counted throws.** A `Page` built from
 * a {@see PageRequest::withoutTotal()} request has no total, and `total()` refuses rather than
 * answering `0` or `null` — a "0" that means "not counted" renders as "0 results" in a template
 * and is indistinguishable from an empty table. `hasTotal()` and `totalOr()` are the tolerant
 * forms, the same three-way missing-value policy {@see \D4np\Utils\Support\Lookup} established.
 *
 * @template T of DataTransferObject
 */
final class Page
{
    /**
     * @param list<T>  $items the rows in this window, in the query's order
     * @param int|null $total the rows the query matches in total, or `null` when not counted
     *
     * @internal construct these through {@see Repository::fetchPage()}; the constructor takes an
     *           already-validated window and does no checking of its own
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $size,
        private readonly ?int $total = null,
    ) {
    }

    /** Whether this page was built from a request that asked for the total. */
    public function hasTotal(): bool
    {
        return $this->total !== null;
    }

    /**
     * The rows the query matches in total, ignoring the window.
     *
     * @throws LogicException if the request suppressed the total — the count was never issued, so
     *                        there is no number to return and inventing one would be a lie a
     *                        template renders as fact
     */
    public function total(): int
    {
        if ($this->total === null) {
            throw new LogicException(
                'This page was requested without a total (PageRequest::withoutTotal()), so no '
                . 'COUNT was issued and there is no total to report. Use hasTotal() to check, '
                . 'totalOr() to substitute, or request the page with the total enabled.',
            );
        }

        return $this->total;
    }

    /** The total, or `$default` when it was not counted. */
    public function totalOr(int $default): int
    {
        return $this->total ?? $default;
    }

    /**
     * How many pages the total divides into, or `null` when there is no total.
     *
     * Returns `1` for an empty result set rather than `0`: page 1 of an empty table exists and is
     * the page a consumer is looking at when they see "no results", and "page 1 of 0" is a string
     * no interface wants to render.
     */
    public function pageCount(): ?int
    {
        if ($this->total === null) {
            return null;
        }

        return \max(1, (int) \ceil($this->total / $this->size));
    }

    /**
     * Whether another page follows this one, or `null` when there is no total to decide it from.
     *
     * Deliberately **not** guessed from a full window: "this page is full, so there is probably
     * more" is wrong exactly when the total is an exact multiple of the size, which is the case a
     * consumer hits on the last page of every evenly divisible table.
     */
    public function hasNextPage(): ?bool
    {
        if ($this->total === null) {
            return null;
        }

        return $this->page * $this->size < $this->total;
    }

    /** Whether this window came back empty — a fact about the page, never about the table. */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** How many rows this window actually holds, which is at most the requested size. */
    public function count(): int
    {
        return \count($this->items);
    }
}
