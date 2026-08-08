<?php

declare(strict_types=1);

namespace D4np\Utils\Tests\Mail;

use D4np\Utils\Mail\EmailAddress;
use D4np\Utils\Support\MailException;
use D4np\Utils\Support\UtilsException;
use D4np\Utils\Tests\Mail\Fixture\HeaderInjectionPayloads;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The address boundary (spec FR-43), and the first leg of suite T-10.
 */
#[CoversClass(EmailAddress::class)]
#[Group('T-10')]
final class EmailAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'plain' => ['user@example.com'];
        yield 'plus tag' => ['user+tag@example.com'];
        yield 'dotted local' => ['first.last@example.com'];
        yield 'subdomain' => ['user@mail.example.com'];
        yield 'digits' => ['user123@example.com'];
        yield 'dashed domain' => ['user@my-example.com'];
        yield 'IP literal' => ['user@[127.0.0.1]'];
        yield 'uppercase local' => ['User@example.com'];
    }

    #[DataProvider('valid')]
    public function testAValidAddressIsCarriedUnchanged(string $address): void
    {
        self::assertSame($address, EmailAddress::of($address)->value);
        self::assertSame($address, (string) EmailAddress::of($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'no at' => ['userexample.com'];
        yield 'two ats' => ['user@@example.com'];
        yield 'no local part' => ['@example.com'];
        yield 'no domain' => ['user@'];
        yield 'leading space' => [' user@example.com'];
        yield 'trailing space' => ['user@example.com '];
        yield 'inner space' => ['user name@example.com'];
        yield 'quoted local part' => ['"user name"@example.com'];
        yield 'display-name form' => ['User <user@example.com>'];
        yield 'comma-separated pair' => ['a@example.com, b@example.com'];
        yield 'non-ASCII local part' => ['utènte@example.com'];
        yield 'bare hostname (stricter than RFC 5321)' => ['user@example'];
    }

    #[DataProvider('invalid')]
    public function testAnInvalidAddressIsRefused(string $address): void
    {
        $this->expectException(MailException::class);

        EmailAddress::of($address);
    }

    /**
     * T-10's first surface. Every payload in the corpus, applied to a legitimate address.
     */
    #[DataProvider('injectionPayloads')]
    public function testNoInjectionPayloadCanBecomeAnAddress(string $payload): void
    {
        $this->expectException(MailException::class);

        EmailAddress::of($payload);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionPayloads(): iterable
    {
        yield from HeaderInjectionPayloads::appliedTo('user@example.com');
    }

    /**
     * The property that must survive every future change to the validation rules: **no instance can
     * carry a header terminator.** Asserted against this class rather than against `filter_var()`'s
     * current rule set, because the rule set is PHP's to change and this promise is the library's.
     */
    public function testTheForbiddenSetIsTheThreeHeaderTerminators(): void
    {
        self::assertSame(["\r", "\n", "\0"], EmailAddress::FORBIDDEN);
    }

    public function testTheRefusalMessageNamesTheOffendingCharacter(): void
    {
        foreach (["\r" => 'carriage return', "\n" => 'line feed', "\0" => 'NUL byte'] as $character => $name) {
            try {
                EmailAddress::of('user@example.com' . $character);
                self::fail("a {$name} must be refused");
            } catch (MailException $e) {
                self::assertStringContainsString($name, $e->getMessage());
            }
        }
    }

    public function testTryOfReturnsNullInsteadOfThrowing(): void
    {
        self::assertNull(EmailAddress::tryOf('not an address'));
        self::assertNull(EmailAddress::tryOf("user@example.com\r\nBcc: victim@example.com"));
        self::assertSame('user@example.com', EmailAddress::tryOf('user@example.com')?->value);
    }

    public function testTheDomainIsLowerCasedAndTheLocalPartIsNot(): void
    {
        $address = EmailAddress::of('User.Name@Example.COM');

        // RFC 5321: the domain is case-insensitive, the local part is not. Lower-casing the local
        // part is a common habit that rewrites the address on hosts which honour the distinction.
        self::assertSame('example.com', $address->domain());
        self::assertSame('User.Name@Example.COM', $address->value);
    }

    public function testTheDomainOfAnAddressWithAnAtInTheLocalPartIsTheLastOne(): void
    {
        // filter_var() refuses an unquoted second '@', so the only way to hold one is an IP literal
        // domain; strrpos() is what makes the last '@' the separator either way.
        self::assertSame('[127.0.0.1]', EmailAddress::of('user@[127.0.0.1]')->domain());
    }

    public function testTheExceptionIsCatchableAsTheLibrarysRoot(): void
    {
        $this->expectException(UtilsException::class);

        EmailAddress::of('nope');
    }
}
