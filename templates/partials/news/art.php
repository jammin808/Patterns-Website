<?php
/**
 * A category's artwork in place of an article's picture (docs/DESIGN.md 8.36): a test card drawn for the category,
 * chosen by the article's id, so a list of one category never shows copies. Psc\News\Art prints it from its generated
 * table, the same bytes the share card and the art's own build print; the markup is the table's literals and takes
 * no words from the article, so nothing in it needs escaping. It is decoration, hidden from assistive technology,
 * and its colours are classes in pages/news.css, never in the markup.
 *
 * @var string $key  a category key
 * @var int|null $id  the article's id: the same id always gets the same picture
 * @var bool|null $wide  the article page's band rather than a card's 16:9 tile
 * @var bool|null $lead  the lead card's picture: shown whole on the screen's black, never cropped
 */
if (!\Psc\News\Categories::has($key)) {
    return;
}
?>
<?= \Psc\News\Art::tile($key, (int) ($id ?? 0), !empty($wide), !empty($lead)) ?>
