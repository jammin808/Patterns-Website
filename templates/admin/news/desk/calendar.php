<?php
/**
 * The desk's calendar (docs/NEWS.md §11 Desk): one table per month for three months, a column per weekday. Each day
 * still to come is a checkbox labelled with its full date: ticked, its sessions run. The form posts the months it
 * drew, and a save changes only their days, however long the page was open. A day that differs from its
 * weekday's sessions is marked Changed (an icon and the word); turning a day on runs every session that day. Each
 * month can go back to the weekly pattern with Reset this month. Below 40rem each week stacks as a list of the days
 * still to come.
 *
 * @var list<array{key: string, name: string, weeks: list<list<array<string, mixed>|null>>}> $months
 * @var string $from the first month shown (YYYY-MM)
 * @var string|null $earlier the first month of the three before, if they can be shown
 * @var string|null $later the first month of the three after, if they can be shown
 * @var array<int, int> $perDay sessions by weekday
 */
use Psc\News\Schedule;

$short = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$weekly = [];
foreach ($perDay as $day => $n) {
    $weekly[] = Schedule::DAYS[$day] . ' ' . $n;
}
?>
<header class="pt-header">
    <p class="pt-eyebrow">Industry Updates</p>
    <h1 class="pt-title">Desk calendar</h1>
    <p class="pt-lede">Tick the days the desk works. The weekly pattern comes from the session times in the settings (<?= e(implode(', ', $weekly)) ?>); here you change single days.</p>
</header>

<?= $this->render('admin/news/desk/_nav', ['current' => 'calendar']) ?>

<form method="post" action="/portal/admin/news/desk/calendar/" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="from" value="<?= e($from) ?>">
    <?php foreach ($months as $month): ?>
        <input type="hidden" name="months[]" value="<?= e($month['key']) ?>">
    <?php endforeach; ?>
    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--primary" data-busy="Saving…">Save the calendar</button>
        <span class="pt-small pt-muted">A day turned on runs every session; a day turned off runs none.</span>
    </div>

    <?php foreach ($months as $month): ?>
        <section class="pt-section" aria-labelledby="desk-month-<?= e($month['key']) ?>">
            <div class="pt-table-wrap">
                <table class="pt-table pt-table--stack pt-desk-cal">
                    <caption id="desk-month-<?= e($month['key']) ?>"><?= e($month['name']) ?></caption>
                    <thead>
                        <tr>
                            <?php foreach ($short as $day => $abbr): ?>
                                <th scope="col"><abbr title="<?= e(Schedule::DAYS[$day]) ?>"><?= e($abbr) ?></abbr></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($month['weeks'] as $week):
                            $gone = array_filter($week, static fn (?array $d): bool => $d !== null && !$d['past']) === []; ?>
                            <tr<?= $gone ? ' class="pt-desk-cal-gone"' : '' ?>>
                                <?php foreach ($week as $i => $day):
                                    $label = $short[$i + 1]; ?>
                                    <?php if ($day === null): ?>
                                        <td class="pt-desk-cal-blank" data-label="<?= e($label) ?>"></td>
                                    <?php elseif ($day['past']): ?>
                                        <td class="pt-desk-cal-past" data-label="<?= e($label) ?>">
                                            <span class="pt-desk-cal-num" aria-hidden="true"><?= (int) $day['day'] ?></span><span class="u-visually-hidden"><?= e($day['label']) ?>, passed</span>
                                        </td>
                                    <?php else:
                                        $id = 'desk-day-' . $day['date']; ?>
                                        <td<?= $day['changed'] ? ' class="pt-desk-cal-changed"' : '' ?> data-label="<?= e($label) ?>">
                                            <span class="pt-desk-cal-day">
                                                <input type="checkbox" id="<?= e($id) ?>" name="days[]" value="<?= e($day['date']) ?>"<?= $day['on'] ? ' checked' : '' ?> aria-describedby="<?= e($id) ?>-meta">
                                                <label for="<?= e($id) ?>"><span class="pt-desk-cal-num" aria-hidden="true"><?= (int) $day['day'] ?></span><span class="u-visually-hidden"><?= e($day['label']) ?></span></label>
                                            </span>
                                            <span class="pt-desk-cal-meta" id="<?= e($id) ?>-meta">
                                                <?= match ((int) $day['sessions']) { 0 => 'No sessions', 1 => '1 session', default => (int) $day['sessions'] . ' sessions' } ?>
                                                <?php if ($day['changed']): ?><span class="pt-desk-cal-mark"><?= icon('edit') ?>Changed</span><?php endif; ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="pt-actions">
                <button type="submit" class="pt-btn pt-btn--secondary pt-btn--small" name="reset" value="<?= e($month['key']) ?>">Reset this month<span class="u-visually-hidden">: <?= e($month['name']) ?></span></button>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="pt-actions">
        <button type="submit" class="pt-btn pt-btn--primary" data-busy="Saving…">Save the calendar</button>
    </div>
</form>

<?php if ($earlier !== null || $later !== null): ?>
    <nav class="pt-pager" aria-label="Months">
        <?php if ($earlier !== null): ?><a rel="prev" href="/portal/admin/news/desk/calendar/<?= $earlier !== date('Y-m') ? '?from=' . e($earlier) : '' ?>">Earlier months</a><?php endif; ?>
        <span class="pt-pager-info"><?= e($months[0]['name']) ?> to <?= e($months[count($months) - 1]['name']) ?></span>
        <?php if ($later !== null): ?><a rel="next" href="/portal/admin/news/desk/calendar/?from=<?= e($later) ?>">Later months</a><?php endif; ?>
    </nav>
<?php endif; ?>
