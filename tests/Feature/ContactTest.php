<?php

declare(strict_types=1);

namespace Psc\Tests\Feature;

use Psc\Controllers\ContactController;
use Psc\Http\Response;
use Psc\Support\FormGuard;
use Psc\Tests\TestCase;

/**
 * The contact form's sends: what is stored and emailed, and the bot defences. Only a filled honeypot is answered
 * with a "Message sent" that keeps nothing; a send faster than the minimum fill time goes back to the form with the
 * input kept, because a person whose browser filled the form is that fast too. Every other refusal (too many links,
 * too many messages) also keeps the input and says what to change, and a message sent twice is passed on once.
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

    /**
     * Opens the form, then returns what the browser would send with it had the visitor spent $seconds filling it in:
     * the form's signed showing time, moved back by that much.
     *
     * @return array<string, string>
     */
    private function openForm(int $seconds): array
    {
        $page = $this->request('GET', '/contact/');
        if (str_contains($page->body, 'id="sent"')) {
            // The answer to the last send shows once; the next view is the form.
            $page = $this->request('GET', '/contact/');
        }
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('/<input type="hidden" name="_started" value="\d+\.[a-f0-9]{64}">/', $page->body, 'the form carries its signed showing time');
        self::assertStringContainsString('name="website"', $page->body, 'and the honeypot');
        return $this->filledIn('contact', $seconds);
    }

    /** Posts a message as a person at $ip who spent 30 seconds on the form (the app trusts the test's client header). */
    private function sendFrom(string $ip, array $message): Response
    {
        return $this->request('POST', '/contact/', $message + $this->filledIn('contact') + ['_csrf' => $this->csrf()], [], null, true, [], ['HTTP_X_TEST_CLIENT' => $ip]);
    }

    private function storedMessages(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM contact_messages');
    }

    /** @return list<array<string, mixed>> the emails to the administrator */
    private function adminMails(): array
    {
        return array_values(array_filter($this->mails(), static fn (array $m): bool => $m['to'] === 'owner@example.test'));
    }

    public function testANormalSendIsStoredAndEmailed(): void
    {
        $r = $this->post('/contact/', self::message() + $this->openForm(30));
        self::assertSame(303, $r->status);
        self::assertSame('/contact/#sent', $r->getHeader('Location'));

        $row = $this->db->one('SELECT * FROM contact_messages');
        self::assertNotNull($row);
        self::assertSame(['Rita Rigger', 'rita@example.test', 'support', 'stagewatch', 'new'], [$row['name'], $row['email'], $row['topic'], $row['product'], $row['status']]);
        self::assertSame('contact-admin', $this->lastMailTo('owner@example.test')['template'] ?? null);
        self::assertSame('contact-receipt', $this->lastMailTo('Rita@Example.test')['template'] ?? null);
        $page = $this->request('GET', '/contact/')->body;
        self::assertStringContainsString('Message sent', $page);
        self::assertMatchesRegularExpression('/<div class="c-callout c-callout--success support-sent" id="sent" role="status" tabindex="-1" data-feedback>/', $page, 'the answer takes focus on load');
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
            $this->post('/contact/', self::message(['name' => $bait, 'email' => 'victim@example.test', 'message' => 'Pay now at patterns-licence.example/pay ' . $i]) + $this->openForm(30));
        }
        self::assertSame(3, $this->storedMessages(), 'every message is kept');
        self::assertCount(3, $this->adminMails(), 'and passed on to the administrator');
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
        $this->request('GET', '/contact/');
        // Shown a second ago: too quick, whatever the clock does while the test runs.
        $shown = $this->filledIn('contact', 1);
        $r = $this->post('/contact/', self::message() + $shown);
        self::assertSame(303, $r->status);
        self::assertSame('/contact/#form', $r->getHeader('Location'), 'back to the form, not to "Message sent"');
        self::assertSame(0, $this->storedMessages(), 'nothing is stored yet');
        self::assertSame([], $this->mails(), 'nothing is emailed yet');

        $form = $this->request('GET', '/contact/');
        self::assertStringContainsString(ContactController::QUICK_MESSAGE, $form->body);
        self::assertStringContainsString('press Send message again', $form->body);
        self::assertStringContainsString('Your message is not sent yet', $form->body);
        self::assertStringContainsString('data-error-summary', $form->body);
        self::assertStringNotContainsString('Message sent', $form->body);
        self::assertStringContainsString('value="Rita Rigger"', $form->body, 'the input is kept');
        self::assertStringContainsString('>Our countdown froze during the rehearsal this morning.</textarea>', $form->body);
        self::assertStringContainsString('name="_started" value="' . $shown['_started'] . '"', $form->body, 'the form it comes back to does not restart the clock');

        // Pressing Send again on that form, once the minimum time has passed since it was first shown, sends it.
        sleep(ContactController::MIN_FILL_SECONDS - 1);
        $again = $this->post('/contact/', self::message() + $shown);
        self::assertSame('/contact/#sent', $again->getHeader('Location'));
        self::assertSame(1, $this->storedMessages());
    }

    /**
     * The showing time is signed for the form and the session: a send without one (a script posting straight to the
     * form), with a forged one or with one from another session is treated as quick. Nothing is lost: the input comes
     * back, and the form carries a good time for the next press.
     */
    public function testASendWithoutAGoodSignedTimeComesBackLikeAQuickOne(): void
    {
        $this->request('GET', '/contact/');
        $good = $this->filledIn('contact', 60);
        [$at] = explode('.', $good['_started']);
        $otherSession = $at . '.' . FormGuard::signature($this->app->secret(), 'contact', (int) $at, str_repeat('cd', 32));
        $otherForm = $this->filledIn('forgot', 60)['_started'];
        $forged = ($at - 600) . '.' . explode('.', $good['_started'])[1];
        foreach (['missing' => null, 'forged' => $forged, 'another session' => $otherSession, 'another form' => $otherForm, 'garbage' => 'yesterday'] as $case => $token) {
            $post = self::message(['message' => 'A message for the ' . $case . ' case, long enough.']);
            if ($token !== null) {
                $post['_started'] = $token;
            }
            $r = $this->post('/contact/', $post);
            self::assertSame('/contact/#form', $r->getHeader('Location'), $case);
            $form = $this->request('GET', '/contact/')->body;
            self::assertStringContainsString(ContactController::QUICK_MESSAGE, $form, $case);
            self::assertStringContainsString('A message for the ' . $case . ' case', $form, $case . ': the input is kept');
        }
        self::assertSame(0, $this->storedMessages());
        self::assertSame([], $this->mails());
    }

    public function testAFilledHoneypotIsToldItWasSentAndNothingIsKept(): void
    {
        $r = $this->post('/contact/', self::message(['website' => 'https://spam.example']) + $this->openForm(30));
        self::assertSame(303, $r->status);
        self::assertSame('/contact/#sent', $r->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
        self::assertSame([], $this->mails());
        self::assertStringContainsString('Message sent', $this->request('GET', '/contact/')->body);

        // Quick and with the honeypot filled is still a bot: the same silent answer.
        $quick = $this->post('/contact/', self::message(['website' => 'https://spam.example']) + $this->openForm(0));
        self::assertSame('/contact/#sent', $quick->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
    }

    public function testAnEmailAddressErrorSaysWhatTheFormatIs(): void
    {
        $r = $this->post('/contact/', self::message(['email' => 'rita.example.test']) + $this->openForm(30));
        self::assertSame('/contact/#form', $r->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
        self::assertSame('Enter an email address in the format name@example.co.uk', ($_SESSION['_errors'] ?? [])['email'] ?? null);
        $form = $this->request('GET', '/contact/')->body;
        self::assertStringContainsString('<p class="c-error-msg" id="email-err">Enter an email address in the format name@example.co.uk</p>', $form);
        self::assertStringContainsString('value="rita.example.test"', $form);
        self::assertMatchesRegularExpression('/id="email"[^>]*aria-invalid="true"[^>]*aria-describedby="email-err"/', $form);
        self::assertStringContainsString('<li><a href="#email">Enter an email address in the format name@example.co.uk</a></li>', $form, 'the summary links to the field');
    }

    /**
     * An empty send lists every missing field in the summary, each a link to its field, and keeps what was typed.
     * It says so however fast it came: only a send that would go through is asked to wait.
     */
    public function testAnEmptySendListsEachFieldInTheSummary(): void
    {
        $r = $this->post('/contact/', ['name' => '', 'email' => '', 'topic' => '', 'message' => '', 'company' => 'Kept Ltd'] + $this->openForm(0));
        self::assertSame('/contact/#form', $r->getHeader('Location'));
        $form = $this->request('GET', '/contact/')->body;
        self::assertStringNotContainsString(ContactController::QUICK_MESSAGE, $form);
        self::assertMatchesRegularExpression('/<div class="c-callout c-callout--danger c-error-summary" id="form" data-error-summary tabindex="-1" role="alert" aria-labelledby="form-summary-title">/', $form);
        self::assertStringContainsString('Check 4 fields before sending', $form);
        foreach (['name', 'email', 'topic', 'message'] as $field) {
            self::assertStringContainsString('<li><a href="#' . $field . '">', $form, $field);
            self::assertMatchesRegularExpression('/id="' . $field . '"[^>]*aria-invalid="true"/', $form, $field);
        }
        self::assertStringContainsString('value="Kept Ltd"', $form);
        self::assertSame(1, substr_count($form, 'id="form"'), 'the summary takes the id the redirect lands on, the form gives it up');
    }

    /** Link spam carries many links: past the limit the message comes back, saying what the limit is. */
    public function testAMessageWithTooManyLinksIsRefusedWithTheLimitAndKept(): void
    {
        $spammy = 'Great site! See https://a.example/1 and http://b.example/2, www.c.example and https://www.d.example now.';
        $r = $this->post('/contact/', self::message(['message' => $spammy]) + $this->openForm(30));
        self::assertSame('/contact/#form', $r->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
        self::assertSame([], $this->mails());
        $form = $this->request('GET', '/contact/')->body;
        self::assertStringContainsString('Your message has 4 links, and the limit is ' . ContactController::MAX_LINKS . '. Remove some links, then send it again.', $form);
        self::assertStringContainsString(e($spammy) . '</textarea>', $form, 'the message is kept');
        self::assertStringContainsString('Up to ' . ContactController::MAX_LINKS . ' links.', $form, 'the field says the limit before anyone hits it');

        // At the limit it goes through.
        $ok = $this->post('/contact/', self::message(['message' => 'Logs at https://a.example/1, https://b.example/2 and www.c.example.']) + $this->openForm(30));
        self::assertSame('/contact/#sent', $ok->getHeader('Location'));
        self::assertSame(1, $this->storedMessages());
    }

    public function testANameHoldingAWebAddressIsRefused(): void
    {
        $r = $this->post('/contact/', self::message(['name' => 'Cheap pills www.spam.example']) + $this->openForm(30));
        self::assertSame('/contact/#form', $r->getHeader('Location'));
        self::assertSame(0, $this->storedMessages());
        self::assertSame('Enter your name without a web address.', ($_SESSION['_errors'] ?? [])['name'] ?? null);
    }

    /**
     * A message sent twice (a double press, the back button, a resend) was stored and emailed twice. The second is
     * now answered "Message sent", saying we already have it, and nothing more is stored, emailed or counted.
     */
    public function testTheSameMessageSentTwiceIsPassedOnOnce(): void
    {
        self::assertSame('/contact/#sent', $this->post('/contact/', self::message() + $this->openForm(30))->getHeader('Location'));
        $again = $this->post('/contact/', self::message(['message' => "  our COUNTDOWN froze during the rehearsal\n this morning. "]) + $this->openForm(30));
        self::assertSame('/contact/#sent', $again->getHeader('Location'));
        self::assertSame(1, $this->storedMessages());
        self::assertCount(1, $this->adminMails());
        $page = $this->request('GET', '/contact/')->body;
        self::assertStringContainsString('Message sent', $page);
        self::assertStringContainsString('We already have this message from you, so it was not sent a second time.', $page);

        // The same words from the same network under another address are the same sender too.
        $this->post('/contact/', self::message(['email' => 'rita.other@example.test']) + $this->openForm(30));
        self::assertSame(1, $this->storedMessages());

        // A different message from the same sender is a new message.
        $this->post('/contact/', self::message(['message' => 'And now the clock will not start at all.']) + $this->openForm(30));
        self::assertSame(2, $this->storedMessages());

        // The same words a day later are a new message.
        $this->db->exec('UPDATE contact_messages SET created_at = created_at - ?', [ContactController::DUPLICATE_SECONDS + 1]);
        $this->post('/contact/', self::message() + $this->openForm(30));
        self::assertSame(3, $this->storedMessages());
    }

    /** Messages are limited per network and per sender address; a refused one keeps its input and names no address. */
    public function testMessagesAreRateLimitedPerNetworkAndPerAddress(): void
    {
        $this->bootApp(['app' => ['trusted_proxy_header' => 'HTTP_X_TEST_CLIENT', 'trusted_proxies' => ['203.0.113.7']]]);
        for ($i = 1; $i <= ContactController::PER_IP_PER_HOUR; $i++) {
            self::assertSame('/contact/#sent', $this->sendFrom('198.51.100.1', self::message(['email' => "net{$i}@example.test", 'message' => "Message number {$i} from one network."]))->getHeader('Location'));
        }
        $refused = $this->sendFrom('198.51.100.1', self::message(['email' => 'net9@example.test', 'message' => 'One message too many from this network.']));
        self::assertSame('/contact/#form', $refused->getHeader('Location'));
        self::assertSame(ContactController::PER_IP_PER_HOUR, $this->storedMessages());
        $form = $this->request('GET', '/contact/')->body;
        self::assertStringContainsString('Your message was not sent', $form);
        self::assertStringContainsString('so this one was not sent. Your message is still here', $form);
        self::assertStringContainsString('One message too many from this network.</textarea>', $form, 'the input is kept');
        self::assertStringContainsString('href="/portal/tickets/new/"', $form, 'another way to reach a person');
        self::assertSame(0, preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', strip_tags(str_replace('net9@example.test', '', $form))), 'no address to write to instead');

        // One sender address, many networks.
        for ($i = 1; $i <= ContactController::PER_ADDRESS_PER_DAY; $i++) {
            self::assertSame('/contact/#sent', $this->sendFrom('192.0.2.' . $i, self::message(['email' => 'one@example.test', 'message' => "Message number {$i} from one address."]))->getHeader('Location'));
        }
        $this->sendFrom('192.0.2.99', self::message(['email' => 'one@example.test', 'message' => 'One message too many from this address.']));
        self::assertSame(ContactController::PER_IP_PER_HOUR + ContactController::PER_ADDRESS_PER_DAY, $this->storedMessages());
        self::assertStringContainsString('messages from this email address today', $this->request('GET', '/contact/')->body);
    }

    /** Without scripting, a question typed into the assistant's box lands here as ?q=, ready to send. */
    public function testAQuestionFromTheAssistantsBoxIsReadyToSend(): void
    {
        $page = $this->request('GET', '/contact/?q=' . rawurlencode('Which licence do I need for two PCs?'))->body;
        self::assertStringContainsString('>Which licence do I need for two PCs?</textarea>', $page);
        self::assertStringContainsString('36 / 5000', $page, 'the count starts from the question');
    }
}
