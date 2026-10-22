<?php
/**
 * /news/{category}/{slug}/comment/ (Paths::comment()): one article's comments for a signed-in reader without scripting, and where signing in from
 * an article leads. The comment form, the visible comments with the person's own pending and hidden ones (each of
 * theirs with a delete form, everyone else's with a report form), and a link back to the article. On an article that
 * is not live only the person's own comments, each with its delete form, without the article's title or a link to it.
 * Every form posts to this page and comes back here with a 303, so its answer shows; the cached article page never
 * could.
 *
 * @var array<string, mixed> $article
 * @var bool $live whether the article is live
 * @var list<array<string, mixed>> $comments visible ones and the viewer's pending and hidden ones (only the viewer's
 *   own when the article is not live), the oldest first
 * @var array<string, mixed> $viewer the signed-in account
 * @var string $name the viewer's public name
 * @var bool $staff whether the viewer is an administrator
 * @var string|null $closed why the article takes no new comments
 * @var array<string, string> $old
 * @var array<string, string> $errors
 * @var int $idleSeconds security.session_idle_seconds
 * @var list<array{type: string, message: string}> $flashes
 */
use Psc\News\Comments;
use Psc\News\Paths;

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/news-comments.css')) . '">');
$articlePath = Paths::article($article);
$pagePath = Paths::comment($article);
$uid = (int) $viewer['id'];
$hours = intdiv($idleSeconds, 3600);
$idle = $hours >= 1 && $idleSeconds % 3600 === 0
    ? $hours . ' ' . ($hours === 1 ? 'hour' : 'hours')
    : max(1, intdiv($idleSeconds, 60)) . ' minutes';
$body = (string) ($old['body'] ?? '');
$bodyError = $errors['body'] ?? null;
$described = 'comment-body-help comment-body-count' . ($bodyError !== null ? ' comment-body-error' : '');
?>
<section class="c-hero c-hero--page" aria-labelledby="comment-title">
    <div class="l-wrap l-wrap--prose">
        <div class="c-hero-grid">
            <p class="c-eyebrow">Industry Updates</p>
            <h1 class="c-hero-title" id="comment-title">Comments</h1>
            <?php if ($live): ?>
                <p class="c-hero-lede">On <a href="<?= e($articlePath) ?>"><?= e($article['title']) ?></a></p>
            <?php else: ?>
                <p class="c-hero-lede">Yours, on an article that is not published at the moment</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="l-section l-section--flush news-comment-page">
    <div class="l-wrap l-wrap--prose">
        <section class="news-comments" id="comments" aria-labelledby="comments-title">
            <h2 id="comments-title"><?= $live ? 'What people said' : 'Your comments' ?></h2>
            <?php if ($comments === []): ?>
                <p class="news-comments-empty">No comments yet.</p>
            <?php else: ?>
                <ol class="news-comments-list">
                    <?php foreach ($comments as $c):
                        $cid = (int) $c['id'];
                        $own = (int) $c['user_id'] === $uid;
                        $pending = $c['status'] === 'pending';
                        $hidden = $c['status'] === 'hidden';
                        $when = Comments::timeLabel((int) $c['created_at']);
                        $classes = 'news-comment' . (!empty($c['staff']) ? ' news-comment--staff' : '') . ($pending ? ' news-comment--pending' : '') . ($hidden ? ' news-comment--hidden' : ''); ?>
                        <li class="<?= e($classes) ?>" id="comment-<?= $cid ?>">
                            <p class="news-comment-meta">
                                <span class="news-comment-author"><bdi><?= e($own ? 'You' : $c['name']) ?></bdi></span>
                                <?php if (!empty($c['staff'])): ?><span class="c-tag news-comment-badge"><?= e(Comments::STAFF_BADGE) ?></span><?php endif; ?>
                                <time datetime="<?= e(date(DATE_ATOM, (int) $c['created_at'])) ?>"><?= e($when) ?></time>
                            </p>
                            <?php if ($pending): ?>
                                <p class="news-comment-note"><?= icon('clock') ?><span><?= e(Comments::PENDING_NOTE) ?></span></p>
                            <?php elseif ($hidden): ?>
                                <p class="news-comment-note"><?= icon('shield') ?><span><?= e(Comments::HIDDEN_NOTE) ?></span></p>
                            <?php endif; ?>
                            <p class="news-comment-body"><?= nl2br(e($c['body'])) ?></p>
                            <?php if ($own): ?>
                                <form method="post" action="<?= e($pagePath) ?>" class="news-comment-actions">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="comment" value="<?= $cid ?>">
                                    <button type="submit" class="c-btn c-btn--secondary c-btn--sm" data-busy="Deleting…">Delete<span class="u-visually-hidden"> your comment from <?= e($when) ?></span></button>
                                </form>
                            <?php elseif ($staff): ?>
                                <p class="news-comment-actions"><a href="/portal/admin/news/comments/?tab=visible&amp;article=<?= (int) $article['id'] ?>#comment-<?= $cid ?>">Moderate<span class="u-visually-hidden"> the comment by <?= e($c['name']) ?></span></a></p>
                            <?php elseif (!$pending && !$hidden): ?>
                                <form method="post" action="<?= e($pagePath) ?>" class="news-comment-actions">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="report">
                                    <input type="hidden" name="comment" value="<?= $cid ?>">
                                    <button type="submit" class="c-btn c-btn--ghost c-btn--sm" data-busy="Reporting…">Report<span class="u-visually-hidden"> the comment by <?= e($c['name']) ?> from <?= e($when) ?></span></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <section class="news-comments-box" id="comment-form" aria-labelledby="comment-form-title">
            <h2 id="comment-form-title">Add a comment</h2>
            <?php if ($closed !== null): ?>
                <p class="news-comments-closed"><?= icon('lock') ?><span><?= e($closed) ?></span></p>
            <?php else: ?>
                <form method="post" action="<?= e($pagePath) ?>" class="c-form news-comments-form" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="post">
                    <?= $this->partial('error-summary', [
                        'errors' => $errors,
                        'fields' => ['body' => 'comment-body'],
                        'flashes' => $flashes ?? [],
                        'own' => ($old['action'] ?? '') === 'post',
                        'id' => 'comment-errors',
                        'action' => 'before posting',
                        'failed' => 'Your comment was not posted',
                    ]) ?>
                    <div class="c-field<?= $bodyError !== null ? ' is-invalid' : '' ?>">
                        <label for="comment-body">Your comment</label>
                        <p class="c-help" id="comment-body-help">Everyone can read it, shown as <strong><bdi><?= e($name) ?></bdi></strong><?= $staff ? ' with the ' . e(Comments::STAFF_BADGE) . ' badge' : '' ?>, with the time. Plain text up to <?= e(number_format(Comments::MAX_LENGTH)) ?> characters, at most <?= (int) Comments::MAX_LINKS ?> links and no email addresses.</p>
                        <textarea id="comment-body" name="body" rows="5" maxlength="<?= (int) Comments::MAX_LENGTH ?>" required aria-describedby="<?= e($described) ?>" data-count="#comment-body-count"<?= $bodyError !== null ? ' aria-invalid="true"' : '' ?>><?= e($body) ?></textarea>
                        <p class="c-count" id="comment-body-count"><?= mb_strlen($body) ?> / <?= (int) Comments::MAX_LENGTH ?></p>
                        <?php if ($bodyError !== null): ?>
                            <p class="c-error-msg" id="comment-body-error"><?= e($bodyError) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="news-comments-submit">
                        <button type="submit" class="c-btn c-btn--primary" data-busy="Posting…">Post comment</button>
                        <p class="news-comments-rules">Comments follow the <a href="/legal/terms/">site terms</a>. You can delete your own at any time.</p>
                    </div>
                </form>
                <p class="news-comments-note">Sessions end after <?= e($idle) ?> without activity. If you are writing something long, copy it somewhere safe before you take a break.</p>
            <?php endif; ?>
        </section>

        <?php if ($live): ?>
            <p class="news-comment-back"><a class="c-btn c-btn--secondary" href="<?= e($articlePath) ?>#comments">Back to the article</a></p>
        <?php else: ?>
            <p class="news-comment-back"><a class="c-btn c-btn--secondary" href="<?= e(Paths::index()) ?>">Back to Industry Updates</a></p>
        <?php endif; ?>
    </div>
</div>
