<?php
/**
 * The "send a new confirmation link" form. With a known address it is a single button; otherwise it asks for one.
 * The answer is the same for every address, so the address here proves nothing and grants nothing.
 *
 * @var string|null $email
 * @var string $idPrefix
 * @var string|null $buttonLabel
 */
$email ??= '';
$buttonLabel ??= 'Send a new link';
?>
<form method="post" action="/portal/verify/resend/" class="pt-form">
    <?= csrf_field() ?>
    <?php if ($email !== ''): ?>
        <input type="hidden" name="email" value="<?= e($email) ?>">
    <?php else: ?>
        <?= $this->render('portal/auth/_field', [
            'id' => $idPrefix . '-email',
            'name' => 'email',
            'label' => 'Email address',
            'type' => 'email',
            'autocomplete' => 'email',
            'spellcheck' => 'false',
            'required' => true,
            'maxlength' => 190,
        ]) ?>
    <?php endif; ?>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--secondary"><?= e($buttonLabel) ?></button>
    </div>
</form>
