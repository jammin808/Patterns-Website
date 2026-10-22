<?php
/**
 * The desk's settings (docs/NEWS.md §8): the sessions (six rows, each a time, seven weekday ticks and Remove; blank
 * rows are ignored, so no script is needed), the articles per session and how newsworthy a story must be, publishing
 * automatically, the research limits and lists, the models with their estimated cost, the monthly cap, the phrases
 * to avoid, the AI note and the comments. One form, saved all at once; a refused save comes back as it was typed.
 *
 * @var array<string, mixed> $values the stored settings, or what was posted when a save was refused
 * @var array<string, string> $errors
 * @var array{research: array<string, array{low: float, high: float}>, writing: array<string, array{low: float, high: float}>, session: array{low: float, high: float}} $estimates
 * @var array<int, int> $perDay sessions by weekday, as stored
 * @var int $perWeek
 */
use Psc\News\Pricing;
use Psc\News\Schedule;
use Psc\News\Settings;

$field = fn (array $p): string => $this->render('portal/licences/_field', $p);
$text = static fn (mixed $v): string => is_array($v) ? implode("\n", array_map('strval', $v)) : (string) $v;
$flag = static fn (mixed $v): bool => $v === true || $v === '1' || $v === 1;
$usd = static fn (float $a): string => '$' . Pricing::usd($a);
$range = static fn (array $r): string => 'about ' . '$' . Pricing::usd($r['low']) . ' to $' . Pricing::usd($r['high']);
$short = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

$rows = [];
foreach (is_array($values['slots']) ? $values['slots'] : [] as $slot) {
    if (is_array($slot)) {
        $rows[] = [
            'time' => (string) ($slot['time'] ?? ''),
            'days' => array_map('intval', is_array($slot['days'] ?? null) ? $slot['days'] : []),
            'remove' => ($slot['remove'] ?? '') === '1',
        ];
    }
}
$rows = array_slice(array_pad($rows, Settings::MAX_SLOTS, ['time' => '', 'days' => [], 'remove' => false]), 0, Settings::MAX_SLOTS);
$slotError = $errors['slots'] ?? null;

$modelKeys = array_keys(Settings::MODELS);
$fields = [
    'slots' => 'f-slot-1-time',
    'max_per_session' => 'f-max-per-session',
    'min_importance' => 'f-min-importance',
    'auto_publish' => 'f-auto-publish',
    'max_searches' => 'f-max-searches',
    'max_fetches' => 'f-max-fetches',
    'watch_urls' => 'f-watch-urls',
    'banned_domains' => 'f-banned-domains',
    'banned_topics' => 'f-banned-topics',
    'research_model' => 'f-research-model-' . $modelKeys[0],
    'compose_model' => 'f-compose-model-' . $modelKeys[0],
    'monthly_budget_usd' => 'f-monthly-budget-usd',
    'avoid_phrases' => 'f-avoid-phrases',
    'ai_note' => 'f-ai-note',
    'comments' => 'f-comments-open',
];
$importance = [
    '1' => '1: anything relevant',
    '2' => '2: minor, but useful to some',
    '3' => '3: matters to a good share of readers',
    '4' => '4: important to most readers',
    '5' => '5: changes how shows are delivered',
];
$dayCounts = [];
foreach ($perDay as $day => $n) {
    $dayCounts[] = Schedule::DAYS[$day] . ' ' . $n;
}
/** A group of radio buttons with its legend, help and error, as the release form writes them. */
$radios = function (string $name, string $legend, array $options, string $help) use ($values, $errors): string {
    $id = 'f-' . str_replace('_', '-', $name);
    $err = $errors[$name] ?? null;
    $out = '<fieldset class="pt-fieldset"><legend>' . e($legend) . '</legend>';
    foreach ($options as $value => $label) {
        $out .= '<div class="pt-field pt-field--inline"><input type="radio" id="' . e($id . '-' . $value) . '" name="' . e($name) . '" value="' . e($value) . '"'
            . ((string) $values[$name] === (string) $value ? ' checked' : '') . ' aria-describedby="' . e($id . '-help' . ($err !== null ? ' ' . $id . '-error' : '')) . '"'
            . ($err !== null ? ' aria-invalid="true"' : '') . '><label for="' . e($id . '-' . $value) . '">' . e($label) . '</label></div>';
    }
    $out .= '<p class="pt-help" id="' . e($id) . '-help">' . e($help) . '</p>';
    if ($err !== null) {
        $out .= '<p class="pt-error" id="' . e($id) . '-error">' . e($err) . '</p>';
    }
    return $out . '</fieldset>';
};
?>
<header class="pt-header">
    <p class="pt-eyebrow">Industry Updates</p>
    <h1 class="pt-title">Desk settings</h1>
    <p class="pt-lede">When the AI desk works, how much it writes, where it may look and what it may spend. Changes apply from the next session.</p>
</header>

<?= $this->render('admin/news/desk/_nav', ['current' => 'settings']) ?>

<form class="pt-form" method="post" action="/portal/admin/news/desk/settings/" novalidate>
    <?= csrf_field() ?>
    <?= $this->partial('error-summary', ['errors' => $errors, 'fields' => $fields, 'flashes' => $flashes ?? [], 'action' => 'before saving', 'failed' => 'The settings were not saved']) ?>

    <section class="pt-section" aria-labelledby="desk-sessions">
        <h2 class="pt-h2" id="desk-sessions">Sessions</h2>
        <p class="pt-help" id="desk-slots-help">London time. Each session runs on the days ticked. To add a session, fill in an empty row; to take one away, tick Remove. Up to <?= (int) Settings::MAX_SLOTS ?> sessions.</p>
        <?php if ($slotError !== null): ?><p class="pt-error" id="desk-slots-error"><?= e($slotError) ?></p><?php endif; ?>
        <?php foreach ($rows as $i => $row):
            $n = $i + 1;
            $describedBy = 'desk-slots-help' . ($slotError !== null ? ' desk-slots-error' : ''); ?>
            <fieldset class="pt-fieldset pt-desk-slot">
                <legend>Session <?= $n ?><?= $row['time'] === '' ? ' <span class="pt-muted">(empty)</span>' : '' ?></legend>
                <div class="pt-field pt-desk-slot-time">
                    <label for="f-slot-<?= $n ?>-time">Time</label>
                    <input type="time" id="f-slot-<?= $n ?>-time" name="slots[<?= $i ?>][time]" value="<?= e($row['time']) ?>" step="60" aria-describedby="<?= e($describedBy) ?>"<?= $slotError !== null ? ' aria-invalid="true"' : '' ?>>
                </div>
                <fieldset class="pt-desk-days">
                    <legend class="u-visually-hidden">Days session <?= $n ?> runs</legend>
                    <?php foreach (Schedule::DAYS as $day => $dayName): ?>
                        <div class="pt-desk-day">
                            <input type="checkbox" id="f-slot-<?= $n ?>-day-<?= $day ?>" name="slots[<?= $i ?>][days][]" value="<?= $day ?>"<?= in_array($day, $row['days'], true) ? ' checked' : '' ?>>
                            <label for="f-slot-<?= $n ?>-day-<?= $day ?>"><span aria-hidden="true"><?= e($short[$day]) ?></span><span class="u-visually-hidden"><?= e($dayName) ?></span></label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
                <?php if ($row['time'] !== ''): ?>
                    <div class="pt-field pt-field--inline pt-desk-slot-remove">
                        <input type="checkbox" id="f-slot-<?= $n ?>-remove" name="slots[<?= $i ?>][remove]" value="1"<?= $row['remove'] ? ' checked' : '' ?>>
                        <label for="f-slot-<?= $n ?>-remove">Remove this session</label>
                    </div>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
        <p class="pt-small">As saved, the desk runs <?= (int) $perWeek ?> <?= $perWeek === 1 ? 'session' : 'sessions' ?> a week: <?= e(implode(', ', $dayCounts)) ?>. <a href="/portal/admin/news/desk/calendar/">The calendar</a> turns single days on or off.</p>
    </section>

    <section class="pt-section" aria-labelledby="desk-articles">
        <h2 class="pt-h2" id="desk-articles">Articles</h2>
        <?= $field(['name' => 'max_per_session', 'id' => 'f-max-per-session', 'kind' => 'number', 'label' => 'Articles per session', 'value' => $text($values['max_per_session']), 'required' => true,
            'help' => 'At most this many, from 1 to 5. A slow news session writes fewer, or none.', 'error' => $errors['max_per_session'] ?? null, 'attrs' => ['min' => 1, 'max' => 5, 'inputmode' => 'numeric']]) ?>
        <?= $field(['name' => 'min_importance', 'id' => 'f-min-importance', 'kind' => 'select', 'label' => 'How newsworthy a story must be', 'value' => $text($values['min_importance']), 'required' => true,
            'options' => $importance, 'help' => 'The desk rates each story from 1 to 5 and writes only those at this level or above.', 'error' => $errors['min_importance'] ?? null]) ?>
        <?= $field(['name' => 'auto_publish', 'id' => 'f-auto-publish', 'kind' => 'checkbox', 'label' => 'Publish articles automatically', 'checked' => $flag($values['auto_publish']),
            'help' => 'Ticked, an article the checks pass goes live, 10 to 30 minutes after the one before. Not ticked, every article waits as a draft for you to publish.', 'error' => $errors['auto_publish'] ?? null]) ?>
    </section>

    <section class="pt-section" aria-labelledby="desk-research">
        <h2 class="pt-h2" id="desk-research">Research</h2>
        <?= $field(['name' => 'max_searches', 'id' => 'f-max-searches', 'kind' => 'number', 'label' => 'Web searches per session', 'value' => $text($values['max_searches']), 'required' => true,
            'help' => 'From 1 to 30. Each search costs about $0.01 and brings pages into the research.', 'error' => $errors['max_searches'] ?? null, 'attrs' => ['min' => 1, 'max' => 30, 'inputmode' => 'numeric']]) ?>
        <?= $field(['name' => 'max_fetches', 'id' => 'f-max-fetches', 'kind' => 'number', 'label' => 'Pages read per session', 'value' => $text($values['max_fetches']), 'required' => true,
            'help' => 'From 0 to 20. Only a page the desk has read, or a search result it cites, can back a confirmed fact.', 'error' => $errors['max_fetches'] ?? null, 'attrs' => ['min' => 0, 'max' => 20, 'inputmode' => 'numeric']]) ?>
        <?= $field(['name' => 'watch_urls', 'id' => 'f-watch-urls', 'kind' => 'textarea', 'label' => 'Pages to check every session', 'value' => $text($values['watch_urls']),
            'help' => 'Full web addresses starting https://, one per line, up to 30: release notes, a regulator\'s news page. The desk reads them each session.', 'error' => $errors['watch_urls'] ?? null, 'attrs' => ['rows' => 4, 'spellcheck' => 'false', 'autocomplete' => 'off']]) ?>
        <?= $field(['name' => 'banned_domains', 'id' => 'f-banned-domains', 'kind' => 'textarea', 'label' => 'Banned websites', 'value' => $text($values['banned_domains']),
            'help' => 'One site per line, such as example.com, up to 200. Their subdomains are banned too. The desk never searches or reads them, and no article cites them.', 'error' => $errors['banned_domains'] ?? null, 'attrs' => ['rows' => 4, 'spellcheck' => 'false', 'autocomplete' => 'off']]) ?>
        <?= $field(['name' => 'banned_topics', 'id' => 'f-banned-topics', 'kind' => 'textarea', 'label' => 'Banned topics', 'value' => $text($values['banned_topics']),
            'help' => 'One topic per line, up to 100, each up to 80 characters. An AI article that mentions one, or that the check finds is about one, is refused.', 'error' => $errors['banned_topics'] ?? null, 'attrs' => ['rows' => 4]]) ?>
    </section>

    <section class="pt-section" aria-labelledby="desk-money" id="money">
        <h2 class="pt-h2" id="desk-money">Models and money</h2>
        <?php
        $researchOptions = [];
        $writingOptions = [];
        foreach (Settings::MODELS as $model => $name) {
            $researchOptions[$model] = $name . ', ' . $range($estimates['research'][$model]) . ' a session';
            $writingOptions[$model] = $name . ', ' . $range($estimates['writing'][$model]) . ' a session';
        }
        ?>
        <?= $radios('research_model', 'Model for research', $researchOptions, 'Research searches the web and reads a lot, so it costs most in input. Sonnet 5.5 is the default.') ?>
        <?= $radios('compose_model', 'Model for choosing and writing', $writingOptions, 'This model picks the stories and writes each article. Opus 5.5 is the default, because the writing is the point.') ?>
        <?= $field(['name' => 'monthly_budget_usd', 'id' => 'f-monthly-budget-usd', 'kind' => 'number', 'label' => 'Monthly spending cap, in US dollars', 'value' => $text($values['monthly_budget_usd']), 'required' => true,
            'help' => 'From 5 to 2000, whole dollars. A session starts only if this month\'s estimated spend plus a costly session stays under the cap. Set a hard limit in the Anthropic Console too.', 'error' => $errors['monthly_budget_usd'] ?? null, 'attrs' => ['min' => 5, 'max' => 2000, 'inputmode' => 'numeric']]) ?>
        <p class="pt-small">With the models saved now, a session costs <?= e($range($estimates['session'])) ?>, so about <?= e($usd($estimates['session']['low'] * $perWeek * 52 / 12)) ?> to <?= e($usd($estimates['session']['high'] * $perWeek * 52 / 12)) ?> a month at <?= (int) $perWeek ?> <?= $perWeek === 1 ? 'session' : 'sessions' ?> a week. These are estimates at Anthropic's published prices; the invoice is Anthropic's.</p>
    </section>

    <section class="pt-section" aria-labelledby="desk-writing">
        <h2 class="pt-h2" id="desk-writing">Writing</h2>
        <?= $field(['name' => 'avoid_phrases', 'id' => 'f-avoid-phrases', 'kind' => 'textarea', 'label' => 'Phrases to avoid', 'value' => $text($values['avoid_phrases']),
            'help' => 'One per line, up to 200. Checked in the standfirst, body, impact and verdict note, but not in quotes or link text. An article that uses one is repaired once, then held for review.', 'error' => $errors['avoid_phrases'] ?? null, 'attrs' => ['rows' => 8]]) ?>
        <?= $field(['name' => 'ai_note', 'id' => 'f-ai-note', 'kind' => 'textarea', 'label' => 'The note on AI-written articles', 'value' => $text($values['ai_note']), 'required' => true,
            'help' => 'Up to ' . Settings::AI_NOTE_MAX . ' characters. Shown at the end of every AI-written article; the words "Tell us" link to the contact page.', 'error' => $errors['ai_note'] ?? null, 'attrs' => ['rows' => 3, 'maxlength' => Settings::AI_NOTE_MAX]]) ?>
    </section>

    <section class="pt-section" aria-labelledby="desk-comments" id="comments">
        <h2 class="pt-h2" id="desk-comments">Comments</h2>
        <?= $radios('comments', 'Comments on articles', Settings::COMMENT_MODES, 'Closing comments keeps the ones already posted. Comments by administrators always appear at once.') ?>
    </section>

    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--primary" data-busy="Saving…">Save the settings</button>
    </div>
</form>
