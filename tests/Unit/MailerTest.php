<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use Psc\Config;
use Psc\Mail\Delivery;
use Psc\Mail\Mailer;
use Psc\Tests\Fakes\FakeSmtpServer;
use Psc\Tests\TestCase;

/**
 * The Mailer (docs/NEWS.md §14.3, §14.14): every existing send unchanged; the list headers, and nothing else, added
 * after Auto-Submitted, folded so they unfold to themselves; a bad header refusing the whole message; the written text
 * part; the layout's two optional values; and deliverNow()'s outcome at each SMTP stage against a scripted server on
 * 127.0.0.1, never with the server's words or the address in what it reports or logs.
 */
final class MailerTest extends TestCase
{
    private const TO = 'reader@example.test';
    private const TOKEN = '0123456789abcdef01234567.Yp3kV0s9qJx_7mN2aL-cR8tWf4hZ1uE6oB5iD0gTnQs';

    /**
     * The layout's output before it took a preheader and a footer, for the data in
     * testTheLayoutWithoutItsTwoValuesIsUnchangedByteForByte() (a nowdoc drops the last line break, put back there).
     */
    private const LAYOUT_BEFORE = <<<'HTML'
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>Your ticket &amp; &lt;you&gt;</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#15171c;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;border:1px solid #e3e5ea;">
<tr><td style="padding:20px 28px;border-bottom:4px solid #15171c;">
<a href="https://patternsshowcontrol.test/" style="color:#15171c;text-decoration:none;font-weight:700;font-size:16px;letter-spacing:.02em;">Patterns Show Control</a>
</td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
<p>Body</p>
</td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #e3e5ea;font-size:12px;line-height:1.5;color:#5b616e;">
You received this because of an action on <a href="https://patternsshowcontrol.test/" style="color:#5b616e;">patternsshowcontrol.test</a>. If it was not you, you can ignore it.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    /** @return array<string, string> */
    private static function listHeaders(): array
    {
        return [
            'List-Unsubscribe' => '<https://patternsshowcontrol.test/digest/unsubscribe/?token=' . self::TOKEN . '>, <mailto:unsubscribe@patternsshowcontrol.co.uk?subject=Unsubscribe%20' . self::TOKEN . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'List-Id' => 'Industry Updates weekly digest <digest.patternsshowcontrol.test>',
        ];
    }

    /** @return list<string> the .eml files the log driver wrote, oldest first */
    private function emls(): array
    {
        $files = glob($this->storage . '/mail/*.eml') ?: [];
        sort($files);
        return array_map(static fn (string $f): string => (string) file_get_contents($f), $files);
    }

    private function siteLog(): string
    {
        $out = '';
        foreach (glob($this->storage . '/logs/*.log') ?: [] as $file) {
            $out .= (string) file_get_contents($file);
        }
        return $out;
    }

    private static function head(string $raw): string
    {
        return explode("\r\n\r\n", $raw, 2)[0];
    }

    private static function unfold(string $headers): string
    {
        return (string) preg_replace("/\r\n(?=[ \t])/", '', $headers);
    }

    /** The text part of a message, decoded. */
    private static function textPart(string $raw): string
    {
        preg_match('/Content-Type: text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n(.*?)\r\n--psc-/s', $raw, $m);
        return quoted_printable_decode($m[1] ?? '');
    }

    /** @param array<string, mixed> $smtp */
    private function smtpMailer(array $smtp): Mailer
    {
        $config = Config::fromArray(PSC_ROOT, [
            'app' => ['url' => 'https://patternsshowcontrol.test', 'env' => 'testing'],
            'mail' => ['driver' => 'smtp', 'smtp' => $smtp + ['host' => '127.0.0.1', 'encryption' => 'none', 'user' => '', 'password' => '', 'timeout' => 3]],
        ]);
        return Mailer::fromConfig($config, $this->app->storage, $this->app->log, $this->app->view());
    }

    /**
     * One message through the scripted server.
     *
     * @param array<string, string|null> $script
     * @param array<string, mixed> $smtp
     * @param array<string, string> $headers
     * @return array{0: Delivery, 1: string}
     */
    private function overSmtp(array $script, array $smtp = [], array $headers = []): array
    {
        $server = FakeSmtpServer::start($script, $this->storage);
        $delivery = $this->smtpMailer($smtp + ['port' => $server->port])->deliverNow(self::TO, 'We have your message', 'contact-receipt', ['topicLabel' => 'Sales'], $headers);
        return [$delivery, $server->transcript()];
    }

    private function assertNothingOfTheServerOrTheAddress(Delivery $delivery): void
    {
        $summary = $delivery->summary();
        self::assertLessThanOrEqual(40, strlen($summary));
        self::assertMatchesRegularExpression('/^[a-z]+( \d{3})?( [245]\.\d{1,3}\.\d{1,3})?( timeout)?$/D', $summary);
        $log = $this->siteLog();
        foreach ([self::TO, 'rejected', 'Recipient', 'authentication failed', 'Try again', 'spam', 'cGFzc3dvcmQ'] as $words) {
            self::assertStringNotContainsString($words, $summary);
            self::assertStringNotContainsString($words, $log, 'the site log holds none of the server\'s words, the address or the credentials');
        }
    }

    public function testASendWithoutHeadersIsUnchanged(): void
    {
        self::assertSame('log', $this->mail->driver());
        self::assertTrue($this->mail->send(self::TO, 'We have your message', 'contact-receipt', ['topicLabel' => 'Sales'], 'reply@example.test'));
        $emls = $this->emls();
        self::assertCount(1, $emls);
        $head = (string) preg_replace(
            ['/^Date: [^\r\n]*/m', '/^Message-ID: <[0-9a-f]{32}@/m', '/psc-[0-9a-f]{24}/'],
            ['Date: (date)', 'Message-ID: <(id)@', 'psc-(boundary)'],
            self::head($emls[0]),
        );
        self::assertSame(implode("\r\n", [
            'Date: (date)',
            'From: Patterns Show Control <no-reply@patternsshowcontrol.co.uk>',
            'To: ' . self::TO,
            'Subject: We have your message',
            'Message-ID: <(id)@patternsshowcontrol.co.uk>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="psc-(boundary)"',
            'Auto-Submitted: auto-generated',
            'Reply-To: reply@example.test',
        ]), $head);
        $sent = $this->mails()[0];
        self::assertSame([], $sent['headers']);
        self::assertSame(Mailer::toText($sent['html']), $sent['text'], 'without a .text.php the text part is derived from the HTML, as before');
        self::assertSame($sent['text'], str_replace("\r\n", "\n", self::textPart($emls[0])));
        self::assertStringContainsString('Thanks, we have your message', $sent['html']);
    }

    public function testTheListHeadersFollowAutoSubmittedInTheOrderGiven(): void
    {
        $headers = self::listHeaders();
        self::assertTrue($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales'], null, $headers));
        $lines = explode("\r\n", self::unfold(self::head($this->emls()[0])));
        $at = array_search('Auto-Submitted: auto-generated', $lines, true);
        self::assertIsInt($at);
        self::assertSame([
            'List-Unsubscribe: ' . $headers['List-Unsubscribe'],
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            'List-Id: Industry Updates weekly digest <digest.patternsshowcontrol.test>',
        ], array_slice($lines, $at + 1));
        self::assertSame($headers, $this->mails()[0]['headers'], '$sent keeps the headers');

        // Two files written in the same second have no order by name, so the first goes before the second is sent.
        array_map('unlink', glob($this->storage . '/mail/*.eml') ?: []);
        $reversed = array_reverse($headers, true);
        self::assertTrue($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales'], null, $reversed));
        $lines = explode("\r\n", self::unfold(self::head($this->emls()[0])));
        self::assertSame(['List-Id', 'List-Unsubscribe-Post', 'List-Unsubscribe'], array_map(static fn (string $l): string => strtok($l, ':'), array_slice($lines, (int) array_search('Auto-Submitted: auto-generated', $lines, true) + 1)));
    }

    public function testALongListUnsubscribeFoldsAfterItsCommaAndUnfoldsToItself(): void
    {
        $headers = self::listHeaders();
        self::assertTrue($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales'], null, $headers));
        $head = self::head($this->emls()[0]);
        $unsubscribe = 'https://patternsshowcontrol.test/digest/unsubscribe/?token=' . self::TOKEN;
        self::assertStringContainsString("List-Unsubscribe: <{$unsubscribe}>,\r\n <mailto:unsubscribe@patternsshowcontrol.co.uk?subject=Unsubscribe%20" . self::TOKEN . ">\r\n", $head);
        self::assertStringContainsString("\r\nList-Id: Industry Updates weekly digest <digest.patternsshowcontrol.test>", $head, 'a short value is not folded');
        self::assertStringContainsString('List-Unsubscribe: ' . $headers['List-Unsubscribe'], self::unfold($head));
        foreach (explode("\r\n", $head) as $line) {
            self::assertLessThanOrEqual(998, strlen($line));
            self::assertNotSame('', trim($line), 'no folded line is only white space');
        }
    }

    public function testABadHeaderRefusesTheWholeMessage(): void
    {
        $value = 'Bcc: victim@example.test';
        $cases = [
            'an unknown name' => ['Bcc' => 'victim@example.test'],
            'a name in another case' => ['list-id' => 'x'],
            'a name with a line break' => ["List-Id\r\nBcc" => 'x'],
            'a CR' => ['List-Unsubscribe' => "<https://patternsshowcontrol.test/>\r" . $value],
            'an LF' => ['List-Unsubscribe' => "<https://patternsshowcontrol.test/>\n" . $value],
            'a CRLF' => ['List-Unsubscribe' => "<https://patternsshowcontrol.test/>\r\n" . $value],
            'a NUL' => ['List-Id' => "Industry Updates\0" . $value],
            'over 900 bytes' => ['List-Id' => str_repeat('a', 901)],
            'an empty value' => ['List-Id' => ''],
            'a value that is not text' => ['List-Id' => 5],
            'a list, not names' => ['List-Id: ' . $value],
            'eight-bit text' => ['List-Id' => "Caf\u{e9} " . $value],
            'a good one and a bad one' => self::listHeaders() + ['Reply-To' => 'victim@example.test'],
        ];
        foreach ($cases as $why => $headers) {
            self::assertFalse($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales'], null, $headers), $why);
            $delivery = $this->mail->deliverNow(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales'], $headers);
            self::assertFalse($delivery->ok, $why);
            self::assertSame('refused', $delivery->stage, $why);
        }
        self::assertSame([], $this->emls(), 'nothing is written');
        self::assertSame([], $this->mails(), 'nothing is recorded as sent');
        $log = $this->siteLog();
        self::assertSame(2 * count($cases), substr_count($log, '"mail refused"'));
        self::assertStringContainsString('"header":"Bcc"', $log);
        self::assertStringContainsString('"header":"invalid"', $log, 'a name that is not a header name is not written out');
        self::assertStringContainsString('"template":"contact-receipt"', $log);
        self::assertStringNotContainsString('victim', $log, 'never the value');

        self::assertTrue($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales'], null, ['List-Id' => str_repeat('a', 900)]), '900 bytes is allowed');
        self::assertFalse($this->mail->send("reader@example.test\r\nBcc: victim@example.test", 'Subject', 'contact-receipt', ['topicLabel' => 'Sales']), 'a line break in the recipient refuses it too');
        self::assertCount(1, $this->emls());
    }

    public function testRenderTakesTheWrittenTextPartWhenThereIsOne(): void
    {
        $data = ['email' => self::TO, 'url' => 'https://patternsshowcontrol.test/digest/confirm/?token=' . self::TOKEN . '&x=1', 'publisher' => 'Ben Rohleder'];
        $parts = $this->mail->render('Confirm your Industry Updates digest', 'digest-confirm', $data);
        self::assertStringStartsWith('Confirm your weekly digest', $parts['text']);
        self::assertStringContainsString("\n" . $data['url'] . "\n", $parts['text'], 'written out whole, not escaped');
        self::assertStringNotContainsString('&amp;', $parts['text']);
        self::assertNotSame(Mailer::toText($parts['html']), $parts['text']);
        self::assertStringContainsString(e($data['url']), $parts['html']);

        $parts = $this->mail->render('Confirm your email address', 'verify-email', ['name' => 'Ben', 'url' => 'https://patternsshowcontrol.test/portal/verify/x/']);
        self::assertSame(Mailer::toText($parts['html']), $parts['text'], 'no .text.php: derived from the HTML');

        self::assertTrue($this->mail->send(self::TO, 'Confirm your Industry Updates digest', 'digest-confirm', $data));
        self::assertSame($this->mails()[0]['text'], str_replace("\r\n", "\n", self::textPart($this->emls()[0])), 'the message carries the written part');
    }

    public function testATemplateErrorThrowsFromRenderAndRefusesASend(): void
    {
        try {
            $this->mail->render('Subject', 'no-such-template');
            self::fail('A missing template rendered');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Template not found', $e->getMessage());
        }
        self::assertFalse($this->mail->send(self::TO, 'Subject', 'no-such-template'));
        self::assertSame('refused', $this->mail->deliverNow(self::TO, 'Subject', 'no-such-template')->stage);
        self::assertFalse($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales', '_footer' => '../layout']), 'a footer is a partial name only');
        self::assertFalse($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales', '_footer' => 'no-such-footer']));
        self::assertFalse($this->mail->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales', '_preheader' => ['not text']]));
        self::assertSame([], $this->emls());
    }

    public function testTheLayoutWithoutItsTwoValuesIsUnchangedByteForByte(): void
    {
        $data = ['subject' => 'Your ticket & <you>', 'body' => "<p>Body</p>\n", 'siteName' => 'Patterns Show Control', 'baseUrl' => 'https://patternsshowcontrol.test'];
        self::assertSame(self::LAYOUT_BEFORE . "\n", $this->app->view()->render('emails/layout', $data));

        $html = $this->mail->render('We have your message', 'contact-receipt', ['topicLabel' => 'Sales'])['html'];
        self::assertSame($this->app->view()->render('emails/layout', [
            'subject' => 'We have your message',
            'body' => $this->app->view()->render('emails/contact-receipt', ['topicLabel' => 'Sales', 'siteName' => 'Patterns Show Control', 'baseUrl' => 'https://patternsshowcontrol.test']),
            'siteName' => 'Patterns Show Control',
            'baseUrl' => 'https://patternsshowcontrol.test',
        ]), $html);
        self::assertStringContainsString('You received this because of an action on', $html);
        self::assertStringNotContainsString('display:none', $html);
    }

    public function testThePreheaderAndTheFooterReachTheLayout(): void
    {
        $data = [
            'topicLabel' => 'Sales',
            '_preheader' => 'One new article & more',
            '_footer' => 'digest-footer',
            'who' => ['email' => self::TO, 'source' => 'page', 'date' => '23 October 2026'],
            'unsubscribeUrl' => 'https://patternsshowcontrol.test/digest/unsubscribe/?token=' . self::TOKEN,
            'mailto' => 'unsubscribe@patternsshowcontrol.co.uk',
            'mailtoUrl' => 'mailto:unsubscribe@patternsshowcontrol.co.uk?subject=Unsubscribe%20' . self::TOKEN,
            'privacyUrl' => 'https://patternsshowcontrol.test/legal/privacy/',
            'publisher' => 'Ben Rohleder',
        ];
        $html = $this->mail->render('We have your message', 'contact-receipt', $data)['html'];
        self::assertMatchesRegularExpression('/<body [^>]*>\n<div style="display:none;[^"]*">One new article &amp; more<\/div>\n<table /', $html, 'the preheader is the body\'s hidden first line');
        self::assertStringNotContainsString('You received this because of an action on', $html, 'the footer takes the line\'s place');
        self::assertStringContainsString('You get this weekly digest at ' . self::TO . ' because you signed up for it on our website and confirmed it on 23 October 2026.', $html);
        self::assertSame(1, substr_count($html, 'display:none'));
    }

    public function testDeliverNowOnTheLogDriverWritesOneMessage(): void
    {
        $delivery = $this->mail->deliverNow(self::TO, 'We have your message', 'contact-receipt', ['topicLabel' => 'Sales'], self::listHeaders());
        self::assertTrue($delivery->ok);
        self::assertSame('done', $delivery->stage);
        self::assertSame('done', $delivery->summary());
        self::assertCount(1, $this->emls());
        self::assertCount(1, $this->mails());
        self::assertSame(self::listHeaders(), $this->mails()[0]['headers']);
        self::assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $this->emls()[0]);
    }

    public function testADeliveryAcceptedThroughoutIsDone(): void
    {
        [$delivery, $transcript] = $this->overSmtp([], [], self::listHeaders());
        self::assertTrue($delivery->ok);
        self::assertSame('done', $delivery->stage);
        self::assertFalse($delivery->retryable() || $delivery->uncertain() || $delivery->hardBounce());
        self::assertMatchesRegularExpression('/^EHLO \S+\r\n/', $transcript);
        self::assertStringContainsString("MAIL FROM:<no-reply@patternsshowcontrol.co.uk>\r\nRCPT TO:<" . self::TO . ">\r\nDATA\r\n", $transcript);
        self::assertStringContainsString("\r\nAuto-Submitted: auto-generated\r\nList-Unsubscribe: <https://", $transcript);
        self::assertStringContainsString("\r\n.\r\nQUIT\r\n", $transcript, 'the message ends with the lone dot, then QUIT');
        self::assertStringNotContainsString('AUTH', $transcript, 'no AUTH without a user');
        $this->assertNothingOfTheServerOrTheAddress($delivery);
    }

    public function testAnAddressThatDoesNotExistIsAHardBounce(): void
    {
        [$delivery, $transcript] = $this->overSmtp(['RCPT' => '550 5.1.1 <' . self::TO . '>: Recipient address rejected: User unknown in virtual mailbox table']);
        self::assertFalse($delivery->ok);
        self::assertSame(['rcpt', 550, '5.1.1'], [$delivery->stage, $delivery->code, $delivery->enhanced]);
        self::assertTrue($delivery->hardBounce());
        self::assertFalse($delivery->retryable());
        self::assertFalse($delivery->uncertain());
        self::assertSame('rcpt 550 5.1.1', $delivery->summary());
        self::assertStringNotContainsString('DATA', $transcript, 'nothing of the message went');
        $this->assertNothingOfTheServerOrTheAddress($delivery);
    }

    public function testARefusalBeforeTheMessageIsRetryable(): void
    {
        $cases = [
            'rcpt 451 4.7.1' => [['RCPT' => '451 4.7.1 <' . self::TO . '>: Recipient address rejected: Try again later'], []],
            'mail 554 5.7.1' => [['MAIL' => '554 5.7.1 <no-reply@patternsshowcontrol.co.uk>: Sender address rejected: Access denied'], []],
            'auth 535 5.7.8' => [['AUTH_PASS' => '535 5.7.8 Error: authentication failed: ' . self::TO], ['user' => 'no-reply@patternsshowcontrol.co.uk', 'password' => 'password']],
            'greeting 554 5.3.2' => [['greeting' => '554 5.3.2 No service here, try again later'], []],
            'data 451 4.3.0' => [['DATA' => '451 4.3.0 Try again later'], []],
            'rcpt 550' => [['RCPT' => '550 Recipient address rejected'], []],
            // Postfix's sender restrictions, reported at RCPT (smtpd_delay_reject): about the sender, never the address.
            'rcpt 550 5.1.8' => [['RCPT' => '550 5.1.8 <no-reply@patternsshowcontrol.co.uk>: Sender address rejected: Domain not found'], []],
            'rcpt 550 5.1.7' => [['RCPT' => '550 5.1.7 <no-reply@patternsshowcontrol.co.uk>: Sender address rejected: undeliverable address'], []],
            'rcpt 550 5.1.0' => [['RCPT' => '550 5.1.0 <no-reply@patternsshowcontrol.co.uk>: Sender address rejected: User unknown in virtual mailbox table'], []],
        ];
        foreach ($cases as $summary => [$script, $smtp]) {
            [$delivery, $transcript] = $this->overSmtp($script, $smtp);
            self::assertFalse($delivery->ok, $summary);
            self::assertTrue($delivery->retryable(), $summary);
            self::assertFalse($delivery->uncertain() || $delivery->hardBounce(), $summary);
            self::assertSame($summary, $delivery->summary());
            self::assertStringNotContainsString("\r\n.\r\n", $transcript, 'nothing of the message went: ' . $summary);
            $this->assertNothingOfTheServerOrTheAddress($delivery);
        }
    }

    public function testARefusedConnectionStopsAtConnect(): void
    {
        $delivery = $this->smtpMailer(['port' => FakeSmtpServer::closedPort()])->deliverNow(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales']);
        self::assertSame(['connect', 0], [$delivery->stage, $delivery->code]);
        self::assertTrue($delivery->retryable());
        self::assertSame('connect', $delivery->summary());
        $this->assertNothingOfTheServerOrTheAddress($delivery);
    }

    /**
     * A 4xx or 5xx reply to the whole message says the server did not take it (RFC 5321 §4.2.5), so it is no unknown
     * outcome: after a 4xx it may go again, and a 5xx is final, never sent again.
     */
    public function testARefusalAfterTheMessageIsNotUncertain(): void
    {
        [$delivery, $transcript] = $this->overSmtp(['body' => '451 4.7.1 Try again later, mentioning ' . self::TO]);
        self::assertSame(['body', 451, '4.7.1', false], [$delivery->stage, $delivery->code, $delivery->enhanced, $delivery->timeout]);
        self::assertTrue($delivery->retryable());
        self::assertFalse($delivery->uncertain() || $delivery->hardBounce());
        self::assertSame('body 451 4.7.1', $delivery->summary());
        self::assertStringContainsString("\r\n.\r\n", $transcript);
        $this->assertNothingOfTheServerOrTheAddress($delivery);

        [$delivery] = $this->overSmtp(['body' => '554 5.7.1 Message rejected as spam, mentioning ' . self::TO]);
        self::assertSame(['body', 554, '5.7.1', false], [$delivery->stage, $delivery->code, $delivery->enhanced, $delivery->timeout]);
        self::assertFalse($delivery->retryable() || $delivery->uncertain() || $delivery->hardBounce(), 'a 5xx after the message is final');
        self::assertSame('body 554 5.7.1', $delivery->summary());
        $this->assertNothingOfTheServerOrTheAddress($delivery);
    }

    public function testNoAcceptanceAfterTheMessageIsUncertain(): void
    {
        [$delivery, $transcript] = $this->overSmtp(['body' => null], ['timeout' => 1]);
        self::assertSame(['body', 0, true], [$delivery->stage, $delivery->code, $delivery->timeout]);
        self::assertTrue($delivery->uncertain());
        self::assertFalse($delivery->retryable());
        self::assertSame('body timeout', $delivery->summary());
        self::assertStringContainsString("\r\n.\r\n", $transcript);

        [$delivery] = $this->overSmtp(['body' => 'CLOSE']);
        self::assertSame(['body', 0, false], [$delivery->stage, $delivery->code, $delivery->timeout], 'a hang-up after the message');
        self::assertTrue($delivery->uncertain());
        $this->assertNothingOfTheServerOrTheAddress($delivery);
    }

    public function testAQuitThatFailsAfterTheMessageIsStillDone(): void
    {
        foreach (['CLOSE', '500 5.5.1 What?', null] as $quit) {
            [$delivery] = $this->overSmtp(['QUIT' => $quit], ['timeout' => 1]);
            self::assertTrue($delivery->ok, var_export($quit, true));
            self::assertSame('done', $delivery->stage);
        }
    }

    public function testSendOverSmtpReportsWhetherTheServerTookIt(): void
    {
        $server = FakeSmtpServer::start([], $this->storage);
        self::assertTrue($this->smtpMailer(['port' => $server->port])->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales']));
        $server->stop();
        $server = FakeSmtpServer::start(['RCPT' => '550 5.1.1 User unknown'], $this->storage);
        self::assertFalse($this->smtpMailer(['port' => $server->port])->send(self::TO, 'Subject', 'contact-receipt', ['topicLabel' => 'Sales']));
        $server->stop();
        self::assertStringContainsString('"reply":"rcpt 550 5.1.1"', $this->siteLog());
        self::assertStringContainsString('"to_domain":"example.test"', $this->siteLog());
    }

    public function testADeliverySaysOnlyItsStageAndCodes(): void
    {
        self::assertTrue(Delivery::sent()->ok);
        self::assertSame('done', Delivery::sent()->summary());
        self::assertSame('rcpt 550', (new Delivery(false, 'rcpt', 550, 'User unknown <' . self::TO . '>'))->summary(), 'an enhanced code not of its shape is left out');
        self::assertSame('unknown 250', (new Delivery(false, self::TO, 250))->summary(), 'a stage not of the list is left out');
        self::assertSame('body timeout', (new Delivery(false, 'body', 0, '', true))->summary());
        self::assertSame('greeting 421 4.3.2 timeout', (new Delivery(false, 'greeting', 421, '4.3.2', true))->summary());
        self::assertSame('refused', (new Delivery(false, 'refused'))->summary());
        foreach (Delivery::STAGES as $stage) {
            self::assertLessThanOrEqual(40, strlen((new Delivery(false, $stage, 554, '5.123.456', true))->summary()));
        }
        $refused = new Delivery(false, 'refused');
        self::assertFalse($refused->retryable() || $refused->uncertain() || $refused->hardBounce(), 'refused is none of the three: the sender stops the digest');
        self::assertFalse((new Delivery(false, 'mail', 550, '5.1.0'))->hardBounce(), 'only at RCPT is it the address');
        self::assertTrue((new Delivery(false, 'mail', 550, '5.1.0'))->retryable());
        self::assertFalse((new Delivery(false, 'rcpt', 450, '4.1.1'))->hardBounce(), 'a 4.1.x is not a bounce');
        // Only the codes that say the recipient's address is bad bounce; every other 5.1.x at RCPT is retried, so a
        // fault of the sender's that hits the whole list never unsubscribes anyone.
        foreach (['5.1.1', '5.1.2', '5.1.3', '5.1.6', '5.1.10'] as $code) {
            self::assertTrue((new Delivery(false, 'rcpt', 550, $code))->hardBounce(), $code);
            self::assertFalse((new Delivery(false, 'rcpt', 550, $code))->retryable(), $code);
        }
        foreach (['5.1.0', '5.1.4', '5.1.7', '5.1.8', '5.1.9', '5.1.11'] as $code) {
            self::assertFalse((new Delivery(false, 'rcpt', 550, $code))->hardBounce(), $code);
            self::assertTrue((new Delivery(false, 'rcpt', 550, $code))->retryable(), $code);
        }
        self::assertFalse((new Delivery(false, 'rcpt', 450, '5.1.1'))->hardBounce(), 'a 4xx reply is never a bounce, whatever its enhanced code');
        // After the message, only a reply that is neither taken nor refused (none, or a 2xx or 3xx other than 250) is unknown.
        foreach ([[421, true, false], [452, true, false], [550, false, false], [554, false, false], [0, false, true], [251, false, true], [354, false, true]] as [$code, $retry, $unknown]) {
            $body = new Delivery(false, 'body', $code);
            self::assertSame([$retry, $unknown, false], [$body->retryable(), $body->uncertain(), $body->hardBounce()], 'body ' . $code);
        }
        self::assertFalse((new Delivery(true, 'done'))->retryable());
    }
}
