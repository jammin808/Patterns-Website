<?php

declare(strict_types=1);

namespace Psc\Http;

/** An HTTP response: a status, headers, cookies and either a body or a streaming callback. */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $cookies = [];

    /** @var (callable(): void)|null */
    private $stream = null;

    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        foreach ($headers as $k => $v) {
            $this->header((string) $k, (string) $v);
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200, string $type = 'text/plain'): self
    {
        return new self($body, $status, ['Content-Type' => $type . '; charset=utf-8']);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return (new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']))
            ->header('Cache-Control', 'no-store');
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    /** @param callable(): void $writer writes and flushes the body itself */
    public static function stream(callable $writer, string $type, int $status = 200): self
    {
        $r = new self('', $status, ['Content-Type' => $type]);
        $r->stream = $writer;
        return $r;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$this->canonical($name)] = $value;
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$this->canonical($name)]);
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$this->canonical($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param array<string, mixed> $options setcookie() options */
    public function cookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = [$name, $value, $options];
        return $this;
    }

    /** @return list<array{string, string, array<string, mixed>}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function isStream(): bool
    {
        return $this->stream !== null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v, true);
            }
            foreach ($this->cookies as [$name, $value, $options]) {
                setcookie($name, $value, $options);
            }
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $this->body;
        }
    }

    /** Runs a streaming body into a string (tests). */
    public function captureStream(): string
    {
        if ($this->stream === null) {
            return $this->body;
        }
        ob_start();
        ($this->stream)();
        return (string) ob_get_clean();
    }

    private function canonical(string $name): string
    {
        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
