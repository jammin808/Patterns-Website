<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\Support\FormGuard;
use Psc\View\Schema;

/**
 * The contact page and its form. A message is stored (so nothing is lost if mail fails) and emailed to the
 * administrator with the sender as Reply-To. Spam defences: the shared FormGuard (a honeypot and a signed showing
 * time), a cap on links in a message, duplicate suppression, rate limits per network address and per sender's email
 * address, and optional Cloudflare Turnstile. The fields are checked first, so what to fix is always said first.
 *
 * Only a filled honeypot, which no person sees, is answered with a "Message sent" that stores nothing. A send
 * faster than the minimum fill time may be a person whose browser filled the form, so it goes back to the form with
 * the input kept and a request to press Send again; nothing is sent and nothing is lost. Every other refusal also
 * keeps the input and says plainly what to change.
 */
final class ContactController extends Controller
{
    public const TOPICS = [
        'sales' => 'Buying a licence or pricing',
        'support' => 'Help with the software',
        'licence' => 'A licence I already have',
        'partnership' => 'Rental, reseller or partnership',
        'press' => 'Press or events',
        'other' => 'Something else',
    ];

    /** The fewest whole seconds between the form being shown and a send being accepted (FormGuard). */
    public const MIN_FILL_SECONDS = FormGuard::MIN_SECONDS['contact'];

    /** The form's message after a send that came faster than MIN_FILL_SECONDS. */
    public const QUICK_MESSAGE = 'That was quick. Please check your message, then press Send message again.';

    public const EMAIL_ERROR = self::EMAIL_FORMAT;

    /** The most links (http://, https:// or www.) one message may hold: link spam carries many, a question few. */
    public const MAX_LINKS = 3;

    /** The longest a message can be, in characters. */
    public const MAX_MESSAGE = 5000;

    /** Receipts one address can be sent in a day, however many messages name it. */
    public const RECEIPTS_PER_DAY = 2;

    /** Messages accepted from one network address in an hour. */
    public const PER_IP_PER_HOUR = 5;

    /** Messages accepted from one email address in a day. */
    public const PER_ADDRESS_PER_DAY = 5;

    /** How long a message already received counts as a duplicate when the same sender sends it again. */
    public const DUPLICATE_SECONDS = 86400;

    public function show(Request $request): Response
    {
        $page = Content::page('/contact/') ?? ['title' => 'Contact | Patterns Show Control', 'description' => 'Contact Patterns Show Control.', 'template' => 'pages/contact', 'crumb' => 'Contact', 'section' => 'contact', 'type' => 'contact'];
        $seo = $this->app->seo((string) $page['title'], (string) $page['description'], '/contact/');
        $seo->breadcrumbs = Content::trail('/contact/');
        $seo->image = isset($page['og']) ? (string) $page['og'] : null;
        $this->baseGraph($seo);
        $seo->add(Schema::webPage($this->app->baseUrl(), $seo->canonical(), (string) $page['title'], (string) $page['description'], 'ContactPage'));
        $session = $this->app->session();
        $quick = $this->takeQuick('contact');
        // A question typed into the assistant's box without scripting arrives here as ?q=, ready to send as a message.
        $question = trim(str_replace("\r\n", "\n", $request->queryString('q')));
        $prefill = [
            'topic' => array_key_exists($request->queryString('topic'), self::TOPICS) ? $request->queryString('topic') : '',
            'product' => in_array($request->queryString('product'), Content::PRODUCTS, true) ? $request->queryString('product') : '',
            'message' => mb_substr($question, 0, self::MAX_MESSAGE),
        ];
        $sent = $session->get('contact_sent', false);
        $response = $this->page((string) ($page['template'] ?? 'pages/contact'), [
            'page' => $page,
            'section' => 'contact',
            'topics' => self::TOPICS,
            'old' => $session->takeOld() + $prefill,
            'errors' => $this->takeErrors(),
            'sent' => $sent !== false,
            // The same message from the same sender was already with us, so this send was not passed on twice.
            'again' => $sent === 'again',
            'quick' => $quick,
            'maxLinks' => self::MAX_LINKS,
            'maxMessage' => self::MAX_MESSAGE,
            'turnstileKey' => $this->app->config->string('security.turnstile_site_key'),
        ], $seo);
        $session->forget('contact_sent');
        // The page is personalised (CSRF token, form state), so it is never cached, but it is still indexable.
        $response->header('X-Robots-Tag', 'index, follow');
        if ($this->app->config->string('security.turnstile_site_key') !== '') {
            // The site's own policy with Turnstile's host added, so nothing else (the Google tag) is dropped.
            $cf = 'https://challenges.cloudflare.com';
            $response->header('Content-Security-Policy', $this->app->contentSecurityPolicy($request, ['script-src' => $cf, 'frame-src' => $cf, 'connect-src' => $cf]));
        }
        return $response;
    }

    public function submit(Request $request): Response
    {
        $session = $this->app->session();
        // A new send replaces the answer to the last one: the page shows this send's outcome, never an older one's.
        $session->forget('contact_sent');
        $ip = $this->app->clientIp();
        $verdict = (new FormGuard($this->app))->check('contact', $request);
        // Only a bot fills the hidden field: it is told the message was sent, and nothing is stored or emailed.
        if ($verdict === FormGuard::BOT) {
            $session->set('contact_sent', true);
            return Response::redirect('/contact/#sent', 303);
        }
        $data = [
            'name' => trim($request->input('name')),
            'email' => trim($request->input('email')),
            'company' => trim($request->input('company')),
            'country' => $request->input('country'),
            'topic' => $request->input('topic'),
            'product' => $request->input('product'),
            'message' => trim(str_replace("\r\n", "\n", $request->input('message'))),
        ];
        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 120) {
            $errors['name'] = 'Enter your name.';
        } elseif (self::linkCount($data['name']) > 0) {
            $errors['name'] = 'Enter your name without a web address.';
        }
        if ($data['email'] === '') {
            $errors['email'] = 'Enter your email address, so we can reply.';
        } elseif (mb_strlen($data['email']) > 190) {
            $errors['email'] = 'Enter an email address of up to 190 characters.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = self::EMAIL_ERROR;
        }
        if (mb_strlen($data['company']) > 160) {
            $errors['company'] = 'Keep this under 160 characters.';
        }
        if ($data['country'] !== '' && !array_key_exists($data['country'], Content::countries())) {
            $errors['country'] = 'Choose a country from the list.';
        }
        if (!array_key_exists($data['topic'], self::TOPICS)) {
            $errors['topic'] = 'Choose what your message is about.';
        }
        if ($data['product'] !== '' && !in_array($data['product'], Content::PRODUCTS, true)) {
            $errors['product'] = 'Choose a product from the list.';
        }
        $len = mb_strlen($data['message']);
        $links = self::linkCount($data['message']);
        if ($len < 10 || $len > self::MAX_MESSAGE) {
            $errors['message'] = 'Write a message between 10 and 5,000 characters.';
        } elseif ($links > self::MAX_LINKS) {
            $errors['message'] = 'Your message has ' . $links . ' links, and the limit is ' . self::MAX_LINKS . '. Remove some links, then send it again.';
        }
        // What to fix comes first, however fast the send: a refused form sends nothing, so it needs no clock.
        if ($errors !== []) {
            return $this->backWithErrors('/contact/#form', $request, $errors);
        }
        // Faster than a person types, but a browser's autofill is as fast: back to the form, input kept, nothing sent.
        if ($verdict === FormGuard::QUICK) {
            return $this->tooQuick('/contact/#form', $request, 'contact', self::QUICK_MESSAGE);
        }
        if (!$this->turnstileOk($request, $ip)) {
            return $this->backWithErrors('/contact/#form', $request, ['turnstile' => 'Please complete the check below the form.']);
        }
        $email = mb_strtolower($data['email']);
        $ipHash = $this->app->ipHash($ip);
        // A second send of the same message (a double press, the back button, a reload) is not passed on again: the
        // sender sees "Message sent", told that we already have it, and nothing is stored, emailed or counted.
        if ($this->alreadyReceived($email, $ipHash, $data['message'])) {
            $session->set('contact_sent', 'again');
            return Response::redirect('/contact/#sent', 303);
        }
        $limiter = $this->app->limiter();
        if (!$limiter->hit('contact:' . $ip, self::PER_IP_PER_HOUR, 3600)) {
            return $this->backWithErrors('/contact/#form', $request, [], 'Several messages have been sent from your network in the last hour, so this one was not sent. Your message is still here: please try again in an hour.');
        }
        if (!$limiter->hit('contact-from:' . $email, self::PER_ADDRESS_PER_DAY, 86400)) {
            return $this->backWithErrors('/contact/#form', $request, [], 'You have sent ' . self::PER_ADDRESS_PER_DAY . ' messages from this email address today, so this one was not sent. Your message is still here: please try again tomorrow.');
        }
        $id = $this->app->db()->insert('contact_messages', [
            'name' => $data['name'],
            'email' => $email,
            'company' => $data['company'] !== '' ? $data['company'] : null,
            'country' => $data['country'] !== '' ? $data['country'] : null,
            'topic' => $data['topic'],
            'product' => $data['product'] !== '' ? $data['product'] : null,
            'message' => $data['message'],
            'ip_hash' => $ipHash,
            'status' => 'new',
            'created_at' => time(),
        ]);
        $to = $this->app->config->string('mail.admin_to');
        $recipients = $to !== '' ? [$to] : array_filter($this->app->config->list('app.admin_emails'), 'is_string');
        foreach ($recipients as $r) {
            $this->app->mailer()->send($r, 'Website message: ' . self::TOPICS[$data['topic']], 'contact-admin', $data + ['id' => $id, 'topicLabel' => self::TOPICS[$data['topic']], 'countryName' => Content::countries()[$data['country']] ?? ''], $data['email']);
        }
        // The receipt goes to an address nobody has proven, so it carries only the site's own words (the topic comes
        // from the fixed list), never the name or message typed into the form, and each address gets at most
        // RECEIPTS_PER_DAY a day: the form cannot be used to send someone mail from this site. The message itself is
        // always stored and passed on to the administrator.
        if ($limiter->hit('contact-to:' . $email, self::RECEIPTS_PER_DAY, 86400)) {
            $this->app->mailer()->send($data['email'], 'We have your message', 'contact-receipt', ['topicLabel' => self::TOPICS[$data['topic']]]);
        }
        $session->set('contact_sent', true);
        return Response::redirect('/contact/#sent', 303);
    }

    /** The web addresses in a text: each begins with http://, https:// or www. and runs to the next space. */
    public static function linkCount(string $text): int
    {
        return (int) preg_match_all('~(?:https?://|\bwww\.)[^\s<>"\']*~iu', $text);
    }

    /**
     * Whether the same message, ignoring case and spacing, came from this address or this network within
     * DUPLICATE_SECONDS. Compared here rather than in SQL, so both database engines' collations agree.
     */
    private function alreadyReceived(string $email, string $ipHash, string $message): bool
    {
        $fingerprint = self::fingerprint($message);
        $recent = $this->app->db()->all(
            'SELECT message FROM contact_messages WHERE created_at >= ? AND (email = ? OR ip_hash = ?) ORDER BY id DESC LIMIT 50',
            [time() - self::DUPLICATE_SECONDS, $email, $ipHash]
        );
        foreach ($recent as $row) {
            if (hash_equals($fingerprint, self::fingerprint((string) $row['message']))) {
                return true;
            }
        }
        return false;
    }

    private static function fingerprint(string $message): string
    {
        return hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message))));
    }

    private function turnstileOk(Request $request, string $ip): bool
    {
        $secret = $this->app->config->string('security.turnstile_secret');
        if ($secret === '') {
            return true;
        }
        $token = $request->input('cf-turnstile-response');
        if ($token === '') {
            return false;
        }
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if ($ch === false) {
            return true;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            $this->app->log->warning('turnstile unreachable');
            return true;
        }
        $data = json_decode($raw, true);
        return is_array($data) && ($data['success'] ?? false) === true;
    }
}
