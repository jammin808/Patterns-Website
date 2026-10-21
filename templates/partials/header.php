<?php
/**
 * The site header with the Products mega-menu and the mobile drawer (docs/DESIGN.md 8.3), from content/site.php.
 *
 * @var string|null $section
 */
$site = content('site');
$path = $app->request()?->path ?? '/';
$products = \Psc\Content\Content::PRODUCTS;
$current = static function (string $href, ?string $navSection) use ($path, $section): string {
    if ($href === $path) {
        return ' aria-current="page"';
    }
    if ($navSection !== null && $navSection === $section) {
        return ' aria-current="true"';
    }
    return '';
};
?>
<header class="c-header" data-header>
    <div class="l-wrap c-header-bar">
        <a class="c-brand" href="/" aria-label="Patterns Show Control, home">
            <span class="c-brand-mark" aria-hidden="true"></span>
            <span class="c-brand-name">Patterns <span>Show Control</span></span>
        </a>
        <nav class="c-nav" id="site-nav" aria-label="Main">
            <ul class="c-nav-list" role="list">
                <?php foreach (($site['nav'] ?? []) as $item): ?>
                    <?php if (!empty($item['children'])): $inProducts = in_array($section, $products, true); ?>
                        <li class="c-mega" data-mega>
                            <button class="c-nav-link" type="button" aria-expanded="false" aria-controls="mega-products"<?= $inProducts ? ' aria-current="true"' : '' ?>>
                                <?= e($item['label']) ?> <span class="c-mega-mark" aria-hidden="true"></span><?= icon('chevron', 'c-icon--sm') ?>
                            </button>
                            <div class="c-mega-panel" id="mega-products">
                                <ul class="c-mega-grid" role="list">
                                    <?php foreach ($item['children'] as $child): $p = product((string) $child['product']); ?>
                                        <li class="c-mega-product" data-product="<?= e($child['product']) ?>">
                                            <a class="c-mega-title" href="<?= e($child['href']) ?>"><span class="c-swatch"></span><?= e($child['label']) ?> <span class="c-role"><?= e($p['role'] ?? '') ?></span></a>
                                            <p class="c-mega-line"><?= e($child['line'] ?? '') ?></p>
                                            <ul class="c-mega-links" role="list">
                                                <?php foreach (($child['links'] ?? []) as $l): ?>
                                                    <?php /* The product's name rides in the link, unseen: "Licensing" alone names four pages. */ ?>
                                                    <li><a href="<?= e($l['href']) ?>"<?= $l['href'] === $path ? ' aria-current="page"' : '' ?>><span class="u-visually-hidden"><?= e($child['label']) ?> </span><?= e($l['label']) ?></a></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <?php if (!empty($item['footer'])): ?>
                                    <ul class="c-mega-foot" role="list">
                                        <?php foreach ($item['footer'] as $l): ?>
                                            <li><a href="<?= e($l['href']) ?>"><?= e($l['label']) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php else: $navSection = trim((string) $item['href'], '/'); ?>
                        <li><a class="c-nav-link" href="<?= e($item['href']) ?>"<?= $current((string) $item['href'], $navSection) ?>><?= e($item['label']) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <div class="c-nav-actions">
                <a class="c-btn c-btn--secondary" href="/downloads/"<?= $path === '/downloads/' ? ' aria-current="page"' : '' ?>>Downloads</a>
                <a class="c-btn c-btn--primary" href="/portal/login/" data-account-link data-account-href="/portal/" data-account-label="My account">
                    <?= icon('user', 'c-icon--sm') ?><span data-account-text>Client login</span></a>
            </div>
        </nav>
        <div class="c-header-tools">
            <div class="c-theme" role="group" aria-label="Colour theme" data-theme-toggle>
                <button type="button" data-theme-set="system" aria-pressed="true" title="Follow the system"><?= icon('system', 'c-icon--sm') ?><span class="u-visually-hidden">Follow the system</span></button>
                <button type="button" data-theme-set="light" aria-pressed="false" title="Light theme"><?= icon('sun', 'c-icon--sm') ?><span class="u-visually-hidden">Light</span></button>
                <button type="button" data-theme-set="dark" aria-pressed="false" title="Dark theme"><?= icon('moon', 'c-icon--sm') ?><span class="u-visually-hidden">Dark</span></button>
            </div>
            <button class="c-btn c-btn--secondary c-btn--sm c-menu-btn" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
                <svg class="c-icon c-icon-open" aria-hidden="true" focusable="false"><use href="#i-menu"/></svg><svg class="c-icon c-icon-close" aria-hidden="true" focusable="false"><use href="#i-close"/></svg><span data-nav-label>Menu</span>
            </button>
        </div>
    </div>
    <div class="c-scroll-line" aria-hidden="true"></div>
</header>
