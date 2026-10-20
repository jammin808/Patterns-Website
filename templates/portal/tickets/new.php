<?php
/**
 * Choosing what kind of ticket to open.
 *
 * @var string $product a product to carry into the form, or ''
 * @var array<string, string> $errors
 */
$suffix = $product !== '' ? '&product=' . rawurlencode($product) : '';
$choices = [
    'bug' => ['Report a bug', 'Something in one of the apps is not working as it should. Only you and the team see it, unless the team makes it public.'],
    'feature' => ['Suggest a feature', 'An idea that would make your shows easier. Feature requests are public, so other clients can vote for them.'],
    'question' => ['Ask a question', 'How something works, licensing, or anything else. Only you and the team see it, unless the team makes it public.'],
];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Tickets</p>
    <h1 class="pt-title">New ticket</h1>
    <p class="pt-lede">Choose what you would like to do.</p>
</header>

<?php if (isset($errors['type'])): ?>
    <p class="pt-error" id="type-error"><?= e($errors['type']) ?></p>
<?php endif; ?>

<div class="pt-grid pt-grid--3">
    <?php foreach ($choices as $key => [$heading, $text]): ?>
        <a class="pt-card pt-card--link" href="/portal/tickets/new/?type=<?= e($key) ?><?= e($suffix) ?>">
            <span class="pt-badge pt-badge--<?= e($key) ?>"><?= e(['bug' => 'Bug', 'feature' => 'Idea', 'question' => 'Question'][$key]) ?></span>
            <h2 class="pt-card-title"><?= e($heading) ?></h2>
            <p class="pt-card-body"><?= e($text) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<section class="pt-section">
    <h2 class="pt-h2">Looking for a quick answer?</h2>
    <p>The <a href="/support/">support pages</a> and the <a href="/support/faq/">frequently asked questions</a> may already cover it. You can also see what other clients have asked for on the <a href="/portal/ideas/">feature request board</a>.</p>
</section>
