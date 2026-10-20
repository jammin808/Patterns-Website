<?php
/**
 * Every release, drafts included, with counts.
 *
 * @var list<array<string, mixed>> $releases
 * @var array{total: int, published: int, drafts: int, downloads: int} $counts
 * @var array<string, string> $products
 * @var array<string, string> $channels
 * @var array<string, string> $access
 */
$time = fn (mixed $ts): string => $this->render('portal/licences/_time', ['ts' => $ts]);
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Releases</h1>
    <p class="pt-lede">Every release, drafts included. Clients see a release in Downloads once it is published.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--primary" href="/portal/admin/releases/new/">Add a release</a>
        <a class="pt-btn pt-btn--secondary" href="/portal/downloads/">View the downloads page</a>
    </div>
</header>

<div class="pt-stats">
    <div class="pt-stat"><span class="pt-stat-value"><?= (int) $counts['published'] ?></span> <span class="pt-stat-label">Published</span></div>
    <div class="pt-stat"><span class="pt-stat-value"><?= (int) $counts['drafts'] ?></span> <span class="pt-stat-label">Drafts</span></div>
    <div class="pt-stat"><span class="pt-stat-value"><?= (int) $counts['downloads'] ?></span> <span class="pt-stat-label">Downloads in all</span></div>
</div>

<?php if ($releases === []): ?>
    <div class="pt-empty">
        <p>No releases yet. Add the first one, and clients can download it once you publish it.</p>
        <a class="pt-btn pt-btn--primary" href="/portal/admin/releases/new/">Add a release</a>
    </div>
<?php else: ?>
    <div class="pt-table-wrap">
        <table class="pt-table">
            <caption class="u-visually-hidden">Releases, the newest first (<?= (int) $counts['total'] ?> in all)</caption>
            <thead>
                <tr>
                    <th scope="col">Release</th>
                    <th scope="col">Channel</th>
                    <th scope="col">Status</th>
                    <th scope="col">Access</th>
                    <th scope="col" class="pt-num">Size</th>
                    <th scope="col" class="pt-num">Downloads</th>
                    <th scope="col">Published</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($releases as $r):
                    $channel = (string) $r['channel'];
                    $published = (int) $r['is_published'] === 1; ?>
                    <tr>
                        <th scope="row">
                            <a href="/portal/admin/releases/<?= (int) $r['id'] ?>/"><?= e(($products[(string) $r['product']] ?? $r['product']) . ' ' . $r['version']) ?></a>
                            <span class="pt-small pt-muted"><?= e($r['title']) ?></span>
                        </th>
                        <td><span class="pt-badge pt-badge--<?= e($channel) ?>"><?= e($channels[$channel] ?? $channel) ?></span></td>
                        <td><span class="pt-badge pt-badge--<?= $published ? 'public' : 'private' ?>"><?= $published ? 'Published' : 'Draft' ?></span></td>
                        <td><?= e($access[(string) $r['access']] ?? $r['access']) ?></td>
                        <td class="pt-num"><?= e(filesize_human((int) $r['file_size'])) ?></td>
                        <td class="pt-num"><?= (int) $r['download_count'] ?></td>
                        <td><?= $published ? $time($r['published_at']) : '<span class="pt-muted">Not yet</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
