<?php
/**
 * One form field (docs/PORTAL-UI.md, pt-field): a label tied to its control, optional help, and the error when
 * the field is invalid, with aria-invalid and aria-describedby set to match.
 *
 * @var string $name
 * @var string $label
 * @var string $kind text | search | textarea | select
 * @var string $value
 * @var string|null $error
 * @var string|null $help
 * @var bool $required
 * @var array<string, string> $options select options, value => label
 * @var array<string, string|int> $attrs extra attributes (autocomplete, maxlength, rows, …)
 * @var string $id
 * @var bool $markOptional say "(optional)" on a field that is not required (off for filter forms)
 */
$kind ??= 'text';
$value = (string) ($value ?? '');
$error ??= null;
$help ??= null;
$required ??= false;
$options ??= [];
$attrs ??= [];
$id ??= 'f-' . $name;
$markOptional ??= true;
$describedBy = [];
if ($help !== null && $help !== '') {
    $describedBy[] = $id . '-help';
}
if ($error !== null && $error !== '') {
    $describedBy[] = $id . '-error';
}
$attr = ' id="' . e($id) . '" name="' . e($name) . '"';
foreach ($attrs as $k => $v) {
    $attr .= ' ' . e($k) . '="' . e($v) . '"';
}
if ($required) {
    $attr .= ' required';
}
if ($error !== null && $error !== '') {
    $attr .= ' aria-invalid="true"';
}
if ($describedBy !== []) {
    $attr .= ' aria-describedby="' . e(implode(' ', $describedBy)) . '"';
}
?>
<div class="pt-field">
    <label for="<?= e($id) ?>"><?= e($label) ?><?php if (!$required && $markOptional): ?> <span class="pt-muted">(optional)</span><?php endif; ?></label>
    <?php if ($kind === 'textarea'): ?>
        <textarea<?= $attr ?>><?= e($value) ?></textarea>
    <?php elseif ($kind === 'select'): ?>
        <select<?= $attr ?>>
            <?php foreach ($options as $optValue => $optLabel): ?>
                <option value="<?= e($optValue) ?>"<?= (string) $optValue === $value ? ' selected' : '' ?>><?= e($optLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="<?= e($kind) ?>"<?= $attr ?> value="<?= e($value) ?>">
    <?php endif; ?>
    <?php if ($help !== null && $help !== ''): ?><p class="pt-help" id="<?= e($id) ?>-help"><?= e($help) ?></p><?php endif; ?>
    <?php if ($error !== null && $error !== ''): ?><p class="pt-error" id="<?= e($id) ?>-error"><?= e($error) ?></p><?php endif; ?>
</div>
