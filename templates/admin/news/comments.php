<?php
/**
 * Moderation of Industry Updates comments in four tabs (reported, pending, visible, hidden), optionally for one
 * article. Each comment shows its article, its author (full name and email: administrators only), the name readers
 * see, its state and reports, the comment itself (plain text: nl2br(e()), never Markdown) and the buttons its state
 * allows. Each button posts to the comment's own address and comes back to this tab and page.
 *
 * @var string $tab
 * @var array<string, string> $tabs
 * @var array<string, int> $counts per tab
 * @var \Psc\Support\Paginator $pager
 * @var list<array<string, mixed>> $items the page's comments, with public_name, staff and article_live
 * @var array<string, mixed>|null $article the article the list is filtered to
 * @var string $mode the desk's comments setting: open, approval or closed
 */
use Psc\Controllers\Admin\AdminController;
use Psc\Controllers\Admin\AdminNewsCommentController;
use Psc\News\Comments;

$articleId = $article !== null ? (int) $article['id'] : 0;
$time = static fn (mixed $ts): string => '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(Comments::timeLabel((int) $ts)) . '</time>';
$tabUrl = static fn (string $t): string => AdminNewsCommentController::listPath($t, 1, $articleId);
$statusBadge = [
    'visible' => ['public', 'Visible'],
    'pending' => ['pending', 'Waiting for approval'],
    'hidden' => ['private', 'Hidden'],
];
// Each state's buttons: action => [label, style].
$buttons = [
    'pending' => ['approve' => ['Approve', 'primary'], 'hide' => ['Hide', 'secondary']],
    'visible' => ['hide' => ['Hide', 'secondary']],
    'hidden' => ['show' => ['Show again', 'secondary']],
];
$empty = [
    'reported' => 'No reported comments. When a reader reports one, it is listed here and you get an email.',
    'pending' => 'Nothing is waiting for approval.',
    'visible' => 'No comments are showing on articles yet.',
    'hidden' => 'No hidden comments.',
];
$modes = [
    'open' => 'Comments appear as soon as they are posted, except a link from an account less than a day old, which waits here.',
    'approval' => 'Every new comment waits here until you approve it. Comments by administrators appear at once.',
    'closed' => 'Comments are closed on every article. The comments already posted stay as they are.',
];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Comments</h1>
    <p class="pt-lede">Comments on Industry Updates articles. <?= e($modes[$mode] ?? $modes['open']) ?> A comment reported <?= (int) Comments::HIDE_AT ?> times is hidden until you look, unless an administrator wrote it.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/news/desk/settings/">Change how comments work</a>
    </div>
</header>

<?php if ($article !== null): ?>
    <div class="pt-alert pt-alert--info" role="status">
        <p>Showing the comments on <strong><?= e($article['title']) ?></strong> only. <a href="<?= e(AdminNewsCommentController::listPath($tab)) ?>">Show the comments on every article</a></p>
    </div>
<?php endif; ?>

<nav class="pt-tabs" aria-label="Comment lists">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= e($tabUrl($key)) ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="pt-muted">(<?= (int) ($counts[$key] ?? 0) ?>)</span></a>
    <?php endforeach; ?>
</nav>

<section class="pt-section" aria-labelledby="comments-list">
    <h2 class="pt-h2" id="comments-list"><?= e($tabs[$tab] ?? 'Comments') ?> comments</h2>
    <?php if ($items === []): ?>
        <div class="pt-empty">
            <p><?= e($empty[$tab] ?? 'No comments here.') ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($items as $c):
            $id = (int) $c['id'];
            $status = (string) $c['status'];
            [$badge, $badgeLabel] = $statusBadge[$status] ?? ['closed', $status];
            $reports = (int) $c['reports'];
            $mailto = AdminController::mailto((string) $c['author_email'], 'Your comment on ' . $c['article_title']);
            $articleUrl = '/news/' . $c['article_slug'] . '/'; ?>
            <article class="pt-card" id="comment-<?= $id ?>" aria-labelledby="comment-<?= $id ?>-title">
                <h3 class="pt-card-title" id="comment-<?= $id ?>-title"><bdi><?= e($c['author_name']) ?></bdi> on <a href="/portal/admin/news/<?= (int) $c['article_id'] ?>/"><?= e($c['article_title']) ?></a></h3>
                <p class="pt-card-meta">
                    <span class="pt-badge pt-badge--<?= e($badge) ?>"><?= e($badgeLabel) ?></span>
                    <?php if ($reports > 0): ?><span class="pt-badge pt-badge--approval">Reported <?= $reports === 1 ? 'once' : e($reports) . ' times' ?></span><?php endif; ?>
                    posted <?= $time($c['created_at']) ?>
                </p>
                <dl class="pt-kv">
                    <dt>Author</dt>
                    <dd><a href="/portal/admin/users/<?= (int) $c['user_id'] ?>/"><bdi><?= e($c['author_name']) ?></bdi></a><?php if ($c['staff']): ?> <span class="pt-badge pt-badge--admin">Administrator</span><?php endif; ?></dd>
                    <dt>Email</dt>
                    <dd><?php if ($mailto !== null): ?><a href="<?= e($mailto) ?>"><?= e($c['author_email']) ?></a><?php else: ?><?= e($c['author_email']) ?><?php endif; ?></dd>
                    <dt>Readers see</dt>
                    <dd><bdi><?= e($c['public_name']) ?></bdi><?= $c['staff'] ? ', with the ' . e(Comments::STAFF_BADGE) . ' badge' : '' ?></dd>
                    <dt>Article</dt>
                    <dd><?php if ($c['article_live']): ?><a href="<?= e($articleUrl) ?>#comment-<?= $id ?>">See it on the article</a><?php else: ?><span class="pt-muted">Not published, so its comments are not shown</span><?php endif; ?></dd>
                    <dt>Reference</dt>
                    <dd class="pt-mono">#<?= $id ?></dd>
                </dl>
                <div class="pt-card-body">
                    <p><?= nl2br(e($c['body'])) ?></p>
                </div>
                <div class="pt-card-footer">
                    <form method="post" action="/portal/admin/news/comments/<?= $id ?>/" class="pt-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                        <?php if ($pager->page > 1): ?><input type="hidden" name="page" value="<?= (int) $pager->page ?>"><?php endif; ?>
                        <?php if ($articleId > 0): ?><input type="hidden" name="article" value="<?= $articleId ?>"><?php endif; ?>
                        <?php foreach ($buttons[$status] ?? [] as $action => [$label, $style]): ?>
                            <button type="submit" name="action" value="<?= e($action) ?>" class="pt-btn pt-btn--<?= e($style) ?> pt-btn--small"><?= e($label) ?><span class="u-visually-hidden"> (comment #<?= $id ?> by <?= e($c['author_name']) ?>)</span></button>
                        <?php endforeach; ?>
                        <?php if ($reports > 0): ?>
                            <button type="submit" name="action" value="clear_reports" class="pt-btn pt-btn--secondary pt-btn--small">Clear reports<span class="u-visually-hidden"> (comment #<?= $id ?>)</span></button>
                        <?php endif; ?>
                        <button type="submit" name="action" value="delete" class="pt-btn pt-btn--danger pt-btn--small">Delete<span class="u-visually-hidden"> (comment #<?= $id ?> by <?= e($c['author_name']) ?>)</span></button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        <?= $pager->links() ?>
    <?php endif; ?>
</section>
