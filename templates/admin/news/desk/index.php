<?php
/**
 * The desk's main page (docs/NEWS.md §11 Desk): its state and the buttons that change it, the last tick, the next
 * sessions, this month's estimated spend against the cap, and the last 20 runs. Pausing stops new sessions only, so
 * the pause form offers (ticked) to hold the articles a session has already scheduled, and a paused desk says how
 * many still go live.
 *
 * @var array{key: string, label: string, badge: string, text: string} $state
 * @var bool $enabled
 * @var array<string, mixed>|null $tick the last tick (t, run, status)
 * @var bool $tickStale the cron is not ticking (Settings::cronState()), with a key set
 * @var int $scheduled AI articles a session has scheduled that are not live yet
 * @var list<array{date: string, time: string, slot: string, at: int}> $upcoming
 * @var float $spent this month, estimated
 * @var int $cap
 * @var float $typical the 90th-percentile session cost the cap check assumes
 * @var array<string, mixed>|null $running
 * @var array<string, mixed>|null $queued
 * @var list<array<string, mixed>> $runs
 * @var int $perWeek
 * @var bool $hasOverrides
 */
use Psc\News\Pricing;
use Psc\News\Schedule;

$at = static fn (mixed $ts): string => (int) $ts > 0
    ? '<time datetime="' . e(date(DATE_ATOM, (int) $ts)) . '">' . e(date('j M Y, H:i', (int) $ts)) . '</time>'
    : '';
$usd = static fn (float $amount): string => '$' . Pricing::usd($amount);
$cron = '*/15 * * * * /usr/bin/php /home/<user>/domains/<domain>/public_html/tools/news.php tick';
?>
<header class="pt-header">
    <p class="pt-eyebrow">Industry Updates</p>
    <h1 class="pt-title">The AI desk</h1>
    <p class="pt-lede">The desk researches the news and writes Industry Updates articles in scheduled sessions, <?= (int) $perWeek ?> a week. Every amount of money here is an estimate from the usage Anthropic reports.</p>
    <div class="pt-header-actions">
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/news/">Articles</a>
        <a class="pt-btn pt-btn--ghost" href="/portal/admin/news/?tab=held">Held for review</a>
    </div>
</header>

<?= $this->render('admin/news/desk/_nav', ['current' => 'index']) ?>

<section class="pt-section" aria-labelledby="desk-state">
    <h2 class="pt-h2" id="desk-state">State</h2>
    <p class="pt-desk-state"><span class="pt-badge pt-badge--<?= e($state['badge']) ?>"><?= e($state['label']) ?></span> <?= e($state['text']) ?></p>
    <?php if ($running !== null): ?>
        <div class="pt-alert pt-alert--info" role="status">A session is running now: it started at <?= $at($running['started_at']) ?> and stops by <?= $at($running['deadline_at']) ?>. <a href="/portal/admin/news/desk/runs/<?= (int) $running['id'] ?>/">Follow it on its page</a>.</div>
    <?php elseif ($queued !== null): ?>
        <div class="pt-alert pt-alert--info" role="status">A session is queued. It starts within 15 minutes, at the next tick.</div>
    <?php endif; ?>
    <?php if ($enabled): ?>
        <div class="pt-field pt-field--inline pt-desk-hold">
            <input type="checkbox" id="f-hold" name="hold" value="1" form="desk-control" checked aria-describedby="f-hold-help">
            <label for="f-hold">When pausing, also hold the articles the desk has already scheduled<?= $scheduled > 0 ? ' (' . (int) $scheduled . ' now)' : '' ?></label>
            <p class="pt-help" id="f-hold-help">Pausing stops new sessions; one already running finishes. Articles a session has already scheduled still go live at their times unless you hold them: held, each becomes a draft under Held for review.</p>
        </div>
    <?php elseif ($scheduled > 0): ?>
        <p class="pt-desk-hold"><?= (int) $scheduled ?> <?= $scheduled === 1 ? 'article the desk has scheduled still goes live at its time' : 'articles the desk has scheduled still go live at their times' ?>. <a href="/portal/admin/news/?tab=scheduled">Open the scheduled articles</a> to unpublish any of them.</p>
    <?php endif; ?>
    <div class="pt-actions">
        <form method="post" action="/portal/admin/news/desk/" id="desk-control">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $enabled ? 'pause' : 'resume' ?>">
            <button type="submit" class="pt-btn <?= $enabled ? 'pt-btn--secondary' : 'pt-btn--primary' ?>"><?= $enabled ? 'Pause the desk' : 'Continue the desk' ?></button>
        </form>
        <form method="post" action="/portal/admin/news/desk/">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="run_now">
            <button type="submit" class="pt-btn pt-btn--secondary" aria-describedby="desk-run-now-help">Run a session now</button>
        </form>
    </div>
    <p class="pt-help" id="desk-run-now-help">A session started by hand begins within 15 minutes, at the next tick, and counts against the monthly cap like any other.</p>

    <dl class="pt-kv">
        <dt>Last tick</dt>
        <dd><?= $tick !== null ? $at($tick['t']) . ' <span class="pt-muted">(' . e(ago((int) $tick['t'])) . ')</span>' : '<span class="pt-muted">None yet</span>' ?></dd>
    </dl>
    <?php if ($tickStale): ?>
        <div class="pt-alert pt-alert--warning" role="status">
            <p><?= $tick === null ? 'The cron job has not ticked yet.' : 'The last tick was more than 35 minutes ago.' ?> Check the cron job in hPanel (Advanced, Cron Jobs). It should run every 15 minutes:</p>
            <p class="pt-mono pt-desk-cron"><?= e($cron) ?></p>
        </div>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="desk-next">
    <h2 class="pt-h2" id="desk-next">Next sessions</h2>
    <?php if ($upcoming === []): ?>
        <div class="pt-empty">
            <p>No sessions are scheduled. Add a session time in the settings, or turn days on in the calendar.</p>
            <a class="pt-btn pt-btn--secondary" href="/portal/admin/news/desk/settings/">Change the sessions</a>
        </div>
    <?php else: ?>
        <?php if (!$enabled): ?><p class="pt-muted">These run once you continue the desk.</p><?php endif; ?>
        <ol class="pt-desk-next">
            <?php foreach ($upcoming as $s): ?>
                <li><time datetime="<?= e(date(DATE_ATOM, $s['at'])) ?>"><?= e(Schedule::label($s['slot'])) ?></time></li>
            <?php endforeach; ?>
        </ol>
        <p class="pt-small pt-muted">London time. <?= $hasOverrides ? 'The calendar changes some days.' : 'The calendar changes no days.' ?> <a href="/portal/admin/news/desk/calendar/">Open the calendar</a></p>
    <?php endif; ?>
</section>

<section class="pt-section" aria-labelledby="desk-money">
    <h2 class="pt-h2" id="desk-money">This month's spend</h2>
    <div class="pt-stats">
        <div class="pt-stat"><span class="pt-stat-value"><?= e($usd($spent)) ?></span> <span class="pt-stat-label">Spent this month, estimated</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= e($usd((float) $cap)) ?></span> <span class="pt-stat-label">Monthly cap</span></div>
        <div class="pt-stat"><span class="pt-stat-value"><?= e($usd($typical)) ?></span> <span class="pt-stat-label">A costly session, estimated</span></div>
    </div>
    <p class="pt-small pt-muted">A session starts only while this month's spend plus a costly session (the most 9 in 10 recent sessions cost, or $2.50 until there are 10) stays under the cap. <a href="/portal/admin/news/desk/settings/#money">Change the cap</a></p>
</section>

<section class="pt-section" aria-labelledby="desk-runs">
    <h2 class="pt-h2" id="desk-runs">Last 20 runs</h2>
    <?php if ($runs === []): ?>
        <div class="pt-empty"><p>No sessions have run yet. The first starts at its time once the cron job is ticking and a key is set.</p></div>
    <?php else: ?>
        <div class="pt-table-wrap">
            <table class="pt-table pt-table--stack">
                <caption class="u-visually-hidden">The desk's last runs, the newest first</caption>
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Kind</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="pt-num">Articles</th>
                        <th scope="col" class="pt-num">Held</th>
                        <th scope="col" class="pt-num">Cost</th>
                        <th scope="col">Summary</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $r): ?>
                        <tr>
                            <th scope="row"><a href="/portal/admin/news/desk/runs/<?= (int) $r['id'] ?>/"><?= $at($r['started_at'] ?? $r['created_at']) ?><span class="u-visually-hidden"> (run <?= (int) $r['id'] ?>)</span></a></th>
                            <td data-label="Kind"><?= $r['kind'] === 'manual' ? 'By hand' : 'Scheduled' ?></td>
                            <td data-label="Status"><?= $this->render('admin/news/desk/_status', ['status' => (string) $r['status']]) ?></td>
                            <td class="pt-num" data-label="Articles"><?= (int) $r['articles'] ?></td>
                            <td class="pt-num" data-label="Held"><?= (int) $r['held'] ?></td>
                            <td class="pt-num" data-label="Cost"><?= e($usd((int) $r['cost_micros'] / 1000000)) ?></td>
                            <td data-label="Summary"><?= e((string) ($r['summary'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
