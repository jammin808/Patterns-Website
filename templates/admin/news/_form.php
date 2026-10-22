<?php
/**
 * The article editor (docs/NEWS.md §11), shared by the new and edit pages, in the order an article reads: the error
 * summary; the headline, SEO title and meta description; the standfirst, the body, what is confirmed and what is
 * still speculation; what it means, the verdict and its note; the categories and tags; the sources; the articles it
 * follows up; the byline and the ticks; the schedule; then the buttons the article's state allows. Every button
 * saves what is in the form first (Unpublish takes the article down even when the save is refused), and a save over
 * a change made after the form was opened is refused.
 *
 * @var array<string, mixed>|null $article
 * @var string|null $state live | scheduled | held | draft, null for a new article
 * @var string $action the form's address
 * @var array<string, mixed> $values with loaded_at, the updated_at of the article the form was first opened on
 * @var array<string, string> $errors
 * @var bool $kept the form is shown again after a refused save
 * @var list<array{type: string, message: string}> $flashes
 * @var list<string> $categoryOrder
 * @var array<string, array<string, string>> $categories
 * @var array<int, string> $choices follow-up choices, id => label
 * @var array<string, array<string, string>> $verdicts
 * @var array<string, string> $kinds
 * @var array<string, string> $sections
 * @var string $deskByline
 * @var int $spare
 */
use Psc\News\Validator;

$state ??= null;
$err = static fn (string $k): ?string => $errors[$k] ?? null;
$field = fn (array $p): string => $this->render('admin/news/_field', $p);
$origin = (string) ($article['origin'] ?? 'manual');
$published = $state === 'live' || $state === 'scheduled';
$verdictOptions = ['' => 'No verdict'] + array_map(static fn (array $v): string => (string) $v['label'], $verdicts);
$verdict = (string) $values['verdict'];
$chosen = array_map('strval', $values['categories']);
$rowFields = ['confirmed' => ['text', 'url'], 'speculation' => ['text', 'url'], 'sources' => ['url', 'title', 'publisher', 'kind']];

// The summary's links, in the order of the page; a list's own error links to its first control.
$summary = ['title' => 'f-title', 'seo_title' => 'f-seo-title', 'summary' => 'f-summary', 'dek' => 'f-dek', 'body' => 'f-body'];
$rowLinks = static function (string $list) use ($values, $rowFields): array {
    $links = [$list => $list . '-0-' . $rowFields[$list][0]];
    foreach (array_keys($values[$list]) as $i) {
        foreach ($rowFields[$list] as $f) {
            $links[$list . '.' . $i . '.' . $f] = $list . '-' . $i . '-' . $f;
        }
    }
    return $links;
};
$summary += $rowLinks('confirmed') + $rowLinks('speculation');
$summary += [
    'impact' => 'f-impact',
    'verdict' => 'f-verdict-' . ($verdict !== '' && isset($verdicts[$verdict]) ? $verdict : 'none'),
    'verdict_note' => 'f-verdict-note',
    'categories' => 'f-category-' . $categoryOrder[0],
    'tags' => 'f-tags',
];
$summary += $rowLinks('sources');
$summary += ['follow_up_of' => 'f-follow-up-of', 'byline' => 'f-byline', 'publish_at' => 'f-publish-date'];

$buttons = match ($state) {
    'live' => ['save' => ['Save changes', 'primary', 'Saving…'], 'unpublish' => ['Unpublish', 'secondary', 'Unpublishing…']],
    'scheduled' => ['save' => ['Save changes', 'primary', 'Saving…'], 'publish' => ['Publish now', 'secondary', 'Publishing…'], 'schedule' => ['Change the time', 'secondary', 'Scheduling…'], 'unpublish' => ['Unpublish', 'secondary', 'Unpublishing…']],
    default => ['save' => ['Save draft', 'primary', 'Saving…'], 'publish' => ['Publish now', 'secondary', 'Publishing…'], 'schedule' => ['Schedule', 'secondary', 'Scheduling…']],
};
?>
<form class="pt-form" id="article-form" method="post" action="<?= e($action) ?>" novalidate data-news-editor>
    <?= csrf_field() ?>
    <?php if ($article !== null): ?>
        <input type="hidden" name="loaded_at" value="<?= e((string) $values['loaded_at']) ?>">
    <?php endif; ?>
    <?= $this->partial('error-summary', [
        'errors' => $errors,
        'fields' => $summary,
        'flashes' => $flashes ?? [],
        'own' => $kept,
        'action' => 'and save again',
        'failed' => 'The article was not saved',
    ]) ?>

    <?= $field(['name' => 'title', 'label' => 'Headline', 'value' => $values['title'], 'error' => $err('title'), 'required' => true, 'count' => Validator::TITLE_MAX,
        'help' => 'The heading readers see, 10 to 160 characters. A draft needs nothing else.', 'attrs' => ['autocomplete' => 'off']]) ?>
    <?= $field(['name' => 'seo_title', 'label' => 'SEO title', 'value' => $values['seo_title'], 'error' => $err('seo_title'), 'count' => Validator::SEO_TITLE_MAX,
        'help' => 'The title search results and browser tabs show, up to 60 characters. Leave it empty to use the headline.', 'attrs' => ['autocomplete' => 'off']]) ?>
    <?= $field(['name' => 'summary', 'label' => 'Meta description', 'kind' => 'textarea', 'value' => $values['summary'], 'error' => $err('summary'), 'count' => Validator::SUMMARY_MAX,
        'help' => 'What search results show under the title, 50 to 155 characters. Leave it empty to use the standfirst.', 'attrs' => ['rows' => 2]]) ?>

    <?= $field(['name' => 'dek', 'label' => 'Standfirst', 'kind' => 'textarea', 'value' => $values['dek'], 'error' => $err('dek'), 'markOptional' => false, 'count' => Validator::DEK_MAX,
        'help' => 'One or two sentences under the headline: what happened and why it matters, 50 to 320 characters. Needed to publish.', 'attrs' => ['rows' => 3]]) ?>
    <?= $field(['name' => 'body', 'label' => 'Body', 'kind' => 'textarea', 'value' => $values['body'], 'error' => $err('body'), 'markOptional' => false, 'count' => Validator::BODY_MAX,
        'help' => 'Markdown: ## and ### headings, **bold**, *italic*, lists starting with -, and links written [words](https://…). Code blocks are not shown. 50 to 20,000 characters to publish.', 'attrs' => ['rows' => 18]]) ?>

    <?= $this->render('admin/news/_rows', ['list' => 'confirmed', 'legend' => (string) ($sections['confirmed'] ?? "What's confirmed"), 'item' => 'Fact', 'add' => 'Add a fact',
        'help' => 'One verified fact per row, each with the web address it rests on. That address must also be one of the sources below. Up to 12.',
        'rows' => $values['confirmed'], 'errors' => $errors, 'spare' => $spare, 'max' => Validator::ITEMS_MAX]) ?>
    <?= $this->render('admin/news/_rows', ['list' => 'speculation', 'legend' => (string) ($sections['speculation'] ?? 'Still speculation'), 'item' => 'Claim', 'add' => 'Add a claim',
        'help' => 'Claims not yet confirmed, each saying whose claim it is, with the web address it comes from (also one of the sources). Up to 12.',
        'rows' => $values['speculation'], 'errors' => $errors, 'spare' => $spare, 'max' => Validator::ITEMS_MAX]) ?>

    <?= $field(['name' => 'impact', 'label' => (string) ($sections['impact'] ?? 'What it means for freelancers and corporate events'), 'kind' => 'textarea', 'value' => $values['impact'], 'error' => $err('impact'), 'count' => Validator::IMPACT_MAX,
        'help' => 'Markdown. How it applies to freelance and corporate event work, or plainly that it does not.', 'attrs' => ['rows' => 6]]) ?>

    <fieldset class="pt-fieldset" aria-describedby="f-verdict-help<?= $err('verdict') !== null ? ' f-verdict-error' : '' ?>">
        <legend>Verdict <span class="pt-muted">(optional)</span></legend>
        <p class="pt-help" id="f-verdict-help">A callout near the end of the article saying whether it is worth acting on.</p>
        <?php foreach ($verdictOptions as $key => $label):
            $rid = 'f-verdict-' . ($key !== '' ? $key : 'none'); ?>
            <div class="pt-field pt-field--inline">
                <input type="radio" id="<?= e($rid) ?>" name="verdict" value="<?= e($key) ?>"<?= $verdict === (string) $key || ($key === '' && !isset($verdicts[$verdict])) ? ' checked' : '' ?><?= $err('verdict') !== null ? ' aria-invalid="true"' : '' ?>>
                <label for="<?= e($rid) ?>"><?= e($label) ?></label>
            </div>
        <?php endforeach; ?>
        <?php if ($err('verdict') !== null): ?><p class="pt-error" id="f-verdict-error"><?= e($err('verdict')) ?></p><?php endif; ?>
    </fieldset>
    <?= $field(['name' => 'verdict_note', 'label' => 'Verdict note', 'value' => $values['verdict_note'], 'error' => $err('verdict_note'), 'count' => Validator::VERDICT_NOTE_MAX,
        'help' => 'What to test or adopt, or why to wait, in a sentence.', 'attrs' => ['autocomplete' => 'off']]) ?>

    <fieldset class="pt-fieldset" aria-describedby="f-categories-help<?= $err('categories') !== null ? ' f-categories-error' : '' ?>">
        <legend>Categories</legend>
        <p class="pt-help" id="f-categories-help">Tick one to three to publish. The first ticked is the main one: it names the article's section in its breadcrumb.</p>
        <div class="pt-news-checks">
            <?php foreach ($categoryOrder as $key): ?>
                <div class="pt-field pt-field--inline">
                    <input type="checkbox" id="f-category-<?= e($key) ?>" name="categories[]" value="<?= e($key) ?>"<?= in_array($key, $chosen, true) ? ' checked' : '' ?><?= $err('categories') !== null ? ' aria-invalid="true"' : '' ?>>
                    <label for="f-category-<?= e($key) ?>"><?= e($categories[$key]['name'] ?? $key) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($err('categories') !== null): ?><p class="pt-error" id="f-categories-error"><?= e($err('categories')) ?></p><?php endif; ?>
    </fieldset>
    <?= $field(['name' => 'tags', 'label' => 'Tags', 'value' => $values['tags'], 'error' => $err('tags'),
        'help' => 'Up to 8, separated by commas, for example radio mics, spectrum.', 'attrs' => ['autocomplete' => 'off']]) ?>

    <?= $this->render('admin/news/_rows', ['list' => 'sources', 'legend' => (string) ($sections['sources'] ?? 'Sources'), 'item' => 'Source', 'add' => 'Add a source', 'optional' => false,
        'help' => 'The pages the article rests on, the original first: at least one to publish, up to 12. Readers get real links to them, and the clicks on them are counted, approximately.',
        'rows' => $values['sources'], 'errors' => $errors, 'spare' => $spare, 'max' => Validator::SOURCES_MAX, 'kinds' => $kinds]) ?>

    <?php if ($choices !== []): ?>
        <?= $field(['name' => 'follow_up_of[]', 'id' => 'f-follow-up-of', 'label' => 'Follow-up of', 'kind' => 'select', 'options' => $choices, 'selected' => array_map('strval', $values['follow_up_of']), 'error' => $err('follow_up_of'),
            'help' => 'The earlier articles this one follows up: each links to the other. Hold Ctrl (Cmd on a Mac) to choose more than one.', 'attrs' => ['multiple' => true, 'size' => min(8, max(3, count($choices)))]]) ?>
    <?php endif; ?>

    <?= $field(['name' => 'byline', 'label' => 'Byline', 'value' => $values['byline'], 'error' => $err('byline'),
        'help' => $origin === 'manual' ? 'The writer\'s name as readers see it. Left empty, an article written with AI help is credited to the ' . $deskByline . '.' : 'Articles from the AI desk and the import files are credited to the ' . $deskByline . ' unless you name someone.',
        'attrs' => ['autocomplete' => 'off']]) ?>
    <?= $field(['name' => 'comments_open', 'label' => 'Comments are open', 'kind' => 'checkbox', 'checked' => (bool) $values['comments_open'],
        'help' => 'Untick to close comments on this article; the comments already posted stay.']) ?>
    <?php if ($origin === 'ai'): ?>
        <?= $field(['name' => 'ai_written', 'label' => 'Written with AI help', 'kind' => 'checkbox', 'checked' => true, 'attrs' => ['disabled' => true],
            'help' => 'The AI desk wrote it, so it always carries the AI note.']) ?>
    <?php else: ?>
        <?= $field(['name' => 'ai_written', 'label' => 'Written with AI help', 'kind' => 'checkbox', 'checked' => (bool) $values['ai_written'],
            'help' => 'Adds the note that says so under the article.']) ?>
    <?php endif; ?>

    <?php if ($state !== 'live'): ?>
        <fieldset class="pt-fieldset" aria-describedby="f-schedule-help">
            <legend>Schedule <span class="pt-muted">(for the Schedule button)</span></legend>
            <p class="pt-help" id="f-schedule-help">The date and time to go live, in London time. Within the next year.</p>
            <div class="pt-news-when">
                <?= $field(['name' => 'publish_date', 'label' => 'Date', 'kind' => 'date', 'value' => $values['publish_date'], 'error' => $err('publish_at'), 'markOptional' => false]) ?>
                <?= $field(['name' => 'publish_time', 'label' => 'Time', 'kind' => 'time', 'value' => $values['publish_time'], 'markOptional' => false]) ?>
            </div>
        </fieldset>
    <?php endif; ?>

    <div class="pt-actions">
        <?php foreach ($buttons as $value => [$label, $style, $busy]): ?>
            <button type="submit" name="action" value="<?= e($value) ?>" class="pt-btn pt-btn--<?= e($style) ?>" data-busy="<?= e($busy) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>
    <?php if ($published): ?>
        <p class="pt-small pt-muted">This article is <?= $state === 'live' ? 'live' : 'scheduled' ?>, so saving checks it against the full publishing rules.<?= $state === 'live' ? ' Unpublishing takes it off the site. ' . e(\Psc\Controllers\Admin\AdminNewsCommentController::TAKEDOWN) : '' ?></p>
    <?php endif; ?>
</form>
