<?php
/**
 * An article's images, after its form: each with its own forms (its words, making it the lead, deleting it), then
 * the upload form. Every one of them sends only itself, so admin-news.js disables their buttons while the article
 * above has unsaved changes. Pictures come from the administrators' media route, so drafts show too.
 *
 * @var array<string, mixed> $article
 * @var list<array<string, mixed>> $images the lead first
 * @var array<string, string> $errors
 * @var array<string, string> $old the posted image form's input after a refusal (its "form" names it)
 * @var list<array{type: string, message: string}> $flashes
 * @var int $maxBytes
 * @var bool $imagesSupported
 */
use Psc\News\Images;

$id = (int) $article['id'];
$action = '/portal/admin/news/' . $id . '/images/';
$field = fn (array $p): string => $this->render('admin/news/_field', $p);
$posted = (string) ($old['form'] ?? '');
$limit = filesize_human($maxBytes);
/** One image form's fields: what was typed after a refusal of that form, else what is stored. */
$words = static function (string $form, array $stored) use ($old, $posted): array {
    $out = [];
    foreach (['alt', 'caption', 'credit', 'credit_url'] as $k) {
        $out[$k] = $posted === $form ? (string) ($old[$k] ?? '') : (string) ($stored[$k] ?? '');
    }
    return $out;
};
$wordFields = static function (string $form, array $values) use ($field, $errors): string {
    $key = 'image.' . substr($form, strlen('image-')) . '.';
    $err = static fn (string $k): ?string => $errors[$key . $k] ?? null;
    return $field(['name' => 'alt', 'id' => $form . '-alt', 'label' => 'Alt text', 'value' => $values['alt'], 'error' => $err('alt'), 'required' => true, 'count' => Images::ALT_MAX,
            'help' => 'What the image shows, for someone who cannot see it.', 'attrs' => ['autocomplete' => 'off']])
        . $field(['name' => 'caption', 'id' => $form . '-caption', 'label' => 'Caption', 'value' => $values['caption'], 'error' => $err('caption'),
            'help' => 'Shown under the image.', 'attrs' => ['autocomplete' => 'off']])
        . $field(['name' => 'credit', 'id' => $form . '-credit', 'label' => 'Credit', 'value' => $values['credit'], 'error' => $err('credit'),
            'help' => 'Who took it or owns it, for example the maker\'s press office.', 'attrs' => ['autocomplete' => 'off']])
        . $field(['name' => 'credit_url', 'id' => $form . '-credit-url', 'label' => 'Credit link', 'kind' => 'url', 'value' => $values['credit_url'], 'error' => $err('credit_url'),
            'help' => 'A web address for the credit, such as the press kit\'s page.', 'attrs' => ['autocomplete' => 'off', 'inputmode' => 'url', 'spellcheck' => 'false']]);
};
$summaryFields = static fn (string $form): array => [
    'image.' . substr($form, strlen('image-')) . '.file' => $form . '-file',
    'image.' . substr($form, strlen('image-')) . '.alt' => $form . '-alt',
    'image.' . substr($form, strlen('image-')) . '.caption' => $form . '-caption',
    'image.' . substr($form, strlen('image-')) . '.credit' => $form . '-credit',
    'image.' . substr($form, strlen('image-')) . '.credit_url' => $form . '-credit-url',
];
?>
<section class="pt-section" id="images" aria-labelledby="images-title">
    <h2 class="pt-h2" id="images-title">Images</h2>
    <p>The first image leads the article and is its picture when it is shared; the others follow the verdict. An article without one shows its category's artwork.</p>
    <p class="pt-help">Save your changes first: each form here sends only itself, so anything unsaved in the article above would be lost.</p>
    <div class="pt-alert pt-alert--warning" role="status" data-news-dirty hidden>The article above has unsaved changes. Save them, and the image buttons work again.</div>

    <?php if ($images === []): ?>
        <p class="pt-muted">No images yet.</p>
    <?php else: ?>
        <ul class="pt-news-images" role="list">
            <?php foreach ($images as $n => $image):
                $iid = (int) $image['id'];
                $form = 'image-' . $iid;
                $w = min(480, max(1, (int) $image['width']));
                $h = max(1, (int) round((int) $image['height'] * $w / max(1, (int) $image['width'])));
                $name = (string) $image['original_name']; ?>
                <li class="pt-news-image" id="<?= e($form) ?>">
                    <img class="pt-news-thumb" src="/portal/admin/news/media/<?= e($image['base']) ?>-480.webp" width="<?= $w ?>" height="<?= $h ?>" alt="<?= e($image['alt']) ?>" decoding="async">
                    <div class="pt-news-image-body">
                        <p class="pt-small">
                            <?php if ($n === 0): ?><span class="pt-badge pt-badge--active">Lead image</span><?php endif; ?>
                            <span class="pt-mono"><?= e($name) ?></span>
                            <span class="pt-muted"><?= (int) $image['width'] ?> &times; <?= (int) $image['height'] ?> pixels, uploaded <?= e(date_short((int) $image['created_at'])) ?></span>
                        </p>
                        <form class="pt-form" method="post" action="<?= e($action) ?>" novalidate data-news-image-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="image" value="<?= $iid ?>">
                            <input type="hidden" name="form" value="<?= e($form) ?>">
                            <?= $this->partial('error-summary', ['errors' => $errors, 'fields' => $summaryFields($form), 'flashes' => $flashes ?? [], 'own' => $posted === $form, 'id' => $form . '-summary', 'action' => 'and save again', 'failed' => 'The image details were not saved']) ?>
                            <?= $wordFields($form, $words($form, $image)) ?>
                            <div class="pt-actions">
                                <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small" data-busy="Saving…">Save the details<span class="u-visually-hidden"> of <?= e($name) ?></span></button>
                            </div>
                        </form>
                        <div class="pt-actions">
                            <?php if ($n > 0): ?>
                                <form method="post" action="<?= e($action) ?>" data-news-image-form>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="lead">
                                    <input type="hidden" name="image" value="<?= $iid ?>">
                                    <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small">Make it the lead image<span class="u-visually-hidden">: <?= e($name) ?></span></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e($action) ?>" data-news-image-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="image" value="<?= $iid ?>">
                                <button type="submit" class="pt-btn pt-btn--danger pt-btn--small">Delete the image<span class="u-visually-hidden"> <?= e($name) ?></span></button>
                            </form>
                        </div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!$imagesSupported): ?>
        <div class="pt-alert pt-alert--danger" role="alert">Images cannot be added on this server: its PHP has no WebP or JPEG support. Ask the host to turn on GD with WebP.</div>
    <?php else: ?>
        <form class="pt-form pt-news-upload" id="image-new" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" novalidate data-news-image-form>
            <h3>Add an image</h3>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="form" value="image-new">
            <?= $this->partial('error-summary', ['errors' => $errors, 'fields' => $summaryFields('image-new'), 'flashes' => $flashes ?? [], 'own' => $posted === 'image-new', 'id' => 'image-new-summary', 'action' => 'and upload it again', 'failed' => 'The image was not added']) ?>
            <p class="pt-help">Only upload images you have the right to use: your own, a press kit that allows editorial use, or licensed.</p>
            <?= $field(['name' => 'image', 'id' => 'image-new-file', 'label' => 'Image', 'kind' => 'file', 'required' => true, 'error' => $errors['image.new.file'] ?? null,
                'help' => 'A PNG, JPEG, WebP or GIF of up to ' . $limit . ' and 25 megapixels, at least ' . Images::MIN_WIDTH . ' pixels wide. It is stored as new copies, so nothing else in the file is kept.',
                'attrs' => ['accept' => '.png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif', 'data-max-bytes' => $maxBytes, 'data-max-message' => 'This image is larger than ' . $limit . '. Choose a smaller one.']]) ?>
            <?= $wordFields('image-new', $words('image-new', [])) ?>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--primary" data-busy="Uploading…">Upload the image</button>
            </div>
        </form>
    <?php endif; ?>
</section>
