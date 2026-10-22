<?php
/**
 * The desk's prompts (docs/NEWS.md §6.3): the research brief and the voice, each with save, reset to the default
 * and its versions (each restorable, as a new version, so the history keeps what it replaced), then the fixed rules
 * the desk sends before them, read-only.
 *
 * @var array{brief: string, voice: string} $texts what the desk uses now, or what was posted when a save was refused
 * @var array{brief: string, voice: string} $defaults
 * @var array{brief: list<array<string, mixed>>, voice: list<array<string, mixed>>} $versions the newest (in use) first
 * @var array<int, string> $authors account id => name
 * @var array<string, string> $fixed step => its fixed rules
 * @var array<string, string> $errors
 * @var int $max
 */
use Psc\News\Prose;

$at = static fn (mixed $ts): string => '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(date('j M Y, H:i', (int) $ts)) . '</time>';
$prompts = [
    'brief' => [
        'title' => 'The research brief',
        'label' => 'Research brief',
        'help' => 'What the desk looks for and what counts as news, in plain words. The desk cannot open web addresses written here: put full addresses in the settings, under pages to check every session.',
        'rows' => 18,
    ],
    'voice' => [
        'title' => 'The voice',
        'label' => 'Voice',
        'help' => 'How the articles read: tone, rhythm and words. The rules that keep the articles honest are fixed, below, and come first.',
        'rows' => 14,
    ],
];
?>
<header class="pt-header">
    <p class="pt-eyebrow">Industry Updates</p>
    <h1 class="pt-title">Desk prompts</h1>
    <p class="pt-lede">The instructions the AI desk works from. The brief and the voice are yours to change; the fixed rules are part of the code, because the checks on every article rely on them.</p>
</header>

<?= $this->render('admin/news/desk/_nav', ['current' => 'prompt']) ?>

<?php foreach ($prompts as $key => $p):
    $list = $versions[$key];
    $isDefault = $list === [] || trim((string) $list[0]['body']) === trim($defaults[$key]); ?>
    <section class="pt-section" aria-labelledby="desk-<?= e($key) ?>-title" id="<?= e($key) ?>">
        <h2 class="pt-h2" id="desk-<?= e($key) ?>-title"><?= e($p['title']) ?></h2>
        <form class="pt-form pt-desk-prompt" method="post" action="/portal/admin/news/desk/prompt/" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_<?= e($key) ?>">
            <?= $this->partial('error-summary', ['errors' => $errors, 'fields' => [$key => 'f-' . $key], 'flashes' => [], 'id' => 'desk-' . $key . '-errors', 'action' => 'before saving']) ?>
            <?= $this->render('portal/licences/_field', [
                'name' => 'text',
                'id' => 'f-' . $key,
                'kind' => 'textarea',
                'label' => $p['label'],
                'value' => $texts[$key],
                'required' => true,
                'help' => $p['help'],
                'error' => $errors[$key] ?? null,
                'attrs' => ['rows' => $p['rows'], 'maxlength' => $max],
            ]) ?>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary" data-busy="Saving…">Save <?= e(lcfirst($p['title'])) ?></button>
                <span class="pt-small pt-muted"><?= $isDefault ? 'This is the default text.' : 'Changed from the default.' ?></span>
            </div>
        </form>
        <?php if (!$isDefault): ?>
            <form method="post" action="/portal/admin/news/desk/prompt/" class="pt-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_<?= e($key) ?>">
                <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Reset to the default<span class="u-visually-hidden">: <?= e(lcfirst($p['title'])) ?></span></button>
                <span class="pt-small pt-muted">The text in use now is kept in the versions below.</span>
            </form>
        <?php endif; ?>

        <h3>Versions</h3>
        <?php if ($list === []): ?>
            <p class="pt-muted">No saved versions yet: the desk uses the default text.</p>
        <?php else: ?>
            <ol class="pt-desk-versions">
                <?php foreach ($list as $i => $v):
                    $by = $v['created_by'] !== null ? ($authors[(int) $v['created_by']] ?? '') : ''; ?>
                    <li>
                        <p class="pt-desk-version-meta">
                            <span><?= $at($v['created_at']) ?><?= $by !== '' ? ' by <bdi>' . e($by) . '</bdi>' : '' ?>, <?= e(number_format(mb_strlen((string) $v['body']))) ?> characters</span>
                            <?php if ($i === 0): ?><span class="pt-badge pt-badge--active">In use</span><?php endif; ?>
                        </p>
                        <p class="pt-small pt-muted"><?= e(Prose::cut(Prose::plain((string) $v['body']), 160)) ?></p>
                        <?php if ($i > 0): ?>
                            <form method="post" action="/portal/admin/news/desk/prompt/" class="pt-actions">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="version" value="<?= (int) $v['id'] ?>">
                                <button type="submit" class="pt-btn pt-btn--ghost pt-btn--small">Use this version again<span class="u-visually-hidden">: saved <?= e(date('j M Y, H:i', (int) $v['created_at'])) ?></span></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
<?php endforeach; ?>

<section class="pt-section" aria-labelledby="desk-fixed">
    <h2 class="pt-h2" id="desk-fixed">The fixed rules</h2>
    <p>The desk sends these before your brief and voice. They keep every article to its sources, keep what is confirmed apart from speculation, and set the answer's shape that the server checks. Web addresses in the brief cannot be fetched; full addresses go in the settings, under pages to check every session.</p>
    <?php foreach ($fixed as $step => $rules): ?>
        <h3><?= e($step) ?></h3>
        <div class="pt-desk-rules"><?= e($rules) ?></div>
    <?php endforeach; ?>
</section>
