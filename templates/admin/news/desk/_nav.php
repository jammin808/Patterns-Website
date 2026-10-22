<?php
/**
 * The desk's pages as tabs under each page's header. Every desk page shows them, so the desk's stylesheet is added
 * here too (the layout prints the head stack after the page is rendered).
 *
 * @var string $current index | settings | calendar | prompt | run
 */
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/admin-desk.css')) . '">');
$pages = [
    'index' => ['/portal/admin/news/desk/', 'Overview and runs'],
    'settings' => ['/portal/admin/news/desk/settings/', 'Settings'],
    'calendar' => ['/portal/admin/news/desk/calendar/', 'Calendar'],
    'prompt' => ['/portal/admin/news/desk/prompt/', 'Prompts'],
];
?>
<nav class="pt-tabs" aria-label="The AI desk">
    <?php foreach ($pages as $key => [$href, $label]): ?>
        <a href="<?= e($href) ?>"<?= $key === $current ? ' aria-current="page"' : ($current === 'run' && $key === 'index' ? ' aria-current="true"' : '') ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
