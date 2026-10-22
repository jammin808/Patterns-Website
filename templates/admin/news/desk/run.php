<?php
/**
 * One run of the desk (docs/NEWS.md §11 Desk): what it was and how it ended, the articles it wrote, its log steps
 * with their timings, every candidate with the model's and the server's decisions and what became of it (the rules
 * that refused it included), the references, the usage and the research brief.
 *
 * @var array<string, mixed> $run news_runs row, refs, considered and log decoded
 * @var array<string, mixed>|null $requester
 * @var list<array<string, mixed>> $articles the articles it wrote
 */
use Psc\News\Pricing;
use Psc\News\Schedule;
use Psc\News\Urls;

$at = static fn (mixed $ts): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(date('j M Y, H:i:s', (int) $ts)) . '</time>'
    : '<span class="pt-muted">Not yet</span>';
$n = static fn (mixed $v): string => e(number_format((int) $v));
$took = static function (int $ms): string {
    return $ms < 1000 ? $ms . ' ms' : ($ms < 60000 ? number_format($ms / 1000, 1) . ' s' : intdiv($ms, 60000) . ' min ' . intdiv($ms % 60000, 1000) . ' s');
};
$id = (int) $run['id'];
$manual = $run['kind'] === 'manual';
$decisions = [
    'write' => 'Write', 'duplicate' => 'Duplicate', 'low_importance' => 'Below the bar', 'out_of_window' => 'Too old',
    'banned' => 'Banned topic', 'not_relevant' => 'Not relevant', 'no_sources' => 'No usable source', 'not_chosen' => 'Not chosen',
];
$outcomes = ['published' => 'Published', 'draft' => 'Draft', 'held' => 'Held for review', 'dropped' => 'Dropped', 'ready' => 'Written'];
$articleStatus = static fn (array $a): string => $a['status'] === 'published' ? ((int) $a['published_at'] > time() ? 'Scheduled' : 'Live') : ($a['review_reason'] !== null ? 'Held' : 'Draft');
?>
<header class="pt-header">
    <p class="pt-eyebrow">The AI desk</p>
    <h1 class="pt-title">Run <span class="pt-mono"><?= $id ?></span>: <?= e($manual ? 'started by hand' : Schedule::label((string) $run['slot'])) ?></h1>
    <p class="pt-lede"><?= $this->render('admin/news/desk/_status', ['status' => (string) $run['status']]) ?> <?= e((string) ($run['summary'] ?? '')) ?></p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/news/desk/">Back to the desk</a>
    </div>
</header>

<?= $this->render('admin/news/desk/_nav', ['current' => 'run']) ?>

<div class="pt-grid pt-grid--2">
    <section class="pt-card" aria-labelledby="run-facts">
        <h2 class="pt-card-title" id="run-facts">The run</h2>
        <div class="pt-card-body">
            <dl class="pt-kv">
                <dt>Kind</dt>
                <dd><?= $manual ? 'By hand' . ($requester !== null ? ', by <bdi>' . e($requester['name']) . '</bdi>' : '') : 'Scheduled' ?></dd>
                <dt>Queued</dt>
                <dd><?= $at($run['created_at']) ?></dd>
                <dt>Started</dt>
                <dd><?= $at($run['started_at']) ?></dd>
                <dt>Finished</dt>
                <dd><?= $at($run['finished_at']) ?></dd>
                <?php if ($run['deadline_at'] !== null): ?>
                    <dt>Deadline</dt>
                    <dd><?= $at($run['deadline_at']) ?></dd>
                <?php endif; ?>
                <dt>Models</dt>
                <dd><?= (string) ($run['models'] ?? '') !== '' ? e($run['models']) : '<span class="pt-muted">None used</span>' ?></dd>
            </dl>
        </div>
    </section>
    <section class="pt-card" aria-labelledby="run-usage">
        <h2 class="pt-card-title" id="run-usage">Usage and cost</h2>
        <div class="pt-card-body">
            <dl class="pt-kv">
                <dt>Input tokens</dt>
                <dd class="pt-mono"><?= $n($run['usage_in']) ?></dd>
                <dt>Cache writes</dt>
                <dd class="pt-mono"><?= $n($run['cache_write']) ?></dd>
                <dt>Cache reads</dt>
                <dd class="pt-mono"><?= $n($run['cache_read']) ?></dd>
                <dt>Output tokens</dt>
                <dd class="pt-mono"><?= $n($run['usage_out']) ?></dd>
                <dt>Web searches</dt>
                <dd class="pt-mono"><?= $n($run['searches']) ?></dd>
                <dt>Pages read</dt>
                <dd class="pt-mono"><?= $n($run['fetches']) ?></dd>
                <dt>Cost, estimated</dt>
                <dd class="pt-mono">$<?= e(Pricing::usd((int) $run['cost_micros'] / 1000000)) ?></dd>
            </dl>
        </div>
    </section>
</div>

<section class="pt-section" aria-labelledby="run-articles">
    <h2 class="pt-h2" id="run-articles">Articles</h2>
    <?php if ($articles === []): ?>
        <p class="pt-muted">This run wrote no articles.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($articles as $a): ?>
                <li><a href="/portal/admin/news/<?= (int) $a['id'] ?>/"><?= e($a['title']) ?></a> <span class="pt-small pt-muted"><?= e($articleStatus($a)) ?><?= $a['review_reason'] !== null ? ': ' . e($a['review_reason']) : '' ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="run-candidates">
    <h2 class="pt-h2" id="run-candidates">Candidates</h2>
    <?php if ($run['considered'] === []): ?>
        <p class="pt-muted">No candidates were recorded<?= in_array($run['status'], ['queued', 'running'], true) ? ' yet' : '' ?>.</p>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption class="u-visually-hidden">The stories the run considered, with the decisions on each</caption>
                <thead>
                    <tr>
                        <th scope="col">Story</th>
                        <th scope="col" class="pt-num">Importance</th>
                        <th scope="col">The model</th>
                        <th scope="col">The server</th>
                        <th scope="col">Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($run['considered'] as $c):
                        $outcome = is_array($c['outcome'] ?? null) ? $c['outcome'] : null; ?>
                        <tr>
                            <th scope="row"><?= e((string) ($c['headline'] ?? '')) ?>
                                <span class="pt-small pt-muted"><?= e((string) ($c['reason'] ?? '')) ?><?= ($c['refs'] ?? []) !== [] ? ' (' . e(implode(', ', (array) $c['refs'])) . ')' : '' ?></span></th>
                            <td class="pt-num" data-label="Importance"><?= (int) ($c['importance'] ?? 0) ?></td>
                            <td data-label="The model"><?= e($decisions[(string) ($c['decision'] ?? '')] ?? (string) ($c['decision'] ?? '')) ?></td>
                            <td data-label="The server"><?= e($decisions[(string) ($c['server'] ?? '')] ?? '') ?><?= (string) ($c['server_note'] ?? '') !== '' ? '<small>' . e($c['server_note']) . '</small>' : '' ?></td>
                            <td data-label="Outcome">
                                <?php if ($outcome !== null): ?>
                                    <?php if (isset($outcome['article'])): ?><a href="/portal/admin/news/<?= (int) $outcome['article'] ?>/"><?= e($outcomes[(string) $outcome['status']] ?? (string) $outcome['status']) ?></a><?php else: ?><?= e($outcomes[(string) $outcome['status']] ?? (string) $outcome['status']) ?><?php endif; ?>
                                    <?php if ((string) ($outcome['note'] ?? '') !== ''): ?><small><?= e($outcome['note']) ?></small><?php endif; ?>
                                    <?php foreach ((array) ($outcome['rules'] ?? []) as $rule): ?><small><span class="pt-mono"><?= e((string) ($rule['rule'] ?? '')) ?></span>: <?= e((string) ($rule['detail'] ?? '')) ?></small><?php endforeach; ?>
                                <?php else: ?>
                                    <span class="pt-muted">None</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="run-log">
    <h2 class="pt-h2" id="run-log">Steps</h2>
    <?php if ($run['log'] === []): ?>
        <p class="pt-muted">No steps were recorded<?= in_array($run['status'], ['queued', 'running'], true) ? ' yet' : '' ?>.</p>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption class="u-visually-hidden">The run's steps in order, with how long each took</caption>
                <thead>
                    <tr>
                        <th scope="col">Step</th>
                        <th scope="col">At</th>
                        <th scope="col" class="pt-num">Took</th>
                        <th scope="col">Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($run['log'] as $step):
                        $counts = [];
                        foreach ((array) ($step['counts'] ?? []) as $k => $v) {
                            $counts[] = str_replace('_', ' ', (string) $k) . ' ' . (int) $v;
                        } ?>
                        <tr>
                            <th scope="row"><?= e(ucfirst((string) ($step['step'] ?? ''))) ?></th>
                            <td data-label="At"><?= (int) ($step['t'] ?? 0) > 0 ? '<time datetime="' . e(date(DATE_ATOM, (int) $step['t'])) . '">' . e(date('H:i:s', (int) $step['t'])) . '</time>' : '' ?></td>
                            <td class="pt-num" data-label="Took"><?= e($took((int) ($step['ms'] ?? 0))) ?></td>
                            <td data-label="Note"><?= e((string) ($step['note'] ?? '')) ?><?= $counts !== [] ? '<small>' . e(implode(', ', $counts)) . '</small>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="run-refs">
    <h2 class="pt-h2" id="run-refs">References</h2>
    <?php if ($run['refs'] === []): ?>
        <p class="pt-muted">The research returned no references<?= in_array($run['status'], ['queued', 'running'], true) ? ' yet' : '' ?>.</p>
    <?php else: ?>
        <p class="pt-small pt-muted">Every address the research saw, numbered in the order it first saw them. Articles can cite only these; a confirmed fact needs a page that was read or a search result the research cited.</p>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption class="u-visually-hidden">The research's references</caption>
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Page</th>
                        <th scope="col">Found by</th>
                        <th scope="col">Cited</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($run['refs'] as $name => $ref):
                        $url = (string) ($ref['url'] ?? ''); ?>
                        <tr>
                            <th scope="row" class="pt-mono"><?= e((string) $name) ?></th>
                            <td data-label="Page"><a href="<?= e($url) ?>" rel="nofollow noopener noreferrer" target="_blank"><?= e((string) ($ref['title'] ?? '') !== '' ? $ref['title'] : $url) ?><span class="u-visually-hidden"> (opens in a new tab)</span></a><small><?= e(Urls::host($url) ?? $url) ?><?= (string) ($ref['page_age'] ?? '') !== '' ? ', ' . e($ref['page_age']) : '' ?></small></td>
                            <td data-label="Found by"><?= ($ref['via'] ?? '') === 'fetch' ? 'Read' : 'Search' ?></td>
                            <td data-label="Cited"><?= !empty($ref['cited']) ? 'Yes' : 'No' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ((string) ($run['brief'] ?? '') !== ''): ?>
    <section class="pt-section" aria-labelledby="run-brief">
        <h2 class="pt-h2" id="run-brief">The research brief</h2>
        <details class="pt-desk-brief">
            <summary>Show the brief the research wrote</summary>
            <div class="pt-desk-rules"><?= e((string) $run['brief']) ?></div>
        </details>
    </section>
<?php endif; ?>
