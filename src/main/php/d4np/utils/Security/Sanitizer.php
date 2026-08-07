<?php

declare(strict_types=1);

namespace D4np\Utils\Security;

use D4np\Utils\Support\UtilsException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Input sanitization for the two cases where escaping is not the right tool (spec FR-09b, FR-10,
 * ADR-0021).
 *
 * {@see Escaper} covers RFC-0001's third security mechanism — escaping at output. This class
 * covers the two places that mechanism does not reach:
 *
 * - **{@see richText()}** — the fourth mechanism. When the *point* is to render user-authored
 *   HTML, escaping it defeats the feature, so the markup has to be parsed and reduced to an
 *   allowlist. RFC-0001 requires this be delegated, and names the delegate.
 * - **{@see sqlLikePattern()}** — a gap the *first* mechanism leaves open. Parameter binding
 *   stops a `LIKE` value injecting SQL, but it does nothing about the wildcards inside it: a
 *   user-supplied `%` still turns an intended lookup into a scan.
 */
final class Sanitizer
{
    /**
     * The escape character {@see sqlLikePattern()} uses, and the one a `LIKE` clause must declare.
     *
     * **`!`, not the more familiar `\`, and the choice is load-bearing.** A backslash is itself
     * special inside a SQL string literal on several drivers, so the `ESCAPE` clause has to be
     * written differently per driver — and `ESCAPE '\'` is not merely awkward but a *parse error*
     * on SQLite (`unrecognized token`), verified directly. `!` has no meaning inside a string
     * literal anywhere, so one spelling of the clause works on every driver.
     *
     * Exposed as a constant because the escaped pattern is useless without the matching `ESCAPE`
     * clause — see {@see sqlLikePattern()} — and a caller writing that clause by hand needs to
     * know which character to name.
     */
    public const LIKE_ESCAPE = '!';

    private static ?HtmlSanitizer $htmlSanitizer = null;

    private function __construct()
    {
        // Static-only: no instances.
    }

    /**
     * Neutralise the wildcards in a value destined for a `LIKE` pattern (spec FR-10).
     *
     * **Binding is not enough here, and that is the whole point of this method.** A bound `LIKE`
     * value cannot inject SQL — item 4.4's injection suite proves that — but `%` and `_` are
     * *pattern syntax*, not SQL syntax, so binding passes them through intact. A search box that
     * forwards `%` to `LIKE` has turned an indexed lookup into a full scan, and a search intended
     * to match one user's records into one that matches every row the query's other conditions
     * allow.
     *
     * **The escaped value only works if the SQL also declares the escape character.** This is the
     * trap worth stating loudly, because the failure is silent and driver-dependent:
     *
     * ```sql
     * -- Correct, everywhere:
     * SELECT * FROM t WHERE name LIKE ? ESCAPE '!'
     * ```
     *
     * Without that `ESCAPE` clause, MySQL and PostgreSQL treat backslash as an escape by default
     * and a backslash-escaped pattern happens to work, while **SQLite has no default escape at
     * all and the pattern silently matches nothing** — verified. Code written and tested against
     * MySQL therefore returns quietly wrong results on SQLite. `QueryBuilder::whereLike()`
     * (roadmap 4.2, extended here) emits the clause so the common path cannot get this wrong.
     *
     * Note what is *not* escaped: this escapes the wildcards inside a value, and the caller
     * appends whatever wildcards the search itself needs — `Sanitizer::sqlLikePattern($term) . '%'`
     * is a prefix search whose user portion is literal. Escaping the caller's own wildcards too
     * would make every `LIKE` an equality test.
     *
     * @param string $escape the escape character the accompanying `ESCAPE` clause declares
     */
    public static function sqlLikePattern(string $value, string $escape = self::LIKE_ESCAPE): string
    {
        // The escape character must be escaped first. Doing it after would also escape the escape
        // characters this method just introduced, doubling them into literals.
        return \str_replace(
            [$escape, '%', '_'],
            [$escape . $escape, $escape . '%', $escape . '_'],
            $value,
        );
    }

    /**
     * Reduce user-authored HTML to a curated allowlist (spec FR-09b).
     *
     * **Delegated, and RFC-0001 says so in as many words: *"no hand-rolled tag stripper"*.** HTML
     * sanitization is a parsing problem, and a regex-based stripper is defeated by the same
     * corpus of mutation-XSS and namespace-confusion payloads every time someone writes one. This
     * calls `symfony/html-sanitizer`, which parses the markup into a DOM and rebuilds it from an
     * allowlist.
     *
     * **`symfony/html-sanitizer` is an optional dependency** (spec NFR-08 keeps the core free of
     * third-party implementation code), so a consumer who never renders user HTML never installs
     * it. That makes the missing-dependency path a security question rather than a convenience
     * one, and it is answered the only defensible way: **this throws.** Returning the input
     * unchanged would hand a caller who asked for sanitization their attacker's markup, and
     * returning `''` would silently destroy content. Neither failure announces itself; an
     * exception does.
     *
     * The public signature takes and returns `string` only — no Symfony type appears in it. That
     * is deliberate: a type-hint naming a class from an optional package makes the package
     * effectively required for anyone reflecting over this class, which is the opposite of
     * optional. The cost is that the profile below is not caller-configurable; that is FR-09b's
     * *"curated allowlist profile"* read literally, and a consumer needing a different profile can
     * use the component directly.
     *
     * The profile:
     *
     * - `allowSafeElements()` — Symfony's own list of elements and attributes with no scripting
     *   capability. Starting from *their* curated list rather than one written here is the same
     *   delegation decision applied one level down.
     * - **Link schemes limited to `https`, `http` and `mailto`.** This is what stops `data:` URLs
     *   — including `data:text/html`, which is a genuine XSS vector — along with every other
     *   scheme not named.
     *
     *   An earlier version of this note also credited the allowlist with stopping `javascript:`.
     *   **It does not, and measuring said so:** adding `javascript` to the allowed schemes and
     *   re-running the corpus changed nothing, because `symfony/html-sanitizer` refuses that
     *   scheme unconditionally, allowlist or not. The restriction is still worth having for it —
     *   two independent barriers rather than one — but it is defence in depth there, and the sole
     *   barrier only for `data:` and friends. The distinction matters because a reader deciding
     *   whether to widen this list needs to know which entries are load-bearing.
     * - **Relative links and relative media are refused.** A relative URL's meaning depends on the
     *   page it lands on, which is not knowable here.
     * - **`rel="noopener noreferrer"` is forced onto every link**, so a sanitized document cannot
     *   hand `window.opener` to a third-party page.
     *
     * The 20 000-byte input cap is Symfony's default and is left in place: it bounds the work an
     * untrusted document can demand, and raising it would be a denial-of-service decision made on
     * a consumer's behalf.
     *
     * @throws UtilsException if `symfony/html-sanitizer` is not installed
     */
    public static function richText(string $html): string
    {
        return self::htmlSanitizer()->sanitize($html);
    }

    /**
     * @throws UtilsException
     */
    private static function htmlSanitizer(): HtmlSanitizer
    {
        if (self::$htmlSanitizer instanceof HtmlSanitizer) {
            return self::$htmlSanitizer;
        }

        if (!\class_exists(HtmlSanitizer::class)) {
            throw new UtilsException(
                'Sanitizer::richText() needs symfony/html-sanitizer, which is an optional '
                . 'dependency of this library and is not installed. Run '
                . '`composer require symfony/html-sanitizer`. This raises rather than returning '
                . 'the input unchanged, because handing back unsanitized markup to a caller who '
                . 'asked for it to be sanitized is the failure this method exists to prevent.',
            );
        }

        // Built once: the configuration is immutable and describes a fixed policy, so there is
        // nothing per-call to vary. Same reasoning as the shared hydrator in ADR-0008.
        return self::$htmlSanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowRelativeLinks(false)
                ->allowMediaSchemes(['https', 'http'])
                ->allowRelativeMedias(false)
                ->forceAttribute('a', 'rel', 'noopener noreferrer'),
        );
    }
}
