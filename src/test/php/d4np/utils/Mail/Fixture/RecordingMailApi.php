<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail\Fixture;

use D4np\Utils\Mail\MailApi;

/**
 * A {@see MailApi} that records what would have reached PHP's `mail()`.
 *
 * The suite's whole view of the transport boundary: it is what lets T-10 assert *which shape of
 * headers* is handed over — the array form, which PHP validates — rather than only that an email
 * appeared to send.
 */
final class RecordingMailApi implements MailApi
{
    /** @var list<array{to: string, subject: string, message: string, headers: array<string, string>, parameters: string}> */
    public array $calls = [];

    public function __construct(public bool $succeeds = true)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $to, string $subject, string $message, array $headers, string $parameters): bool
    {
        $this->calls[] = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'parameters' => $parameters,
        ];

        return $this->succeeds;
    }

    /**
     * Everything a transport would put on the wire, flattened — the string an injection test wants to
     * search. Header *names* are included, because a smuggled `Bcc` is a name, not a value.
     *
     * @return array{to: string, subject: string, message: string, headers: array<string, string>, parameters: string}
     */
    public function lastCall(): array
    {
        return $this->calls[\count($this->calls) - 1];
    }

    public function lastAsText(): string
    {
        $call = $this->lastCall();
        $text = 'To: ' . $call['to'] . "\n" . 'Subject: ' . $call['subject'] . "\n";

        foreach ($call['headers'] as $name => $value) {
            $text .= $name . ': ' . $value . "\n";
        }

        return $text . "\n" . $call['message'] . "\n" . $call['parameters'];
    }
}
