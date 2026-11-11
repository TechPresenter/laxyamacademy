<?php
// Month calendar of live classes (IST).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';

require_staff_page();
require_permission('live.manage');
$month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$first = strtotime($month . '-01');
$daysInMonth = (int) date('t', $first);
$lead = ((int) date('N', $first)) - 1; // Monday-first grid
$classes = db_all("SELECT lc.id, lc.title, lc.starts_at, lc.duration_minutes, lc.status, lc.meeting_url, i.name AS instructor
    FROM live_classes lc LEFT JOIN instructors i ON i.id = lc.instructor_id
    WHERE lc.starts_at >= ? AND lc.starts_at < ? + INTERVAL 1 MONTH ORDER BY lc.starts_at", [$month . '-01', $month . '-01']);
$byDay = [];
foreach ($classes as $c) {
    $byDay[(int) date('j', strtotime($c['starts_at']))][] = $c;
}
$prev = date('Y-m', strtotime('-1 month', $first));
$next = date('Y-m', strtotime('+1 month', $first));
$n = count($classes);
$trail = (7 - (($lead + $daysInMonth) % 7)) % 7; // empty cells that complete the last week

admin_start('Class Calendar', null, 'live', ['head' => true, 'subtitle' => ($n ? $n . ' class' . ($n === 1 ? '' : 'es') : 'No classes') . ' in ' . date('F Y', $first) . ' · times in IST',
    'actions' => can('live.manage') ? '<a class="btn btn-primary" href="live-classes.php?action=new">' . icon('plus') . ' Schedule class</a>' : '']);
?>
<section class="card" aria-labelledby="cal-title">
  <div class="d1-cal-head">
    <h2 id="cal-title"><?= e(date('F Y', $first)) ?></h2>
    <nav class="d1-seg" aria-label="Change month">
      <a href="?month=<?= e($prev) ?>" aria-label="Previous month (<?= e(date('F Y', strtotime($prev . '-01'))) ?>)"><?= icon('left') ?> <?= e(date('M', strtotime($prev . '-01'))) ?></a>
      <a href="?month=<?= e(date('Y-m')) ?>"<?= $month === date('Y-m') ? ' aria-current="date"' : '' ?>>Today</a>
      <a href="?month=<?= e($next) ?>" aria-label="Next month (<?= e(date('F Y', strtotime($next . '-01'))) ?>)"><?= e(date('M', strtotime($next . '-01'))) ?> <?= icon('right') ?></a>
    </nav>
  </div>
  <div class="d1-cal">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><div class="dow" aria-hidden="true"><?= $d ?></div><?php endforeach; ?>
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="off" aria-hidden="true"></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
        $date = sprintf('%s-%02d', $month, $d);
        $isToday = date('Y-m-d') === $date; ?>
    <div class="<?= $isToday ? 'today' : '' ?><?= empty($byDay[$d]) ? ' empty-day' : '' ?>">
      <span class="day-n"<?= $isToday ? ' aria-current="date"' : '' ?>><?= $d ?></span><span class="dname"><?= e(date('l, j F', strtotime($date))) ?></span>
      <?php foreach ($byDay[$d] ?? [] as $c): ?>
        <a class="ev <?= e($c['status']) ?><?= $c['meeting_url'] ? '' : ' nolink' ?>" href="live-classes.php?<?= strtotime($c['starts_at']) + 60 * (int) $c['duration_minutes'] <= time() ? 'when=past&amp;' : '' ?>action=edit&amp;id=<?= (int) $c['id'] ?>" title="<?= e($c['title'] . ($c['instructor'] ? ' · ' . $c['instructor'] : '') . ($c['meeting_url'] ? '' : ' · meeting link missing') . ($c['status'] === 'cancelled' ? ' · cancelled' : '')) ?>"><strong><?= e(date('g:i A', strtotime($c['starts_at']))) ?> · <?= (int) $c['duration_minutes'] ?> min</strong><?= e($c['title']) ?><?= $c['status'] === 'cancelled' ? '<span class="sr-only"> (cancelled)</span>' : '' ?><?= $c['meeting_url'] ? '' : '<span class="sr-only"> (meeting link missing)</span>' ?></a>
      <?php endforeach; ?>
    </div>
    <?php endfor; ?>
    <?php for ($i = 0; $i < $trail; $i++): ?><div class="off" aria-hidden="true"></div><?php endfor; ?>
  </div>
  <?php if (!$n): ?>
  <div class="empty" style="border-top:1px solid var(--line)"><span class="empty-ic"><?= icon('calendar') ?></span><strong>No classes in <?= e(date('F', $first)) ?></strong><p>Scheduled live classes appear on their day. Use the arrows to look at other months.</p></div>
  <?php endif; ?>
</section>
<div class="d1-legend" aria-hidden="true">
  <span><i style="border-color:var(--primary);background:var(--primary-soft)"></i>Scheduled</span>
  <span><i style="border-color:var(--amber);background:var(--primary-soft)"></i>Meeting link not added yet</span>
  <span><i style="border-color:#94a3b8;background:var(--line-2)"></i>Completed</span>
  <span><i style="border-color:var(--red);background:var(--red-soft)"></i>Cancelled</span>
</div>
<?php admin_end();
