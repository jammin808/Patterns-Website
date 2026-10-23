<?php
/**
 * The weekly digest's body (docs/NEWS.md §14.8): text-led, no image of any kind, every link a text link to this
 * site carrying the week's static campaign tags and nothing per person. The footer is _digest-footer.php.
 *
 * @var bool $test sent from the desk: its unsubscribe links change nothing
 * @var bool $sample the newest articles stand in for a week that has none yet (a test or the preview only)
 * @var array{start: string, end: string}|null $window the London dates of the articles it covers; null on a sample
 * @var list<array{category_name: string, title: string, dek: string, url: string}> $articles the newest first
 * @var int $leftOut articles in the window beyond those shown
 * @var string $newsUrl /news/ with the campaign
 */
?>
<?php if ($test): ?>
<p style="margin:0 0 24px;padding:12px 16px;background:#fff6db;border:1px solid #e8cf7a;border-radius:8px;font-size:14px;line-height:1.5;">A test of the next digest, sent from the desk. Its unsubscribe links change nothing.<?php if ($sample): ?> No article has gone live since the last digest yet, so these are the newest articles instead.<?php endif ?></p>
<?php endif ?>
<h1 style="font-size:18px;line-height:1.3;margin:0 0 8px;">The week in Industry Updates</h1>
<?php if ($window !== null): ?>
<p style="margin:0 0 24px;color:#5b616e;">Every article that went live between <?= e($window['start']) ?> and <?= e($window['end']) ?>, the newest first.</p>
<?php endif ?>
<?php foreach ($articles as $article): ?>
<div style="margin:0 0 20px;padding:0 0 20px;border-bottom:1px solid #e3e5ea;">
<?php if ($article['category_name'] !== ''): ?>
<p style="margin:0 0 4px;font-size:12px;line-height:1.4;color:#5b616e;"><?= e($article['category_name']) ?></p>
<?php endif ?>
<h2 style="font-size:17px;line-height:1.35;margin:0 0 6px;"><a href="<?= e($article['url']) ?>" style="color:#15171c;"><?= e($article['title']) ?></a></h2>
<p style="margin:0;"><?= e($article['dek']) ?></p>
</div>
<?php endforeach ?>
<?php if ($leftOut > 0): ?>
<p>And <?= e($leftOut) ?> more this week on <a href="<?= e($newsUrl) ?>" style="color:#15171c;">Industry Updates</a>.</p>
<?php endif ?>
<p style="margin:24px 0 0;"><a href="<?= e($newsUrl) ?>" style="display:inline-block;background:#15171c;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600;">Open Industry Updates</a></p>
