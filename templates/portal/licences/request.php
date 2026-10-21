<?php
/**
 * The request form for one licence: one hardware ID field per PC it covers, nonprofit pricing, a 5-pack for
 * rental houses (tiers only) and a note.
 *
 * @var string $product
 * @var string $productName
 * @var string $tier
 * @var array<string, mixed> $option see LicenceController::options()
 * @var array<string, string> $hardwareFields field name => label
 * @var string $hardwareHelp
 * @var array<string, mixed>|null $nonprofit the nonprofit extra from the licensing content, when offered
 * @var array<string, mixed>|null $pack the rental extra, when a 5-pack is offered for this licence
 * @var int $packQuantity
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$field = fn (array $p): string => $this->render('portal/licences/_field', $p);
$machines = (int) $option['machines'];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Licences</p>
    <h1 class="pt-title">Request <?= e($productName) ?> <?= e($option['name']) ?></h1>
    <p class="pt-lede">Add your hardware ID<?= $machines > 1 ? 's' : '' ?> and send the request. We email you about payment, and your licence file appears in the portal once it is issued.</p>
</header>

<section class="pt-section" aria-labelledby="request-summary">
    <h2 class="pt-h2" id="request-summary">Your choice</h2>
    <dl class="pt-kv">
        <dt>Product</dt>
        <dd><?= e($productName) ?></dd>
        <dt>Licence</dt>
        <dd><?= e($option['name']) ?></dd>
        <dt>Price</dt>
        <dd><?= $this->render('portal/licences/_price', ['option' => $option]) ?></dd>
        <dt>Covers</dt>
        <dd><?= $machines === 1 ? '1 PC' : $machines . ' PCs' ?></dd>
    </dl>
    <?php if ($option['summary'] !== ''): ?><p class="pt-small pt-muted"><?= e($option['summary']) ?></p><?php endif; ?>
    <p class="pt-small"><a href="/portal/licences/request/?product=<?= e(rawurlencode($product)) ?>">Choose a different licence</a></p>
</section>

<form class="pt-form" method="post" action="/portal/licences/request/" id="form">
    <?= csrf_field() ?>
    <input type="hidden" name="product" value="<?= e($product) ?>">
    <input type="hidden" name="tier" value="<?= e($tier) ?>">

    <fieldset class="pt-fieldset">
        <legend><?= $machines > 1 ? 'Hardware IDs' : 'Hardware ID' ?></legend>
        <p class="pt-help"><?= $machines === 2 ? 'This licence covers a main and a backup PC, so it needs the hardware ID of each.' : 'The licence is tied to the PC with this hardware ID.' ?></p>
        <?php foreach ($hardwareFields as $name => $label): ?>
            <?= $field([
                'name' => $name,
                'label' => $label,
                'value' => $old[$name] ?? '',
                'error' => $errors[$name] ?? null,
                'help' => $hardwareHelp,
                'required' => true,
                'attrs' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 128, 'class' => 'pt-mono'],
            ]) ?>
        <?php endforeach; ?>
    </fieldset>

    <?php if ($nonprofit !== null || $pack !== null): ?>
        <fieldset class="pt-fieldset">
            <legend>Pricing</legend>
            <?php if ($nonprofit !== null): ?>
                <?= $field([
                    'name' => 'nonprofit',
                    'kind' => 'checkbox',
                    'label' => 'We are a charity, church, school or university (' . (string) ($nonprofit['price_label'] ?? 'nonprofit pricing') . ')',
                    'checked' => ($old['nonprofit'] ?? '') === '1',
                    'error' => $errors['nonprofit'] ?? null,
                ]) ?>
                <?= $field([
                    'name' => 'organisation',
                    'label' => 'Organisation name',
                    'value' => $old['organisation'] ?? '',
                    'error' => $errors['organisation'] ?? null,
                    'help' => 'Needed for nonprofit pricing.',
                    'markOptional' => false,
                    'attrs' => ['autocomplete' => 'organization', 'maxlength' => 160],
                ]) ?>
            <?php endif; ?>
            <?php if ($pack !== null): ?>
                <?= $field([
                    'name' => 'pack',
                    'kind' => 'checkbox',
                    'label' => 'Make it a ' . $packQuantity . '-pack for a rental or production company',
                    'checked' => ($old['pack'] ?? '') === '1',
                    'error' => $errors['pack'] ?? null,
                    'help' => ((string) ($pack['price_label'] ?? '') !== '' ? ucfirst((string) $pack['price_label']) . '. ' : '') . 'Add the other PCs’ hardware IDs in the note if you have them.',
                ]) ?>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <?= $field([
        'name' => 'note',
        'kind' => 'textarea',
        'label' => 'Anything we should know',
        'value' => $old['note'] ?? '',
        'error' => $errors['note'] ?? null,
        'help' => 'For example, the name to put on an invoice or a purchase order number. Up to 2,000 characters.',
        'attrs' => ['rows' => 4, 'maxlength' => 2000],
    ]) ?>

    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--primary">Send request</button>
        <a class="pt-btn pt-btn--ghost" href="/portal/licences/">Cancel</a>
    </div>
</form>
