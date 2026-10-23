<?php

declare(strict_types=1);

namespace Psc\View;

/**
 * Builders for the schema.org nodes the site uses. Every node that other nodes point at has a stable @id
 * (<site>/#organization, <site>/#website, <page>#software), so one graph per page links them.
 */
final class Schema
{
    public static function organizationId(string $base): string
    {
        return $base . '/#organization';
    }

    public static function websiteId(string $base): string
    {
        return $base . '/#website';
    }

    /** The publisher as a person: described on the About page, and pointed at as founder and author everywhere. */
    public static function personId(string $base): string
    {
        return $base . '/about/#ben';
    }

    /** @return array<string, mixed> */
    public static function person(string $base, string $name): array
    {
        return [
            '@type' => 'Person',
            '@id' => self::personId($base),
            'name' => $name,
            'url' => $base . '/about/',
            'worksFor' => ['@id' => self::organizationId($base)],
        ];
    }

    /**
     * The organisation. $sameAs are its own profiles elsewhere (content/site.php 'same_as'), https addresses only;
     * none leaves the property out.
     *
     * @param list<string> $sameAs
     * @return array<string, mixed>
     */
    public static function organization(string $base, string $name, array $sameAs = []): array
    {
        $profiles = array_values(array_unique(array_filter($sameAs, static fn (mixed $u): bool => is_string($u) && preg_match('#^https://[^\s/]+\.[^\s/]+(?:/\S*)?$#i', $u) === 1)));
        // Reached through the contact page's form: no email address is published for crawlers to collect.
        return [
            '@type' => 'Organization',
            '@id' => self::organizationId($base),
            'name' => $name,
            'url' => $base . '/',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => $base . '/assets/img/brand/logo-512.png',
                'width' => 512,
                'height' => 512,
            ],
            'founder' => ['@id' => self::personId($base)],
            'contactPoint' => [[
                '@type' => 'ContactPoint',
                'contactType' => 'customer support',
                'url' => $base . '/contact/',
                'availableLanguage' => ['en-GB'],
            ]],
            'areaServed' => 'Worldwide',
        ] + ($profiles !== [] ? ['sameAs' => $profiles] : []);
    }

    /** @return array<string, mixed> */
    public static function website(string $base, string $name, string $description): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::websiteId($base),
            'url' => $base . '/',
            'name' => $name,
            'description' => $description,
            'inLanguage' => 'en-GB',
            'publisher' => ['@id' => self::organizationId($base)],
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function webPage(string $base, string $url, string $name, string $description, string $type = 'WebPage', array $extra = []): array
    {
        return [
            '@type' => $type,
            '@id' => $url . '#webpage',
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'inLanguage' => 'en-GB',
            'isPartOf' => ['@id' => self::websiteId($base)],
            'publisher' => ['@id' => self::organizationId($base)],
        ] + $extra;
    }

    /**
     * A product as a SoftwareApplication with its offers. $offers: list of [name, price (string|null), url,
     * description?]; a null price is left out (pricing not yet announced) rather than guessed.
     *
     * @param list<array{name: string, price: ?string, url: string, description?: string}> $offers
     * @param list<string> $features
     * @param list<string> $screenshots absolute URLs
     * @return array<string, mixed>
     */
    public static function software(
        string $base,
        string $url,
        string $name,
        string $description,
        string $category,
        string $os,
        array $offers = [],
        array $features = [],
        array $screenshots = [],
        ?string $version = null,
    ): array {
        $node = [
            '@type' => 'SoftwareApplication',
            '@id' => $url . '#software',
            'name' => $name,
            'description' => $description,
            'url' => $url,
            'applicationCategory' => $category,
            'operatingSystem' => $os,
            'publisher' => ['@id' => self::organizationId($base)],
            'author' => ['@id' => self::organizationId($base)],
            'inLanguage' => 'en-GB',
        ];
        if ($version !== null && $version !== '') {
            $node['softwareVersion'] = $version;
        }
        if ($features !== []) {
            $node['featureList'] = implode(', ', $features);
        }
        if ($screenshots !== []) {
            $node['screenshot'] = $screenshots;
        }
        $o = [];
        foreach ($offers as $offer) {
            if ($offer['price'] === null) {
                continue;
            }
            $o[] = array_filter([
                '@type' => 'Offer',
                'name' => $offer['name'],
                'price' => $offer['price'],
                'priceCurrency' => 'GBP',
                'url' => $offer['url'],
                'description' => $offer['description'] ?? null,
                'availability' => 'https://schema.org/InStock',
                'seller' => ['@id' => self::organizationId($base)],
            ], static fn ($v): bool => $v !== null);
        }
        if ($o !== []) {
            $node['offers'] = count($o) === 1 ? $o[0] : $o;
        }
        return $node;
    }

    /**
     * @param list<array{q: string, a: string}> $faqs answers as plain text
     * @param array<string, mixed> $extra page fields, for an FAQPage that stands in for the page's WebPage
     * @return array<string, mixed>
     */
    public static function faq(string $url, array $faqs, array $extra = []): array
    {
        return [
            '@type' => 'FAQPage',
            '@id' => $url . '#faq',
        ] + $extra + [
            'mainEntity' => array_map(static fn (array $f): array => [
                '@type' => 'Question',
                'name' => $f['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
            ], $faqs),
        ];
    }

    /**
     * @param list<array{name: string, url: string}> $items
     * @return array<string, mixed>
     */
    public static function itemList(string $url, string $name, array $items): array
    {
        return [
            '@type' => 'ItemList',
            '@id' => $url . '#list',
            'name' => $name,
            'itemListElement' => array_map(static fn (array $it, int $i): array => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $it['name'],
                'url' => $it['url'],
            ], $items, array_keys($items)),
        ];
    }

    /**
     * @param list<array{name: string, text: string}> $steps
     * @return array<string, mixed>
     */
    public static function howTo(string $url, string $name, string $description, array $steps): array
    {
        return [
            '@type' => 'HowTo',
            '@id' => $url . '#howto',
            'name' => $name,
            'description' => $description,
            'step' => array_map(static fn (array $s, int $i): array => [
                '@type' => 'HowToStep',
                'position' => $i + 1,
                'name' => $s['name'],
                'text' => $s['text'],
            ], $steps, array_keys($steps)),
        ];
    }

    /**
     * @param list<array{name: string, path: string}> $crumbs
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs, string $base): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(static fn (array $c, int $i): array => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $c['name'],
                'item' => $base . $c['path'],
            ], $crumbs, array_keys($crumbs)),
        ];
    }
}
