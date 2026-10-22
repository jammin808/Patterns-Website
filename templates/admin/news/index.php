<?php
/**
 * Every Industry Updates article in five tabs (all, live, scheduled, drafts, and held: the AI desk's drafts waiting
 * for a person), filtered by category, origin and words in the headline or standfirst, the newest first (the
 * scheduled ones soonest first, drafts by their last change).
 *
 * @var string $tab
 * @var array{category: string, origin: string, q: string} $filters
 * @var bool $filtered
 * @var array<string, int> $counts per tab, with the filters applied
 * @var \Psc\Support\Paginator $pager
 * @var int $now
 * @var array<string, array<string, string>> $categories
 */
use Psc\Controllers\Admin\AdminNewsController;

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/admin-news.css')) . '">');
$tabUrl = static function (string $t) use ($filters): string {
    $q = array_filter(['tab' => $t !== 'all' ? $t : ''] + $filters, static fn (string $v): bool => $v !== '');
    return '/portal/admin/news/' . ($q !== [] ? '?' . http_build_query($q) : '');
};
$date = static fn (mixed $ts, bool $clock = false): string => '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e($clock ? date('j M Y, H:i', (int) $ts) : date_short((int) $ts)) . '</time>';
$tabHelp = [
    'held' => 'Drafts the AI desk wrote that wait for you: check each one, then publish it or delete it.',
    'scheduled' => 'Published with a later time: each goes live by itself, the soonest first.',
    'drafts' => 'Only administrators can see drafts, the most recently changed first.',
];
$empty = [
    'all' => 'No articles yet. Write the first one, or wait for the AI desk\'s next session.',
    'live' => 'Nothing is live yet.',
    'scheduled' => 'Nothing is scheduled.',
    'drafts' => 'No drafts.',
    'held' => 'Nothing is waiting for review.',
];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Industry Updates</h1>
    <p class="pt-lede">Every news article, drafts and the AI desk's work included. Readers see an article once it is live.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--primary" href="/portal/admin/news/new/">Write an article</a>
        <a class="pt-btn pt-btn--secondary" href="/portal/admin/news/comments/">Moderate comments</a>
        <a class="pt-btn pt-btn--secondary" href="/portal/admin/news/desk/">Open the AI desk</a>
    </div>
</header>

<nav class="pt-tabs" aria-label="Article state">
    <?php foreach (AdminNewsController::TABS as $t => $label): ?>
        <a href="<?= e($tabUrl($t)) ?>"<?= $t === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="pt-muted">(<?= (int) ($counts[$t] ?? 0) ?>)</span></a>
    <?php endforeach; ?>
</nav>
<?php if (isset($tabHelp[$tab])): ?><p class="pt-small pt-muted"><?= e($tabHelp[$tab]) ?></p><?php endif; ?>

<form class="pt-search" method="get" action="/portal/admin/news/" role="search" aria-label="Search articles">
    <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
    <div class="pt-field">
        <label for="news-q">Search</label>
        <input type="search" id="news-q" name="q" value="<?= e($filters['q']) ?>" maxlength="100" autocomplete="off" aria-describedby="news-q-help">
        <p class="pt-help" id="news-q-help">Words in the headline or the standfirst.</p>
    </div>
    <div class="pt-field">
        <label for="news-category">Category</label>
        <select id="news-category" name="category">
            <option value="">Any category</option>
            <?php foreach ($categories as $key => $c): ?>
                <option value="<?= e($key) ?>"<?= $key === $filters['category'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="pt-field">
        <label for="news-origin">Origin</label>
        <select id="news-origin" name="origin">
            <option value="">Any origin</option>
            <?php foreach (AdminNewsController::ORIGINS as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $key === $filters['origin'] ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Search</button>
        <?php if ($filtered): ?><a class="pt-btn pt-btn--ghost pt-btn--small" href="<?= e($tab !== 'all' ? '/portal/admin/news/?tab=' . $tab : '/portal/admin/news/') ?>">Clear</a><?php endif; ?>
    </div>
</form>

<?php if ($pager->items === []): ?>
    <div class="pt-empty">
        <?php if ($filtered): ?>
            <p>No articles match this search.</p>
            <a class="pt-btn pt-btn--secondary" href="/portal/admin/news/">Show every article</a>
        <?php else: ?>
            <p><?= e($empty[$tab]) ?></p>
            <?php if ($tab === 'all' || $tab === 'drafts'): ?><a class="pt-btn pt-btn--primary" href="/portal/admin/news/new/">Write an article</a><?php endif; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table pt-table--stack">
            <caption class="u-visually-hidden">Articles: <?= e(AdminNewsController::TABS[$tab]) ?> (<?= (int) $pager->total ?>)</caption>
            <thead>
                <tr>
                    <th scope="col">Headline</th>
                    <th scope="col">Status</th>
                    <th scope="col">Origin</th>
                    <th scope="col">Published</th>
                    <th scope="col" class="pt-num">Comments</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pager->items as $a):
                    $state = AdminNewsController::state($a, $now);
                    [$badge, $label] = AdminNewsController::STATES[$state];
                    $category = (string) ($a['category'] ?? '');
                    $reason = trim((string) ($a['review_reason'] ?? '')); ?>
                    <tr>
                        <th scope="row">
                            <a href="/portal/admin/news/<?= (int) $a['id'] ?>/"><?= e($a['title']) ?></a>
                            <span class="pt-small pt-muted"><?= $category !== '' ? e($categories[$category]['name'] ?? $category) . ' · ' : '' ?><?= e(\Psc\News\Paths::article($a)) ?></span>
                            <?php if ($state === 'held' && $reason !== ''): ?><span class="pt-small">Held because: <?= e($reason) ?></span><?php endif; ?>
                        </th>
                        <td data-label="Status"><span class="pt-badge pt-badge--<?= e($badge) ?>"><?= e($label) ?></span></td>
                        <td data-label="Origin"><?= e(AdminNewsController::ORIGINS[(string) $a['origin']] ?? $a['origin']) ?></td>
                        <td data-label="Published">
                            <?php if ($state === 'live'): ?>
                                <?= $date($a['published_at']) ?>
                            <?php elseif ($state === 'scheduled'): ?>
                                Goes live <?= $date($a['published_at'], true) ?>
                            <?php else: ?>
                                <span class="pt-muted">Not live</span>
                            <?php endif; ?>
                        </td>
                        <td class="pt-num" data-label="Comments"><?= (int) $a['comment_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->links() ?>
<?php endif; ?>
