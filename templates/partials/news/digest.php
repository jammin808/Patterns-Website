<?php
/**
 * The weekly digest's block at the end of an article (docs/NEWS.md §14.11), after the share row and never in the
 * administrators' preview: a heading, one line and a plain link to the signup page. A link, never a form, so the
 * article stays the same for every visitor and sets no cookie; the signup page, outside /news/, carries the session.
 *
 * @var array<string, mixed> $copy  content/news.php
 */
$end = is_array($copy['digest']['end'] ?? null) ? $copy['digest']['end'] : [];
?>
<section class="news-part news-digest" aria-labelledby="news-digest">
    <h2 class="news-digest-title" id="news-digest"><?= e($end['title'] ?? 'Get Industry Updates by email') ?></h2>
    <p><?= e($end['text'] ?? '') ?></p>
    <p class="news-digest-action"><a class="c-btn c-btn--secondary" href="<?= e(\Psc\News\Digest\Links::SIGNUP) ?>"><?= icon('mail', 'c-icon--sm') ?><?= e($end['button'] ?? 'Get the weekly digest') ?></a></p>
</section>
