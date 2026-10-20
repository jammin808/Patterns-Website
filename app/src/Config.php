<?php

declare(strict_types=1);

namespace Psc;

/**
 * Read-only settings: config/defaults.php overlaid by the server's own file. Lookups use dotted keys
 * ("chat.api_key"). The server file is searched outside the web root first; see config/config.example.php.
 */
final class Config
{
    /** @param array<string, mixed> $values */
    private function __construct(private array $values, private ?string $source)
    {
    }

    /** @param array<string, mixed> $overrides */
    public static function load(string $root, array $overrides = []): self
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require $root . '/config/defaults.php';
        $source = self::locate($root);
        $local = [];
        if ($source !== null) {
            $loaded = require $source;
            $local = is_array($loaded) ? $loaded : [];
        }
        return new self(self::merge(self::merge($defaults, $local), $overrides), $source);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(string $root, array $values): self
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require $root . '/config/defaults.php';
        return new self(self::merge($defaults, $values), null);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->get($key, $default);
        return is_scalar($v) ? (string) $v : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        return is_bool($v) ? $v : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<mixed> */
    public function list(string $key): array
    {
        $v = $this->get($key, []);
        return is_array($v) ? array_values($v) : [];
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function isProduction(): bool
    {
        return $this->string('app.env') === 'production';
    }

    /**
     * The server file: $PSC_CONFIG, then ../patterns-data/config.php, then config/local.php. A local.php found
     * while the outside folder is creatable is moved there, so the secret file leaves the web root.
     */
    private static function locate(string $root): ?string
    {
        $env = getenv('PSC_CONFIG');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return $env;
        }
        $outsideDir = dirname($root) . '/patterns-data';
        $outside = $outsideDir . '/config.php';
        if (is_file($outside)) {
            return $outside;
        }
        $local = $root . '/config/local.php';
        if (!is_file($local)) {
            return null;
        }
        if (getenv('PSC_NO_CONFIG_MOVE') === false && self::canCreate($outsideDir)) {
            if ((is_dir($outsideDir) || @mkdir($outsideDir, 0700, true)) && @rename($local, $outside)) {
                @chmod($outside, 0600);
                return $outside;
            }
        }
        return $local;
    }

    private static function canCreate(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        $parent = dirname($dir);
        return is_dir($parent) && is_writable($parent);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }
}
