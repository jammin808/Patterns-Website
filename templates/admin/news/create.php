<?php
/**
 * A new article: the editor, empty but for the writer's name. Its images are added once it is saved.
 *
 * @var array<string, mixed> $values
 * @var array<string, string> $errors
 * @var bool $kept
 * @var list<array{type: string, message: string}> $flashes
 * @var list<string> $categoryOrder
 * @var array<string, array<string, string>> $categories
 * @var array<int, string> $choices
 * @var array<string, array<string, string>> $verdicts
 * @var array<string, string> $kinds
 * @var array<string, string> $sections
 * @var string $deskByline
 * @var int $spare
 */
$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/admin-news.css')) . '">');
$this->push('scripts', '<script type="module" src="' . e(asset('/assets/js/admin-news.js')) . '"></script>');
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title">Write an article</h1>
    <p class="pt-lede">A draft needs only its headline; publishing needs the rest. Nobody sees it until you publish it, and its images can be added once it is saved.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/news/">Back to the articles</a>
    </div>
</header>

<section class="pt-section" id="editor" aria-label="The article">
    <?= $this->render('admin/news/_form', [
        'article' => null,
        'state' => null,
        'action' => '/portal/admin/news/',
        'values' => $values,
        'errors' => $errors,
        'kept' => $kept,
        'flashes' => $flashes ?? [],
        'categoryOrder' => $categoryOrder,
        'categories' => $categories,
        'choices' => $choices,
        'verdicts' => $verdicts,
        'kinds' => $kinds,
        'sections' => $sections,
        'deskByline' => $deskByline,
        'spare' => $spare,
    ]) ?>
</section>
