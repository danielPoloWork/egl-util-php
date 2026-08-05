<?php

declare(strict_types=1);

namespace D4np\Utils\Http;

/**
 * A cookie's `SameSite` policy.
 *
 * An enum for the same reason {@see \D4np\Utils\Database\Sort} and
 * {@see \D4np\Utils\Database\Operator} are (ADR-0015): a closed set of keywords that reaches a
 * security-relevant output, where a `string` parameter means validating at run time what the type
 * system can decide outright. PHPStan at max is what surfaced it here — `session_set_cookie_params()`
 * is itself typed against a literal union, and a plain `string` could not satisfy it.
 *
 * `lax`/`strict`/`none` in lower case are legal for PHP but omitted deliberately: two spellings of
 * one policy is a distinction with no meaning and one more thing for a reader to wonder about.
 */
enum SameSite: string
{
    /**
     * FR-15's default. The cookie is withheld from cross-site POSTs — a second, independent line
     * against CSRF — but still sent when a user follows an ordinary inbound link.
     */
    case Lax = 'Lax';

    /**
     * Withheld from *every* cross-site request, including ordinary inbound links. A logged-in user
     * following a link from an email arrives logged out, which is the kind of breakage that gets a
     * security control switched off entirely — hence `Lax` as the default rather than this.
     */
    case Strict = 'Strict';

    /**
     * Sent on every cross-site request. Browsers **reject this unless the cookie is also
     * `Secure`** and drop it entirely, which {@see Session} refuses at construction rather than
     * letting it surface as "sessions do not work".
     */
    case None = 'None';
}
