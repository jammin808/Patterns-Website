<?php
/**
 * One of the editor's repeating lists: the confirmed facts, the speculation (a sentence and the web address it rests
 * on) or the sources (address, title, publisher, kind). The filled rows come first, then $spare empty ones, so the
 * form works without scripting; admin-news.js shows the empty rows one at a time behind an "add" button and makes
 * more up to the list's limit. Each control's id names its list, row and field (confirmed-3-url), which is what the
 * error summary links to: row N is the draft's item N, blank rows having been dropped before it was checked.
 *
 * @var string $list confirmed | speculation | sources
 * @var string $legend
 * @var string $help
 * @var string $item the word for one row, "Fact", "Claim", "Source"
 * @var string $add the add button's words
 * @var list<array<string, string>> $rows
 * @var array<string, string> $errors
 * @var int $spare
 * @var int $max
 * @var array<string, string> $kinds sources only
 * @var bool $optional
 */
$optional ??= true;
$isSources = $list === 'sources';
$fields = $isSources ? ['url', 'title', 'publisher', 'kind'] : ['text', 'url'];
$blank = array_fill_keys($fields, '');
$shown = array_slice(array_merge(array_map(static fn (array $r): array => $r + $blank, $rows), array_fill(0, $spare, $blank)), 0, max(count($rows), min($max, count($rows) + $spare)));
$listError = (string) ($errors[$list] ?? '');
$labels = $isSources
    ? ['url' => 'web address', 'title' => 'title', 'publisher' => 'publisher', 'kind' => 'kind']
    : ['text' => '', 'url' => 'web address'];
$describe = static function (string $id, string $error): string {
    return $error !== '' ? ' aria-invalid="true" aria-describedby="' . e($id) . '-error"' : '';
};
?>
<fieldset class="pt-fieldset pt-news-rows" id="<?= e($list) ?>" data-news-rows data-news-max="<?= (int) $max ?>" aria-describedby="<?= e($list) ?>-help<?= $listError !== '' ? ' ' . e($list) . '-error' : '' ?>">
    <legend><?= e($legend) ?><?= $optional ? ' <span class="pt-muted">(optional)</span>' : '' ?></legend>
    <p class="pt-help" id="<?= e($list) ?>-help"><?= e($help) ?></p>
    <?php if ($listError !== ''): ?><p class="pt-error" id="<?= e($list) ?>-error"><?= e($listError) ?></p><?php endif; ?>
    <ol class="pt-news-row-list" role="list">
        <?php foreach ($shown as $i => $row): ?>
            <li class="pt-news-row<?= $isSources ? ' pt-news-row--source' : '' ?>" data-news-row>
                <?php foreach ($fields as $f):
                    $fid = $list . '-' . $i . '-' . $f;
                    $err = (string) ($errors[$list . '.' . $i . '.' . $f] ?? '');
                    $name = $list . '[' . $i . '][' . $f . ']';
                    $words = $labels[$f]; ?>
                    <div class="pt-field">
                        <label for="<?= e($fid) ?>"><?= e($item) ?> <span data-news-n><?= $i + 1 ?></span><?= $words !== '' ? ': ' . e($words) : '' ?></label>
                        <?php if ($f === 'kind'): ?>
                            <select id="<?= e($fid) ?>" name="<?= e($name) ?>"<?= $describe($fid, $err) ?>>
                                <?php foreach ($kinds as $k => $kindLabel): ?>
                                    <option value="<?= e($k) ?>"<?= ($row['kind'] !== '' ? $row['kind'] : 'other') === $k ? ' selected' : '' ?>><?= e($kindLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="<?= $f === 'url' ? 'url' : 'text' ?>" id="<?= e($fid) ?>" name="<?= e($name) ?>" value="<?= e($row[$f]) ?>" autocomplete="off"<?= $f === 'url' ? ' inputmode="url" spellcheck="false"' : '' ?><?= $describe($fid, $err) ?>>
                        <?php endif; ?>
                        <?php if ($err !== ''): ?><p class="pt-error" id="<?= e($fid) ?>-error"><?= e($err) ?></p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <div class="pt-actions">
        <button type="button" class="pt-btn pt-btn--secondary pt-btn--small" data-news-add hidden><?= e($add) ?></button>
    </div>
</fieldset>
