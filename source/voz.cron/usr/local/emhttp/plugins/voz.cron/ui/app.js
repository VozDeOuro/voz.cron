/* VOZ_CRON UI. Based on User Scripts by Andrew Zawadzki (Squid). Vanilla JS, talks to api.php. */
'use strict';
const US = (() => {
  const root = document.getElementById('us-app');
  const $ = (sel, el = root) => el.querySelector(sel);
  const FREQ = [
    ['disabled', 'Schedule disabled'], ['hourly', 'Hourly'], ['daily', 'Daily'], ['weekly', 'Weekly'], ['monthly', 'Monthly'],
    ['start', 'At startup of array'], ['boot', 'At first array start only'], ['stop', 'At stopping of array'], ['custom', 'Custom'],
  ];
  // per-browser preferences; storage can be missing or throw (private mode), so the page never depends on it
  const store = {
    get(k, d) { try { const v = localStorage.getItem(`voz.cron.${k}`); return v === null ? d : JSON.parse(v); } catch { return d; } },
    set(k, v) { try { localStorage.setItem(`voz.cron.${k}`, JSON.stringify(v)); } catch { /* not remembered */ } },
  };
  const state = { scripts: [], filter: 'all', query: '', pending: {}, menuOpen: null, moveMenu: false, fmenuOpen: null, tz: 'UTC', sel: null,
    layout: { version: 0, items: [] }, collapsed: new Set([].concat(store.get('collapsed', [])).filter((x) => typeof x === 'string')), saving: 0 };
  let drag = null; // key of the row being dragged; polling does not re-render while it is set

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmtDur = (s) => s < 60 ? `${s}s` : s < 3600 ? `${Math.floor(s / 60)}m ${s % 60}s` : `${Math.floor(s / 3600)}h ${Math.floor(s % 3600 / 60)}m`;
  const fmtAgo = (ts) => { const d = Math.max(0, Date.now() / 1000 - ts); return d < 60 ? 'just now' : d < 3600 ? `${Math.floor(d / 60)} min ago` : d < 86400 ? `${Math.floor(d / 3600)} h ago` : `${Math.floor(d / 86400)} d ago`; };
  let fmt = UsFmt.make('UTC'); // replaced by the Tower's zone from the first list response
  const fmtWhen = (ts) => fmt.when(ts);
  const OUTCOME_LABEL = { ok: 'ok', warn: 'warning', ignore: 'ignored', fail: 'failed', aborted: 'aborted', skipped: 'skipped' };
  const pillLabel = (h) => (h.status === 'timeout' ? 'timeout' : OUTCOME_LABEL[h.outcome] || h.status);
  const pillClass = (h) => h.outcome || h.status;

  async function api(action, data = {}, keepalive = false) {
    const r = await fetch('/plugins/voz.cron/api.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
      body: JSON.stringify({ action, ...data }), keepalive,
    });
    let j; try { j = await r.json(); } catch { j = { ok: false, error: `HTTP ${r.status}` }; }
    if (!j.ok) { const e = new Error(j.error || 'request failed'); e.status = r.status; e.body = j; throw e; }
    return j;
  }

  let toastTimer;
  function toast(msg, kind = '') {
    const t = $('#us-toast'); t.textContent = msg; t.className = `us-toast ${kind}`; t.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, kind === 'err' ? 6000 : 3000);
  }

  /** Dot color: running, or the last real run's outcome (a skip shows the health underneath it). */
  function status(s) {
    if (s.running) return 'running';
    const l = s.last;
    if (!l) return '';
    if (l.status === 'skipped') return s.health.health === 'failing' ? 'fail' : 'ok';
    return l.outcome || (l.status === 'ok' ? 'ok' : 'fail');
  }

  const failing = (s) => s.health.health === 'failing' || s.last?.outcome === 'fail';

  function visible() {
    const q = state.query.toLowerCase();
    return state.scripts.filter((s) => {
      if (q && !`${s.name} ${s.description} ${s.id}`.toLowerCase().includes(q)) return false;
      const sched = state.pending[s.id]?.frequency ?? s.schedule.frequency;
      if (state.filter === 'scheduled') return sched !== 'disabled';
      if (state.filter === 'running') return !!s.running;
      if (state.filter === 'failing') return failing(s);
      return true;
    });
  }

  function schedCell(s) {
    const p = { ...s.schedule, ...(state.pending[s.id] || {}) };
    const opts = FREQ.map(([v, l]) => `<option value="${v}"${v === p.frequency ? ' selected' : ''}>${l}</option>`).join('');
    const custom = p.frequency === 'custom'
      ? `<input type="text" class="us-cron" value="${esc(p.custom)}" placeholder="*/5 * * * *" aria-label="Custom cron for ${esc(s.name)}" spellcheck="false">` : '';
    let meta = '';
    if (state.pending[s.id]) meta = `<span class="us-meta" data-meta>${esc(state.pending[s.id]._text || 'unsaved, press Apply')}</span>`;
    else if (s.cronError) meta = `<span class="us-meta err">${esc(s.cronError)} · not scheduled</span>`;
    else if (s.cron) meta = `<span class="us-meta">${esc(s.cron)}${s.next ? ` · next ${esc(fmtWhen(s.next))}` : ''}</span>`;
    return `<select class="us-freq" aria-label="Schedule for ${esc(s.name)}">${opts}</select>${custom}${meta}`;
  }

  function lastCell(s) {
    if (s.running) return `<span class="us-pill running">running</span> <span class="us-meta">since ${esc(fmtWhen(s.running.since))}</span>`;
    const l = s.last;
    if (!l) return '<span class="us-meta">never run</span>';
    const rep = l.repeat ? ` ×${Number(l.repeat).toLocaleString()}` : '';
    const dur = l.status === 'skipped' ? '' : `, ${esc(fmtDur(l.dur ?? 0))}`;
    const badge = s.health.health === 'failing' && s.health.firstFail ? `<span class="us-badge">failing since ${esc(fmtWhen(s.health.firstFail))}</span>` : '';
    return `<span class="us-pill ${esc(pillClass(l))}">${esc(pillLabel(l))}${rep}</span> <span class="us-meta">${esc(fmtAgo(l.start))}${dur}</span>${badge}`;
  }

  const tickTip = (t) => `${OUTCOME_LABEL[t.o] || t.o}, exit ${t.exit} · ${t.n > 1 ? `one of ${t.n} runs, ${fmtWhen(t.first)} – ${fmtWhen(t.t)}` : fmtWhen(t.t)}`;

  function sparkHtml(s) {
    const ticks = s.spark || [];
    if (!ticks.length) return '';
    const counts = {};
    ticks.forEach((t) => { counts[t.o] = (counts[t.o] || 0) + 1; });
    const label = `Last ${ticks.length} runs: ${Object.entries(counts).map(([o, c]) => `${c} ${OUTCOME_LABEL[o] || o}`).join(', ')}. Arrow keys show each run.`;
    return `<span class="us-spark" tabindex="0" role="img" aria-label="${esc(label)}">${ticks.map((t, i) => `<i class="us-tick ${esc(t.o)}" data-i="${i}"></i>`).join('')}</span>`;
  }

  function menuHtml(s) {
    const open = state.menuOpen === s.id;
    let list = '';
    if (open && state.moveMenu) {
      const at = UsLayout.locate(state.layout.items, `s:${s.id}`);
      const cur = at && at.folder !== null ? state.layout.items[at.folder].id : '';
      list = `<ul class="us-menu-list" role="menu" aria-label="Move ${esc(s.name)} to">
        <li><button type="button" role="menuitem" data-act="move" data-to=""${cur === '' ? ' disabled' : ''}>Top level</button></li>
        ${UsLayout.folders(state.layout.items).map((f) => `<li><button type="button" role="menuitem" data-act="move" data-to="${esc(f.id)}"${f.id === cur ? ' disabled' : ''}>${esc(f.icon || '📁')} ${esc(f.name)}</button></li>`).join('')}</ul>`;
    } else if (open) {
      list = `<ul class="us-menu-list" role="menu">
        <li><button type="button" role="menuitem" data-act="edit">Edit script</button></li>
        <li><button type="button" role="menuitem" data-act="options">Run options</button></li>
        <li><button type="button" role="menuitem" data-act="moveto">Move to…</button></li>
        <li><button type="button" role="menuitem" data-act="rename">Rename</button></li>
        <li><button type="button" role="menuitem" data-act="describe">Edit description</button></li>
        <li><button type="button" role="menuitem" data-act="delete" class="danger"${s.running ? ' disabled' : ''}>Delete</button></li></ul>`;
    }
    return `<span class="us-menu"><button type="button" data-act="menu" aria-haspopup="menu" aria-expanded="${open}" aria-label="More actions for ${esc(s.name)}">⋯</button>${list}</span>`;
  }


  // only the grip drags, so the rest of a row stays selectable text (a draggable row swallows text selection)
  const GRIP = '<span class="us-grip" draggable="true" title="Drag to reorder" aria-hidden="true">⠿</span>';

  /** One row = seven grid cells, always: a control the script lacks leaves its cell empty so columns line up. */
  function rowHtml(s, folderId) {
    const v = s.vars || {};
    const dis = s.missing ? ' disabled' : '';
    const run = s.running ? '<button type="button" data-act="abort">Abort</button>'
      : v.backgroundOnly ? '' : `<button type="button" data-act="run"${dis}>Run</button>`;
    const bg = s.running || v.foregroundOnly ? '' : `<button type="button" data-act="background"${dis}>Background</button>`;
    const key = `s:${s.id}`;
    return `<div class="us-row${state.sel === key ? ' us-selected' : ''}" role="listitem" data-id="${esc(s.id)}" data-key="${esc(key)}" data-folder="${esc(folderId)}" tabindex="-1">
      <div class="us-cell-name"><div class="us-name">${GRIP}<span class="us-dot ${esc(status(s))}" aria-hidden="true"></span><h3>${esc(s.name)}</h3></div>
        ${s.description ? `<p class="us-desc">${esc(s.description)}</p>` : ''}${s.missing ? '<p class="us-desc us-meta err">script file missing</p>' : ''}</div>
      <div class="us-sched">${schedCell(s)}</div>
      <div class="us-last">${lastCell(s)}${sparkHtml(s)}</div>
      <div class="us-c-run">${run}</div>
      <div class="us-c-bg">${bg}</div>
      <div class="us-c-log"><button type="button" data-act="log">Log</button></div>
      <div class="us-c-menu">${menuHtml(s)}</div></div>`;
  }

  /** Display order: layout items, folder children right after their header. While a search or chip filter
      is active, folders without a match are hidden and folders with one are shown open. */
  function rows() {
    const byKey = new Map(state.scripts.map((s) => [s.id, s]));
    const show = new Set(visible().map((s) => s.id));
    const filtering = state.query !== '' || state.filter !== 'all';
    const out = [];
    for (const it of state.layout.items) {
      if (it.type === 'script') { if (show.has(it.id) && byKey.has(it.id)) out.push({ kind: 'script', s: byKey.get(it.id), folder: '' }); continue; }
      const all = it.scripts.map((id) => byKey.get(id)).filter(Boolean);
      const kids = all.filter((s) => show.has(s.id));
      if (filtering && !kids.length) continue;
      const open = filtering || !state.collapsed.has(it.id);
      out.push({ kind: 'folder', f: it, all, open });
      if (open) kids.forEach((s) => out.push({ kind: 'script', s, folder: it.id }));
    }
    // a script the layout has not caught up with yet (created since the last list) goes last
    const placed = new Set(state.layout.items.flatMap((it) => (it.type === 'folder' ? it.scripts : [it.id])));
    state.scripts.forEach((s) => { if (!placed.has(s.id) && show.has(s.id)) out.push({ kind: 'script', s, folder: '' }); });
    return out;
  }

  function folderHtml(n) {
    const f = n.f, key = `f:${f.id}`;
    const running = n.all.filter((s) => s.running).length;
    const bad = n.all.filter(failing).length;
    const menu = state.fmenuOpen === f.id ? `<ul class="us-menu-list" role="menu">
      <li><button type="button" role="menuitem" data-fact="rename">Rename folder</button></li>
      <li><button type="button" role="menuitem" data-fact="icon">Change icon</button></li>
      <li><button type="button" role="menuitem" data-fact="delete" class="danger">Delete folder</button></li></ul>` : '';
    return `<div class="us-folder${state.sel === key ? ' us-selected' : ''}" role="listitem" data-key="${esc(key)}" data-folder-id="${esc(f.id)}" tabindex="-1">
      ${GRIP}<button type="button" class="us-chev" data-fact="toggle" aria-expanded="${n.open}" aria-label="${n.open ? 'Collapse' : 'Expand'} ${esc(f.name)}">${n.open ? '▾' : '▸'}</button>
      <span class="us-ficon" aria-hidden="true">${esc(f.icon || '📁')}</span>
      <h3 class="us-fname">${esc(f.name)}</h3>
      <span class="us-count" title="${n.all.length} scripts">${n.all.length}</span>
      ${running ? `<span class="us-fbadge running">${running} running</span>` : ''}
      ${bad ? `<span class="us-fbadge failing">${bad} failing</span>` : ''}
      <span class="us-spacer"></span>
      <span class="us-menu"><button type="button" data-fact="menu" aria-haspopup="menu" aria-expanded="${state.fmenuOpen === f.id}" aria-label="Folder actions for ${esc(f.name)}">⋯</button>${menu}</span></div>`;
  }

  let lastRendered = '';
  function render() {
    const list = $('#us-list');
    // skip identical re-renders (polling) so open selects and focus are left alone; the minute bucket keeps "x min ago" fresh
    const key = JSON.stringify([state.scripts, state.pending, state.filter, state.query, state.menuOpen, state.moveMenu, state.fmenuOpen, state.layout, [...state.collapsed], state.sel, state.tz, Math.floor(Date.now() / 60000)]);
    if (key === lastRendered) return;
    lastRendered = key;
    const fe = document.activeElement;
    const holder = fe && list.contains(fe) ? fe.closest('[data-key]') : null;
    const focus = holder ? { key: holder.dataset.key, self: fe === holder, act: fe.dataset.act || fe.dataset.fact || '', cls: fe.classList[0] || '' } : null;
    const nodes = rows();
    list.innerHTML = nodes.map((n) => (n.kind === 'folder' ? folderHtml(n) : rowHtml(n.s, n.folder))).join('');
    $('#us-empty').hidden = nodes.length > 0;
    $('#us-apply').disabled = Object.keys(state.pending).length === 0 || Object.values(state.pending).some((p) => p._invalid);
    if (focus) {
      const el = list.querySelector(`[data-key="${CSS.escape(focus.key)}"]`);
      let target = el;
      if (el && !focus.self) target = focus.act ? el.querySelector(`[data-act="${focus.act}"], [data-fact="${focus.act}"]`) : focus.cls ? el.querySelector(`.${CSS.escape(focus.cls)}`) : null;
      (target || el)?.focus();
    }
  }

  let timer;
  let loadFailed = false;
  async function refresh() {
    try {
      const r = await api('list'); state.scripts = r.scripts; loadFailed = false;
      // never undo a move that is still being saved, nor take a list answer older than the last save
      if (!state.saving && r.layout && r.layout.version >= (state.layout?.version ?? 0)) state.layout = r.layout;
      if (r.tz && r.tz !== state.tz) { state.tz = r.tz; fmt = UsFmt.make(r.tz); }
      const ids = new Set(state.scripts.map((s) => s.id));
      Object.keys(state.pending).forEach((id) => { if (!ids.has(id)) delete state.pending[id]; });
      if (!editingInline() && !drag) render();
    } catch (e) { if (!loadFailed) toast(`Could not load scripts: ${e.message}`, 'err'); loadFailed = true; }
  }
  const editingInline = () => { const c = document.activeElement?.classList; return !!c && (c.contains('us-cron') || c.contains('us-freq')) || !!state.menuOpen || !!state.fmenuOpen; };
  function poll() { clearTimeout(timer); if (!document.hidden) timer = setTimeout(async () => { await refresh(); poll(); }, 5000); }

  const byId = (id) => state.scripts.find((s) => s.id === id);

  async function act(id, a, to = '') {
    const s = byId(id);
    if (!s) { await refresh(); return; }
    try {
      if (a === 'menu') {
        const opening = state.menuOpen !== id;
        state.menuOpen = opening ? id : null; state.moveMenu = false; state.fmenuOpen = null; render();
        if (opening) rowEl(id)?.querySelector('[role="menuitem"]')?.focus(); else menuTrigger(id)?.focus();
        return;
      }
      if (a === 'moveto') { state.moveMenu = true; render(); rowEl(id)?.querySelector('[data-act="move"]:not([disabled])')?.focus(); return; }
      if (a === 'move') { state.menuOpen = null; state.moveMenu = false; await moveScriptTo(id, to); return; }
      if (state.menuOpen) { state.menuOpen = null; render(); menuTrigger(id)?.focus(); }
      if (a === 'run') { await api('run', { id }); toast(`${s.name} started`); US.openLog(id); }
      else if (a === 'background') { await api('background', { id }); toast(`${s.name} started in the background`); }
      else if (a === 'abort') { await api('abort', { id }); toast(`${s.name} aborted`); }
      else if (a === 'log') US.openLog(id);
      else if (a === 'edit') US.openEdit(id);
      else if (a === 'options') US.openOptions(id);
      else if (a === 'rename') { const n = prompt('New name', s.name); if (n !== null && n.trim()) { await api('rename', { id, name: n }); toast('Renamed'); } }
      else if (a === 'describe') { const d = prompt('Description', s.description); if (d !== null) { await api('describe', { id, description: d }); toast('Description saved'); } }
      else if (a === 'delete') { if (confirm(`Delete "${s.name}"? Its script file and logs are removed.`)) { await api('delete', { id }); delete state.pending[id]; toast(`${s.name} deleted`); } }
      await refresh();
    } catch (e) { toast(e.message, 'err'); await refresh(); }
  }

  const rowEl = (id) => root.querySelector(`.us-row[data-id="${CSS.escape(id)}"]`);
  const menuTrigger = (id) => rowEl(id)?.querySelector('[data-act="menu"]');

  let cronCheck;
  function stage(id, fields) {
    const s = byId(id);
    if (!s) return;
    const p = { ...(state.pending[id] || {}), ...fields };
    const same = p.frequency === s.schedule.frequency && (p.frequency !== 'custom' || (p.custom ?? s.schedule.custom) === s.schedule.custom);
    if (same) delete state.pending[id]; else state.pending[id] = { custom: s.schedule.custom, ...p };
  }

  root.addEventListener('click', (e) => {
    const fb = e.target.closest('button[data-fact]');
    if (fb) { folderAct(fb.closest('.us-folder').dataset.folderId, fb.dataset.fact); return; }
    const b = e.target.closest('button[data-act]');
    if (b && b.closest('.us-row')) { act(b.closest('.us-row').dataset.id, b.dataset.act, b.dataset.to || ''); return; }
    if ((state.menuOpen || state.fmenuOpen) && !e.target.closest('.us-menu')) { state.menuOpen = null; state.moveMenu = false; state.fmenuOpen = null; render(); }
  });
  root.addEventListener('change', (e) => {
    if (!e.target.classList.contains('us-freq')) return;
    const id = e.target.closest('.us-row').dataset.id;
    stage(id, { frequency: e.target.value, _invalid: false, _text: '' });
    if (e.target.value === 'custom') {
      const p = state.pending[id];
      if (p && !p.custom) { p._invalid = true; p._text = 'enter a cron expression'; }
    }
    render();
    if (e.target.value === 'custom') root.querySelector(`.us-row[data-id="${CSS.escape(id)}"] .us-cron`)?.focus();
  });
  root.addEventListener('input', (e) => {
    if (!e.target.classList.contains('us-cron')) return;
    const input = e.target, id = input.closest('.us-row').dataset.id, expr = input.value.trim();
    stage(id, { frequency: 'custom', custom: expr });
    const p = state.pending[id];
    if (p) p._invalid = true; // until cron.check confirms it
    $('#us-apply').disabled = true;
    clearTimeout(cronCheck);
    cronCheck = setTimeout(async () => {
      const r = await api('cron.check', { expr }).catch(() => ({ valid: false, failed: true }));
      if (!input.isConnected || input.value.trim() !== expr) return; // stale response
      const meta = input.parentElement.querySelector('[data-meta]') || input.insertAdjacentElement('afterend', Object.assign(document.createElement('span'), { className: 'us-meta' }));
      meta.setAttribute('data-meta', '');
      const bad = r.failed ? 'could not check expression' : (r.error || 'invalid cron expression');
      if (p) { p._invalid = !r.valid; p._text = r.valid ? `${r.text}${r.next ? ` · next ${fmtWhen(r.next)}` : ''} (unsaved)` : bad; }
      input.setAttribute('aria-invalid', String(!r.valid));
      meta.className = `us-meta${r.valid ? '' : ' err'}`;
      meta.textContent = p ? p._text : (r.valid ? r.text : bad);
      $('#us-apply').disabled = Object.keys(state.pending).length === 0 || Object.values(state.pending).some((x) => x._invalid);
    }, 300);
  });

  $('#us-apply').addEventListener('click', async () => {
    const changes = Object.fromEntries(Object.entries(state.pending).map(([id, p]) => [id, { frequency: p.frequency, custom: p.custom ?? '' }]));
    try { await api('apply', { changes }); state.pending = {}; toast('Schedule applied'); await refresh(); }
    catch (e) { toast(e.message, 'err'); }
  });
  $('#us-add').addEventListener('click', async () => {
    const name = prompt('Name for the new script');
    if (!name || !name.trim()) return;
    try { const r = await api('create', { name }); await refresh(); US.openEdit(r.id); } catch (e) { toast(e.message, 'err'); }
  });
  $('#us-settings').addEventListener('click', () => US.openSettings());
  $('#us-search').addEventListener('input', (e) => { state.query = e.target.value; render(); });
  root.querySelectorAll('.us-chip').forEach((c) => c.addEventListener('click', () => {
    state.filter = c.dataset.filter;
    root.querySelectorAll('.us-chip').forEach((x) => x.setAttribute('aria-pressed', String(x === c)));
    render();
  }));
  function clearFilters() {
    state.filter = 'all';
    root.querySelectorAll('.us-chip').forEach((x) => x.setAttribute('aria-pressed', String(x.dataset.filter === 'all')));
    clearSearch(); $('#us-search').focus();
  }
  $('#us-clear-filters').addEventListener('click', clearFilters);

  // density: compact or comfortable, remembered per browser
  const densityBtn = $('#us-density');
  function setDensity(d) { root.dataset.density = d; densityBtn.setAttribute('aria-pressed', String(d === 'compact')); store.set('density', d); }
  setDensity(store.get('density', 'comfortable') === 'compact' ? 'compact' : 'comfortable');
  densityBtn.addEventListener('click', () => setDensity(root.dataset.density === 'compact' ? 'comfortable' : 'compact'));

  // sparkline tooltip: hover a tick, or focus the sparkline and use the arrow keys
  const tip = Object.assign(document.createElement('div'), { id: 'us-tip', className: 'us-tip', hidden: true });
  tip.setAttribute('role', 'tooltip');
  root.appendChild(tip);
  let tipAt = null;
  function showTip(spark, i) {
    const ticks = spark.querySelectorAll('.us-tick');
    const s = byId(spark.closest('.us-row')?.dataset.id);
    if (!ticks.length || !s?.spark) return;
    i = Math.max(0, Math.min(ticks.length - 1, i));
    ticks.forEach((x, j) => x.classList.toggle('cur', j === i));
    tip.textContent = tickTip(s.spark[i]);
    tip.hidden = false;
    const b = ticks[i].getBoundingClientRect(), r = root.getBoundingClientRect();
    tip.style.left = `${Math.max(0, Math.min(b.left - r.left - 40, root.clientWidth - tip.offsetWidth))}px`;
    tip.style.top = `${b.bottom - r.top + 6}px`;
    tipAt = { spark, i };
  }
  function hideTip() { tip.hidden = true; tipAt?.spark.querySelectorAll('.cur').forEach((x) => x.classList.remove('cur')); tipAt = null; }
  root.addEventListener('mouseover', (e) => { const t = e.target.closest?.('.us-tick'); if (t) showTip(t.parentElement, +t.dataset.i); });
  root.addEventListener('mouseout', (e) => { if (e.target.closest?.('.us-tick') && !e.relatedTarget?.closest?.('.us-tick') && document.activeElement !== tipAt?.spark) hideTip(); });
  root.addEventListener('focusin', (e) => { if (e.target.classList?.contains('us-spark')) showTip(e.target, e.target.children.length - 1); });
  root.addEventListener('focusout', (e) => { if (e.target.classList?.contains('us-spark')) hideTip(); });
  root.addEventListener('keydown', (e) => {
    if (!e.target.classList?.contains('us-spark') || !tipAt || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
    e.preventDefault();
    showTip(e.target, tipAt.i + (e.key === 'ArrowRight' ? 1 : -1));
  });

  document.addEventListener('visibilitychange', () => { if (!document.hidden) { refresh(); poll(); } });
  window.addEventListener('beforeunload', (e) => { if (Object.keys(state.pending).length) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------- folders and order ---------- */
  const listEl = $('#us-list');
  const focusKey = (key) => listEl.querySelector(`[data-key="${CSS.escape(key)}"]`)?.focus();
  const folderEl = (fid) => listEl.querySelector(`.us-folder[data-folder-id="${CSS.escape(fid)}"]`);
  /** Trims to at most max UTF-8 bytes without splitting a character (the server limits folder names and icons in bytes). */
  function cutBytes(s, max) {
    const enc = new TextEncoder(), chars = Array.from(s);
    while (chars.length && enc.encode(chars.join('')).length > max) chars.pop();
    return chars.join('');
  }

  let saveChain = Promise.resolve();
  let inFlight = null;  // { version, items } of the save on the wire
  let unsent = false;   // a newer layout is queued behind it
  let flushed = null;   // items the pagehide handler already sent
  /** Shows the new order at once, then saves in sequence. A conflict (another tab saved first) reloads the stored layout. */
  function saveLayout(items) {
    state.layout = { ...state.layout, items };
    state.saving++;
    unsent = true;
    render();
    saveChain = saveChain.then(async () => {
      try {
        unsent = false;
        if (flushed && flushed === state.layout.items) {
          // Back from the bfcache: pagehide already sent these items; take whatever the server kept.
          flushed = null;
          state.layout = (await api('layout.get')).layout;
          return;
        }
        inFlight = { version: state.layout.version, items: state.layout.items };
        // keepalive: a reload right after a move must not cancel its save.
        const r = await api('layout.save', { layout: { items: inFlight.items }, version: inFlight.version }, true);
        state.layout = { version: r.layout.version, items: state.saving > 1 ? state.layout.items : r.layout.items };
      } catch (e) {
        toast(e.status === 409 ? 'The folder layout was changed elsewhere and has been reloaded.' : e.message, 'err');
        try { state.layout = e.body?.layout || (await api('layout.get')).layout; } catch { /* the next poll reloads it */ }
      } finally {
        inFlight = null;
        state.saving--;
        render();
      }
    });
    return saveChain;
  }
  // Leaving the page with a move still queued behind the in-flight save: send it now. It carries the in-flight
  // save's base version and items ("after"), so the server takes it only if that save has not landed yet or is
  // exactly what is stored; a save from another tab in between makes it a conflict.
  window.addEventListener('pagehide', () => {
    if (!unsent) return;
    unsent = false;
    flushed = state.layout.items;
    const data = inFlight
      ? { layout: { items: flushed }, version: inFlight.version, after: inFlight.items }
      : { layout: { items: flushed }, version: state.layout.version };
    api('layout.save', data, true).catch(() => { /* best effort: the page is going away */ });
  });

  async function moveScriptTo(id, to) {
    const key = `s:${id}`, items = state.layout.items;
    const at = UsLayout.locate(items, key);
    let next = items;
    if (to) next = UsLayout.moveTo(items, key, `f:${to}`, 'into');
    else if (at && at.folder !== null) next = UsLayout.moveTo(items, key, UsLayout.keyOf(items[at.folder]), 'after');
    if (next !== items) await saveLayout(next); else render();
    focusKey(key);
  }

  async function folderAct(fid, a) {
    const f = UsLayout.folders(state.layout.items).find((x) => x.id === fid);
    if (!f) return;
    if (a === 'toggle') {
      if (state.collapsed.has(fid)) state.collapsed.delete(fid); else state.collapsed.add(fid);
      store.set('collapsed', [...state.collapsed]);
      render();
      return;
    }
    if (a === 'menu') {
      state.fmenuOpen = state.fmenuOpen === fid ? null : fid; state.menuOpen = null; render();
      if (state.fmenuOpen) folderEl(fid)?.querySelector('[role="menuitem"]')?.focus(); else folderEl(fid)?.querySelector('[data-fact="menu"]')?.focus();
      return;
    }
    state.fmenuOpen = null;
    render();
    if (a === 'rename') {
      const n = prompt('Folder name', f.name);
      if (n !== null && n.trim()) await saveLayout(UsLayout.updateFolder(state.layout.items, fid, { name: cutBytes(n.trim(), 64) }));
    } else if (a === 'icon') {
      const i = prompt('Folder icon: an emoji or a few characters', f.icon || '');
      if (i !== null) await saveLayout(UsLayout.updateFolder(state.layout.items, fid, { icon: cutBytes(i.trim(), 16) }));
    } else if (a === 'delete') {
      if (confirm(`Delete folder "${f.name}"? Its scripts stay, at the top level.`)) await saveLayout(UsLayout.deleteFolder(state.layout.items, fid));
    }
    focusKey(`f:${fid}`);
  }

  $('#us-newfolder').addEventListener('click', async () => {
    const name = prompt('Folder name');
    if (!name || !name.trim()) return;
    const { items, id } = UsLayout.addFolder(state.layout.items, cutBytes(name.trim(), 64));
    await saveLayout(items);
    focusKey(`f:${id}`);
  });

  // drag and drop (mouse); Move to… and Alt+arrows are the keyboard and touch alternatives
  function dropTarget(e) {
    const el = e.target.closest?.('[data-key]');
    if (!el || !drag || el.dataset.key === drag) return null;
    const b = el.getBoundingClientRect(), y = (e.clientY - b.top) / b.height;
    if (drag.startsWith('s:') && el.classList.contains('us-folder') && y > 0.25 && y < 0.75) return { el, key: el.dataset.key, where: 'into' };
    return { el, key: el.dataset.key, where: y < 0.5 ? 'before' : 'after' };
  }
  const clearDrop = () => listEl.querySelectorAll('.us-drop-before, .us-drop-after, .us-drop-into').forEach((x) => x.classList.remove('us-drop-before', 'us-drop-after', 'us-drop-into'));
  listEl.addEventListener('dragstart', (e) => {
    const el = e.target.closest?.('[data-key]');
    if (!el || !e.target.closest?.('.us-grip')) return; // dragging selected text stays a text drag
    drag = el.dataset.key;
    state.menuOpen = state.fmenuOpen = null;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', drag);
    e.dataTransfer.setDragImage(el, e.clientX - el.getBoundingClientRect().left, e.clientY - el.getBoundingClientRect().top);
    el.classList.add('us-dragging');
  });
  listEl.addEventListener('dragover', (e) => {
    const t = dropTarget(e);
    clearDrop();
    if (!t) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    t.el.classList.add(`us-drop-${t.where}`);
  });
  listEl.addEventListener('dragleave', (e) => { if (!listEl.contains(e.relatedTarget)) clearDrop(); });
  listEl.addEventListener('drop', async (e) => {
    const t = dropTarget(e), key = drag;
    e.preventDefault();
    clearDrop();
    drag = null;
    if (!t || !key) return;
    const next = UsLayout.moveTo(state.layout.items, key, t.key, t.where);
    if (next !== state.layout.items) { await saveLayout(next); focusKey(key); }
  });
  listEl.addEventListener('dragend', () => { clearDrop(); drag = null; listEl.querySelectorAll('.us-dragging').forEach((x) => x.classList.remove('us-dragging')); });

  listEl.addEventListener('keydown', async (e) => {
    if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    const el = e.target.closest('[data-key]');
    if (!el || e.target.closest('input, select, textarea')) return;
    e.preventDefault();
    const key = el.dataset.key;
    const next = UsLayout.step(state.layout.items, key, e.key === 'ArrowUp' ? -1 : 1);
    if (next !== state.layout.items) { await saveLayout(next); focusKey(key); }
  });

  function clearSearch() { state.query = ''; $('#us-search').value = ''; render(); }
  const selectedScript = () => (state.sel?.startsWith('s:') ? state.sel.slice(2) : null);
  function moveSel(d) {
    const els = [...listEl.querySelectorAll('[data-key]')];
    if (!els.length) return;
    const i = els.findIndex((x) => x.dataset.key === state.sel);
    const next = els[i < 0 ? (d > 0 ? 0 : els.length - 1) : Math.max(0, Math.min(els.length - 1, i + d))];
    next.focus(); // focusin records the selection
    next.scrollIntoView({ block: 'nearest' });
  }
  listEl.addEventListener('focusin', (e) => {
    const el = e.target.closest('[data-key]');
    if (!el || state.sel === el.dataset.key) return;
    state.sel = el.dataset.key;
    listEl.querySelectorAll('.us-selected').forEach((x) => x.classList.remove('us-selected'));
    el.classList.add('us-selected');
  });
  document.addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    if (!$('#us-drawer').hidden) return; // the drawer owns the keyboard while it is open (Esc closes it)
    if (e.key === 'Escape') {
      if (state.menuOpen || state.fmenuOpen) {
        const id = state.menuOpen, fid = state.fmenuOpen;
        state.menuOpen = state.fmenuOpen = null; state.moveMenu = false; render();
        (id ? menuTrigger(id) : folderEl(fid)?.querySelector('[data-fact="menu"]'))?.focus();
      } else if (state.query || e.target === $('#us-search')) { e.preventDefault(); clearSearch(); }
      return;
    }
    if (e.target.closest?.('input, textarea, select, .ace_editor, [contenteditable="true"]')) return;
    if (e.key === '/') { e.preventDefault(); $('#us-search').focus(); }
    else if (e.key === 'j' || e.key === 'k') { e.preventDefault(); moveSel(e.key === 'j' ? 1 : -1); }
    else if (e.key === 'e' || e.key === 'r') {
      const id = selectedScript(), s = id && byId(id);
      if (!s) return;
      e.preventDefault();
      act(id, e.key === 'e' ? 'edit' : s.running ? 'log' : s.vars?.backgroundOnly ? 'background' : 'run');
    } else if (e.key === '?') { e.preventDefault(); US.openHelp(); }
  });
  $('#us-help').addEventListener('click', () => US.openHelp());

  refresh().then(poll);
  return { api, state, store, render, refresh, toast, esc, fmtAgo, fmtDur, fmtWhen, pillLabel, pillClass, byId, $, openLog() {}, openEdit() {}, openOptions() {}, openSettings() {}, openHelp() {} };
})();

/* Drawers: log/history, editor, run options, settings. */
(() => {
  const { api, esc, fmtWhen, fmtDur, toast, $ } = US;
  const app = document.getElementById('us-app'); // US.$ searches inside #us-app, so it cannot return #us-app itself
  const drawer = $('#us-drawer'), title = $('#us-drawer-title'), body = $('#us-drawer-body');
  let cleanup = null, lastFocus = null, opener = null, dirty = () => false;

  function open(t, html, onClose) {
    if (cleanup) cleanup();
    if (drawer.hidden) {
      lastFocus = document.activeElement;
      const row = lastFocus?.closest?.('.us-row');
      opener = row ? { id: row.dataset.id, act: lastFocus.dataset.act } : null;
    }
    dirty = () => false;
    title.textContent = t; body.innerHTML = html; drawer.hidden = false;
    cleanup = onClose || null;
    (body.querySelector('[autofocus]') || $('#us-drawer-close')).focus();
  }
  function restoreFocus() {
    if (lastFocus?.isConnected) return lastFocus.focus();
    // the list re-rendered while the drawer was open (e.g. Run became Abort): find the same row's control
    const row = opener && $(`.us-row[data-id="${CSS.escape(opener.id)}"]`);
    (row && (row.querySelector(`[data-act="${opener.act}"]`) || row.querySelector('[data-act="log"]')))?.focus();
  }
  function close() {
    if (dirty() && !confirm('Discard unsaved changes?')) return;
    if (cleanup) cleanup();
    cleanup = null; dirty = () => false; drawer.hidden = true; body.innerHTML = '';
    restoreFocus(); lastFocus = opener = null;
  }
  $('#us-drawer-close').addEventListener('click', close);
  let downOnBackdrop = false; // close only when press and release both land on the backdrop (not a drag out of a text field)
  drawer.addEventListener('mousedown', (e) => { downOnBackdrop = e.target === drawer; });
  drawer.addEventListener('click', (e) => { if (e.target === drawer && downOnBackdrop) close(); downOnBackdrop = false; });
  // the page's "/" search shortcut must not reach the list behind the modal
  document.addEventListener('keydown', (e) => { if (!drawer.hidden && e.key === '/' && !e.target.closest('input, textarea, select, .ace_editor')) { e.preventDefault(); e.stopImmediatePropagation(); } }, true);
  document.addEventListener('keydown', (e) => {
    if (drawer.hidden) return;
    if (e.key === 'Escape' && !e.target.closest('.ace_search, select')) { e.preventDefault(); close(); }
    if (e.key === 'Tab') { // keep focus inside the drawer
      const f = [...drawer.querySelectorAll('button, input, select, textarea, [tabindex]:not([tabindex="-1"])')].filter((x) => !x.disabled && x.offsetParent);
      if (!f.length) return;
      if (!drawer.contains(document.activeElement)) { e.preventDefault(); f[0].focus(); }
      else if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });

  US.openLog = (id) => {
    const s = US.byId(id);
    if (!s) return;
    let offset = 0, tm, size = -1, alive = true, paused = false, wasRunning = null;
    open(`Log: ${s.name}`, `
      <div class="us-tabs" role="tablist"><button type="button" role="tab" aria-selected="true" data-tab="log">Log</button><button type="button" role="tab" aria-selected="false" data-tab="hist">History</button></div>
      <div data-pane="log" style="display:contents">
        <div class="us-logbar">
          <label>Run <select id="us-which"><option value="0">Current / latest</option>${[1, 2, 3, 4, 5].map((i) => `<option value="${i}">${i} run${i > 1 ? 's' : ''} before</option>`).join('')}</select></label>
          <label><input type="checkbox" id="us-wrap"> Wrap lines</label>
          <button type="button" id="us-dl">Download</button><button type="button" id="us-clear">Clear logs</button>
          <span class="us-meta" id="us-logstate" aria-live="polite"></span>
        </div>
        <pre class="us-log" id="us-log" tabindex="0" aria-label="Script output"></pre>
      </div>
      <div data-pane="hist" hidden><table class="us-hist"><thead><tr><th>Started</th><th>Status</th><th>Exit</th><th>Duration</th><th>Trigger</th></tr></thead><tbody id="us-hist"></tbody></table></div>`,
    () => { alive = false; clearTimeout(tm); });
    const pre = $('#us-log'), which = $('#us-which'), state = $('#us-logstate');
    async function tick(reset) {
      clearTimeout(tm);
      if (reset) { offset = 0; pre.textContent = ''; size = -1; wasRunning = null; }
      const w = which.value;
      try {
        const r = await api('log', { id, which: +w, offset });
        if (!alive || w !== which.value) return; // drawer closed or run selection changed meanwhile
        const started = w === '0' && wasRunning === false && r.running; // idle -> running: a new run replaced the log
        if (w === '0') wasRunning = r.running;
        if (r.size < size || (started && size >= 0)) { pre.textContent = ''; offset = 0; size = -1; return tick(false); }
        const atBottom = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 20;
        pre.textContent += r.text; offset = r.size; size = r.size;
        if (atBottom || reset) pre.scrollTop = pre.scrollHeight;
        state.textContent = r.running && w === '0' ? 'running, updating live' : (r.size ? '' : 'no log yet');
        if (w === '0' && !paused) tm = setTimeout(() => tick(false), r.running ? 2000 : 5000);
      } catch (e) {
        if (!alive) return;
        toast(e.message, 'err');
        if (w === '0' && !paused) tm = setTimeout(() => tick(false), 10000);
      }
    }
    which.addEventListener('change', () => tick(true));
    $('#us-wrap').addEventListener('change', (e) => pre.classList.toggle('wrap', e.target.checked));
    $('#us-dl').addEventListener('click', () => {
      const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(new Blob([pre.textContent], { type: 'text/plain' })), download: `${id}-log${which.value !== '0' ? '.' + which.value : ''}.txt` });
      a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    });
    $('#us-clear').addEventListener('click', async () => {
      if (!confirm('Delete all saved logs for this script?')) return;
      try { await api('clearLog', { id }); tick(true); } catch (e) { toast(e.message, 'err'); }
    });
    body.querySelectorAll('[data-tab]').forEach((t) => t.addEventListener('click', async () => {
      body.querySelectorAll('[data-tab]').forEach((x) => x.setAttribute('aria-selected', String(x === t)));
      const logPane = body.querySelector('[data-pane="log"]');
      logPane.hidden = t.dataset.tab !== 'log';
      logPane.style.display = t.dataset.tab === 'log' ? 'contents' : 'none';
      body.querySelector('[data-pane="hist"]').hidden = t.dataset.tab !== 'hist';
      if (t.dataset.tab !== 'hist') { if (paused) { paused = false; tick(false); } return; }
      paused = true; clearTimeout(tm); // no log polling while History is shown
      let r;
      try { r = await api('history', { id, limit: 200 }); } catch (e) { toast(e.message, 'err'); return; }
      if (!alive) return;
      $('#us-hist').innerHTML = r.runs.map((h) => `<tr><td>${esc(fmtWhen(h.start))}${h.repeat ? `<br><span class="us-meta">×${esc(Number(h.repeat).toLocaleString())} since ${esc(fmtWhen(h.first))}</span>` : ''}</td>
        <td><span class="us-pill ${esc(US.pillClass(h))}">${esc(US.pillLabel(h))}</span></td><td>${esc(h.exit)}</td>
        <td>${h.status === 'skipped' ? '' : esc(fmtDur(h.dur ?? 0))}${h.repeat ? `<br><span class="us-meta">min ${esc(fmtDur(h.dmin))} · avg ${esc(fmtDur(h.davg))} · max ${esc(fmtDur(h.dmax))}</span>` : ''}</td>
        <td>${esc(h.trigger)}</td></tr>`).join('') || '<tr><td colspan="5" class="us-meta">No runs recorded yet.</td></tr>';
    }));
    tick(true);
  };

  const VARS = [['arrayStarted', 'Only run when the array is started'], ['noParity', 'Skip while a parity check runs'], ['foregroundOnly', 'Foreground only'],
    ['backgroundOnly', 'Background only'], ['clearLog', 'Do not keep previous logs']];

  const { setHeaderVar, parse: parseHeader, truthy } = UsHeaderVars; // ui/header-vars.js, mirrors lib/Vars.php
  US.setHeaderVar = setHeaderVar;

  // ACE is ~360 KB: load it the first time the editor opens. Resolves to null when it cannot load (plain textarea fallback).
  let acePromise = null;
  function loadAce() {
    if (window.ace) return Promise.resolve(window.ace);
    acePromise ??= new Promise((resolve) => {
      const src = app.dataset.ace || '/plugins/voz.cron/javascript/ace/ace.js';
      const sc = Object.assign(document.createElement('script'), { src, async: true });
      sc.onload = () => {
        if (!window.ace) return resolve(null);
        ace.config.set('basePath', src.replace(/\/ace\.js(\?.*)?$/, ''));
        resolve(window.ace);
      };
      sc.onerror = () => { acePromise = null; sc.remove(); resolve(null); };
      document.head.appendChild(sc);
    });
    return acePromise;
  }

  US.openEdit = async (id) => {
    const s = US.byId(id);
    if (!s) return;
    let text;
    try { [text] = await Promise.all([api('get', { id }).then((r) => r.body), loadAce()]); } catch (e) { toast(e.message, 'err'); return; }
    const useAce = !!window.ace;
    let ed = null;
    open(`Edit: ${s.name}`, `
      <div class="us-vars">${VARS.map(([k, l]) => `<label><input type="checkbox" data-var="${k}"${s.vars?.[k] ? ' checked' : ''}> ${l}</label>`).join('')}</div>
      ${useAce ? '<div id="us-ace" class="us-editor"></div>' : '<textarea id="us-ta" class="us-editor" spellcheck="false" aria-label="Script"></textarea>'}
      <p class="us-meta">Saved to /boot/config/plugins/voz.cron/scripts/${esc(id)}/script. Ctrl+S saves.</p>
      <div class="us-foot"><button type="button" id="us-cancel">Close</button><button type="button" id="us-save">Save</button></div>`, () => { clearTimeout(syncT); ed?.destroy(); });
    const ta = $('#us-ta');
    if (useAce) {
      const dark = /black|gray/.test(app.dataset.theme);
      ed = ace.edit('us-ace', { mode: 'ace/mode/' + (/^#!.*\bphp\b/.test(text) ? 'php' : /^#!.*python/.test(text) ? 'python' : 'sh'),
        theme: dark ? 'ace/theme/tomorrow_night' : 'ace/theme/tomorrow', value: text, fontSize: 13, showPrintMargin: false, tabSize: 2, useSoftTabs: true });
      ed.clearSelection(); ed.moveCursorTo(0, 0);
      ed.textInput.getElement().setAttribute('aria-label', 'Script');
    } else ta.value = text;
    let saved = ed ? ed.getValue() : ta.value; // the textarea normalises CRLF, so compare against what it holds
    const get = () => ed ? ed.getValue() : ta.value;
    const set = (v) => {
      if (ed) { const pos = ed.getCursorPosition(); ed.setValue(v, -1); ed.moveCursorToPosition(pos); }
      else { const p = ta.selectionStart; ta.value = v; ta.selectionStart = ta.selectionEnd = Math.min(p, v.length); }
    };
    dirty = () => get() !== saved;
    (ed || ta).focus();
    const boxes = [...body.querySelectorAll('[data-var]')];
    const syncBoxes = () => { const v = parseHeader(get()); boxes.forEach((c) => { c.checked = truthy(v[c.dataset.var]); }); };
    boxes.forEach((c) => c.addEventListener('change', () => { set(setHeaderVar(get(), c.dataset.var, c.checked)); syncBoxes(); }));
    let syncT; const queueSync = () => { clearTimeout(syncT); syncT = setTimeout(syncBoxes, 250); };
    ed?.session.on('change', queueSync); ta?.addEventListener('input', queueSync);
    syncBoxes();
    const save = async () => {
      const v = get();
      try { await api('save', { id, body: v }); saved = v; toast('Script saved'); US.refresh(); } catch (e) { toast(e.message, 'err'); }
    };
    ed?.commands.addCommand({ name: 'save', bindKey: { win: 'Ctrl-S', mac: 'Command-S' }, exec: save });
    ta?.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); } });
    $('#us-save').addEventListener('click', save);
    $('#us-cancel').addEventListener('click', close);
  };

  US.openOptions = (id) => {
    const s = US.byId(id);
    if (!s) return;
    const o = s.schedule;
    const sel = (name, opts) => `<select name="${name}" id="us-o-${name}">${opts.map(([v, l]) => `<option value="${v}"${String(o[name]) === String(v) ? ' selected' : ''}>${l}</option>`).join('')}</select>`;
    open(`Run options: ${s.name}`, `
      <form class="us-form" id="us-opts" novalidate>
        <label for="us-o-notify">Notifications</label>${sel('notify', [['off', 'Off'], ['failure', 'On failure'], ['always', 'Every run']])}
        <p class="hint">Warnings (exit codes mapped to warn) are notified too, unless this is Off.</p>
        <span class="us-label" id="us-xc-label">Exit codes</span>
        <div class="us-xc" role="group" aria-labelledby="us-xc-label">
          <div id="us-xc-rows"></div>
          <input type="text" id="us-xc-text" class="wide" spellcheck="false" placeholder="0=ok *=fail" aria-label="Exit code rules as text" aria-describedby="us-xc-err" hidden>
          <div class="us-xc-bar"><button type="button" id="us-xc-add">Add rule</button><button type="button" id="us-xc-mode" aria-pressed="false">Edit as text</button></div>
          <input type="hidden" name="exitCodes" id="us-xc-value" value="${esc(o.exitCodes || '')}">
        </div>
        <p class="hint err" id="us-xc-err" role="alert" hidden></p>
        <p class="hint">First matching rule wins; an exit code no rule matches counts as failed. Timeouts always fail.
          Try exit <input type="number" id="us-xc-try" class="us-xc-try" min="0" max="255" value="1" aria-label="Exit code to try"> <span id="us-xc-preview" aria-live="polite"></span></p>
        <label for="us-o-failAfter">Alert after</label><input type="number" min="1" max="1000" name="failAfter" id="us-o-failAfter" value="${esc(o.failAfter)}">
        <p class="hint">consecutive failures. Use 3 for scripts that run every minute so one blip stays quiet.</p>
        <label for="us-o-remindHours">Remind every</label><input type="number" min="0" max="720" name="remindHours" id="us-o-remindHours" value="${esc(o.remindHours)}">
        <p class="hint">hours while it keeps failing or warning. 0 never reminds. Recovery is always announced after an alert.</p>
        <label for="us-o-overlap">If still running</label>${sel('overlap', [['skip', 'Skip the new run'], ['queue', 'Queue one run'], ['parallel', 'Run anyway (parallel)']])}
        <label for="us-o-hangAfter">Stuck alert after</label><input type="number" min="1" max="1000" name="hangAfter" id="us-o-hangAfter" value="${esc(o.hangAfter)}">
        <p class="hint">skipped runs in a row.</p>
        <label for="us-o-timeout">Timeout</label><input type="number" min="0" max="604800" name="timeout" id="us-o-timeout" value="${esc(o.timeout)}">
        <p class="hint">seconds. 0 means no limit. The whole process group is stopped when it is reached.</p>
      </form>
      <div class="us-foot"><button type="button" id="us-test-notify">Send test notification</button><span class="us-spacer"></span><button type="button" id="us-cancel">Cancel</button><button type="button" id="us-save">Save</button></div>`);
    const form = $('#us-opts');
    const xc = exitCodeEditor(o.exitCodes || '');
    $('#us-o-notify').focus();
    const initial = new URLSearchParams(new FormData(form)).toString();
    dirty = () => new URLSearchParams(new FormData(form)).toString() !== initial;
    $('#us-cancel').addEventListener('click', close);
    $('#us-test-notify').addEventListener('click', async (e) => {
      const b = e.currentTarget;
      b.disabled = true;
      try { await api('notify.test', { id }); toast('Test notification sent. Check Unraid notifications and your notification agents.'); }
      catch (err) { toast(err.message, 'err'); }
      finally { b.disabled = false; }
    });
    $('#us-save').addEventListener('click', async () => {
      if (!xc.valid()) { toast('Fix the exit code rules first', 'err'); return; }
      const f = Object.fromEntries(new FormData(form));
      for (const k of ['failAfter', 'remindHours', 'hangAfter', 'timeout']) f[k] = Number(f[k]);
      try { await api('schedule.set', { id, fields: f }); dirty = () => false; close(); await US.refresh(); toast('Run options saved'); }
      catch (e) { toast(e.message, 'err'); }
    });
  };

  US.openSettings = async () => {
    let st;
    try { st = (await api('settings.get')).settings; } catch (e) { toast(e.message, 'err'); return; }
    open('VOZ_CRON settings', `
      <form class="us-form" id="us-set" novalidate>
        <label for="us-s-dir">History folder</label><input type="text" name="historyDir" id="us-s-dir" value="${esc(st.historyDir)}" spellcheck="false" class="wide" aria-describedby="us-s-dir-err">
        <p class="hint err" id="us-s-dir-err" hidden></p>
        <p class="hint">Default is on the flash drive. A pool path such as /mnt/user/system/voz.cron saves flash writes; while the array is stopped, history stays in RAM and is saved once the path is back.</p>
        <label for="us-s-sync">Save history every</label><select name="syncMinutes" id="us-s-sync">${[15, 30, 60].map((m) => `<option value="${m}"${m === st.syncMinutes ? ' selected' : ''}>${m} minutes</option>`).join('')}</select>
        <p class="hint">Failures and recoveries are always saved immediately.</p>
        <label for="us-s-cap">Keep</label><input type="number" min="10" max="5000" name="historyCap" id="us-s-cap" value="${esc(st.historyCap)}">
        <p class="hint">history entries per script. Repeated identical results count as one entry.</p>
      </form>
      <div class="us-foot"><button type="button" id="us-cancel">Cancel</button><button type="button" id="us-save">Save</button></div>`);
    const form = $('#us-set');
    $('#us-s-dir').focus();
    const initial = new URLSearchParams(new FormData(form)).toString();
    dirty = () => new URLSearchParams(new FormData(form)).toString() !== initial;
    $('#us-cancel').addEventListener('click', close);
    $('#us-save').addEventListener('click', async () => {
      const f = Object.fromEntries(new FormData(form));
      f.syncMinutes = Number(f.syncMinutes); f.historyCap = Number(f.historyCap);
      const dir = $('#us-s-dir'), err = $('#us-s-dir-err');
      const bad = !/^\/(boot|mnt)\/[^/]/.test(f.historyDir) ? 'Use a folder under /boot/ or /mnt/.' : f.historyDir.includes('..') ? 'The path cannot contain "..".' : '';
      err.textContent = bad; err.hidden = !bad; dir.setAttribute('aria-invalid', String(!!bad));
      if (bad) { dir.focus(); return; }
      try { await api('settings.set', { settings: f }); dirty = () => false; toast('Settings saved'); close(); }
      catch (e) { toast(e.message, 'err'); }
    });
  };

  US.openHelp = () => open('Keyboard shortcuts', `<dl class="us-keys">
    <dt><kbd>/</kbd></dt><dd>Search</dd>
    <dt><kbd>j</kbd> <kbd>k</kbd></dt><dd>Next / previous row</dd>
    <dt><kbd>e</kbd></dt><dd>Edit the selected script</dd>
    <dt><kbd>r</kbd></dt><dd>Run the selected script (shows its log when it is already running)</dd>
    <dt><kbd>Alt</kbd> + <kbd>↑</kbd> <kbd>↓</kbd></dt><dd>Move the selected row up or down</dd>
    <dt><kbd>Esc</kbd></dt><dd>Close the drawer or a menu, or clear the search</dd>
    <dt><kbd>?</kbd></dt><dd>This help</dd></dl>
    <h3 class="us-about-h">About</h3>
    <p class="us-about">VOZ_CRON by <a href="https://github.com/VozDeOuro" target="_blank" rel="noopener">Lucas Carvalho (VozDeOuro)</a>.
    Based on User Scripts by Andrew Zawadzki (Squid) — <a href="https://github.com/Squidly271/user.scripts" target="_blank" rel="noopener">github.com/Squidly271/user.scripts</a>.
    Support: <a href="https://github.com/VozDeOuro/voz.cron/issues" target="_blank" rel="noopener">github.com/VozDeOuro/voz.cron</a>.</p>`);

  /** Rows editor + text view for a script's exit-code rules; writes the stored form into #us-xc-value. */
  function exitCodeEditor(initial) {
    const X = UsExitCodes;
    const rowsEl = $('#us-xc-rows'), text = $('#us-xc-text'), value = $('#us-xc-value'), err = $('#us-xc-err');
    const mode = $('#us-xc-mode'), add = $('#us-xc-add'), tryEl = $('#us-xc-try'), preview = $('#us-xc-preview');
    let rows = X.toRows((X.parse(initial).rules) || X.parse('').rules);
    const outSel = (o) => X.OUTCOMES.map((x) => `<option value="${x}"${x === o ? ' selected' : ''}>${x}</option>`).join('');
    function draw() {
      rowsEl.innerHTML = rows.map((r, i) => `<div class="us-xc-row" data-i="${i}">
        <input type="text" class="us-xc-codes" value="${esc(r.codes)}" aria-label="Exit codes for rule ${i + 1}" placeholder="1,3-5 or *" spellcheck="false">
        <select class="us-xc-out" aria-label="Outcome for rule ${i + 1}">${outSel(r.outcome)}</select>
        <button type="button" class="us-xc-del" aria-label="Remove rule ${i + 1}">✕</button></div>`).join('');
    }
    const current = () => (text.hidden ? X.fromRows(rows) : text.value);
    function check() {
      const p = X.parse(current());
      err.hidden = !p.error;
      err.textContent = p.error || '';
      (text.hidden ? rowsEl : text).setAttribute('aria-invalid', String(!!p.error));
      if (p.error) { preview.textContent = ''; return false; }
      value.value = p.text === X.DEFAULT ? '' : p.text;
      const n = Number(tryEl.value);
      preview.textContent = tryEl.value !== '' && Number.isInteger(n) ? `exit ${n} → ${X.outcome(p.rules, n)}` : '';
      return true;
    }
    rowsEl.addEventListener('input', (e) => {
      const row = e.target.closest('.us-xc-row');
      if (row && e.target.classList.contains('us-xc-codes')) { rows[+row.dataset.i].codes = e.target.value; check(); }
    });
    rowsEl.addEventListener('change', (e) => {
      const row = e.target.closest('.us-xc-row');
      if (row && e.target.classList.contains('us-xc-out')) { rows[+row.dataset.i].outcome = e.target.value; check(); }
    });
    rowsEl.addEventListener('click', (e) => {
      const d = e.target.closest('.us-xc-del');
      if (!d) return;
      rows.splice(+d.closest('.us-xc-row').dataset.i, 1);
      draw(); check();
    });
    add.addEventListener('click', () => {
      const star = rows.findIndex((r) => r.codes.trim() === '*');
      const at = star < 0 ? rows.length : star; // new rules go before the catch-all
      rows.splice(at, 0, { codes: '', outcome: 'warn' });
      draw(); check();
      rowsEl.querySelectorAll('.us-xc-codes')[at]?.focus();
    });
    mode.addEventListener('click', () => {
      if (text.hidden) {
        text.value = X.fromRows(rows);
        text.hidden = false; rowsEl.hidden = true; add.hidden = true;
        mode.textContent = 'Edit as rows'; mode.setAttribute('aria-pressed', 'true');
        text.focus();
      } else {
        const p = X.parse(text.value);
        if (p.error) { check(); text.focus(); return; }
        rows = X.toRows(p.rules); draw();
        text.hidden = true; rowsEl.hidden = false; add.hidden = false;
        mode.textContent = 'Edit as text'; mode.setAttribute('aria-pressed', 'false');
      }
      check();
    });
    text.addEventListener('input', check);
    tryEl.addEventListener('input', check);
    draw(); check();
    return { valid: check };
  }

  US.closeDrawer = close;
})();
