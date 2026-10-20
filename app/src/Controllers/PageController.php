<?php

declare(strict_types=1);

namespace Psc\Controllers;

use Psc\Content\Content;
use Psc\Http\Request;
use Psc\Http\Response;
use Psc\View\Schema;

/**
 * Renders a public page from the registry (content/pages.php). Each page gets its title, description, canonical,
 * breadcrumbs and the default graph here; a product page also gets its SoftwareApplication node with the offers
 * from its licensing file. The page template adds its own nodes (FAQ, HowTo, ItemList) to $seo while rendering.
 */
final class PageController extends Controller
{
    private const WEBPAGE_TYPES = [
        'about' => 'AboutPage',
        'contact' => 'ContactPage',
        'faq' => 'FAQPage',
        'hub' => 'CollectionPage',
        'licensing' => 'WebPage',
        'legal' => 'WebPage',
    ];

    public function show(Request $request): Response
    {
        $page = Content::page($request->path);
        if ($page === null) {
            return $this->notFound();
        }
        $base = $this->app->baseUrl();
        $seo = $this->app->seo((string) $page['title'], (string) $page['description'], $request->path);
        $seo->image = isset($page['og']) ? (string) $page['og'] : null;
        $seo->imageAlt = isset($page['og_alt']) ? (string) $page['og_alt'] : null;
        $seo->breadcrumbs = Content::trail($request->path);
        if (!empty($page['noindex'])) {
            $seo->noindex();
        }
        $this->baseGraph($seo);

        $type = (string) ($page['type'] ?? 'page');
        $productKey = isset($page['product']) ? (string) $page['product'] : null;
        $product = $productKey !== null ? Content::product($productKey) : null;
        $licensing = $productKey !== null ? Content::licensing($productKey) : null;

        if ($type !== 'faq') {
            $seo->add(Schema::webPage($base, $seo->canonical(), (string) $page['title'], (string) $page['description'], self::WEBPAGE_TYPES[$type] ?? 'WebPage'));
        }
        if ($product !== null && in_array($type, ['product', 'licensing'], true)) {
            $seo->ogType = $type === 'product' ? 'product' : 'website';
            $seo->add($this->softwareNode($productKey ?? '', $product, $licensing));
        }

        return $this->page((string) $page['template'], [
            'page' => $page,
            'section' => $page['section'] ?? null,
            'productKey' => $productKey,
            'product' => $product,
            'licensing' => $licensing,
        ], $seo);
    }

    /**
     * @param array<string, mixed> $product
     * @param array<string, mixed>|null $licensing
     * @return array<string, mixed>
     */
    private function softwareNode(string $key, array $product, ?array $licensing): array
    {
        $base = $this->app->baseUrl();
        $offers = [];
        foreach (($licensing['tiers'] ?? []) as $tier) {
            if (!is_array($tier) || !array_key_exists('price', $tier)) {
                continue;
            }
            $price = $tier['price'];
            $offers[] = [
                'name' => ($product['name'] ?? $key) . ' ' . ($tier['name'] ?? ''),
                'price' => is_numeric($price) ? (string) $price : null,
                'url' => $base . '/' . $key . '/licensing/',
                'description' => isset($tier['summary']) ? (string) $tier['summary'] : null,
            ];
        }
        $features = [];
        foreach (($product['feature_groups'] ?? []) as $g) {
            if (is_array($g) && isset($g['title'])) {
                $features[] = (string) $g['title'];
            }
        }
        $shots = [];
        foreach (array_slice($product['screenshots'] ?? [], 0, 4) as $s) {
            if (is_array($s) && isset($s['src'])) {
                $shots[] = $base . '/' . ltrim((string) $s['src'], '/');
            }
        }
        return Schema::software(
            $base,
            $base . '/' . $key . '/',
            (string) ($product['name'] ?? $key),
            (string) ($product['summary'] ?? $product['tagline'] ?? ''),
            (string) ($product['schema_category'] ?? 'MultimediaApplication'),
            (string) ($product['os'] ?? 'Windows 10, Windows 11'),
            $offers,
            $features,
            $shots,
            isset($product['version']) ? (string) $product['version'] : null,
        );
    }
}
