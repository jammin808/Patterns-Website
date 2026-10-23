<?php

declare(strict_types=1);

namespace Psc\Support;

use Psc\App;
use Psc\Http\Request;

/**
 * The bot defences shared by every public form that sends or stores something (contact, sign-up, a password reset
 * link, a new confirmation link): a honeypot field no person sees, and the time the form was shown, signed with the
 * site's secret and bound to the visitor's session, so it can be neither forged nor carried to another session.
 *
 * check() says what a send is:
 *   bot    the honeypot is filled: the form answers as if it worked and nothing is stored or sent
 *   quick  sooner than a person fills the form in, or without a good signed time (a copy of the page from before a
 *          deploy, a session that changed): back to the form with the input kept and a request to press the button
 *          again. A browser's autofill is that fast too, so a quick send is never a silent loss. A form checks its
 *          fields first and turns back only a quick send that would otherwise go through, so what to fix is always
 *          said first, however fast the send.
 *   ok     a person
 *
 * After a quick send or a validation error the form comes back with the input kept, and fields() reads the signed
 * time back from it: pressing the button again is measured from the first showing, never restarted.
 */
final class FormGuard
{
    /** The honeypot: a text field hidden from people and from assistive technology. */
    public const HONEYPOT = 'website';

    /** The hidden field carrying the signed time the form was shown. */
    public const STARTED = '_started';

    public const OK = 'ok';
    public const QUICK = 'quick';
    public const BOT = 'bot';

    /**
     * The fewest whole seconds between showing a form and accepting a send from it: three where there is a message
     * or several fields to fill in, two where there is one address to type or pick from autofill.
     */
    public const MIN_SECONDS = ['contact' => 3, 'register' => 3, 'forgot' => 2, 'resend' => 2, 'digest' => 2];

    public function __construct(private readonly App $app)
    {
    }

    public static function minSeconds(string $form): int
    {
        return self::MIN_SECONDS[$form] ?? 3;
    }

    /**
     * The signature over a form's name, the second it was shown and the session it was shown in (its CSRF token).
     * Static so a test can sign a time in the past exactly as the form would have.
     */
    public static function signature(string $secret, string $form, int $shownAt, string $session): string
    {
        return hash_hmac('sha256', 'form-started|' . $form . '|' . $shownAt . '|' . $session, $secret);
    }

    /** A signed time for the form, shown now unless $shownAt says otherwise. */
    public function token(string $form, ?int $shownAt = null): string
    {
        $at = $shownAt ?? time();
        return $at . '.' . $this->sign($form, $at);
    }

    /** The second a token says its form was shown, or null when it is not a good token for this form and session. */
    public function shownAt(string $form, string $token): ?int
    {
        if (!preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/D', $token, $m)) {
            return null;
        }
        $at = (int) $m[1];
        // A time from the future is no time this server signed (a few seconds of clock step are forgiven).
        if ($at > time() + 5 || !hash_equals($this->sign($form, $at), $m[2])) {
            return null;
        }
        return $at;
    }

    /** One of OK, QUICK or BOT (see the class comment). */
    public function check(string $form, Request $request): string
    {
        if ($request->input(self::HONEYPOT) !== '') {
            return self::BOT;
        }
        $at = $this->shownAt($form, $request->input(self::STARTED));
        return $at !== null && time() - $at >= self::minSeconds($form) ? self::OK : self::QUICK;
    }

    /**
     * The hidden fields for a form: its signed time and the honeypot. $old is the input kept after a refused send;
     * a good signed time in it stands, so the clock is not restarted. $id names the honeypot's element, unique on
     * pages that show the same form twice.
     *
     * @param array<string, mixed> $old
     */
    public function fields(string $form, array $old = [], string $id = ''): string
    {
        $kept = is_string($old[self::STARTED] ?? null) ? $old[self::STARTED] : '';
        $token = $kept !== '' && $this->shownAt($form, $kept) !== null ? $kept : $this->token($form);
        $id = ($id !== '' ? $id : $form) . '-website';
        return '<input type="hidden" name="' . self::STARTED . '" value="' . e($token) . '">'
            . '<div class="u-visually-hidden" aria-hidden="true">'
            . '<label for="' . e($id) . '">Leave this field empty</label>'
            . '<input id="' . e($id) . '" name="' . self::HONEYPOT . '" type="text" tabindex="-1" autocomplete="off" value="">'
            . '</div>';
    }

    private function sign(string $form, int $at): string
    {
        return self::signature($this->app->secret(), $form, $at, $this->app->session()->csrfToken());
    }
}
