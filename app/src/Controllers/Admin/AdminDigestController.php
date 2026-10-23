<?php

declare(strict_types=1);

namespace Psc\Controllers\Admin;

use Psc\App;
use Psc\Auth\Auth;
use Psc\Controllers\Controller;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Mail\Delivery;
use Psc\News\ArticleStore;
use Psc\News\Digest\Composer;
use Psc\News\Digest\Digests;
use Psc\News\Digest\DigestMail;
use Psc\News\Digest\Sender;
use Psc\News\Digest\Sends;
use Psc\News\Digest\Subscribers;
use Psc\News\Digest\Tokens;
use Psc\News\Digest\Week;
use Psc\News\Runs;
use Psc\News\Settings;
use Psc\Support\Audit;

/**
 * The weekly digest on the AI desk, for administrators (docs/NEWS.md §14.12): its state, who gets it, what the next
 * one holds (with its preview and a test to the administrator's own address), unsubscribing someone who asked by
 * email, and each digest's sends. Pausing and continuing change digest_enabled through Psc\News\Settings; every
 * action is audited as digest.*, never with an address.
 *
 * The preview is the email itself, drawn as its own document outside the portal shell, under a policy of its own:
 * an email's styles are inline, which the site's own policy refuses.
 */
final class AdminDigestController extends Controller
{
    public const BASE = '/portal/admin/news/desk/digest/';

    private const ACTIONS = ['pause' => 'pause', 'resume' => 'resume', 'test' => 'test', 'unsubscribe' => 'unsubscribe'];

    /** Tests an administrator may send in an hour. */
    public const TESTS_PER_HOUR = 5;

    /** From this many confirmed subscribers, the page says the list is nearing one mailbox's day. */
    public const WARN_AT = 400;

    public const PAST = 12;
    public const SENDS_PER_PAGE = 50;

    /** The preview's own policy: the email's inline styles and nothing else. */
    public const PREVIEW_POLICY = "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    /** A digest's state as a badge: [badge, word]. */
    public const DIGEST_STATES = [
        'sending' => ['in_progress', 'Sending'],
        'sent' => ['done', 'Sent'],
        'empty' => ['closed', 'Nothing to send'],
        'skipped' => ['pending', 'Skipped'],
        'stopped' => ['declined', 'Stopped'],
    ];

    /** A send's state in words. */
    public const SEND_STATES = ['claimed' => 'Unknown', 'sent' => 'Sent', 'retry' => 'Waiting to retry', 'failed' => 'Failed', 'bounced' => 'Bounced'];

    /** A stopped digest's recipients it never reached, a send left to retry among them: none is tried again. */
    public const NOT_SENT = 'Not sent: the digest stopped';

    /**
     * A send left to retry whose subscriber has unsubscribed since (or has since joined again, after the digest was
     * composed, so the next one is their first): it is never tried again.
     */
    public const UNSUBSCRIBED = 'Not sent: unsubscribed since';

    /** An unsubscribe token anywhere in what was pasted (a whole subject line works). */
    private const TOKEN = '/(?<![a-f0-9])[a-f0-9]{24}\.[A-Za-z0-9_-]{43}(?![A-Za-z0-9_-])/';

    /**
     * The digest's state as its page says it, the first that applies: sending is blocked (PHP mail(), no unsubscribe
     * address), the cron has not ticked or has stopped (the digest goes only at a tick), paused, or on.
     *
     * @return array{key: string, label: string, badge: string, text: string}
     */
    public static function state(App $app): array
    {
        $settings = new Settings($app);
        $key = (new Sender($app))->blocked()
            ?? ['never' => 'no_tick', 'stopped' => 'stopped', 'ok' => null][$settings->cronState((new Runs($app))->running())]
            ?? ($settings->get('digest_enabled') === true ? 'on' : 'paused');
        return match ($key) {
            'mail_driver' => ['key' => 'mail_driver', 'label' => 'Stopped: PHP mail()', 'badge' => 'declined', 'text' => 'The site\'s mail goes through PHP mail(), which Hostinger caps at 100 a day. Set mail.driver to smtp in the server\'s config file, as docs/DEPLOY.md describes.'],
            'no_mailto' => ['key' => 'no_mailto', 'label' => 'Stopped: no unsubscribe address', 'badge' => 'declined', 'text' => 'mail.unsubscribe_to is empty or is not an email address, and every digest must offer an address to unsubscribe by email. Set it in the server\'s config file.'],
            'no_tick' => ['key' => 'no_tick', 'label' => 'Stopped: no tick yet', 'badge' => 'declined', 'text' => 'The cron job has not ticked yet, and the digest goes only at a tick. Add the news cron job in hPanel, as docs/DEPLOY.md describes.'],
            'stopped' => ['key' => 'stopped', 'label' => 'Stopped: cron not ticking', 'badge' => 'declined', 'text' => 'The cron job has not ticked for more than 35 minutes, and the digest goes only at a tick. Check the news cron job in hPanel.'],
            'paused' => ['key' => 'paused', 'label' => 'Paused', 'badge' => 'pending', 'text' => 'No digest is composed or sent until you continue it. A week whose time passes while it is paused is skipped; a digest partly sent waits, and goes on when you continue it, up to 48 hours after its time.'],
            default => ['key' => 'on', 'label' => 'On', 'badge' => 'active', 'text' => 'The next digest goes out ' . self::nextLabel($app) . '.'],
        };
    }

    // ------------------------------------------------------------------------------------------- the main page

    public function index(Request $request): Response
    {
        $now = new \DateTimeImmutable('now');
        $at = $now->getTimestamp();
        $settings = new Settings($this->app);
        $subscribers = new Subscribers($this->app);
        $counts = $subscribers->counts($at);
        $preview = (new Composer($this->app))->preview($now);
        $text = '';
        try {
            $text = DigestMail::courier($this->app)->render($preview, null)['text'];
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'digest preview');
        }
        $session = $this->app->session();
        $old = $session->takeOld();
        $admin = $this->currentUser();
        return $this->portal('admin/news/desk/digest', [
            'state' => self::state($this->app),
            'enabled' => $settings->get('digest_enabled') === true,
            'driver' => $this->app->config->string('mail.driver', 'log'),
            'counts' => $counts,
            'sentDay' => (new Sends($this->app))->countSince($at - 86400),
            'cap' => (int) $settings->get('digest_per_day'),
            'warn' => $counts['confirmed'] >= self::WARN_AT,
            'preview' => $preview,
            'monday' => self::longDate(Week::monday($preview['week'])),
            'label' => Week::label((int) $preview['send_at']),
            'text' => $text,
            'email' => (string) $admin['email'],
            'mailto' => $this->app->config->string('mail.unsubscribe_to'),
            'past' => (new Digests($this->app))->recent(self::PAST),
            'errors' => $this->takeErrors(),
            'old' => $old,
        ], 'Weekly digest');
    }

    public function control(Request $request): Response
    {
        $admin = $this->currentUser();
        $adminId = (int) $admin['id'];
        $settings = new Settings($this->app);
        $enabled = $settings->get('digest_enabled') === true;
        switch (AdminController::pick($request->input('action'), self::ACTIONS)) {
            case 'pause':
                if (!$enabled) {
                    return $this->redirect(self::BASE, 'info', 'The digest is already paused.');
                }
                $settings->save(['digest_enabled' => false], $adminId);
                Audit::log($this->app, $adminId, 'digest.paused');
                return $this->redirect(self::BASE, 'success', 'The digest is paused. Nothing is composed or sent until you continue it.');
            case 'resume':
                if ($enabled) {
                    return $this->redirect(self::BASE, 'info', 'The digest is already on.');
                }
                $settings->save(['digest_enabled' => true], $adminId);
                Audit::log($this->app, $adminId, 'digest.resumed');
                return $this->redirect(self::BASE, 'success', 'The digest is on again. The next one goes out ' . self::nextLabel($this->app) . '.');
            case 'test':
                return $this->test($admin);
            case 'unsubscribe':
                return $this->unsubscribe($request, $adminId);
            default:
                return $this->redirect(self::BASE, 'danger', 'That action is not available. Use one of the buttons on this page.');
        }
    }

    /** The email preview: the next digest as it stands, as its own document. */
    public function preview(Request $request): Response
    {
        $html = DigestMail::courier($this->app)->render((new Composer($this->app))->preview(new \DateTimeImmutable('now')), null)['html'];
        return Response::html($html)
            ->header('Content-Security-Policy', self::PREVIEW_POLICY)
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    // ---------------------------------------------------------------------------------------------- a digest

    public function show(Request $request): Response
    {
        $digest = (new Digests($this->app))->find($request->attrInt('id'));
        if ($digest === null) {
            return $this->notFound();
        }
        $id = (int) $digest['id'];
        $sends = new Sends($this->app);
        $store = new ArticleStore($this->app);
        $articles = [];
        foreach (is_array($digest['articles']) ? $digest['articles'] : [] as $articleId) {
            $article = $store->find((int) $articleId);
            $articles[] = [
                'id' => (int) $articleId,
                'title' => $article !== null ? (string) $article['title'] : null,
                'live' => $article !== null && $article['status'] === 'published' && $article['published_at'] !== null && (int) $article['published_at'] <= time(),
            ];
        }
        $page = $sends->page($digest, max(1, (int) $request->queryString('page')), self::SENDS_PER_PAGE);
        $monday = self::longDate(Week::monday((string) $digest['week']));
        $counts = $sends->counts($id);
        $sending = $digest['state'] === 'sending';
        return $this->portal('admin/news/desk/digest-show', [
            'digest' => $digest,
            'monday' => $monday,
            'articles' => $articles,
            'counts' => $counts,
            'left' => $sending ? $sends->countDue($digest) : 0,
            'waiting' => $sending ? $sends->countWaiting($digest) : 0,
            'unsent' => Digests::unsent($digest, $counts),
            'sends' => $page,
            'base' => self::BASE . $id . '/',
        ], 'Digest for the week of ' . $monday);
    }

    // -------------------------------------------------------------------------------------------- internals

    /**
     * A test of the next digest as it stands, to the signed-in administrator's own address only (never one posted),
     * at most TESTS_PER_HOUR an hour. Its links carry the test selector, so they change nothing.
     *
     * @param array<string, mixed> $admin
     */
    private function test(array $admin): Response
    {
        $state = self::state($this->app);
        if (in_array($state['key'], ['mail_driver', 'no_mailto'], true)) {
            return $this->redirect(self::BASE . '#next', 'danger', $state['text']);
        }
        if (!$this->app->limiter()->hit('digest-test:' . (int) $admin['id'], self::TESTS_PER_HOUR, 3600)) {
            return $this->redirect(self::BASE . '#next', 'danger', 'You have sent 5 tests in the last hour. Please wait a while, then try again.');
        }
        $email = (string) $admin['email'];
        $digest = (new Composer($this->app))->preview(new \DateTimeImmutable('now'));
        try {
            $delivery = DigestMail::courier($this->app)->test($digest, $email);
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'digest test');
            $delivery = new Delivery(false, 'refused');
        }
        Audit::log($this->app, (int) $admin['id'], 'digest.test', null, ['week' => (string) $digest['week'], 'sent' => $delivery->ok]);
        if (!$delivery->ok) {
            return $this->redirect(self::BASE . '#next', 'danger', 'The test was not sent: ' . self::why($delivery) . '. The site log has more.');
        }
        return $this->redirect(self::BASE . '#next', 'success', 'A test digest is on its way to ' . $email . '.');
    }

    /**
     * Why a test was not sent, by who stopped it: the site (it would not build the email, reached no server, or could
     * not set up TLS: tls with no code and no timeout is a handshake that failed, most often over a certificate not
     * issued to mail.smtp.host, or a connection closed at STARTTLS), or the mail server, by its reply's codes or by
     * its silence.
     */
    private static function why(Delivery $delivery): string
    {
        return match (true) {
            $delivery->stage === 'refused' => 'the site could not build the email',
            $delivery->code > 0 => 'the mail server answered ' . $delivery->summary(),
            $delivery->stage === 'connect' => 'the site could not reach the mail server',
            $delivery->stage === 'tls' && !$delivery->timeout => 'the site could not set up a secure connection to the mail server',
            default => 'the mail server did not reply (' . $delivery->summary() . ')',
        };
    }

    /**
     * Unsubscribes someone who asked by email (to mail.unsubscribe_to) or some other way: a genuine unsubscribe token
     * anywhere in the field (a pasted subject line works, even from a forwarded request), else a usable address.
     */
    private function unsubscribe(Request $request, int $adminId): Response
    {
        $who = trim($request->input('unsubscribe_who'));
        $method = $request->input('unsubscribe_method') === 'admin' ? 'admin' : 'mailto';
        $subscribers = new Subscribers($this->app);
        $now = time();
        if (preg_match(self::TOKEN, $who, $m) === 1) {
            $selector = Tokens::fromApp($this->app)->read(Tokens::UNSUBSCRIBE, $m[0]);
            $result = $selector !== null ? $subscribers->unsubscribe($selector, $method, $now) : 'missing';
        } elseif ($who !== '' && Auth::isUsableEmail($who)) {
            $result = $subscribers->unsubscribeEmail($who, $method, $now);
        } else {
            // To the error summary itself, which takes focus: a fragment on its section would leave focus on the page.
            return $this->backWithErrors(self::BASE . '#unsubscribe-summary', $request, ['unsubscribe_who' => 'Enter an email address, or paste the token from the unsubscribe email.']);
        }
        return match ($result) {
            'done' => $this->unsubscribed($adminId, $method),
            'already' => $this->redirect(self::BASE . '#unsubscribe', 'info', 'That address was already unsubscribed. Nothing changed.'),
            default => $this->redirect(self::BASE . '#unsubscribe', 'warning', 'No subscription matches that address or token. Nothing changed.'),
        };
    }

    private function unsubscribed(int $adminId, string $method): Response
    {
        Audit::log($this->app, $adminId, 'digest.unsubscribed', null, ['method' => $method]);
        return $this->redirect(self::BASE . '#unsubscribe', 'success', 'That address is unsubscribed and gets no more digests.');
    }

    /**
     * A send's state in words. A retry is tried again only while its digest is sending and the digest is still due to
     * its subscriber (Sends::page()'s due): one of a stopped digest is NOT_SENT, as its page counts it, and on any
     * other digest, one whose subscriber has left since is UNSUBSCRIBED.
     */
    public static function sendState(string $state, string $digestState, bool $due): string
    {
        return match (true) {
            $state === 'retry' && $digestState === 'stopped' => self::NOT_SENT,
            $state === 'retry' && !$due => self::UNSUBSCRIBED,
            default => self::SEND_STATES[$state] ?? ucfirst($state),
        };
    }

    /** When the next digest goes out: "Friday 30 October 2026 at 09:00". */
    private static function nextLabel(App $app): string
    {
        $now = new \DateTimeImmutable('now');
        $next = Week::next($now, new Settings($app), (new Digests($app))->weeksSince($now->getTimestamp() - 8 * 86400));
        return Week::label($next['send_at']);
    }

    /** "19 October 2026" for "2026-10-19". */
    public static function longDate(string $date): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/London'));
        return $d !== false ? $d->format('j F Y') : $date;
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            throw new \LogicException('The digest pages need the auth middleware');
        }
        return $user;
    }
}
