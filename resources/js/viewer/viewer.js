import * as pdfjsLib from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { h, icon, clear, when, initials, toast } from './dom.js';
import { getJson, postJson } from './api.js';

pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

const root = document.getElementById('doc-root');
if (root) {
    boot(root).catch((e) => {
        console.error(e);
        root.replaceChildren(h('p', { class: 'empty' }, h('b', {}, 'The agreement could not be displayed.'), 'Your browser may be too old to show it here. ', h('a', { href: JSON.parse(root.dataset.config).downloadUrl }, 'Download the PDF'), ' to read it.'));
    });
}

async function boot(root) {
    const cfg = JSON.parse(root.dataset.config);
    const S = { cfg, state: null, pdf: null, pages: [], drawing: false, composer: null, active: null };
    window.__engageViewer = S;

    const stateUrl = () => cfg.stateUrl + (cfg.version ? '?v=' + encodeURIComponent(cfg.version) : '');
    const urlFor = (tpl, id) => tpl.replace('__ID__', id);

    const bar = h('div', { class: 'docstrip', id: 'agreement' });
    const tools = h('div', { class: 'doctools' });
    const unanchored = h('div', { class: 'carried' });
    const pagesEl = h('div', { class: 'pages', 'aria-label': 'Pages of the agreement' });
    const amendEl = h('section', { class: 'amend-list', id: 'changes', 'aria-labelledby': 'changes-h' });
    root.replaceChildren(bar, tools, unanchored, pagesEl, amendEl);

    S.state = await getJson(stateUrl());
    if (!S.state.contract.is_pdf) {
        bar.append(h('p', { class: 'empty' }, h('b', {}, 'This file cannot be shown or signed here.'), 'Only PDF documents can be reviewed and signed in Dot.Engage. ', h('a', { href: S.state.file_url + '&download=1' }, 'Download it'), ', export it as a PDF and upload the PDF as a new version.'));
        return;
    }

    S.pdf = await pdfjsLib.getDocument({ url: S.state.file_url, standardFontDataUrl: cfg.standardFonts, cMapUrl: cfg.cmaps, cMapPacked: true, isEvalSupported: false, enableScripting: false }).promise;

    async function refresh() {
        S.state = await getJson(stateUrl());
        renderAgreement();
        renderMargins();
        renderAmendments();
    }

    // ── toolbar ────────────────────────────────────────────────────────────
    function renderTools() {
        clear(tools);
        const s = S.state;
        const draw = h('button', { type: 'button', class: 'btn btn-quiet btn-sm', 'aria-pressed': 'false', disabled: !s.me.can.comment || !s.viewing.is_current }, icon('comment'), h('span', {}, 'Add a note or change'));
        draw.addEventListener('click', () => {
            S.drawing = !S.drawing;
            draw.setAttribute('aria-pressed', String(S.drawing));
            pagesEl.classList.toggle('drawing', S.drawing);
            if (S.drawing) toast('Drag a box around the clause on the page.');
        });
        tools.append(draw, h('span', { class: 'muted fig' }, s.contract.pages ? s.contract.pages + ' pages' : ''));
        if (!s.viewing.is_current) {
            tools.append(h('span', { class: 'tag' }, 'You are looking at version ' + s.viewing.number + ', not the working version'));
        }
        if (s.contract.locked) {
            tools.append(h('span', { class: 'tag is-ok' }, 'Out for signature: this document cannot change'));
        }
    }

    // ── agreement strip ────────────────────────────────────────────────────
    function renderAgreement() {
        const s = S.state;
        const a = s.agreement;
        clear(bar);
        if (!s.viewing.is_current) { bar.hidden = true; return; }
        bar.hidden = false;
        const cur = s.versions[s.versions.length - 1];
        const line = h('div', { class: 'line' });
        line.append(h('b', {}, 'Version ' + cur.number), ' ', h('span', { class: 'fig muted', title: 'SHA-256 of this version' }, (cur.sha256 || '').slice(0, 12)));
        const status = h('span', { class: 'state ' + (a.agreed ? 'is-ok' : (a.open_items ? 'is-warn' : '')) },
            a.agreed ? 'Terms agreed' + (a.agreed_at ? ' ' + when(a.agreed_at) : '')
                : (a.open_items ? a.open_items + ' open ' + (a.open_items === 1 ? 'item' : 'items') : (s.approvals.length ? s.approvals.filter((x) => x.status === 'approved').length + ' of ' + s.approvals.length + ' approved' : 'Not yet agreed')));
        const actions = h('div', { class: 'page-actions' });

        const mine = s.approvals.find((x) => x.mine && x.status === 'pending');
        if (mine) {
            actions.append(
                button('Approve this version', 'btn btn-sm', () => decide(mine.id, 'approved')),
                button('I do not approve', 'btn btn-wax btn-sm', () => decide(mine.id, 'rejected', true)),
            );
        }
        if (a.can_request) actions.append(button('Ask approvers to approve', 'btn btn-sm', () => act(cfg.urls.requestApproval, {}, 'Approval requested.')));
        if (a.can_record) actions.append(button('Record that terms are agreed', 'btn btn-sm', () => act(cfg.urls.agree, {}, 'Agreement recorded.')));
        if (a.can_revise) actions.append(button('Create the revised version', 'btn btn-sm', () => act(cfg.urls.revise, {}, 'Revised version created.', true)));

        bar.append(line, status, actions);
        if (s.approvals.length) {
            bar.append(h('ul', { class: 'approvals' }, s.approvals.map((x) => h('li', { class: 'pen pen-' + x.pen },
                h('span', { class: 'ini ini-sm' }, initials(x.name)), h('span', { class: 'by' }, x.name),
                h('span', { class: 'state ' + (x.status === 'approved' ? 'is-ok' : x.status === 'rejected' ? 'is-wax' : 'is-warn') }, x.status === 'approved' ? 'Approved' : x.status === 'rejected' ? 'Did not approve' : 'Waiting'),
                x.note ? h('span', { class: 'muted' }, '“' + x.note + '”') : null))));
        }
    }

    async function decide(id, decision, askNote = false) {
        let note = null;
        if (askNote) {
            note = window.prompt('Say briefly why, so the others can fix it:');
            if (note === null) return;
        }
        await act(urlFor(cfg.urls.decide, id), { decision, note }, decision === 'approved' ? 'Approved.' : 'Recorded.');
    }

    function button(label, cls, fn) {
        const b = h('button', { type: 'button', class: cls }, h('span', {}, label));
        b.addEventListener('click', async () => {
            b.setAttribute('data-busy', 'true');
            try { await fn(); } finally { b.removeAttribute('data-busy'); }
        });
        return b;
    }

    async function act(url, body, okMessage, reload = false) {
        try {
            await postJson(url, body);
            toast(okMessage);
            if (reload) { location.reload(); return; }
            await refresh();
        } catch (e) {
            toast(e.message, true);
        }
    }

    // ── pages ──────────────────────────────────────────────────────────────
    async function buildPages() {
        clear(pagesEl);
        S.pages = [];
        const first = await S.pdf.getPage(1);
        const base = first.getViewport({ scale: 1 });
        const avail = Math.min(pagesEl.clientWidth || 760, 900);
        const colW = window.matchMedia('(min-width: 1061px)') && pagesEl.clientWidth > 1000 ? pagesEl.clientWidth - cfg.marginWidth - 28 : avail;
        for (let n = 1; n <= S.pdf.numPages; n++) {
            const page = n === 1 ? first : await S.pdf.getPage(n);
            const vp1 = page.getViewport({ scale: 1 });
            const scale = Math.min(colW, 940) / vp1.width;
            const vp = page.getViewport({ scale });
            const canvas = h('canvas', { width: Math.floor(vp.width), height: Math.floor(vp.height), role: 'img', 'aria-label': 'Page ' + n + ' of the agreement' });
            const layer = h('div', { class: 'layer' });
            const wrap = h('div', { class: 'sheetpage', style: { width: vp.width + 'px', height: vp.height + 'px' } }, h('span', { class: 'pg' }, 'Page ' + n + ' of ' + S.pdf.numPages), canvas, layer);
            const margin = h('div', { class: 'margin' }, h('div', { class: 'notes' }));
            const pageNote = h('button', { type: 'button', class: 'linkbtn pagebtn', onclick: () => openComposer(entry, null) }, '+ Note on page ' + n);
            const row = h('div', { class: 'pagewrap' }, h('div', {}, wrap, S.state.me.can.comment && S.state.viewing.is_current ? pageNote : null), margin);
            pagesEl.append(row);
            const entry = { num: n, page, vp, canvas, layer, wrap, margin, notes: margin.firstChild, rendered: false, scale };
            S.pages.push(entry);
            attachDraw(entry);
        }
        lazyRender();
    }

    function lazyRender() {
        const io = new IntersectionObserver((items) => {
            for (const it of items) {
                if (!it.isIntersecting) continue;
                const entry = S.pages.find((p) => p.wrap === it.target.querySelector('.sheetpage') || p.wrap.parentNode.parentNode === it.target);
                if (entry) draw(entry);
            }
        }, { rootMargin: '700px 0px' });
        pagesEl.querySelectorAll('.pagewrap').forEach((r) => io.observe(r));
    }

    async function draw(entry) {
        if (entry.rendered) return;
        entry.rendered = true;
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        const vp = entry.page.getViewport({ scale: entry.scale * ratio });
        entry.canvas.width = Math.floor(vp.width);
        entry.canvas.height = Math.floor(vp.height);
        entry.canvas.style.width = entry.vp.width + 'px';
        entry.canvas.style.height = entry.vp.height + 'px';
        await entry.page.render({ canvasContext: entry.canvas.getContext('2d'), viewport: vp }).promise;
    }

    // ── drawing a box to anchor a note ─────────────────────────────────────
    function attachDraw(entry) {
        let start = null; let rectEl = null;
        entry.layer.addEventListener('pointerdown', (ev) => {
            if (!S.drawing || ev.button !== 0 || ev.target.closest('.hl')) return;
            const b = entry.layer.getBoundingClientRect();
            start = { x: ev.clientX - b.left, y: ev.clientY - b.top, b };
            rectEl = h('div', { class: 'draw-rect' });
            entry.layer.append(rectEl);
            entry.layer.setPointerCapture(ev.pointerId);
            ev.preventDefault();
        });
        entry.layer.addEventListener('pointermove', (ev) => {
            if (!start) return;
            const x = Math.max(0, Math.min(ev.clientX - start.b.left, start.b.width));
            const y = Math.max(0, Math.min(ev.clientY - start.b.top, start.b.height));
            Object.assign(rectEl.style, { left: Math.min(x, start.x) + 'px', top: Math.min(y, start.y) + 'px', width: Math.abs(x - start.x) + 'px', height: Math.abs(y - start.y) + 'px' });
        });
        const finish = async (ev) => {
            if (!start) return;
            const x = Math.max(0, Math.min(ev.clientX - start.b.left, start.b.width));
            const y = Math.max(0, Math.min(ev.clientY - start.b.top, start.b.height));
            const r = { x: Math.min(x, start.x) / start.b.width, y: Math.min(y, start.y) / start.b.height, w: Math.abs(x - start.x) / start.b.width, h: Math.abs(y - start.y) / start.b.height };
            const keep = rectEl; start = null; rectEl = null;
            if (r.w < 0.01 || r.h < 0.008) { keep.remove(); return; }
            const quote = await extractText(entry, r);
            keep.classList.add('is-kept');
            openComposer(entry, r, quote, keep);
        };
        entry.layer.addEventListener('pointerup', finish);
        entry.layer.addEventListener('pointercancel', () => { rectEl?.remove(); start = null; rectEl = null; });
    }

    async function extractText(entry, r) {
        try {
            const content = await entry.page.getTextContent();
            const vp = entry.page.getViewport({ scale: 1 });
            const box = { x0: r.x * vp.width, y0: r.y * vp.height, x1: (r.x + r.w) * vp.width, y1: (r.y + r.h) * vp.height };
            const hits = [];
            for (const it of content.items) {
                if (!it.str || !it.str.trim()) continue;
                const [x, y] = vp.convertToViewportPoint(it.transform[4], it.transform[5]);
                const w = (it.width || 0), hgt = (it.height || 10);
                const cx = x + w / 2; const cy = y - hgt / 2;
                if (cx >= box.x0 && cx <= box.x1 && cy >= box.y0 && cy <= box.y1) hits.push({ s: it.str, x, y });
            }
            hits.sort((a, b) => (Math.abs(a.y - b.y) < 3 ? a.x - b.x : a.y - b.y));
            let out = ''; let lastY = null;
            for (const it of hits) {
                if (lastY !== null && Math.abs(it.y - lastY) >= 3) out += ' ';
                out += it.s + (it.s.endsWith(' ') ? '' : ' ');
                lastY = it.y;
            }
            return out.replace(/\s+/g, ' ').trim().slice(0, 1200);
        } catch (e) {
            return '';
        }
    }

    // ── composer ───────────────────────────────────────────────────────────
    function openComposer(entry, rect, quote = '', rectEl = null) {
        closeComposer();
        const can = S.state.me.can;
        let mode = 'comment';
        const form = h('form', { class: 'note composer is-open' });
        const modes = h('div', { class: 'seg', role: 'radiogroup', 'aria-label': 'What do you want to add' });
        const body = h('textarea', { class: 'textarea', required: true, maxlength: 4000, rows: 3, placeholder: 'Write your note', 'aria-label': 'Your note' });
        const clause = h('input', { class: 'input', maxlength: 120, placeholder: 'Clause number, e.g. 4.2', 'aria-label': 'Clause number' });
        const original = h('textarea', { class: 'textarea clause', maxlength: 4000, rows: 3, 'aria-label': 'Current wording' }, quote || '');
        const proposed = h('textarea', { class: 'textarea clause', maxlength: 4000, rows: 3, 'aria-label': 'Proposed wording' });
        const why = h('textarea', { class: 'textarea', maxlength: 2000, rows: 2, placeholder: 'Why? (optional)', 'aria-label': 'Reason for the change' });
        const kindSel = h('select', { class: 'select', 'aria-label': 'Kind of change' }, h('option', { value: 'replace' }, 'Replace this wording'), h('option', { value: 'add' }, 'Add a new clause'), h('option', { value: 'remove' }, 'Remove this wording'));
        const changeBox = h('div', { class: 'change', hidden: true }, kindSel, clause, h('label', { class: 'label' }, 'Current wording'), original, h('label', { class: 'label' }, 'Proposed wording'), proposed, why);
        const err = h('p', { class: 'err', role: 'alert' });
        const submit = h('button', { type: 'submit', class: 'btn btn-sm' }, h('span', {}, 'Add'));
        const cancel = h('button', { type: 'button', class: 'btn btn-quiet btn-sm', onclick: closeComposer }, h('span', {}, 'Cancel'));

        const setMode = (m) => {
            mode = m;
            modes.querySelectorAll('button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.m === m)));
            changeBox.hidden = m !== 'change';
            body.hidden = m === 'change';
            body.required = m !== 'change';
            body.placeholder = m === 'question' ? 'Ask your question' : 'Write your note';
        };
        const opt = (m, label) => h('button', { type: 'button', role: 'radio', 'aria-checked': 'false', dataset: { m }, onclick: () => setMode(m) }, label);
        modes.append(opt('comment', 'Note'), opt('question', 'Question'));
        if (can.propose) modes.append(opt('change', 'Propose a change'));
        if (quote) form.append(h('div', { class: 'quote' }, quote));
        form.append(modes, body, changeBox, err, h('div', { class: 'note-foot' }, submit, cancel));
        setMode('comment');

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            err.textContent = '';
            submit.setAttribute('data-busy', 'true');
            try {
                if (mode === 'change') {
                    await postJson(cfg.urls.propose, { type: kindSel.value, clause_ref: clause.value || null, page: entry.num, original_text: kindSel.value === 'add' ? null : original.value, proposed_text: kindSel.value === 'remove' ? null : proposed.value, explanation: why.value || null });
                } else {
                    await postJson(cfg.urls.comment, { body: body.value, kind: mode, page: entry.num, quote: quote || null, ...(rect || {}) });
                }
                closeComposer();
                toast(mode === 'change' ? 'Change proposed. It does not alter the document until it is accepted and a new version is made.' : 'Added.');
                await refresh();
            } catch (e) {
                err.textContent = e.message;
            } finally {
                submit.removeAttribute('data-busy');
            }
        });

        S.composer = { form, rectEl, entry };
        form.style.top = (rect ? rect.y * entry.vp.height : 0) + 'px';
        entry.notes.append(form);
        body.focus();
        S.drawing = false;
        pagesEl.classList.remove('drawing');
        tools.querySelector('[aria-pressed]')?.setAttribute('aria-pressed', 'false');
    }

    function closeComposer() {
        if (!S.composer) return;
        S.composer.form.remove();
        S.composer.rectEl?.remove();
        S.composer = null;
    }

    // ── margins ────────────────────────────────────────────────────────────
    function renderMargins() {
        const s = S.state;
        for (const p of S.pages) {
            clear(p.layer);
            for (const el of [...p.notes.children]) if (el !== S.composer?.form) el.remove();
        }
        clear(unanchored);

        const viewingId = s.viewing.id;
        const carried = [];
        let n = 0;
        for (const t of s.threads) {
            const entry = S.pages.find((p) => p.num === t.page);
            const here = t.version_id === viewingId;
            n++;
            if (!entry || !here) {
                if (t.status !== 'resolved' || here) carried.push(t);
                if (!entry) continue;
                if (!here) continue;
            }
            const note = noteEl(t, n);
            entry.notes.append(note);
            if (t.rect) {
                const hl = h('button', { type: 'button', class: 'hl pen pen-' + t.pen, dataset: { for: 'note-' + t.id }, 'aria-label': 'Show note ' + n, style: { left: t.rect.x * 100 + '%', top: t.rect.y * 100 + '%', width: t.rect.w * 100 + '%', height: t.rect.h * 100 + '%' } }, h('span', { class: 'mk' }, n));
                hl.addEventListener('click', () => focusNote(t.id));
                entry.layer.append(hl);
            }
        }
        if (carried.filter((t) => t.version_id !== viewingId && t.status !== 'resolved').length) {
            unanchored.append(h('h3', {}, 'Still open from earlier versions'));
            for (const t of carried.filter((x) => x.version_id !== viewingId && x.status !== 'resolved')) {
                unanchored.append(h('div', { class: 'carried-note' }, noteEl(t, '', true)));
            }
        }
        layoutNotes();
    }

    function noteEl(t, number, flat = false) {
        const s = S.state;
        const el = h('article', { class: 'note pen pen-' + t.pen + (t.status === 'resolved' ? ' is-resolved' : ''), id: 'note-' + t.id, tabindex: '-1' });
        el.dataset.top = t.rect ? String(t.rect.y) : '0';
        if (flat) el.style.position = 'static';
        el.append(h('div', { class: 'note-head' },
            number !== '' ? h('span', { class: 'mk-n fig' }, '#' + number) : null,
            h('span', { class: 'ini ini-sm' }, initials(t.author)), h('span', { class: 'by' }, t.author),
            t.kind === 'question' ? h('span', { class: 'tag' }, 'Question') : null,
            t.version_number && t.version_number !== s.viewing.number ? h('span', { class: 'tag' }, 'v' + t.version_number) : null,
            h('time', { class: 'when' }, when(t.at))));
        if (t.quote) el.append(h('div', { class: 'quote' }, t.quote));
        el.append(h('div', { class: 'body' }, t.body));
        if (t.replies.length) {
            el.append(h('div', { class: 'replies' }, t.replies.map((r) => h('div', { class: 'reply pen pen-' + r.pen }, h('div', { class: 'note-head' }, h('span', { class: 'by' }, r.author), h('time', { class: 'when' }, when(r.at))), h('div', { class: 'body' }, r.body)))));
        }
        const foot = h('div', { class: 'note-foot' });
        if (s.me.can.comment) {
            foot.append(h('button', { type: 'button', class: 'linkbtn', onclick: () => replyBox(el, t) }, 'Reply'));
            if (t.status === 'open') {
                foot.append(h('button', { type: 'button', class: 'linkbtn', onclick: () => act(urlFor(cfg.urls.commentStatus, t.id), { status: 'discussed' }, 'Marked as discussed.') }, 'Mark discussed'));
                foot.append(h('button', { type: 'button', class: 'linkbtn', onclick: () => act(urlFor(cfg.urls.commentStatus, t.id), { status: 'resolved' }, 'Resolved.') }, 'Resolve'));
            } else if (t.status === 'discussed') {
                foot.append(h('span', { class: 'tag is-ok' }, 'Discussed'));
                foot.append(h('button', { type: 'button', class: 'linkbtn', onclick: () => act(urlFor(cfg.urls.commentStatus, t.id), { status: 'resolved' }, 'Resolved.') }, 'Resolve'));
            } else {
                foot.append(h('button', { type: 'button', class: 'linkbtn', onclick: () => act(urlFor(cfg.urls.commentStatus, t.id), { status: 'open' }, 'Reopened.') }, 'Reopen'));
            }
        }
        el.append(foot);
        return el;
    }

    function replyBox(noteEl, t) {
        if (noteEl.querySelector('form.reply-form')) return;
        const ta = h('textarea', { class: 'textarea', rows: 2, required: true, maxlength: 4000, 'aria-label': 'Your reply' });
        const f = h('form', { class: 'reply-form' }, ta, h('div', { class: 'note-foot' }, h('button', { class: 'btn btn-sm', type: 'submit' }, h('span', {}, 'Reply')), h('button', { class: 'btn btn-quiet btn-sm', type: 'button', onclick: () => f.remove() }, h('span', {}, 'Cancel'))));
        f.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            await act(urlFor(S.cfg.urls.reply, t.id), { body: ta.value }, 'Reply added.');
        });
        noteEl.append(f);
        ta.focus();
        layoutNotes();
    }

    function focusNote(id) {
        document.querySelectorAll('.note.is-active, .hl.is-active').forEach((e) => e.classList.remove('is-active'));
        const el = document.getElementById('note-' + id);
        if (!el) return;
        el.classList.add('is-active');
        document.querySelector('.hl[data-for="note-' + id + '"]')?.classList.add('is-active');
        el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        el.focus({ preventScroll: true });
    }

    function layoutNotes() {
        requestAnimationFrame(() => {
            const wide = window.matchMedia('(min-width: 1061px)').matches;
            for (const p of S.pages) {
                const items = [...p.notes.children];
                if (!wide) { items.forEach((i) => { i.style.top = ''; }); p.notes.style.height = ''; continue; }
                items.sort((a, b) => parseFloat(a.dataset.top || '0') - parseFloat(b.dataset.top || '0'));
                let bottom = 0;
                for (const it of items) {
                    const desired = it.classList.contains('composer') ? parseFloat(it.style.top) || 0 : parseFloat(it.dataset.top || '0') * p.vp.height;
                    const top = Math.max(desired, bottom);
                    it.style.top = top + 'px';
                    bottom = top + it.offsetHeight + 10;
                }
                p.notes.style.height = Math.max(bottom, 0) + 'px';
            }
        });
    }

    // ── proposed changes ───────────────────────────────────────────────────
    function renderAmendments() {
        const list = S.state.amendments;
        clear(amendEl);
        amendEl.append(h('div', { class: 'section-head' }, h('h2', { id: 'changes-h' }, 'Proposed changes ', h('span', { class: 'n' }, String(list.length)))));
        if (!list.length) {
            amendEl.append(h('p', { class: 'empty' }, 'Nobody has proposed a change. Wording changes are proposed here, reviewed by someone else, and only reach the document in a new version.'));
            return;
        }
        for (const a of list) {
            const statusTone = { proposed: 'is-warn', accepted: 'is-ok', rejected: 'is-wax', withdrawn: '' }[a.status] || '';
            const card = h('article', { class: 'amend pen pen-' + a.pen, id: 'amend-' + a.id },
                h('div', { class: 'amend-head' }, h('span', { class: 'ini ini-sm' }, initials(a.author)), h('span', { class: 'by' }, a.author), h('span', {}, a.type_label + (a.clause_ref ? ' · clause ' + a.clause_ref : '') + (a.page ? ' · page ' + a.page : '')),
                    h('span', { class: 'state ' + statusTone, style: { marginLeft: 'auto' } }, ({ proposed: 'Waiting for review', accepted: a.applied_in ? 'Accepted, in v' + a.applied_in : 'Accepted, not yet in a version', rejected: 'Rejected', withdrawn: 'Withdrawn' })[a.status])),
                h('div', { class: 'amend-body' },
                    a.original_text ? h('div', { class: 'was clause' }, a.original_text) : null,
                    a.proposed_text ? h('div', { class: 'now clause' }, a.proposed_text) : null,
                    a.explanation ? h('p', { class: 'muted' }, a.explanation) : null,
                    a.review_note ? h('p', { class: 'muted' }, 'Reviewer: ' + a.review_note) : null));
            const foot = h('div', { class: 'amend-foot' });
            if (a.can_review) {
                foot.append(button('Accept', 'btn btn-sm', () => act(urlFor(cfg.urls.reviewAmendment, a.id), { decision: 'accepted' }, 'Accepted.')),
                    button('Reject', 'btn btn-wax btn-sm', async () => {
                        const note = window.prompt('Why not? (optional, the author will see it)');
                        if (note === null) return;
                        await act(urlFor(cfg.urls.reviewAmendment, a.id), { decision: 'rejected', note: note || null }, 'Rejected.');
                    }));
            }
            if (a.can_withdraw) foot.append(button('Withdraw', 'btn btn-quiet btn-sm', () => act(urlFor(cfg.urls.withdrawAmendment, a.id), {}, 'Withdrawn.')));
            if (foot.children.length) card.append(foot);
            amendEl.append(card);
        }
    }

    // ── go ─────────────────────────────────────────────────────────────────
    renderTools();
    renderAgreement();
    await buildPages();
    renderMargins();
    renderAmendments();

    if (location.hash.startsWith('#note-')) focusNote(location.hash.slice(6));

    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(async () => { await buildPages(); renderMargins(); }, 250);
    });
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') { closeComposer(); S.drawing = false; pagesEl.classList.remove('drawing'); } });

    // Other people's notes show up without a reload.
    setInterval(async () => {
        if (document.hidden || S.composer || document.activeElement?.closest?.('form.reply-form')) return;
        try {
            const next = await getJson(stateUrl());
            if (JSON.stringify([next.threads, next.amendments, next.approvals, next.agreement]) !== JSON.stringify([S.state.threads, S.state.amendments, S.state.approvals, S.state.agreement])) {
                S.state = next;
                renderAgreement();
                renderMargins();
                renderAmendments();
            }
        } catch (e) { /* the next tick will try again */ }
    }, 20000);
}
