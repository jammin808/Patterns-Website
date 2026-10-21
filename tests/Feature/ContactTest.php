<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Controllers\ContactController;
use Psc\Tests\TestCase;

/**
 * The contact form's sends: what is stored and emailed, and the bot defences. Only a filled honeypot is answered
 * with a "Message sent" that keeps nothing; a send faster than the minimum fill time goes back to the form with the
 * input kept, because a person whose browser filled the form is that fast too.
 */
final class ContactTest extends TestCase
{
    /** @return array<string, string> a complete, valid message */
    private static function message(array $over = []): array
    {
        return $over + [
            'name' => 'Rita Rigger',
            'email' => 'Rita@Example.test',
            'company' => 'Flown Ltd',
            'topic' => 'support',
            'product' => 'stagewatch',
            'message' => 'Our countdown froze during the rehearsal this morning.',
            'website' => '',
        ];
    }

    /** Opens the form, then moves its clock back as if the visitor had spent $seconds filling it in. */
    private function openForm(int $seconds): void
    {
        self::assertSame(200, $this->request('GET', '/contact/')->status);
        self::assertIsInt($_SESSION['contact_started'] ?? null, 'the form starts the fill-time clock');
        $_SESSION['contact_started'] = time() - $seconds;
    }

    private function storedMessages(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM contact_messages');
    }

    public function testANormalSendIsStoredAndEmailed(): void
    {
        $this->openForm(30);
        $r = $this->post('/contact/', self::message());
        self::assertSame(303, $r->status);
        self::assertSame('/contact/#sent', $r->getHeader('Location'));

        $row = $this->db->one('SELECT * FROM contact_messages');
        self::assertNotNull($row);
        self::assertSame(['Rita Rigger', 'rita@example.test', 'support', 'stagewatch', 'new'], [$row['name'], $row['email'], $row['topic'], $row['product'], $row['status']]);
        self::assertSame('contact-admin', $this->lastMailTo('owner@example.test')['template'] ?? null);
        self::assertSame('contact-receipt', $this->lastMailTo('Rita@Example.test')['template'] ?? null);
        self::assertStringContainsString('Message sent', $this->request('GET', '/contact/')->body);
    }

    /**
     * The receipt printed the name typed into the form, so anyone could have the site mail any address words of
     * their own ("your licence is suspended, pay at …") from its own domain. It now carries only the site's words,
     * and an address receives at most two a day; every message is still stored and passed on.
     */
    public function testTheReceiptCarriesNoVisitorTextAndIsCappedPerAddress(): void
    {
        $bait = 'customer. URGENT: your Patterns licence is suspended, re-activate at patterns-licence.example/pay within 24h';
        for ($i = 0; $i < 3; $i++) {
            $this->openForm(30);
            $this->post('/contact/', self::message(['name' => $bait, 'email' => 'victim@example.test', 'message' => 'Pay now at patterns-licence.example/pay ' . $i]));
        }
        self::assertSame(3, $this->storedMessages(), 'every message is kept');
        $admin = array_filter($this->mails(), static fn (array $m): bool => $m['to'] === 'owner@example.test');
        self::assertCount(3, $admin, 'and passed on to the administrator');
        $receipts = array_values(array_filter($this->mails(), static fn (array $m): bool => $m['to'] === 'victim@example.test'));
        self::assertCount(ContactController::RECEIPTS_PER_DAY, $receipts, 'two receipts a day for one address');
        foreach ($receipts as $r) {
            self::assertSame('contact-receipt', $r['template']);
            self::assertArrayNotHasKey('name', $r['data']);
            foreach (['URGENT', 'suspended', 'patterns-licence.example', 'customer'] as $word) {
                self::assertStringNotContainsString($word, $r['html']);
                self::assertStringNotContainsString($word, $r['subject']);
            }
            self::assertStringContainsString('Thanks — we have your message', $r['html']);
            self::assertStringContainsString('help with the software', $r['html'], 'the topic from the fixed list');
        }
    }

    public function testATooQuickSendKeepsTheInputAndAsksForSendAgain(): void
    {
        $this->openForm(0);
        $started = $_SESSION['contact_started'];
        $r = $this->post('/contact/', self::message());
        self::assertSame(303, $r->status);
        self::assertSame('/contact/#form', $r->getHeader('Location'), 'back to the form, not to "Message sent"');
        self::assertSame(0, $this->storedMessages(), 'nothing is stored yet');
        self::assertSame([], $this->mails(), 'nothing is emailed yet');

        $form = $this->request('GET', '/contact/');
        self::assertStringContainsString(ContactController::QUICK_MESSAGE, $form->body);
        self::assertStringContainsString('Your message is not sent yet', $form->body);
        self::assertStringNotContainsString('Message sent', $form->body);
        self::assertStringContainsString('value="Rita Rigger"', $form->body, 'the input is kept');
        self::assertStringContainsString('>Our countdown froze during the rehearsal this morning.</textarea>', $form->body);
        self::assertSame($started, $_SESSION['contact_started'], 'the form it comes back to does not restart the clock');

        // Pressing Send again once the minimum time has passed sends it.
        $_SESSION['contact_started'] = time() - ContactController::MIN_FILL_SECONDS;
        $again = $this->post('/contact/', self::message());
        self::assertSame('/contact/#sent', $again->getHeader('Location'));
        self::assertSame(1, $this->storedMessages());
    }

    public function testAFilledHoneypotIsToldItWasSentAndNothingIsKept(): void
    {
        $this->openForm(30);
        $r = $this->post('/contact/', self::message(['website' => 'https://spam.example']));
        self::assertSame(303, $r->status);
        self::assertSame('/contact/#sent', $r->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
        self::assertSame([], $this->mails());
        self::assertStringContainsString('Message sent', $this->request('GET', '/contact/')->body);

        // Quick and with the honeypot filled is still a bot: the same silent answer.
        $this->openForm(0);
        $quick = $this->post('/contact/', self::message(['website' => 'https://spam.example']));
        self::assertSame('/contact/#sent', $quick->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
    }

    public function testAnEmailAddressErrorSaysWhatTheFormatIs(): void
    {
        $this->openForm(30);
        $r = $this->post('/contact/', self::message(['email' => 'rita.example.test']));
        self::assertSame('/contact/#form', $r->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
        self::assertSame('Enter an email address in the format name@example.co.uk', ($_SESSION['_errors'] ?? [])['email'] ?? null);
        $form = $this->request('GET', '/contact/')->body;
        self::assertStringContainsString('<p class="c-error-msg" id="email-err">Enter an email address in the format name@example.co.uk</p>', $form);
        self::assertStringContainsString('value="rita.example.test"', $form);
    }
}
