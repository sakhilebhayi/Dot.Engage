// The strip above the call: "Sam is sharing their screen", people waiting at the door, notices that fade.

import { h } from './util.js';

export class Banner {
    constructor(element) {
        this.el = element;
        this.items = new Map();
    }

    /** set('share', { text, tone: 'info'|'warn'|'wax', actions: [{label, run}], ttl: ms }) */
    set(key, { text, tone = 'info', actions = [], ttl = 0 }) {
        const signature = JSON.stringify([text, tone, actions.map((a) => a.label)]);
        let item = this.items.get(key);
        if (item && item.signature === signature) {
            item.actions = actions;
            this.arm(key, item, ttl);
            return;
        }
        const node = h('div', { class: 'banner-item', 'data-tone': tone, 'data-key': key },
            h('span', { class: 'banner-text' }, text),
            actions.length ? h('span', { class: 'banner-actions' }, actions.map((action, i) => h('button', {
                type: 'button', class: 'btn btn-sm btn-quiet', 'data-i': i,
                onclick: () => { const current = this.items.get(key); (current ? current.actions : actions)[i]?.run(); },
            }, h('span', {}, action.label)))) : null);
        if (item) { item.node.replaceWith(node); clearTimeout(item.timer); }
        else this.el.append(node);
        item = { node, signature, actions, timer: null };
        this.items.set(key, item);
        this.arm(key, item, ttl);
        this.el.hidden = false;
    }

    arm(key, item, ttl) {
        clearTimeout(item.timer);
        item.timer = ttl > 0 ? setTimeout(() => this.clear(key), ttl) : null;
    }

    clear(key) {
        const item = this.items.get(key);
        if (!item) return;
        clearTimeout(item.timer);
        item.node.remove();
        this.items.delete(key);
        if (!this.items.size) this.el.hidden = true;
    }

    has(key) { return this.items.has(key); }

    destroy() {
        for (const item of this.items.values()) clearTimeout(item.timer);
        this.items.clear();
        this.el.replaceChildren();
        this.el.hidden = true;
    }
}
