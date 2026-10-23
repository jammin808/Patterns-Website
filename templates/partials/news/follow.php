<?php
/**
 * The follow row on the hub and on each category's hub (docs/DESIGN.md 8.36): the page's own RSS feed, the same feed
 * in Feedly and in Inoreader (each reader's subscribe page for it), and Google's preferred-sources page for the site.
 * Plain links: nothing is loaded from any of them, so the page keeps its security policy and stays the same for
 * every visitor. The readers' and Google's open in a new tab.
 *
 * @var array<string, mixed> $copy  content/news.php
 * @var string $feed  the feed's path (Paths::feed())
 * @var string $feedTitle  what the feed is called (its alternate link's title)
 */
$follow = is_array($copy['follow'] ?? null) ? $copy['follow'] : [];
$absolute = url($feed);
$domain = (string) preg_replace('/^www\./', '', (string) parse_url(url('/'), PHP_URL_HOST));
$newTab = '<span class="u-visually-hidden"> (' . e($copy['new_tab'] ?? 'opens in a new tab') . ')</span>';
// The feed link's name is the feed's title, which already ends with the words it shows ("RSS feed"), so the hidden
// part is only what comes before them, and a screen reader hears the title once.
$rss = (string) ($follow['rss'] ?? 'RSS feed');
$rssHint = str_ends_with($feedTitle, ' ' . $rss) ? substr($feedTitle, 0, -strlen($rss)) : $feedTitle . ': ';
$links = [
    ['href' => 'https://feedly.com/i/subscription/feed%2F' . rawurlencode($absolute), 'label' => (string) ($follow['feedly'] ?? 'Feedly'), 'hint' => $feedTitle . ' in '],
    ['href' => 'https://www.inoreader.com/?add_feed=' . rawurlencode($absolute), 'label' => (string) ($follow['inoreader'] ?? 'Inoreader'), 'hint' => $feedTitle . ' in '],
];
?>
<div class="news-follow">
    <p class="news-follow-label" id="news-follow-label"><?= e($follow['label'] ?? 'Follow') ?></p>
    <ul class="news-follow-list" role="list" aria-labelledby="news-follow-label">
        <li><a href="<?= e($feed) ?>" type="application/rss+xml"><?= icon('broadcast', 'c-icon--sm') ?><span class="u-visually-hidden"><?= e($rssHint) ?></span><?= e($rss) ?></a></li>
        <?php foreach ($links as $l): ?>
            <li><a href="<?= e($l['href']) ?>" target="_blank" rel="nofollow noopener"><span class="u-visually-hidden"><?= e($l['hint']) ?></span><?= e($l['label']) ?><?= icon('external', 'c-icon--sm') ?><?= $newTab ?></a></li>
        <?php endforeach; ?>
        <?php if ($domain !== ''): ?>
            <li><a href="<?= e('https://www.google.com/preferences/source?q=' . rawurlencode($domain)) ?>" target="_blank" rel="nofollow noopener"><span class="u-visually-hidden"><?= e($follow['google_hint'] ?? 'Add Patterns Show Control as a') ?> </span><?= e($follow['google'] ?? 'Google preferred source') ?><?= icon('external', 'c-icon--sm') ?><?= $newTab ?></a></li>
        <?php endif; ?>
    </ul>
</div>
