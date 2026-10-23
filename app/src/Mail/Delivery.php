<?php

declare(strict_types=1);

namespace Psc\Mail;

/**
 * How one message went (docs/NEWS.md §14.3), as Mailer::deliverNow() reports it: whether the server took it, and if
 * not, the stage that did not get its reply, with the reply's codes. It never holds the server's words, which can
 * carry the recipient's address, so summary() is safe for a log line and a database column.
 *
 * Stages, in the order a delivery passes them: refused (the Mailer would not build or send it: no connection was
 * made), connect, greeting, ehlo, tls, auth, mail, rcpt, data (the DATA command, before any of the message), body
 * (the reply after the message, or none) and done. A 4xx or 5xx reply after the message says the server did not take
 * it (RFC 5321 §4.2.5), so only a body with no such reply is uncertain: after a 4xx it may go again, and a 5xx is
 * final.
 */
final readonly class Delivery
{
    public const STAGES = ['refused', 'connect', 'greeting', 'ehlo', 'tls', 'auth', 'mail', 'rcpt', 'data', 'body', 'done'];

    /** The stages at which nothing of the message has reached the server, so it may be sent again. */
    private const BEFORE_MESSAGE = ['connect', 'greeting', 'ehlo', 'tls', 'auth', 'mail', 'rcpt', 'data'];

    /**
     * The RFC 3463 codes that say the recipient's address itself is bad (X.1.1 no such mailbox, X.1.2 no such
     * system, X.1.3 a bad address syntax, X.1.6 moved, X.1.10 a null MX). Every other X.1.x is about the sender (X.1.7,
     * X.1.8) or says nothing about the address (X.1.0), and can hit a whole list at once.
     */
    private const RECIPIENT_GONE = ['5.1.1', '5.1.2', '5.1.3', '5.1.6', '5.1.10'];

    /**
     * @param int $code the reply's three digits, 0 when there was none
     * @param string $enhanced the reply's RFC 3463 code (5.1.1), or ''
     * @param bool $timeout whether the reply never came
     */
    public function __construct(
        public bool $ok,
        public string $stage,
        public int $code = 0,
        public string $enhanced = '',
        public bool $timeout = false,
    ) {
    }

    public static function sent(): self
    {
        return new self(true, 'done');
    }

    /**
     * The server refused the recipient as an address that does not exist: a 5xx at RCPT with one of the
     * RECIPIENT_GONE codes. Any other refusal at RCPT is retryable, so a fault of the sender's never unsubscribes
     * anyone.
     */
    public function hardBounce(): bool
    {
        return !$this->ok && $this->stage === 'rcpt' && $this->code >= 500 && $this->code <= 599 && in_array($this->enhanced, self::RECIPIENT_GONE, true);
    }

    /**
     * Refused, not for the address, and none of the message held by the server: before any of it was sent, or with a
     * 4xx reply after it. It may go again later. A 5xx after it is final: RFC 5321 §4.2.5 says not to send it again
     * without someone's review.
     */
    public function retryable(): bool
    {
        return !$this->ok && !$this->hardBounce()
            && (in_array($this->stage, self::BEFORE_MESSAGE, true) || ($this->refusedAfterMessage() && $this->code < 500));
    }

    /**
     * The message was sent but neither taken nor refused (no reply, a hang-up, a write that failed, or a reply that is
     * neither): the server may have it, so it never goes again.
     */
    public function uncertain(): bool
    {
        return !$this->ok && $this->stage === 'body' && !$this->refusedAfterMessage();
    }

    /**
     * The stage and the codes only (rcpt 550 5.1.1, body timeout, connect, done), at most 40 characters. Each part is
     * checked against its shape, so nothing but a stage word and digits ever reaches it.
     */
    public function summary(): string
    {
        $parts = [in_array($this->stage, self::STAGES, true) ? $this->stage : 'unknown'];
        if ($this->code >= 100 && $this->code <= 599) {
            $parts[] = (string) $this->code;
        }
        if (preg_match('/^[245]\.\d{1,3}\.\d{1,3}$/D', $this->enhanced) === 1) {
            $parts[] = $this->enhanced;
        }
        if ($this->timeout) {
            $parts[] = 'timeout';
        }
        return substr(implode(' ', $parts), 0, 40);
    }

    /** The server's own 4xx or 5xx reply to the whole message: it did not take it. */
    private function refusedAfterMessage(): bool
    {
        return !$this->ok && $this->stage === 'body' && $this->code >= 400 && $this->code <= 599;
    }
}
