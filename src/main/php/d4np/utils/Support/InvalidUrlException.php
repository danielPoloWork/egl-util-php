<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A URL string could not be accepted, or an operation on one was refused.
 *
 * Raised by {@see Url} for four distinct causes, each named in the message: a control
 * character in the input (see ADR-0036 — `parse_url()` silently rewrites those to `_`
 * rather than rejecting them), a string that is not an absolute URL, a string
 * `parse_url()` cannot decompose at all, and a refused **scheme downgrade**.
 *
 * One type rather than four: the message names the cause, and a consumer that later needs
 * to branch on one of them gets a subclass additively (the item 9.1 / 9.2 precedent — a
 * distinct type is earned by a consumer that needs the distinct `catch`, not anticipated).
 * What a consumer does need today is to separate "this URL is unusable" from every other
 * library failure, which is what this type provides.
 */
final class InvalidUrlException extends UtilsException
{
}
