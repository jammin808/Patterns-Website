<?php
/**
 * The product sub-navigation (docs/DESIGN.md 8.20), with the same labels and order as the product's entry in the
 * Products menu (content/site.php), so the header and the page never disagree.
 *
 * @var string $productKey
 * @var array<string, mixed> $product
 * @var string|null $class  extra classes, e.g. 'u-mt-6'
 */
$class ??= '';
$links = [];
foreach ((content('site')['nav'] ?? []) as $item) {
    foreach (($item['children'] ?? []) as $child) {
        if (($child['product'] ?? null) === $productKey) {
            $links = $child['links'] ?? [];
        }
    }
}
if ($links === []) {
    $links = [
        ['label' => 'Overview', 'href' => '/' . $productKey . '/'],
        ['label' => 'Features', 'href' => '/' . $productKey . '/features/'],
        ['label' => 'Licensing', 'href' => '/' . $productKey . '/licensing/'],
        ['label' => 'Help', 'href' => '/support/' . $productKey . '/'],
    ];
}
$here = $app->request()?->path ?? '';
?>
<nav class="c-subnav<?= $class !== '' ? ' ' . e($class) : '' ?>" aria-label="<?= e($product['name']) ?> pages">
    <?php foreach ($links as $l): ?><a href="<?= e($l['href']) ?>"<?= $l['href'] === $here ? ' aria-current="page"' : '' ?>><?= e($l['label']) ?></a><?php endforeach; ?>
</nav>
