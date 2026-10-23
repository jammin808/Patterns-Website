<?php
/**
 * The optional country of an account (docs/PORTAL-UI.md, pt-field): the contact form's list, the most common
 * countries first and then every country A to Z, stored as its ISO 3166-1 alpha-2 code. A code only the second
 * group holds is selected there; one in both groups is selected in the first only, so one option is ever selected.
 *
 * @var string $id
 * @var string $value the code, or '' for none
 * @var string|null $error
 */
$value ??= '';
$error ??= null;
$countries = \Psc\Content\Content::countries();
$top = \Psc\Content\Content::topCountries();
$invalid = $error !== null && $error !== '';
?>
<div class="pt-field">
    <label for="<?= e($id) ?>">Country <span class="pt-muted">(optional)</span></label>
    <select id="<?= e($id) ?>" name="country" autocomplete="country"<?= $invalid ? ' aria-invalid="true" aria-describedby="' . e($id) . '-error"' : '' ?>>
        <option value=""<?= $value === '' ? ' selected' : '' ?>>Choose a country</option>
        <optgroup label="Most common">
            <?php foreach ($top as $code): ?>
                <option value="<?= e($code) ?>"<?= $value === $code ? ' selected' : '' ?>><?= e($countries[$code]) ?></option>
            <?php endforeach; ?>
        </optgroup>
        <optgroup label="All countries">
            <?php foreach ($countries as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= $value === $code && !in_array($code, $top, true) ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </optgroup>
    </select>
    <?php if ($invalid): ?>
        <p class="pt-error" id="<?= e($id) ?>-error"><?= e($error) ?></p>
    <?php endif; ?>
</div>
