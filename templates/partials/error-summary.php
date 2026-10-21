<?php
/**
 * A form's error summary (docs/DESIGN.md 8.24), first inside the form. After a refused send it lists each field's
 * error as a link to that field's control, or, when no field explains the refusal (too many sends in an hour, a send
 * faster than a person fills the form in), says why in the words the controller flashed. site.js focuses it on load,
 * so it is the first thing read, and site.css hides the flash strip's copy of the refusal while it shows.
 *
 * It renders nothing when there is nothing to fix, so a template can test the returned string.
 *
 * @var array<string, mixed> $errors the errors the controller kept, field => message
 * @var array<string, string> $fields this form's fields in their order on the page, field => the id of the control
 *      its error links to; errors for any other field belong to another form on the page and are not listed here
 * @var list<array{type: string, message: string}>|null $flashes the page's flash messages
 * @var bool|null $own whether a refusal in the flashes is this form's (a page with several forms says which posted)
 * @var string|null $action how the title over field errors ends: "before sending", "and try again"
 * @var string|null $failed the title over a refusal no field explains
 * @var string|null $id the summary's id; the contact form's is "form", where its redirect lands
 * @var string|null $extra markup the site built, shown under such a refusal: another way to reach a person
 */
$own ??= true;
$action ??= 'and try again';
$failed ??= 'That did not go through';
$id ??= 'error-summary';
$extra ??= '';
$listed = [];
foreach ($fields as $field => $target) {
    $message = $errors[$field] ?? null;
    if (is_string($message) && $message !== '') {
        $listed[] = [$target, $message];
    }
}
$refusal = null;
if ($listed === [] && $own) {
    foreach ((array) ($flashes ?? []) as $f) {
        if (is_array($f) && ($f['type'] ?? '') === 'danger' && is_string($f['message'] ?? null) && $f['message'] !== \Psc\Controllers\Controller::CHECK_FIELDS) {
            $refusal = $f['message'];
        }
    }
}
if ($listed === [] && $refusal === null) {
    return;
}
$n = count($listed);
?>
<div class="c-callout c-callout--danger c-error-summary" id="<?= e($id) ?>" data-error-summary tabindex="-1" role="alert" aria-labelledby="<?= e($id) ?>-summary-title">
    <?= icon('alert') ?>
    <?php if ($listed !== []): ?>
        <strong class="c-callout-title" id="<?= e($id) ?>-summary-title">Check <?= $n === 1 ? 'one field' : e($n) . ' fields' ?> <?= e($action) ?></strong>
        <ul>
            <?php foreach ($listed as [$target, $message]): ?>
                <li><a href="#<?= e($target) ?>"><?= e($message) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <strong class="c-callout-title" id="<?= e($id) ?>-summary-title"><?= e($failed) ?></strong>
        <p><?= e($refusal) ?></p>
        <?= $extra ?>
    <?php endif; ?>
</div>
