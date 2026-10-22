<?php
/**
 * One article: why it is held (an AI article kept for review), what it is and where it came from, the editor, its
 * images, the clicks on its links, and deleting it.
 *
 * @var array<string, mixed> $article
 * @var string $state live | scheduled | held | draft
 * @var list<array<string, mixed>> $images
 * @var list<array<string, mixed>> $links news_links rows, sources first, each with recent (its last 30 days' clicks)
 * @var array<string, mixed>|null $run the AI desk's run that wrote it
 * @var array<string, string> $old the image or delete form's input after a refusal
 * @var int $maxBytes
 * @var bool $imagesSupported
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
use Psc\Controllers\Admin\AdminNewsController;
use Psc\News\Prose;

$this->push('head', '<link rel="stylesheet" href="' . e(asset('/assets/css/pages/admin-news.css')) . '">');
$this->push('scripts', '<script type="module" src="' . e(asset('/assets/js/admin-news.js')) . '"></script>');
$id = (int) $article['id'];
[$badge, $stateLabel] = AdminNewsController::STATES[$state];
$origin = (string) $article['origin'];
$address = '/news/' . $article['slug'] . '/';
$time = static fn (mixed $ts): string => '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(AdminNewsController::london((int) $ts)) . '</time>';
$reason = trim((string) ($article['review_reason'] ?? ''));
$field = fn (array $p): string => $this->render('admin/news/_field', $p);
$sourceNo = 0;
?>
<header class="pt-header">
    <p class="pt-eyebrow">Administration</p>
    <h1 class="pt-title"><?= e($article['title']) ?></h1>
    <p class="pt-lede">
        <span class="pt-badge pt-badge--<?= e($badge) ?>"><?= e($stateLabel) ?></span>
        <?= e(AdminNewsController::ORIGINS[$origin] ?? $origin) ?>
    </p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/news/">Back to the articles</a>
        <a class="pt-btn pt-btn--secondary" href="/portal/admin/news/<?= $id ?>/preview/">Preview</a>
        <?php if ($state === 'live'): ?><a class="pt-btn pt-btn--secondary" href="<?= e($address) ?>">View it on the site</a><?php endif; ?>
    </div>
</header>

<?php if ($reason !== '' && $state !== 'live' && $state !== 'scheduled'): ?>
    <div class="pt-alert pt-alert--warning" role="status"><strong>Held for review.</strong> <?= e($reason) ?> Check it, then publish it or delete it.</div>
<?php endif; ?>

<section class="pt-section" aria-labelledby="facts-title">
    <h2 class="pt-h2" id="facts-title">About this article</h2>
    <dl class="pt-kv">
        <dt>Address</dt>
        <dd>
            <?php if ($state === 'live'): ?><a href="<?= e($address) ?>"><?= e($address) ?></a><?php else: ?><span class="pt-mono"><?= e($address) ?></span><?php endif; ?>
            <?php if ($article['published_at'] === null): ?><span class="pt-small pt-muted">It follows the headline until the article is first published, then never changes.</span><?php endif; ?>
        </dd>
        <dt><?= $state === 'scheduled' ? 'Goes live' : 'Published' ?></dt>
        <dd><?= $article['published_at'] !== null && $state !== 'draft' && $state !== 'held' ? $time($article['published_at']) . ' <span class="pt-muted">(London time)</span>' : '<span class="pt-muted">Not yet</span>' ?></dd>
        <dt>Origin</dt>
        <dd>
            <?php if ($origin === 'ai'): ?>
                The AI desk<?php if ($run !== null): ?>, in <a href="/portal/admin/news/desk/runs/<?= (int) $run['id'] ?>/">run <?= (int) $run['id'] ?></a> of <?= e(date_short((int) $run['created_at'])) ?><?php endif; ?>
            <?php elseif ($origin === 'import'): ?>
                An import file from the site's repository
            <?php else: ?>
                Written here
            <?php endif; ?>
        </dd>
        <?php if ($reason !== ''): ?>
            <dt>Held because</dt>
            <dd><?= e($reason) ?></dd>
        <?php endif; ?>
        <dt>Last changed</dt>
        <dd><?= $time($article['updated_at']) ?></dd>
        <dt>Length</dt>
        <dd><?= number_format((int) $article['word_count']) ?> words, <?= Prose::readMinutes((int) $article['word_count']) ?> min read</dd>
        <dt>Comments</dt>
        <dd><a href="/portal/admin/news/comments/?tab=visible&amp;article=<?= $id ?>"><?= (int) $article['comment_count'] ?> showing</a><?= (int) $article['comments_open'] === 1 ? '' : ' <span class="pt-muted">(closed on this article)</span>' ?></dd>
    </dl>
</section>

<section class="pt-section" id="editor" aria-labelledby="editor-title">
    <h2 class="pt-h2" id="editor-title">The article</h2>
    <?= $this->render('admin/news/_form', [
        'article' => $article,
        'state' => $state,
        'action' => '/portal/admin/news/' . $id . '/',
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

<?= $this->render('admin/news/_images', [
    'article' => $article,
    'images' => $images,
    'errors' => $errors,
    'old' => $old,
    'flashes' => $flashes ?? [],
    'maxBytes' => $maxBytes,
    'imagesSupported' => $imagesSupported,
]) ?>

<section class="pt-section" id="clicks" aria-labelledby="clicks-title">
    <h2 class="pt-h2" id="clicks-title">Link clicks, approximate</h2>
    <?php if ($links === []): ?>
        <p class="pt-muted">This article has no links out yet.</p>
    <?php else: ?>
        <p>These counts are approximate. A click counts once per link per reader's address every ten minutes and never from a robot: on an article page only when the reader's browser runs scripts, from the RSS feed either way. Nothing stops someone adding to them on purpose, so read them as a rough guide.</p>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption class="u-visually-hidden">Approximate clicks on this article's links, the sources first</caption>
                <thead>
                    <tr>
                        <th scope="col">Link</th>
                        <th scope="col">Where</th>
                        <th scope="col" class="pt-num">In all</th>
                        <th scope="col" class="pt-num">Last 30 days</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $l):
                        $isSource = (int) $l['is_source'] === 1; ?>
                        <tr>
                            <th scope="row">
                                <a href="<?= e($l['url']) ?>" rel="nofollow noopener" target="_blank"><?= e(($l['title'] ?? '') !== '' && $l['title'] !== null ? $l['title'] : $l['host']) ?><span class="u-visually-hidden"> (opens in a new tab)</span></a>
                                <span class="pt-small pt-muted"><?= e($l['host']) ?></span>
                            </th>
                            <td data-label="Where"><?= $isSource ? 'Source ' . ++$sourceNo : 'In the text' ?></td>
                            <td class="pt-num" data-label="In all"><?= number_format((int) $l['clicks']) ?></td>
                            <td class="pt-num" data-label="Last 30 days"><?= number_format((int) $l['recent']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" id="delete" aria-labelledby="delete-title">
    <h2 class="pt-h2" id="delete-title">Delete this article</h2>
    <p>Deleting removes the article, its images, its link counts and its comments for good<?= $origin === 'import' ? ', and its import file will not bring it back' : '' ?>. To take it off the site for now, unpublish it instead.</p>
    <form class="pt-form" method="post" action="/portal/admin/news/<?= $id ?>/" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <?= $this->partial('error-summary', ['errors' => $errors, 'fields' => ['confirm' => 'f-delete-confirm'], 'flashes' => $flashes ?? [], 'own' => ($old['action'] ?? '') === 'delete', 'id' => 'delete-summary', 'action' => 'before deleting', 'failed' => 'The article was not deleted']) ?>
        <?= $field(['name' => 'confirm', 'id' => 'f-delete-confirm', 'kind' => 'checkbox', 'label' => 'Yes, delete this article, its images and its comments', 'error' => $errors['confirm'] ?? null]) ?>
        <div class="pt-actions">
            <button type="submit" class="pt-btn pt-btn--danger" data-busy="Deleting…">Delete the article</button>
        </div>
    </form>
</section>
