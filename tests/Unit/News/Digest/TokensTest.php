<?php

declare(strict_types=1);

namespace Psc\Tests\Unit\News\Digest;

use PHPUnit\Framework\TestCase;
use Psc\News\Digest\Tokens;

/**
 * The digest's signed links (docs/NEWS.md §14.3, Tokens): a token reads back only for its own purpose, selector and
 * secret, in exactly the shape make() gives; a selector is random hex and never the test one; an address's hash is
 * keyed and ignores case and outer spaces.
 */
final class TokensTest extends TestCase
{
    private const SECRET = 'a secret of the site that is long enough to sign with';
    private const SELECTOR = '0123456789abcdef01234567';

    public function testATokenReadsBackForItsOwnPurpose(): void
    {
        $tokens = new Tokens(self::SECRET);
        foreach ([Tokens::CONFIRM => '/^[a-f0-9]{24}\.0\.[A-Za-z0-9_-]{43}$/D', Tokens::UNSUBSCRIBE => '/^[a-f0-9]{24}\.[A-Za-z0-9_-]{43}$/D'] as $purpose => $shape) {
            $token = $tokens->make($purpose, self::SELECTOR);
            self::assertMatchesRegularExpression($shape, $token);
            self::assertStringStartsWith(self::SELECTOR . '.', $token);
            self::assertSame(self::SELECTOR, $tokens->read($purpose, $token));
            self::assertSame($token, $tokens->make($purpose, self::SELECTOR), 'the same inputs make the same token');
        }
        self::assertSame(Tokens::TEST_SELECTOR, $tokens->read(Tokens::UNSUBSCRIBE, $tokens->make(Tokens::UNSUBSCRIBE, Tokens::TEST_SELECTOR)), 'the test selector signs like any other');
    }

    public function testTheMacIsTheDesignsHmac(): void
    {
        $key = hash_hmac('sha256', 'psc-digest-v1', self::SECRET, true);
        $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', 'digest|unsubscribe|' . self::SELECTOR, $key, true)), '+/', '-_'), '=');
        self::assertSame(self::SELECTOR . '.' . $mac, (new Tokens(self::SECRET))->make(Tokens::UNSUBSCRIBE, self::SELECTOR));
        $confirm = rtrim(strtr(base64_encode(hash_hmac('sha256', 'digest|confirm|' . self::SELECTOR . '|7', $key, true)), '+/', '-_'), '=');
        self::assertSame(self::SELECTOR . '.7.' . $confirm, (new Tokens(self::SECRET))->make(Tokens::CONFIRM, self::SELECTOR, 7), 'a confirmation binds its generation');
    }

    /** A confirmation carries the generation it was sent with, and no other generation reads as it. */
    public function testAConfirmationBindsItsGeneration(): void
    {
        $tokens = new Tokens(self::SECRET);
        foreach ([0, 1, 12, Tokens::MAX_GENERATION] as $generation) {
            $token = $tokens->make(Tokens::CONFIRM, self::SELECTOR, $generation);
            self::assertSame(['selector' => self::SELECTOR, 'generation' => $generation], $tokens->readConfirm($token));
            self::assertSame(self::SELECTOR, $tokens->read(Tokens::CONFIRM, $token));
        }
        $token = $tokens->make(Tokens::CONFIRM, self::SELECTOR, 1);
        [$selector, , $mac] = explode('.', $token);
        foreach ([
            $selector . '.0.' . $mac => 'another generation with the same mac',
            $selector . '.2.' . $mac => 'a later generation with the same mac',
            $selector . '.01.' . $mac => 'a leading zero',
            $selector . '.-1.' . $mac => 'a negative generation',
            $selector . '.' . $mac => 'no generation',
            $selector . '.2147483648.' . $mac => 'past the column\'s top',
            $selector . '.1.' . $mac . 'A' => 'a 44-character mac',
        ] as $bad => $why) {
            self::assertNull($tokens->readConfirm((string) $bad), $why);
            self::assertNull($tokens->read(Tokens::CONFIRM, (string) $bad), $why);
        }
        self::assertNull($tokens->readConfirm($tokens->make(Tokens::UNSUBSCRIBE, self::SELECTOR)), 'an unsubscribe link never confirms');
        foreach ([-1, Tokens::MAX_GENERATION + 1] as $generation) {
            try {
                $tokens->make(Tokens::CONFIRM, self::SELECTOR, $generation);
                self::fail('A generation of ' . $generation . ' was signed');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $tokens->make(Tokens::UNSUBSCRIBE, self::SELECTOR, 1);
    }

    public function testEveryOtherTokenReadsAsNothing(): void
    {
        $tokens = new Tokens(self::SECRET);
        $confirm = $tokens->make(Tokens::CONFIRM, self::SELECTOR);
        $unsubscribe = $tokens->make(Tokens::UNSUBSCRIBE, self::SELECTOR);
        [$selector, $mac] = explode('.', $unsubscribe);
        $flip = static fn (string $s, int $at): string => substr($s, 0, $at) . ($s[$at] === 'A' ? 'B' : 'A') . substr($s, $at + 1);

        self::assertNull($tokens->read(Tokens::UNSUBSCRIBE, $confirm), 'a confirmation never unsubscribes');
        self::assertNull($tokens->read(Tokens::CONFIRM, $unsubscribe), 'an unsubscribe link never confirms');
        self::assertNull((new Tokens('another secret, just as long as the first one is'))->read(Tokens::UNSUBSCRIBE, $unsubscribe), 'another secret');
        self::assertNull($tokens->read(Tokens::UNSUBSCRIBE, 'fedcba9876543210fedcba98.' . $mac), 'another selector');
        foreach ([0, 20, 42] as $at) {
            self::assertNull($tokens->read(Tokens::UNSUBSCRIBE, $selector . '.' . $flip($mac, $at)), 'one changed character of the mac at ' . $at);
        }
        self::assertNull($tokens->read(Tokens::UNSUBSCRIBE, substr_replace($unsubscribe, $selector[0] === '0' ? '1' : '0', 0, 1)), 'one changed character of the selector');
        foreach ([
            '' => 'empty',
            $selector . $mac => 'no dot',
            $unsubscribe . '=' => 'padding',
            strtoupper($selector) . '.' . $mac => 'upper-case hex',
            $selector . '.' . substr($mac, 0, 42) => 'a 42-character mac',
            $selector . '.' . $mac . 'A' => 'a 44-character mac',
            $unsubscribe . "\n" => 'a trailing line break',
            ' ' . $unsubscribe => 'a leading space',
            $selector . '.' . strtr($mac, '-_', '+/') => 'base64 rather than base64url',
            substr($selector, 1) . '.' . $mac => 'a 23-character selector',
        ] as $token => $why) {
            self::assertNull($tokens->read(Tokens::UNSUBSCRIBE, (string) $token), $why);
        }
        self::assertNull($tokens->read('subscribe', $unsubscribe), 'an unknown purpose reads as nothing');
    }

    public function testMakeRefusesABadPurposeOrSelector(): void
    {
        $tokens = new Tokens(self::SECRET);
        foreach (['subscribe', '', 'CONFIRM'] as $purpose) {
            try {
                $tokens->make($purpose, self::SELECTOR);
                self::fail('A purpose of "' . $purpose . '" was signed');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString(self::SELECTOR, $e->getMessage());
            }
        }
        $bad = ['', 'ABCDEF0123456789ABCDEF01', '0123456789abcdef0123456', '0123456789abcdef012345678', '0123456789abcdef0123456g', "0123456789abcdef01234567\n"];
        $refused = 0;
        foreach ($bad as $selector) {
            try {
                $tokens->make(Tokens::CONFIRM, $selector);
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }
        self::assertSame(count($bad), $refused, 'every selector not of selector()\'s shape is refused');
    }

    public function testASelectorIsRandomHexAndNeverTheTestOne(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $selector = Tokens::selector();
            self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/D', $selector);
            self::assertNotSame(Tokens::TEST_SELECTOR, $selector);
            $seen[$selector] = true;
        }
        self::assertCount(50, $seen, 'every selector is new');
        self::assertSame(24, strlen(Tokens::TEST_SELECTOR));
        self::assertSame(604800, Tokens::CONFIRM_SECONDS, 'a confirmation works for 7 days');
    }

    public function testAnAddressHashIsKeyedAndIgnoresCaseAndOuterSpaces(): void
    {
        $tokens = new Tokens(self::SECRET);
        $hash = $tokens->emailHash('Name@Example.co.uk');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $hash);
        self::assertSame($hash, $tokens->emailHash('  name@example.co.uk '));
        self::assertNotSame($hash, $tokens->emailHash('other@example.co.uk'));
        self::assertNotSame($hash, (new Tokens('another secret, just as long as the first one is'))->emailHash('name@example.co.uk'), 'the hash changes with the secret');
        self::assertNotSame(hash('sha256', 'name@example.co.uk'), $hash, 'never a plain hash');
        self::assertNotSame(hash('sha256', 'email|name@example.co.uk'), $hash);
    }

    public function testTheKeyNeverShowsInADump(): void
    {
        $tokens = new Tokens(self::SECRET);
        ob_start();
        var_dump($tokens);
        print_r($tokens);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString(self::SECRET, $dump);
        self::assertStringNotContainsString(hash_hmac('sha256', 'psc-digest-v1', self::SECRET, true), $dump);
        self::assertStringNotContainsString('key', $dump);
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tokens('');
    }
}
