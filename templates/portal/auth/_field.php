<?php
/**
 * One text-like form field (docs/PORTAL-UI.md: div.pt-field > label + control + p.pt-help + p.pt-error). The
 * error is shown only when present, with the control marked aria-invalid and described by the help and the error.
 *
 * @var string $id
 * @var string $name
 * @var string $label
 * @var string|null $type
 * @var string|null $value
 * @var string|null $autocomplete
 * @var string|null $help
 * @var string|null $error
 * @var bool|null $required
 * @var int|null $maxlength
 * @var int|null $minlength
 * @var string|null $inputmode
 * @var string|null $spellcheck
 */
$type ??= 'text';
$value ??= '';
$help ??= null;
$error ??= null;
$required ??= false;
$described = [];
if ($help !== null && $help !== '') {
    $described[] = $id . '-help';
}
if ($error !== null && $error !== '') {
    $described[] = $id . '-error';
}
?>
<div class="pt-field">
    <label for="<?= e($id) ?>"><?= e($label) ?><?php if (!$required): ?> <span class="pt-muted">(optional)</span><?php endif; ?></label>
    <input id="<?= e($id) ?>" name="<?= e($name) ?>" type="<?= e($type) ?>"<?php
        if ($type !== 'password'): ?> value="<?= e($value) ?>"<?php endif;
        if (!empty($autocomplete)): ?> autocomplete="<?= e($autocomplete) ?>"<?php endif;
        if (!empty($maxlength)): ?> maxlength="<?= e((int) $maxlength) ?>"<?php endif;
        if (!empty($minlength)): ?> minlength="<?= e((int) $minlength) ?>"<?php endif;
        if (!empty($inputmode)): ?> inputmode="<?= e($inputmode) ?>"<?php endif;
        if (!empty($spellcheck)): ?> spellcheck="<?= e($spellcheck) ?>"<?php endif;
        if ($required): ?> required<?php endif;
        if ($error !== null && $error !== ''): ?> aria-invalid="true"<?php endif;
        if ($described !== []): ?> aria-describedby="<?= e(implode(' ', $described)) ?>"<?php endif; ?>>
    <?php if ($help !== null && $help !== ''): ?>
        <p class="pt-help" id="<?= e($id) ?>-help"><?= e($help) ?></p>
    <?php endif; ?>
    <?php if ($error !== null && $error !== ''): ?>
        <p class="pt-error" id="<?= e($id) ?>-error"><?= e($error) ?></p>
    <?php endif; ?>
</div>
