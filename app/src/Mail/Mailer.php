<?php

declare(strict_types=1);

namespace Psc\Mail;

use Psc\Config;
use Psc\Support\Logger;
use Psc\Support\Storage;
use Psc\View\View;

/**
 * Sends the site's emails from templates/emails/<name>.php (HTML; a plain-text part is derived from it).
 * Drivers: smtp (Hostinger mail: implicit TLS on 465 or STARTTLS on 587), mail (PHP mail()), log (writes .eml
 * files to storage/mail, for development and tests). A failed send is logged and reported false; it never breaks
 * the page that triggered it.
 */
final class Mailer
{
    /** @var list<array{to: string, subject: string, template: string, data: array<string, mixed>, html: string}> */
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

    /** @param array<string, mixed> $data */
    public function send(string $to, string $subject, string $template, array $data = [], ?string $replyTo = null): bool
    {
        try {
            $html = $this->view->render('emails/layout', [
                'subject' => $subject,
                'body' => $this->view->render('emails/' . $template, $data + ['siteName' => $this->siteName, 'baseUrl' => $this->baseUrl]),
                'siteName' => $this->siteName,
                'baseUrl' => $this->baseUrl,
            ]);
        } catch (\Throwable $e) {
            $this->log->exception($e, 'mail template ' . $template);
            return false;
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'template' => $template, 'data' => $data, 'html' => $html];
        $message = $this->compose($to, $subject, $html, self::toText($html), $replyTo);
        if ($this->defer !== null) {
            ($this->defer)(function () use ($to, $subject, $template, $message): void {
                $this->deliver($to, $subject, $template, $message);
            });
            return true;
        }
        return $this->deliver($to, $subject, $template, $message);
    }

    /** @param array{headers: string, body: string, raw: string} $message */
    private function deliver(string $to, string $subject, string $template, array $message): bool
    {
        try {
            $ok = match ($this->driver) {
                'smtp' => $this->sendSmtp($to, $message),
                'mail' => $this->sendMail($to, $subject, $message),
                default => $this->sendLog($to, $message),
            };
        } catch (\Throwable $e) {
            $this->log->exception($e, 'mail send');
            $ok = false;
        }
        $this->log->info($ok ? 'mail sent' : 'mail failed', ['template' => $template, 'to_domain' => substr((string) strrchr($to, '@'), 1)]);
        return $ok;
    }

    /** @return array{headers: string, body: string, raw: string} */
    private function compose(string $to, string $subject, string $html, string $text, ?string $replyTo): array
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
    private function sendLog(string $to, array $message): bool
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0770, true);
        }
        return @file_put_contents($this->logDir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml', $message['raw']) !== false;
    }

    /** @param array{headers: string, body: string, raw: string} $message */
    private function sendMail(string $to, string $subject, array $message): bool
    {
        $headers = preg_replace('/^(To|Subject): .*\r\n/m', '', $message['headers'] . "\r\n");
        return mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'Q'), $message['body'], rtrim((string) $headers), '-f' . $this->from);
    }

    /** @param array{headers: string, body: string, raw: string} $message */
    private function sendSmtp(string $to, array $message): bool
    {
        $host = (string) ($this->smtp['host'] ?? '');
        $port = (int) ($this->smtp['port'] ?? 465);
        $enc = (string) ($this->smtp['encryption'] ?? 'ssl');
        $timeout = (int) ($this->smtp['timeout'] ?? 15);
        if ($host === '') {
            throw new \RuntimeException('SMTP host not set');
        }
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($fp, $timeout);
        try {
            $this->expect($fp, 220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $this->command($fp, $ehlo, 250);
            if ($enc === 'tls') {
                $this->command($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $this->command($fp, $ehlo, 250);
            }
            $user = (string) ($this->smtp['user'] ?? '');
            if ($user !== '') {
                $this->command($fp, 'AUTH LOGIN', 334);
                $this->command($fp, base64_encode($user), 334);
                $this->command($fp, base64_encode((string) ($this->smtp['password'] ?? '')), 235, true);
            }
            $this->command($fp, 'MAIL FROM:<' . $this->from . '>', 250);
            $this->command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($fp, 'DATA', 354);
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message['raw']));
            fwrite($fp, rtrim((string) $data, "\r\n") . "\r\n.\r\n");
            $this->expect($fp, 250);
            $this->command($fp, 'QUIT', 221);
        } finally {
            fclose($fp);
        }
        return true;
    }

    /**
     * @param resource $fp
     * @param int|list<int> $code
     */
    private function command($fp, string $line, int|array $code, bool $secret = false): string
    {
        fwrite($fp, $line . "\r\n");
        try {
            return $this->expect($fp, $code);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(($secret ? '[credentials]' : strtok($line, ' ')) . ': ' . $e->getMessage());
        }
    }

    /**
     * @param resource $fp
     * @param int|list<int> $code
     */
    private function expect($fp, int|array $code): string
    {
        $codes = (array) $code;
        $reply = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $got = (int) substr($reply, 0, 3);
        if (!in_array($got, $codes, true)) {
            throw new \RuntimeException('SMTP said ' . trim($reply));
        }
        return $reply;
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
