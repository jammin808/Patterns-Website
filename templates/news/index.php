<?php
/**
 * /news/: the Industry Updates index. The hero, then the newest live articles, or the empty state when there are
 * none. The page reads the same for every visitor (no session, no form token).
 *
 * @var array<string, mixed> $copy content/news.php
 * @var list<array<string, mixed>> $articles ArticleStore::live() items
 */
$index = is_array($copy['index'] ?? null) ? $copy['index'] : [];
?>
<section class="c-hero c-hero--page" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow"><?= e($index['eyebrow'] ?? 'AV and live event news') ?></p>
                <h1 class="c-hero-title" id="page-title"><?= e($index['title'] ?? 'Industry Updates') ?></h1>
            </div>
            <div>
                <p class="c-hero-lede"><?= e($index['lede'] ?? '') ?></p>
            </div>
        </div>
    </div>
</section>

<section class="l-section" aria-labelledby="news-latest">
    <div class="l-wrap">
        <h2 id="news-latest">Latest articles</h2>
        <?php if ($articles === []): ?>
            <div class="c-empty">
                <span class="c-empty-mark" aria-hidden="true"></span>
                <p><?= e($copy['empty']['index'] ?? 'Nothing published yet.') ?></p>
            </div>
        <?php else: ?>
            <div class="l-grid l-grid--3">
                <?php foreach ($articles as $a): ?>
                    <article class="c-card">
                        <span class="c-card-meta"><time datetime="<?= e(date(DATE_ATOM, (int) $a['published_at'])) ?>"><?= e(date_short((int) $a['published_at'])) ?></time></span>
                        <h3 class="c-card-title"><a href="/news/<?= e($a['slug']) ?>/"><?= e($a['title']) ?></a></h3>
                        <p class="c-card-body"><?= e($a['dek']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
