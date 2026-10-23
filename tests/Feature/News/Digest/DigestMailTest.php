<?php

declare(strict_types=1);

namespace Psc\Tests\Feature\News\Digest;

use Psc\Mail\Delivery;
use Psc\Mail\Mailer;
use Psc\News\Digest\Courier;
use Psc\News\Digest\DigestMail;
use Psc\News\Digest\Links;
use Psc\News\Digest\Tokens;
use Psc\Tests\TestCase;

/**
 * The digest's emails as the log driver writes them (docs/NEWS.md §14.8, §14.9, §14.14): the confirmation; each
 * subscriber's digest with its subject, preheader, articles, footer, list headers and written text part; the desk's
 * test and preview, which carry only the test selector; and the administrators' account-deleted email's digest line.
 * The digest and subscriber rows are built by hand in the shapes §14.3 fixes.
 */
final class DigestMailTest extends TestCase
{
    private const SELECTOR = '0123456789abcdef01234567';
    private const BASE = 'https://patternsshowcontrol.test';
    private const LONG_TITLE = 'Projector makers agree a common protocol for edge blending across brands, with the first firmware due before the spring trade shows';

    private static function london(string $at): int
    {
        return (new \DateTimeImmutable($at, new \DateTimeZone('Europe/London')))->getTimestamp();
    }

    /** @return array<string, mixed> a news_digest_subscribers row */
    private static function subscriber(string $source = 'page', string $email = 'reader@example.test'): array
    {
        return [
            'id' => 7,
            'email' => $email,
            'email_hash' => str_repeat('a', 64),
            'user_id' => null,
            'state' => 'confirmed',
            'source' => $source,
            'selector' => self::SELECTOR,
            'consent_version' => '2026-10-23',
            'privacy_version' => '2026-10-23',
            'requested_at' => self::london('2026-10-01 11:58'),
            'confirmed_at' => self::london('2026-10-01 12:00'),
            'unsubscribed_at' => null,
            'unsubscribe_method' => null,
            'bounces' => 0,
            'bounced_at' => null,
        ];
    }

    /**
     * The digest shape (Composer): three articles, the newest first, and $leftOut more.
     *
     * @return array<string, mixed>
     */
    private static function digest(int $leftOut = 0, bool $sample = false, ?int $articles = null): array
    {
        $items = [
            ['id' => 31, 'title' => self::LONG_TITLE, 'dek' => 'Six makers sign the **draft**, and the first [firmware](https://example.com/fw) ships in March.', 'category' => 'video', 'category_name' => 'Video', 'path' => '/news/video/projector-makers-agree-a-common-protocol/'],
            ['id' => 30, 'title' => 'A new console desk for small theatres & studios', 'dek' => 'Twelve faders, one screen and a price for small rooms.', 'category' => 'lighting', 'category_name' => 'Lighting', 'path' => '/news/lighting/a-new-console-desk/'],
            ['id' => 29, 'title' => 'Streaming encoders get a firmware update', 'dek' => 'Lower latency on the same hardware.', 'category' => 'streaming', 'category_name' => 'Streaming', 'path' => '/news/streaming/streaming-encoders-update/'],
        ];
        return [
            'id' => $sample ? null : 4,
            'week' => '2026-w43',
            'send_at' => self::london('2026-10-23 09:00'),
            'window_start' => self::london('2026-10-16 09:00'),
            'window_end' => self::london('2026-10-23 09:00'),
            'articles' => array_slice($items, 0, $articles ?? 3),
            'left_out' => $leftOut,
            'sample' => $sample,
        ];
    }

    /** @return list<string> the .eml files the log driver wrote, oldest first */
    private function emls(): array
    {
        $files = glob($this->storage . '/mail/*.eml') ?: [];
        sort($files);
        return array_map(static fn (string $f): string => (string) file_get_contents($f), $files);
    }

    private static function head(string $raw): string
    {
        return (string) preg_replace("/\r\n(?=[ \t])/", '', explode("\r\n\r\n", $raw, 2)[0]);
    }

    /** @return list<string> every href in $html, decoded */
    private static function hrefs(string $html): array
    {
        preg_match_all('/href="([^"]*)"/', $html, $m);
        return array_map(static fn (string $h): string => html_entity_decode($h, ENT_QUOTES | ENT_HTML5), $m[1]);
    }

    private static function token(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return isset($query['token']) && is_string($query['token']) ? $query['token'] : null;
    }

    private function assertTextLedAndOnSite(string $html): void
    {
        self::assertStringNotContainsStringIgnoringCase('<img', $html, 'no image of any kind');
        self::assertStringNotContainsStringIgnoringCase('<script', $html);
        foreach (self::hrefs($html) as $href) {
            self::assertTrue(
                str_starts_with($href, self::BASE . '/') || str_starts_with($href, 'mailto:unsubscribe@patternsshowcontrol.co.uk?'),
                'every link is to the site or the unsubscribe mailbox: ' . $href
            );
        }
    }

    public function testTheConfirmation(): void
    {
        self::assertTrue((new DigestMail($this->app))->confirmation(self::subscriber()));
        $mail = $this->mails()[0];
        self::assertSame('reader@example.test', $mail['to']);
        self::assertSame('Confirm your Industry Updates digest', $mail['subject']);
        self::assertSame('digest-confirm', $mail['template']);
        self::assertSame([], $mail['headers'], 'a confirmation is no list message');
        self::assertStringStartsWith(self::BASE . Links::CONFIRM . '?token=', $mail['data']['url']);
        self::assertSame(['selector' => self::SELECTOR, 'generation' => 0], Tokens::fromApp($this->app)->readConfirm((string) self::token($mail['data']['url'])));
        self::assertTrue((new DigestMail($this->app))->confirmation(['generation' => 4] + self::subscriber()));
        self::assertSame(['selector' => self::SELECTOR, 'generation' => 4], Tokens::fromApp($this->app)->readConfirm((string) self::token($this->mails()[1]['data']['url'])), 'the link carries the row\'s generation');
        self::assertNull(Tokens::fromApp($this->app)->read(Tokens::UNSUBSCRIBE, (string) self::token($mail['data']['url'])), 'it never unsubscribes');

        $html = $mail['html'];
        self::assertStringContainsString('<h1 style="font-size:18px;margin:0 0 12px;">Confirm your weekly digest</h1>', $html);
        self::assertStringContainsString('Someone, most likely you, asked for the weekly Industry Updates digest from Patterns Show Control to be sent to reader@example.test.', $html);
        self::assertStringContainsString('To start it, press the button below, then the button on the page it opens. Nothing is sent until you do.', $html);
        self::assertStringContainsString('>Confirm my digest</a>', $html);
        self::assertStringContainsString('Or paste this link into your browser:', $html);
        self::assertStringContainsString('The link works for 7 days. If you did not ask for this, you can ignore this email and you will hear nothing more from us.', $html);
        self::assertStringContainsString('every one has a link to a page where one button unsubscribes you.', $html);
        self::assertStringNotContainsString('one click', $html, 'the body\'s link opens a page with a button: two steps, never called one click');
        self::assertStringContainsString('From Patterns Show Control, run by Ben Rohleder, United Kingdom. How we use your address: <a href="' . self::BASE . '/legal/privacy/" style="color:#5b616e;">our privacy notice</a>.', $html);
        self::assertStringContainsString('You received this because of an action on', $html, 'the layout\'s own footer line');
        $this->assertTextLedAndOnSite($html);

        $text = $mail['text'];
        self::assertStringStartsWith('Confirm your weekly digest', $text);
        self::assertStringContainsString("\n" . $mail['data']['url'] . "\n", $text, 'the link written out');
        self::assertStringContainsString('7 days', $text);
        self::assertStringContainsString('From Patterns Show Control, run by Ben Rohleder, United Kingdom.', $text);
        self::assertStringContainsString(self::BASE . '/legal/privacy/', $text);

        $raw = $this->emls()[0];
        self::assertStringNotContainsString('List-Unsubscribe', $raw);
        self::assertStringNotContainsString('List-Id', $raw);
        self::assertStringContainsString("\r\nFrom: Patterns Show Control <no-reply@patternsshowcontrol.co.uk>\r\n", self::head($raw) . "\r\n");
    }

    public function testAConfirmationNeedsAnAddressAndARealSelector(): void
    {
        $courier = new DigestMail($this->app);
        self::assertFalse($courier->confirmation(['email' => null] + self::subscriber()));
        self::assertFalse($courier->confirmation(['selector' => 'nope'] + self::subscriber()));
        self::assertFalse($courier->confirmation(['selector' => Tokens::TEST_SELECTOR] + self::subscriber()));
        self::assertSame([], $this->mails());
    }

    public function testADigest(): void
    {
        $delivery = (new DigestMail($this->app))->digest(self::digest(4), self::subscriber());
        self::assertTrue($delivery->ok);
        self::assertSame('done', $delivery->stage);
        $mail = $this->mails()[0];
        self::assertSame('reader@example.test', $mail['to']);
        self::assertSame('This week in Industry Updates: Projector makers agree a common protocol for edge blending across brands, with', $mail['subject'], 'the lead headline, the whole cut at a word to 110 characters');
        self::assertLessThanOrEqual(110, mb_strlen($mail['subject']));
        self::assertSame('7 new articles this week on Industry Updates, the newest first.', $mail['data']['_preheader'], 'the three shown and the four left out');

        $html = $mail['html'];
        self::assertMatchesRegularExpression('/<body [^>]*>\n<div style="display:none;[^"]*">7 new articles this week on Industry Updates, the newest first\.<\/div>/', $html);
        self::assertStringContainsString('The week in Industry Updates</h1>', $html);
        self::assertStringContainsString('Every article that went live between Friday 16 October and Friday 23 October 2026, the newest first.', $html);
        $campaign = '?utm_source=digest&utm_medium=email&utm_campaign=2026-w43';
        foreach (self::digest()['articles'] as $article) {
            self::assertStringContainsString('>' . e($article['category_name']) . '</p>', $html);
            self::assertStringContainsString('<a href="' . e(self::BASE . $article['path'] . $campaign) . '" style="color:#15171c;">' . e($article['title']) . '</a></h2>', $html, 'each headline links to its article with exactly the campaign');
        }
        self::assertStringContainsString('<p style="margin:0;">Six makers sign the draft, and the first firmware ships in March.</p>', $html, 'the standfirst as plain text');
        self::assertLessThan(strpos($html, 'A new console desk'), strpos($html, 'Projector makers'), 'in the digest\'s order, the newest first');
        self::assertStringContainsString('<p>And 4 more this week on <a href="' . e(self::BASE . '/news/' . $campaign) . '" style="color:#15171c;">Industry Updates</a>.</p>', $html);
        self::assertStringContainsString('>Open Industry Updates</a>', $html);
        self::assertStringNotContainsString('A test of the next digest', $html);

        $token = Tokens::fromApp($this->app)->make(Tokens::UNSUBSCRIBE, self::SELECTOR);
        $unsubscribe = self::BASE . '/digest/unsubscribe/?token=' . $token;
        $mailto = 'mailto:unsubscribe@patternsshowcontrol.co.uk?subject=Unsubscribe%20' . $token;
        self::assertStringContainsString('You get this weekly digest at reader@example.test because you signed up for it on our website and confirmed it on 1 October 2026.', $html);
        self::assertStringContainsString('To stop it, <a href="' . e($unsubscribe) . '" style="color:#5b616e;">open this link and press Unsubscribe</a> or email <a href="' . e($mailto) . '" style="color:#5b616e;">unsubscribe@patternsshowcontrol.co.uk</a>.', $html);
        self::assertStringNotContainsString('one click', $html);
        self::assertStringContainsString('From Patterns Show Control, run by Ben Rohleder, United Kingdom. How we use your address: <a href="' . self::BASE . '/legal/privacy/" style="color:#5b616e;">our privacy notice</a>.', $html);
        self::assertStringNotContainsString('You received this because of an action on', $html, 'the digest\'s footer takes the layout\'s line');
        $this->assertTextLedAndOnSite($html);
        foreach (self::hrefs($html) as $href) {
            if (str_starts_with($href, self::BASE . '/news/')) {
                self::assertStringNotContainsString(self::SELECTOR, $href, 'no per-person identifier in an article link');
            }
        }

        self::assertSame([
            'List-Unsubscribe' => '<' . $unsubscribe . '>, <' . $mailto . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'List-Id' => 'Industry Updates weekly digest <digest.patternsshowcontrol.test>',
        ], $mail['headers'], 'exactly the three headers, with this subscriber\'s token');
        $head = self::head($this->emls()[0]);
        self::assertStringContainsString("\r\nAuto-Submitted: auto-generated\r\nList-Unsubscribe: <" . $unsubscribe . '>, <' . $mailto . ">\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\nList-Id: Industry Updates weekly digest <digest.patternsshowcontrol.test>", $head);
        self::assertStringContainsString("\r\nFrom: Patterns Show Control <no-reply@patternsshowcontrol.co.uk>", "\r\n" . $head, 'From as on every other email');
        foreach (['Reply-To:', 'Bcc:', 'Cc:', 'Precedence:'] as $absent) {
            self::assertStringNotContainsString($absent, $head);
        }
        self::assertStringContainsString("\r\nTo: reader@example.test\r\n", $head, 'one recipient a message');
    }

    public function testTheTextPartWritesEveryLinkOut(): void
    {
        (new DigestMail($this->app))->digest(self::digest(4), self::subscriber());
        $text = $this->mails()[0]['text'];
        $token = Tokens::fromApp($this->app)->make(Tokens::UNSUBSCRIBE, self::SELECTOR);
        $campaign = '?utm_source=digest&utm_medium=email&utm_campaign=2026-w43';
        self::assertStringStartsWith("The week in Industry Updates\nEvery article that went live between Friday 16 October and Friday 23 October 2026, the newest first.\n", $text);
        self::assertStringContainsString("\nVideo\n" . self::LONG_TITLE . "\nSix makers sign the draft, and the first firmware ships in March.\n" . self::BASE . '/news/video/projector-makers-agree-a-common-protocol/' . $campaign . "\n", $text, 'one article a block: category, headline, standfirst, link');
        self::assertStringContainsString("\nA new console desk for small theatres & studios\n", $text, 'plain text, never escaped');
        self::assertStringContainsString("\nAnd 4 more this week on Industry Updates: " . self::BASE . '/news/' . $campaign . "\n", $text);
        self::assertStringContainsString("\nOpen Industry Updates: " . self::BASE . '/news/' . $campaign . "\n", $text);
        self::assertStringContainsString("\nYou get this weekly digest at reader@example.test because you signed up for it on our website and confirmed it on 1 October 2026.\n", $text);
        self::assertStringContainsString("\nTo stop it, open this link and press Unsubscribe: " . self::BASE . '/digest/unsubscribe/?token=' . $token . "\n", $text);
        self::assertStringContainsString("\nOr email unsubscribe@patternsshowcontrol.co.uk with the subject: Unsubscribe " . $token . "\n", $text);
        self::assertStringEndsWith('From Patterns Show Control, run by Ben Rohleder, United Kingdom. How we use your address: our privacy notice, ' . self::BASE . '/legal/privacy/', $text);
        self::assertDoesNotMatchRegularExpression('/<[a-z\/][^>]*>/i', $text, 'no markup in the text part');
        self::assertNotSame(Mailer::toText($this->mails()[0]['html']), $text, 'a written text part, not the derived one');
    }

    public function testTheFooterSaysWhyForEachSource(): void
    {
        $courier = new DigestMail($this->app);
        $why = [
            'page' => 'You get this weekly digest at reader@example.test because you signed up for it on our website and confirmed it on 1 October 2026.',
            'account' => 'You get this weekly digest at reader@example.test because you ticked the box for it on your account page and confirmed it on 1 October 2026.',
            'registration' => 'You get this weekly digest at reader@example.test because you ticked the box for it when you created your account and confirmed it on 1 October 2026.',
        ];
        foreach ($why as $source => $sentence) {
            $parts = $courier->render(self::digest(), self::subscriber($source));
            self::assertStringContainsString($sentence, $parts['html'], $source);
            self::assertStringContainsString("\n" . $sentence . "\n", $parts['text'], $source);
        }
    }

    public function testThePreheaderForOneArticle(): void
    {
        $digest = self::digest(0, false, 1);
        self::assertSame('One new article this week on Industry Updates.', DigestMail::preheader($digest));
        self::assertSame('2 new articles this week on Industry Updates, the newest first.', DigestMail::preheader(self::digest(0, false, 2)));
        self::assertSame('16 new articles this week on Industry Updates, the newest first.', DigestMail::preheader(['articles' => array_fill(0, 15, []), 'left_out' => 1]));
        $html = (new DigestMail($this->app))->render($digest, self::subscriber())['html'];
        self::assertStringContainsString('>One new article this week on Industry Updates.</div>', $html);
        self::assertStringNotContainsString('more this week on', $html, 'no "And :n more" without articles left out');
        self::assertSame('This week in Industry Updates: A new console desk', DigestMail::subject(['articles' => [['title' => "A new  console\ndesk"]]]), 'a short headline whole, its spaces made one');
        self::assertSame('[Test] This week in Industry Updates: A new console desk', DigestMail::subject(['articles' => [['title' => 'A new console desk']]], true));
    }

    public function testATest(): void
    {
        $delivery = (new DigestMail($this->app))->test(self::digest(0, true), 'admin@example.test');
        self::assertTrue($delivery->ok);
        $mail = $this->mails()[0];
        self::assertSame('admin@example.test', $mail['to']);
        self::assertSame('[Test] This week in Industry Updates: Projector makers agree a common protocol for edge blending across brands, with', $mail['subject']);
        $html = $mail['html'];
        self::assertStringContainsString('A test of the next digest, sent from the desk. Its unsubscribe links change nothing. No article has gone live since the last digest yet, so these are the newest articles instead.', $html);
        self::assertStringNotContainsString('Every article that went live between', $html, 'no window on a sample');
        self::assertStringContainsString('You get this test because you sent it from the Industry Updates desk.', $html);
        self::assertStringContainsString("A test of the next digest, sent from the desk. Its unsubscribe links change nothing. No article has gone live since the last digest yet, so these are the newest articles instead.\n", $mail['text']);
        self::assertStringContainsString("\nYou get this test because you sent it from the Industry Updates desk.\n", $mail['text']);

        $test = Tokens::fromApp($this->app)->make(Tokens::UNSUBSCRIBE, Tokens::TEST_SELECTOR);
        self::assertStringContainsString(e(self::BASE . '/digest/unsubscribe/?token=' . $test), $html);
        self::assertStringContainsString('<' . self::BASE . '/digest/unsubscribe/?token=' . $test . '>, <mailto:unsubscribe@patternsshowcontrol.co.uk?subject=Unsubscribe%20' . $test . '>', $mail['headers']['List-Unsubscribe']);
        self::assertStringNotContainsString(self::SELECTOR, $html . $mail['text'] . implode("\n", $mail['headers']));
        $this->assertTextLedAndOnSite($html);

        $delivery = (new DigestMail($this->app))->test(self::digest(), 'admin@example.test');
        self::assertTrue($delivery->ok);
        self::assertStringContainsString('Its unsubscribe links change nothing.</p>', $this->mails()[1]['html'], 'no sample line when the week has articles');
        self::assertStringContainsString('Every article that went live between', $this->mails()[1]['html']);

        self::assertSame('refused', (new DigestMail($this->app))->test(self::digest(), 'not an address')->stage);
    }

    public function testThePreviewCarriesTheTestSelectorAndTheSampleWords(): void
    {
        $parts = (new DigestMail($this->app))->render(self::digest(0, true), null);
        self::assertSame(['subject', 'preheader', 'html', 'text', 'headers'], array_keys($parts));
        self::assertSame('This week in Industry Updates: Projector makers agree a common protocol for edge blending across brands, with', $parts['subject']);
        self::assertSame('3 new articles this week on Industry Updates, the newest first.', $parts['preheader']);
        $test = Tokens::fromApp($this->app)->make(Tokens::UNSUBSCRIBE, Tokens::TEST_SELECTOR);
        self::assertStringContainsString('You get this weekly digest at name@example.co.uk because you signed up for it on our website and confirmed it on ', $parts['html']);
        self::assertStringNotContainsString('A test of the next digest', $parts['html'], 'the preview shows the email as a subscriber gets it');
        self::assertStringContainsString(e(self::BASE . '/digest/unsubscribe/?token=' . $test), $parts['html']);
        self::assertSame(DigestMail::headers($this->app, $test), $parts['headers']);
        self::assertStringContainsString('name@example.co.uk', $parts['text']);
        self::assertSame([], $this->mails(), 'a preview sends nothing');
        self::assertSame([], $this->emls());
    }

    public function testTheHeadersAreExactlyTheDesigns(): void
    {
        $token = '4f0c0123456789abcdef01e2.Q2xYw0123456789abcdefghijklmnopqrstuvwxyzAB';
        self::assertSame([
            'List-Unsubscribe' => '<https://patternsshowcontrol.test/digest/unsubscribe/?token=' . $token . '>, <mailto:unsubscribe@patternsshowcontrol.co.uk?subject=Unsubscribe%20' . $token . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'List-Id' => 'Industry Updates weekly digest <digest.patternsshowcontrol.test>',
        ], DigestMail::headers($this->app, $token));

        $this->bootApp(['app' => ['url' => 'https://www.patternsshowcontrol.co.uk/'], 'mail' => ['unsubscribe_to' => 'leave@example.test']]);
        $headers = DigestMail::headers($this->app, $token);
        self::assertSame('Industry Updates weekly digest <digest.patternsshowcontrol.co.uk>', $headers['List-Id'], 'the host of app.url without www.');
        self::assertSame('<https://www.patternsshowcontrol.co.uk/digest/unsubscribe/?token=' . $token . '>, <mailto:leave@example.test?subject=Unsubscribe%20' . $token . '>', $headers['List-Unsubscribe'], 'the base exactly as the site answers, with the routes\' slash');
    }

    public function testNoDigestGoesWithoutBothWaysOutOrThroughPhpMail(): void
    {
        $this->bootApp(['mail' => ['unsubscribe_to' => '']]);
        $courier = new DigestMail($this->app);
        self::assertSame('refused', $courier->digest(self::digest(), self::subscriber())->stage);
        self::assertSame('refused', $courier->test(self::digest(), 'admin@example.test')->stage);
        self::assertSame([], $this->mails());
        self::assertStringNotContainsString('mailto:', DigestMail::headers($this->app, 'x')['List-Unsubscribe']);
        $log = (string) file_get_contents((string) (glob($this->storage . '/logs/*.log') ?: [''])[0]);
        self::assertStringContainsString('"digest refused"', $log);

        // A value that is not a plain address names no mailbox a mail client could write to.
        foreach (['Unsubscribe <leave@example.test>', 'unsubscribe'] as $address) {
            $this->bootApp(['mail' => ['unsubscribe_to' => $address]]);
            $courier = new DigestMail($this->app);
            self::assertSame('refused', $courier->digest(self::digest(), self::subscriber())->stage, $address);
            self::assertSame('refused', $courier->test(self::digest(), 'admin@example.test')->stage, $address);
            self::assertSame([], $this->mails(), $address);
        }

        $this->bootApp(['mail' => ['driver' => 'mail']]);
        $courier = new DigestMail($this->app);
        self::assertSame('refused', $courier->digest(self::digest(), self::subscriber())->stage, 'never through PHP mail()');
        self::assertSame('refused', $courier->test(self::digest(), 'admin@example.test')->stage);
        self::assertSame([], $this->mails());
    }

    public function testADigestThatCannotBeBuiltIsRefused(): void
    {
        $courier = new DigestMail($this->app);
        $cases = [
            'no address' => [self::digest(), ['email' => null] + self::subscriber()],
            'a bad selector' => [self::digest(), ['selector' => 'nope'] + self::subscriber()],
            'the test selector' => [self::digest(), ['selector' => Tokens::TEST_SELECTOR] + self::subscriber()],
            'no week' => [['week' => ''] + self::digest(), self::subscriber()],
            'an article without its path' => [['articles' => [['title' => 'x', 'path' => 'news/x']]] + self::digest(), self::subscriber()],
        ];
        foreach ($cases as $why => [$digest, $subscriber]) {
            $delivery = $courier->digest($digest, $subscriber);
            self::assertInstanceOf(Delivery::class, $delivery);
            self::assertSame('refused', $delivery->stage, $why);
            self::assertFalse($delivery->retryable() || $delivery->uncertain() || $delivery->hardBounce(), $why);
        }
        self::assertSame([], $this->mails());
        $log = '';
        foreach (glob($this->storage . '/logs/*.log') ?: [] as $file) {
            $log .= (string) file_get_contents($file);
        }
        self::assertStringNotContainsString('reader@example.test', $log, 'no address in the log');
        self::assertStringNotContainsString(self::SELECTOR, $log, 'no selector in the log');
    }

    public function testEveryCallerTakesTheCourierThatATestSet(): void
    {
        self::assertInstanceOf(DigestMail::class, DigestMail::courier($this->app));
        $fake = new class implements Courier {
            public function confirmation(array $subscriber): bool
            {
                return true;
            }

            public function digest(array $digest, array $subscriber): Delivery
            {
                return Delivery::sent();
            }

            public function test(array $digest, string $to): Delivery
            {
                return Delivery::sent();
            }

            public function render(array $digest, ?array $subscriber): array
            {
                return ['subject' => '', 'preheader' => '', 'html' => '', 'text' => '', 'headers' => []];
            }
        };
        DigestMail::useCourier($fake);
        self::assertSame($fake, DigestMail::courier($this->app));
        DigestMail::useCourier(null);
        self::assertInstanceOf(DigestMail::class, DigestMail::courier($this->app));
    }

    public function testTheAccountDeletedEmailSaysTheSubscriptionEnded(): void
    {
        $data = [
            'id' => 12,
            'name' => 'Test Client',
            'email' => 'client@example.test',
            'company' => '',
            'kept' => ['licences' => 0, 'tickets' => 1, 'comments' => 2],
        ];
        $ended = '<p><strong>Weekly digest:</strong> their subscription ended, and their address was deleted from it.</p>';
        $request = '<p><strong>Weekly digest:</strong> their unconfirmed request was deleted.</p>';
        foreach (['unsubscribed' => [true, false], 'removed' => [false, true], 'none' => [false, false]] as $answer => [$endedShown, $requestShown]) {
            $html = $this->mail->render('An account was deleted', 'account-deleted-admin', $data + ['removed' => ['news_comments' => 0, 'news_reports' => 0, 'news_digest' => $answer]])['html'];
            self::assertSame([$endedShown, $requestShown], [str_contains($html, $ended), str_contains($html, $request)], $answer . ': a request never started is no subscription that ended');
        }
        $html = $this->mail->render('An account was deleted', 'account-deleted-admin', $data + ['removed' => ['news_comments' => 1, 'news_reports' => 0]])['html'];
        self::assertStringNotContainsString('Weekly digest', $html, 'an older call without the key has no line');
        self::assertStringContainsString('1 comment on Industry Updates articles', $html);
    }
}
