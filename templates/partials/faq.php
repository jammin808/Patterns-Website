<?php
/**
 * An FAQ accordion (docs/DESIGN.md 8.22). With $schema true (the default) the questions are also added to the page's
 * JSON-LD as an FAQPage, so put each question set on one page only.
 *
 * The block's anchor is "faq-<name>" (a leading "faq-" or trailing "-faq" in the name is not repeated), and the
 * FAQPage's @id is the page URL with that one fragment, so the node names the element it describes. Each block on a
 * page takes its own name, which also keeps its exclusive-open group to itself, so the anchors stay unique.
 *
 * @var list<array{q: string, a: string}> $faqs answers are plain text; [links](/path/) are allowed
 * @var string|null $name   exclusive-open group name
 * @var bool|null $schema
 * @var bool|null $openFirst
 */
$name ??= 'faq';
$schema ??= true;
$openFirst ??= false;
if ($faqs === []) {
    return;
}
$stem = (string) preg_replace('/^faq-|-faq$/', '', $name);
$anchor = 'faq' . ($stem !== '' && $stem !== 'faq' ? '-' . $stem : '');
if ($schema && isset($seo) && $seo instanceof \Psc\View\Seo) {
    $plain = array_map(static fn (array $f): array => ['q' => (string) $f['q'], 'a' => trim((string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', (string) $f['a']))], $faqs);
    $node = \Psc\View\Schema::faq($seo->canonical(), $plain);
    $node['@id'] = $seo->canonical() . '#' . $anchor;
    $seo->add($node);
}
// A hyphenated word stays on one line ("14-day", "back-room"), as in the product headings.
$question = static fn (string $q): string => (string) preg_replace('/([\p{L}\d]+(?:-[\p{L}\d]+)+)/u', '<span class="u-nowrap">$1</span>', e($q));
?>
<div class="c-faq" id="<?= e($anchor) ?>">
    <?php foreach ($faqs as $i => $f): ?>
        <details class="c-faq-item" name="<?= e($name) ?>"<?= $openFirst && $i === 0 ? ' open' : '' ?>>
            <summary class="c-faq-q"><span><?= $question((string) $f['q']) ?></span><span class="c-xhair" aria-hidden="true"></span></summary>
            <div class="c-faq-a"><?= md((string) $f['a']) ?></div>
        </details>
    <?php endforeach; ?>
</div>
