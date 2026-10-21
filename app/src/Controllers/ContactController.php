<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\View\Schema;

/**
 * The contact page and its form. A message is stored (so nothing is lost if mail fails) and emailed to the
 * administrator with the sender as Reply-To. Spam defences: a honeypot field, a minimum fill time, per-address
 * rate limits and optional Cloudflare Turnstile.
 *
 * Only a filled honeypot, which no person sees, is answered with a "Message sent" that stores nothing. A send
 * faster than the minimum fill time may be a person whose browser filled the form, so it goes back to the form with
 * the input kept and a request to press Send again; nothing is sent and nothing is lost.
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

    /**
     * The fewest whole seconds between the form being shown and a send being accepted. The clock is kept in the
     * session, so a visitor cannot set it.
     */
    public const MIN_FILL_SECONDS = 3;

    /** The form's message after a send that came faster than MIN_FILL_SECONDS. */
    public const QUICK_MESSAGE = 'That was quick. Please check your message, then send it again.';

    public const EMAIL_ERROR = 'Enter an email address in the format name@example.co.uk';

    public function show(Request $request): Response
    {
        $page = Content::page('/contact/') ?? ['title' => 'Contact | Patterns Show Control', 'description' => 'Contact Patterns Show Control.', 'template' => 'pages/contact', 'crumb' => 'Contact', 'section' => 'contact', 'type' => 'contact'];
        $seo = $this->app->seo((string) $page['title'], (string) $page['description'], '/contact/');
        $seo->breadcrumbs = Content::trail('/contact/');
        $seo->image = isset($page['og']) ? (string) $page['og'] : null;
        $this->baseGraph($seo);
        $seo->add(Schema::webPage($this->app->baseUrl(), $seo->canonical(), (string) $page['title'], (string) $page['description'], 'ContactPage'));
        $session = $this->app->session();
        // The fill-time clock starts when the form is shown. A send turned back for being quick does not restart it,
        // so pressing Send again on the form it comes back to is measured from the first showing.
        $quick = (bool) $session->get('contact_quick', false);
        $session->forget('contact_quick');
        if (!$quick || (int) $session->get('contact_started', 0) === 0) {
            $session->set('contact_started', time());
        }
        $prefill = [
            'topic' => array_key_exists($request->queryString('topic'), self::TOPICS) ? $request->queryString('topic') : '',
            'product' => in_array($request->queryString('product'), Content::PRODUCTS, true) ? $request->queryString('product') : '',
        ];
        $response = $this->page((string) ($page['template'] ?? 'pages/contact'), [
            'page' => $page,
            'section' => 'contact',
            'topics' => self::TOPICS,
            'old' => $session->takeOld() + $prefill,
            'errors' => $this->takeErrors(),
            'sent' => (bool) $session->get('contact_sent', false),
            'quick' => $quick,
            'turnstileKey' => $this->app->config->string('security.turnstile_site_key'),
        ], $seo);
        $session->forget('contact_sent');
        // The page is personalised (CSRF token, form state), so it is never cached, but it is still indexable.
        $response->header('X-Robots-Tag', 'index, follow');
        if ($this->app->config->string('security.turnstile_site_key') !== '') {
            $cf = 'https://challenges.cloudflare.com';
            $response->header('Content-Security-Policy', "default-src 'self'; script-src 'self' {$cf}; frame-src {$cf}; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self' {$cf}; frame-ancestors 'none'; form-action 'self'; base-uri 'self'; object-src 'none'");
        }
        return $response;
    }

    public function submit(Request $request): Response
    {
        $session = $this->app->session();
        $ip = $this->app->clientIp();
        // Only a bot fills the hidden field: it is told the message was sent, and nothing is stored or emailed.
        if ($request->input('website') !== '') {
            $session->set('contact_sent', true);
            return Response::redirect('/contact/#sent', 303);
        }
        // Faster than a person types, but a browser's autofill is as fast: back to the form, input kept, nothing sent.
        $started = (int) $session->get('contact_started', 0);
        if ($started > 0 && time() - $started < self::MIN_FILL_SECONDS) {
            $session->set('contact_quick', true);
            return $this->backWithErrors('/contact/#form', $request, [], self::QUICK_MESSAGE);
        }
        if (!$this->app->limiter()->hit('contact:' . $ip, 5, 3600)) {
            return $this->backWithErrors('/contact/#form', $request, [], 'You have sent several messages already. Please wait an hour, or email us directly.');
        }
        $data = [
            'name' => trim($request->input('name')),
            'email' => trim($request->input('email')),
            'company' => trim($request->input('company')),
            'topic' => $request->input('topic'),
            'product' => $request->input('product'),
            'message' => trim(str_replace("\r\n", "\n", $request->input('message'))),
        ];
        $errors = [];
        if ($data['name'] === '' || mb_strlen($data['name']) > 120) {
            $errors['name'] = 'Enter your name.';
        }
        if (mb_strlen($data['email']) > 190) {
            $errors['email'] = 'Enter an email address of up to 190 characters.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = self::EMAIL_ERROR;
        }
        if (mb_strlen($data['company']) > 160) {
            $errors['company'] = 'Keep this under 160 characters.';
        }
        if (!array_key_exists($data['topic'], self::TOPICS)) {
            $errors['topic'] = 'Choose what your message is about.';
        }
        if ($data['product'] !== '' && !in_array($data['product'], Content::PRODUCTS, true)) {
            $errors['product'] = 'Choose a product from the list.';
        }
        $len = mb_strlen($data['message']);
        if ($len < 10 || $len > 5000) {
            $errors['message'] = 'Write a message between 10 and 5,000 characters.';
        }
        if ($errors === [] && !$this->turnstileOk($request, $ip)) {
            $errors['turnstile'] = 'Please complete the check below the form.';
        }
        if ($errors !== []) {
            return $this->backWithErrors('/contact/#form', $request, $errors);
        }
        $id = $this->app->db()->insert('contact_messages', [
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'company' => $data['company'] !== '' ? $data['company'] : null,
            'topic' => $data['topic'],
            'product' => $data['product'] !== '' ? $data['product'] : null,
            'message' => $data['message'],
            'ip_hash' => $this->app->ipHash($ip),
            'status' => 'new',
            'created_at' => time(),
        ]);
        $to = $this->app->config->string('mail.admin_to');
        $recipients = $to !== '' ? [$to] : array_filter($this->app->config->list('app.admin_emails'), 'is_string');
        foreach ($recipients as $r) {
            $this->app->mailer()->send($r, 'Website message: ' . self::TOPICS[$data['topic']], 'contact-admin', $data + ['id' => $id, 'topicLabel' => self::TOPICS[$data['topic']]], $data['email']);
        }
        $this->app->mailer()->send($data['email'], 'We have your message', 'contact-receipt', ['name' => $data['name'], 'topicLabel' => self::TOPICS[$data['topic']]]);
        $session->set('contact_sent', true);
        return Response::redirect('/contact/#sent', 303);
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
