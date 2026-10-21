<?php

declare(strict_types=1);

namespace Psc\Support;

/**
 * Checks and stores uploaded files. A file is accepted only when it arrived through an HTTP upload, its extension
 * is on the allow-list, its size is within the limit, and its sniffed content type agrees with the extension. It is
 * stored under a random name (the visitor's name is kept only as metadata), so nothing uploaded can be executed or
 * guessed. Served back only through a controller, as an attachment.
 */
final class Uploads
{
    /** Extensions whose sniffed MIME type must match one of these prefixes. */
    private const MIME = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'txt' => ['text/', 'application/octet-stream', 'inode/x-empty'],
        'log' => ['text/', 'application/octet-stream', 'inode/x-empty'],
        'csv' => ['text/', 'application/csv', 'application/octet-stream'],
        'json' => ['application/json', 'text/'],
        'xml' => ['text/xml', 'application/xml', 'text/'],
        'dat' => ['application/octet-stream', 'text/', 'application/'],
        'lic' => ['application/octet-stream', 'text/', 'application/'],
        'exe' => ['application/x-dosexec', 'application/x-msdownload', 'application/vnd.microsoft.portable-executable', 'application/octet-stream'],
        'msi' => ['application/x-msi', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2', 'application/octet-stream'],
        'tgz' => ['application/gzip', 'application/x-gzip', 'application/x-compressed-tar'],
        'gz' => ['application/gzip', 'application/x-gzip'],
    ];

    /**
     * Normalises PHP's $_FILES entry for one field into a list of files (handles name[] multiples).
     *
     * @param array<string, mixed>|null $entry
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public static function files(?array $entry): array
    {
        if ($entry === null || !isset($entry['name'])) {
            return [];
        }
        if (!is_array($entry['name'])) {
            return (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [[
                'name' => (string) $entry['name'],
                'type' => (string) ($entry['type'] ?? ''),
                'tmp_name' => (string) ($entry['tmp_name'] ?? ''),
                'error' => (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($entry['size'] ?? 0),
            ]];
        }
        $out = [];
        foreach ($entry['name'] as $i => $name) {
            if (!is_scalar($name) || (int) ($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue; // nested arrays (field[a][]) are not accepted
            }
            $out[] = [
                'name' => (string) $name,
                'type' => (string) ($entry['type'][$i] ?? ''),
                'tmp_name' => (string) ($entry['tmp_name'][$i] ?? ''),
                'error' => (int) ($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($entry['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Validates and moves one upload into $dir.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @param list<string> $allowed lower-case extensions
     * @return array{ok: true, original_name: string, stored_name: string, size: int, mime: string, path: string}|array{ok: false, error: string}
     */
    public static function store(array $file, string $dir, array $allowed, int $maxBytes, bool $trustTestFiles = false): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the server allows.',
                UPLOAD_ERR_PARTIAL => 'The file only partly arrived. Please try again.',
                default => 'The file could not be uploaded.',
            }];
        }
        if (!$trustTestFiles && !is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => 'The file could not be uploaded.'];
        }
        $original = self::cleanName($file['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            return ['ok' => false, 'error' => 'Files of this type are not accepted. Allowed: ' . implode(', ', $allowed) . '.'];
        }
        $size = (int) @filesize($file['tmp_name']);
        if ($size <= 0 || $size > $maxBytes) {
            return ['ok' => false, 'error' => 'Each file must be between 1 byte and ' . filesize_human($maxBytes) . '.'];
        }
        $mime = self::sniff($file['tmp_name']);
        if (!self::mimeMatches($ext, $mime)) {
            return ['ok' => false, 'error' => 'The file\'s content does not match its extension.'];
        }
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            return ['ok' => false, 'error' => 'The file could not be stored.'];
        }
        $stored = Storage::randomName($ext);
        $target = rtrim($dir, '/') . '/' . $stored;
        $moved = $trustTestFiles ? @rename($file['tmp_name'], $target) : @move_uploaded_file($file['tmp_name'], $target);
        if (!$moved) {
            return ['ok' => false, 'error' => 'The file could not be stored.'];
        }
        @chmod($target, 0640);
        return ['ok' => true, 'original_name' => $original, 'stored_name' => $stored, 'size' => $size, 'mime' => $mime, 'path' => $target];
    }

    /** A display-safe file name: no path, no control characters, at most 150 characters. */
    /** A php.ini size ("64M", "2G", "512K", "1048576") in bytes; 0 when it is unset or unreadable. */
    public static function iniBytes(string $value): int
    {
        if (!preg_match('/^(\d+)\s*([KMG]?)/i', trim($value), $m)) {
            return 0;
        }
        return (int) $m[1] * match (strtoupper($m[2])) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        // Control characters, bidi overrides and zero-width characters cannot disguise an extension.
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $name);
        $name = trim($name, " .\t");
        if ($name === '') {
            $name = 'file';
        }
        if (mb_strlen($name) > 150) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 140) . ($ext !== '' ? '.' . $ext : '');
        }
        return $name;
    }

    public static function sniff(string $path): string
    {
        if (class_exists(\finfo::class)) {
            $type = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($type) && $type !== '') {
                return $type;
            }
        }
        return 'application/octet-stream';
    }

    public static function mimeMatches(string $ext, string $mime): bool
    {
        $allowed = self::MIME[$ext] ?? null;
        if ($allowed === null) {
            return false;
        }
        foreach ($allowed as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Headers for sending a stored file to the browser as a download: always an attachment, never sniffed, never
     * cached by a CDN.
     *
     * @return array<string, string>
     */
    public static function downloadHeaders(string $downloadName, int $size, string $mime = 'application/octet-stream'): array
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);
        return [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
    }
}
