<?php

declare(strict_types=1);

namespace Psc\View;

/**
 * Everything a page says about itself to search engines and link previews: the title tag, description, canonical
 * URL, robots directive, Open Graph / Twitter card, breadcrumbs and the JSON-LD graph. Rendered once in the
 * layout's <head> by toHead().
 */
final class Seo
{
    /** @var list<array{name: string, path: string}> */
    public array $breadcrumbs = [];

    /** @var list<array<string, mixed>> JSON-LD nodes added to the page's @graph */
    public array $graph = [];

    public string $ogType = 'website';
    public string $robots = 'index, follow, max-image-preview:large, max-snippet:-1';
    public ?string $image = null;
    public ?string $imageAlt = null;
    public ?int $modified = null;
    /** The headline share cards show (og:title, twitter:title) when it differs from the <title>; null: the title. */
    public ?string $ogTitle = null;

    public function __construct(
        public string $title,
        public string $description,
        public string $path,
        private readonly string $baseUrl,
        private readonly string $siteName,
        private readonly string $locale = 'en_GB',
    ) {
    }

    public function noindex(): self
    {
        $this->robots = 'noindex, nofollow';
        return $this;
    }

    /**
     * Kept out of the index while its links are still followed (page 2 and on of a list, a search, an empty
     * category): like noindex(), it carries no canonical and no structured data.
     */
    public function noindexFollow(): self
    {
        $this->robots = 'noindex, follow';
        return $this;
    }

    public function isIndexable(): bool
    {
        return !str_contains($this->robots, 'noindex');
    }

    public function canonical(): string
    {
        return $this->baseUrl . $this->path;
    }

    public function absolute(string $path): string
    {
        if (preg_match('#^https?://#', $path)) {
            return $path;
        }
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    /** @param array<string, mixed> $node */
    public function add(array $node): self
    {
        $this->graph[] = $node;
        return $this;
    }

    public function crumb(string $name, string $path): self
    {
        $this->breadcrumbs[] = ['name' => $name, 'path' => $path];
        return $this;
    }

    public function toHead(): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $image = $this->absolute($this->image ?? '/assets/img/og/default.png');
        // A card without its own description is named by the page it stands for, never by the site name alone.
        $alt = $this->imageAlt ?? $this->title;
        $out = [];
        $out[] = '<title>' . $e($this->title) . '</title>';
        $out[] = '<meta name="description" content="' . $e($this->description) . '">';
        $out[] = '<meta name="robots" content="' . $e($this->robots) . '">';
        if ($this->isIndexable()) {
            $out[] = '<link rel="canonical" href="' . $e($this->canonical()) . '">';
            $out[] = '<link rel="alternate" hreflang="en-GB" href="' . $e($this->canonical()) . '">';
            $out[] = '<link rel="alternate" hreflang="x-default" href="' . $e($this->canonical()) . '">';
        }
        $out[] = '<meta property="og:site_name" content="' . $e($this->siteName) . '">';
        $out[] = '<meta property="og:locale" content="' . $e($this->locale) . '">';
        $out[] = '<meta property="og:type" content="' . $e($this->ogType) . '">';
        $out[] = '<meta property="og:title" content="' . $e($this->ogTitle ?? $this->title) . '">';
        $out[] = '<meta property="og:description" content="' . $e($this->description) . '">';
        $out[] = '<meta property="og:url" content="' . $e($this->canonical()) . '">';
        $out[] = '<meta property="og:image" content="' . $e($image) . '">';
        $out[] = '<meta property="og:image:width" content="1200">';
        $out[] = '<meta property="og:image:height" content="630">';
        $out[] = '<meta property="og:image:alt" content="' . $e($alt) . '">';
        $out[] = '<meta name="twitter:card" content="summary_large_image">';
        $out[] = '<meta name="twitter:title" content="' . $e($this->ogTitle ?? $this->title) . '">';
        $out[] = '<meta name="twitter:description" content="' . $e($this->description) . '">';
        $out[] = '<meta name="twitter:image" content="' . $e($image) . '">';
        if ($this->isIndexable()) {
            $graph = $this->graph;
            if (count($this->breadcrumbs) > 1) {
                $graph[] = Schema::breadcrumbs($this->breadcrumbs, $this->baseUrl);
            }
            if ($graph !== []) {
                $json = json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
                $out[] = '<script type="application/ld+json">' . $json . '</script>';
            }
        }
        return implode("\n    ", $out);
    }
}
