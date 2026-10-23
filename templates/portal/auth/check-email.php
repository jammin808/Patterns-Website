<?php
/**
 * After creating an account. The same page whether the address was new or already had an account, so it never
 * tells anyone which addresses are registered.
 *
 * @var string $email
 * @var string|null $article the article the person was reading when they signed up (AuthController::articleOf())
 */
$article ??= null;
?>
<div class="pt-auth">
    <header class="pt-header">
        <p class="pt-eyebrow">Client portal</p>
        <h1 class="pt-title">Check your email</h1>
    </header>

    <div class="pt-alert pt-alert--success" role="status" tabindex="-1" data-feedback>
        <p>We have sent a message to <strong><?= e($email) ?></strong>.</p>
    </div>

    <div class="pt-card">
        <div class="pt-card-body">
            <p>Open the link in that email and press Confirm my email address on the page it opens to finish setting up your account. The link works for 48 hours.</p>
            <p>If this address already has an account, the email says so and explains how to sign in instead.</p>
            <?php if ($article !== null): ?>
                <p>Open the link in this browser and the confirmation page will take you back to the article you were reading.</p>
            <?php endif; ?>
        </div>
    </div>

    <section class="pt-section" aria-labelledby="check-email-help">
        <h2 class="pt-h2" id="check-email-help">Nothing arrived?</h2>
        <p>Emails can take a little while to arrive. Check your spam or junk folder, and make sure the address above is right.</p>
        <?= $this->render('portal/auth/_resend', ['email' => $email, 'idPrefix' => 'check-resend', 'buttonLabel' => 'Send the link again']) ?>
        <p class="pt-small">Wrong address? <a href="/portal/register/">Start again</a>.</p>
    </section>
</div>
