<?php
/**
 * To staff: the Industry Updates desk needs a look. Each kind is sent at most once a day (Settings::claimEmail()):
 * a session failed, an AI article was held as a draft for review, or three sessions in a row wrote nothing.
 *
 * @var string $kind failed | held | empty
 * @var string $summary the run's one-line summary (failed, empty)
 * @var int $count sessions in a row that wrote nothing (empty)
 * @var list<array{title: string, reason: string, url: string}> $articles the held drafts (held)
 * @var string $run the run's page, a site path
 * @var string $desk the desk's page, a site path
 * @var string $baseUrl
 */
$articles ??= [];
$summary ??= '';
$count ??= 0;
$heading = match ($kind) {
    'held' => count($articles) === 1 ? 'An article is waiting for review' : 'Articles are waiting for review',
    'empty' => 'The desk has written nothing for ' . $count . ' sessions',
    default => 'A desk session failed',
};
$intro = match ($kind) {
    'held' => 'The AI desk kept ' . (count($articles) === 1 ? 'this article' : 'these articles') . ' as a draft instead of publishing, because the automatic check found something a person should read first. Nothing is published until you publish it.',
    'empty' => 'The last ' . $count . ' sessions wrote no articles. That can be a slow news week, but it can also mean the brief is too narrow, the importance bar too high, or the searches running out. The run pages show what each session considered.',
    default => 'A session of the AI desk stopped before it finished, so nothing from it was published. It is not retried on its own; you can run a session from the desk page.',
};
?>
<h1 style="font-size:18px;margin:0 0 12px;"><?= e($heading) ?></h1>
<p><?= e($intro) ?></p>
<?php if ($kind === 'held'): ?>
    <?php foreach ($articles as $a): ?>
        <p style="margin:0 0 4px;"><strong><a href="<?= e($baseUrl . $a['url']) ?>"><?= e($a['title']) ?></a></strong></p>
        <?php if ($a['reason'] !== ''): ?><p style="margin:0 0 12px;color:#5b616e;"><?= e($a['reason']) ?></p><?php endif; ?>
    <?php endforeach; ?>
    <?= $this->render('emails/_button', ['url' => $baseUrl . (count($articles) === 1 ? $articles[0]['url'] : '/portal/admin/news/?tab=held'), 'label' => count($articles) === 1 ? 'Review the article' : 'Review the held articles']) ?>
<?php else: ?>
    <?php if ($summary !== ''): ?><div style="margin-top:16px;border-left:3px solid #e3e5ea;padding-left:12px;"><p><?= e($summary) ?></p></div><?php endif; ?>
    <?= $this->render('emails/_button', ['url' => $baseUrl . $run, 'label' => 'See the session']) ?>
<?php endif; ?>
<p style="font-size:13px;color:#5b616e;">This email is sent at most once a day for each kind of notice. <a href="<?= e($baseUrl . $desk) ?>">Open the desk</a> to pause it or change its settings.</p>
