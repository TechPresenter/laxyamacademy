<?php
// Course preview inside the admin panel: the real course page (drafts too) in a desktop or mobile
// frame, as a visitor or with full (enrolled) access, next to a "ready to publish?" checklist.
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/catalog.php';

$me = require_staff_page();
require_permission('courses.view');
$id = (int) ($_GET['id'] ?? 0);
$course = db_one('SELECT c.*, cat.name AS category_name, i.name AS instructor_name FROM courses c
    JOIN course_categories cat ON cat.id = c.category_id LEFT JOIN instructors i ON i.id = c.instructor_id WHERE c.id = ?', [$id]);
if (!$course) {
    flash('error', 'Course not found.');
    redirect_to('courses.php');
}
$view = ($_GET['view'] ?? '') === 'mobile' ? 'mobile' : 'desktop';
$mode = ($_GET['mode'] ?? '') === 'enrolled' ? 'enrolled' : 'visitor';
// Staff open drafts/archived courses on the site; ?as=student shows what a visitor who has not enrolled sees.
$src = app_url('courses/' . $course['slug']) . ($mode === 'visitor' ? '?as=student' : '');
$link = fn(array $change) => '?' . http_build_query(['id' => $id, 'view' => $view, 'mode' => $mode] + $change);

$lessons = (int) db_value("SELECT COUNT(*) FROM lessons WHERE course_id = ? AND status = 'published'", [$id]);
$previews = (int) db_value("SELECT COUNT(*) FROM lessons WHERE course_id = ? AND status = 'published' AND is_preview = 1", [$id]);
$students = (int) db_value("SELECT COUNT(*) FROM course_enrolments WHERE course_id = ? AND status = 'active'", [$id]);
$price = course_effective_price($course); // the sale price only while the offer window is open
$checks = [
    ['Status', $course['status'] === 'published' ? 'ok' : 'warn', match ($course['status']) {
        'published' => 'Published - visible on the website.',
        'archived' => 'Archived - hidden from the website and closed to new admissions; enrolled students keep access.',
        default => 'Draft - only staff can see this page. Publish it when it is ready.',
    }],
    ['Thumbnail', $course['thumbnail_path'] ? 'ok' : 'warn', $course['thumbnail_path'] ? 'Uploaded.' : 'No image yet - course cards show a coloured placeholder.'],
    ['Short description', trim((string) $course['short_description']) !== '' ? 'ok' : 'warn', trim((string) $course['short_description']) !== '' ? 'Shown on course cards and search results.' : 'Add 1-2 lines for the course cards.'],
    ['Price', $course['course_type'] === 'free' ? 'ok' : ((float) $price > 0 ? 'ok' : 'fail'), $course['course_type'] === 'free' ? 'Free course.' : ((float) $price > 0 ? money((float) $price) . ((float) $price < (float) $course['price'] ? ' (sale, list ' . money((float) $course['price']) . ')' : '') : 'Paid course without a price.')],
    // Classroom courses teach at the centre: online lessons are optional for them.
    ['Lessons', $lessons ? 'ok' : ($course['course_type'] === 'offline' ? 'info' : 'warn'), $lessons ? $lessons . ' published lesson' . ($lessons === 1 ? '' : 's') . '.' : ($course['course_type'] === 'offline' ? 'No online lessons - fine for an offline classroom course.' : 'No published lessons yet - add them in Curriculum.')],
    ['Free preview', $previews ? 'ok' : ($course['course_type'] === 'offline' ? 'info' : 'warn'), $previews ? $previews . ' lesson' . ($previews === 1 ? '' : 's') . ' open to everyone.' : ($course['course_type'] === 'offline' ? 'No preview lessons (offline course).' : 'Mark 1-2 lessons as free preview to help students decide.')],
    ['Faculty', $course['instructor_name'] ? 'ok' : 'warn', $course['instructor_name'] ? (string) $course['instructor_name'] : 'No faculty assigned.'],
    ['SEO', trim((string) ($course['seo_description'] ?: $course['short_description'])) !== '' ? 'ok' : 'warn', trim((string) $course['seo_description']) !== '' ? 'Custom SEO description set.' : 'Uses the short description for Google.'],
];
if (course_extras_enabled()) {
    $subjects = course_syllabus($course['syllabus'] ?? null);
    $checks[] = ['Syllabus', $subjects ? 'ok' : ($lessons ? 'info' : 'warn'), $subjects
        ? count($subjects) . ' subject' . (count($subjects) === 1 ? '' : 's') . ', ' . array_sum(array_map(fn($s) => count($s['topics']), $subjects)) . ' topics.'
        : 'No subject-wise syllabus yet - add it on the course form.'];
    $checks[] = ['Admission', 'info', course_enquiry_only($course) ? 'Through the Apply Now form (no online payment).' : 'Online checkout.'];
}
if (course_batches_enabled()) {
    $checks[] = ['Batch', $course['academic_session'] ? 'ok' : 'info', $course['academic_session'] ? 'Session ' . $course['academic_session'] . ' - admissions counted for it from day one.' : 'Not tied to an academic session.'];
}
$todo = count(array_filter($checks, fn($c) => $c[1] === 'warn' || $c[1] === 'fail'));
$stateIcon = ['ok' => 'check', 'warn' => 'alert', 'fail' => 'alert', 'info' => 'help'];

admin_start('Preview · ' . mb_strimwidth($course['title'], 0, 70, '…'), null, 'courses', [
    'head' => true,
    'crumbs' => '<a href="courses.php">Courses</a> / Preview',
    'subtitle' => $course['category_name'] . ' · ' . ucfirst($course['status']) . ' · ' . number_format($students) . ' active student' . ($students === 1 ? '' : 's'),
    'actions' => (can('courses.manage') ? '<a class="btn btn-primary" href="courses.php?action=edit&amp;id=' . $id . '">' . icon('edit') . ' Edit course</a>' : '')
        . (can('lessons.manage') ? '<a class="btn btn-light" href="lessons.php?course=' . $id . '">' . icon('book') . ' Curriculum</a>' : '')
        . '<a class="btn btn-light" href="' . e($src) . '" target="_blank" rel="noopener">' . icon('external') . ' Open on website</a>'
        . '<a class="btn btn-light" href="courses.php">All courses</a>',
]);
?>
<div class="cp-layout">
  <section class="card" aria-label="Course page preview">
    <div class="cp-bar">
      <div class="cp-seg" role="group" aria-label="Screen size">
        <a href="<?= e($link(['view' => 'desktop'])) ?>"<?= $view === 'desktop' ? ' class="on" aria-current="true"' : '' ?>>Desktop</a>
        <a href="<?= e($link(['view' => 'mobile'])) ?>"<?= $view === 'mobile' ? ' class="on" aria-current="true"' : '' ?>>Mobile</a>
      </div>
      <div class="cp-seg" role="group" aria-label="Who is looking">
        <a href="<?= e($link(['mode' => 'visitor'])) ?>"<?= $mode === 'visitor' ? ' class="on" aria-current="true"' : '' ?> title="What a student who has not enrolled sees (locked lessons, buy button)">Visitor view</a>
        <a href="<?= e($link(['mode' => 'enrolled'])) ?>"<?= $mode === 'enrolled' ? ' class="on" aria-current="true"' : '' ?> title="All lessons unlocked, as for an enrolled student">Enrolled view</a>
      </div>
      <button type="button" class="btn btn-light btn-sm" onclick="document.getElementById('cp-frame').src = document.getElementById('cp-frame').src"><?= icon('history') ?> Reload</button>
    </div>
    <div class="cp-stage <?= $view ?>">
      <?php $frame = '<iframe id="cp-frame" src="' . e($src) . '" title="Preview of ' . e($course['title']) . ' (' . $view . ', ' . $mode . ' view)" loading="eager"></iframe>'; ?>
      <?= $view === 'desktop' ? '<div class="cp-scale">' . $frame . '</div>' : $frame ?>
    </div>
  </section>
  <aside class="card" aria-label="Ready to publish checklist">
    <div class="card-head"><h2><?= icon('check') ?> Ready to publish?</h2><span class="badge <?= $todo ? 'b-amber' : 'b-green' ?>"><?= $todo ? $todo . ' to check' : 'All good' ?></span></div>
    <ul class="cp-checks">
      <?php foreach ($checks as [$label, $state, $text]): ?>
      <li><span class="st <?= $state ?>" aria-hidden="true"><?= icon($stateIcon[$state]) ?></span><span><strong><?= e($label) ?></strong><small><?= e($text) ?></small></span></li>
      <?php endforeach; ?>
    </ul>
  </aside>
</div>
<script>
// Desktop view: render the page at a real 1280px desktop width and scale it down to fit the card.
(function () {
  var box = document.querySelector('.cp-scale');
  if (!box) return;
  var frame = box.querySelector('iframe');
  function fit() {
    var s = Math.min(1, box.clientWidth / 1280);
    frame.style.transform = 'scale(' + s + ')';
    box.style.height = Math.round(1600 * s) + 'px';
  }
  fit();
  window.addEventListener('resize', fit);
})();
</script>
<?php admin_end();
