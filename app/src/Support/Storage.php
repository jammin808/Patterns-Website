<?php

declare(strict_types=1);

namespace Psc\Support;

/**
 * Where runtime data lives: sessions, logs, caches, uploads, release files, licence files and the development
 * database. By default '../patterns-data/storage' beside the site (outside the web root, so redeploys never touch
 * it) when that can be created, else the denied ./storage directory.
 */
final class Storage
{
    private function __construct(private readonly string $root)
    {
    }

    public static function resolve(string $appRoot, ?string $configured): self
    {
        if ($configured !== null && $configured !== '') {
            return new self(rtrim($configured, '/'));
        }
        $outside = dirname($appRoot) . '/patterns-data/storage';
        if (is_dir($outside) && is_writable($outside)) {
            return new self($outside);
        }
        if (is_writable(dirname($appRoot)) && @mkdir($outside, 0770, true)) {
            return new self($outside);
        }
        return new self($appRoot . '/storage');
    }

    public function root(): string
    {
        return $this->root;
    }

    /** An absolute path under storage; the directory part is created on demand. */
    public function path(string $relative = ''): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if (str_contains($relative, '..')) {
            throw new \InvalidArgumentException('Storage paths may not climb');
        }
        $full = $relative === '' ? $this->root : $this->root . '/' . $relative;
        return $full;
    }

    public function dir(string $relative): string
    {
        $dir = $this->path($relative);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    /** A random file name that cannot collide or be guessed. */
    public static function randomName(string $extension = ''): string
    {
        $name = bin2hex(random_bytes(16));
        return $extension !== '' ? $name . '.' . strtolower($extension) : $name;
    }
}
