<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fences on the site's words (content/, knowledge/, templates/pages): the products are proprietary, the site is
 * promotional and never shows internal working notes, and the assistant's knowledge holds no code or commands.
 */
final class ContentRulesTest extends TestCase
{
    /** @return list<string> */
    private static function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/content/*.php') ?: [],
            glob($root . '/content/*/*.php') ?: [],
            glob($root . '/content/legal/*.md') ?: [],
            glob($root . '/knowledge/*.md') ?: [],
        );
        $it = is_dir($root . '/templates/pages') ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/templates/pages', \FilesystemIterator::SKIP_DOTS)) : [];
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }

    /** @return iterable<string, array{string, string}> */
    public static function banned(): iterable
    {
        yield 'open-source licence claims' => ['/\bMIT\b|open[- ]source|\bGPL\b/i', 'The products are proprietary: no MIT, GPL or open-source claims.'];
        yield 'internal working notes' => ['/\bround \d+\b|\bunwalked\b|\blab line\b|\bOPEN\.md\b|\bL\d{2}\b ledger|\bTODO\b|\bFIXME\b|lorem ipsum/i', 'No internal working notes or placeholders.'];
        yield 'code and commands in knowledge' => ['/```|\b(dotnet|npm|pwsh|powershell|composer|git clone|winget)\s+[a-z-]/i', 'No code or commands.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('banned')]
    public function testContentAvoids(string $pattern, string $why): void
    {
        $hits = [];
        foreach (self::files() as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = basename(dirname($file)) . '/' . basename($file) . ':' . ($n + 1) . ': ' . trim(mb_substr($line, 0, 140));
                }
            }
        }
        self::assertSame([], $hits, $why);
    }

    public function testEveryContentFileReturnsAnArray(): void
    {
        foreach (self::files() as $file) {
            if (str_ends_with($file, '.php') && str_contains($file, '/content/')) {
                self::assertIsArray(require $file, $file);
            }
        }
    }
}
