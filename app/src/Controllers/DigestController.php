<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Auth\Auth;
use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\News\Digest\DigestMail;
use Psc\News\Digest\Links;
use Psc\News\Digest\Subscribers;
use Psc\News\Digest\Tokens;
use Psc\News\Digest\Week;
use Psc\Support\FormGuard;
use Psc\View\Schema;

/**
 * The weekly digest's public pages (docs/NEWS.md §14.5, §14.6): the signup page and its form, and the two pages a
 * digest email's links open, to confirm a sign-up and to unsubscribe.
 *
 * The signup page carries a session for its CSRF token and FormGuard's signed time. Every send it accepts, and every
 * one it turns away silently, is answered with the same "Check your inbox", so the page never says whether an address
 * is subscribed, waiting, unsubscribed or past its daily limit. Nothing is sent until the confirmation's button is
 * pressed (double opt-in), and a mailbox is sent at most CONFIRMATIONS_PER_DAY confirmations a day.
 *
 * Only the delivery of a confirmation waits until after the response: the request's write, the limit and the email's
 * build still happen in it, so a sign-up for an address already confirmed answers a fraction of a millisecond sooner.
 * The per-network limit keeps that from being sampled often enough to read.
 *
 * The confirm and unsubscribe pages carry no session, no CSRF check and no cookie: the signed token in their query is
 * the only authority, because a mail client's RFC 8058 one-click POST sends none. A GET or HEAD changes nothing for
 * any token (link scanners fetch every link), and only the page's POST acts. Every answer is a page drawn in the
 * response itself, never a redirect: 200 for a good token whatever its row's state, 404 for a token that is not one
 * of ours, and 429 once a network has sent BAD_LINKS_PER_HOUR of those in an hour. A good token is never limited,
 * because a mail provider's one-click POSTs for many people come from the same few addresses.
 */
final class DigestController extends Controller
{
    /** Sign-ups accepted from one network address in an hour. */
    public const PER_IP_PER_HOUR = 10;

    /**
     * Confirmation emails one mailbox can be sent in a day, however often it is typed into the form or ticked on an
     * account page: counted by Auth::mailboxKey(), so every spelling of it (name+1@, a quoted local part) shares it.
     * The signup page's words name it ('page' 'sent').
     */
    public const CONFIRMATIONS_PER_DAY = 3;

    /** Tokens that are not ours one network address can send to the confirm and unsubscribe pages in an hour. */
    public const BAD_LINKS_PER_HOUR = 30;

    /** The longest address the form takes (the column's width). */
    public const MAX_EMAIL = 190;

    /**
     * The versions every consent record stores (docs/NEWS.md §14.2): the sign-up wording's (content/news.php 'digest'
     * 'consent_version') and the privacy notice's date (content/pages.php /legal/privacy/ 'updated'). Every caller of
     * Subscribers::request() and subscribeAccount() passes these.
     *
     * @return array{consent: string, privacy: string}
     */
    public static function versions(\Psc\App $app): array
    {
        $digest = Content::get('news')['digest'] ?? [];
        $privacy = Content::page('/legal/privacy/');
        return [
            'consent' => (string) (is_array($digest) ? ($digest['consent_version'] ?? '') : ''),
            'privacy' => (string) ($privacy['updated'] ?? ''),
        ];
    }

    /**
     * Whether one more confirmation may go to this address's mailbox today (CONFIRMATIONS_PER_DAY), counting it when
     * it may. The signup form and the account page's tick share the allowance.
     */
    public static function allowConfirmation(\Psc\App $app, string $email): bool
    {
        return $app->limiter()->hit('digest-confirm:' . Auth::mailboxKey($email), self::CONFIRMATIONS_PER_DAY, 86400);
    }

    /**
     * When the digest goes, in the pages' words: "every Friday at 09:00 UK time" from the desk's settings, or "once a
     * week" while the digest is paused or its settings cannot be read.
     */
    public static function cadence(\Psc\App $app): string
    {
        $copy = self::copy();
        $cadence = null;
        try {
            $cadence = Week::cadence($app);
        } catch (\Throwable $e) {
            $app->log->exception($e, 'digest cadence');
        }
        if ($cadence === null) {
            return (string) ($copy['cadence_fallback'] ?? 'once a week');
        }
        return strtr((string) ($copy['cadence'] ?? 'every :day at :time UK time'), [':day' => (string) $cadence['day'], ':time' => (string) $cadence['time']]);
    }

    public function show(Request $request): Response
    {
        $page = Content::page(Links::SIGNUP) ?? ['title' => 'Weekly digest | Industry Updates', 'description' => (string) (self::copy()['description'] ?? ''), 'template' => 'news/digest'];
        $seo = $this->app->seo((string) $page['title'], (string) $page['description'], Links::SIGNUP);
        $seo->breadcrumbs = Content::trail(Links::SIGNUP);
        $seo->image = isset($page['og']) ? (string) $page['og'] : null;
        $seo->imageAlt = isset($page['og_alt']) ? (string) $page['og_alt'] : null;
        $this->baseGraph($seo);
        $seo->add(Schema::webPage($this->app->baseUrl(), $seo->canonical(), (string) $page['title'], (string) $page['description'], 'WebPage'));
        $session = $this->app->session();
        $quick = $this->takeQuick('digest');
        $sent = $session->get('digest_sent', false) !== false;
        $response = $this->page((string) ($page['template'] ?? 'news/digest'), [
            'page' => $page,
            'section' => 'news',
            'copy' => self::copy(),
            'cadence' => self::cadence($this->app),
            'old' => $session->takeOld(),
            'errors' => $this->takeErrors(),
            'sent' => $sent,
            'quick' => $quick,
        ], $seo);
        $session->forget('digest_sent');
        // A form, so never cached (it carries the session's token), but a page people should find: indexable.
        $response->header('X-Robots-Tag', 'index, follow');
        return $response;
    }

    /**
     * A sign-up, in the contact form's order: the honeypot, the field, the clock, the network's limit, then the
     * request. Whatever becomes of an address that passes them, the answer is the same "Check your inbox".
     */
    public function submit(Request $request): Response
    {
        $session = $this->app->session();
        // A new send replaces the answer to the last one.
        $session->forget('digest_sent');
        $errors = is_array(self::copy()['errors'] ?? null) ? self::copy()['errors'] : [];
        $verdict = (new FormGuard($this->app))->check('digest', $request);
        // Only a bot fills the hidden field: it is told the email is on its way, and nothing is stored or sent.
        if ($verdict === FormGuard::BOT) {
            $session->set('digest_sent', true);
            return Response::redirect(Links::SIGNUP . '#sent', 303);
        }
        $email = trim($request->input('email'));
        $error = null;
        if ($email === '') {
            $error = (string) ($errors['email_empty'] ?? 'Enter your email address.');
        } elseif (mb_strlen($email) > self::MAX_EMAIL) {
            $error = (string) ($errors['email_long'] ?? 'Enter an email address of up to 190 characters.');
        } elseif (!Auth::isUsableEmail($email)) {
            $error = (string) ($errors['email_format'] ?? self::EMAIL_FORMAT);
        }
        if ($error !== null) {
            return $this->backWithErrors(Links::SIGNUP . '#form', $request, ['email' => $error]);
        }
        // Faster than a person types, but autofill is as fast: back to the form, the address kept, nothing sent.
        if ($verdict === FormGuard::QUICK) {
            return $this->tooQuick(Links::SIGNUP . '#form', $request, 'digest', (string) ($errors['quick'] ?? 'That was quick. Please check your address, then press Send me the digest again.'));
        }
        try {
            if (!$this->app->limiter()->hit('digest-signup:' . $this->app->clientIp(), self::PER_IP_PER_HOUR, 3600)) {
                return $this->backWithErrors(Links::SIGNUP . '#form', $request, [], (string) ($errors['limited'] ?? ''));
            }
            $versions = self::versions($this->app);
            $address = Auth::normaliseEmail($email);
            $result = (new Subscribers($this->app))->request($address, $versions['consent'], $versions['privacy'], time());
            // Only a request that is waiting for its button gets a confirmation, and a mailbox at most
            // CONFIRMATIONS_PER_DAY a day, so the form cannot flood anyone's inbox. Past it nothing is sent or said.
            if (in_array($result['outcome'], ['new', 'again', 'returning'], true) && $result['subscriber'] !== null
                && self::allowConfirmation($this->app, $address)) {
                DigestMail::courier($this->app)->confirmation($result['subscriber']);
            }
        } catch (\Throwable $e) {
            $this->app->log->exception($e, 'digest signup');
            return $this->backWithErrors(Links::SIGNUP . '#form', $request, [], (string) ($errors['unavailable'] ?? ''));
        }
        $session->set('digest_sent', true);
        return Response::redirect(Links::SIGNUP . '#sent', 303);
    }

    /**
     * GET /digest/confirm/?token=: what pressing the button would do, by the button's own rule
     * (Subscribers::linkState(): the row's generation, and 7 days from requested_at). Changes nothing.
     */
    public function confirmPage(Request $request): Response
    {
        $link = $this->confirmLink($request);
        if ($link instanceof Response) {
            return $link;
        }
        $row = (new Subscribers($this->app))->bySelector($link['selector']);
        $state = Subscribers::linkState($row, $link['generation'], time());
        return $this->linkPage($request, 'confirm', $state === 'missing' ? 'expired' : $state, $row);
    }

    /** POST /digest/confirm/?token=: the button. Consent is given only by this POST, never by opening the link. */
    public function confirm(Request $request): Response
    {
        $link = $this->confirmLink($request);
        if ($link instanceof Response) {
            return $link;
        }
        $subscribers = new Subscribers($this->app);
        $outcome = $subscribers->confirm($link['selector'], $link['generation'], time());
        $state = match ($outcome) {
            'confirmed' => 'done',
            'already', 'expired', 'gone' => $outcome,
            default => 'expired',
        };
        return $this->linkPage($request, 'confirm', $state, $state === 'done' ? $subscribers->bySelector($link['selector']) : null);
    }

    /** GET /digest/unsubscribe/?token=: what pressing the button would do. Changes nothing. */
    public function unsubscribePage(Request $request): Response
    {
        $selector = $this->selector($request, Tokens::UNSUBSCRIBE);
        if (!is_string($selector)) {
            return $selector;
        }
        if ($selector === Tokens::TEST_SELECTOR) {
            return $this->linkPage($request, 'unsubscribe', 'test', null);
        }
        $row = (new Subscribers($this->app))->bySelector($selector);
        $state = $row !== null && in_array($row['state'], ['pending', 'confirmed'], true) ? 'ask' : 'already';
        return $this->linkPage($request, 'unsubscribe', $state, $row);
    }

    /**
     * POST /digest/unsubscribe/?token=: the page's button, and a mail client's RFC 8058 one-click POST, whose body is
     * List-Unsubscribe=One-Click (multipart or urlencoded; PHP reads both). Both unsubscribe at once; always 200.
     */
    public function unsubscribe(Request $request): Response
    {
        $selector = $this->selector($request, Tokens::UNSUBSCRIBE);
        if (!is_string($selector)) {
            return $selector;
        }
        $method = $request->input('List-Unsubscribe') === 'One-Click' ? 'one_click' : 'page';
        $outcome = (new Subscribers($this->app))->unsubscribe($selector, $method, time());
        $state = match ($outcome) {
            'done' => 'done',
            'test' => 'test',
            default => 'already',
        };
        return $this->linkPage($request, 'unsubscribe', $state, null);
    }

    /**
     * The selector an unsubscribe token carries, or the answer for one that is not ours (notOurs()).
     */
    private function selector(Request $request, string $purpose): string|Response
    {
        $selector = Tokens::fromApp($this->app)->read($purpose, $request->queryString('token'));
        return $selector ?? $this->notOurs($request, $purpose);
    }

    /**
     * The selector and generation a confirmation token carries, or the answer for one that is not ours (notOurs()).
     *
     * @return array{selector: string, generation: int}|Response
     */
    private function confirmLink(Request $request): array|Response
    {
        return Tokens::fromApp($this->app)->readConfirm($request->queryString('token')) ?? $this->notOurs($request, Tokens::CONFIRM);
    }

    /**
     * The answer for a token that is not ours: the invalid page (404), or 429 once the network has sent
     * BAD_LINKS_PER_HOUR of them in an hour. A good token is never counted.
     */
    private function notOurs(Request $request, string $purpose): Response
    {
        if (!$this->app->limiter()->hit('digest-link:' . $this->app->clientIp(), self::BAD_LINKS_PER_HOUR, 3600)) {
            return $this->app->errorResponse($request, 429)->header('Retry-After', '3600');
        }
        return $this->linkPage($request, $purpose === Tokens::CONFIRM ? 'confirm' : 'unsubscribe', 'invalid', null, 404);
    }

    /**
     * A confirm or unsubscribe page in one of its states. Never indexed and never given a canonical; the referrer meta
     * keeps the token in its address from reaching the next page in a Referer, and the address is printed from the
     * row only where the words name it.
     *
     * @param 'confirm'|'unsubscribe' $kind
     * @param array<string, mixed>|null $row
     */
    private function linkPage(Request $request, string $kind, string $state, ?array $row, int $status = 200): Response
    {
        $copy = self::copy();
        $words = is_array($copy[$kind] ?? null) ? $copy[$kind] : [];
        $title = $state === 'invalid' ? (string) ($copy['invalid_title'] ?? 'This link does not work') : (string) ($words[$state . '_title'] ?? '');
        $path = $kind === 'confirm' ? Links::CONFIRM : Links::UNSUBSCRIBE;
        $seo = $this->app->seo($title . ' | ' . (string) (Content::get('news')['name'] ?? 'Industry Updates'), (string) ($copy['description'] ?? ''), $path)->noindex();
        $seo->image = '/assets/img/og/news.png';
        $this->baseGraph($seo);
        $token = $request->queryString('token');
        $unsubscribe = null;
        if ($kind === 'confirm' && $state === 'done' && $row !== null) {
            $unsubscribe = Links::unsubscribe(Tokens::fromApp($this->app)->make(Tokens::UNSUBSCRIBE, (string) $row['selector']));
        }
        return $this->page('news/digest-link', [
            'section' => 'news',
            'copy' => $copy,
            'kind' => $kind,
            'state' => $state,
            'title' => $title,
            // The form posts back to the page's own address with its query, so the token goes with the button.
            'action' => $state === 'ask' ? ($kind === 'confirm' ? Links::confirm($token) : Links::unsubscribe($token)) : null,
            'email' => in_array($state, ['ask', 'done'], true) && $row !== null ? (string) ($row['email'] ?? '') : '',
            'cadence' => $kind === 'confirm' && $state === 'done' ? self::cadence($this->app) : '',
            'unsubscribe' => $unsubscribe,
        ], $seo, 'layouts/base', $status);
    }

    /** @return array<string, mixed> content/news.php 'digest' */
    private static function copy(): array
    {
        $digest = Content::get('news')['digest'] ?? [];
        return is_array($digest) ? $digest : [];
    }
}
