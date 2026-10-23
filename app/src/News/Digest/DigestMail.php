<?php

declare(strict_types=1);

namespace Psc\News\Digest;

use Psc\App;
use Psc\Mail\Delivery;
use Psc\News\Schedule;

/**
 * The digest's emails, sent through the site's Mailer (docs/NEWS.md §14.3, §14.8): the confirmation, each
 * subscriber's digest, the desk's test and its preview.
 *
 * Every digest carries both ways out (the one-click https link and the mailto in List-Unsubscribe, and the same two
 * in its footer), each with the token of that subscriber's own selector; a test and the preview carry the test
 * selector's, which no row has. Its links to the site carry the week's static campaign tags and nothing per person.
 * A digest that cannot be built (no address, no unsubscribe address, a template that fails), or would go through PHP
 * mail(), is refused before any connection, and the site log says why. Nothing here logs an address, a token or a
 * selector.
 */
final class DigestMail implements Courier
{
    public const CONFIRM_SUBJECT = 'Confirm your Industry Updates digest';
    public const SUBJECT_LEAD = 'This week in Industry Updates';
    public const SUBJECT_MAX = 110;

    /** The address the preview's footer names in place of a subscriber's. */
    public const SAMPLE_EMAIL = 'name@example.co.uk';

    /** The email partial (templates/emails/_digest-footer.php) drawn in place of the layout's footer line. */
    public const FOOTER = 'digest-footer';

    private static ?Courier $override = null;

    private ?Tokens $tokens;

    public function __construct(private readonly App $app, ?Tokens $tokens = null)
    {
        $this->tokens = $tokens;
    }

    /** The courier every caller uses: the one a test set with useCourier(), else a new DigestMail. */
    public static function courier(App $app): Courier
    {
        return self::$override ?? new self($app);
    }

    /** Makes courier() answer $courier (null: a new DigestMail again). tests/TestCase.php resets it after each test. */
    public static function useCourier(?Courier $courier): void
    {
        self::$override = $courier;
    }

    /**
     * The confirmation to the row's address, its link carrying the row's generation, sent as a page's email (its
     * delivery after the response). No list headers: it is no list message.
     */
    public function confirmation(array $subscriber): bool
    {
        $email = trim((string) ($subscriber['email'] ?? ''));
        $selector = (string) ($subscriber['selector'] ?? '');
        $generation = (int) ($subscriber['generation'] ?? 0);
        if ($email === '' || !self::isSelector($selector) || $selector === Tokens::TEST_SELECTOR || $generation < 0) {
            $this->app->log->warning('digest confirmation refused', ['reason' => $email === '' ? 'no address' : 'bad row']);
            return false;
        }
        return $this->app->mailer()->send($email, self::CONFIRM_SUBJECT, 'digest-confirm', [
            'email' => $email,
            'url' => $this->app->baseUrl() . Links::confirm($this->tokens()->make(Tokens::CONFIRM, $selector, $generation)),
            'publisher' => $this->publisher(),
        ]);
    }

    public function digest(array $digest, array $subscriber): Delivery
    {
        $email = trim((string) ($subscriber['email'] ?? ''));
        $selector = (string) ($subscriber['selector'] ?? '');
        if ($email === '' || !self::isSelector($selector) || $selector === Tokens::TEST_SELECTOR) {
            $this->app->log->warning('digest refused', ['reason' => $email === '' ? 'no address' : 'bad row']);
            return new Delivery(false, 'refused');
        }
        return $this->deliver($digest, $subscriber, $email, false);
    }

    public function test(array $digest, string $to): Delivery
    {
        $to = trim($to);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->app->log->warning('digest test refused', ['reason' => 'no address']);
            return new Delivery(false, 'refused');
        }
        return $this->deliver($digest, null, $to, true);
    }

    public function render(array $digest, ?array $subscriber): array
    {
        $message = $this->message($digest, $subscriber, false);
        $parts = $this->app->mailer()->render($message['subject'], 'digest', $message['data']);
        return [
            'subject' => $message['subject'],
            'preheader' => self::preheader($digest),
            'html' => $parts['html'],
            'text' => $parts['text'],
            'headers' => $message['headers'],
        ];
    }

    /**
     * The three list headers of every digest, exactly (docs/NEWS.md §14.8): the https unsubscribe link first and
     * then the mailto (RFC 2369: preference left to right; RFC 8058: one https URL), the one-click marker, and the
     * list's id under the site's host without www. (RFC 2919). With no unsubscribe address configured the mailto is
     * left out; digest() and test() refuse to send then.
     *
     * @return array{List-Unsubscribe: string, List-Unsubscribe-Post: string, List-Id: string}
     */
    public static function headers(App $app, string $unsubscribeToken): array
    {
        $links = '<' . $app->baseUrl() . Links::unsubscribe($unsubscribeToken) . '>';
        $mailto = self::unsubscribeAddress($app);
        if ($mailto !== '') {
            $links .= ', <' . Links::mailto($mailto, $unsubscribeToken) . '>';
        }
        return [
            'List-Unsubscribe' => $links,
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'List-Id' => 'Industry Updates weekly digest <digest.' . self::listHost($app) . '>',
        ];
    }

    /**
     * Whether mail.unsubscribe_to is an address a digest can name: digest() and test() refuse to send without one, and
     * Sender::blocked() reads this same rule, so it says so before anything is composed.
     */
    public static function hasUnsubscribeAddress(App $app): bool
    {
        return filter_var(self::unsubscribeAddress($app), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** "This week in Industry Updates: " and the newest headline, cut at a word to 110 characters; "[Test] " first on a test. */
    public static function subject(array $digest, bool $test = false): string
    {
        $lead = trim((string) preg_replace('/\s+/u', ' ', (string) ($digest['articles'][0]['title'] ?? '')));
        $subject = $lead === '' ? self::SUBJECT_LEAD : \Psc\News\Prose::cut(self::SUBJECT_LEAD . ': ' . $lead, self::SUBJECT_MAX);
        return ($test ? '[Test] ' : '') . $subject;
    }

    /** The hidden line mail clients show beside the subject, counting the articles shown and those left out. */
    public static function preheader(array $digest): string
    {
        $n = count((array) ($digest['articles'] ?? [])) + max(0, (int) ($digest['left_out'] ?? 0));
        return match (true) {
            $n <= 0 => '',
            $n === 1 => 'One new article this week on Industry Updates.',
            default => $n . ' new articles this week on Industry Updates, the newest first.',
        };
    }

    /**
     * @param array<string, mixed> $digest
     * @param array<string, mixed>|null $subscriber null on a test
     */
    private function deliver(array $digest, ?array $subscriber, string $to, bool $test): Delivery
    {
        if (!self::hasUnsubscribeAddress($this->app)) {
            $this->app->log->warning('digest refused', ['reason' => 'mail.unsubscribe_to is empty or not an address']);
            return new Delivery(false, 'refused');
        }
        // PHP mail() on Hostinger takes 100 a day: the digest never goes that way (Sender::blocked() says so first).
        if ($this->app->mailer()->driver() === 'mail') {
            $this->app->log->warning('digest refused', ['reason' => 'mail.driver is mail']);
            return new Delivery(false, 'refused');
        }
        try {
            $message = $this->message($digest, $subscriber, $test);
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'digest mail');
            return new Delivery(false, 'refused');
        }
        return $this->app->mailer()->deliverNow($to, $message['subject'], 'digest', $message['data'], $message['headers']);
    }

    /**
     * The subject, the templates' data and the headers of one digest message. A subscriber's carries its own
     * selector's token and its footer's reason; a test (or the preview, a null subscriber) the test selector's.
     *
     * @param array<string, mixed> $digest
     * @param array<string, mixed>|null $subscriber
     * @return array{subject: string, data: array<string, mixed>, headers: array<string, string>}
     */
    private function message(array $digest, ?array $subscriber, bool $test): array
    {
        $selector = !$test && $subscriber !== null ? (string) ($subscriber['selector'] ?? '') : Tokens::TEST_SELECTOR;
        $token = $this->tokens()->make(Tokens::UNSUBSCRIBE, $selector);
        $base = $this->app->baseUrl();
        $week = (string) ($digest['week'] ?? '');
        if (preg_match('/^\d{4}-w\d{2}$/D', $week) !== 1) {
            throw new \InvalidArgumentException('A digest needs its week');
        }
        $sample = (bool) ($digest['sample'] ?? false);
        $articles = [];
        foreach ((array) ($digest['articles'] ?? []) as $article) {
            $path = (string) ($article['path'] ?? '');
            if (!str_starts_with($path, '/')) {
                throw new \InvalidArgumentException('A digest article needs its path');
            }
            $articles[] = [
                'category_name' => (string) ($article['category_name'] ?? ''),
                'title' => (string) ($article['title'] ?? ''),
                'dek' => \Psc\News\Prose::plain((string) ($article['dek'] ?? '')),
                'url' => $base . Links::campaign($path, $week),
            ];
        }
        $mailto = self::unsubscribeAddress($this->app);
        $who = match (true) {
            $test => null,
            $subscriber === null => ['email' => self::SAMPLE_EMAIL, 'source' => 'page', 'date' => self::date(time())],
            default => [
                'email' => (string) ($subscriber['email'] ?? ''),
                'source' => (string) ($subscriber['source'] ?? 'page'),
                'date' => self::date((int) ($subscriber['confirmed_at'] ?? $subscriber['requested_at'] ?? time())),
            ],
        };
        $data = [
            'test' => $test,
            'sample' => $sample,
            'window' => $sample ? null : self::window((int) ($digest['window_start'] ?? 0), (int) ($digest['window_end'] ?? 0)),
            'articles' => $articles,
            'leftOut' => max(0, (int) ($digest['left_out'] ?? 0)),
            'newsUrl' => $base . Links::campaign('/news/', $week),
            'who' => $who,
            'unsubscribeUrl' => $base . Links::unsubscribe($token),
            'mailto' => $mailto,
            'mailtoUrl' => $mailto !== '' ? Links::mailto($mailto, $token) : '',
            'mailtoSubject' => 'Unsubscribe ' . $token,
            'privacyUrl' => $base . '/legal/privacy/',
            'publisher' => $this->publisher(),
            '_preheader' => self::preheader($digest),
            '_footer' => self::FOOTER,
        ];
        return ['subject' => self::subject($digest, $test), 'data' => $data, 'headers' => self::headers($this->app, $token)];
    }

    /**
     * The window's London dates: "Friday 16 October" and "Friday 23 October 2026" (the first with its year too when
     * the two years differ); null without a window.
     *
     * @return array{start: string, end: string}|null
     */
    private static function window(int $start, int $end): ?array
    {
        if ($start <= 0 || $end <= 0) {
            return null;
        }
        $zone = Schedule::zone();
        $from = (new \DateTimeImmutable('@' . $start))->setTimezone($zone);
        $to = (new \DateTimeImmutable('@' . $end))->setTimezone($zone);
        return [
            'start' => $from->format($from->format('Y') === $to->format('Y') ? 'l j F' : 'l j F Y'),
            'end' => $to->format('l j F Y'),
        ];
    }

    /** A London date as the footer says it: "23 October 2026". */
    private static function date(int $at): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone(Schedule::zone())->format('j F Y');
    }

    private static function unsubscribeAddress(App $app): string
    {
        return trim($app->config->string('mail.unsubscribe_to'));
    }

    private static function listHost(App $app): string
    {
        $host = strtolower((string) parse_url($app->config->string('app.url'), PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);
        return $host !== '' ? $host : 'localhost';
    }

    private static function isSelector(string $selector): bool
    {
        return preg_match('/^[a-f0-9]{24}$/D', $selector) === 1;
    }

    private function publisher(): string
    {
        return $this->app->config->string('app.publisher');
    }

    private function tokens(): Tokens
    {
        return $this->tokens ??= Tokens::fromApp($this->app);
    }
}
