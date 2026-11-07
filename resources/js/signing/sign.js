import { h, clear, toast, initials, icon } from '../viewer/dom.js';
import { getJson, postJson } from '../viewer/api.js';
import { loadPages, FIELD_LABELS } from './pages.js';
import { captureSignature, initialsImage } from './capture.js';

const root = document.getElementById('sign-root');
if (root) boot(JSON.parse(root.dataset.config)).catch((e) => {
    console.error(e);
    root.replaceChildren(h('p', { class: 'empty' }, h('b', {}, 'The signing page could not start.'), 'Reload the page. If it keeps failing, ask the sender to send the request again.'));
});

async function boot(cfg) {
    const state = await getJson(cfg.urls.state);
    const keyName = 'engage.signkey.' + state.envelope.id;
    let key = null;
    try { key = sessionStorage.getItem(keyName); } catch (e) { /* storage blocked */ }
    if (!key) { key = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2)).replace(/-/g, '').slice(0, 40); try { sessionStorage.setItem(keyName, key); } catch (e) { /* ignore */ } }

    const S = { sig: null, ini: null, values: {}, done: false };
    const today = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date());
    const mine = state.fields.filter((f) => f.mine);
    const pagesEl = h('div', { class: 'pages sign-pages' });
    const bar = h('div', { class: 'signbar' });
    const progress = h('div', { class: 'sb-progress', role: 'status', 'aria-live': 'polite' });
    root.replaceChildren(pagesEl);
    document.querySelector('[data-sign-bar]')?.append(bar);

    const { pages } = await loadPages(pagesEl, state.file_url, { maxWidth: 860 });

    function renderFields() {
        for (const p of pages) clear(p.layer);
        for (const f of state.fields) {
            const p = pages.find((x) => x.num === f.page);
            if (!p) continue;
            const signer = state.signers.find((s) => s.id === f.signer_id);
            const style = { left: f.x * 100 + '%', top: f.y * 100 + '%', width: f.w * 100 + '%', height: f.h * 100 + '%' };
            if (!f.mine) {
                p.layer.append(h('div', { class: 'sfld other pen pen-' + (signer?.pen || 1), style, title: 'For ' + (signer?.name || '') }, h('span', { class: 'fl' }, (signer?.name || '').split(' ')[0])));
                continue;
            }
            p.layer.append(fieldEl(f, style, signer));
        }
        renderBar();
    }

    function filled(f) {
        if (f.type === 'signature') return !!S.sig;
        if (f.type === 'initials') return !!S.ini;
        if (f.type === 'date') return true;
        if (f.type === 'text') return (S.values[f.id] || '').trim() !== '';
        if (f.type === 'checkbox') return !!S.values[f.id];
        return false;
    }

    function fieldEl(f, style, signer) {
        const isDone = filled(f);
        const base = { class: 'sfld mine pen pen-' + (signer?.pen || 1) + (isDone ? ' is-done' : ' is-todo'), style, dataset: { field: f.id } };
        if (f.type === 'signature' || f.type === 'initials') {
            const img = f.type === 'signature' ? S.sig?.dataUri : S.ini;
            const b = h('button', { type: 'button', ...base, 'aria-label': isDone ? 'Change your ' + FIELD_LABELS[f.type].toLowerCase() : 'Add your ' + FIELD_LABELS[f.type].toLowerCase(), onclick: () => act(f) },
                img ? h('img', { src: img, alt: 'Your ' + FIELD_LABELS[f.type].toLowerCase() }) : h('span', { class: 'fl' }, f.type === 'signature' ? 'Sign here' : 'Initial here'));
            return b;
        }
        if (f.type === 'date') return h('div', { ...base, class: base.class + ' is-done' }, h('span', { class: 'fv' }, today));
        if (f.type === 'checkbox') {
            return h('button', { type: 'button', ...base, role: 'checkbox', 'aria-checked': String(!!S.values[f.id]), 'aria-label': f.label || 'Tick to confirm', onclick: () => { S.values[f.id] = !S.values[f.id]; renderFields(); } }, S.values[f.id] ? h('span', { class: 'fv' }, '✕') : null);
        }
        const input = h('input', { class: 'sfld-input', value: S.values[f.id] || '', maxlength: 200, placeholder: f.label || 'Type here', 'aria-label': f.label || 'Text', oninput: () => { S.values[f.id] = input.value; renderBar(); }, onchange: () => renderFields() });
        return h('div', { ...base }, input);
    }

    async function act(f) {
        const needSig = f.type === 'signature' || !S.sig;
        if (f.type === 'signature' || (f.type === 'initials' && S.ini)) {
            const res = await captureSignature({ name: cfg.name, title: 'Your signature' });
            if (!res) return;
            S.sig = res;
            // Initials follow the name unless they were already set.
            if (!S.ini && state.fields.some((x) => x.mine && x.type === 'initials')) S.ini = await initialsImage(initialsOf(res.typedName || cfg.name));
        } else if (f.type === 'initials') {
            S.ini = await initialsImage(initialsOf(cfg.name));
            if (!S.sig) toast('Initials added. Now add your signature.');
        }
        renderFields();
    }

    const initialsOf = (n) => (n || '').trim().split(/\s+/).map((w) => w[0]?.toUpperCase() || '').join('').slice(0, 3);

    function remaining() { return mine.filter((f) => f.required && !filled(f)); }

    function renderBar() {
        const left = remaining();
        const total = mine.filter((f) => f.required).length;
        clear(bar);
        const consent = h('input', { type: 'checkbox', id: 'consent', checked: S.consent, onchange: () => { S.consent = consent.checked; renderBar(); } });
        const sign = h('button', { type: 'button', class: 'btn', disabled: left.length > 0 || !S.consent || S.done || !cfg.turn }, h('span', {}, S.done ? 'Signed' : 'Sign and finish'));
        sign.addEventListener('click', () => submit(sign));
        const next = h('button', { type: 'button', class: 'btn btn-quiet', disabled: left.length === 0, onclick: () => { const el = document.querySelector('[data-field="' + left[0].id + '"]'); el?.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); el?.focus?.(); } }, h('span', {}, 'Next field'));
        const decline = h('button', { type: 'button', class: 'linkbtn', onclick: declineFlow }, 'I will not sign this');
        bar.append(
            h('div', { class: 'sb-left' },
                h('div', { class: 'sb-progress fig' }, cfg.turn ? (left.length === 0 ? 'All fields complete' : (total - left.length) + ' of ' + total + ' fields complete') : 'Waiting for the people before you'),
                h('label', { class: 'check', for: 'consent' }, consent, h('span', {}, cfg.consent))),
            h('div', { class: 'sb-actions' }, decline, next, sign));
    }

    async function submit(btn) {
        btn.setAttribute('data-busy', 'true');
        try {
            const body = { signature: S.sig.dataUri, initials: S.ini || null, method: S.sig.method, typed_name: S.sig.typedName, consent: true, key, fields: S.values };
            const res = await postJson(cfg.urls.submit, body);
            S.done = true;
            try { sessionStorage.removeItem(keyName); } catch (e) { /* ignore */ }
            showDone(res);
        } catch (e) {
            toast(e.message, true);
            btn.removeAttribute('data-busy');
        }
    }

    async function declineFlow() {
        const reason = window.prompt('Tell the others why you are not signing. This is shared with them and returns the agreement to discussion.');
        if (!reason || reason.trim().length < 3) return;
        try { const res = await postJson(cfg.urls.decline, { reason }); location.href = res.redirect; } catch (e) { toast(e.message, true); }
    }

    function showDone(res) {
        clear(bar);
        const panel = document.getElementById('sign-done');
        if (panel) {
            panel.hidden = false;
            panel.querySelector('[data-done-text]').textContent = res.all_signed
                ? 'Every signature is in. The signed agreement and its evidence record are being prepared and will be emailed to everyone.'
                : 'Your signature is recorded. You will be emailed when everyone has signed.';
            panel.scrollIntoView({ block: 'start' });
            panel.querySelector('a, button')?.focus();
        }
        root.classList.add('is-signed');
        root.querySelectorAll('.sfld.mine').forEach((el) => el.setAttribute('aria-disabled', 'true'));
    }

    renderFields();
}
