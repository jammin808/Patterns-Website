<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psc\Chat\Knowledge;
use Psc\Chat\Prompts;

/**
 * Fences on the site's words (content/, knowledge/, templates/pages): the products are proprietary, the site is
 * promotional and never shows internal working notes, and the assistant's knowledge holds no code or commands.
 * The house style has no em dash, in anything a reader or the assistant sees.
 */
final class ContentRulesTest extends TestCase
{
    /** An em dash anywhere, or an en dash with a space beside it standing in for one; an en dash between numbers is a range. */
    private const DASH = '/\x{2014}|\h\x{2013}|\x{2013}\h/u';

    /** @return list<string> */
    private static function files(): array
    {
        $root = dirname(__DIR__, 2);
        return array_merge(
            glob($root . '/content/*.php') ?: [],
            glob($root . '/content/*/*.php') ?: [],
            glob($root . '/content/legal/*.md') ?: [],
            glob($root . '/knowledge/*.md') ?: [],
            array_values(array_filter(self::tree($root . '/templates/pages'), static fn (string $f): bool => str_ends_with($f, '.php'))),
        );
    }

    /**
     * Every file whose words reach a reader or the assistant: all of content/, the knowledge notes, every template
     * (the emails and the admin pages too), the site's scripts and the assistant's own prompts.
     *
     * @return list<string>
     */
    private static function dashFiles(): array
    {
        $root = dirname(__DIR__, 2);
        return array_merge(
            self::tree($root . '/content'),
            glob($root . '/knowledge/*.md') ?: [],
            self::tree($root . '/templates'),
            glob($root . '/public/assets/js/*.js') ?: [],
            [$root . '/app/src/Chat/Prompts.php'],
        );
    }

    /** @return list<string> every file under $dir, sorted */
    private static function tree(string $dir): array
    {
        $files = [];
        $it = is_dir($dir) ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) : [];
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * @param list<string> $files
     * @return list<string> "path:line: text" for each line that matches
     */
    private static function hits(array $files, string $pattern): array
    {
        $root = dirname(__DIR__, 2) . '/';
        $hits = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $n => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = str_replace($root, '', $file) . ':' . ($n + 1) . ': ' . trim(mb_substr($line, 0, 140));
                }
            }
        }
        return $hits;
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

    public function testTheSitesWordsHaveNoEmDash(): void
    {
        $files = self::dashFiles();
        $names = array_map(static fn (string $f): string => str_replace(dirname(__DIR__, 2) . '/', '', $f), $files);
        foreach (['content/legal/licence-terms.md', 'knowledge/website.md', 'templates/emails/contact-receipt.php', 'templates/admin/audit.php', 'public/assets/js/site.js', 'app/src/Chat/Prompts.php'] as $scanned) {
            self::assertContains($scanned, $names, 'the fence reads ' . $scanned);
        }
        self::assertSame([], self::hits($files, self::DASH), 'Use a comma, colon, full stop or brackets in place of an em dash (U+2014) or a spaced en dash (U+2013), at the file and line listed.');
    }

    /** The fence's pattern: what it catches and what it lets through. */
    public function testTheDashPatternCatchesDashesUsedAsPunctuation(): void
    {
        foreach (["Thanks \u{2014} we have it", "Thanks\u{2014}we have it", "Thanks \u{2013} we have it", "Thanks \u{2013}we have it"] as $dashed) {
            self::assertSame(1, preg_match(self::DASH, $dashed), $dashed);
        }
        foreach (['10' . "\u{2013}" . '30 minutes', 'A well-known show', 'Help → License → Plans', 'Thanks, we have it'] as $fine) {
            self::assertSame(0, preg_match(self::DASH, $fine), $fine);
        }
    }

    /**
     * The compiled knowledge joins a name and its line with a colon, never a dash, and points to Industry Updates,
     * while a question about the news itself stays outside the assistant's scope.
     */
    public function testTheAssistantsKnowledgeHasNoEmDash(): void
    {
        $knowledge = (new Knowledge(dirname(__DIR__, 2), sys_get_temp_dir()))->compile();
        self::assertStringNotContainsString("\u{2014}", $knowledge);
        self::assertSame(0, preg_match(self::DASH, $knowledge));
        self::assertStringContainsString("\n- /portal/register/: create a free client account\n", $knowledge);
        self::assertMatchesRegularExpression('#^- /news/ \(Industry Updates\): .+$#m', $knowledge, 'the page list names the section');
        self::assertMatchesRegularExpression('#^- Industry Updates \(/news/\): .+$#m', $knowledge, 'the website notes describe it');
        self::assertStringContainsString('"out_of_scope": everything else: general knowledge, news,', Prompts::gateSystem());
        foreach ([Prompts::OUT_OF_SCOPE, Prompts::NO_CODE, Prompts::gateSystem(), Prompts::answerSystem('', 'Site', 'https://example.test')] as $prompt) {
            self::assertSame(0, preg_match(self::DASH, $prompt), mb_substr($prompt, 0, 60));
        }
    }
}
