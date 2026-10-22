<?php
/**
 * An article's comments section (docs/NEWS.md §9). The article page is cached and the same for every visitor, so
 * nothing here reads the visitor or starts a session: it holds the visible comments as they were when the page was
 * drawn (data-rendered-at), and a box that is a sign-in link to the comment page (behind sign-in, so signing in
 * brings the person back to it) or says why comments are closed. news-comments.js, when the portal's signed-in hint
 * is set, asks /api/news/{id}/state what the person may do and builds the rest from the inert templates below: the
 * comment form, the person's own recent and pending comments and the ones a moderator hid (marked so, for their
 * author only), and Delete and Report buttons. Without scripting the comment page offers all of it.
 *
 * Comments are plain text (nl2br(e())), never Markdown, with no live links; the author's short name and, for staff,
 * a badge of its own that no name can produce.
 *
 * @var array<string, mixed> $article the live article (ArticleStore::findBySlug())
 * @var list<array<string, mixed>> $comments its visible comments (Comments::visible())
 * @var string|null $closed why it takes no new comments (Comments::closedReason()); worked out here when not given
 */
$comments ??= [];
$closed ??= (new \Psc\News\Comments($app))->closedReason($article);
$articleId = (int) $article['id'];
$page = \Psc\Controllers\NewsCommentController::pagePath($article);
// The call to action follows security.registration; an unrecognised mode is treated as by invitation (AuthController).
$canRegister = in_array($app->config->string('security.registration', 'open'), ['open', 'approval'], true);
$register = '/portal/register/?next=' . rawurlencode($page);
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/news-comments.css')) . '">');
$this->push('scripts', '<script type="module" src="' . e(asset('/assets/js/news-comments.js')) . '"></script>');
?>
<section class="news-comments" id="comments" aria-labelledby="comments-title" data-comments data-article-id="<?= $articleId ?>" data-rendered-at="<?= time() ?>" data-comment-page="<?= e($page) ?>">
    <h2 id="comments-title">Comments</h2>
    <ol class="news-comments-list" data-comment-list>
        <?php foreach ($comments as $c): ?>
            <li class="news-comment<?= !empty($c['staff']) ? ' news-comment--staff' : '' ?>" id="comment-<?= (int) $c['id'] ?>" data-comment-id="<?= (int) $c['id'] ?>">
                <p class="news-comment-meta">
                    <span class="news-comment-author"><bdi data-comment-author><?= e($c['name']) ?></bdi></span>
                    <?php if (!empty($c['staff'])): ?><span class="c-tag news-comment-badge"><?= e(\Psc\News\Comments::STAFF_BADGE) ?></span><?php endif; ?>
                    <time datetime="<?= e(date(DATE_ATOM, (int) $c['created_at'])) ?>" data-comment-time><?= e(\Psc\News\Comments::timeLabel((int) $c['created_at'])) ?></time>
                </p>
                <p class="news-comment-body"><?= nl2br(e($c['body'])) ?></p>
                <div class="news-comment-actions" data-comment-actions></div>
            </li>
        <?php endforeach; ?>
    </ol>
    <p class="news-comments-empty" data-comment-empty<?= $comments !== [] ? ' hidden' : '' ?>>No comments yet.</p>

    <div class="news-comments-box" data-comment-box>
        <?php if ($closed !== null): ?>
            <p class="news-comments-closed"><?= icon('lock') ?><span><?= e($closed) ?></span></p>
        <?php elseif ($canRegister): ?>
            <p class="news-comments-cta" data-comment-cta><a href="<?= e($page) ?>" data-comment-signin>Sign in</a> or <a href="<?= e($register) ?>" data-comment-register>create an account</a> to comment.</p>
        <?php else: ?>
            <p class="news-comments-cta" data-comment-cta><a href="<?= e($page) ?>" data-comment-signin>Sign in to comment</a>.</p>
        <?php endif; ?>
    </div>
    <p class="u-visually-hidden" role="status" data-comment-status></p>

    <template data-comment-form>
        <form class="c-form news-comments-form" novalidate>
            <div class="c-field">
                <label for="comment-body">Your comment</label>
                <p class="c-help" id="comment-body-help">Everyone can read it, shown as <strong><bdi data-comment-name></bdi></strong><span data-comment-staff-note hidden> with the <?= e(\Psc\News\Comments::STAFF_BADGE) ?> badge</span>, with the time. Plain text up to <?= e(number_format(\Psc\News\Comments::MAX_LENGTH)) ?> characters, at most <?= (int) \Psc\News\Comments::MAX_LINKS ?> links and no email addresses.</p>
                <textarea id="comment-body" name="body" rows="4" maxlength="<?= (int) \Psc\News\Comments::MAX_LENGTH ?>" required aria-describedby="comment-body-help comment-body-count" data-comment-input></textarea>
                <p class="c-count" id="comment-body-count" data-comment-count>0 / <?= (int) \Psc\News\Comments::MAX_LENGTH ?></p>
                <p class="c-error-msg" id="comment-body-error" data-comment-error hidden></p>
            </div>
            <div class="news-comments-submit">
                <button type="submit" class="c-btn c-btn--primary" data-comment-send>Post comment</button>
                <p class="news-comments-rules">Comments follow the <a href="/legal/terms/">site terms</a>. You can delete your own at any time.</p>
            </div>
        </form>
    </template>
    <template data-comment-item>
        <li class="news-comment" data-comment-id="">
            <p class="news-comment-meta">
                <span class="news-comment-author"><bdi data-comment-author></bdi></span>
                <span class="c-tag news-comment-badge" data-comment-staff hidden><?= e(\Psc\News\Comments::STAFF_BADGE) ?></span>
                <time data-comment-time></time>
            </p>
            <p class="news-comment-note" data-comment-pending hidden><?= icon('clock') ?><span><?= e(\Psc\News\Comments::PENDING_NOTE) ?></span></p>
            <p class="news-comment-note" data-comment-hidden hidden><?= icon('shield') ?><span><?= e(\Psc\News\Comments::HIDDEN_NOTE) ?></span></p>
            <p class="news-comment-body" data-comment-body></p>
            <div class="news-comment-actions" data-comment-actions></div>
        </li>
    </template>
    <template data-comment-expired>
        <p class="news-comments-alert" role="alert">Your sign-in has ended, so the comment was not posted. It is kept in this tab: <a href="<?= e($page) ?>" data-comment-signin>sign in again</a> to post it.</p>
    </template>
    <template data-comment-failed>
        <p class="news-comments-alert" role="alert">The comment box did not load. You can still comment on <a href="<?= e($page) ?>">the comment page</a>.</p>
    </template>
</section>
