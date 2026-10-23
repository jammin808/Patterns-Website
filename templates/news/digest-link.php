<?php
/**
 * /digest/confirm/ and /digest/unsubscribe/ (docs/NEWS.md §14.6), drawn by DigestController for a digest email's
 * link: what pressing the button would do ("ask"), what it did, or why there is nothing to do. The only form is the
 * button, posting to the page's own address with its query (the token), with no field, no CSRF token and no script:
 * a GET changes nothing, so a link scanner that opens the page never confirms or unsubscribes anyone. The referrer
 * meta keeps the token in the address from reaching the next page in a Referer. The site's components only.
 *
 * @var array<string, mixed> $copy  content/news.php 'digest'
 * @var 'confirm'|'unsubscribe' $kind
 * @var string $state  ask, done, already, expired, gone, test or invalid
 * @var string $title  the state's h1
 * @var string|null $action  the button's form address (the "ask" state only)
 * @var string $email  the row's address, on the pages whose words name it
 * @var string $cadence  when the digest goes (the confirmation's done page)
 * @var string|null $unsubscribe  the row's own unsubscribe link (the confirmation's done page)
 */
use Psc\News\Digest\Links;
use Psc\News\Paths;

$this->push('head', '<meta name="referrer" content="no-referrer">');
$copy ??= [];
$words = is_array($copy[$kind] ?? null) ? $copy[$kind] : [];
$text = $state === 'invalid' ? (string) ($copy['invalid'] ?? '') : (string) ($words[$state] ?? '');
$text = strtr($text, [':email' => $email ?? '', ':cadence' => $cadence ?? '']);
$signUp = in_array($state, ['expired', 'gone', 'invalid'], true);
?>
<section class="c-hero c-hero--page" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow"><?= e($copy['page']['eyebrow'] ?? 'Industry Updates') ?></p>
                <h1 class="c-hero-title" id="page-title"><?= e($title) ?></h1>
                <p class="c-hero-lede"><?= e($text) ?></p>
            </div>
        </div>
    </div>
</section>

<div class="l-section l-section--flush">
    <div class="l-wrap l-stack">
        <?php if ($state === 'ask' && $action !== null): ?>
            <form method="post" action="<?= e($action) ?>">
                <button class="c-btn c-btn--primary" type="submit"><?= e($words['button'] ?? '') ?></button>
            </form>
        <?php elseif ($signUp): ?>
            <p><a class="c-btn c-btn--primary" href="<?= e(Links::SIGNUP) ?>"><?= e($copy['again'] ?? 'Sign up again') ?></a></p>
        <?php elseif ($kind === 'confirm' && $state === 'done' && $unsubscribe !== null): ?>
            <p><a href="<?= e($unsubscribe) ?>"><?= e($words['unsubscribe'] ?? 'Changed your mind? Unsubscribe') ?></a></p>
        <?php elseif ($kind === 'unsubscribe' && $state === 'done'): ?>
            <p><a href="<?= e(Links::SIGNUP) ?>"><?= e($copy['again_changed'] ?? 'Changed your mind? Sign up again') ?></a></p>
        <?php endif; ?>
        <p><a href="<?= e(Paths::index()) ?>"><?= e($copy['news'] ?? 'Read Industry Updates') ?></a></p>
    </div>
</div>
