// Laxyam Academy admin panel - progressive enhancements (no dependencies).
// Every control here performs a UI action; server operations stay in PHP.
(function () {
  const body = document.body;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* storage blocked */ } },
  };
  const session = {
    get: (k) => { try { return sessionStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { sessionStorage.setItem(k, v); } catch { /* storage blocked */ } },
  };
  const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const isMobileNav = () => window.innerWidth <= 1100;

  // ---------- Sidebar: mini mode (desktop) and off-canvas drawer (tablet/phone) ----------
  if (store.get('lx-admin-collapsed') === '1' && !isMobileNav()) body.classList.add('collapsed');
  if (store.get('lx-admin-density') === 'compact') body.classList.add('compact');
  const sidebar = $('#admin-sidebar');
  const toggles = $$('[data-toggle-sidebar]');
  let lastFocus = null;
  function setDrawer(open) {
    body.classList.toggle('nav-open', open);
    document.documentElement.classList.toggle('no-scroll', open);
    toggles.forEach((t) => t.setAttribute('aria-expanded', String(open)));
    if (open) {
      lastFocus = document.activeElement;
      setTimeout(() => ($('a.active, .nav-link, summary', sidebar) || sidebar)?.focus?.(), 60);
    } else if (lastFocus) {
      lastFocus.focus?.();
      lastFocus = null;
    }
  }
  toggles.forEach((b) => b.addEventListener('click', () => {
    if (isMobileNav()) setDrawer(!body.classList.contains('nav-open'));
    else {
      const mini = body.classList.toggle('collapsed');
      store.set('lx-admin-collapsed', mini ? '1' : '0');
      b.setAttribute('aria-expanded', String(!mini));
    }
  }));
  $$('[data-close-sidebar]').forEach((b) => b.addEventListener('click', () => setDrawer(false)));
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && body.classList.contains('nav-open')) setDrawer(false);
    // Keep keyboard focus inside the open drawer
    if (e.key === 'Tab' && body.classList.contains('nav-open') && sidebar) {
      const f = $$('a[href],button:not([disabled]),summary', sidebar).filter((el) => el.offsetParent !== null);
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });
  // A desktop "mini" preference never applies inside the phone/tablet drawer.
  window.addEventListener('resize', () => {
    if (isMobileNav()) body.classList.remove('collapsed');
    else {
      if (body.classList.contains('nav-open')) setDrawer(false);
      if (store.get('lx-admin-collapsed') === '1') body.classList.add('collapsed');
    }
  });
  // In mini mode clicking a module expands the sidebar first.
  $$('.nav-group > summary').forEach((s) => s.addEventListener('click', (e) => {
    if (body.classList.contains('collapsed')) { e.preventDefault(); body.classList.remove('collapsed'); store.set('lx-admin-collapsed', '0'); s.parentElement.open = true; }
  }));
  // Keep the active sidebar item in view
  $('.sb-nav a.active, .sb-nav .nav-link.active')?.scrollIntoView?.({ block: 'center' });

  // ---------- Dark mode: saved per device; the first visit follows the device setting ----------
  const root = document.documentElement;
  const darkQuery = window.matchMedia?.('(prefers-color-scheme: dark)');
  const themeBtns = $$('[data-theme-toggle]');
  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    const dark = theme === 'dark';
    themeBtns.forEach((b) => {
      b.setAttribute('aria-pressed', String(dark));
      b.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
      b.title = dark ? 'Light mode' : 'Dark mode';
    });
    $('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0b1220' : '#08264D');
  }
  applyTheme(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');
  themeBtns.forEach((b) => b.addEventListener('click', () => {
    const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    store.set('lx-admin-theme', next);
    applyTheme(next);
    const sel = $('select[data-pref="theme"]');
    if (sel) sel.value = next;
  }));
  // While no choice is saved, follow the device when it switches between light and dark.
  darkQuery?.addEventListener?.('change', (e) => { if (!store.get('lx-admin-theme')) applyTheme(e.matches ? 'dark' : 'light'); });

  // Device preferences (profile.php / appearance.php → Layout): <input data-pref="collapsed|density|theme|…">
  $$('[data-pref]').forEach((el) => {
    const key = 'lx-admin-' + el.dataset.pref;
    if (el.type === 'checkbox') el.checked = store.get(key) === el.value;
    else el.value = store.get(key) || el.value;
    el.addEventListener('change', () => {
      const v = el.type === 'checkbox' ? (el.checked ? el.value : '') : el.value;
      if (el.dataset.pref === 'theme' && v === '') { try { localStorage.removeItem(key); } catch { /* storage blocked */ } }
      else store.set(key, v);
      if (el.dataset.pref === 'collapsed') body.classList.toggle('collapsed', v === '1' && !isMobileNav());
      if (el.dataset.pref === 'density') body.classList.toggle('compact', v === 'compact');
      if (el.dataset.pref === 'theme') applyTheme(v || (darkQuery?.matches ? 'dark' : 'light'));
      toast('Preference saved on this device.', 'success');
    });
  });

  // ---------- Toasts ----------
  const toastRegion = $('#toasts');
  function toast(message, type = 'info') {
    if (!toastRegion) return;
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    t.innerHTML = '<span></span><button type="button" aria-label="Dismiss">×</button>';
    t.firstChild.textContent = message;
    t.lastChild.addEventListener('click', () => t.remove());
    toastRegion.appendChild(t);
    setTimeout(() => t.remove(), type === 'error' ? 9000 : 5000);
  }
  window.lxToast = toast;
  // Older markup: <div class="alert" data-toast="type"> becomes a toast.
  $$('.alert[data-toast]').forEach((a) => { toast(a.textContent.trim(), a.dataset.toast); a.remove(); });

  // ---------- Flash messages: dismiss button + auto-dismiss for success/info ----------
  function dismiss(el) {
    if (reduceMotion) { el.remove(); return; }
    el.classList.add('leaving');
    setTimeout(() => el.remove(), 260);
  }
  $$('.alert[data-flash]').forEach((a) => {
    const x = document.createElement('button');
    x.type = 'button';
    x.className = 'alert-close';
    x.setAttribute('aria-label', 'Dismiss message');
    x.textContent = '×';
    x.addEventListener('click', () => dismiss(a));
    a.appendChild(x);
    if (a.dataset.flash === 'success' || a.dataset.flash === 'info') {
      let timer = setTimeout(() => dismiss(a), 6000);
      a.addEventListener('mouseenter', () => clearTimeout(timer));
      a.addEventListener('mouseleave', () => { timer = setTimeout(() => dismiss(a), 3000); });
    }
  });

  // ---------- Dropdowns (create, notifications, profile) ----------
  const closePops = (except) => $$('.menu-pop').forEach((p) => {
    if (p === except) return;
    p.hidden = true;
    $(`[data-dropdown="${p.id}"]`)?.setAttribute('aria-expanded', 'false');
  });
  $$('[data-dropdown]').forEach((btn) => {
    const pop = document.getElementById(btn.dataset.dropdown);
    if (!pop) return;
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = pop.hidden;
      closePops(pop);
      pop.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
      if (open) pop.querySelector('a,button')?.focus();
    });
    pop.addEventListener('click', (e) => e.stopPropagation());
    pop.addEventListener('keydown', (e) => {
      const items = $$('a,button', pop);
      const i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length]?.focus(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length]?.focus(); }
      if (e.key === 'Escape') { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
    });
  });
  document.addEventListener('click', () => closePops());
  // Escape closes menus and the phone search overlay (the search field may not have focus).
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closePops(); if (body.classList.contains('searching')) { body.classList.remove('searching'); const sb = $('#admin-suggest'); if (sb) sb.hidden = true; } } });

  // ---------- Global search: Ctrl/⌘+K or "/" focuses it; grouped suggestions ----------
  const search = $('#admin-search');
  const box = $('#admin-suggest');
  const searchForm = search?.form;
  const suggestUrl = searchForm?.dataset.suggest;
  let timer, ctrl, active = -1;
  function openSearch() {
    body.classList.add('searching');
    search?.focus();
    search?.select?.();
  }
  $('[data-search-open]')?.addEventListener('click', openSearch);
  $('[data-search-close]')?.addEventListener('click', () => { body.classList.remove('searching'); if (box) box.hidden = true; });
  document.addEventListener('keydown', (e) => {
    const typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '') || document.activeElement?.isContentEditable;
    if (search && ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey))) { e.preventDefault(); openSearch(); }
    else if (search && e.key === '/' && !typing) { e.preventDefault(); openSearch(); }
  });
  // Group icons come from the page (one hidden copy of each icon in the search form), so no markup is built from JSON.
  const groupIcon = (name) => {
    const src = $(`#admin-search-icons [data-ic="${CSS.escape(name || '')}"]`) || $('#admin-search-icons [data-ic="search"]');
    return src ? src.firstElementChild.cloneNode(true) : document.createElement('span');
  };
  function renderSuggest(groups, q) {
    box.innerHTML = '';
    let n = 0;
    (Array.isArray(groups) ? groups : []).forEach((g) => {
      const h = document.createElement('div');
      h.className = 'grp';
      h.setAttribute('role', 'presentation');
      h.appendChild(groupIcon(g.icon));
      h.appendChild(document.createTextNode(g.group));
      box.appendChild(h);
      (g.items || []).forEach((it) => {
        const a = document.createElement('a');
        a.href = it.url;
        a.setAttribute('role', 'option');
        a.id = 'sg-' + n++;
        const t = document.createElement('span');
        t.className = 'sg-t';
        t.textContent = it.title;
        const s = document.createElement('small');
        s.textContent = it.sub || '';
        t.appendChild(s);
        a.appendChild(t);
        if (it.badge) {
          const b = document.createElement('span');
          b.className = 'sg-badge';
          b.textContent = it.badge;
          a.appendChild(b);
        }
        box.appendChild(a);
      });
    });
    if (!n) {
      const empty = document.createElement('div');
      empty.className = 'sg-empty';
      empty.textContent = 'No matches for “' + q + '”.';
      box.appendChild(empty);
    }
    // Full results page (search.php) for everything else
    const all = document.createElement('a');
    all.href = searchForm.action + '?q=' + encodeURIComponent(q);
    all.className = 'sg-all';
    all.setAttribute('role', 'option');
    all.id = 'sg-' + n++;
    all.textContent = 'See all results for “' + q + '”';
    box.appendChild(all);
    box.hidden = false;
    search.setAttribute('aria-expanded', 'true');
    active = -1;
  }
  search?.addEventListener('input', () => {
    clearTimeout(timer);
    const q = search.value.trim();
    if (q.length < 2) { box.hidden = true; search.setAttribute('aria-expanded', 'false'); searchForm.classList.remove('busy'); return; }
    timer = setTimeout(async () => {
      ctrl?.abort();
      ctrl = new AbortController();
      searchForm.classList.add('busy');
      try {
        const r = await fetch(suggestUrl + (suggestUrl.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(q), { signal: ctrl.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (r.ok) renderSuggest(await r.json(), q);
        else if (r.status === 401) location.href = 'login.php';
        searchForm.classList.remove('busy');
      } catch (err) { if (err?.name !== 'AbortError') searchForm.classList.remove('busy'); }
    }, 180);
  });
  search?.addEventListener('focus', () => { if (search.value.trim().length >= 2 && box.childElementCount) { box.hidden = false; search.setAttribute('aria-expanded', 'true'); } });
  search?.addEventListener('keydown', (e) => {
    const items = $$('a', box);
    if (e.key === 'Escape') { box.hidden = true; body.classList.remove('searching'); search.blur(); return; }
    if (!items.length || box.hidden) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach((a, i) => a.classList.toggle('on', i === active));
      search.setAttribute('aria-activedescendant', items[active].id);
      items[active].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' && active >= 0) {
      e.preventDefault();
      location.href = items[active].href;
    }
  });
  search?.addEventListener('blur', () => setTimeout(() => { if (box && !box.contains(document.activeElement)) box.hidden = true; }, 150));

  // ---------- Wide tables: flag scrollers that can scroll sideways (CSS pins the actions column with an edge shadow) ----------
  const markScroll = (w) => {
    const over = w.scrollWidth > w.clientWidth + 1;
    w.classList.toggle('x-scroll', over);
    w.classList.toggle('at-end', over && w.scrollLeft + w.clientWidth >= w.scrollWidth - 1);
  };
  const scrollers = $$('.table-wrap');
  scrollers.forEach((w) => { markScroll(w); w.addEventListener('scroll', () => markScroll(w), { passive: true }); });
  if (scrollers.length && 'ResizeObserver' in window) {
    const ro = new ResizeObserver((entries) => entries.forEach((en) => markScroll(en.target)));
    scrollers.forEach((w) => ro.observe(w));
  }
  window.addEventListener('load', () => scrollers.forEach(markScroll));

  // ---------- Fullscreen ----------
  $('[data-fullscreen]')?.addEventListener('click', () => {
    if (document.fullscreenElement) document.exitFullscreen();
    else document.documentElement.requestFullscreen?.();
  });

  // ---------- Row action menus (row_actions() in admin-header.php): native popovers placed under their ⋯ button ----------
  function placeRowMenu(menu) {
    const btn = $(`[popovertarget="${CSS.escape(menu.id)}"]`);
    if (!btn || window.innerWidth <= 600) { menu.style.left = menu.style.top = ''; return; } // phones: bottom sheet (CSS)
    const r = btn.getBoundingClientRect();
    const w = menu.offsetWidth, h = menu.offsetHeight;
    const left = Math.max(8, Math.min(window.innerWidth - w - 8, r.right - w));
    const below = r.bottom + 6 + h <= window.innerHeight - 8;
    menu.style.left = left + 'px';
    menu.style.top = (below ? r.bottom + 6 : Math.max(8, r.top - h - 6)) + 'px';
  }
  $$('.row-menu[popover]').forEach((menu) => {
    menu.addEventListener('toggle', (e) => {
      if (e.newState === 'open') { placeRowMenu(menu); $('a,button', menu)?.focus(); }
    });
    menu.addEventListener('keydown', (e) => {
      const items = $$('a,button', menu);
      const i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length]?.focus(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length]?.focus(); }
    });
  });
  // Browsers without the popover API: the ⋯ button toggles the menu as a simple dropdown.
  if (!HTMLElement.prototype.hasOwnProperty('popover')) {
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('[popovertarget]');
      $$('.row-menu.is-open').forEach((m) => { if (!btn || m.id !== btn.getAttribute('popovertarget')) m.classList.remove('is-open'); });
      if (!btn) return;
      const m = document.getElementById(btn.getAttribute('popovertarget'));
      if (m) { m.classList.toggle('is-open'); if (m.classList.contains('is-open')) placeRowMenu(m); }
    });
  }
  const closeRowMenus = () => $$('.row-menu[popover]').forEach((m) => { try { if (m.matches(':popover-open')) m.hidePopover(); } catch { /* unsupported */ } });
  window.addEventListener('scroll', closeRowMenus, { passive: true, capture: true });
  window.addEventListener('resize', closeRowMenus);

  // ---------- Accessible confirmation dialog: data-confirm on forms, buttons and links ----------
  const dialog = $('#confirm-dialog');
  function confirmDialog(message, danger) {
    if (!dialog?.showModal) return Promise.resolve(window.confirm(message));
    $('#confirm-text').textContent = message;
    const ok = $('button[value=ok]', dialog);
    ok.className = 'btn ' + (danger ? 'btn-danger-solid' : 'btn-primary');
    ok.textContent = danger ? 'Yes, continue' : 'Confirm';
    dialog.classList.toggle('danger', !!danger);
    dialog.showModal();
    $('button[value=cancel]', dialog).focus();
    return new Promise((resolve) => {
      const done = (res) => { dialog.close(); cleanup(); resolve(res); };
      const onClick = (e) => { if (e.target.value === 'ok') done(true); if (e.target.value === 'cancel') done(false); if (e.target === dialog) done(false); };
      const onCancel = (e) => { e.preventDefault(); done(false); };
      const cleanup = () => { dialog.removeEventListener('click', onClick); dialog.removeEventListener('cancel', onCancel); };
      dialog.addEventListener('click', onClick);
      dialog.addEventListener('cancel', onCancel);
    });
  }
  window.lxConfirm = confirmDialog;
  const looksDangerous = (el, msg) => /btn-danger/.test(el?.className || '') || /delete|remove|revoke|cannot be undone|permanently/i.test(msg);
  document.addEventListener('submit', async (e) => {
    const form = e.target;
    const msg = e.submitter?.dataset.confirm || form.dataset.confirm;
    if (msg && !form.dataset.confirmed) {
      e.preventDefault();
      if (await confirmDialog(msg, looksDangerous(e.submitter, msg))) {
        form.dataset.confirmed = '1';
        if (e.submitter?.name) {
          const h = document.createElement('input');
          h.type = 'hidden'; h.name = e.submitter.name; h.value = e.submitter.value;
          form.appendChild(h);
        }
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
      return;
    }
    if (e.defaultPrevented) return;
    form.classList.remove('is-dirty');
    dirty = false;
    // Loading state on the button that submitted (downloads keep their label: no page change follows)
    const btn = e.submitter || $('button[type=submit],button:not([type])', form);
    if (btn && !btn.hasAttribute('data-no-loading') && form.method !== 'get') {
      btn.classList.add('loading');
      btn.setAttribute('aria-busy', 'true');
      setTimeout(() => { btn.classList.remove('loading'); btn.removeAttribute('aria-busy'); }, 15000);
    }
  });
  document.addEventListener('click', async (e) => {
    const a = e.target.closest('a[data-confirm]');
    if (!a || a.dataset.confirmed) return;
    e.preventDefault();
    if (await confirmDialog(a.dataset.confirm, looksDangerous(a, a.dataset.confirm))) {
      a.dataset.confirmed = '1';
      a.click();
      delete a.dataset.confirmed;
    }
  });
  // Bring the page back to normal when it is restored from the back/forward cache
  window.addEventListener('pageshow', () => $$('.btn.loading').forEach((b) => { b.classList.remove('loading'); b.removeAttribute('aria-busy'); }));

  // ---------- Unsaved-change warning ----------
  let dirty = false;
  $$('form[data-dirty-check]').forEach((f) => {
    f.addEventListener('input', () => { dirty = true; f.classList.add('is-dirty'); });
    f.addEventListener('change', () => { dirty = true; f.classList.add('is-dirty'); });
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // ---------- Conditional fields: data-show-if="field=a,b" ('!=' negates, ';' joins rules) ----------
  function fieldValue(scope, name) {
    const list = $$(`[name="${CSS.escape(name)}"]`, scope);
    if (!list.length) return '';
    const el = list.find((x) => x.type !== 'hidden') || list[0];
    if (el.type === 'checkbox') return list.some((x) => x.type === 'checkbox' && x.checked) ? (el.value && el.value !== 'on' ? el.value : '1') : '0';
    if (el.type === 'radio') return (list.find((x) => x.checked) || {}).value || '';
    return el.value ?? '';
  }
  function ruleMatches(rule, scope) {
    return rule.split(';').map((s) => s.trim()).filter(Boolean).every((part) => {
      const m = part.match(/^([\w-]+)\s*(!?=)\s*(.*)$/);
      if (!m) return true;
      const inList = m[3].split(',').map((v) => v.trim()).includes(fieldValue(scope, m[1]));
      return m[2] === '=' ? inList : !inList;
    });
  }
  function applyShowIf(scope) {
    $$('[data-show-if]', scope).forEach((w) => {
      const on = ruleMatches(w.dataset.showIf, w.closest('form') || document);
      w.classList.toggle('si-off', !on);
      w.toggleAttribute('hidden', !on);
    });
  }
  if ($('[data-show-if]')) {
    applyShowIf(document);
    document.addEventListener('change', (e) => { if (e.target.name) applyShowIf(e.target.form || document); });
    document.addEventListener('input', (e) => { if (e.target.name && e.target.tagName === 'SELECT') applyShowIf(e.target.form || document); });
  }

  // ---------- Slugs, previews, counters ----------
  $$('[data-slug-from]').forEach((slug) => {
    const src = document.querySelector(`[name="${slug.dataset.slugFrom}"]`);
    if (!src) return;
    let touched = slug.value !== '';
    slug.addEventListener('input', () => { touched = true; });
    src.addEventListener('input', () => {
      if (!touched) slug.value = src.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 180);
      slug.dispatchEvent(new Event('preview'));
    });
  });
  $$('input[type=file][data-preview]').forEach((input) => {
    input.addEventListener('change', () => {
      const img = document.getElementById(input.dataset.preview);
      const f = input.files?.[0];
      if (img && f && f.type.startsWith('image/')) { img.src = URL.createObjectURL(f); img.hidden = false; }
    });
  });
  // File inputs: show the chosen file name next to the styled button
  $$('.field input[type=file]').forEach((input) => {
    const note = document.createElement('span');
    note.className = 'file-name';
    note.setAttribute('aria-live', 'polite');
    input.after(note);
    input.addEventListener('change', () => { note.textContent = input.files?.length ? Array.from(input.files).map((f) => f.name).join(', ') : ''; });
  });
  $$('[data-counter]').forEach((el) => {
    const [good, max] = el.dataset.counter.split(',').map(Number);
    const c = document.createElement('span');
    c.className = 'counter';
    el.closest('.field')?.querySelector('label')?.appendChild(c);
    const upd = () => {
      const n = el.value.length;
      c.textContent = n + ' / ' + good;
      c.className = 'counter' + (n > max ? ' bad' : n > good || n === 0 ? ' warn' : '');
    };
    el.addEventListener('input', upd);
    upd();
  });
  // Search-result (SERP) preview: <div class="serp" data-serp-title="#id" data-serp-desc="#id" data-serp-url="...">
  $$('.serp[data-serp-title]').forEach((p) => {
    const t = $(p.dataset.serpTitle);
    const d = $(p.dataset.serpDesc);
    const upd = () => {
      $('.t', p).textContent = (t?.value || 'Page title').slice(0, 65) + ((t?.value || '').length > 65 ? '…' : '');
      $('.d', p).textContent = (d?.value || 'Meta description will appear here.').slice(0, 160) + ((d?.value || '').length > 160 ? '…' : '');
    };
    t?.addEventListener('input', upd);
    d?.addEventListener('input', upd);
    upd();
  });

  // ---------- Auto-submit filters ----------
  $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

  // ---------- Bulk selection (shift-click selects a range) ----------
  $$('[data-bulk]').forEach((wrap) => {
    const all = $('[data-check-all]', wrap);
    const boxes = () => $$('input[data-row-check]', wrap);
    const bar = $('.bulkbar', wrap);
    const count = $('[data-bulk-count]', wrap);
    let last = null;
    const sync = () => {
      const list = boxes();
      const n = list.filter((b) => b.checked).length;
      bar?.classList.toggle('on', n > 0);
      if (count) count.textContent = n;
      if (all) { all.checked = n > 0 && n === list.length; all.indeterminate = n > 0 && n < list.length; }
      list.forEach((b) => b.closest('tr')?.classList.toggle('selected', b.checked));
    };
    all?.addEventListener('change', () => { boxes().forEach((b) => { b.checked = all.checked; }); sync(); });
    wrap.addEventListener('click', (e) => {
      const b = e.target.closest?.('input[data-row-check]');
      if (!b) return;
      if (e.shiftKey && last && last !== b) {
        const list = boxes();
        const [a, z] = [list.indexOf(last), list.indexOf(b)].sort((x, y) => x - y);
        list.slice(a, z + 1).forEach((x) => { x.checked = b.checked; });
      }
      last = b;
      sync();
    });
    wrap.addEventListener('change', (e) => { if (e.target.matches('[data-row-check]')) sync(); });
    sync();
  });

  // ---------- Copy to clipboard ----------
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    try { await navigator.clipboard.writeText(b.dataset.copy); toast('Copied to clipboard.', 'success'); } catch { toast('Copy failed - select and copy manually.', 'error'); }
  });

  // ---------- Long-running actions: <button data-busy="Working…"> ----------
  document.addEventListener('submit', (e) => {
    const b = e.submitter;
    if (!b || !b.dataset.busy || e.defaultPrevented) return;
    setTimeout(() => { b.disabled = true; b.textContent = b.dataset.busy; }, 0);
  });

  // ---------- Rich text editor (sanitised again on the server) ----------
  $$('textarea[data-rte]').forEach((ta) => {
    const bar = document.createElement('div');
    bar.className = 'rte-bar';
    bar.setAttribute('role', 'toolbar');
    bar.setAttribute('aria-label', 'Formatting');
    const ed = document.createElement('div');
    ed.className = 'rte';
    ed.contentEditable = 'true';
    ed.setAttribute('role', 'textbox');
    ed.setAttribute('aria-multiline', 'true');
    ed.setAttribute('aria-label', ta.closest('.field')?.querySelector('label')?.textContent || 'Content');
    ed.innerHTML = ta.value;
    const tools = [
      ['P', 'formatBlock', 'p', 'Paragraph'], ['H2', 'formatBlock', 'h2', 'Heading 2'], ['H3', 'formatBlock', 'h3', 'Heading 3'],
      ['B', 'bold', null, 'Bold'], ['I', 'italic', null, 'Italic'], ['• List', 'insertUnorderedList', null, 'Bulleted list'],
      ['1. List', 'insertOrderedList', null, 'Numbered list'], ['❝', 'formatBlock', 'blockquote', 'Quote'],
      ['Link', 'link', null, 'Insert link'], ['Image', 'image', null, 'Insert image by URL'], ['Table', 'table', null, 'Insert table'],
      ['⟲', 'undo', null, 'Undo'], ['⟳', 'redo', null, 'Redo'], ['</>', 'source', null, 'HTML source'],
    ];
    let source = false;
    tools.forEach(([label, cmd, arg, title]) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = label;
      b.title = title;
      b.setAttribute('aria-label', title);
      b.addEventListener('click', () => {
        if (cmd === 'source') {
          source = !source;
          b.classList.toggle('on', source);
          if (source) { ta.value = ed.innerHTML; ta.hidden = false; ed.hidden = true; } else { ed.innerHTML = ta.value; ta.hidden = true; ed.hidden = false; }
          return;
        }
        ed.focus();
        if (cmd === 'link') { const u = prompt('Link URL (https:// or /path)'); if (u && /^(https?:\/\/|\/|mailto:|tel:)/i.test(u)) document.execCommand('createLink', false, u); }
        else if (cmd === 'image') { const u = prompt('Image URL (https:// or /uploads/...)'); if (u && /^(https?:\/\/|\/)/i.test(u)) document.execCommand('insertImage', false, u); }
        else if (cmd === 'table') document.execCommand('insertHTML', false, '<table><thead><tr><th>Heading</th><th>Heading</th></tr></thead><tbody><tr><td>Cell</td><td>Cell</td></tr></tbody></table><p></p>');
        else document.execCommand(cmd, false, arg);
        ta.value = ed.innerHTML;
        ta.form?.dispatchEvent(new Event('input'));
      });
      bar.appendChild(b);
    });
    // Paste as plain text: never carries scripts or foreign styles.
    ed.addEventListener('paste', (e) => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); });
    ed.addEventListener('input', () => { ta.value = ed.innerHTML; ta.form?.dispatchEvent(new Event('input')); });
    ta.hidden = true;
    ta.classList.add('code');
    ta.parentNode.insertBefore(bar, ta);
    ta.parentNode.insertBefore(ed, ta);
    ta.form?.addEventListener('submit', () => { if (!source) ta.value = ed.innerHTML; });
  });

  // ---------- Page-builder block controls (order is the DOM order on submit) ----------
  $$('[data-blocks]').forEach((wrap) => {
    const markDirty = () => wrap.closest('form')?.dispatchEvent(new Event('input'));
    wrap.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-move],[data-dup],[data-del]');
      if (!btn) return;
      const card = btn.closest('[data-block]');
      if (btn.dataset.move === 'up' && card.previousElementSibling) card.parentNode.insertBefore(card, card.previousElementSibling);
      if (btn.dataset.move === 'down' && card.nextElementSibling) card.parentNode.insertBefore(card.nextElementSibling, card);
      if (btn.hasAttribute('data-dup')) {
        const uid = Math.random().toString(16).slice(2, 10);
        // Flatten rich-text editors in the copy back to plain textareas.
        card.querySelectorAll('.rte').forEach((ed) => { const ta = ed.nextElementSibling; if (ta) ta.value = ed.innerHTML; });
        const copy = card.cloneNode(true);
        copy.querySelectorAll('.rte-bar,.rte').forEach((n) => n.remove());
        copy.querySelectorAll('textarea').forEach((t) => { t.hidden = false; });
        copy.querySelectorAll('[name]').forEach((el) => { el.name = el.name.replace(/^(blocks(?:_files)?)\[[^\]]+\]/, `$1[${uid}]`); });
        copy.querySelectorAll('[id]').forEach((el) => { el.id = el.id + uid; });
        copy.querySelectorAll('input[name$="[_uid]"]').forEach((el) => { el.value = uid; });
        copy.querySelectorAll('input[type=file]').forEach((el) => { el.value = ''; });
        card.after(copy);
      }
      if (btn.hasAttribute('data-del')) card.remove();
      markDirty();
    });
  });

  // ---------- Chart tooltips ----------
  let tip;
  document.addEventListener('mousemove', (e) => {
    const t = e.target.closest?.('[data-tip]');
    if (!t) { tip?.remove(); tip = null; return; }
    if (!tip) { tip = document.createElement('div'); tip.className = 'chart-tip'; document.body.appendChild(tip); }
    tip.textContent = t.dataset.tip;
    tip.style.left = Math.min(window.innerWidth - tip.offsetWidth - 8, e.clientX + 12) + 'px';
    tip.style.top = (e.clientY - 36) + 'px';
  });

  // ---------- Horizontal scrollers (tab bars): fade edges + keep the active tab in view ----------
  function fadeEdges(nav) {
    const track = $('.tabs-track', nav) || nav;
    const upd = () => {
      nav.classList.toggle('fade-start', track.scrollLeft > 10);
      nav.classList.toggle('fade-end', track.scrollLeft + track.clientWidth < track.scrollWidth - 10);
    };
    track.addEventListener('scroll', upd, { passive: true });
    window.addEventListener('resize', upd);
    upd();
    nav._fade = upd;
    const on = $('a.on, [aria-selected="true"]', track);
    if (on) centerIn(track, on, false);
    upd();
  }
  // Scroll a horizontal strip so that `item` sits in the middle (no-op when everything fits)
  function centerIn(track, item, smooth) {
    if (track.scrollWidth <= track.clientWidth) return;
    const left = item.getBoundingClientRect().left - track.getBoundingClientRect().left + track.scrollLeft;
    const to = Math.max(0, left - (track.clientWidth - item.offsetWidth) / 2);
    track.scrollTo({ left: to, behavior: smooth && !reduceMotion ? 'smooth' : 'auto' });
  }
  $$('[data-scroll-fade]').forEach(fadeEdges);
  // Arrow keys move between links of a tab bar (roving focus)
  document.addEventListener('keydown', (e) => {
    const link = e.target.closest?.('.modtabs a, nav.tabs a');
    if (!link || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(e.key)) return;
    const links = $$('a', link.closest('nav'));
    const i = links.indexOf(link);
    const next = e.key === 'Home' ? 0 : e.key === 'End' ? links.length - 1 : (i + (e.key === 'ArrowRight' ? 1 : -1) + links.length) % links.length;
    e.preventDefault();
    links[next].focus();
    links[next].scrollIntoView({ block: 'nearest', inline: 'nearest' });
  });

  // ---------- Module tab bar ↔ page: same-page #panel links, duplicate tab strips ----------
  const modtabs = $('[data-modtabs]');
  const norm = (href) => {
    try {
      const u = new URL(href, location.href);
      const p = new URLSearchParams(u.search);
      p.sort();
      return u.pathname + '?' + p.toString();
    } catch { return href; }
  };
  const here = norm(location.href);
  function markModtab(key) {
    if (!modtabs) return;
    const links = $$('a', modtabs);
    const match = links.find((a) => a.dataset.hash === key && norm(a.href) === here);
    if (!match) return;
    links.forEach((a) => { a.classList.toggle('on', a === match); if (a === match) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current'); });
    const track = $('.tabs-track', modtabs);
    if (track) centerIn(track, match, true);
    setTimeout(() => modtabs._fade?.(), 400);
  }
  if (modtabs) {
    const modUrls = new Set($$('a', modtabs).map((a) => norm(a.href) + (a.dataset.hash ? '#' + a.dataset.hash : '')));
    const modPlain = new Set($$('a', modtabs).filter((a) => !a.dataset.hash).map((a) => norm(a.href)));
    // A page's own server-side tab strip is hidden when the module tab bar already links every one of its tabs
    // (admin_tabs() marks it on the server and names the matching bar link in data-cover-href).
    $$('nav.tabs').forEach((nav) => {
      const links = $$('a', nav);
      const target = (a) => norm(a.dataset.coverHref || a.href);
      if (!links.length || !(nav.classList.contains('is-covered') || links.every((a) => modPlain.has(target(a))))) return;
      nav.classList.add('is-covered');
      // Keep the page's counts ("Live · 3") visible on the module tab bar
      links.forEach((a) => {
        const c = $('.tab-count', a);
        const m = $$('a', modtabs).find((x) => !x.dataset.hash && norm(x.href) === target(a));
        if (c && m && !$('.tab-count', m)) m.appendChild(c.cloneNode(true));
      });
    });
    // Same for client-side tabs: hide the in-page buttons when every panel is in the module tab bar.
    $$('[data-tabs]').forEach((root) => {
      const keys = $$('[data-tab-panel]', root).filter((p) => p.closest('[data-tabs]') === root).map((p) => p.dataset.tabPanel);
      if (!keys.length || !keys.every((k) => modUrls.has(here + '#' + k))) return;
      root.classList.add('tabs-covered');
      // Badges on the hidden buttons (error counts, totals) move to the module tab bar.
      $$('[data-tab]', root).filter((b) => b.closest('[data-tabs]') === root).forEach((b) => {
        const badge = $('.tab-count, .st-tab-err, .badge', b);
        const m = $$('a[data-hash]', modtabs).find((x) => x.dataset.hash === b.dataset.tab && norm(x.href) === here);
        if (badge && m) m.appendChild(badge.cloneNode(true));
      });
    });
    // Clicking a same-page #panel link switches the panel without reloading.
    modtabs.addEventListener('click', (e) => {
      const a = e.target.closest('a[data-hash]');
      if (!a || norm(a.href) !== here || e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault();
      if (location.hash !== '#' + a.dataset.hash) history.pushState(null, '', '#' + a.dataset.hash);
      window.dispatchEvent(new HashChangeEvent('hashchange'));
    });
  }

  // Pages that switch panels with their own script (e.g. settings.php) only change the URL hash:
  // keep the module tab bar highlight in sync with it as well.
  const syncModtabHash = () => { const h = decodeURIComponent(location.hash.slice(1)); if (h) markModtab(h); };
  window.addEventListener('hashchange', syncModtabHash);
  window.addEventListener('popstate', syncModtabHash);
  syncModtabHash();
  window.lxMarkModtab = markModtab;

  // ---------- Client-side tabs: <div data-tabs> <button data-tab="x"> … <section data-tab-panel="x"> ----------
  // Hash-synced (page.php#x opens panel x, also #id-inside-a-panel), deep-linkable, arrow keys,
  // remembers the last panel per page in sessionStorage.
  const tabRoots = $$('[data-tabs]');
  tabRoots.forEach((root, n) => {
    const own = (el) => el.closest('[data-tabs]') === root;
    const panels = $$('[data-tab-panel]', root).filter(own);
    if (!panels.length) return;
    const buttons = $$('[data-tab]', root).filter(own);
    const keys = panels.map((p) => p.dataset.tabPanel);
    const list = buttons[0]?.parentElement;
    const memo = 'lx-tab:' + location.pathname + ':' + (root.dataset.tabs || n);
    if (list) {
      list.setAttribute('role', 'tablist');
      list.classList.add('tab-list');
      if (!list.hasAttribute('aria-label')) list.setAttribute('aria-label', 'Sections');
      // The module tab bar above already lists every panel: don't show a second strip.
      if (root.classList.contains('tabs-covered')) list.style.display = 'none';
    }
    panels.forEach((p) => {
      const k = p.dataset.tabPanel;
      if (!p.id) p.id = 'tabpanel-' + k;
      p.setAttribute('role', 'tabpanel');
      if (!p.hasAttribute('tabindex')) p.tabIndex = -1;
    });
    buttons.forEach((b) => {
      const k = b.dataset.tab;
      const p = panels.find((x) => x.dataset.tabPanel === k);
      b.type = 'button';
      b.setAttribute('role', 'tab');
      if (!b.id) b.id = 'tab-' + k;
      if (p) { b.setAttribute('aria-controls', p.id); p.setAttribute('aria-labelledby', b.id); }
    });
    let current = null;
    function activate(key, { focus = false, sync = false, scrollTo = null } = {}) {
      if (!keys.includes(key)) return;
      current = key;
      panels.forEach((p) => { const on = p.dataset.tabPanel === key; p.hidden = !on; p.classList.toggle('active', on); });
      buttons.forEach((b) => {
        const on = b.dataset.tab === key;
        b.setAttribute('aria-selected', String(on));
        b.tabIndex = on ? 0 : -1;
        b.classList.toggle('on', on);
        if (on && focus) b.focus();
      });
      session.set(memo, key);
      if (sync && location.hash !== '#' + key) history.replaceState(null, '', location.pathname + location.search + '#' + key);
      markModtab(key);
      root.dispatchEvent(new CustomEvent('lx:tab', { bubbles: true, detail: { key } }));
      if (scrollTo) setTimeout(() => scrollTo.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' }), 30);
      $$('[data-scroll-fade]', root).forEach((s) => s._fade?.());
    }
    function fromHash() {
      const h = decodeURIComponent(location.hash.slice(1));
      if (!h) return null;
      if (keys.includes(h)) return { key: h };
      let el = null;
      try { el = document.getElementById(h); } catch { el = null; }
      const panel = el?.closest('[data-tab-panel]');
      return panel && own(panel) ? { key: panel.dataset.tabPanel, el } : null;
    }
    buttons.forEach((b) => b.addEventListener('click', () => activate(b.dataset.tab, { sync: true })));
    list?.addEventListener('keydown', (e) => {
      const i = buttons.findIndex((b) => b.dataset.tab === current);
      let j = null;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') j = (i + 1) % buttons.length;
      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') j = (i - 1 + buttons.length) % buttons.length;
      if (e.key === 'Home') j = 0;
      if (e.key === 'End') j = buttons.length - 1;
      if (j === null) return;
      e.preventDefault();
      activate(buttons[j].dataset.tab, { focus: true, sync: true });
    });
    window.addEventListener('hashchange', () => {
      const t = fromHash();
      if (t && (t.key !== current || t.el)) activate(t.key, { scrollTo: t.el || null });
    });
    // First panel: URL hash > panel with a validation error > last used on this page > first
    const t = fromHash();
    const withError = panels.find((p) => p.querySelector('.field.invalid, [aria-invalid="true"]'));
    const remembered = session.get(memo);
    activate(t?.key || withError?.dataset.tabPanel || (keys.includes(remembered) ? remembered : keys[0]), { scrollTo: t?.el || null });
    root.classList.add('tabs-ready');
  });

  // QR codes: <div data-qr="text"> (e.g. the 2FA otpauth link) drawn by assets/vendor/qrcode-generator.js,
  // which the page loads when it needs it. Without the library the setup key text remains the fallback.
  if (window.qrcode) {
    $$('[data-qr]').forEach((el) => {
      const qr = window.qrcode(0, 'M'); // version chosen automatically, medium error correction
      qr.addData(el.dataset.qr);
      qr.make();
      el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true, title: el.dataset.qrTitle || 'QR code' });
      el.hidden = false;
    });
  }

  // Open the section named in the URL hash (e.g. #reminders) and highlight it briefly (non-tab pages).
  if (location.hash && location.hash.length > 1) {
    let target = null;
    try { target = document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch { target = null; }
    if (target && !target.closest('[data-tab-panel]') && !target.matches('[data-tab-panel]')) {
      target.scrollIntoView({ block: 'start' });
      target.classList.add('flash-target');
      setTimeout(() => target.classList.remove('flash-target'), 2000);
    }
  }
})();
