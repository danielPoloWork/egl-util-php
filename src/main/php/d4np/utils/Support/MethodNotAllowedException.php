<?php

declare(strict_types=1);

namespace D4np\Utils\Support;

/**
 * The path is routed, but not for this method (spec r3 FR-38, RFC-0002; ADR-0050).
 *
 * The `405` half of FR-38's pair, and it **carries the methods that would have worked**
 * because RFC 9110 §15.5.6 makes `Allow` mandatory on a 405 response: *"The origin server MUST
 * generate an Allow header field."* An exception that only said "not allowed" would leave the
 * caller unable to comply with a header it is required to send, so the list travels with the
 * refusal rather than being recomputed by whoever catches it.
 */
final class MethodNotAllowedException extends HttpException
{
    /**
     * @param list<string> $allowedMethods the methods registered for this path, uppercased and
     *                                     sorted, ready for an `Allow` header
     */
    public function __construct(
        string $message,
        private readonly array $allowedMethods = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * The value an `Allow` header takes, ready to send.
     */
    public function allowHeader(): string
    {
        return \implode(', ', $this->allowedMethods);
    }
}
