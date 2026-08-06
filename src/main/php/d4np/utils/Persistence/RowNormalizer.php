<?php

declare(strict_types=1);

namespace D4np\Utils\Persistence;

use D4np\Utils\Support\DatabaseException;
use D4np\Utils\Support\Str;
use D4np\Utils\Support\UtilsThrowable;

/**
 * The row-cleanup pipeline as one explicit, immutable policy (spec r3 FR-36, RFC-0002;
 * ADR-0042).
 *
 * The surveyed estate carried this pipeline — legacy-charset transcode, trim, collapse
 * internal whitespace, empty-string to `null` — **seventeen times**, once per data-access
 * class, each copy free to drift from the others. None of them said what they were doing;
 * every one of them just did it. This class is that pipeline named, configured once, and
 * testable: {@see \D4np\Utils\Tests\Persistence\RowNormalizerTest} walks its policy table
 * (suite **T-15**).
 *
 * **Every step that changes data is opt-in, except trimming.** That asymmetry is the
 * decision, not an oversight (ADR-0042):
 *
 * | step | default | why |
 * |---|---|---|
 * | transcode | **off** (`$fromEncoding = null`) | most databases are already UTF-8; guessing an encoding corrupts more than it fixes |
 * | trim | **on** | trailing spaces in a fixed-width `CHAR` column are a storage artifact, not data — this is the one case where *not* acting is what surprises people |
 * | collapse internal whitespace | **off** | `"a  b"` → `"a b"` alters content a caller may have meant |
 * | blank → `null` | **off** | `''` and `NULL` are different values in SQL, and conflating them silently breaks a `NOT NULL` round trip |
 *
 * That ordering follows spec §1's rejection of blanket input mutilation, and the same rule
 * ADR-0037 applied to the CSV formula guard and ADR-0019 to invalid-UTF-8 substitution: a
 * library may not quietly rewrite a consumer's data, but it may offer to.
 *
 * **What it never touches:** keys (a column name is schema, not data), and any value that is
 * not a string — `int`, `float`, `bool`, `null` and resources (a BLOB stream handed back by
 * the driver) pass through by identity. Transcoding a resource would destroy it.
 *
 * **Strict by default about failure, too.** With `$fromEncoding` set and `$lossy = false`,
 * a value the target encoding cannot represent raises {@see DatabaseException} naming the
 * column, rather than losing bytes — {@see Str::transcode()}'s own stance, and the direct
 * answer to the estate's `//IGNORE`, which dropped unconvertible characters silently in all
 * seventeen copies.
 */
final class RowNormalizer
{
    /**
     * @param ?string $fromEncoding        the encoding values arrive in, or `null` to do no
     *                                     transcoding at all
     * @param bool    $trim               strip leading and trailing whitespace
     * @param bool    $collapseWhitespace collapse internal runs of whitespace to one space
     *                                     (implies trimming — {@see Str::collapseWhitespace()}
     *                                     trims as well)
     * @param bool    $blankToNull        turn a value that is empty, or all whitespace, into
     *                                     `null`
     * @param bool    $lossy              drop characters the target encoding cannot represent
     *                                     instead of refusing the value; only meaningful with
     *                                     `$fromEncoding`
     */
    public function __construct(
        private readonly ?string $fromEncoding = null,
        private readonly bool $trim = true,
        private readonly bool $collapseWhitespace = false,
        private readonly bool $blankToNull = false,
        private readonly bool $lossy = false,
        private readonly string $toEncoding = 'UTF-8',
    ) {
    }

    /**
     * Apply the policy to every string value in one row, in the fixed order below.
     *
     * The order is load-bearing rather than incidental: **transcoding comes first**, because
     * trimming or collapsing bytes that are not yet in the target encoding is only safe when
     * the source happens to be ASCII-compatible. The estate's helper trimmed *before*
     * converting, which is harmless for its single-byte source encoding and silently
     * destructive for any multibyte one — a latent bug this ordering removes rather than
     * inherits.
     *
     * Blank-to-`null` comes last, so it sees the value the earlier steps produced: a
     * `CHAR(20)` holding only spaces is blank after trimming and not before it.
     *
     * @param array<string, mixed> $row as the driver returned it
     *
     * @return array<string, mixed> the same keys, in the same order, with string values
     *                              normalized
     *
     * @throws DatabaseException if a value does not survive transcoding in strict mode
     */
    public function normalize(array $row): array
    {
        foreach ($row as $column => $value) {
            if (!is_string($value)) {
                // int, float, bool, null and resources (BLOB streams) are returned by
                // identity: none of the steps below is meaningful for them, and transcoding
                // a resource would destroy it.
                continue;
            }

            $row[$column] = $this->normalizeValue($value, $column);
        }

        return $row;
    }

    /**
     * @throws DatabaseException
     */
    private function normalizeValue(string $value, string $column): ?string
    {
        if ($this->fromEncoding !== null) {
            try {
                $value = Str::transcode($value, $this->fromEncoding, $this->toEncoding, $this->lossy);
            } catch (UtilsThrowable $e) {
                // Rethrown rather than propagated as-is for one reason: which column failed.
                // `Str::transcode()` cannot know, and a row-wide failure with no column name
                // is the difference between a fixable report and a guessing game — the
                // estate's seventeen silent catches being the extreme version of the same
                // problem. The original is preserved as the cause.
                throw new DatabaseException(sprintf(
                    'Column "%s": value did not survive transcoding from "%s" to "%s". %s',
                    $column,
                    $this->fromEncoding,
                    $this->toEncoding,
                    $e->getMessage(),
                ), 0, $e);
            }
        }

        if ($this->collapseWhitespace) {
            // Collapsing trims as well, so this subsumes $trim rather than conflicting with
            // it — checked in that order so enabling both is not a contradiction.
            $value = Str::collapseWhitespace($value);
        } elseif ($this->trim) {
            $value = trim($value);
        }

        return $this->blankToNull ? Str::nullIfBlank($value) : $value;
    }
}
