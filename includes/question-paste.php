<?php
/**
 * Question authoring helpers for admin/questions.php: the answer-key rules, inserting a batch of
 * questions, and the "paste many questions" parser (one parser for the live preview and the import).
 *
 * Paste format (one question after another; blank lines are optional):
 *   12. Question text (more lines allowed, e.g. numbered statements 1. 2. 3.)
 *   A) option          also (a) / a. / A: / [A] / Hindi (क) (ख) (ग) (घ); or all on one line: A) x B) y C) z D) w
 *   Answer: B          also Ans / Correct / उत्तर ; "A, C" for multiple-correct ; True / False
 *   Explanation: …     optional, may continue on the next lines (also Solution / व्याख्या)
 *   Topic: Polity      optional ; Marks: 2 / 0.5 optional (+marks / negative marks)
 *   Section: Reasoning optional heading line: the questions after it go into that section
 * Statements inside a question can be numbered 1. 2. 3. (they stay question text until options start).
 * A Hindi version is pasted separately in the same order and attached with paste_attach_hindi().
 */
declare(strict_types=1);

if (!defined('LAXYAM_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once APP_ROOT . '/includes/exams.php';

const PASTE_MAX_QUESTIONS = 500;
const PASTE_MAX_BYTES = 400_000;
const PASTE_OPTION_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const PASTE_HINDI_LETTERS = ['क' => 'A', 'ख' => 'B', 'ग' => 'C', 'घ' => 'D', 'ङ' => 'E', 'च' => 'F'];

/** Answer-key rules shared by the question form, CSV import and paste import. */
function question_key_error(string $type, array $options, bool $allowMultiple): ?string
{
    $correct = count(array_filter($options, fn($o) => $o['correct']));
    return match (true) {
        count($options) < 2 => 'Add at least two options.',
        $correct === 0 => 'Mark the correct answer.',
        $type !== 'multiple' && $correct !== 1 => 'Single-answer and True/False questions need exactly one correct option.',
        $type === 'multiple' && !$allowMultiple => 'Enable "Allow multiple-correct questions" on the test first.',
        default => null,
    };
}

/**
 * Append questions to a test (after the existing ones). Each row:
 * ['type', 'text', 'topic', 'explanation', 'marks', 'negative', 'options' => [['text', 'correct', 'text_hi'?], ...]]
 * plus optional 'text_hi', 'explanation_hi', 'section_id'. Returns how many.
 */
function insert_exam_questions(int $examId, array $rows): int
{
    $v11 = exam_v11();
    db_transaction(function () use ($rows, $examId, $v11) {
        $order = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM exam_questions WHERE exam_id = ?', [$examId]);
        foreach ($rows as $r) {
            $vals = [$examId, $r['type'], $r['topic'] ? mb_substr($r['topic'], 0, 80) : null, mb_substr($r['text'], 0, 5000), $r['explanation'] ?: null, $r['marks'], $r['negative'], ++$order];
            if ($v11) {
                db_query('INSERT INTO exam_questions (exam_id, question_type, topic, question_text, explanation, marks, negative_marks, sort_order, question_text_hi, explanation_hi, section_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [...$vals, exam_hi($r['text_hi'] ?? null) !== null ? mb_substr($r['text_hi'], 0, 5000) : null, exam_hi($r['explanation_hi'] ?? null), $r['section_id'] ?? null]);
            } else {
                db_query('INSERT INTO exam_questions (exam_id, question_type, topic, question_text, explanation, marks, negative_marks, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', $vals);
            }
            $qid = (int) db()->lastInsertId();
            foreach ($r['options'] as $i => $o) {
                $hi = exam_hi($o['text_hi'] ?? null);
                db_query('INSERT INTO exam_options (question_id, option_text, is_correct, sort_order' . ($v11 ? ', option_text_hi' : '') . ') VALUES (?, ?, ?, ?' . ($v11 ? ', ?' : '') . ')',
                    [$qid, mb_substr($o['text'], 0, 2000), $o['correct'] ? 1 : 0, $i + 1, ...($v11 ? [$hi !== null ? mb_substr($hi, 0, 2000) : null] : [])]);
            }
        }
    });
    return count($rows);
}

/** Hindi labels for True / False options. */
const PASTE_TRUE_FALSE_HI = ['सत्य', 'असत्य'];

/**
 * Attach a separately pasted Hindi version to parsed English questions (same order, same number of
 * options): adds text_hi, explanation_hi and options[i]['text_hi'], and reports mismatches as errors.
 * Returns [questions, extra] - extra = Hindi questions beyond the English ones.
 */
function paste_attach_hindi(array $questions, array $hindi): array
{
    foreach ($questions as $i => &$q) {
        $q['text_hi'] = null;
        $q['explanation_hi'] = null;
        $q['options'] = array_map(fn($o) => $o + ['text_hi' => null], $q['options']);
        if (!$hindi) {
            continue;
        }
        $h = $hindi[$i] ?? null;
        if ($h === null) {
            $q['errors'][] = 'Hindi version missing - the Hindi box has fewer questions.';
            continue;
        }
        if (trim($h['text']) === '') {
            $q['errors'][] = 'Hindi question text is empty.';
        }
        $q['text_hi'] = $h['text'];
        $q['explanation_hi'] = $h['explanation'];
        if ($q['type'] === 'true_false') {
            foreach ($q['options'] as $k => &$o) {
                $o['text_hi'] = $h['options'][$k]['text'] ?? PASTE_TRUE_FALSE_HI[$k] ?? null;
                if (in_array(mb_strtolower((string) $o['text_hi']), ['true', 'false'], true)) {
                    $o['text_hi'] = PASTE_TRUE_FALSE_HI[$k]; // "उत्तर: सत्य" makes English True/False labels
                }
            }
            unset($o);
        } elseif (count($h['options']) !== count($q['options'])) {
            $q['errors'][] = 'The Hindi version has ' . count($h['options']) . ' options, the English one has ' . count($q['options']) . '.';
        } else {
            foreach ($q['options'] as $k => &$o) {
                $o['text_hi'] = $h['options'][$k]['text'];
            }
            unset($o);
            if ($h['answered'] && array_column($h['options'], 'correct') !== array_column($q['options'], 'correct')) {
                $q['errors'][] = 'The Hindi answer differs from the English answer.';
            }
        }
    }
    unset($q);
    return [$questions, max(0, count($hindi) - count($questions))];
}

/** Option letter (A-F, a-f or Hindi क-च) → index 0-5, or null. */
function paste_letter_index(string $letter): ?int
{
    $letter = PASTE_HINDI_LETTERS[$letter] ?? strtoupper($letter);
    $i = array_search($letter, PASTE_OPTION_LETTERS, true);
    return $i === false ? null : $i;
}

/** "A) x B) y C) z" on one line → ['x', 'y', 'z'] when the letters run in order from $start; else null. */
function paste_inline_options(string $line, int $start): ?array
{
    $marker = '[(\[]?(?:[A-Fa-f]|[कखगघङच])[)\].:]\s+';
    $parts = preg_split('/(?:^|\s+)(?=' . $marker . ')/u', $line, -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts || count($parts) < 2) {
        return null;
    }
    $out = [];
    foreach ($parts as $k => $part) {
        if (!preg_match('/^[(\[]?([A-Fa-f]|[कखगघङच])[)\].:]\s+(.+)$/u', trim($part), $m) || paste_letter_index($m[1]) !== $start + $k) {
            return null;
        }
        $out[] = trim($m[2]);
    }
    return $out;
}

/**
 * Options typed on the same line as the question ("Synonym of brave? A) x B) y C) z") → [question, options].
 * Only used for a question that has no separate option lines, so lists inside a question stay intact.
 */
function paste_trailing_options(string $text): ?array
{
    preg_match_all('/(?:^|\s)(?=[(\[]?(?:A|a|क)[)\].:]\s+)/u', $text, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$match, $offset]) {
        $start = $offset + strlen($match);
        if (($options = paste_inline_options(substr($text, $start), 0)) && count($options) >= 2) {
            return [rtrim(substr($text, 0, $start)), $options];
        }
    }
    return null;
}

/**
 * Parse pasted MCQs. Defaults apply where a question has no Marks:/Topic: line. With $keyOptional
 * (a pasted Hindi version) a missing answer line is not an error - the key comes from the English.
 * Returns a list of ['n', 'line', 'type', 'text', 'topic', 'section', 'explanation', 'marks', 'negative', 'options', 'answered', 'errors'].
 */
function parse_pasted_questions(string $text, float $marks, float $negative, ?string $topic, bool $allowMultiple, bool $keyOptional = false): array
{
    $lines = explode("\n", str_replace(["\r\n", "\r", "\u{00A0}", "\t"], ["\n", "\n", ' ', ' '], $text));
    $blocks = [];
    $cur = null;
    $closed = false; // a blank line after the answer: the next text starts a new question
    $section = null; // set by a "Section: …" heading for the questions that follow
    $new = function (string $t, int $line) use (&$section): array {
        return ['text' => $t, 'line' => $line, 'options' => [], 'answer' => null, 'explanation' => null, 'topic' => null, 'section' => $section, 'marks' => null, 'negative' => null, 'mode' => 'question'];
    };
    foreach ($lines as $no => $raw) {
        $line = trim($raw);
        if ($line === '') {
            $closed = $closed || ($cur !== null && $cur['answer'] !== null);
            continue;
        }
        if (preg_match('/^(?:section|खंड|भाग)\s*:\s*(.+)$/iu', $line, $m)) {
            $section = mb_substr(trim($m[1]), 0, 80);
            $closed = $closed || ($cur !== null && $cur['answer'] !== null);
            continue;
        }
        if ($cur !== null) {
            // "Answer: B" / "Ans - A, C" / "उत्तर: ख"; without a separator only bare letters count ("Answer B"),
            // so a sentence such as "Correct a sentence…" inside a question is not taken for an answer.
            $bare = '(?:option\s+)?[(\[]?(?:[A-Fa-f]|[कखगघङच])[)\]]?(?:\s*(?:[,;&\/]|and)\s*[(\[]?(?:[A-Fa-f]|[कखगघङच])[)\]]?)*\.?$|(?:true|false)\.?$';
            if (preg_match('/^(?:ans(?:wer)?|correct(?:\s+answers?)?|right\s+answer|key|उत्तर|सही\s+उत्तर)\s*(?:[:.\-–=)]\s*|\s+(?=' . $bare . '))(.+)$/iu', $line, $m)) {
                $cur['answer'] = trim($m[1]);
                $cur['mode'] = 'answer';
                continue;
            }
            if (preg_match('/^(?:explanation|expl|solution|sol|व्याख्या|हल)\s*[:.\-–)]\s*(.*)$/iu', $line, $m)) {
                $cur['explanation'] = trim($m[1]);
                $cur['mode'] = 'explanation';
                continue;
            }
            // Topic / marks need a colon ("Subject-verb agreement…" stays question text).
            if (preg_match('/^(?:topic|subject|chapter|विषय)\s*:\s*(.+)$/iu', $line, $m)) {
                $cur['topic'] = trim($m[1]);
                continue;
            }
            if (preg_match('/^marks?\s*:\s*\+?(\d+(?:\.\d+)?)(?:\s*[\/,|]\s*[-−–]?\s*(\d+(?:\.\d+)?))?\s*$/iu', $line, $m)) {
                $cur['marks'] = (float) $m[1];
                if (isset($m[2]) && $m[2] !== '') {
                    $cur['negative'] = (float) $m[2];
                }
                continue;
            }
            // Options run in order (A, then B, …), so a sentence that happens to start with "D." is not mistaken for one.
            if ($cur['mode'] !== 'explanation' && !$closed) {
                $next = count($cur['options']);
                if ($inline = paste_inline_options($line, $next)) {
                    array_push($cur['options'], ...$inline);
                    $cur['mode'] = 'options';
                    continue;
                }
                if (preg_match('/^[(\[]?([A-Fa-f]|[कखगघङच])[)\].:]\s*(.+)$/u', $line, $m) && paste_letter_index($m[1]) === $next) {
                    $cur['options'][] = trim($m[2]);
                    $cur['mode'] = 'options';
                    continue;
                }
            }
        }
        // A numbered line starts a question - unless the current one has no options yet
        // (then it is a numbered statement inside the question, e.g. "1. … 2. … Which are correct?").
        $numbered = preg_match('/^(?:(?:q(?:uestion)?|प्रश्न)\s*\.?\s*)?(\d{1,3})\s*[.):\-]\s*(.*)$/iu', $line, $m);
        $labelled = !$numbered && preg_match('/^(?:q(?:uestion)?|प्रश्न)\s*[.:\-)]\s*(.+)$/iu', $line, $m2);
        if (($numbered || $labelled) && ($cur === null || count($cur['options']) >= 2 || $closed)) {
            if ($cur !== null) {
                $blocks[] = $cur;
            }
            $cur = $new(trim($numbered ? $m[2] : $m2[1]), $no + 1);
            $closed = false;
            continue;
        }
        if ($cur === null || $closed || $cur['mode'] === 'answer') { // unnumbered question after a finished one
            if ($cur !== null) {
                $blocks[] = $cur;
            }
            $cur = $new($line, $no + 1);
            $closed = false;
            continue;
        }
        match ($cur['mode']) {
            'explanation' => $cur['explanation'] = trim(($cur['explanation'] ?? '') . "\n" . $line),
            'options' => $cur['options'][count($cur['options']) - 1] .= ' ' . $line, // option text on two lines
            default => $cur['text'] = trim($cur['text'] . "\n" . $line),
        };
    }
    if ($cur !== null) {
        $blocks[] = $cur;
    }

    $out = [];
    foreach ($blocks as $i => $b) {
        if (!$b['options'] && ($split = paste_trailing_options($b['text']))) {
            [$b['text'], $b['options']] = $split;
        }
        $errors = [];
        $opts = array_map(fn($t) => ['text' => mb_substr(trim($t), 0, 2000), 'correct' => false], $b['options']);
        $answer = trim((string) $b['answer'], " \t.()[]");
        $tf = null;
        if (preg_match('/^(true|false|सत्य|असत्य|सही|गलत)$/iu', $answer, $m)) {
            $tf = in_array(mb_strtolower($m[1]), ['true', 'सत्य', 'सही'], true) ? 0 : 1;
            if (!$opts) {
                $opts = [['text' => 'True', 'correct' => false], ['text' => 'False', 'correct' => false]];
            }
        }
        $isTrueFalse = count($opts) === 2 && strcasecmp($opts[0]['text'], 'True') === 0 && strcasecmp($opts[1]['text'], 'False') === 0;
        $letters = [];
        if ($tf !== null) {
            $letters = [$tf];
        } elseif ($answer !== '') {
            // Stand-alone letters only: not the "a" in "and", nor क inside a Hindi word (vowel signs are \p{M}).
            preg_match_all('/(?<![\p{L}\p{M}])([A-Fa-f]|[कखगघङच])(?![\p{L}\p{M}])/u', preg_replace('/^(?:option|opt|विकल्प)\s*/iu', '', $answer), $mm);
            $letters = array_values(array_unique(array_map('paste_letter_index', $mm[1])));
        }
        foreach ($letters as $li) {
            if ($li === null || !isset($opts[$li])) {
                $errors[] = 'The answer names option ' . ($li === null ? '?' : PASTE_OPTION_LETTERS[$li]) . ', which is not in the list.';
                continue;
            }
            $opts[$li]['correct'] = true;
        }
        $type = count($letters) > 1 ? 'multiple' : ($isTrueFalse || $tf !== null ? 'true_false' : 'single');
        $qMarks = $b['marks'] ?? $marks;
        $text = trim($b['text']);
        if ($text === '') {
            $errors[] = 'Question text is empty.';
        }
        if (count($opts) > 6) {
            $errors[] = 'At most 6 options (A-F).';
        }
        if ($b['answer'] === null) {
            if (!$keyOptional) {
                $errors[] = 'No answer line - add “Answer: B”.';
            }
        } elseif (!$errors && ($k = question_key_error($type, $opts, $allowMultiple))) {
            $errors[] = $k;
        }
        if ($qMarks <= 0) {
            $errors[] = 'Marks must be more than 0.';
        }
        $out[] = [
            'n' => $i + 1, 'line' => $b['line'], 'type' => $type, 'text' => mb_substr($text, 0, 5000),
            'topic' => ($b['topic'] ?? $topic) ?: null, 'section' => $b['section'], 'explanation' => $b['explanation'] !== null && $b['explanation'] !== '' ? $b['explanation'] : null,
            'marks' => $qMarks, 'negative' => $b['negative'] ?? $negative, 'options' => array_slice($opts, 0, 6), 'answered' => $b['answer'] !== null, 'errors' => $errors,
        ];
        if (count($out) >= PASTE_MAX_QUESTIONS) {
            break;
        }
    }
    return $out;
}
