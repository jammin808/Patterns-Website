<?php
/**
 * One form field (docs/PORTAL-UI.md, pt-field): a label tied to its control, optional help, and the error when
 * the field is invalid, with aria-invalid and aria-describedby set to match. Used by the licence, download and
 * release pages.
 *
 * @var string $name
 * @var string $label
 * @var string $kind text | search | date | textarea | select | file | checkbox
 * @var string $value
 * @var string|null $error
 * @var string|null $help
 * @var bool $required
 * @var bool $checked checkbox only
 * @var array<string, string> $options select options, value => label
 * @var array<string, string|int> $attrs extra attributes (autocomplete, maxlength, rows, accept, multiple, …)
 * @var string $id
 * @var bool $markOptional say "(optional)" on a field that is not required
 */
$kind ??= 'text';
$value = (string) ($value ?? '');
$error ??= null;
$help ??= null;
$required ??= false;
$checked ??= false;
$options ??= [];
$attrs ??= [];
$id ??= 'f-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
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
    $attr .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e($v) . '"';
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
$optional = !$required && $markOptional && $kind !== 'checkbox' ? ' <span class="pt-muted">(optional)</span>' : '';
?>
<?php if ($kind === 'checkbox'): ?>
<div class="pt-field pt-field--inline">
    <input type="checkbox"<?= $attr ?> value="1"<?= $checked ? ' checked' : '' ?>>
    <label for="<?= e($id) ?>"><?= e($label) ?></label>
<?php else: ?>
<div class="pt-field">
    <label for="<?= e($id) ?>"><?= e($label) ?><?= $optional ?></label>
    <?php if ($kind === 'textarea'): ?>
        <textarea<?= $attr ?>><?= e($value) ?></textarea>
    <?php elseif ($kind === 'select'): ?>
        <select<?= $attr ?>>
            <?php foreach ($options as $optValue => $optLabel): ?>
                <option value="<?= e($optValue) ?>"<?= (string) $optValue === $value ? ' selected' : '' ?>><?= e($optLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($kind === 'file'): ?>
        <input type="file"<?= $attr ?>>
    <?php else: ?>
        <input type="<?= e($kind) ?>"<?= $attr ?> value="<?= e($value) ?>">
    <?php endif; ?>
<?php endif; ?>
    <?php if ($help !== null && $help !== ''): ?><p class="pt-help" id="<?= e($id) ?>-help"><?= e($help) ?></p><?php endif; ?>
    <?php if ($error !== null && $error !== ''): ?><p class="pt-error" id="<?= e($id) ?>-error"><?= e($error) ?></p><?php endif; ?>
</div>
