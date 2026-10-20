<?php
/**
 * An FAQ accordion (docs/DESIGN.md 8.22). With $schema true (the default) the questions are also added to the page's
 * JSON-LD as an FAQPage, so put each question set on one page only.
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
if ($schema && isset($seo) && $seo instanceof \Psc\View\Seo) {
    $plain = array_map(static fn (array $f): array => ['q' => (string) $f['q'], 'a' => trim((string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', (string) $f['a']))], $faqs);
    $seo->add(\Psc\View\Schema::faq($seo->canonical() . '#' . $name, $plain));
}
?>
<div class="c-faq">
    <?php foreach ($faqs as $i => $f): ?>
        <details class="c-faq-item" name="<?= e($name) ?>"<?= $openFirst && $i === 0 ? ' open' : '' ?>>
            <summary class="c-faq-q"><?= e($f['q']) ?><span class="c-xhair" aria-hidden="true"></span></summary>
            <div class="c-faq-a"><?= md((string) $f['a']) ?></div>
        </details>
    <?php endforeach; ?>
</div>
