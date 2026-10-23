<?php
/**
 * The share row at the end of an article (docs/DESIGN.md 8.36): LinkedIn, X, Bluesky and WhatsApp, each its own share
 * page opened in a new tab with the article's canonical address (and its headline, where the network takes one),
 * every value encoded; and a Copy link button, which site.js's data-copy hook works (shown only with scripting). Plain
 * links: no network's script is loaded, so the page keeps its security policy and sets no third-party cookie.
 *
 * @var array<string, mixed> $copy  content/news.php
 * @var string $url  the article's canonical address
 * @var string $title  its headline
 */
$share = is_array($copy['share'] ?? null) ? $copy['share'] : [];
$on = (string) ($share['on'] ?? 'Share on');
$newTab = '<span class="u-visually-hidden"> (' . e($copy['new_tab'] ?? 'opens in a new tab') . ')</span>';
$u = rawurlencode($url);
$networks = [
    'LinkedIn' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $u,
    'X' => 'https://x.com/intent/tweet?text=' . rawurlencode($title) . '&url=' . $u,
    'Bluesky' => 'https://bsky.app/intent/compose?text=' . rawurlencode($title . ' ' . $url),
    'WhatsApp' => 'https://wa.me/?text=' . rawurlencode($title . ' ' . $url),
];
?>
<section class="news-part news-share" aria-labelledby="news-share">
    <h2 class="news-share-title" id="news-share"><?= e($share['title'] ?? 'Share this article') ?></h2>
    <ul class="news-share-list" role="list">
        <?php foreach ($networks as $name => $href): ?>
            <li><a href="<?= e($href) ?>" target="_blank" rel="nofollow noopener"><span class="u-visually-hidden"><?= e($on) ?> </span><?= e($name) ?><?= icon('external', 'c-icon--sm') ?><?= $newTab ?></a></li>
        <?php endforeach; ?>
        <li class="news-js-only"><button type="button" data-copy="<?= e($url) ?>"><?= icon('copy', 'c-icon--sm') ?><span data-copy-label><?= e($share['copy'] ?? 'Copy link') ?></span></button></li>
    </ul>
</section>
