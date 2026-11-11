<?php
// Question bank for a mock test / practice quiz: questions with options, correct answers,
// explanations and images; side-by-side live preview, "paste many questions", CSV import,
// reuse from other tests and answer-key export. Every question can carry a Hindi version, and
// questions can be grouped into sections (optionally each with its own timer).
// Once students have attempted a test, questions cannot be added, removed or re-marked and
// sections can only be renamed; wording (both languages), images, explanations and the answer
// key itself can still be corrected (then re-score).
declare(strict_types=1);

require __DIR__ . '/../includes/admin-header.php';
require_once APP_ROOT . '/includes/exams.php';
require_once APP_ROOT . '/includes/question-paste.php';

$me = require_staff_page();
require_permission('exams.manage');
$examId = (int) ($_GET['exam'] ?? 0);
$exam = $examId ? db_one('SELECT * FROM exams WHERE id = ?', [$examId]) : null;
$hasAttempts = $exam && db_value('SELECT 1 FROM exam_attempts WHERE exam_id = ? LIMIT 1', [$examId]);
$finished = $exam ? (int) db_value("SELECT COUNT(*) FROM exam_attempts WHERE exam_id = ? AND status <> 'in_progress'", [$examId]) : 0;
$v11 = exam_v11(); // Hindi versions + sections need database update 011
$sections = $exam ? exam_sections_of($examId) : [];
$sectionIds = array_map(fn($s) => (int) $s['id'], $sections);
const QUESTIONS_LOCKED = 'Students have attempted this test, so questions cannot be added, removed or re-marked (existing results would change). Duplicate the test from Mock Tests to make a new version.';
const SECTIONS_LOCKED = 'Students have attempted this test, so sections can only be renamed (their timers and questions are part of the existing results).';
const OPTION_SLOTS = 6;

/** Options from the form's slots, keeping each slot's tick (and Hindi text) with its own text. Returns [options, error]. */
function question_options_from_post(string $type): array
{
    $texts = array_values((array) ($_POST['options'] ?? []));
    $textsHi = array_values((array) ($_POST['options_hi'] ?? []));
    $ticked = array_map('intval', (array) ($_POST['correct'] ?? []));
    if ($type === 'true_false') {
        return [[['text' => 'True', 'text_hi' => PASTE_TRUE_FALSE_HI[0], 'correct' => in_array(0, $ticked, true)], ['text' => 'False', 'text_hi' => PASTE_TRUE_FALSE_HI[1], 'correct' => in_array(1, $ticked, true)]], null];
    }
    $options = [];
    for ($slot = 0; $slot < OPTION_SLOTS; $slot++) {
        $text = trim((string) ($texts[$slot] ?? ''));
        if ($text === '') {
            if (in_array($slot, $ticked, true)) {
                return [[], 'Option ' . chr(65 + $slot) . ' is ticked as correct but has no text.'];
            }
            continue; // blank slots are skipped; the tick mapping above stays per slot
        }
        $hi = trim((string) ($textsHi[$slot] ?? ''));
        $options[] = ['text' => mb_substr($text, 0, 2000), 'text_hi' => $hi !== '' ? mb_substr($hi, 0, 2000) : null, 'correct' => in_array($slot, $ticked, true)];
    }
    return [$options, null];
}

/** Sectional timing: the test time is the sum of the section timers (once every section has one). */
function sync_section_duration(int $examId): void
{
    $rows = exam_sections_of($examId);
    if ($rows && !array_filter($rows, fn($s) => (int) $s['duration_minutes'] <= 0)) {
        db_query('UPDATE exams SET duration_minutes = ? WHERE id = ? AND sectional_timing = 1', [min(600, array_sum(array_column($rows, 'duration_minutes'))), $examId]);
    }
}

/** Section id for a pasted / imported section name: an existing one (English or Hindi title, any case) or a new section. */
function section_id_for(int $examId, string $name, array &$sections): int
{
    $key = mb_strtolower(trim($name));
    foreach ($sections as $s) {
        if (mb_strtolower($s['title']) === $key || mb_strtolower((string) $s['title_hi']) === $key) {
            return (int) $s['id'];
        }
    }
    $order = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM exam_sections WHERE exam_id = ?', [$examId]);
    db_query('INSERT INTO exam_sections (exam_id, title, sort_order) VALUES (?, ?, ?)', [$examId, mb_substr(trim($name), 0, 80), $order]);
    $sections[] = ['id' => (int) db()->lastInsertId(), 'title' => mb_substr(trim($name), 0, 80), 'title_hi' => null, 'duration_minutes' => null];
    return (int) $sections[count($sections) - 1]['id'];
}

/** "1 question" / "3 questions". */
function q_count_label(int $n, string $noun): string
{
    return number_format($n) . ' ' . $noun . ($n === 1 ? '' : 's');
}

$formError = null;
$formValues = null; // re-filled into the form after a validation error

if ($exam && admin_post()) {
    $act = $_POST['_action'] ?? '';
    // Sections: add / rename / time, delete, reorder, put a range of questions into one, timing on/off.
    if (in_array($act, ['section_save', 'section_delete', 'section_move', 'section_assign', 'section_timing'], true)) {
        $back = '?exam=' . $examId . '#sections';
        $sid = (int) ($_POST['section_id'] ?? 0);
        $row = $sid ? (current(array_filter($sections, fn($s) => (int) $s['id'] === $sid)) ?: null) : null;
        if (!$v11) {
            flash('error', 'Apply the latest database update first (System → Database Updates).');
            redirect_to($back);
        }
        if ($act === 'section_save') {
            $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 80);
            $titleHi = mb_substr(trim((string) ($_POST['title_hi'] ?? '')), 0, 80) ?: null;
            $minutes = trim((string) ($_POST['minutes'] ?? '')) === '' ? null : (int) $_POST['minutes'];
            $sameTime = $row && $minutes === ($row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null);
            $error = match (true) {
                $sid && !$row => 'Section not found.',
                $title === '' => 'Give the section a name, e.g. Quantitative Aptitude.',
                $minutes !== null && ($minutes < 1 || $minutes > 600) => 'Section time must be 1-600 minutes (or blank).',
                $hasAttempts && !$sameTime => SECTIONS_LOCKED,
                default => null,
            };
            if ($error) {
                flash('error', $error);
                redirect_to($back);
            }
            if ($row) {
                db_query('UPDATE exam_sections SET title = ?, title_hi = ?, duration_minutes = ? WHERE id = ? AND exam_id = ?', [$title, $titleHi, $minutes, $sid, $examId]);
            } else {
                db_query('INSERT INTO exam_sections (exam_id, title, title_hi, duration_minutes, sort_order) VALUES (?, ?, ?, ?, ?)', [$examId, $title, $titleHi, $minutes, count($sections) + 1]);
            }
            sync_section_duration($examId);
            audit_log('exam_section_saved', 'exams', $examId, ['section' => $title, 'minutes' => $minutes]);
            flash('success', 'Section “' . $title . '” saved.');
            redirect_to($back);
        }
        if ($hasAttempts) {
            flash('error', SECTIONS_LOCKED);
            redirect_to($back);
        }
        if ($act === 'section_delete' && $row) {
            db_query('DELETE FROM exam_sections WHERE id = ? AND exam_id = ?', [$sid, $examId]); // its questions keep going, without a section
            if (count($sections) === 1) {
                db_query('UPDATE exams SET sectional_timing = 0 WHERE id = ?', [$examId]);
            }
            sync_section_duration($examId);
            audit_log('exam_section_deleted', 'exams', $examId, ['section' => $row['title']]);
            flash('success', 'Section “' . $row['title'] . '” deleted. Its questions are kept without a section.');
        } elseif ($act === 'section_move' && $row) {
            $i = array_search($sid, $sectionIds, true);
            $j = $i + ((int) ($_POST['dir'] ?? 0) < 0 ? -1 : 1);
            if (isset($sectionIds[$j])) {
                $order = $sectionIds;
                [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
                foreach ($order as $n => $id) {
                    db_query('UPDATE exam_sections SET sort_order = ? WHERE id = ? AND exam_id = ?', [$n + 1, $id, $examId]);
                }
            }
        } elseif ($act === 'section_assign') {
            $ids = array_map('intval', array_column(db_all('SELECT id FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id', [$examId]), 'id'));
            $from = max(1, (int) ($_POST['from'] ?? 1));
            $to = min(count($ids), (int) ($_POST['to'] ?? 0));
            if (!$row && $sid !== 0) {
                flash('error', 'Choose a section.');
            } elseif ($from > $to) {
                flash('error', 'Enter a question range such as 1 to 25 (this test has ' . count($ids) . ' questions).');
            } else {
                $pick = array_slice($ids, $from - 1, $to - $from + 1);
                db_query('UPDATE exam_questions SET section_id = ? WHERE exam_id = ? AND id IN (' . db_placeholders(count($pick)) . ')', [$row ? $sid : null, $examId, ...$pick]);
                audit_log('exam_section_assigned', 'exams', $examId, ['from' => $from, 'to' => $to, 'section' => $row['title'] ?? null]);
                flash('success', 'Q' . $from . '–Q' . $to . ($row ? ' are now in “' . $row['title'] . '”.' : ' no longer have a section.'));
            }
        } elseif ($act === 'section_timing') {
            $on = !empty($_POST['on']);
            $untimed = array_filter($sections, fn($s) => (int) $s['duration_minutes'] <= 0);
            if ($on && exam_is_practice($exam)) {
                flash('error', 'Practice quizzes are untimed, so sections cannot have timers.');
            } elseif ($on && (!$sections || $untimed)) {
                flash('error', !$sections ? 'Add sections first.' : 'Give every section its minutes first (' . implode(', ', array_column($untimed, 'title')) . ').');
            } else {
                db_query('UPDATE exams SET sectional_timing = ? WHERE id = ?', [$on ? 1 : 0, $examId]);
                sync_section_duration($examId);
                audit_log('exam_sectional_timing', 'exams', $examId, ['on' => $on]);
                flash('success', $on ? 'Sectional timing is on: each section runs on its own timer and the test time is their total.' : 'Sectional timing is off: one timer for the whole test.');
            }
        }
        redirect_to($back);
    }
    // Paste many questions: "paste_parse" answers the live preview (JSON); "paste_import" re-parses
    // on the server and adds them only when every question is valid (same rule as CSV import).
    // An optional Hindi version is pasted in its own box, in the same order.
    if ($act === 'paste_parse' || $act === 'paste_import') {
        $text = (string) ($_POST['paste'] ?? '');
        $textHi = $v11 ? (string) ($_POST['paste_hi'] ?? '') : '';
        $defSection = in_array((int) ($_POST['def_section'] ?? 0), $sectionIds, true) ? (int) $_POST['def_section'] : 0;
        $error = match (true) {
            (bool) $hasAttempts => QUESTIONS_LOCKED,
            strlen($text) > PASTE_MAX_BYTES || strlen($textHi) > PASTE_MAX_BYTES => 'That is too much text at once - paste up to ' . PASTE_MAX_QUESTIONS . ' questions per batch.',
            default => null,
        };
        $parsed = $error ? [] : parse_pasted_questions($text, (float) ($_POST['def_marks'] ?? 1), max(0, (float) ($_POST['def_negative'] ?? 0)),
            mb_substr(trim((string) ($_POST['def_topic'] ?? '')), 0, 80) ?: null, (bool) $exam['allow_multiple_correct']);
        [$parsed, $extraHi] = paste_attach_hindi($parsed, trim($textHi) !== '' && !$error ? parse_pasted_questions($textHi, 1, 0, null, true, true) : []);
        $defTitle = $defSection ? current(array_filter($sections, fn($s) => (int) $s['id'] === $defSection))['title'] : null;
        $hiError = $extraHi ? 'The Hindi box has ' . q_count_label($extraHi, 'more question') . ' than the English box - both must list the same questions in the same order.' : null;
        if ($act === 'paste_parse') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => $error === null, 'error' => $error ?? $hiError, 'existing' => (int) db_value('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?', [$examId]),
                'questions' => array_map(fn($q) => ['section' => $q['section'] ?? $defTitle, 'negative_effective' => exam_negative_for($exam, ['negative_marks' => $q['negative']])] + $q, $parsed)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $bad = array_values(array_filter($parsed, fn($q) => $q['errors']));
        if ($error || $hiError || !$parsed || $bad) {
            $_SESSION['paste_draft'] = mb_substr($text, 0, PASTE_MAX_BYTES);
            $_SESSION['paste_draft_hi'] = mb_substr($textHi, 0, PASTE_MAX_BYTES);
            flash('error', $error ?? $hiError ?? (!$parsed ? 'Paste at least one question.' : 'Nothing was added. Fix ' . q_count_label(count($bad), 'question') . ' first: '
                . implode('; ', array_map(fn($q) => 'Q' . $q['n'] . ' - ' . $q['errors'][0], array_slice($bad, 0, 5))) . (count($bad) > 5 ? ' …' : '')));
            redirect_to('?exam=' . $examId . '&paste=1');
        }
        foreach ($parsed as &$pq) {
            $pq['section_id'] = $pq['section'] !== null && $v11 ? section_id_for($examId, $pq['section'], $sections) : ($defSection ?: null);
        }
        unset($pq);
        $added = insert_exam_questions($examId, $parsed);
        sync_section_duration($examId);
        unset($_SESSION['paste_draft'], $_SESSION['paste_draft_hi']);
        audit_log('questions_pasted', 'exams', $examId, ['count' => $added]);
        flash('success', q_count_label($added, 'question') . ' added. Check them in the list below.');
        redirect_to('?exam=' . $examId);
    }
    if (in_array($act, ['delete', 'import', 'import_bank', 'sync_total'], true) && $hasAttempts) {
        flash('error', QUESTIONS_LOCKED);
        redirect_to('?exam=' . $examId);
    }
    if ($act === 'delete') {
        db_query('DELETE FROM exam_questions WHERE id = ? AND exam_id = ?', [(int) $_POST['id'], $examId]);
        audit_log('question_deleted', 'exam_questions', (int) $_POST['id'], ['exam' => $examId]);
        flash('success', 'Question deleted.');
        redirect_to('?exam=' . $examId);
    }
    if ($act === 'import') {
        $file = $_FILES['csv'] ?? null;
        if (!uploaded_file_ok($file) || $file['size'] > 2 * 1_048_576) {
            flash('error', 'Choose a CSV file up to 2 MB.');
            redirect_to('?exam=' . $examId . '&import=1');
        }
        $fh = fopen($file['tmp_name'], 'r');
        $head = array_map(fn($h) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h))), fgetcsv($fh, 0, ',', '"', '\\') ?: []);
        if (array_diff(['question', 'option_a', 'option_b', 'correct'], $head)) {
            flash('error', 'CSV must have columns: question, type, topic, option_a … option_f, correct (e.g. B or A;C), marks, negative_marks, explanation. Download the template to start.');
            redirect_to('?exam=' . $examId . '&import=1');
        }
        $rows = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $line++;
            if (count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $r = array_combine($head, array_pad(array_slice($cells, 0, count($head)), count($head), ''));
            $type = in_array(strtolower(trim($r['type'] ?? '')), ['single', 'true_false', 'multiple'], true) ? strtolower(trim($r['type'])) : 'single';
            // Map each option letter to its position among the non-blank options, so the
            // correct letters keep pointing at the right text when a middle column is blank.
            $options = [];
            $slotOf = [];
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $letter) {
                $text = trim((string) ($r['option_' . strtolower($letter)] ?? ''));
                if ($text !== '') {
                    $slotOf[$letter] = count($options);
                    $hi = trim((string) ($r['option_' . strtolower($letter) . '_hi'] ?? ''));
                    $options[] = ['text' => mb_substr($text, 0, 2000), 'text_hi' => $hi !== '' ? mb_substr($hi, 0, 2000) : null, 'correct' => false];
                }
            }
            if ($type === 'true_false' && !$options) {
                $options = [['text' => 'True', 'text_hi' => PASTE_TRUE_FALSE_HI[0], 'correct' => false], ['text' => 'False', 'text_hi' => PASTE_TRUE_FALSE_HI[1], 'correct' => false]];
                $slotOf = ['A' => 0, 'B' => 1];
            }
            $letters = array_values(array_unique(array_filter(array_map(fn($x) => strtoupper(trim($x)), preg_split('/[;,\s]+/', (string) $r['correct'])), fn($x) => $x !== '')));
            if ($type === 'true_false') {
                $letters = array_map(fn($x) => ['TRUE' => 'A', 'FALSE' => 'B'][$x] ?? $x, $letters);
            }
            $missing = array_diff($letters, array_keys($slotOf));
            foreach ($letters as $letter) {
                if (isset($slotOf[$letter])) {
                    $options[$slotOf[$letter]]['correct'] = true;
                }
            }
            $marks = trim((string) ($r['marks'] ?? '')) === '' ? 1.0 : (float) $r['marks'];
            $err = match (true) {
                trim((string) $r['question']) === '' => 'question is empty',
                !$letters => 'correct is empty (use A, B, … or A;C)',
                (bool) $missing => 'correct names ' . implode(', ', $missing) . ', which is blank or not an option',
                $marks <= 0 => 'marks must be more than 0',
                default => ($k = question_key_error($type, $options, (bool) $exam['allow_multiple_correct'])) ? strtolower(rtrim($k, '.')) : null,
            };
            if ($err) {
                $errors[] = "Row {$line}: {$err}";
                continue;
            }
            $rows[] = ['type' => $type, 'text' => trim($r['question']), 'topic' => trim((string) ($r['topic'] ?? '')) ?: null,
                'explanation' => trim((string) ($r['explanation'] ?? '')) ?: null, 'marks' => $marks, 'negative' => max(0, (float) ($r['negative_marks'] ?? 0)), 'options' => $options,
                'text_hi' => trim((string) ($r['question_hi'] ?? '')) ?: null, 'explanation_hi' => trim((string) ($r['explanation_hi'] ?? '')) ?: null,
                'section' => mb_substr(trim((string) ($r['section'] ?? '')), 0, 80) ?: null];
            if (count($rows) > 500) {
                break;
            }
        }
        fclose($fh);
        if ($errors) {
            flash('error', 'Nothing imported. Fix these rows: ' . implode('; ', array_slice($errors, 0, 8)) . (count($errors) > 8 ? ' …' : ''));
            redirect_to('?exam=' . $examId . '&import=1');
        }
        if (!$rows) {
            flash('error', 'The file has no question rows.');
            redirect_to('?exam=' . $examId . '&import=1');
        }
        foreach ($rows as &$row) {
            $row['section_id'] = $row['section'] !== null && $v11 ? section_id_for($examId, $row['section'], $sections) : null;
        }
        unset($row);
        insert_exam_questions($examId, $rows);
        sync_section_duration($examId);
        audit_log('questions_imported', 'exams', $examId, ['count' => count($rows)]);
        flash('success', q_count_label(count($rows), 'question') . ' imported.');
        redirect_to('?exam=' . $examId);
    }
    if ($act === 'import_bank') {
        $from = (int) ($_POST['from'] ?? 0);
        $src = db_one('SELECT id, title FROM exams WHERE id = ? AND id <> ?', [$from, $examId]);
        $topic = trim((string) ($_POST['topic'] ?? ''));
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['qids'] ?? []))));
        if ($src && !$ids && $topic !== '' && isset($_POST['whole_topic'])) {
            $ids = array_map('intval', array_column(db_all('SELECT id FROM exam_questions WHERE exam_id = ? AND ' . ($topic === '__none' ? 'topic IS NULL' : 'topic = ?') . ' ORDER BY sort_order, id',
                $topic === '__none' ? [$from] : [$from, $topic]), 'id'));
        } elseif ($src && $ids) {
            $ids = array_map('intval', array_column(db_all('SELECT id FROM exam_questions WHERE exam_id = ? AND id IN (' . db_placeholders(count($ids)) . ')', [$from, ...$ids]), 'id'));
        }
        if (!$src || !$ids) {
            flash('error', 'Choose a test and select at least one question (or a whole topic) to copy.');
            redirect_to('?exam=' . $examId . '&bank=1' . ($src ? '&from=' . $from : ''));
        }
        [$copied, $skipped] = exam_copy_questions(array_slice($ids, 0, 500), $examId, (bool) $exam['allow_multiple_correct']);
        audit_log('questions_reused', 'exams', $examId, ['from' => $from, 'copied' => $copied, 'skipped' => $skipped]);
        flash('success', q_count_label($copied, 'question') . " copied from “{$src['title']}”." . ($skipped ? ' ' . q_count_label($skipped, 'multiple-correct question') . ($skipped === 1 ? ' was' : ' were') . ' skipped because this test does not allow them.' : ''));
        redirect_to('?exam=' . $examId);
    }
    if ($act === 'sync_total') {
        db_query('UPDATE exams SET total_marks = (SELECT COALESCE(SUM(marks), 0) FROM exam_questions WHERE exam_id = ?) WHERE id = ?', [$examId, $examId]);
        audit_log('exam_total_synced', 'exams', $examId);
        flash('success', 'Total marks updated to the sum of question marks.');
        redirect_to('?exam=' . $examId);
    }
    if ($act === 'rescore') {
        [$n, $changed] = exam_rescore_exam($examId);
        audit_log('exam_rescored', 'exams', $examId, ['attempts' => $n, 'changed' => $changed], 'warning');
        flash('success', q_count_label((int) $n, 'submitted attempt') . ' re-scored with the current answer key; ' . q_count_label((int) $changed, 'score') . ' changed.');
        redirect_to('?exam=' . $examId);
    }
    if ($act === 'save') {
        $qid = (int) ($_POST['id'] ?? 0);
        $q = $qid ? db_one('SELECT * FROM exam_questions WHERE id = ? AND exam_id = ?', [$qid, $examId]) : null;
        if (($qid && !$q) || (!$qid && $hasAttempts)) {
            flash('error', $qid ? 'Question not found.' : QUESTIONS_LOCKED);
            redirect_to('?exam=' . $examId);
        }
        $text = trim((string) ($_POST['question_text'] ?? ''));
        $topic = mb_substr(trim((string) ($_POST['topic'] ?? '')), 0, 80) ?: null;
        $explanation = trim((string) ($_POST['explanation'] ?? '')) ?: null;
        $sort = (int) ($_POST['sort_order'] ?? 0);
        $correction = $q && $hasAttempts; // key-correction mode: same options, same marks
        $textHi = $v11 ? exam_hi(mb_substr((string) ($_POST['question_text_hi'] ?? ''), 0, 5000)) : null;
        $explanationHi = $v11 ? exam_hi((string) ($_POST['explanation_hi'] ?? '')) : null;
        $sectionId = in_array((int) ($_POST['section_id'] ?? 0), $sectionIds, true) ? (int) $_POST['section_id'] : null;
        if ($correction) {
            $type = $q['question_type'];
            $texts = (array) ($_POST['opt_text'] ?? []);
            $textsHi = (array) ($_POST['opt_text_hi'] ?? []);
            $ticks = array_map('intval', (array) ($_POST['opt_correct'] ?? []));
            $options = [];
            foreach (db_all('SELECT id, is_correct FROM exam_options WHERE question_id = ? ORDER BY sort_order, id', [$qid]) as $o) {
                $options[] = ['id' => (int) $o['id'], 'text' => mb_substr(trim((string) ($texts[$o['id']] ?? '')), 0, 2000), 'text_hi' => exam_hi(mb_substr((string) ($textsHi[$o['id']] ?? ''), 0, 2000)),
                    'correct' => in_array((int) $o['id'], $ticks, true), 'was' => (bool) $o['is_correct']];
            }
            $marks = (float) $q['marks'];
            $neg = (float) $q['negative_marks'];
            $error = match (true) {
                $text === '' => 'Enter the question text.',
                (bool) array_filter($options, fn($o) => $o['text'] === '') => 'Options cannot be removed after students have attempted. Edit their wording instead.',
                default => question_key_error($type, $options, (bool) $exam['allow_multiple_correct'] || $type === 'multiple'),
            };
        } else {
            $type = in_array($_POST['question_type'] ?? '', ['single', 'true_false', 'multiple'], true) ? $_POST['question_type'] : 'single';
            [$options, $optError] = question_options_from_post($type);
            $marks = (float) ($_POST['marks'] ?? 1);
            $neg = max(0, (float) ($_POST['negative_marks'] ?? 0));
            $error = match (true) {
                $text === '' => 'Enter the question text.',
                $optError !== null => $optError,
                $marks <= 0 => 'Marks must be more than 0.',
                default => question_key_error($type, $options, (bool) $exam['allow_multiple_correct']),
            };
        }
        $imagePath = $q['image_path'] ?? null;
        $upload = $_FILES['image'] ?? null;
        if (!$error && uploaded_file_ok($upload)) {
            try {
                $imagePath = store_image_upload($upload, 'questions', 1400, 1400);
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        } elseif (!$error && $upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $error = 'The image could not be uploaded. Use a JPG, PNG, WebP or GIF under 8 MB.';
        } elseif (!$error && !empty($_POST['image_remove'])) {
            $imagePath = null;
        }

        if ($error) {
            $formError = $error;
            $formValues = $_POST;
            $_GET['edit'] = $qid ?: 'new';
        } else {
            $keyChanged = false;
            db_transaction(function () use (&$qid, &$keyChanged, $correction, $examId, $type, $text, $imagePath, $explanation, $marks, $neg, $sort, $topic, $options, $v11, $textHi, $explanationHi, $sectionId) {
                if ($correction) {
                    // Wording in both languages and the key can be corrected; the section stays (it is part of the results).
                    db_query('UPDATE exam_questions SET question_text = ?, image_path = ?, explanation = ?, topic = ?, sort_order = ?' . ($v11 ? ', question_text_hi = ?, explanation_hi = ?' : '') . ' WHERE id = ? AND exam_id = ?',
                        [mb_substr($text, 0, 5000), $imagePath, $explanation, $topic, $sort, ...($v11 ? [$textHi, $explanationHi] : []), $qid, $examId]);
                    foreach ($options as $o) {
                        db_query('UPDATE exam_options SET option_text = ?, is_correct = ?' . ($v11 ? ', option_text_hi = ?' : '') . ' WHERE id = ? AND question_id = ?',
                            [$o['text'], $o['correct'] ? 1 : 0, ...($v11 ? [$o['text_hi']] : []), $o['id'], $qid]);
                        $keyChanged = $keyChanged || $o['correct'] !== $o['was'];
                    }
                    return;
                }
                $vals = [$type, mb_substr($text, 0, 5000), $imagePath, $explanation, $marks, $neg, $sort, $topic];
                $hiVals = $v11 ? [$textHi, $explanationHi, $sectionId] : [];
                if ($qid) {
                    db_query('UPDATE exam_questions SET question_type = ?, question_text = ?, image_path = ?, explanation = ?, marks = ?, negative_marks = ?, sort_order = ?, topic = ?'
                        . ($v11 ? ', question_text_hi = ?, explanation_hi = ?, section_id = ?' : '') . ' WHERE id = ? AND exam_id = ?', [...$vals, ...$hiVals, $qid, $examId]);
                    db_query('DELETE FROM exam_options WHERE question_id = ?', [$qid]);
                } else {
                    db_query('INSERT INTO exam_questions (question_type, question_text, image_path, explanation, marks, negative_marks, sort_order, topic' . ($v11 ? ', question_text_hi, explanation_hi, section_id' : '') . ', exam_id)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?' . ($v11 ? ', ?, ?, ?' : '') . ', ?)', [...$vals, ...$hiVals, $examId]);
                    $qid = (int) db()->lastInsertId();
                }
                foreach ($options as $i => $o) {
                    db_query('INSERT INTO exam_options (question_id, option_text, is_correct, sort_order' . ($v11 ? ', option_text_hi' : '') . ') VALUES (?, ?, ?, ?' . ($v11 ? ', ?' : '') . ')',
                        [$qid, $o['text'], $o['correct'] ? 1 : 0, $i + 1, ...($v11 ? [$o['text_hi'] ?? null] : [])]);
                }
            });
            audit_log($keyChanged ? 'answer_key_corrected' : 'question_saved', 'exam_questions', $qid, ['exam' => $examId], $keyChanged ? 'warning' : 'info');
            flash('success', 'Question saved.');
            if ($keyChanged && $finished) {
                flash('warning', 'Answer key changed. Use “Re-score attempts” at the top of this page so the ' . q_count_label($finished, 'submitted attempt') . ' use the corrected key.');
            }
            redirect_to('?exam=' . $examId . (isset($_POST['add_another']) && !$hasAttempts ? '&edit=new' : ''));
        }
    }
}

// ------------------------------------------------------------------
// No test chosen: pick one
// ------------------------------------------------------------------
if (!$exam) {
    $importing = isset($_GET['import']);
    admin_start($importing ? 'Question Import' : 'Question Bank', null, 'tests', ['head' => true,
        'subtitle' => $importing ? 'Choose the test to import a CSV of questions into (tests that already have attempts are locked).' : 'Choose a test or quiz to manage its questions, answer key and explanations.']);
    $rows = db_all('SELECT e.id, e.title, e.mode, e.status, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS n, EXISTS(SELECT 1 FROM exam_attempts a WHERE a.exam_id = e.id) AS attempted FROM exams e ORDER BY e.id DESC');
    echo '<div class="card">';
    if (!$rows) {
        echo '<div class="empty"><span class="empty-ic">' . icon('clipboard') . '</span><strong>No tests yet</strong><p>Create a mock test or practice quiz first, then add its questions here.</p>'
            . '<a class="btn btn-primary btn-sm" href="mock-tests.php?action=new">' . icon('plus') . ' New test</a></div>';
    } else {
        echo '<div class="table-wrap"><table class="t stack"><thead><tr><th scope="col">Test</th><th scope="col">Type</th><th scope="col">Questions</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $href = '?exam=' . (int) $r['id'] . ($importing ? '&import=1' : '');
            $btn = $importing
                ? ($r['attempted'] ? '<span class="badge b-gray d1-ic" title="Students have attempted this test, so its questions are locked">' . icon('lock', 'i') . ' Locked</span>' : '<a class="btn btn-light btn-sm" href="' . e($href) . '">' . icon('upload') . ' Import CSV</a>')
                : '<a class="btn btn-light btn-sm" href="' . e($href) . '">' . icon('list') . ' Questions</a>';
            echo '<tr><td data-label="Test"><div class="d1-main">' . ($importing && $r['attempted'] ? '<span class="d1-t">' . e($r['title']) . '</span>' : '<a class="d1-t" href="' . e($href) . '">' . e($r['title']) . '</a>') . '</div></td>'
                . '<td data-label="Type">' . ($r['mode'] === 'practice' ? 'Practice quiz' : 'Mock test') . '</td>'
                . '<td data-label="Questions" class="d1-num">' . ((int) $r['n'] ? (int) $r['n'] : '<span class="badge b-amber">None yet</span>') . '</td>'
                . '<td data-label="Status">' . badge($r['status']) . '</td><td class="actions">' . $btn . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    admin_end();
    exit;
}

// ------------------------------------------------------------------
// Downloads: CSV template, answer key (CSV / printable page)
// ------------------------------------------------------------------
const QUESTION_CSV_EXTRA = ['section', 'question_hi', 'option_a_hi', 'option_b_hi', 'option_c_hi', 'option_d_hi', 'option_e_hi', 'option_f_hi', 'explanation_hi'];
if (isset($_GET['sample'])) {
    csv_download('questions-template.csv', ['question', 'type', 'topic', 'option_a', 'option_b', 'option_c', 'option_d', 'option_e', 'option_f', 'correct', 'marks', 'negative_marks', 'explanation', ...QUESTION_CSV_EXTRA], [
        ['Who chaired the Drafting Committee of the Constituent Assembly?', 'single', 'Polity', 'Jawaharlal Nehru', 'Dr B. R. Ambedkar', 'Rajendra Prasad', 'Sardar Patel', '', '', 'B', '2', '0.5', 'Dr Ambedkar chaired the Drafting Committee.',
            'General Studies', 'संविधान सभा की प्रारूप समिति की अध्यक्षता किसने की?', 'जवाहरलाल नेहरू', 'डॉ. बी. आर. आंबेडकर', 'राजेंद्र प्रसाद', 'सरदार पटेल', '', '', 'डॉ. आंबेडकर ने प्रारूप समिति की अध्यक्षता की।'],
        ['The Tropic of Cancer passes through Rajasthan.', 'true_false', 'Geography', 'True', 'False', '', '', '', '', 'A', '1', '0', '', 'General Studies', 'कर्क रेखा राजस्थान से होकर गुजरती है।', '', '', '', '', '', '', ''],
        ['Which of these are Fundamental Duties? (select all that apply)', 'multiple', 'Polity', 'Protect the environment', 'Pay income tax', 'Develop scientific temper', '', '', '', 'A;C', '2', '0', 'Article 51A lists both.', '', '', '', '', '', '', '', '', ''],
    ]);
}
$questions = db_all('SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY sort_order, id', [$examId]);
$opts = [];
foreach (db_all('SELECT o.* FROM exam_options o JOIN exam_questions q ON q.id = o.question_id WHERE q.exam_id = ? ORDER BY o.sort_order, o.id', [$examId]) as $o) {
    $opts[(int) $o['question_id']][] = $o;
}
$letterOf = function (int $qid) use ($opts): string {
    $letters = [];
    foreach ($opts[$qid] ?? [] as $i => $o) {
        if ($o['is_correct']) {
            $letters[] = chr(65 + $i);
        }
    }
    return implode(';', $letters);
};

$sectionTitle = array_column($sections, 'title', 'id');
if (($_GET['answer_key'] ?? '') === 'csv') {
    // Same columns as the import (plus no / image_url), so an exported key can be edited and imported into another test.
    $out = [];
    foreach ($questions as $n => $q) {
        $qo = $opts[(int) $q['id']] ?? [];
        $o = array_map(fn($x) => $x['option_text'], $qo);
        $oHi = array_map(fn($x) => (string) ($x['option_text_hi'] ?? ''), $qo);
        $out[] = [$n + 1, $q['question_text'], $q['question_type'], $q['topic'] ?? '', ...array_pad(array_slice($o, 0, 6), 6, ''),
            $letterOf((int) $q['id']), (float) $q['marks'], exam_negative_for($exam, $q), $q['explanation'] ?? '',
            $sectionTitle[(int) ($q['section_id'] ?? 0)] ?? '', (string) ($q['question_text_hi'] ?? ''), ...array_pad(array_slice($oHi, 0, 6), 6, ''), (string) ($q['explanation_hi'] ?? ''),
            (string) public_file_url($q['image_path'])];
    }
    csv_download('answer-key-' . $exam['slug'] . '.csv', ['no', 'question', 'type', 'topic', 'option_a', 'option_b', 'option_c', 'option_d', 'option_e', 'option_f', 'correct', 'marks', 'negative_marks', 'explanation', ...QUESTION_CSV_EXTRA, 'image_url'], $out);
}
if (($_GET['answer_key'] ?? '') === 'print') {
    audit_log('answer_key_viewed', 'exams', $examId);
    $nonce = base64_encode(random_bytes(12));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'");
    $academy = setting('company_legal_name', '') ?: (setting('site_name', 'Laxyam Academy') ?: 'Laxyam Academy');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Answer key · <?= e($exam['title']) ?></title>
<style>
  :root { --navy:#08264D; --primary:#0B63F6; --green:#15803d; --line:#E2EAF4; --muted:#5b6b82; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 14px/1.55 Inter, "Segoe UI", system-ui, sans-serif; color: #0f1d33; background: #F5F9FF; }
  .sheet { max-width: 860px; margin: 24px auto; background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 32px 36px; }
  header { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; border-bottom: 2px solid var(--navy); padding-bottom: 16px; margin-bottom: 20px; }
  header img { height: 44px; }
  h1 { font-size: 22px; margin: 6px 0 4px; color: var(--navy); }
  .meta { color: var(--muted); font-size: 13px; }
  .conf { display: inline-block; background: #fff4e5; color: #9a3412; border-radius: 999px; padding: 3px 10px; font-size: 12px; font-weight: 600; }
  .bar { display: flex; gap: 10px; justify-content: flex-end; max-width: 860px; margin: 16px auto 0; }
  button, a.btn { font: 600 14px/1 inherit; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: var(--navy); cursor: pointer; text-decoration: none; }
  button.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
  button:focus-visible, a.btn:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
  ol.qs { list-style: none; padding: 0; margin: 0; }
  ol.qs > li { padding: 14px 0; border-bottom: 1px solid var(--line); break-inside: avoid; }
  .qhead { display: flex; gap: 10px; }
  .qno { font-weight: 700; color: var(--navy); min-width: 34px; }
  .qmeta { color: var(--muted); font-size: 12px; margin-top: 2px; }
  .qimg { max-width: 100%; max-height: 260px; border: 1px solid var(--line); border-radius: 8px; margin: 8px 0 0 44px; }
  ul.opts { list-style: none; margin: 8px 0 0 44px; padding: 0; display: grid; gap: 4px; }
  ul.opts li { padding: 4px 10px; border-radius: 8px; }
  ul.opts li.ok { background: #ecfdf3; color: var(--green); font-weight: 600; }
  .ans { margin: 8px 0 0 44px; font-weight: 700; color: var(--green); }
  .expl { margin: 6px 0 0 44px; color: #334155; white-space: pre-line; }
  .hi { color: #334155; margin-top: 4px; }
  .sec { margin: 22px 0 4px; padding: 6px 12px; background: #eef4ff; border-radius: 8px; color: var(--navy); font-weight: 700; break-after: avoid; }
  footer { margin-top: 20px; color: var(--muted); font-size: 12px; }
  @media (max-width: 640px) { .sheet { margin: 0; border-radius: 0; padding: 20px 16px; } header { flex-direction: column; } .qimg, ul.opts, .ans, .expl { margin-left: 0; } }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { border: 0; margin: 0; padding: 0; max-width: none; } }
</style>
</head>
<body>
<div class="bar"><a class="btn" href="questions.php?exam=<?= $examId ?>">Back to questions</a><a class="btn" href="questions.php?exam=<?= $examId ?>&amp;answer_key=csv">Download CSV</a><button type="button" class="primary" id="print">Print / Save as PDF</button></div>
<main class="sheet">
  <header>
    <div>
      <span class="conf">Confidential · staff only</span>
      <h1>Answer key: <?= e($exam['title']) ?></h1>
      <div class="meta"><?= e(implode(' · ', array_filter([$exam['subject'], $exam['mode'] === 'practice' ? 'Practice quiz' : 'Mock test', count($questions) . ' questions', 'Total ' . exam_format_marks((float) $exam['total_marks']) . ' marks', $exam['negative_marking'] ? 'Negative marking on' : null]))) ?></div>
    </div>
    <img src="<?= e(app_url('assets/logo.png')) ?>" alt="<?= e($academy) ?>">
  </header>
  <?php if (!$questions): ?><p class="meta">This test has no questions yet.</p><?php endif; ?>
  <ol class="qs">
    <?php $lastSection = false; foreach ($questions as $n => $q): $qo = $opts[(int) $q['id']] ?? []; $qHi = exam_hi($q['question_text_hi'] ?? null); ?>
    <?php if ($sections && ($q['section_id'] ?? null) !== $lastSection): $lastSection = $q['section_id'] ?? null; ?>
    </ol><div class="sec"><?= e($sectionTitle[(int) $lastSection] ?? 'No section') ?></div><ol class="qs" start="<?= $n + 1 ?>">
    <?php endif; ?>
    <li>
      <div class="qhead"><span class="qno">Q<?= $n + 1 ?>.</span><div><?= nl2br(e($q['question_text'])) ?>
        <?php if ($qHi): ?><div class="hi" lang="hi"><?= nl2br(e($qHi)) ?></div><?php endif; ?>
        <div class="qmeta"><?= e(implode(' · ', array_filter([ucwords(str_replace('_', ' ', $q['question_type'])), $q['topic'], '+' . exam_format_marks((float) $q['marks']), exam_negative_for($exam, $q) > 0 ? '−' . exam_format_marks(exam_negative_for($exam, $q)) : null]))) ?></div></div></div>
      <?php if ($q['image_path']): ?><img class="qimg" src="<?= e(public_file_url($q['image_path'])) ?>" alt="Image for question <?= $n + 1 ?>"><?php endif; ?>
      <ul class="opts">
        <?php foreach ($qo as $i => $o): $oHi = $qHi ? exam_hi($o['option_text_hi'] ?? null) : null; ?><li class="<?= $o['is_correct'] ? 'ok' : '' ?>"><?= chr(65 + $i) ?>. <?= e($o['option_text']) ?><?php if ($oHi): ?> <span lang="hi">/ <?= e($oHi) ?></span><?php endif; ?><?= $o['is_correct'] ? ' ✓ <span style="position:absolute;left:-9999px">correct answer</span>' : '' ?></li><?php endforeach; ?>
      </ul>
      <div class="ans">Answer: <?= e($letterOf((int) $q['id']) ?: '—') ?></div>
      <?php if ($q['explanation']): ?><div class="expl"><strong>Explanation:</strong> <?= e($q['explanation']) ?></div><?php endif; ?>
      <?php if ($hiExp = exam_hi($q['explanation_hi'] ?? null)): ?><div class="expl" lang="hi"><strong>व्याख्या:</strong> <?= e($hiExp) ?></div><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <footer>Generated <?= e(date('j M Y, g:i A')) ?> by <?= e($me['name']) ?> · <?= e($academy) ?>. Do not share with students before results are published.</footer>
</main>
<script nonce="<?= e($nonce) ?>">document.getElementById('print').addEventListener('click', function () { window.print(); });</script>
</body>
</html>
    <?php
    exit;
}

// ------------------------------------------------------------------
// Page
// ------------------------------------------------------------------
$sum = array_sum(array_map(fn($q) => (float) $q['marks'], $questions));
$editing = $_GET['edit'] ?? null;
$pool = (int) ($exam['question_pool_count'] ?? 0);
$actions = '<a class="btn btn-light" href="mock-tests.php?tab=' . ($exam['mode'] === 'practice' ? 'practice' : 'exam') . '&action=edit&id=' . $examId . '">' . icon('settings') . ' Test settings</a>'
    . ' <a class="btn btn-light" href="attempts.php?exam=' . $examId . '">' . icon('users') . ' Attempts</a>'
    . ' <a class="btn btn-light" href="?exam=' . $examId . '&answer_key=print" target="_blank" rel="noopener">' . icon('key') . ' Answer key</a>'
    . ($hasAttempts ? '' : ' <a class="btn btn-primary" href="?exam=' . $examId . '&edit=new">' . icon('plus') . ' Add question</a>');
admin_start('Questions · ' . $exam['title'], null, 'tests', [
    'head' => true,
    'crumbs' => '<a href="mock-tests.php' . ($exam['mode'] === 'practice' ? '?tab=practice' : '') . '">' . ($exam['mode'] === 'practice' ? 'Practice Quizzes' : 'Mock Tests') . '</a> / Questions',
    'subtitle' => q_count_label(count($questions), 'question') . ($pool ? ' · ' . min($pool, count($questions)) . ' random per attempt' : '') . ' · question marks total ' . exam_format_marks($sum) . ' · test total ' . exam_format_marks((float) $exam['total_marks']),
    'actions' => $actions,
]);

// Progress towards the test total + a grid to jump to any question (long tests: 100+ questions).
$totalMarks = (float) $exam['total_marks'];
$over = $totalMarks > 0 ? round($sum - $totalMarks, 2) : 0.0; // > 0: questions carry more marks than the test total
$marksPct = $totalMarks > 0 ? (int) min(100, floor($sum / $totalMarks * 100 + 1e-9)) : null; // 100% only once complete
$noExpl = count(array_filter($questions, fn($q) => trim((string) $q['explanation']) === ''));
$marksBadge = match (true) {
    $marksPct === null => '',
    $over > 0 => '<span class="badge b-amber">' . exam_format_marks($over) . ' mark' . ($over == 1 ? '' : 's') . ' over the test total</span>',
    default => '<span class="badge ' . ($marksPct >= 100 ? 'b-green' : 'b-blue') . '">' . $marksPct . '% of the paper</span>',
};
echo '<section class="card qx-progress" aria-label="Question progress"><div class="qx-progress-top"><div><strong>' . q_count_label(count($questions), 'question') . '</strong>'
    . ' <span class="muted">· ' . exam_format_marks($sum) . ($totalMarks > 0 ? ' of ' . exam_format_marks($totalMarks) . ' test marks' : ' marks') . ($noExpl ? ' · ' . $noExpl . ' without explanation' : '') . '</span></div>'
    . $marksBadge . '</div>'
    . ($marksPct !== null ? '<div class="qx-bar' . ($over > 0 ? ' over' : '') . '" role="progressbar" aria-label="Marks added" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $marksPct . '"><i style="width:' . $marksPct . '%"></i></div>' : '');
if ($questions) {
    echo '<nav class="qx-palette" aria-label="Jump to a question">';
    foreach ($questions as $n => $q) {
        $expl = trim((string) $q['explanation']) !== '';
        echo '<a href="#q-' . ($n + 1) . '"' . ($expl ? '' : ' class="no-expl"') . ' title="' . e('Q' . ($n + 1) . ': ' . mb_strimwidth($q['question_text'], 0, 90, '…') . ($q['topic'] ? ' · ' . $q['topic'] : '') . ($expl ? '' : ' · no explanation')) . '">' . ($n + 1) . '</a>';
    }
    echo '</nav>' . ($noExpl ? '<p class="qx-legend"><i></i> amber = no explanation yet</p>' : '');
}
echo '</section>';

if (!$hasAttempts) {
    echo '<div class="card d1-chips"><strong>Add in bulk:</strong>'
        . ' <a class="btn btn-primary btn-sm" href="?exam=' . $examId . '&paste=1">' . icon('copy') . ' Paste questions</a>'
        . ' <a class="btn btn-light btn-sm" href="?exam=' . $examId . '&import=1">' . icon('upload') . ' Import CSV</a>'
        . ' <a class="btn btn-light btn-sm" href="?exam=' . $examId . '&sample=1">' . icon('file') . ' CSV template</a>'
        . ' <a class="btn btn-light btn-sm" href="?exam=' . $examId . '&bank=1">' . icon('copy') . ' Reuse from another test</a>'
        . ' <a class="btn btn-light btn-sm" href="?exam=' . $examId . '&answer_key=csv">' . icon('download') . ' Export answer key (CSV)</a></div>';
} else {
    echo '<div class="alert alert-warning" role="status">' . e(QUESTIONS_LOCKED) . ' You can still fix wording, images, explanations and the answer key.'
        . ($finished ? ' After correcting a key, ' . action_form('rescore', [], icon('history') . ' Re-score ' . q_count_label($finished, 'attempt'), 'btn btn-light btn-sm', 'Re-score every submitted attempt with the current answer key? Scores, ranks and results may change.') : '') . '</div>';
}

// ------------------------------------------------------------------
// Sections (optional): Quant / Reasoning / English…, each optionally with its own timer
// ------------------------------------------------------------------
if ($v11) {
    $numbersBySection = [];
    foreach ($questions as $n => $q) {
        $numbersBySection[(int) ($q['section_id'] ?? 0)][] = $n + 1;
    }
    $range = function (array $nums): string {
        if (!$nums) {
            return 'no questions yet';
        }
        $parts = [];
        $start = $prev = $nums[0];
        foreach (array_merge(array_slice($nums, 1), [null]) as $x) {
            if ($x !== $prev + 1) {
                $parts[] = $start === $prev ? 'Q' . $start : 'Q' . $start . '–Q' . $prev;
                $start = $x;
            }
            $prev = $x;
        }
        return count($nums) . ' · ' . implode(', ', $parts);
    };
    $unassigned = count($numbersBySection[0] ?? []);
    $practiceTest = exam_is_practice($exam);
    $timingOn = !empty($exam['sectional_timing']) && !$practiceTest;
    $untimed = array_filter($sections, fn($s) => (int) $s['duration_minutes'] <= 0);
    $sumMinutes = array_sum(array_map(fn($s) => (int) $s['duration_minutes'], $sections));
    $lock = $hasAttempts ? ' disabled title="' . e(SECTIONS_LOCKED) . '"' : '';
    echo '<section class="card qx-sections" id="sections" aria-labelledby="sec-h"><div class="card-head"><h2 id="sec-h">' . icon('layers') . ' Sections</h2>'
        . ($sections ? ($timingOn ? ($untimed ? '<span class="badge b-amber">Sectional timing paused</span>' : '<span class="badge b-green">' . icon('clock', 'i') . ' Sectional timing · ' . $sumMinutes . ' min</span>')
            : '<span class="badge b-gray">One timer for the whole test</span>') : '') . '</div>';
    if (!$sections) {
        echo '<p class="qx-sec-intro muted">Optional: divide the paper into sections such as Quantitative Aptitude, Reasoning and English. Students see them as tabs, and you can give each section its own timer (IBPS / SSC style).</p>';
    } else {
        echo '<div class="table-wrap"><table class="t qx-sec-table"><thead><tr><th scope="col">#</th><th scope="col">Section</th><th scope="col">Hindi name</th><th scope="col">Minutes</th><th scope="col">Questions</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
        foreach ($sections as $i => $s) {
            $sid = (int) $s['id'];
            $form = 'sec-f-' . $sid;
            echo '<tr><td class="d1-num">' . ($i + 1) . '</td>'
                . '<td data-label="Section"><input class="in" form="' . $form . '" name="title" value="' . e($s['title']) . '" maxlength="80" required aria-label="Section ' . ($i + 1) . ' name"></td>'
                . '<td data-label="Hindi name"><input class="in" form="' . $form . '" name="title_hi" value="' . e((string) $s['title_hi']) . '" maxlength="80" lang="hi" placeholder="optional" aria-label="Section ' . ($i + 1) . ' Hindi name"></td>'
                . '<td data-label="Minutes"><input class="in qx-min" form="' . $form . '" type="number" name="minutes" min="1" max="600" value="' . e((string) ($s['duration_minutes'] ?? '')) . '" placeholder="—"' . $lock . ' aria-label="Section ' . ($i + 1) . ' minutes"></td>'
                . '<td data-label="Questions" class="muted">' . e($range($numbersBySection[$sid] ?? [])) . '</td>'
                . '<td class="actions"><form method="post" id="' . $form . '">' . csrf_field() . '<input type="hidden" name="_action" value="section_save"><input type="hidden" name="section_id" value="' . $sid . '"><button class="btn btn-light btn-sm">' . icon('check') . ' Save</button></form>';
            if (!$hasAttempts) {
                echo action_form('section_move', ['section_id' => $sid, 'dir' => -1], icon('up'), 'btn btn-light btn-sm btn-icon' . ($i === 0 ? ' is-hidden' : ''), null, 'Move up')
                    . action_form('section_move', ['section_id' => $sid, 'dir' => 1], icon('down'), 'btn btn-light btn-sm btn-icon' . ($i === count($sections) - 1 ? ' is-hidden' : ''), null, 'Move down')
                    . action_form('section_delete', ['section_id' => $sid], icon('trash'), 'btn btn-danger btn-sm btn-icon', 'Delete section “' . $s['title'] . '”? Its questions are kept, without a section.', 'Delete section');
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($unassigned) {
            echo '<p class="qx-sec-note">' . icon('alert') . ' ' . q_count_label($unassigned, 'question') . ' (' . e(trim(explode('·', $range($numbersBySection[0]), 2)[1] ?? '')) . ') ' . ($unassigned === 1 ? 'has' : 'have')
                . ' no section - students see ' . ($unassigned === 1 ? 'it' : 'them') . ' in the last section, “' . e($sections[count($sections) - 1]['title']) . '”.</p>';
        }
        if ($timingOn && $untimed) {
            echo '<p class="qx-sec-note bad">' . icon('alert') . ' Sectional timing is paused until every section has its minutes (' . e(implode(', ', array_column($untimed, 'title'))) . '). Until then the whole test uses one timer of ' . (int) $exam['duration_minutes'] . ' minutes.</p>';
        }
    }
    if (!$hasAttempts) {
        echo '<form method="post" class="qx-sec-add">' . csrf_field() . '<input type="hidden" name="_action" value="section_save">'
            . '<input class="in" name="title" maxlength="80" required placeholder="New section, e.g. Reasoning Ability" aria-label="New section name">'
            . '<input class="in" name="title_hi" maxlength="80" lang="hi" placeholder="Hindi name (optional), e.g. तर्कशक्ति" aria-label="New section Hindi name">'
            . '<input class="in qx-min" type="number" name="minutes" min="1" max="600" placeholder="Minutes" aria-label="New section minutes">'
            . '<button class="btn btn-primary btn-sm">' . icon('plus') . ' Add section</button></form>';
        if ($sections && $questions) {
            echo '<form method="post" class="qx-sec-add">' . csrf_field() . '<input type="hidden" name="_action" value="section_assign"><span>Put questions</span>'
                . '<label class="sr-only" for="sa-from">From question</label><input class="in qx-min" id="sa-from" type="number" name="from" min="1" max="' . count($questions) . '" value="1" required>'
                . '<span>to</span><label class="sr-only" for="sa-to">To question</label><input class="in qx-min" id="sa-to" type="number" name="to" min="1" max="' . count($questions) . '" value="' . count($questions) . '" required>'
                . '<span>in</span><label class="sr-only" for="sa-sec">Section</label><select class="in" id="sa-sec" name="section_id" style="width:auto">';
            foreach ($sections as $s) {
                echo '<option value="' . (int) $s['id'] . '">' . e($s['title']) . '</option>';
            }
            echo '<option value="0">No section</option></select><button class="btn btn-light btn-sm">' . icon('layers') . ' Move</button></form>';
        }
        if ($sections && !$practiceTest) {
            echo '<div class="qx-sec-timing"><div><strong>Sectional timing</strong><p class="muted">' . ($timingOn
                    ? 'On: each section runs on its own timer, one after another. When a section’s time is over the next one starts and students cannot go back. Test time = ' . $sumMinutes . ' minutes (sum of the sections).'
                    : 'Off: one timer for the whole test (' . (int) $exam['duration_minutes'] . ' minutes) and students move freely between sections. Turn it on to give each section its own time.') . '</p></div>'
                . action_form('section_timing', ['on' => $timingOn ? 0 : 1], $timingOn ? 'Turn off' : icon('clock') . ' Turn on sectional timing', $timingOn ? 'btn btn-light btn-sm' : 'btn btn-primary btn-sm',
                    $timingOn ? 'Turn off sectional timing? The test goes back to one timer for all sections.' : null) . '</div>';
        }
    } elseif ($sections) {
        echo '<p class="qx-sec-note">' . icon('lock') . ' ' . e(SECTIONS_LOCKED) . '</p>';
    }
    echo '</section>';
}

if (isset($_GET['import']) && !$hasAttempts) {
    echo '<form method="post" enctype="multipart/form-data" class="card" style="margin-bottom:18px">' . csrf_field() . '<input type="hidden" name="_action" value="import">'
        . '<div class="card-head"><h2>Import questions from CSV</h2><a href="?exam=' . $examId . '&sample=1">' . icon('download') . ' Download CSV template</a></div>'
        . '<div class="card-pad form"><div class="field full"><label for="csv">CSV file (UTF-8, up to 500 questions)</label><input id="csv" type="file" name="csv" accept=".csv,text/csv" required>'
        . '<div class="hint">Columns: question, type (single / true_false / multiple), topic, option_a … option_f, correct (A, or A;C for multiple), marks, negative_marks, explanation. Blank option columns are skipped and the correct letters still point at the right option. Every row is checked first - nothing is imported if any row has an error.'
        . ($v11 ? ' Optional: section (created if new), question_hi, option_a_hi … option_f_hi, explanation_hi for the Hindi version.' : '') . '</div></div></div>'
        . '<div class="form-actions"><a class="btn btn-light" href="?exam=' . $examId . '">Cancel</a><button class="btn btn-primary">' . icon('download') . ' Import</button></div></form>';
}

if (isset($_GET['paste']) && !$hasAttempts) {
    // Paste many questions: textarea + defaults on the left, live student-view preview on the right
    // (assets/admin-questions.js asks this page to parse the text as you type).
    $draft = (string) ($_SESSION['paste_draft'] ?? '');
    $last = $questions ? $questions[count($questions) - 1] : null;
    $allTopics = array_column(db_all('SELECT DISTINCT topic FROM exam_questions WHERE topic IS NOT NULL ORDER BY topic LIMIT 300'), 'topic');
    $example = "1. Who chaired the Drafting Committee of the Constituent Assembly?\nA) Jawaharlal Nehru\nB) Dr B. R. Ambedkar\nC) Rajendra Prasad\nD) Sardar Patel\nAnswer: B\nExplanation: Dr Ambedkar chaired the Drafting Committee.\n\n"
        . "2. Consider the following statements:\n1. The Rajya Sabha is a permanent house.\n2. The Lok Sabha is never dissolved.\nWhich of the statements given above is/are correct?\n(a) 1 only\n(b) 2 only\n(c) Both 1 and 2\n(d) Neither 1 nor 2\nAns: a\n\n"
        . "3. भारत की राजधानी क्या है?\n(क) मुंबई\n(ख) नई दिल्ली\n(ग) कोलकाता\n(घ) चेन्नई\nउत्तर: ख";
    ?>
    <form method="post" class="card qx-paste" id="paste-form" data-qx-paste data-existing="<?= count($questions) ?>" data-multiple="<?= $exam['allow_multiple_correct'] ? 1 : 0 ?>">
      <?= csrf_field() ?><input type="hidden" name="_action" value="paste_import">
      <div class="card-head"><h2><?= icon('copy') ?> Paste questions</h2><a href="?exam=<?= $examId ?>">Close</a></div>
      <div class="qx-split">
        <div class="qx-paste-in">
          <div class="form qx-defaults">
            <div class="field"><label for="pd-m">Marks each</label><input id="pd-m" type="number" step="0.25" min="0.25" name="def_marks" value="<?= e(exam_format_marks((float) ($last['marks'] ?? 1))) ?>"></div>
            <div class="field"><label for="pd-n">Negative marks<?= $exam['negative_marking'] ? '' : ' (off for this test)' ?></label><input id="pd-n" type="number" step="0.01" min="0" name="def_negative" value="<?= e(exam_format_marks((float) ($last['negative_marks'] ?? 0))) ?>"></div>
            <div class="field"><label for="pd-t">Topic (optional)</label><input id="pd-t" name="def_topic" maxlength="80" list="pd-topics" value="<?= e((string) ($last['topic'] ?? '')) ?>">
              <datalist id="pd-topics"><?php foreach ($allTopics as $tp): ?><option value="<?= e($tp) ?>"><?php endforeach; ?></datalist></div>
            <?php if ($sections): ?>
            <div class="field"><label for="pd-s">Section</label><select id="pd-s" name="def_section"><option value="0">No section</option>
              <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) ($last['section_id'] ?? 0) ? ' selected' : '' ?>><?= e($s['title']) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
          </div>
          <label for="pd-text" class="lbl">Questions - paste from Word, Google Docs or your notes (up to <?= PASTE_MAX_QUESTIONS ?> at a time)</label>
          <textarea id="pd-text" name="paste" class="qx-paste-text" rows="22" spellcheck="false" placeholder="<?= e("1. Question text\nA) First option\nB) Second option\nC) Third option\nD) Fourth option\nAnswer: B\nExplanation: optional") ?>"><?= e($draft) ?></textarea>
          <?php if ($v11): $draftHi = (string) ($_SESSION['paste_draft_hi'] ?? ''); ?>
          <details class="qx-hi qx-paste-hi"<?= $draftHi !== '' ? ' open' : '' ?>>
            <summary><?= icon('languages') ?> हिंदी version <span class="muted">(optional) - the same questions in Hindi, in the same order</span></summary>
            <textarea id="pd-text-hi" name="paste_hi" class="qx-paste-text" rows="14" spellcheck="false" lang="hi" aria-label="Questions in Hindi" placeholder="<?= e("1. प्रश्न का पाठ\n(क) पहला विकल्प\n(ख) दूसरा विकल्प\n(ग) तीसरा विकल्प\n(घ) चौथा विकल्प\nव्याख्या: वैकल्पिक") ?>"><?= e($draftHi) ?></textarea>
            <p class="hint">The answer key comes from the English questions; a Hindi answer line is optional (if given, it must match). Every Hindi question needs the same number of options.</p>
          </details>
          <?php endif; ?>
          <details class="qx-help">
            <summary>Format help</summary>
            <ul>
              <li><b>Question:</b> start with its number - <code>1.</code> <code>Q1.</code> <code>प्रश्न 1.</code> It may run over several lines; numbered statements (1. 2. 3.) inside a question are fine.</li>
              <li><b>Options:</b> <code>A)</code> <code>(a)</code> <code>a.</code> <code>[A]</code> or Hindi <code>(क) (ख) (ग) (घ)</code> - up to 6, one per line or all on one line.</li>
              <li><b>Answer:</b> <code>Answer: B</code> (also <code>Ans</code>, <code>उत्तर</code>). Multiple correct: <code>Answer: A, C</code>. True/False: <code>Answer: True</code>.</li>
              <li><b>Optional:</b> <code>Explanation: …</code> (can continue on the next lines), <code>Topic: Polity</code>, <code>Marks: 2 / 0.5</code> for this question only.</li>
              <?php if ($v11): ?><li><b>Sections:</b> a line <code>Section: Reasoning</code> puts the questions after it into that section (it is created if it does not exist yet).</li><?php endif; ?>
              <li>Nothing is added until every question is correct - problems are shown on the right, click a question to jump to its text.</li>
            </ul>
            <button type="button" class="btn btn-light btn-sm" data-qx-example="<?= e($example) ?>"><?= icon('file') ?> Insert example</button>
          </details>
        </div>
        <aside class="qx-paste-out" aria-label="Preview of the pasted questions">
          <?php if ($v11): ?><div class="cp-seg qx-paste-lang" role="group" aria-label="Preview language"><a href="#" class="on" data-qx-plang="en" aria-pressed="true">EN</a><a href="#" data-qx-plang="hi" aria-pressed="false" lang="hi">हिंदी</a></div><?php endif; ?>
          <div class="qx-paste-sum" data-qx-summary role="status">Paste questions on the left - they appear here exactly as students will see them, with the correct answer marked.</div>
          <div class="qx-paste-list" data-qx-list></div>
        </aside>
      </div>
      <div class="form-actions"><span class="muted" data-qx-count></span><a class="btn btn-light" href="?exam=<?= $examId ?>">Cancel</a><button class="btn btn-primary" data-qx-import disabled><?= icon('check') ?> Add questions</button></div>
    </form>
    <?php
}

if (isset($_GET['bank']) && !$hasAttempts) {
    // Question bank reuse: copy questions from any other test.
    $from = (int) ($_GET['from'] ?? 0);
    $sources = db_all('SELECT e.id, e.title, e.mode, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS n FROM exams e WHERE e.id <> ? HAVING n > 0 ORDER BY e.title', [$examId]);
    echo '<div class="card" style="margin-bottom:18px"><div class="card-head"><h2>Reuse questions from another test</h2><a href="?exam=' . $examId . '">Close</a></div>';
    if (!$sources) {
        echo '<div class="empty">No other test has questions yet.</div></div>';
    } else {
        echo '<form method="get" class="toolbar"><input type="hidden" name="exam" value="' . $examId . '"><input type="hidden" name="bank" value="1"><label for="from" class="lbl" style="margin:0">Copy from</label>'
            . '<select id="from" class="in" style="width:auto;max-width:100%" name="from" data-autosubmit><option value="">Choose a test…</option>';
        foreach ($sources as $s) {
            echo '<option value="' . (int) $s['id'] . '"' . ((int) $s['id'] === $from ? ' selected' : '') . '>' . e($s['title']) . ' (' . (int) $s['n'] . ' questions' . ($s['mode'] === 'practice' ? ', quiz' : '') . ')</option>';
        }
        echo '</select><noscript><button class="btn btn-light btn-sm">Show</button></noscript></form>';
        $src = $from ? db_one('SELECT id, title FROM exams WHERE id = ? AND id <> ?', [$from, $examId]) : null;
        if ($src) {
            $btopic = (string) ($_GET['btopic'] ?? '');
            $srcTopics = db_all('SELECT COALESCE(topic, \'\') AS topic, COUNT(*) AS n FROM exam_questions WHERE exam_id = ? GROUP BY topic ORDER BY topic', [$from]);
            $where = 'exam_id = ?';
            $params = [$from];
            if ($btopic !== '') {
                $where .= $btopic === '__none' ? ' AND topic IS NULL' : ' AND topic = ?';
                if ($btopic !== '__none') {
                    $params[] = $btopic;
                }
            }
            $srcQs = db_all("SELECT id, question_type, topic, question_text, marks FROM exam_questions WHERE {$where} ORDER BY sort_order, id LIMIT 500", $params);
            echo '<div class="toolbar d1-chips" style="margin:0"><strong>Topic:</strong> <a class="d1-chip' . ($btopic === '' ? ' on' : '') . '" href="' . e(url_with(['btopic' => null])) . '">All</a>';
            foreach ($srcTopics as $t) {
                $key = $t['topic'] === '' ? '__none' : $t['topic'];
                echo '<a class="d1-chip' . ($btopic === $key ? ' on' : '') . '" href="' . e(url_with(['btopic' => $key])) . '">' . e($t['topic'] === '' ? 'No topic' : $t['topic']) . ' <b>' . (int) $t['n'] . '</b></a>';
            }
            echo '</div><form method="post" data-bulk>' . csrf_field() . '<input type="hidden" name="_action" value="import_bank"><input type="hidden" name="from" value="' . $from . '"><input type="hidden" name="topic" value="' . e($btopic) . '">'
                . '<div class="table-wrap"><table class="t stack"><thead><tr><th style="width:36px"><input type="checkbox" data-check-all aria-label="Select all questions shown"></th><th scope="col">Question</th><th scope="col">Type</th><th scope="col">Topic</th><th scope="col">Marks</th></tr></thead><tbody>';
            foreach ($srcQs as $sq) {
                $multiNote = $sq['question_type'] === 'multiple' && !$exam['allow_multiple_correct'] ? ' <span class="badge b-amber" title="This test does not allow multiple-correct questions">skipped</span>' : '';
                echo '<tr><td class="sel"><input type="checkbox" name="qids[]" value="' . (int) $sq['id'] . '" data-row-check aria-label="Select question ' . (int) $sq['id'] . '"></td><td data-label="Question"><div class="d1-main"><span class="d1-clip" style="max-width:none;color:var(--ink)" title="' . e($sq['question_text']) . '">' . e($sq['question_text']) . '</span></div></td><td data-label="Type" class="d1-nowrap">' . e(['single' => 'Single answer', 'true_false' => 'True / False', 'multiple' => 'Multiple correct'][$sq['question_type']] ?? $sq['question_type']) . $multiNote . '</td><td data-label="Topic">' . e($sq['topic'] ?? '—') . '</td><td data-label="Marks" class="d1-num">' . e(exam_format_marks((float) $sq['marks'])) . '</td></tr>';
            }
            echo '</tbody></table></div><div class="form-actions"><span class="muted"><span data-bulk-count>0</span> selected</span><a class="btn btn-light" href="?exam=' . $examId . '">Cancel</a>'
                . ($btopic !== '' ? '<button class="btn btn-light" name="whole_topic" value="1" data-confirm="Copy every question in this topic?">' . icon('copy') . ' Copy whole topic</button>' : '')
                . '<button class="btn btn-primary">' . icon('copy') . ' Copy selected</button></div></form>';
        }
        echo '</div>';
    }
}

$sectionFilter = (int) ($_GET['section'] ?? 0);
if ($sections) {
    echo '<nav class="card d1-chips" aria-label="Filter by section"><strong>Sections:</strong> <a class="d1-chip' . (!$sectionFilter ? ' on' : '') . '" href="?exam=' . $examId . '"' . (!$sectionFilter ? ' aria-current="page"' : '') . '>All <b>' . count($questions) . '</b></a>';
    foreach ($sections as $s) {
        $on = $sectionFilter === (int) $s['id'];
        echo '<a class="d1-chip' . ($on ? ' on' : '') . '" href="?exam=' . $examId . '&amp;section=' . (int) $s['id'] . '"' . ($on ? ' aria-current="page"' : '') . '>' . e($s['title']) . ' <b>' . count($numbersBySection[(int) $s['id']] ?? []) . '</b></a>';
    }
    echo '</nav>';
}
$topicFilter = trim((string) ($_GET['topic'] ?? ''));
$examTopics = array_column(db_all('SELECT topic, COUNT(*) n FROM exam_questions WHERE exam_id = ? AND topic IS NOT NULL GROUP BY topic ORDER BY topic', [$examId]), 'n', 'topic');
if ($examTopics) {
    echo '<nav class="card d1-chips" aria-label="Filter by topic"><strong>Topics:</strong> <a class="d1-chip' . ($topicFilter === '' ? ' on' : '') . '" href="?exam=' . $examId . '"' . ($topicFilter === '' ? ' aria-current="page"' : '') . '>All <b>' . count($questions) . '</b></a>';
    foreach ($examTopics as $tp => $n) {
        echo '<a class="d1-chip' . ($topicFilter === (string) $tp ? ' on' : '') . '" href="?exam=' . $examId . '&amp;topic=' . urlencode((string) $tp) . '"' . ($topicFilter === (string) $tp ? ' aria-current="page"' : '') . '>' . e((string) $tp) . ' <b>' . (int) $n . '</b></a>';
    }
    echo '</nav>';
}
if ($questions && abs($sum - (float) $exam['total_marks']) > 0.001 && !$pool) {
    echo '<div class="alert alert-info">The sum of question marks (' . exam_format_marks($sum) . ') differs from the test total (' . exam_format_marks((float) $exam['total_marks']) . '). '
        . ($hasAttempts ? 'Totals are locked because students have attempted.' : action_form('sync_total', [], 'Set total to ' . exam_format_marks($sum))) . '</div>';
}

// ------------------------------------------------------------------
// Add / edit form (re-filled after a validation error)
// ------------------------------------------------------------------
if ($editing !== null && ($editing !== 'new' || !$hasAttempts)) {
    $q = $editing === 'new' ? null : db_one('SELECT * FROM exam_questions WHERE id = ? AND exam_id = ?', [(int) $editing, $examId]);
    if ($editing === 'new' || $q) {
        $qo = $q ? ($opts[(int) $q['id']] ?? []) : [];
        $correction = $q && $hasAttempts;
        $v = fn(string $key, $default = '') => $formValues !== null ? ($formValues[$key] ?? '') : $default;
        // A new question starts with the previous one's marks, negative marks, topic and type (fast entry of long papers).
        $prev = !$q && $questions ? $questions[count($questions) - 1] : null;
        $type = $correction ? $q['question_type'] : (string) $v('question_type', $q['question_type'] ?? ($prev['question_type'] ?? 'single'));
        $position = $q ? (int) array_search((int) $q['id'], array_map('intval', array_column($questions, 'id')), true) + 1 : count($questions) + 1;
        $examHasHindi = (bool) array_filter($questions, fn($x) => exam_hi($x['question_text_hi'] ?? null) !== null);
        $hiOpen = $formValues !== null ? trim((string) ($formValues['question_text_hi'] ?? '')) !== '' : (exam_hi($q['question_text_hi'] ?? null) !== null || $examHasHindi);
        $suggest = db_all('SELECT topic, SUM(exam_id = ?) AS here, COUNT(*) AS n FROM exam_questions WHERE topic IS NOT NULL GROUP BY topic ORDER BY here DESC, n DESC, topic LIMIT 300', [$examId]);
        ?>
    <div class="qx-split qx-editor">
    <form method="post" enctype="multipart/form-data" class="card" id="question-form" data-dirty-check>
      <?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= (int) ($q['id'] ?? 0) ?>">
      <div class="card-head"><h2><?= $q ? ($correction ? 'Correct question / answer key' : 'Edit question') : 'New question' ?></h2><?php if ($correction): ?><span class="badge b-amber">Students have attempted - options and marks are fixed</span><?php endif; ?></div>
      <?php if ($formError): ?><div class="alert alert-error" role="alert" style="margin:16px 20px 0"><?= e($formError) ?><?= !empty($_FILES['image']['name']) ? ' Choose the image again.' : '' ?></div><?php endif; ?>
      <div class="card-pad form">
        <div class="field full"><label for="qt">Question</label><textarea id="qt" name="question_text" rows="3" required<?= $q ? '' : ' autofocus' ?>><?= e((string) $v('question_text', $q['question_text'] ?? '')) ?></textarea></div>
        <div class="field full"><label for="qimg">Image (optional - diagram, chart or table)</label>
          <input id="qimg" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" data-preview="qimg-pv">
          <img id="qimg-pv" class="thumb-preview" alt="" <?= !empty($q['image_path']) ? 'src="' . e(public_file_url($q['image_path'])) . '"' : 'hidden' ?>>
          <?php if (!empty($q['image_path'])): ?><label class="check" style="margin-top:6px"><input type="checkbox" name="image_remove" value="1"<?= $v('image_remove') ? ' checked' : '' ?>> Remove image</label><?php endif; ?>
          <div class="hint">JPG, PNG, WebP or GIF up to 8 MB. It is resized and shown above the options.</div></div>
        <?php if ($sections): $secVal = (int) $v('section_id', $q['section_id'] ?? ($prev['section_id'] ?? $sectionFilter)); ?>
        <div class="field"><label for="sec">Section</label>
          <select id="sec" name="section_id"<?= $correction ? ' disabled' : '' ?>>
            <option value="0">No section</option>
            <?php foreach ($sections as $s): ?><option value="<?= (int) $s['id'] ?>"<?= $secVal === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['title']) ?></option><?php endforeach; ?>
          </select>
          <?php if ($correction): ?><div class="hint">Fixed after students have attempted.</div><?php endif; ?></div>
        <?php endif; ?>
        <?php if (!$correction): ?>
        <div class="field"><label for="qtype">Type</label>
          <select id="qtype" name="question_type">
            <?php foreach (['single' => 'Single correct answer', 'true_false' => 'True / False', 'multiple' => 'Multiple correct answers'] as $k => $l): ?>
            <option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?><?= $k === 'multiple' && !$exam['allow_multiple_correct'] ? ' disabled' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hint">True / False: tick option A for True or option B for False.<?= $exam['allow_multiple_correct'] ? '' : ' Multiple-correct is off for this test (Test settings).' ?></div>
        </div>
        <?php endif; ?>
        <div class="field"><label for="tp">Topic / category</label><input id="tp" type="text" name="topic" list="topics" maxlength="80" value="<?= e((string) $v('topic', $q['topic'] ?? ($prev['topic'] ?? ''))) ?>" placeholder="e.g. Polity › Fundamental Rights">
          <datalist id="topics"><?php foreach ($suggest as $tp): ?><option value="<?= e($tp['topic']) ?>"><?php endforeach; ?></datalist>
          <?php if ($examTopics): ?><div class="hint">In this test: <?= e(implode(', ', array_slice(array_map('strval', array_keys($examTopics)), 0, 8))) ?></div><?php endif; ?></div>
        <div class="field"><label for="so">Order</label><input id="so" type="number" name="sort_order" value="<?= (int) $v('sort_order', $q['sort_order'] ?? count($questions) + 1) ?>"></div>
        <?php if (!$correction): ?>
        <div class="field"><label for="mk">Marks</label><input id="mk" type="number" step="0.25" min="0.25" name="marks" value="<?= e((string) $v('marks', exam_format_marks((float) ($q['marks'] ?? ($prev['marks'] ?? 1))))) ?>"></div>
        <div class="field"><label for="nm">Negative marks <?= $exam['negative_marking'] ? '' : '(negative marking is off for this test)' ?></label><input id="nm" type="number" step="0.01" min="0" name="negative_marks" value="<?= e((string) $v('negative_marks', exam_format_marks((float) ($q['negative_marks'] ?? ($prev['negative_marks'] ?? 0))))) ?>">
          <div class="hint"><?= $exam['negative_marking'] ? 'Leave 0 to use the test default (−' . e(exam_format_marks((float) $exam['default_negative_marks'])) . ').' : 'Turn on negative marking in Test settings to use this.' ?></div></div>
        <div class="field full"><span class="lbl" id="opts-lbl">Options - tick the correct answer (or answers)</span>
          <?php
          $tickedPosted = $formValues !== null ? array_map('intval', (array) ($formValues['correct'] ?? [])) : null;
          for ($i = 0; $i < OPTION_SLOTS; $i++):
              $o = $qo[$i] ?? null;
              $textVal = $formValues !== null ? (string) (((array) ($formValues['options'] ?? []))[$i] ?? '') : ($o['option_text'] ?? ($type === 'true_false' ? (['True', 'False'][$i] ?? '') : ''));
              $isTicked = $tickedPosted !== null ? in_array($i, $tickedPosted, true) : ($o && $o['is_correct']);
          ?>
          <div class="d1-opt-row">
            <label class="check"><input type="checkbox" name="correct[]" value="<?= $i ?>"<?= $isTicked ? ' checked' : '' ?>> Option <?= chr(65 + $i) ?></label>
            <input class="in" name="options[]" value="<?= e($textVal) ?>" placeholder="<?= $i < 2 ? 'Required' : 'Optional' ?>" aria-label="Option <?= chr(65 + $i) ?> text">
          </div>
          <?php endfor; ?>
        </div>
        <?php else: ?>
        <div class="field"><span class="lbl">Marks</span><div>+<?= e(exam_format_marks((float) $q['marks'])) ?><?= exam_negative_for($exam, $q) > 0 ? ' / −' . e(exam_format_marks(exam_negative_for($exam, $q))) : '' ?> <span class="muted">(fixed)</span></div></div>
        <div class="field full"><span class="lbl">Options - fix wording or correct the answer key</span>
          <?php
          $tickedPosted = $formValues !== null ? array_map('intval', (array) ($formValues['opt_correct'] ?? [])) : null;
          foreach ($qo as $i => $o):
              $textVal = $formValues !== null ? (string) (((array) ($formValues['opt_text'] ?? []))[$o['id']] ?? '') : $o['option_text'];
              $isTicked = $tickedPosted !== null ? in_array((int) $o['id'], $tickedPosted, true) : (bool) $o['is_correct'];
          ?>
          <div class="d1-opt-row">
            <label class="check"><input type="checkbox" name="opt_correct[]" value="<?= (int) $o['id'] ?>"<?= $isTicked ? ' checked' : '' ?>> Option <?= chr(65 + $i) ?></label>
            <input class="in" name="opt_text[<?= (int) $o['id'] ?>]" value="<?= e($textVal) ?>" required aria-label="Option <?= chr(65 + $i) ?> text">
          </div>
          <?php endforeach; ?>
          <div class="hint">Changing the ticks corrects the answer key. Re-score attempts afterwards so existing results use it.</div>
        </div>
        <?php endif; ?>
        <div class="field full"><label for="ex">Explanation (shown with the answer key)</label><textarea id="ex" name="explanation" rows="3"><?= e((string) $v('explanation', $q['explanation'] ?? '')) ?></textarea></div>
        <?php if ($v11): ?>
        <details class="field full qx-hi"<?= $hiOpen ? ' open' : '' ?>>
          <summary><?= icon('languages') ?> हिंदी version <span class="muted">(optional - students switch language during the test)</span></summary>
          <div class="qx-hi-body">
            <label for="qt-hi" class="lbl">प्रश्न · Question in Hindi</label>
            <textarea id="qt-hi" name="question_text_hi" rows="3" lang="hi"><?= e((string) $v('question_text_hi', $q['question_text_hi'] ?? '')) ?></textarea>
            <span class="lbl" style="margin-top:10px">विकल्प · Options in Hindi <span class="muted">(same order as the English options; True / False shows सत्य / असत्य)</span></span>
            <?php if (!$correction):
                $hiPosted = $formValues !== null ? array_values((array) ($formValues['options_hi'] ?? [])) : null;
                for ($i = 0; $i < OPTION_SLOTS; $i++):
                    $hiVal = $hiPosted !== null ? (string) ($hiPosted[$i] ?? '') : (string) ($qo[$i]['option_text_hi'] ?? '');
            ?>
            <div class="d1-opt-row qx-hi-row"><span class="qx-hi-lbl">विकल्प <?= chr(65 + $i) ?></span><input class="in" name="options_hi[]" lang="hi" value="<?= e($hiVal) ?>" aria-label="Option <?= chr(65 + $i) ?> in Hindi"></div>
            <?php endfor; else:
                foreach ($qo as $i => $o):
                    $hiVal = $formValues !== null ? (string) (((array) ($formValues['opt_text_hi'] ?? []))[$o['id']] ?? '') : (string) ($o['option_text_hi'] ?? '');
            ?>
            <div class="d1-opt-row qx-hi-row"><span class="qx-hi-lbl">विकल्प <?= chr(65 + $i) ?></span><input class="in" name="opt_text_hi[<?= (int) $o['id'] ?>]" lang="hi" value="<?= e($hiVal) ?>" aria-label="Option <?= chr(65 + $i) ?> in Hindi"></div>
            <?php endforeach; endif; ?>
            <label for="ex-hi" class="lbl" style="margin-top:10px">व्याख्या · Explanation in Hindi</label>
            <textarea id="ex-hi" name="explanation_hi" rows="3" lang="hi"><?= e((string) $v('explanation_hi', $q['explanation_hi'] ?? '')) ?></textarea>
          </div>
        </details>
        <?php endif; ?>
      </div>
      <div class="form-actions"><span class="dirty-flag"><?= icon('alert') ?> Unsaved changes</span><a class="btn btn-light" href="?exam=<?= $examId ?>">Cancel</a><?php if (!$hasAttempts): ?><button class="btn btn-light" name="add_another" value="1" title="Ctrl + Enter">Save &amp; add another</button><?php endif; ?><button class="btn btn-primary" title="Ctrl + S"><?= icon('check') ?> Save</button></div>
    </form>
    <aside class="card qx-preview" aria-label="Live preview" data-qx-preview data-no="<?= $position ?>" data-total="<?= max($position, count($questions)) ?>" data-type="<?= e($type) ?>"
      data-neg-on="<?= $exam['negative_marking'] ? 1 : 0 ?>" data-neg-default="<?= e(exam_format_marks((float) $exam['default_negative_marks'])) ?>"
      <?= $correction ? 'data-marks="' . e(exam_format_marks((float) $q['marks'])) . '" data-neg="' . e(exam_format_marks((float) $q['negative_marks'])) . '"' : '' ?>>
      <div class="card-head"><h2><?= icon('eye') ?> Live preview</h2>
        <div class="qx-head-tools">
          <?php if ($v11): ?><div class="cp-seg" role="group" aria-label="Preview language"><a href="#" class="on" data-qx-lang="en" aria-pressed="true">EN</a><a href="#" data-qx-lang="hi" aria-pressed="false" lang="hi">हिंदी</a></div><?php endif; ?>
          <div class="cp-seg" role="group" aria-label="Preview as"><a href="#" class="on" data-qx-view="student" aria-pressed="true">Student view</a><a href="#" data-qx-view="key" aria-pressed="false">Answer key</a></div>
        </div></div>
      <div class="qx-stage"><article class="qx-q" data-qx-card></article></div>
      <ul class="qx-checks" data-qx-checks aria-label="Before you save"></ul>
      <p class="qx-keys muted"><?= $hasAttempts ? '' : '<kbd>Ctrl</kbd> + <kbd>Enter</kbd> save &amp; add the next question · ' ?><kbd>Ctrl</kbd> + <kbd>S</kbd> save</p>
    </aside>
    </div>
        <?php
    }
}

// ------------------------------------------------------------------
// Question list
// ------------------------------------------------------------------
if (!$questions) {
    echo '<div class="card empty"><span class="empty-ic">' . icon('clipboard') . '</span><strong>No questions yet</strong><p>Add questions one by one, import a CSV or reuse questions from another test.</p>'
        . (!$hasAttempts ? '<a class="btn btn-primary btn-sm" href="?exam=' . $examId . '&amp;edit=new">' . icon('plus') . ' Add question</a>' : '') . '</div>';
}
$qTypes = ['single' => 'Single answer', 'true_false' => 'True / False', 'multiple' => 'Multiple correct'];
foreach ($questions as $n => $q) {
    if (($topicFilter !== '' && $q['topic'] !== $topicFilter) || ($sectionFilter && (int) ($q['section_id'] ?? 0) !== $sectionFilter)) {
        continue;
    }
    $neg = exam_negative_for($exam, $q);
    $qHi = exam_hi($q['question_text_hi'] ?? null);
    echo '<article class="card card-pad d1-q" id="q-' . ($n + 1) . '"><div class="d1-q-head"><div><span class="d1-q-no">Q' . ($n + 1) . '</span>' . nl2br(e($q['question_text']))
        . ($qHi ? '<div class="qx-hi-line" lang="hi">' . nl2br(e($qHi)) . '</div>' : '')
        . '<div class="d1-sub" style="margin-top:6px"><span>' . e($qTypes[$q['question_type']] ?? $q['question_type']) . '</span><span>+' . e(exam_format_marks((float) $q['marks'])) . ($neg > 0 ? ' / −' . e(exam_format_marks($neg)) . ((float) $q['negative_marks'] > 0 ? '' : ' (test default)') : '') . '</span>'
        . ($sections ? '<span class="badge b-purple">' . e($sectionTitle[(int) ($q['section_id'] ?? 0)] ?? 'No section') . '</span>' : '')
        . ($q['topic'] ? '<span class="badge b-blue">' . e($q['topic']) . '</span>' : '')
        . ($qHi ? '<span class="badge b-green" lang="hi" title="Has a Hindi version">हिंदी</span>' : '') . '</div></div><div class="d1-acts">';
    echo '<a class="btn btn-light btn-sm' . ($hasAttempts ? '' : ' btn-icon') . '" href="?exam=' . $examId . '&amp;edit=' . (int) $q['id'] . '#question-form" title="' . ($hasAttempts ? 'Correct wording or answer key' : 'Edit question') . '" aria-label="' . ($hasAttempts ? 'Correct question ' : 'Edit question ') . ($n + 1) . '">' . icon('edit') . ($hasAttempts ? ' Correct' : '') . '</a>';
    if (!$hasAttempts) {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int) $q['id'] . '"><button class="btn btn-danger btn-sm btn-icon" aria-label="Delete question ' . ($n + 1) . '" title="Delete question" data-confirm="Delete question ' . ($n + 1) . '? This cannot be undone.">' . icon('trash') . '</button></form>';
    }
    echo '</div></div>';
    if ($q['image_path']) {
        echo '<img class="d1-q-img" src="' . e(public_file_url($q['image_path'])) . '" alt="Image for question ' . ($n + 1) . '">';
    }
    echo '<ul class="d1-q-opts">';
    foreach ($opts[(int) $q['id']] ?? [] as $i => $o) {
        $oHi = $qHi ? exam_hi($o['option_text_hi'] ?? null) : null;
        echo '<li' . ($o['is_correct'] ? ' class="ok"' : '') . '><b>' . chr(65 + $i) . '</b><span>' . e($o['option_text']) . ($oHi ? '<span class="qx-hi-opt" lang="hi"> / ' . e($oHi) . '</span>' : '') . '</span>' . ($o['is_correct'] ? '<span class="tag">' . icon('check', 'i') . '<span class="sr-only">Correct answer</span></span>' : '') . '</li>';
    }
    echo '</ul>' . ($q['explanation'] ? '<p class="d1-q-expl"><strong>Explanation:</strong> ' . e($q['explanation']) . '</p>' : '') . '</article>';
}
// Live preview, paste preview and keyboard shortcuts
echo '<script src="' . e(app_url('assets/admin-questions.js')) . '?v=' . (int) @filemtime(APP_ROOT . '/assets/admin-questions.js') . '"></script>';
admin_end();
