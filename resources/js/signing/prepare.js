import { h, icon, clear, toast, initials } from '../viewer/dom.js';
import { getJson, postJson } from '../viewer/api.js';
import { loadPages, FIELD_LABELS, FIELD_DEFAULTS } from './pages.js';

const root = document.getElementById('prepare-root');
if (root) boot(JSON.parse(root.dataset.config)).catch((e) => {
    console.error(e);
    root.replaceChildren(h('p', { class: 'empty' }, h('b', {}, 'The editor could not start.'), e.message || 'Reload the page and try again.'));
});

async function boot(cfg) {
    if (!cfg.envelopeId) return chooseSigners(cfg);
    return editor(cfg);
}

// ── Step 1: who signs ─────────────────────────────────────────────────────
function chooseSigners(cfg) {
    const rows = cfg.signable.map((p, i) => ({ ...p, on: p.preselect !== false, order: i + 1 }));
    const mode = { value: 'sequential' };
    const days = h('input', { class: 'input', type: 'number', min: 1, max: 90, value: cfg.defaultDays, style: { width: '90px' }, 'aria-label': 'Days until the request expires' });
    const err = h('p', { class: 'err', role: 'alert' });

    const list = h('ul', { class: 'rows signer-pick' });
    const draw = () => {
        clear(list);
        for (const r of rows) {
            const cb = h('input', { type: 'checkbox', checked: r.on, id: 'sg-' + r.id, onchange: () => { r.on = cb.checked; draw(); } });
            const ord = h('input', { class: 'input', type: 'number', min: 1, max: 50, value: r.order, disabled: !r.on || mode.value !== 'sequential', 'aria-label': 'Signing position for ' + r.name, style: { width: '72px' }, oninput: () => { r.order = parseInt(ord.value || '1', 10); } });
            list.append(h('li', { style: { '--cols': 'auto 1fr auto' }, class: 'pen pen-' + r.pen },
                cb, h('label', { for: 'sg-' + r.id }, h('span', { class: 'ini ini-sm' }, initials(r.name)), ' ', h('b', { class: 'by' }, r.name), h('span', { class: 'row-sub' }, r.email)), ord));
        }
    };
    draw();

    const modeSel = h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'Signing order' },
        ...[['sequential', 'One after another'], ['parallel', 'All at once']].map(([v, label]) => h('button', { type: 'button', role: 'radio', 'aria-checked': String(mode.value === v), onclick: (ev) => { mode.value = v; modeSel.querySelectorAll('button').forEach((b) => b.setAttribute('aria-checked', String(b === ev.currentTarget))); draw(); } }, label)));

    const go = h('button', { class: 'btn', type: 'button' }, h('span', {}, 'Continue to place the signature fields'));
    go.addEventListener('click', async () => {
        err.textContent = '';
        const chosen = rows.filter((r) => r.on);
        if (!chosen.length) { err.textContent = 'Choose at least one person who signs.'; return; }
        go.setAttribute('data-busy', 'true');
        try {
            await postJson(cfg.urls.draft, { mode: mode.value, expires_days: parseInt(days.value || '14', 10), signers: chosen.map((r) => ({ participant_id: r.id, order: r.order })) });
            location.reload();
        } catch (e) { err.textContent = e.message; go.removeAttribute('data-busy'); }
    });

    root.replaceChildren(
        h('h3', {}, 'Who signs'),
        cfg.signable.length ? null : h('p', { class: 'empty' }, 'Nobody has the sign capability yet. Open the People tab and give the people who sign that capability.'),
        list, h('div', { class: 'row-2', style: { marginTop: '18px', maxWidth: '620px' } },
            h('div', {}, h('div', { class: 'label', style: { marginBottom: '6px' } }, 'Order'), modeSel),
            h('div', {}, h('label', { class: 'label', for: 'days', style: { display: 'block', marginBottom: '6px' } }, 'Expires after (days)'), days)),
        err, h('div', { class: 'page-actions', style: { marginTop: '18px' } }, go));
}

// ── Step 2: where they sign ───────────────────────────────────────────────
async function editor(cfg) {
    let state = await getJson(cfg.urls.state);
    const S = { fields: state.fields.map((f) => ({ ...f })), tool: 'signature', signer: state.signers[0]?.id, selected: null, dirty: false, saving: false };
    let uid = -1;

    const toolbar = h('div', { class: 'ptools', role: 'toolbar', 'aria-label': 'Field types' });
    const signerBar = h('div', { class: 'psigners', role: 'radiogroup', 'aria-label': 'Place fields for' });
    const status = h('span', { class: 'muted fig', role: 'status', 'aria-live': 'polite' }, 'All changes saved');
    const summary = h('ul', { class: 'fsummary' });
    const pagesEl = h('div', { class: 'pages pedit' });
    const side = h('aside', { class: 'pside', 'aria-label': 'Fields placed' }, h('h3', {}, 'Fields placed'), summary,
        h('div', { class: 'page-actions', style: { marginTop: '16px' } },
            btn('Send for signature', 'btn', send), btn('Cancel this request', 'btn btn-quiet', cancel)), h('p', { class: 'err', id: 'send-err', role: 'alert' }));
    root.replaceChildren(h('div', { class: 'pedit-bar' }, signerBar, toolbar, status), h('div', { class: 'pedit-grid' }, h('div', {}, pagesEl), side));

    function btn(label, cls, fn) {
        const b = h('button', { type: 'button', class: cls }, h('span', {}, label));
        b.addEventListener('click', async () => { b.setAttribute('data-busy', 'true'); try { await fn(); } finally { b.removeAttribute('data-busy'); } });
        return b;
    }

    const { pages } = await loadPages(pagesEl, state.file_url, { maxWidth: 820 });
    pages.forEach((p) => {
        p.layer.addEventListener('pointerdown', (ev) => {
            if (ev.target !== p.layer || !state.me.can_prepare) return;
            const b = p.layer.getBoundingClientRect();
            const d = FIELD_DEFAULTS[S.tool];
            const x = Math.min(Math.max((ev.clientX - b.left) / b.width - d.w / 2, 0), 1 - d.w);
            const y = Math.min(Math.max((ev.clientY - b.top) / b.height - d.h / 2, 0), 1 - d.h);
            const f = { id: uid--, signer_id: S.signer, type: S.tool, page: p.num, x, y, w: d.w, h: d.h, required: true, label: null };
            S.fields.push(f);
            S.selected = f.id;
            changed();
        });
    });

    function signerOf(id) { return state.signers.find((s) => s.id === id); }

    function renderBars() {
        clear(signerBar);
        for (const s of state.signers) {
            signerBar.append(h('button', { type: 'button', role: 'radio', 'aria-checked': String(S.signer === s.id), class: 'schip pen pen-' + s.pen, onclick: () => { S.signer = s.id; renderBars(); } }, h('span', { class: 'ini ini-sm' }, initials(s.name)), h('span', { class: 'by' }, s.name)));
        }
        clear(toolbar);
        for (const t of Object.keys(FIELD_LABELS)) {
            toolbar.append(h('button', { type: 'button', class: 'btn btn-quiet btn-sm', 'aria-pressed': String(S.tool === t), onclick: () => { S.tool = t; renderBars(); } }, h('span', {}, FIELD_LABELS[t])));
        }
    }

    function renderFields() {
        for (const p of pages) clear(p.layer);
        for (const f of S.fields) {
            const p = pages.find((x) => x.num === f.page);
            if (!p) continue;
            const s = signerOf(f.signer_id);
            const el = h('div', { class: 'fld pen pen-' + (s?.pen || 1) + (S.selected === f.id ? ' is-selected' : ''), tabindex: 0, role: 'group', 'aria-label': FIELD_LABELS[f.type] + ' for ' + (s?.name || ''), style: { left: f.x * 100 + '%', top: f.y * 100 + '%', width: f.w * 100 + '%', height: f.h * 100 + '%' } },
                h('span', { class: 'fl' }, FIELD_LABELS[f.type] + ' · ' + (s?.name || '').split(' ')[0]));
            const del = h('button', { type: 'button', class: 'fdel', 'aria-label': 'Remove this field', onclick: (ev) => { ev.stopPropagation(); S.fields = S.fields.filter((x) => x !== f); S.selected = null; changed(); } }, icon('x', 'icon icon-sm'));
            const grip = h('span', { class: 'fgrip', 'aria-hidden': 'true' });
            el.append(del, grip);
            drag(el, f, p, grip);
            el.addEventListener('focus', () => { S.selected = f.id; });
            el.addEventListener('keydown', (ev) => key(ev, f));
            p.layer.append(el);
        }
        renderSummary();
    }

    function drag(el, f, p, grip) {
        const move = (ev, mode, start) => {
            const b = p.layer.getBoundingClientRect();
            const dx = (ev.clientX - start.x) / b.width; const dy = (ev.clientY - start.y) / b.height;
            if (mode === 'move') { f.x = clamp(start.fx + dx, 0, 1 - f.w); f.y = clamp(start.fy + dy, 0, 1 - f.h); } else { f.w = clamp(start.fw + dx, 0.02, 1 - f.x); f.h = clamp(start.fh + dy, 0.01, 1 - f.y); }
            Object.assign(el.style, { left: f.x * 100 + '%', top: f.y * 100 + '%', width: f.w * 100 + '%', height: f.h * 100 + '%' });
        };
        const down = (mode) => (ev) => {
            if (ev.button !== 0) return;
            ev.preventDefault(); ev.stopPropagation();
            const start = { x: ev.clientX, y: ev.clientY, fx: f.x, fy: f.y, fw: f.w, fh: f.h };
            S.selected = f.id; el.classList.add('is-selected');
            const target = ev.currentTarget; target.setPointerCapture(ev.pointerId);
            const mv = (e) => move(e, mode, start);
            const up = () => { target.removeEventListener('pointermove', mv); target.removeEventListener('pointerup', up); changed(true); };
            target.addEventListener('pointermove', mv); target.addEventListener('pointerup', up);
        };
        el.addEventListener('pointerdown', (ev) => { if (ev.target === el || ev.target.classList.contains('fl')) down('move')(ev); });
        grip.addEventListener('pointerdown', down('resize'));
    }

    function key(ev, f) {
        const step = ev.shiftKey ? 0.004 : 0.01;
        const m = { ArrowLeft: ['x', -step], ArrowRight: ['x', step], ArrowUp: ['y', -step], ArrowDown: ['y', step] }[ev.key];
        if (m) {
            ev.preventDefault();
            if (ev.altKey) { const k = m[0] === 'x' ? 'w' : 'h'; f[k] = clamp(f[k] + m[1], 0.01, 1 - f[m[0]]); } else { f[m[0]] = clamp(f[m[0]] + m[1], 0, 1 - f[m[0] === 'x' ? 'w' : 'h']); }
            changed(true);
        } else if (ev.key === 'Delete' || ev.key === 'Backspace') {
            ev.preventDefault(); S.fields = S.fields.filter((x) => x !== f); S.selected = null; changed();
        }
    }

    function renderSummary() {
        clear(summary);
        for (const s of state.signers) {
            const mine = S.fields.filter((f) => f.signer_id === s.id);
            const hasSig = mine.some((f) => f.type === 'signature');
            summary.append(h('li', { class: 'pen pen-' + s.pen },
                h('span', { class: 'ini ini-sm' }, initials(s.name)), h('div', {}, h('b', { class: 'by' }, s.name), h('div', { class: 'row-sub' }, mine.length + (mine.length === 1 ? ' field' : ' fields'))),
                h('span', { class: 'state ' + (hasSig ? 'is-ok' : 'is-warn') }, hasSig ? 'Has a signature field' : 'Needs a signature field')));
        }
    }

    let timer;
    function changed(quiet = false) {
        S.dirty = true;
        status.textContent = 'Saving…';
        renderFields();
        clearTimeout(timer);
        timer = setTimeout(save, quiet ? 500 : 300);
    }

    async function save() {
        if (S.saving) { timer = setTimeout(save, 300); return; }
        S.saving = true;
        try {
            await postJson(cfg.urls.fields, { fields: S.fields.map((f) => ({ signer_id: f.signer_id, type: f.type, page: f.page, x: f.x, y: f.y, w: f.w, h: f.h, required: f.required, label: f.label })) });
            S.dirty = false;
            status.textContent = 'All changes saved';
        } catch (e) {
            status.textContent = 'Not saved';
            toast(e.message, true);
        } finally { S.saving = false; }
    }

    /** Wait for any save in progress and save what is left; true when nothing is pending. */
    async function flush() {
        clearTimeout(timer);
        for (let i = 0; i < 60 && (S.saving || S.dirty); i++) {
            if (!S.saving) await save(); else await new Promise((r) => setTimeout(r, 100));
        }
        return !S.dirty;
    }

    async function send() {
        const err = document.getElementById('send-err');
        err.textContent = '';
        if (!(await flush())) { err.textContent = 'The fields could not be saved. Check your connection and try again.'; return; }
        const names = state.signers.map((s) => s.name).join(', ');
        if (!window.confirm('Send this for signature to ' + names + '? The document is locked while it is out for signing.')) return;
        try {
            const res = await postJson(cfg.urls.send, {});
            location.href = res.redirect || location.href;
        } catch (e) { err.textContent = e.message; }
    }

    async function cancel() {
        const reason = window.prompt('Why are you cancelling? (the signers will be told)');
        if (!reason) return;
        try { const res = await postJson(cfg.urls.cancel, { reason }); location.href = res.redirect; } catch (e) { toast(e.message, true); }
    }

    renderBars();
    renderFields();
    window.addEventListener('beforeunload', (ev) => { if (S.dirty) { ev.preventDefault(); ev.returnValue = ''; } });
}

const clamp = (v, a, b) => Math.min(Math.max(v, a), Math.max(a, b));
