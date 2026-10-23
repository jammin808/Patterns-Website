<?php

declare(strict_types=1);

namespace Psc\Mail;

use Psc\Config;
use Psc\Support\Logger;
use Psc\Support\Storage;
use Psc\View\View;

/**
 * Sends the site's emails from templates/emails/<name>.php (HTML) and, when it exists, <name>.text.php (the written
 * plain-text part; otherwise one is derived from the HTML). Drivers: smtp (Hostinger mail: implicit TLS on 465 or
 * STARTTLS on 587), mail (PHP mail()), log (writes .eml files to storage/mail, for development and tests).
 *
 * send() is for a page's email: deferred until after the response where the app allows it, a failure logged and
 * reported false, never breaking the page. deliverNow() is for a batch (the weekly digest): delivered in the call,
 * with a Delivery that says how far it got. A caller may add only the list headers (LIST_HEADERS); anything else
 * refuses the whole message, so nothing a caller passes can add a recipient or a header of its own.
 */
final class Mailer
{
    /** The only headers a caller may add: a list message's (RFC 2369 List-Unsubscribe, RFC 8058, RFC 2919 List-Id). */
    public const LIST_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post', 'List-Id'];

    /** The longest value a caller's header may have, in bytes. */
    public const HEADER_MAX = 900;

    /** A header line longer than this is folded after a comma (RFC 5322 §2.1.1's recommended line length). */
    private const LINE = 78;

    /**
     * Every message built, in order, whatever came of its delivery.
     *
     * @var list<array{to: string, subject: string, template: string, data: array<string, mixed>, html: string, headers: array<string, string>, text: string}>
     */
    public array $sent = [];

    /** @param array<string, mixed> $smtp */
    public function __construct(
        private readonly string $driver,
        private readonly string $from,
        private readonly string $fromName,
        private readonly array $smtp,
        private readonly string $logDir,
        private readonly Logger $log,
        private readonly View $view,
        private readonly string $siteName,
        private readonly string $baseUrl,
        private readonly ?\Closure $defer = null,
    ) {
    }

    /**
     * @param (\Closure(callable(): void): void)|null $defer runs the delivery after the response has been sent, so
     *        a slow mail server never shows in how long a page takes (which would reveal whether an email exists)
     */
    public static function fromConfig(Config $config, Storage $storage, Logger $log, View $view, ?\Closure $defer = null): self
    {
        $smtp = $config->get('mail.smtp', []);
        return new self(
            $config->string('mail.driver', 'log'),
            $config->string('mail.from'),
            $config->string('mail.from_name', 'Patterns Show Control'),
            is_array($smtp) ? $smtp : [],
            $storage->path('mail'),
            $log,
            $view,
            $config->string('app.name', 'Patterns Show Control'),
            rtrim($config->string('app.url'), '/'),
            $defer,
        );
    }

    /** The configured driver: smtp, mail or log (any other word delivers as log). */
    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * A page's email. $headers may hold only LIST_HEADERS (name => one-line value); anything else refuses the send.
     *
     * @param array<string, mixed> $data
     * @param array<array-key, mixed> $headers
     */
    public function send(string $to, string $subject, string $template, array $data = [], ?string $replyTo = null, array $headers = []): bool
    {
        $built = $this->build($to, $subject, $template, $data, $replyTo, $headers);
        if ($built === null) {
            return false;
        }
        if ($this->defer !== null) {
            ($this->defer)(function () use ($to, $template, $built): void {
                $this->deliver($to, $template, $built);
            });
            return true;
        }
        return $this->deliver($to, $template, $built)->ok;
    }

    /**
     * The same message as send() builds, delivered in this call (never deferred), with its outcome: refused when it
     * could not be built; with log, done once the .eml is written; with mail, done or connect by mail()'s answer;
     * with smtp, the stage whose reply did not come or was not the one expected, with its codes. A QUIT that fails
     * after the message's 250 is still done.
     *
     * @param array<string, mixed> $data
     * @param array<array-key, mixed> $headers
     */
    public function deliverNow(string $to, string $subject, string $template, array $data = [], array $headers = []): Delivery
    {
        $built = $this->build($to, $subject, $template, $data, null, $headers);
        if ($built === null) {
            return new Delivery(false, 'refused');
        }
        return $this->deliver($to, $template, $built);
    }

    /**
     * The message's two parts as send() builds them. The HTML is the template inside emails/layout; the text is
     * emails/{template}.text.php rendered with the same data when it exists, else toText() of the HTML. Two optional
     * values of $data reach the layout: _preheader (plain text, the hidden first line mail clients show beside the
     * subject) and _footer (an email partial's name, emails/_{name}.php, rendered with the same data in place of
     * the layout's own footer line). A template error throws.
     *
     * @param array<string, mixed> $data
     * @return array{html: string, text: string}
     */
    public function render(string $subject, string $template, array $data = []): array
    {
        $vars = $data + ['siteName' => $this->siteName, 'baseUrl' => $this->baseUrl];
        $layout = [
            'subject' => $subject,
            'body' => $this->view->render('emails/' . $template, $vars),
            'siteName' => $this->siteName,
            'baseUrl' => $this->baseUrl,
        ];
        $preheader = $data['_preheader'] ?? '';
        if (!is_string($preheader)) {
            throw new \InvalidArgumentException('An email preheader is plain text');
        }
        if (trim($preheader) !== '') {
            $layout['preheader'] = trim($preheader);
        }
        if (isset($data['_footer'])) {
            $footer = $data['_footer'];
            if (!is_string($footer) || preg_match('/^[a-z0-9][a-z0-9-]*$/D', $footer) !== 1) {
                throw new \InvalidArgumentException('An email footer is the name of a partial in templates/emails');
            }
            $layout['footer'] = $this->view->render('emails/_' . $footer, $vars);
        }
        $html = $this->view->render('emails/layout', $layout);
        $text = $this->view->exists('emails/' . $template . '.text')
            ? trim(str_replace(["\r\n", "\r"], "\n", $this->view->render('emails/' . $template . '.text', $vars)))
            : self::toText($html);
        return ['html' => $html, 'text' => $text];
    }

    /**
     * Checks, renders and composes a message, and records it in $sent; null (logged) when it is refused.
     *
     * @param array<string, mixed> $data
     * @param array<array-key, mixed> $headers
     * @return array{headers: string, body: string, raw: string}|null
     */
    private function build(string $to, string $subject, string $template, array $data, ?string $replyTo, array $headers): ?array
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $to) === 1) {
            $this->log->warning('mail refused', ['template' => $template, 'header' => 'To']);
            return null;
        }
        $extra = $this->listHeaders($template, $headers);
        if ($extra === null) {
            return null;
        }
        try {
            $parts = $this->render($subject, $template, $data);
        } catch (\Throwable $e) {
            $this->log->exception($e, 'mail template ' . $template);
            return null;
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'template' => $template, 'data' => $data, 'html' => $parts['html'], 'headers' => $extra, 'text' => $parts['text']];
        return $this->compose($to, $subject, $parts['html'], $parts['text'], $replyTo, $extra);
    }

    /**
     * A caller's headers, checked: names only from LIST_HEADERS, each value one line of printable ASCII, not empty,
     * at most HEADER_MAX bytes. Anything else refuses the whole message (null), logged with the template and the
     * header's name, never its value.
     *
     * @param array<array-key, mixed> $headers
     * @return array<string, string>|null
     */
    private function listHeaders(string $template, array $headers): ?array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $ok = in_array($name, self::LIST_HEADERS, true)
                && is_string($value)
                && $value !== ''
                && strlen($value) <= self::HEADER_MAX
                && preg_match('/[^\x20-\x7e]/', $value) !== 1;
            if (!$ok) {
                $shown = preg_match('/^[A-Za-z0-9-]{1,40}$/D', $name) === 1 ? $name : 'invalid';
                $this->log->warning('mail refused', ['template' => $template, 'header' => $shown]);
                return null;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    /**
     * "Name: value", folded after a comma (CRLF before the space that follows it) when longer than LINE, so it
     * unfolds to the same text. A part with nothing after its comma is never put on a line of its own.
     */
    private static function fold(string $name, string $value): string
    {
        $line = $name . ': ' . $value;
        if (strlen($line) <= self::LINE) {
            return $line;
        }
        $parts = explode(', ', $value);
        $lines = [];
        $current = $name . ': ' . array_shift($parts);
        foreach ($parts as $part) {
            if (trim($part) === '' || strlen($current) + 2 + strlen($part) <= self::LINE) {
                $current .= ', ' . $part;
                continue;
            }
            $lines[] = $current . ',';
            $current = ' ' . $part;
        }
        $lines[] = $current;
        return implode("\r\n", $lines);
    }

    /** @param array{headers: string, body: string, raw: string} $message */
    private function deliver(string $to, string $template, array $message): Delivery
    {
        try {
            $delivery = match ($this->driver) {
                'smtp' => $this->sendSmtp($to, $message),
                'mail' => $this->sendMail($to, $message) ? Delivery::sent() : new Delivery(false, 'connect'),
                default => $this->sendLog($message) ? Delivery::sent() : new Delivery(false, 'connect'),
            };
        } catch (\Throwable $e) {
            $this->log->exception($e, 'mail send');
            $delivery = new Delivery(false, 'connect');
        }
        $context = ['template' => $template, 'to_domain' => substr((string) strrchr($to, '@'), 1)];
        if (!$delivery->ok) {
            $context['reply'] = $delivery->summary();
        }
        $this->log->info($delivery->ok ? 'mail sent' : 'mail failed', $context);
        return $delivery;
    }

    /**
     * @param array<string, string> $extra the caller's checked list headers, after Auto-Submitted in their order
     * @return array{headers: string, body: string, raw: string}
     */
    private function compose(string $to, string $subject, string $html, string $text, ?string $replyTo, array $extra = []): array
    {
        $boundary = 'psc-' . bin2hex(random_bytes(12));
        $domain = substr((string) strrchr($this->from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::address($this->from, $this->fromName),
            'To: ' . $to,
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'Q'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted: auto-generated',
        ];
        foreach ($extra as $name => $value) {
            $headers[] = self::fold($name, $value);
        }
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($text) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode($html) . "\r\n"
            . "--{$boundary}--\r\n";
        $h = implode("\r\n", $headers);
        return ['headers' => $h, 'body' => $body, 'raw' => $h . "\r\n\r\n" . $body];
    }

    private static function address(string $email, string $name): string
    {
        return $name !== '' ? mb_encode_mimeheader($name, 'UTF-8', 'Q') . ' <' . $email . '>' : $email;
    }

    /** @param array{headers: string, body: string, raw: string} $message */
    private function sendLog(array $message): bool
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0770, true);
        }
        return @file_put_contents($this->logDir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $message['raw']) !== false;
    }

    /**
     * PHP mail() takes the recipient and the subject apart, so both headers (with any folded continuation lines)
     * leave the header block it is given.
     *
     * @param array{headers: string, body: string, raw: string} $message
     */
    private function sendMail(string $to, array $message): bool
    {
        if (preg_match('/^Subject: (.*(?:\r\n[ \t].*)*)\r\n/m', $message['headers'] . "\r\n", $m) !== 1) {
            return false;
        }
        $headers = preg_replace('/^(?:To|Subject): .*\r\n(?:[ \t].*\r\n)*/m', '', $message['headers'] . "\r\n");
        return mail($to, $m[1], $message['body'], rtrim((string) $headers), '-f' . $this->from);
    }

    /**
     * One message over SMTP. Each step that does not get the reply it expects ends the delivery with its stage, the
     * reply's codes and whether it timed out; the server's words are never kept, since they can hold the address.
     *
     * @param array{headers: string, body: string, raw: string} $message
     */
    private function sendSmtp(string $to, array $message): Delivery
    {
        $host = (string) ($this->smtp['host'] ?? '');
        $port = (int) ($this->smtp['port'] ?? 465);
        $enc = (string) ($this->smtp['encryption'] ?? 'ssl');
        $timeout = max(1, (int) ($this->smtp['timeout'] ?? 15));
        if ($host === '') {
            $this->log->warning('mail failed: the SMTP host is not set');
            return new Delivery(false, 'connect');
        }
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            return new Delivery(false, 'connect');
        }
        stream_set_timeout($fp, $timeout);
        try {
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $failed = $this->step($fp, 'greeting', null, [220]) ?? $this->step($fp, 'ehlo', $ehlo, [250]);
            if ($failed === null && $enc === 'tls') {
                $failed = $this->step($fp, 'tls', 'STARTTLS', [220]);
                if ($failed === null && @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                    $failed = new Delivery(false, 'tls');
                }
                $failed ??= $this->step($fp, 'ehlo', $ehlo, [250]);
            }
            $user = (string) ($this->smtp['user'] ?? '');
            if ($failed === null && $user !== '') {
                $failed = $this->step($fp, 'auth', 'AUTH LOGIN', [334])
                    ?? $this->step($fp, 'auth', base64_encode($user), [334])
                    ?? $this->step($fp, 'auth', base64_encode((string) ($this->smtp['password'] ?? '')), [235]);
            }
            $failed ??= $this->step($fp, 'mail', 'MAIL FROM:<' . $this->from . '>', [250])
                ?? $this->step($fp, 'rcpt', 'RCPT TO:<' . $to . '>', [250, 251])
                ?? $this->step($fp, 'data', 'DATA', [354]);
            if ($failed !== null) {
                return $failed;
            }
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message['raw']));
            // From here on the server may hold the message, so whatever goes wrong, a throwable included, is "body",
            // never sent again unless the server's own 4xx reply says it took none of it (Delivery::retryable()).
            // (deliver()'s own catch, for a throwable before this point, says "connect".)
            try {
                $failed = $this->step($fp, 'body', rtrim((string) $data, "\r\n") . "\r\n.", [250]);
            } catch (\Throwable $e) {
                $this->log->exception($e, 'mail send');
                return new Delivery(false, 'body');
            }
            if ($failed !== null) {
                return $failed;
            }
            // The server took the message: a QUIT that goes wrong in any way leaves it sent.
            try {
                if ($this->write($fp, 'QUIT')) {
                    self::reply($fp);
                }
            } catch (\Throwable $e) {
                $this->log->exception($e, 'mail quit');
            }
            return Delivery::sent();
        } finally {
            fclose($fp);
        }
    }

    /**
     * Sends $line (none for the greeting) and reads the reply: null when its code is one of $codes, else the
     * Delivery that ends the message at $stage.
     *
     * @param resource $fp
     * @param list<int> $codes
     */
    private function step($fp, string $stage, #[\SensitiveParameter] ?string $line, array $codes): ?Delivery
    {
        if ($line !== null && !$this->write($fp, $line)) {
            return new Delivery(false, $stage);
        }
        $reply = self::reply($fp);
        if (in_array($reply['code'], $codes, true)) {
            return null;
        }
        return new Delivery(false, $stage, $reply['code'], $reply['enhanced'], $reply['timeout']);
    }

    /** @param resource $fp */
    private function write($fp, #[\SensitiveParameter] string $line): bool
    {
        $bytes = $line . "\r\n";
        $written = @fwrite($fp, $bytes);
        return $written === strlen($bytes);
    }

    /**
     * The server's reply, as codes only: the three digits of its last line (0 when no whole reply came) and the
     * RFC 3463 code after them, if any.
     *
     * @param resource $fp
     * @return array{code: int, enhanced: string, timeout: bool}
     */
    private static function reply($fp): array
    {
        while (($line = @fgets($fp, 1024)) !== false) {
            if (strlen($line) < 4 || $line[3] !== '-') {
                if (preg_match('/^([2-5]\d\d)(?:[ ]([245]\.\d{1,3}\.\d{1,3})(?=[\s]|$))?/', $line, $m) !== 1) {
                    return ['code' => 0, 'enhanced' => '', 'timeout' => false];
                }
                return ['code' => (int) $m[1], 'enhanced' => $m[2] ?? '', 'timeout' => false];
            }
        }
        $meta = stream_get_meta_data($fp);
        return ['code' => 0, 'enhanced' => '', 'timeout' => (bool) ($meta['timed_out'] ?? false)];
    }

    /** A readable plain-text version of an HTML email: links kept as "text (url)". */
    public static function toText(string $html): string
    {
        $html = (string) preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace_callback('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', static function (array $m): string {
            $label = trim(strip_tags($m[2]));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $label === $url || $label === '' ? $url : $label . ' (' . $url . ')';
        }, $html);
        $html = (string) preg_replace('#<(br|/p|/h[1-6]|/li|/tr|/div)\b[^>]*>#i', "\n", $html);
        $html = (string) preg_replace('#<li\b[^>]*>#i', '- ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace("/[ \t]+/", ' ', $text);
        $text = (string) preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);
        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }
}
