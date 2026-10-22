<?php
/**
 * One field of the article and image forms: portal/licences/_field (a label tied to its control, the hint, the error
 * with aria-invalid and aria-describedby) plus an optional character count. The count is plain text that
 * admin-news.js keeps up to date (never a live region: it would talk over the typing), listed after the hint in
 * aria-describedby so a screen reader reads it when the field takes focus. Limits are the server's, so there is no
 * maxlength: a pasted text that is too long is said to be, never cut short.
 *
 * @var string $name
 * @var string $label
 * @var string $kind text | url | search | date | time | textarea | select | file | checkbox
 * @var string $value
 * @var string|null $error
 * @var string|null $help
 * @var bool $required
 * @var bool $checked checkbox only
 * @var array<string|int, string> $options select options, value => label
 * @var list<string> $selected a multiple select's chosen values
 * @var array<string, string|int|bool> $attrs extra attributes (true is a bare attribute)
 * @var string $id
 * @var int|null $count the most characters: shows the count
 * @var bool $markOptional say "(optional)" on a field that is not required
 */
$kind ??= 'text';
$value = (string) ($value ?? '');
$error ??= null;
$help ??= null;
$required ??= false;
$checked ??= false;
$options ??= [];
$selected ??= [];
$attrs ??= [];
$count ??= null;
$id ??= 'f-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
$markOptional ??= true;
$describedBy = [];
if ($help !== null && $help !== '') {
    $describedBy[] = $id . '-help';
}
if ($count !== null) {
    $describedBy[] = $id . '-count';
    $attrs += ['data-news-count' => '#' . $id . '-count', 'data-news-max' => $count];
}
if ($error !== null && $error !== '') {
    $describedBy[] = $id . '-error';
}
$attr = ' id="' . e($id) . '" name="' . e($name) . '"';
foreach ($attrs as $k => $v) {
    if ($v === false) {
        continue;
    }
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
                <option value="<?= e($optValue) ?>"<?= (string) $optValue === $value || in_array((string) $optValue, $selected, true) ? ' selected' : '' ?>><?= e($optLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php elseif ($kind === 'file'): ?>
        <input type="file"<?= $attr ?>>
    <?php else: ?>
        <input type="<?= e($kind) ?>"<?= $attr ?> value="<?= e($value) ?>">
    <?php endif; ?>
<?php endif; ?>
    <?php if ($help !== null && $help !== ''): ?><p class="pt-help" id="<?= e($id) ?>-help"><?= e($help) ?></p><?php endif; ?>
    <?php if ($count !== null): ?><p class="c-count pt-news-count" id="<?= e($id) ?>-count"><?= mb_strlen($value) ?> / <?= (int) $count ?></p><?php endif; ?>
    <?php if ($error !== null && $error !== ''): ?><p class="pt-error" id="<?= e($id) ?>-error"><?= e($error) ?></p><?php endif; ?>
</div>
