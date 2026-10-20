<?php

declare(strict_types=1);

namespace Psc\Support;

/**
 * One JSON line per event in storage/logs/site-YYYY-MM-DD.log. Context keys that look like secrets are masked, so
 * a password, token or key never reaches the log.
 */
final class Logger
{
    private const SECRET_KEYS = '/pass|secret|token|api_?key|authorization|cookie|hash/i';

    public function __construct(private readonly string $dir)
    {
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function exception(\Throwable $e, string $where = ''): void
    {
        $this->write('error', $where !== '' ? $where : 'exception', [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0770, true)) {
            error_log('[psc] ' . $level . ' ' . $message);
            return;
        }
        $line = json_encode([
            't' => gmdate('c'),
            'level' => $level,
            'msg' => $message,
            'ctx' => self::mask($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($this->dir . '/site-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private static function mask(array $context): array
    {
        foreach ($context as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k)) {
                $context[$k] = '***';
            } elseif (is_array($v)) {
                $context[$k] = self::mask($v);
            }
        }
        return $context;
    }
}
