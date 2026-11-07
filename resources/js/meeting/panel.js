// The side panel: People (with host actions and the waiting room), and the Agreement and Sign pages, loaded on
// first open. On a phone it is a full-height sheet over the stage (styles decide; this file only opens and closes).

import { h, icon, initials, QUALITY_WORD } from './util.js';

export class Panel {
    constructor(root, hooks) {
        this.el = root;
        this.hooks = hooks;
        this.tabs = [...root.querySelectorAll('[role="tab"]')];
        this.peopleList = root.querySelector('#people');
        this.waiters = root.querySelector('#waiters');
        this.countEl = root.querySelector('#people-n');
        this.signature = '';
        this.waiterSignature = '';
        this.openState = false;
        this.current = 'people';

        for (const tab of this.tabs) {
            tab.addEventListener('click', () => this.select(tab.id.replace('tab-', '')));
            tab.addEventListener('keydown', (event) => this.onTabKey(event, tab));
        }
        root.querySelector('#side-close')?.addEventListener('click', () => hooks.onClose());
        root.addEventListener('click', (event) => {
            const button = event.target.closest('[data-act]');
            if (!button) return;
            const id = Number(button.dataset.id);
            hooks.onAction(button.dataset.act, id, button.dataset.name || '');
        });
    }

    get isOpen() { return this.openState; }

    open(tab = this.current) {
        this.openState = true;
        this.el.hidden = false;
        this.select(tab, { focus: true });
    }

    close() {
        this.openState = false;
        this.el.hidden = true;
    }

    select(name, { focus = false } = {}) {
        if (!this.tabs.some((t) => t.id === `tab-${name}`)) name = 'people';
        this.current = name;
        for (const tab of this.tabs) {
            const on = tab.id === `tab-${name}`;
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            const panel = this.el.querySelector(`#${tab.getAttribute('aria-controls')}`);
            if (panel) panel.hidden = !on;
            if (on && panel) {
                const frame = panel.querySelector('iframe[data-src]');
                if (frame && !frame.getAttribute('src')) frame.setAttribute('src', frame.dataset.src);
            }
        }
        if (focus) this.el.querySelector(`#tab-${name}`)?.focus();
    }

    onTabKey(event, tab) {
        const index = this.tabs.indexOf(tab);
        let next = -1;
        if (event.key === 'ArrowRight') next = (index + 1) % this.tabs.length;
        else if (event.key === 'ArrowLeft') next = (index - 1 + this.tabs.length) % this.tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = this.tabs.length - 1;
        if (next < 0) return;
        event.preventDefault();
        this.select(this.tabs[next].id.replace('tab-', ''), { focus: true });
    }

    /** people: [{id, name, you, host, state, stateText, tone, mic, cam, sharing, quality, pen, canMute, canRemove}] */
    setPeople(people) {
        this.countEl.textContent = String(people.filter((p) => p.state !== 'lost' && p.state !== 'gone').length);
        const signature = JSON.stringify(people);
        if (signature === this.signature) return;
        this.signature = signature;
        const keep = this.activeAction();
        this.peopleList.replaceChildren(...people.map((p) => this.person(p)));
        this.restore(keep);
    }

    person(p) {
        const badges = [
            p.host ? h('span', { class: 'tag' }, 'Host') : null,
            p.you ? h('span', { class: 'muted' }, '(You)') : null,
        ];
        const meta = h('span', { class: 'person-meta' },
            h('span', { class: `state ${p.tone || ''}` }, p.stateText),
            p.sharing ? h('span', { class: 'tag' }, 'Sharing') : null,
            h('span', { class: 'person-flag', role: 'img', 'aria-label': p.mic === false ? 'Microphone off' : 'Microphone on', title: p.mic === false ? 'Microphone off' : 'Microphone on', html: icon(p.mic === false ? 'mic-off' : 'mic', 'sm') }),
            h('span', { class: 'person-flag', role: 'img', 'aria-label': p.cam === false ? 'Camera off' : 'Camera on', title: p.cam === false ? 'Camera off' : 'Camera on', html: icon(p.cam === false ? 'cam-off' : 'cam', 'sm') }),
            p.quality ? h('span', { class: 'person-net', 'data-q': p.quality, title: 'Their connection' }, h('span', { html: icon('signal', 'sm') }), QUALITY_WORD[p.quality]) : null);
        const actions = (p.canMute || p.canRemove) ? h('span', { class: 'person-actions' },
            p.canMute ? h('button', { type: 'button', class: 'btn btn-sm btn-quiet', 'data-act': 'mute', 'data-id': p.id, 'data-name': p.name, 'aria-label': `Mute ${p.name}` }, h('span', {}, 'Mute')) : null,
            p.canRemove ? h('button', { type: 'button', class: 'btn btn-sm btn-wax', 'data-act': 'remove', 'data-id': p.id, 'data-name': p.name, 'aria-label': `Remove ${p.name}` }, h('span', {}, 'Remove')) : null) : null;
        return h('li', { class: `person pen pen-${p.pen}`, 'data-id': p.id },
            h('span', { class: 'ini' }, initials(p.name)),
            h('span', { class: 'person-main' }, h('span', { class: 'person-name' }, p.name, ' ', badges), meta),
            actions);
    }

    /** waiters: [{id, name, pen}] ; host only. */
    setWaiters(waiters, isHost) {
        const signature = JSON.stringify([waiters, isHost]);
        if (signature === this.waiterSignature) return;
        this.waiterSignature = signature;
        const keep = this.activeAction();
        if (!waiters.length) {
            this.waiters.replaceChildren();
            this.waiters.hidden = true;
            return;
        }
        this.waiters.hidden = false;
        this.waiters.replaceChildren(
            h('h3', { class: 'waiters-h' }, isHost ? 'Waiting to join' : 'In the waiting room', ' ', h('span', { class: 'fig muted' }, String(waiters.length))),
            h('ul', { class: 'people-list' }, waiters.map((w) => h('li', { class: `person pen pen-${w.pen}`, 'data-id': w.id },
                h('span', { class: 'ini' }, initials(w.name)),
                h('span', { class: 'person-main' }, h('span', { class: 'person-name' }, w.name), h('span', { class: 'person-meta' }, h('span', { class: 'state is-warn' }, 'Waiting'))),
                isHost ? h('span', { class: 'person-actions' },
                    h('button', { type: 'button', class: 'btn btn-sm', 'data-act': 'admit', 'data-id': w.id, 'data-name': w.name, 'aria-label': `Admit ${w.name}` }, h('span', {}, 'Admit')),
                    h('button', { type: 'button', class: 'btn btn-sm btn-wax', 'data-act': 'deny', 'data-id': w.id, 'data-name': w.name, 'aria-label': `Deny ${w.name}` }, h('span', {}, 'Deny'))) : null))));
        this.restore(keep);
    }

    activeAction() {
        const a = document.activeElement;
        if (a && this.el.contains(a) && a.dataset && a.dataset.act) return { act: a.dataset.act, id: a.dataset.id };
        return null;
    }

    restore(keep) {
        if (!keep) return;
        const target = this.el.querySelector(`[data-act="${keep.act}"][data-id="${keep.id}"]`);
        if (target) target.focus();
    }
}
