<?php
/**
 * One weekly digest (docs/NEWS.md §14.12): its state and note, its week, window and times, its articles (one taken
 * down since is marked), its sends counted by outcome, and who it was sent to, 50 a page. The addresses are shown to
 * administrators only, as the comments' moderation shows emails; one unsubscribed since shows none.
 *
 * @var array<string, mixed> $digest a news_digests row (articles decoded)
 * @var string $monday "19 October 2026"
 * @var list<array{id: int, title: ?string, live: bool}> $articles
 * @var array{claimed: int, sent: int, retry: int, failed: int, bounced: int} $counts
 * @var int $left recipients still to send it to, while it is sending
 * @var int $waiting its retries that will be tried again, while it is sending
 * @var int $unsent for a stopped digest, the recipients it never reached
 * @var array{items: list<array<string, mixed>>, total: int, page: int, pages: int} $sends
 * @var string $base this page's address
 */
use Psc\Controllers\Admin\AdminDigestController;
use Psc\News\Digest\Week;

$n = static fn (int $v): string => e(number_format($v));
$at = static fn (mixed $ts): string => $ts !== null && (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(date('j M Y, H:i', (int) $ts)) . '</time>'
    : '<span class="pt-muted">Not yet</span>';
$day = static fn (int $ts): string => e(date('l j F Y, H:i', $ts));
[$badge, $word] = AdminDigestController::DIGEST_STATES[$digest['state']] ?? ['closed', ucfirst((string) $digest['state'])];
$page = (int) $sends['page'];
$pages = (int) $sends['pages'];
$pageUrl = static fn (int $p): string => $base . ($p > 1 ? '?page=' . $p : '');
?>
<header class="pt-header">
    <p class="pt-eyebrow">Weekly digest</p>
    <h1 class="pt-title">Digest for the week of <?= e($monday) ?></h1>
    <p class="pt-lede"><span class="pt-badge pt-badge--<?= e($badge) ?>"><?= e($word) ?></span> <?= e((string) ($digest['note'] ?? '')) ?></p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="<?= e(AdminDigestController::BASE) ?>">Back to the digest</a>
    </div>
</header>

<?= $this->render('admin/news/desk/_nav', ['current' => 'digest-show']) ?>

<section class="pt-section" aria-labelledby="digest-facts">
    <h2 class="pt-h2" id="digest-facts">The digest</h2>
    <dl class="pt-kv">
        <dt>Week</dt>
        <dd><span class="pt-mono"><?= e((string) $digest['week']) ?></span> <span class="pt-muted">(also its campaign tag)</span></dd>
        <dt>Sent from</dt>
        <dd><?= e(Week::label((int) $digest['send_at'])) ?></dd>
        <dt>Articles from</dt>
        <dd><?= $day((int) $digest['window_start']) ?> to <?= $day((int) $digest['window_end']) ?></dd>
        <dt>Recipients when composed</dt>
        <dd><?= $n((int) $digest['recipients']) ?></dd>
        <dt>Composed</dt>
        <dd><?= $at($digest['composed_at']) ?></dd>
        <dt>Finished</dt>
        <dd><?= $at($digest['finished_at']) ?></dd>
    </dl>
</section>

<section class="pt-section" aria-labelledby="digest-articles">
    <h2 class="pt-h2" id="digest-articles">Its articles</h2>
    <?php if ($articles === []): ?>
        <div class="pt-empty"><p><?= $digest['state'] === 'skipped' ? 'None: the week was skipped.' : 'None went live in its window.' ?></p></div>
    <?php else: ?>
        <ol class="pt-desk-next">
            <?php foreach ($articles as $a): ?>
                <li>
                    <?php if ($a['title'] !== null): ?>
                        <a href="/portal/admin/news/<?= (int) $a['id'] ?>/"><?= e($a['title']) ?></a>
                    <?php else: ?>
                        <span class="pt-muted">Article <?= (int) $a['id'] ?>, since deleted</span>
                    <?php endif; ?>
                    <?php if (!$a['live']): ?><span class="pt-badge pt-badge--declined">Taken down since: left out of the messages sent after that</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ((int) $digest['left_out'] > 0): ?><p class="pt-small">and <?= $n((int) $digest['left_out']) ?> more, counted in the email</p><?php endif; ?>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="digest-sends">
    <h2 class="pt-h2" id="digest-sends">Its sends</h2>
    <div class="pt-stats">
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['sent']) ?></span> <span class="pt-stat-label">Sent</span></div>
        <?php if ($digest['state'] === 'stopped'): ?>
            <div class="pt-stat"><span class="pt-stat-value"><?= $n($unsent) ?></span> <span class="pt-stat-label"><?= e(AdminDigestController::NOT_SENT) ?></span></div>
        <?php else: ?>
            <div class="pt-stat"><span class="pt-stat-value"><?= $n($left) ?></span> <span class="pt-stat-label">Still to send</span></div>
            <div class="pt-stat"><span class="pt-stat-value"><?= $n($waiting) ?></span> <span class="pt-stat-label">Waiting to retry</span></div>
        <?php endif; ?>
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['bounced']) ?></span> <span class="pt-stat-label">Bounced</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['failed']) ?></span> <span class="pt-stat-label">Failed</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= $n($counts['claimed']) ?></span> <span class="pt-stat-label">Unknown (the tick stopped while sending)</span></div>
    </div>
    <?php if ($sends['items'] === []): ?>
        <div class="pt-empty"><p>It has not been sent to anyone.</p></div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption>Who it was sent to, 50 a page</caption>
                <thead>
                    <tr>
                        <th scope="col">Address</th>
                        <th scope="col">State</th>
                        <th scope="col">When</th>
                        <th scope="col">Server's answer</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sends['items'] as $s): ?>
                        <tr>
                            <th scope="row"><?= $s['email'] !== null ? '<bdi>' . e($s['email']) . '</bdi>' : '<span class="pt-muted">Unsubscribed since</span>' ?></th>
                            <td data-label="State"><?= e(AdminDigestController::sendState((string) $s['state'], (string) $digest['state'], (bool) $s['due'])) ?><?= (int) $s['attempts'] > 1 ? ' <span class="pt-muted">(' . (int) $s['attempts'] . ' attempts)</span>' : '' ?></td>
                            <td data-label="When"><?= $at($s['sent_at'] ?? $s['claimed_at']) ?></td>
                            <td data-label="Server's answer"><?= $s['reply'] !== null ? '<span class="pt-mono">' . e($s['reply']) . '</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pt-pager" aria-label="Pages">
                <?php if ($page > 1): ?><a rel="prev" href="<?= e($pageUrl($page - 1)) ?>">Previous</a><?php endif; ?>
                <span class="pt-pager-info">Page <?= $page ?> of <?= $pages ?></span>
                <?php if ($page < $pages): ?><a rel="next" href="<?= e($pageUrl($page + 1)) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
