<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psc\Auth\Auth;

/**
 * Auth::mailboxKey(): the mailbox an address delivers to, which the limits on mail to unproven addresses count by,
 * so name+1@ and name+2@, Gmail's dotted spellings, RFC 5322's quoted spellings and a domain literal's spellings of
 * one IP address share one allowance. It is never an address to send to.
 */
final class MailboxKeyTest extends TestCase
{
    public function testSubAddressesAndGmailsSpellingsAreOneMailbox(): void
    {
        $cases = [
            'victim@example.test' => 'victim@example.test',
            ' Victim+news@Example.TEST ' => 'victim@example.test',
            'victim+1+2@example.test' => 'victim@example.test',
            'first.last@example.test' => 'first.last@example.test',
            'First.Last+x@gmail.com' => 'firstlast@gmail.com',
            'f.i.r.s.t.last@googlemail.com' => 'firstlast@gmail.com',
            '+tag@example.test' => '+tag@example.test',
            'no-at-sign' => 'no-at-sign',
            '' => '',
        ];
        foreach ($cases as $address => $mailbox) {
            self::assertSame($mailbox, Auth::mailboxKey((string) $address), (string) $address);
        }
        self::assertNotSame(Auth::mailboxKey('one@example.test'), Auth::mailboxKey('one@example.org'), 'other domains stay apart');
        self::assertNotSame(Auth::mailboxKey('a.b@example.test'), Auth::mailboxKey('ab@example.test'), 'only Gmail ignores dots');
    }

    /**
     * The quotes of a quoted local part, and the backslash of a quoted pair, are no part of the mailbox (RFC 5322
     * §3.2.4), so every such spelling the address check accepts is the plain mailbox: a cap counted by it cannot be
     * escaped by quoting.
     */
    public function testQuotedSpellingsAndDomainLiteralsAreOneMailbox(): void
    {
        $cases = [
            '"jdoe"@gmail.com' => 'jdoe@gmail.com',
            '"j".doe@gmail.com' => 'jdoe@gmail.com',
            'j."doe"@gmail.com' => 'jdoe@gmail.com',
            '"\\jdoe"@gmail.com' => 'jdoe@gmail.com',
            '"j\\doe"@gmail.com' => 'jdoe@gmail.com',
            '"jdoe+x"@gmail.com' => 'jdoe@gmail.com',
            '"\\j\\d\\o\\e"@example.com' => 'jdoe@example.com',
            '"Victim"+1@Example.TEST' => 'victim@example.test',
            '"a@b"@example.test' => 'a@b@example.test',
            'jdoe@[1.2.3.4]' => 'jdoe@[1.2.3.4]',
            'jdoe@[IPv6:::ffff:1.2.3.4]' => 'jdoe@[1.2.3.4]',
            'jdoe@[IPv6:::1]' => 'jdoe@[::1]',
            'jdoe@[IPv6:0:0:0:0:0:0:0:1]' => 'jdoe@[::1]',
            'jdoe@[IPv6:0000::0001]' => 'jdoe@[::1]',
        ];
        foreach ($cases as $address => $mailbox) {
            self::assertSame($mailbox, Auth::mailboxKey((string) $address), (string) $address);
        }
        foreach (['"jdoe"@gmail.com', '"j\\doe"@gmail.com', 'jdoe@[IPv6:::1]'] as $accepted) {
            self::assertTrue(Auth::isUsableEmail($accepted), 'the address check takes ' . $accepted . ', so the key must read it');
        }
        self::assertNotSame(Auth::mailboxKey('jdoe@[1.2.3.4]'), Auth::mailboxKey('jdoe@[1.2.3.5]'), 'other addresses stay apart');
        self::assertNotSame(Auth::mailboxKey('"j doe"@example.test'), Auth::mailboxKey('jdoe@example.test'), 'a quoted space is a character of its own');
    }
}
