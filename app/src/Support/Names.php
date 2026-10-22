<?php

declare(strict_types=1);

namespace Psc\Support;

/**
 * How a person is named to other people: the first name and the initial of the last, never a full name and never
 * an email address. One rule for every place where clients read each other's names (public tickets, news
 * comments), so a name can never say more in one place than in another.
 */
final class Names
{
    /**
     * Characters that are invisible or reorder the text around them (zero-width space, word joiner, byte-order
     * mark, bidirectional embeddings, overrides and isolates). They are removed from names, titles, version numbers
     * and file names, where they could make a value look empty, or make a zip file whose name holds a right-to-left
     * override look like a PNG.
     */
    public const INVISIBLE = '/[\x{200B}\x{2060}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** A single-line value without invisible or reordering characters (see INVISIBLE), its spaces tidied again. */
    public static function visible(string $value): string
    {
        return trim((string) preg_replace('/ {2,}/', ' ', (string) preg_replace(self::INVISIBLE, '', $value)));
    }

    /**
     * "Ada Lovelace" becomes "Ada L."; one word stays as it is. A word with an @ in it is left out, so a name typed
     * as an email address ("ada@example.com", "Ada (ada@example.com)") never shows the address, and invisible or
     * reordering characters are removed first, so a name cannot hide or reverse what is shown. A name with nothing
     * left is $fallback.
     */
    public static function shortName(string $name, string $fallback = 'A client'): string
    {
        $parts = array_values(array_filter(
            preg_split('/\s+/u', self::visible($name), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $part): bool => !str_contains($part, '@')
        ));
        if ($parts === []) {
            return $fallback;
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        return $parts[0] . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
    }

    /**
     * How a comment's author is shown to the person viewing it: "You", staff by name (and everyone by name to
     * staff), and other clients by their short name. A deleted account (anonymised with a reserved .invalid
     * address, flagged as author_gone) is "A former client" to clients.
     *
     * @param array<string, mixed> $comment user_id, is_staff, author_name and author_gone
     */
    public static function commentAuthor(array $comment, int $viewerId, bool $viewerIsAdmin): string
    {
        $name = (string) ($comment['author_name'] ?? '');
        if ((int) $comment['user_id'] === $viewerId) {
            return 'You';
        }
        if ((int) ($comment['is_staff'] ?? 0) === 1 || $viewerIsAdmin) {
            return $name !== '' ? $name : 'Former account';
        }
        if ((int) ($comment['author_gone'] ?? 0) === 1) {
            return 'A former client';
        }
        return self::shortName($name);
    }

    /**
     * The name shown with something a person published for everyone, such as a news comment on a page that reads
     * the same for every visitor: their short name, or $gone once the account was deleted (its email is then a
     * reserved .invalid address).
     *
     * @param array<string, mixed> $user a users row: name and email
     */
    public static function publicName(array $user, string $gone = 'A former client'): string
    {
        if (str_ends_with((string) ($user['email'] ?? ''), '.invalid')) {
            return $gone;
        }
        return self::shortName((string) ($user['name'] ?? ''));
    }
}
