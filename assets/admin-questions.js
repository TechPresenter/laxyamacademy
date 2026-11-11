/* Question editor (admin/questions.php): side-by-side live preview, "paste many questions"
   preview and keyboard shortcuts. Typed text is rendered with textContent only (never as HTML). */
(() => {
  'use strict';

  const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
  const el = (tag, cls, text) => {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  };
  const fmt = (v) => {
    const n = Number(v);
    return Number.isFinite(n) ? String(Math.round(n * 100) / 100) : '0';
  };

  /** A question card that mirrors the student's exam screen (js/exam/ExamRoom.jsx). lang 'hi' shows the Hindi version. */
  function renderQuestion(q, { showKey = false, compact = false, lang = 'en' } = {}) {
    const hi = lang === 'hi' && !!q.text_hi;
    const card = el('article', 'qx-q' + (compact ? ' compact' : '') + (q.errors && q.errors.length ? ' has-error' : ''));
    if (hi) card.lang = 'hi';
    const top = el('div', 'qx-top');
    top.append(el('span', 'qx-no', 'Q' + q.no));
    if (q.total) top.append(el('span', 'qx-of', 'of ' + q.total));
    if (q.section) top.append(el('span', 'qx-sec', q.section));
    if (q.topic) top.append(el('span', 'qx-topic', q.topic));
    const marks = el('span', 'qx-marks');
    marks.append(el('b', 'plus', '+' + fmt(q.marks)), el('b', 'minus' + (Number(q.negative) > 0 ? '' : ' zero'), '−' + fmt(q.negative)));
    top.append(marks);
    card.append(top);
    const kind = el('p', 'qx-kind', q.type === 'multiple' ? 'Multiple correct · select all that apply' : 'Single correct answer');
    if (lang === 'hi' && !hi) kind.append(el('span', 'qx-nohi', ' · हिंदी version नहीं - English दिखेगा'));
    card.append(kind);
    const text = hi ? q.text_hi : q.text;
    card.append(el('h3', 'qx-text' + (text ? '' : ' empty'), text || 'Your question appears here as you type…'));
    if (q.imageUrl) {
      const img = el('img', 'qx-img');
      img.src = q.imageUrl;
      img.alt = 'Figure for question ' + q.no;
      card.append(img);
    }
    const list = el('div', 'qx-opts');
    (q.options.length ? q.options : [{ text: '', correct: false }, { text: '', correct: false }]).forEach((o, i) => {
      const right = showKey && o.correct;
      const label = hi && o.text_hi ? o.text_hi : o.text;
      const row = el('div', 'qx-opt' + (right ? ' ok' : '') + (label ? '' : ' empty'));
      row.append(el('span', 'l', LETTERS[i] || String(i + 1)), el('span', 't', label || 'Option ' + (LETTERS[i] || i + 1)));
      if (right) row.append(el('span', 'tag', hi ? '✓ सही उत्तर' : '✓ Correct answer'));
      list.append(row);
    });
    card.append(list);
    const explanation = hi && q.explanation_hi ? q.explanation_hi : q.explanation;
    if (showKey && explanation) {
      const ex = el('div', 'qx-expl');
      ex.append(el('strong', '', hi && q.explanation_hi ? 'व्याख्या: ' : 'Explanation: '), document.createTextNode(explanation));
      card.append(ex);
    }
    if (q.errors && q.errors.length) {
      const errs = el('ul', 'qx-errs');
      q.errors.forEach((m) => errs.append(el('li', '', m)));
      card.append(errs);
    }
    return card;
  }

  // ------------------------------------------------------------------
  // 1. Single question: live preview next to the form
  // ------------------------------------------------------------------
  const form = document.getElementById('question-form');
  const panel = document.querySelector('[data-qx-preview]');
  if (form && panel) {
    const stage = panel.querySelector('[data-qx-card]');
    const checks = panel.querySelector('[data-qx-checks]');
    let showKey = false;
    let lang = 'en';
    let imageUrl = null;
    const fileInput = form.querySelector('#qimg');
    const existingImg = form.querySelector('#qimg-pv');
    if (existingImg && !existingImg.hidden && existingImg.getAttribute('src')) imageUrl = existingImg.src;

    const read = () => {
      const typeSel = form.querySelector('#qtype');
      const type = typeSel ? typeSel.value : (panel.dataset.type || 'single'); // fixed type when correcting an attempted test
      const rows = [...form.querySelectorAll('.d1-opt-row:not(.qx-hi-row)')];
      const hiInputs = [...form.querySelectorAll('.qx-hi-row input')]; // same slots / order as the English options
      let options = rows.map((r, i) => {
        const text = r.querySelector('input[name="options[]"], input[name^="opt_text["]');
        const tick = r.querySelector('input[type="checkbox"]');
        return { text: text ? text.value.trim() : '', text_hi: hiInputs[i] ? hiInputs[i].value.trim() : '', correct: !!(tick && tick.checked) };
      });
      if (type === 'true_false') {
        options = [{ text: 'True', text_hi: 'सत्य', correct: options[0] ? options[0].correct : false }, { text: 'False', text_hi: 'असत्य', correct: options[1] ? options[1].correct : false }];
      } else {
        options = options.filter((o) => o.text !== '' || o.correct);
      }
      const secSel = form.querySelector('#sec');
      const marksIn = form.querySelector('#mk');
      const negIn = form.querySelector('#nm');
      const own = negIn ? Number(negIn.value) : Number(panel.dataset.neg || 0);
      const negOn = panel.dataset.negOn === '1';
      const removeImg = form.querySelector('input[name="image_remove"]');
      return {
        no: panel.dataset.no, total: panel.dataset.total, type,
        topic: (form.querySelector('#tp') || {}).value || '',
        marks: marksIn ? marksIn.value : panel.dataset.marks,
        negative: negOn ? (own > 0 ? own : Number(panel.dataset.negDefault || 0)) : 0,
        text: (form.querySelector('#qt') || {}).value ? form.querySelector('#qt').value.trim() : '',
        text_hi: ((form.querySelector('#qt-hi') || {}).value || '').trim(),
        section: secSel && secSel.value !== '0' ? secSel.options[secSel.selectedIndex].text : '',
        imageUrl: removeImg && removeImg.checked ? null : imageUrl,
        options,
        explanation: ((form.querySelector('#ex') || {}).value || '').trim(),
        explanation_hi: ((form.querySelector('#ex-hi') || {}).value || '').trim(),
      };
    };

    const update = () => {
      const q = read();
      const card = renderQuestion(q, { showKey, lang });
      stage.lang = card.lang;
      stage.replaceChildren(...card.childNodes);
      const correct = q.options.filter((o) => o.correct).length;
      const items = [
        [q.text !== '', 'Question text'],
        [q.options.filter((o) => o.text !== '').length >= 2, 'At least two options'],
        [correct > 0, 'Correct answer ticked'],
        [q.type === 'multiple' || correct <= 1, 'Only one answer ticked (single answer)'],
        [q.explanation !== '', 'Explanation added (optional, shown after the test)'],
      ];
      if (q.text_hi !== '' && q.type !== 'true_false') {
        const done = q.options.filter((o) => o.text_hi !== '').length;
        items.push([done === q.options.length, `Hindi options filled (${done} of ${q.options.length})`]);
      }
      checks.replaceChildren(...items.map(([ok, label]) => el('li', ok ? 'ok' : 'bad', label)));
    };

    const toggle = (attr, set) => panel.querySelectorAll(`[${attr}]`).forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault();
      set(b);
      panel.querySelectorAll(`[${attr}]`).forEach((x) => {
        x.classList.toggle('on', x === b);
        x.setAttribute('aria-pressed', String(x === b));
      });
      update();
    }));
    toggle('data-qx-view', (b) => { showKey = b.dataset.qxView === 'key'; });
    toggle('data-qx-lang', (b) => {
      lang = b.dataset.qxLang;
      const box = form.querySelector('details.qx-hi');
      if (lang === 'hi' && box) box.open = true; // show the Hindi fields while previewing Hindi
    });
    if (fileInput) {
      fileInput.addEventListener('change', () => {
        if (imageUrl && imageUrl.startsWith('blob:')) URL.revokeObjectURL(imageUrl);
        imageUrl = fileInput.files && fileInput.files[0] ? URL.createObjectURL(fileInput.files[0]) : (existingImg && existingImg.getAttribute('src') ? existingImg.src : null);
        update();
      });
    }
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();

    // Ctrl/Cmd + Enter = save & add another; Ctrl/Cmd + S = save
    form.addEventListener('keydown', (e) => {
      if (!(e.ctrlKey || e.metaKey)) return;
      const another = form.querySelector('button[name="add_another"]');
      const save = form.querySelector('.form-actions .btn-primary');
      if (e.key === 'Enter') {
        e.preventDefault();
        form.requestSubmit(another || save);
      } else if (e.key.toLowerCase() === 's') {
        e.preventDefault();
        form.requestSubmit(save);
      }
    });
  }

  // ------------------------------------------------------------------
  // 2. Paste many questions: live preview from the server's parser
  // ------------------------------------------------------------------
  const paste = document.querySelector('[data-qx-paste]');
  if (paste) {
    const area = paste.querySelector('#pd-text');
    const list = paste.querySelector('[data-qx-list]');
    const summary = paste.querySelector('[data-qx-summary]');
    const count = paste.querySelector('[data-qx-count]');
    const submit = paste.querySelector('[data-qx-import]');
    const existing = Number(paste.dataset.existing || 0);
    let seq = 0;
    let timer = null;
    let lang = 'en';
    let last = null; // last parse result, re-drawn when the preview language changes

    const selectLines = (from, to) => {
      const lines = area.value.split('\n');
      let start = 0;
      for (let i = 0; i < from - 1 && i < lines.length; i++) start += lines[i].length + 1;
      let end = start;
      for (let i = from - 1; i < Math.min(to, lines.length); i++) end += lines[i].length + 1;
      area.focus();
      area.setSelectionRange(start, Math.max(start, end - 1));
      const lineHeight = parseFloat(getComputedStyle(area).lineHeight) || 20;
      area.scrollTop = Math.max(0, (from - 3) * lineHeight);
    };

    const render = (data) => {
      last = data;
      const qs = data.questions || [];
      const bad = qs.filter((q) => q.errors.length).length;
      const blocking = data.ok && data.error ? data.error : null; // e.g. the Hindi box has extra questions
      list.replaceChildren(...qs.map((q, i) => {
        const card = renderQuestion({
          no: existing + q.n, topic: q.topic, section: q.section, marks: q.marks, negative: q.negative_effective, type: q.type,
          text: q.text, text_hi: q.text_hi, options: q.options, explanation: q.explanation, explanation_hi: q.explanation_hi, errors: q.errors,
        }, { showKey: true, compact: true, lang });
        card.tabIndex = 0;
        card.title = 'Show this question in the text';
        const next = qs[i + 1] ? qs[i + 1].line - 1 : area.value.split('\n').length;
        const go = () => selectLines(q.line, next);
        card.addEventListener('click', go);
        card.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
        return card;
      }));
      if (!data.ok) {
        summary.replaceChildren(el('strong', '', data.error || 'Could not read the questions.'));
      } else if (!qs.length) {
        summary.textContent = 'Paste questions on the left - they appear here exactly as students will see them, with the correct answer marked.';
      } else {
        const withHindi = qs.filter((q) => q.text_hi).length;
        summary.replaceChildren(
          el('strong', '', qs.length + ' question' + (qs.length === 1 ? '' : 's')),
          document.createTextNode(' · will be Q' + (existing + 1) + '–Q' + (existing + qs.length) + (withHindi ? ' · हिंदी ' + withHindi + '/' + qs.length : '') + ' · '),
          el('span', bad || blocking ? 'qx-bad' : 'qx-good', bad ? bad + (bad === 1 ? ' needs' : ' need') + ' fixing (shown in red)' : blocking ? 'see below' : 'all ready'),
        );
        if (blocking) summary.append(el('span', 'qx-bad qx-block', blocking));
      }
      const ready = data.ok && qs.length > 0 && !bad && !blocking;
      submit.disabled = !ready;
      submit.lastChild.textContent = ' Add ' + (qs.length ? qs.length + ' question' + (qs.length === 1 ? '' : 's') : 'questions');
      count.textContent = qs.length ? (ready ? 'Ready to add' : bad + ' to fix first') : '';
    };

    const parse = () => {
      const mine = ++seq;
      const fd = new FormData(paste);
      fd.set('_action', 'paste_parse');
      fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => r.json())
        .then((data) => { if (mine === seq) render(data); })
        .catch(() => { if (mine === seq) render({ ok: false, error: 'Preview unavailable - check your connection and try again.', questions: [] }); });
    };
    const schedule = () => { clearTimeout(timer); timer = setTimeout(parse, 350); };

    paste.addEventListener('input', schedule);
    paste.addEventListener('change', (e) => { if (e.target.matches('select')) schedule(); }); // default section
    paste.querySelectorAll('[data-qx-plang]').forEach((b) => b.addEventListener('click', (e) => {
      e.preventDefault();
      lang = b.dataset.qxPlang;
      paste.querySelectorAll('[data-qx-plang]').forEach((x) => {
        x.classList.toggle('on', x === b);
        x.setAttribute('aria-pressed', String(x === b));
      });
      if (last) render(last);
    }));
    paste.addEventListener('submit', (e) => {
      if (submit.disabled) e.preventDefault();
    });
    const example = paste.querySelector('[data-qx-example]');
    if (example) {
      example.addEventListener('click', () => {
        area.value = (area.value.trim() ? area.value.replace(/\s+$/, '') + '\n\n' : '') + example.dataset.qxExample;
        area.dispatchEvent(new Event('input', { bubbles: true }));
        area.focus();
      });
    }
    const areaHi = paste.querySelector('#pd-text-hi');
    if (area.value.trim() || (areaHi && areaHi.value.trim())) parse(); // a draft kept after a failed import
  }
})();
