<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * A message could not be built or could not be handed to a transport (spec FR-43/FR-44, RFC-0002;
 * ADR-0056).
 *
 * Covers both halves of the `Mail` group, which are failures of different kinds on purpose:
 *
 * - **Construction** — an address that is not an address, a subject carrying CR, LF or NUL, a
 *   message with no recipient and no body. These are *caller* errors, refused where the message is
 *   assembled so that a `MailMessage` which exists is one a transport can send.
 * - **Delivery** — `mail()` returned `false`, or refused the message itself. These are
 *   *environment* failures, and they are the reason the group has an exception at all rather than a
 *   boolean: the surveyed estate's mailer returned `false` for both a bad address and an
 *   unreachable MTA, so no caller could tell a typo from an outage.
 *
 * A plain leaf on {@see UtilsException}, like {@see CryptoException}: nothing in this group needed
 * a second failure kind to unify with, and the two above are distinguished by their message rather
 * than by their class — a caller retries an outage and fixes a typo, and both decisions are made by
 * a human reading the message, not by a `catch` block choosing between two types.
 */
final class MailException extends UtilsException
{
}
