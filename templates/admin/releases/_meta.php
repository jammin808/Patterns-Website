<?php
/**
 * A release's details (everything but its file), shared by the add and edit forms.
 *
 * @var array<string, string> $values
 * @var array<string, string> $errors
 * @var array<string, string> $products
 * @var array<string, string> $channels
 * @var array<string, string> $access
 */
$field = fn (array $p): string => $this->render('portal/licences/_field', $p);
$chosenAccess = $values['access'] !== '' ? $values['access'] : 'registered';
?>
<?= $field(['name' => 'product', 'label' => 'Product', 'kind' => 'select', 'value' => $values['product'], 'error' => $errors['product'] ?? null, 'required' => true, 'options' => ['' => 'Choose a product'] + $products]) ?>
<?= $field(['name' => 'version', 'label' => 'Version', 'value' => $values['version'], 'error' => $errors['version'] ?? null, 'required' => true, 'help' => 'As the app shows it, for example 2.16.0.', 'attrs' => ['maxlength' => 40, 'autocomplete' => 'off', 'spellcheck' => 'false']]) ?>
<?= $field(['name' => 'channel', 'label' => 'Channel', 'kind' => 'select', 'value' => $values['channel'] !== '' ? $values['channel'] : 'stable', 'error' => $errors['channel'] ?? null, 'required' => true, 'options' => $channels, 'help' => 'Beta builds are marked as beta on the downloads page.']) ?>
<?= $field(['name' => 'title', 'label' => 'Title', 'value' => $values['title'], 'error' => $errors['title'] ?? null, 'required' => true, 'help' => 'A short headline, for example “Running order import and faster start-up”.', 'attrs' => ['maxlength' => 160, 'autocomplete' => 'off']]) ?>
<?= $field(['name' => 'notes', 'label' => 'Release notes', 'kind' => 'textarea', 'value' => $values['notes'], 'error' => $errors['notes'] ?? null, 'help' => 'Markdown: **bold**, *italic*, lists starting with -, and ### headings. Clients read these on the downloads page.', 'attrs' => ['rows' => 10, 'maxlength' => 20000]]) ?>

<fieldset class="pt-fieldset">
    <legend>Who can download it</legend>
    <?php foreach ($access as $value => $label): ?>
        <div class="pt-field pt-field--inline">
            <input type="radio" id="f-access-<?= e($value) ?>" name="access" value="<?= e($value) ?>"<?= $chosenAccess === $value ? ' checked' : '' ?> aria-describedby="f-access-help<?= isset($errors['access']) ? ' f-access-error' : '' ?>"<?= isset($errors['access']) ? ' aria-invalid="true"' : '' ?>>
            <label for="f-access-<?= e($value) ?>"><?= e($label) ?></label>
        </div>
    <?php endforeach; ?>
    <p class="pt-help" id="f-access-help">Licence holders only: clients with an issued licence for this product. Everyone else is offered a way to request one.</p>
    <?php if (isset($errors['access'])): ?><p class="pt-error" id="f-access-error"><?= e($errors['access']) ?></p><?php endif; ?>
</fieldset>
