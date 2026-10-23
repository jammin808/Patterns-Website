<?php
/**
 * /digest/: the weekly Industry Updates digest's signup page (docs/NEWS.md §14.6), drawn by DigestController::show.
 * The hero (the trail goes in it) with when the digest goes; after a send, "Check your inbox", in the same words
 * whatever became of the address; what the digest holds; the form, whose button after the consent words is the
 * request (no tick box: the form has one purpose); and the other ways to follow the section. The site's components
 * only, so the page adds no stylesheet. The form posts back to /digest/ with FormGuard's hidden fields.
 *
 * @var array<string, mixed> $copy  content/news.php 'digest'
 * @var string $cadence  "every Friday at 09:00 UK time", or "once a week"
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var bool $sent
 * @var bool $quick  the last send came faster than a person types: the address is back and nothing was sent
 * @var list<array{type: string, message: string}> $flashes
 */
use Psc\News\Digest\Links;
use Psc\News\Paths;

$copy ??= [];
$old ??= [];
$errors ??= [];
$sent ??= false;
$quick ??= false;
$cadence ??= (string) ($copy['cadence_fallback'] ?? 'once a week');
$words = is_array($copy['page'] ?? null) ? $copy['page'] : [];
$what = is_array($words['what'] ?? null) ? $words['what'] : [];
$email = is_scalar($old['email'] ?? null) ? (string) $old['email'] : '';
$error = isset($errors['email']) && is_string($errors['email']) ? $errors['email'] : null;
// After a refused send the redirect lands on #form: the error summary carries that id then, so the fragment focuses
// it. A refusal no field explains (too many sign-ups from a network, the database away) is said there too.
$summary = $this->partial('error-summary', [
    'errors' => $errors,
    'fields' => ['email' => 'digest-email'],
    'flashes' => $flashes ?? [],
    'id' => 'form',
    'action' => 'before sending',
]);
$describe = 'digest-email-help' . ($error !== null ? ' digest-email-err' : '');
?>
<section class="c-hero c-hero--page" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow"><?= e($words['eyebrow'] ?? 'Industry Updates') ?></p>
                <h1 class="c-hero-title" id="page-title"><?= e($words['title'] ?? 'The weekly digest') ?></h1>
                <p class="c-hero-lede"><?= e(strtr((string) ($words['lede'] ?? ''), [':cadence' => $cadence])) ?></p>
            </div>
        </div>
    </div>
</section>

<div class="l-section l-section--flush">
    <div class="l-wrap l-split l-split--even">
        <div class="l-stack">
            <?php if ($sent): ?>
                <div class="c-callout c-callout--success" id="sent" role="status" tabindex="-1" data-feedback>
                    <?= icon('ok') ?>
                    <h2 class="c-callout-title"><?= e($words['sent_title'] ?? 'Check your inbox') ?></h2>
                    <p><?= e($words['sent'] ?? '') ?></p>
                </div>
            <?php endif; ?>
            <section class="l-stack" aria-labelledby="digest-what">
                <h2 id="digest-what"><?= e($words['what_title'] ?? 'What you get') ?></h2>
                <div class="c-prose">
                    <ul>
                        <?php foreach ($what as $line): ?>
                            <li><?= e($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        </div>

        <section class="l-stack" aria-labelledby="digest-form-title">
            <h2 id="digest-form-title"><?= e($words['form_title'] ?? 'Sign up') ?></h2>
            <form class="c-form c-form--panel"<?= $summary === '' ? ' id="form"' : '' ?> method="post" action="<?= e(Links::SIGNUP) ?>#form" novalidate>
                <?= csrf_field() ?>
                <?= form_guard('digest', $old) ?>
                <?= $summary ?>

                <div class="c-field<?= $error !== null ? ' is-invalid' : '' ?>">
                    <label for="digest-email"><?= e($words['email'] ?? 'Email address') ?></label>
                    <p class="c-help" id="digest-email-help"><?= e($words['email_help'] ?? '') ?></p>
                    <input id="digest-email" name="email" type="email" autocomplete="email" maxlength="190" required value="<?= e($email) ?>"<?= $error !== null ? ' aria-invalid="true"' : '' ?> aria-describedby="<?= e($describe) ?>">
                    <?php if ($error !== null): ?>
                        <p class="c-error-msg" id="digest-email-err"><?= e($error) ?></p>
                    <?php endif; ?>
                </div>

                <p class="c-help" id="digest-consent"><?= e($words['consent'] ?? '') ?> <a href="/legal/privacy/"><?= e($words['privacy'] ?? 'How we use your address') ?></a></p>

                <div class="c-form-foot">
                    <button class="c-btn c-btn--primary" type="submit" data-busy="<?= e($words['busy'] ?? 'Sending…') ?>" aria-describedby="digest-consent"><?= icon('mail') ?><?= e($words['button'] ?? 'Send me the digest') ?></button>
                </div>
            </form>
            <p class="u-small"><?= e($words['account'] ?? '') ?> <a href="/portal/account/#details"><?= e($words['account_link'] ?? 'Go to your account page') ?></a></p>
            <p class="u-small"><?= e($words['feed'] ?? '') ?> <a href="<?= e(Paths::feed()) ?>" type="application/rss+xml"><?= e($words['feed_link'] ?? 'The RSS feed') ?></a></p>
        </section>
    </div>
</div>
