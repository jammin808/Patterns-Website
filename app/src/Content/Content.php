<?php

declare(strict_types=1);

namespace Psc\Content;

/**
 * The site's words and facts as PHP data under content/: one source for pages, navigation, structured data, the
 * sitemap and the support assistant's knowledge. Files are read once per request.
 *
 *   content/site.php            brand, navigation, footer
 *   content/pages.php           the page registry (path → template, title, description, sitemap, breadcrumbs)
 *   content/products/<key>.php  one product each (patterns, stagewatch, agmessage, playmap)
 *   content/licensing/<key>.php each product's tiers and comparison matrix
 *   content/workflows.php       cross-product workflows
 *   content/faq.php             the support FAQ
 */
final class Content
{
    /** @var array<string, array<mixed>> */
    private static array $cache = [];

    /** @var array<string, string>|null question key => the path of the page that marks it up */
    private static ?array $faqOwners = null;

    public const PRODUCTS = ['patterns', 'stagewatch', 'agmessage', 'playmap'];

    private static function root(): string
    {
        return (defined('PSC_ROOT') ? (string) PSC_ROOT : dirname(__DIR__, 3)) . '/content';
    }

    /** @return array<mixed> */
    public static function get(string $name): array
    {
        if (!isset(self::$cache[$name])) {
            $name = str_replace(['..', '\\'], ['', '/'], $name);
            $file = self::root() . '/' . $name . '.php';
            $data = is_file($file) ? require $file : [];
            self::$cache[$name] = is_array($data) ? $data : [];
        }
        return self::$cache[$name];
    }

    /** @return array<string, mixed>|null */
    public static function product(string $key): ?array
    {
        if (!in_array($key, self::PRODUCTS, true)) {
            return null;
        }
        $p = self::get('products/' . $key);
        return $p === [] ? null : $p;
    }

    /** @return array<string, string> ISO 3166-1 alpha-2 code => name, alphabetical */
    public static function countries(): array
    {
        $all = self::get('countries')['all'] ?? [];
        return is_array($all) ? $all : [];
    }

    /** @return list<string> the codes the contact and account forms list first */
    public static function topCountries(): array
    {
        $top = self::get('countries')['top'] ?? [];
        return array_values(array_filter(is_array($top) ? $top : [], static fn ($c): bool => isset(self::countries()[$c])));
    }

    /** @return array<string, array<string, mixed>> key => product, in ecosystem order */
    public static function products(): array
    {
        $out = [];
        foreach (self::PRODUCTS as $key) {
            $p = self::product($key);
            if ($p !== null) {
                $out[$key] = $p;
            }
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function licensing(string $key): ?array
    {
        if (!in_array($key, self::PRODUCTS, true)) {
            return null;
        }
        $l = self::get('licensing/' . $key);
        return $l === [] ? null : $l;
    }

    /** @return array<string, array<string, mixed>> path => page */
    public static function pages(): array
    {
        /** @var array<string, array<string, mixed>> $pages */
        $pages = self::get('pages');
        return $pages;
    }

    /** @return array<string, mixed>|null */
    public static function page(string $path): ?array
    {
        return self::pages()[$path] ?? null;
    }

    /** @return list<array{name: string, path: string}> the breadcrumb trail from Home to $path */
    public static function trail(string $path): array
    {
        $pages = self::pages();
        $trail = [];
        $guard = 0;
        while ($path !== null && isset($pages[$path]) && $guard++ < 10) {
            $p = $pages[$path];
            array_unshift($trail, ['name' => (string) ($p['crumb'] ?? $p['title']), 'path' => $path]);
            $path = $p['parent'] ?? null;
        }
        return $trail;
    }

    /**
     * The price a tier is sold at while its launch offer runs (its 'launch_price'), or null when it has none. Every
     * reader takes the launch figure from here: the pages, the portal, the structured data and the assistant.
     *
     * @param array<string, mixed> $tier
     */
    public static function launchPrice(array $tier): ?string
    {
        $launch = $tier['launch_price'] ?? null;
        return is_numeric($launch) ? (string) $launch : null;
    }

    /**
     * The one page whose structured data carries an FAQ question (it may be shown on several pages, but is marked
     * up once site-wide): a product's own question on its overview, then on its licensing page; a general or
     * licensing question on /support/faq/; then the ecosystem page; then a workflow. A page lower in that order
     * shows a question it shares without marking it up.
     */
    public static function faqOwner(string $question): ?string
    {
        if (self::$faqOwners === null) {
            $owners = [];
            $claim = static function (string $path, mixed $faqs) use (&$owners): void {
                foreach (is_array($faqs) ? $faqs : [] as $f) {
                    if (is_array($f) && isset($f['q'])) {
                        $owners[self::faqKey((string) $f['q'])] ??= $path;
                    }
                }
            };
            foreach (self::PRODUCTS as $key) {
                $claim('/' . $key . '/', self::product($key)['faqs'] ?? []);
            }
            foreach (self::PRODUCTS as $key) {
                $claim('/' . $key . '/licensing/', self::licensing($key)['faqs'] ?? []);
            }
            foreach (self::get('faq') as $group) {
                $claim('/support/faq/', is_array($group) ? ($group['items'] ?? []) : []);
            }
            $claim('/ecosystem/', self::get('ecosystem')['faqs'] ?? []);
            foreach (self::get('workflows') as $slug => $wf) {
                $claim('/workflows/' . $slug . '/', is_array($wf) ? ($wf['faqs'] ?? []) : []);
            }
            self::$faqOwners = $owners;
        }
        return self::$faqOwners[self::faqKey($question)] ?? null;
    }

    /** A question compared as words: case, spacing and closing punctuation do not make it another question. */
    private static function faqKey(string $question): string
    {
        return rtrim(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $question))), '?.! ');
    }

    /**
     * Stands in for one content file until the next flush(): the tests' way to try an owner's switch (content/site.php
     * 'block_training', say) without editing the file.
     *
     * @param array<mixed> $data
     */
    public static function override(string $name, array $data): void
    {
        self::$cache[$name] = $data;
    }

    public static function flush(): void
    {
        self::$faqOwners = null;
        self::$cache = [];
    }
}
