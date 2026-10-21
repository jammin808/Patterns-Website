<?php
/**
 * The new-ticket form for one type: bug, feature or question. Repopulates from $old after a validation error.
 *
 * @var string $type
 * @var string $title
 * @var string $product preselected from ?product=
 * @var array<string, string> $products
 * @var array<string, string> $severities
 * @var array<string, string> $severityHelp
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var list<string> $allowed
 * @var int $maxMb
 * @var int $maxFiles
 */
$old ??= [];
$errors ??= [];
$v = static fn (string $k, string $default = ''): string => (string) ($old[$k] ?? $default);
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$field = fn (array $p): string => $this->render('portal/tickets/_field', $p);
$ledes = [
    'bug' => 'Tell us what went wrong. Clear steps make a bug much easier to find and fix. Only you and the team can see it, unless the team makes it public.',
    'feature' => 'Share an idea that would make your shows easier. Feature requests are public: other clients can read them, vote and reply.',
    'question' => 'Ask how something works, about licensing, or anything else. Only you and the team can see it, unless the team makes it public.',
];
$titleHelp = [
    'bug' => 'A short summary, for example “Output window goes black after changing displays”.',
    'feature' => 'A short summary of your idea.',
    'question' => 'A short summary of your question.',
];
$submit = ['bug' => 'Send bug report', 'feature' => 'Post feature request', 'question' => 'Send question'];
$severity = $v('severity', 'normal');
$sevError = $err('severity');
$fileError = $err('attachments');
$accept = implode(',', array_map(static fn (string $x): string => '.' . $x, $allowed));
$fileHelp = 'Up to ' . $maxFiles . ' files, ' . $maxMb . ' MB each. Accepted: ' . implode(', ', $allowed) . '.';
?>
<header class="pt-header">
    <p class="pt-eyebrow">New ticket</p>
    <h1 class="pt-title"><?= e($title) ?></h1>
    <p class="pt-lede"><?= e($ledes[$type]) ?></p>
    <div class="pt-header-actions"><a class="pt-btn pt-btn--ghost" href="/portal/tickets/new/">Choose a different kind</a></div>
</header>

<form class="pt-form" id="form" method="post" action="/portal/tickets/"<?= $type === 'bug' ? ' enctype="multipart/form-data"' : '' ?> novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($type) ?>">

    <?= $field(['name' => 'title', 'label' => 'Title', 'value' => $v('title'), 'error' => $err('title'), 'help' => $titleHelp[$type], 'required' => true, 'attrs' => ['maxlength' => 150, 'autocomplete' => 'off']]) ?>

    <?= $field(['name' => 'product', 'label' => 'Product', 'kind' => 'select', 'value' => $v('product', $product), 'error' => $err('product'), 'required' => true, 'options' => ['' => 'Choose a product'] + $products]) ?>

    <?php if ($type === 'bug'): ?>
        <?= $field(['name' => 'app_version', 'label' => 'Version', 'value' => $v('app_version'), 'error' => $err('app_version'), 'help' => 'The version number of the app, if you know it, for example 2.4.1.', 'attrs' => ['maxlength' => 40, 'autocomplete' => 'off']]) ?>

        <?= $field(['name' => 'environment', 'label' => 'Windows version and hardware', 'kind' => 'textarea', 'value' => $v('environment'), 'error' => $err('environment'), 'help' => 'For example Windows 11 24H2, the graphics card, and the displays or outputs in use.', 'attrs' => ['rows' => 3, 'maxlength' => 255, 'autocomplete' => 'off']]) ?>

        <fieldset class="pt-fieldset"<?= $sevError !== null ? ' aria-describedby="severity-error"' : '' ?>>
            <legend>How much does this affect you?</legend>
            <?php if ($sevError !== null): ?><p class="pt-error" id="severity-error"><?= e($sevError) ?></p><?php endif; ?>
            <?php foreach ($severities as $key => $label): ?>
                <div class="pt-field pt-field--inline">
                    <input type="radio" id="severity-<?= e($key) ?>" name="severity" value="<?= e($key) ?>" aria-describedby="severity-<?= e($key) ?>-help"<?= $key === $severity ? ' checked' : '' ?><?= $sevError !== null ? ' aria-invalid="true"' : '' ?>>
                    <label for="severity-<?= e($key) ?>"><?= e($label) ?></label>
                    <p class="pt-help" id="severity-<?= e($key) ?>-help"><?= e($severityHelp[$key] ?? '') ?></p>
                </div>
            <?php endforeach; ?>
        </fieldset>

        <?= $field(['name' => 'what_happened', 'label' => 'What happened?', 'kind' => 'textarea', 'value' => $v('what_happened'), 'error' => $err('what_happened'), 'help' => 'Include any error message word for word, and when it happens.', 'required' => true, 'attrs' => ['rows' => 6, 'maxlength' => 5000]]) ?>

        <?= $field(['name' => 'expected', 'label' => 'What did you expect to happen?', 'kind' => 'textarea', 'value' => $v('expected'), 'error' => $err('expected'), 'attrs' => ['rows' => 3, 'maxlength' => 5000]]) ?>

        <?= $field(['name' => 'steps', 'label' => 'Steps to reproduce', 'kind' => 'textarea', 'value' => $v('steps'), 'error' => $err('steps'), 'help' => 'Number each step, starting from opening the app: 1. … 2. …', 'attrs' => ['rows' => 5, 'maxlength' => 5000]]) ?>

        <div class="pt-field">
            <label for="f-attachments">Screenshots or log files <span class="pt-muted">(optional)</span></label>
            <input type="file" id="f-attachments" name="attachments[]" multiple accept="<?= e($accept) ?>" aria-describedby="f-attachments-help<?= $fileError !== null ? ' f-attachments-error' : '' ?>"<?= $fileError !== null ? ' aria-invalid="true"' : '' ?>>
            <p class="pt-help" id="f-attachments-help"><?= e($fileHelp) ?></p>
            <?php if ($fileError !== null): ?><p class="pt-error" id="f-attachments-error"><?= e($fileError) ?></p><?php endif; ?>
        </div>
    <?php elseif ($type === 'feature'): ?>
        <?= $field(['name' => 'problem', 'label' => 'What problem would it solve?', 'kind' => 'textarea', 'value' => $v('problem'), 'error' => $err('problem'), 'help' => 'Describe the moment in your shows where this would help.', 'required' => true, 'attrs' => ['rows' => 5, 'maxlength' => 5000]]) ?>

        <?= $field(['name' => 'idea', 'label' => 'Your idea', 'kind' => 'textarea', 'value' => $v('idea'), 'error' => $err('idea'), 'help' => 'How you imagine it working. A rough outline is fine.', 'required' => true, 'attrs' => ['rows' => 6, 'maxlength' => 5000]]) ?>

        <p class="pt-small pt-muted">Other clients see your first name and the initial of your last name, never your email address.</p>
    <?php else: ?>
        <?= $field(['name' => 'question', 'label' => 'Your question', 'kind' => 'textarea', 'value' => $v('question'), 'error' => $err('question'), 'help' => 'Mention the version you use and anything you have already tried, if it helps.', 'required' => true, 'attrs' => ['rows' => 7, 'maxlength' => 5000]]) ?>
    <?php endif; ?>

    <p class="pt-small pt-muted">You can use simple formatting: **bold**, *italic*, lists and links.</p>

    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--primary"><?= e($submit[$type]) ?></button>
        <a class="pt-btn pt-btn--ghost" href="/portal/tickets/">Cancel</a>
    </div>
</form>
