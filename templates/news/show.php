<?php
/**
 * /news/{category}/{slug}/: one article, in the order docs/NEWS.md §10 sets. The hero holds the breadcrumbs (put
 * there by the layout), the category tags (each its hub's link), the headline, the standfirst and the meta line; then
 * the corrections (dated, when it has any), the follow-up banner, the lead image or the category's artwork, the
 * body, what is confirmed and what is still speculation (each fact with its badge and its source), what it means, the
 * verdict, the other images, the numbered sources, the later updates, the note on AI-written articles (with the way
 * to the editorial standards), the share row, the newest of its category (More in) and the comments. Every source
 * link is real (rel nofollow, a new tab) and carries data-news-link, which news.js counts. Every link to another
 * article is that article's own address. The page is the same for every visitor; the administrators' preview adds a
 * banner and leaves out the counting, the share row and the comments.
 *
 * @var array<string, mixed> $copy  content/news.php
 * @var array<string, mixed> $article  ArticleStore::findBySlug() or find()
 * @var bool $preview
 * @var bool $live
 * @var array{name: string}|null $primary  the primary category
 * @var string $primaryKey  its key ('' when it has none)
 * @var array<string, array{name: string}> $categories
 * @var string $byline
 * @var int|null $published
 * @var int|null $updated  set when the article changed after it went live, or has a correction: $modified
 * @var int $modified  its last change that counts (ArticleStore::lastChange()), else the time it went live
 * @var int $readMinutes
 * @var string $body  rendered Markdown
 * @var string $impact  rendered Markdown, its headings one level down
 * @var list<array{html: string, url: string, link: int|null, label: string}> $confirmed
 * @var list<array{html: string, url: string, link: int|null, label: string}> $speculation
 * @var list<array<string, mixed>> $sources  news_links rows
 * @var array{earlier: list<array<string, mixed>>, later: list<array<string, mixed>>} $related
 * @var list<array<string, mixed>> $more  the newest other live articles of its primary category
 * @var array<string, mixed>|null $lead  NewsController::picture()
 * @var list<array<string, mixed>> $images  the other pictures
 * @var string $aiNote
 * @var list<array{at: int, text: string}> $corrections  the dated corrections, the oldest first
 * @var list<array<string, mixed>> $comments
 * @var list<array{0: string, 1: string}> $feeds  the RSS feeds the page names: address, title
 * @var \Psc\View\Seo $seo
 */
use Psc\News\Paths;

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/news.css')) . '">');
foreach ($feeds as [$feedHref, $feedTitle]) {
    $this->push('head', '<link rel="alternate" type="application/rss+xml" title="' . e($feedTitle) . '" href="' . e($feedHref) . '">');
}
if ($published !== null) {
    $this->push('head', '<meta property="article:published_time" content="' . e(date(DATE_ATOM, $published)) . '">');
    $this->push('head', '<meta property="article:modified_time" content="' . e(date(DATE_ATOM, $modified)) . '">');
}
if ($primary !== null) {
    $this->push('head', '<meta property="article:section" content="' . e($primary['name']) . '">');
}
foreach ($article['tag_list'] as $tag) {
    $this->push('head', '<meta property="article:tag" content="' . e($tag) . '">');
}
if (!$preview) {
    $this->push('scripts', '<script type="module" src="' . e(asset('/assets/js/news.js')) . '"></script>');
}

$sections = is_array($copy['sections'] ?? null) ? $copy['sections'] : [];
$meta = is_array($copy['meta'] ?? null) ? $copy['meta'] : [];
$badges = is_array($copy['badges'] ?? null) ? $copy['badges'] : [];
$kinds = is_array($copy['kinds'] ?? null) ? $copy['kinds'] : [];
$newTab = '<span class="u-visually-hidden"> (' . e($copy['new_tab'] ?? 'opens in a new tab') . ')</span>';
$when = static fn (int $ts): string => '<time datetime="' . e(date(DATE_ATOM, $ts)) . '">' . e(date('j F Y, H:i', $ts)) . '</time>';
$verdict = $copy['verdicts'][(string) ($article['verdict'] ?? '')] ?? null;
// A source link names its source; the source list's links name their pages.
$out = static fn (string $url, ?int $id, string $label): string => '<a href="' . e($url) . '" rel="nofollow noopener" target="_blank"' . ($id !== null ? ' data-news-link="' . $id . '"' : '') . '>' . e($label) . $newTab . '</a>';
// Tell us (in the editable note) is the way to the contact page, with the topic a correction comes under chosen.
$note = (string) preg_replace('/\bTell us\b/', '<a href="/contact/?topic=other">Tell us</a>', e($aiNote), 1);
?>
<section class="c-hero c-hero--page news-story-hero" aria-labelledby="story-title">
    <div class="l-wrap l-wrap--prose">
        <div class="c-hero-grid">
            <?php if ($preview): $previewCopy = is_array($copy['preview'] ?? null) ? $copy['preview'] : []; ?>
                <div class="c-callout c-callout--warning news-preview" role="note">
                    <?= icon('eye') ?>
                    <strong class="c-callout-title"><?= e($live ? ($previewCopy['live'] ?? 'Preview: this article is live') : ($previewCopy['title'] ?? 'Preview: not published')) ?></strong>
                    <?php if (!$live): ?>
                        <p><?= ($article['status'] ?? '') === 'published' && $published !== null
                            ? strtr(e($previewCopy['scheduled'] ?? 'Scheduled to go live :time.'), [':time' => $when($published)])
                            : e($previewCopy['draft'] ?? '') ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($article['categories'] !== []): ?>
                <ul class="news-tags" role="list" aria-label="<?= e($copy['categories_label'] ?? 'Categories') ?>">
                    <?php foreach ($article['categories'] as $key): ?>
                        <li><a class="c-tag" href="<?= e(Paths::category($key)) ?>"><?= e($categories[$key]['name'] ?? $key) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <h1 class="c-hero-title news-story-title" id="story-title"><?= e($article['title']) ?></h1>
            <p class="c-hero-lede news-story-dek"><?= e($article['dek']) ?></p>
            <p class="news-meta">
                <?php if ($published !== null): ?><span><?= $when($published) ?></span><?php else: ?><span><?= e($meta['unpublished'] ?? 'Not published yet') ?></span><?php endif; ?>
                <span><?= e(strtr((string) ($meta['read'] ?? ':n min read'), [':n' => (string) $readMinutes])) ?></span>
                <?php if ($updated !== null): ?><span><?= e($meta['updated'] ?? 'Updated') ?> <?= $when($updated) ?></span><?php endif; ?>
                <span><?= e($meta['by'] ?? 'By') ?> <?= e($byline) ?></span>
            </p>
        </div>
    </div>
</section>

<div class="l-section l-section--flush news-story">
    <div class="l-wrap l-wrap--prose news-story-wrap">
        <?php if ($corrections !== []): $fix = is_array($copy['corrections'] ?? null) ? $copy['corrections'] : []; ?>
            <div class="c-callout c-callout--info news-corrections" id="corrections" role="note">
                <?= icon('edit') ?>
                <strong class="c-callout-title"><?= e(count($corrections) === 1 ? ($fix['one'] ?? 'Correction') : ($fix['many'] ?? 'Corrections')) ?></strong>
                <ul class="news-corrections-list" role="list">
                    <?php foreach ($corrections as $c): ?>
                        <li><time datetime="<?= e(date(DATE_ATOM, (int) $c['at'])) ?>"><?= e(date('j F Y', (int) $c['at'])) ?></time>: <?= e($c['text']) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p><a href="<?= e(Paths::standards()) ?>#corrections"><?= e($fix['policy'] ?? 'How we correct articles') ?></a></p>
            </div>
        <?php endif; ?>

        <?php if ($related['earlier'] !== []): ?>
            <div class="c-callout c-callout--info news-followup" role="note">
                <?= icon('link') ?>
                <strong class="c-callout-title"><?= e($sections['follow_up'] ?? 'Follow-up to') ?>:</strong>
                <ul>
                    <?php foreach ($related['earlier'] as $r): ?>
                        <li><a href="<?= e(Paths::article($r)) ?>"><?= e($r['title']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($lead !== null): ?>
            <?= $this->partial('news/figure', ['picture' => $lead, 'copy' => $copy, 'eager' => true]) ?>
        <?php else: ?>
            <div class="news-lead-art"><?= $this->partial('news/art', ['key' => $primaryKey, 'wide' => true]) ?></div>
        <?php endif; ?>

        <div class="c-prose news-body"><?= $body ?></div>

        <?php if ($confirmed !== []): ?>
            <section class="news-part" aria-labelledby="news-confirmed">
                <h2 id="news-confirmed"><?= e($sections['confirmed'] ?? "What's confirmed") ?></h2>
                <ul class="news-facts" role="list">
                    <?php foreach ($confirmed as $f): ?>
                        <li class="news-fact">
                            <span class="c-badge c-badge--done"><?= e($badges['confirmed'] ?? 'Confirmed') ?></span>
                            <p><?= $f['html'] ?></p>
                            <?php if ($f['url'] !== ''): ?><p class="news-fact-source"><?= e($meta['source'] ?? 'Source') ?>: <?= $out($f['url'], $f['link'], $f['label']) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($speculation !== []): ?>
            <section class="news-part" aria-labelledby="news-speculation">
                <h2 id="news-speculation"><?= e($sections['speculation'] ?? 'Still speculation') ?></h2>
                <ul class="news-facts" role="list">
                    <?php foreach ($speculation as $f): ?>
                        <li class="news-fact">
                            <span class="c-badge c-badge--progress"><?= e($badges['unconfirmed'] ?? 'Unconfirmed') ?></span>
                            <p><?= $f['html'] ?></p>
                            <?php if ($f['url'] !== ''): ?><p class="news-fact-source"><?= e($meta['source'] ?? 'Source') ?>: <?= $out($f['url'], $f['link'], $f['label']) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (trim(strip_tags($impact)) !== ''): ?>
            <section class="news-part" aria-labelledby="news-impact">
                <h2 id="news-impact"><?= e($sections['impact'] ?? 'What it means for freelancers and corporate events') ?></h2>
                <div class="c-prose"><?= $impact ?></div>
            </section>
        <?php endif; ?>

        <?php if (is_array($verdict)): ?>
            <div class="c-callout<?= $verdict['callout'] !== '' ? ' ' . e($verdict['callout']) : '' ?> news-verdict" role="note">
                <?= icon((string) $verdict['icon']) ?>
                <h2 class="c-callout-title"><span class="u-visually-hidden">Our verdict: </span><?= e($verdict['label']) ?></h2>
                <?php if (trim((string) ($article['verdict_note'] ?? '')) !== ''): ?><p><?= e($article['verdict_note']) ?></p><?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($images !== []): ?>
            <div class="news-gallery">
                <?php foreach ($images as $picture): ?>
                    <?= $this->partial('news/figure', ['picture' => $picture, 'copy' => $copy]) ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($sources !== []): ?>
            <section class="news-part" aria-labelledby="news-sources">
                <h2 id="news-sources"><?= e($sections['sources'] ?? 'Sources') ?></h2>
                <ol class="news-sources">
                    <?php foreach ($sources as $s): ?>
                        <li>
                            <?= $out((string) $s['url'], (int) $s['id'], (string) (($s['title'] ?? '') ?: $s['host'])) ?>
                            <span class="news-source-meta">
                                <?php if (($s['publisher'] ?? '') !== '' && $s['publisher'] !== null): ?><span><?= e($s['publisher']) ?></span><?php endif; ?>
                                <span><?= e($s['host']) ?></span>
                                <span><?= e($kinds[(string) $s['kind']] ?? $kinds['other'] ?? 'Other') ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>

        <?php if ($related['later'] !== []): ?>
            <section class="news-part" aria-labelledby="news-later">
                <h2 id="news-later"><?= e($sections['later'] ?? 'Later updates') ?></h2>
                <ul class="news-later" role="list">
                    <?php foreach ($related['later'] as $r): ?>
                        <li><a href="<?= e(Paths::article($r)) ?>"><?= e($r['title']) ?></a><?php if ($r['published_at'] !== null): ?> <time datetime="<?= e(date(DATE_ATOM, (int) $r['published_at'])) ?>"><?= e(date_short((int) $r['published_at'])) ?></time><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($aiNote !== ''): ?>
            <div class="c-callout news-ainote" role="note">
                <?= icon('sparkles') ?>
                <p><?= $note ?></p>
                <p><a href="<?= e(Paths::standards()) ?>"><?= e($copy['standards_link'] ?? 'How Industry Updates is made') ?></a></p>
            </div>
        <?php endif; ?>

        <?php if (!$preview): ?>
            <?= $this->partial('news/share', ['copy' => $copy, 'url' => $seo->canonical(), 'title' => (string) $article['title']]) ?>
        <?php endif; ?>

        <?php if ($primary !== null): ?>
            <section class="news-part news-more" aria-labelledby="news-more">
                <h2 id="news-more"><?= e(strtr((string) ($sections['more'] ?? 'More in :category'), [':category' => $primary['name']])) ?></h2>
                <?php if ($more !== []): ?>
                    <ul class="news-more-list" role="list">
                        <?php foreach ($more as $m): ?>
                            <li>
                                <a href="<?= e(Paths::article($m)) ?>"><?= e($m['title']) ?></a>
                                <span class="news-more-meta"><time datetime="<?= e(date(DATE_ATOM, (int) $m['published_at'])) ?>"><?= e(date_short((int) $m['published_at'])) ?></time><span><?= e(strtr((string) ($meta['read'] ?? ':n min read'), [':n' => (string) \Psc\News\Prose::readMinutes((int) $m['word_count'])])) ?></span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="news-more-all"><a class="c-btn c-btn--secondary" href="<?= e(Paths::category($primaryKey)) ?>"><?= e(strtr((string) ($sections['more_all'] ?? 'All :category news'), [':category' => $primary['name']])) ?></a></p>
            </section>
        <?php endif; ?>

        <?php if ($preview): ?>
            <p class="news-preview-comments"><?= e($copy['preview']['comments'] ?? '') ?></p>
        <?php else: ?>
            <?= $this->partial('news/comments', ['article' => $article, 'comments' => $comments]) ?>
        <?php endif; ?>
    </div>
</div>
