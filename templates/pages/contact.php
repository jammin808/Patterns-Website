<?php
/**
 * /contact/: the contact form (docs/DESIGN.md 8.24) beside the assistant, the portal's ticket route and what happens
 * next. ContactController::show passes the topics, the old input, the errors, whether a message was just sent and the
 * optional Turnstile site key. The form posts back to /contact/; a hidden "website" field catches bots.
 *
 * @var array<string, string> $topics
 * @var array<string, mixed> $old
 * @var array<string, string> $errors
 * @var bool $sent
 * @var string $turnstileKey
 * @var array<string, mixed> $page
 * @var list<array{type: string, message: string}> $flashes
 */
$topics ??= [];
$old ??= [];
$errors ??= [];
$sent ??= false;
$turnstileKey ??= '';
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/support.css')) . '">');
if ($turnstileKey !== '' && !$sent) {
    $this->push('scripts', '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>');
}
$site = content('site');
$products = \Psc\Content\Content::products();
$val = static fn (string $k): string => is_scalar($old[$k] ?? null) ? (string) $old[$k] : '';
$err = static fn (string $k): ?string => isset($errors[$k]) && is_string($errors[$k]) ? $errors[$k] : null;
$labels = ['name' => 'Name', 'email' => 'Email address', 'company' => 'Company', 'topic' => 'Topic', 'product' => 'Product', 'message' => 'Message', 'turnstile' => 'Security check'];
$fieldErrors = array_filter($errors, static fn ($m, $k): bool => is_string($m) && isset($labels[$k]), ARRAY_FILTER_USE_BOTH);
// A send refused for a reason no field explains (too many messages in an hour, for one) comes back only as a flash at
// the top of the page, above where the redirect lands. It is shown at the form instead, where the visitor is.
$formAlert = null;
if ($fieldErrors === []) {
    foreach ((array) ($flashes ?? []) as $f) {
        if (is_array($f) && ($f['type'] ?? '') === 'danger' && is_string($f['message'] ?? null)) {
            $formAlert = $f['message'];
        }
    }
}
$email = (string) ($site['email'] ?? '');
// aria-describedby for a field: its hint (if any) and its error (if any).
$describe = static function (string $k, ?string $help = null) use ($err): string {
    $ids = array_filter([$help, $err($k) !== null ? $k . '-err' : null]);
    return $ids !== [] ? ' aria-describedby="' . e(implode(' ', $ids)) . '"' : '';
};
$invalid = static fn (string $k): string => $err($k) !== null ? ' aria-invalid="true"' : '';
$errorMsg = static fn (string $k): string => $err($k) !== null ? '<p class="c-error-msg" id="' . e($k) . '-err">' . e($err($k)) . '</p>' : '';
$maxMessage = 5000;
?>
<section class="c-hero c-hero--page support-contact-hero" aria-labelledby="page-title">
    <div class="l-wrap">
        <div class="c-hero-grid">
            <div>
                <p class="c-eyebrow">Contact</p>
                <h1 class="c-hero-title" id="page-title">Get in touch</h1>
                <p class="c-hero-lede">Questions about licences, an AGM, rental packs or a show next week? Send a message, or ask the assistant first. Based in the UK, replies in English.</p>
            </div>
            <?php if (!$sent): ?>
                <div class="c-callout c-callout--info support-showday" role="note">
                    <?= icon('clock') ?>
                    <strong class="c-callout-title">Show in the next 48 hours?</strong>
                    <p>Choose Help with the software as the topic and put the show date at the top of your message, so it is clear how soon you need an answer.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<div class="l-section l-section--flush support-contact">
    <div class="l-wrap l-split l-split--main">
        <section class="support-contact-main" aria-labelledby="form-title">
            <?php if ($sent): ?>
                <div class="c-callout c-callout--success support-sent" id="sent" role="status" tabindex="-1">
                    <?= icon('ok') ?>
                    <h2 class="c-callout-title" id="form-title">Message sent</h2>
                    <p>Thank you. Your message is with us and a receipt is on its way to your inbox. The reply will come to the email address you gave.</p>
                    <p class="support-sent-links"><a href="/support/">Go to support</a><a href="/contact/">Send another message</a></p>
                </div>
            <?php else: ?>
                <h2 class="support-contact-title" id="form-title">Send a message</h2>
                <?php /* After a failed POST the redirect lands on #form: the error summary carries that id then, so the
                       fragment focuses it instead of clearing the focus site.js gave it. */ ?>
                <form class="c-form c-form--panel"<?= $fieldErrors === [] && $formAlert === null ? ' id="form"' : '' ?> method="post" action="/contact/#form" novalidate>
                    <?= csrf_field() ?>
                    <?php if ($fieldErrors !== []): $n = count($fieldErrors); ?>
                        <div class="c-callout c-callout--danger c-error-summary" id="form" data-error-summary tabindex="-1" role="alert" aria-labelledby="error-title">
                            <?= icon('alert') ?>
                            <strong class="c-callout-title" id="error-title">Check <?= $n === 1 ? 'one field' : e($n) . ' fields' ?> before sending</strong>
                            <ul>
                                <?php foreach ($fieldErrors as $k => $m): ?>
                                    <li><a href="#<?= e($k === 'turnstile' ? 'turnstile' : $k) ?>"><?= e($m) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php elseif ($formAlert !== null): ?>
                        <div class="c-callout c-callout--danger c-error-summary" id="form" data-error-summary tabindex="-1" role="alert" aria-labelledby="error-title">
                            <?= icon('alert') ?>
                            <strong class="c-callout-title" id="error-title">Your message was not sent</strong>
                            <p><?= e($formAlert) ?></p>
                            <?php if ($email !== ''): ?><p>Email <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p><?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="c-form-row">
                        <div class="c-field<?= $err('name') !== null ? ' is-invalid' : '' ?>">
                            <label for="name">Name</label>
                            <input id="name" name="name" autocomplete="name" maxlength="120" required value="<?= e($val('name')) ?>"<?= $invalid('name') ?><?= $describe('name') ?>>
                            <?= $errorMsg('name') ?>
                        </div>
                        <div class="c-field<?= $err('email') !== null ? ' is-invalid' : '' ?>">
                            <label for="email">Email address</label>
                            <input id="email" name="email" type="email" autocomplete="email" maxlength="190" required value="<?= e($val('email')) ?>"<?= $invalid('email') ?><?= $describe('email') ?>>
                            <?= $errorMsg('email') ?>
                        </div>
                    </div>

                    <div class="c-field<?= $err('company') !== null ? ' is-invalid' : '' ?>">
                        <label for="company">Company or venue <span class="c-label-opt">Optional</span></label>
                        <input id="company" name="company" autocomplete="organization" maxlength="160" value="<?= e($val('company')) ?>"<?= $invalid('company') ?><?= $describe('company') ?>>
                        <?= $errorMsg('company') ?>
                    </div>

                    <div class="c-form-row">
                        <div class="c-field<?= $err('topic') !== null ? ' is-invalid' : '' ?>">
                            <label for="topic">Topic</label>
                            <select id="topic" name="topic" required<?= $invalid('topic') ?><?= $describe('topic') ?>>
                                <option value=""<?= $val('topic') === '' ? ' selected' : '' ?>>Choose a topic</option>
                                <?php foreach ($topics as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $val('topic') === (string) $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $errorMsg('topic') ?>
                        </div>
                        <div class="c-field<?= $err('product') !== null ? ' is-invalid' : '' ?>">
                            <label for="product">Product <span class="c-label-opt">Optional</span></label>
                            <select id="product" name="product"<?= $invalid('product') ?><?= $describe('product') ?>>
                                <option value=""<?= $val('product') === '' ? ' selected' : '' ?>>Not about one product</option>
                                <?php foreach ($products as $key => $p): ?>
                                    <option value="<?= e($key) ?>"<?= $val('product') === $key ? ' selected' : '' ?>><?= e($p['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?= $errorMsg('product') ?>
                        </div>
                    </div>

                    <div class="c-field<?= $err('message') !== null ? ' is-invalid' : '' ?>">
                        <label for="message">Message</label>
                        <p class="c-help" id="message-help">What you are running, on which machines, and what you need. For a show, include its date.</p>
                        <textarea id="message" name="message" minlength="10" maxlength="<?= e($maxMessage) ?>" required data-count="#message-count"<?= $invalid('message') ?><?= $describe('message', 'message-help message-count') ?>><?= e($val('message')) ?></textarea>
                        <?php /* No live region: a count announced on every keystroke drowns out the typing echo. The
                               field's description carries it, so it is read when the field takes focus. */ ?>
                        <p class="c-count" id="message-count"><?= e(mb_strlen($val('message'))) ?> / <?= e($maxMessage) ?></p>
                        <?= $errorMsg('message') ?>
                    </div>

                    <div class="u-visually-hidden" aria-hidden="true">
                        <label for="website">Leave this field empty</label>
                        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off" value="">
                    </div>

                    <?php if ($turnstileKey !== ''): ?>
                        <div class="c-field<?= $err('turnstile') !== null ? ' is-invalid' : '' ?>" id="turnstile">
                            <div class="cf-turnstile" data-sitekey="<?= e($turnstileKey) ?>"></div>
                            <?= $errorMsg('turnstile') ?>
                        </div>
                    <?php endif; ?>

                    <div class="c-form-foot">
                        <button class="c-btn c-btn--primary" type="submit">Send message</button>
                        <p>We use your details only to reply. See the <a href="/legal/privacy/">privacy policy</a>.</p>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <div class="support-contact-aside">
            <section class="support-contact-block support-js-only" aria-labelledby="ask-title">
                <h2 class="support-contact-h" id="ask-title">Ask the assistant first</h2>
                <p>It answers from this site's pages for all four apps, and it can point you to a person.</p>
                <button class="c-launcher" type="button" data-chat-open aria-controls="chat-panel" aria-expanded="false">
                    <span class="c-launcher-icon"><?= icon('chat') ?></span>
                    <span><b>Ask the assistant</b><small>Setup, licences, downloads and first fixes</small></span>
                </button>
            </section>
            <section class="support-contact-block" aria-labelledby="ticket-title">
                <h2 class="support-contact-h" id="ticket-title">Already a client?</h2>
                <p>Report a bug or ask a question in the client portal. You can attach files, and every reply stays with the ticket.</p>
                <p class="l-cluster support-contact-links"><a class="c-btn c-btn--secondary c-btn--sm" href="/portal/tickets/new/">Open a support ticket</a><a href="/support/">Help pages</a></p>
            </section>
            <section class="support-contact-block" aria-labelledby="next-title">
                <h2 class="support-contact-h" id="next-title">What happens next</h2>
                <ol class="support-contact-next">
                    <li>Your message reaches us as soon as you send it. It is saved on this site too, in case an email goes astray.</li>
                    <li>You get an email receipt that names the topic you chose.</li>
                    <li>The reply comes to the email address you gave.</li>
                </ol>
                <dl class="c-kv u-mt-4">
                    <dt>Email</dt><dd><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></dd>
                    <dt>Based in</dt><dd><?= e($site['publisher']['country'] ?? 'United Kingdom') ?></dd>
                    <dt>Replies in</dt><dd>English</dd>
                </dl>
            </section>
        </div>
    </div>
</div>
