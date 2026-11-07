// The tiles. A keyed reconciler: the room hands over a list of tile models; nodes are created, updated in place
// (so video elements never flicker) and removed. Layout: a best-fit grid for 1 to 6, or one large tile with a strip.

import { h, icon, initials, QUALITY_WORD } from './util.js';

const GAP = 8;
const ASPECTS = [16 / 9, 4 / 3, 1, 3 / 4];

export class Tile {
    constructor(model, hooks) {
        this.key = model.key;
        this.hooks = hooks;
        this.stream = null;
        this.sink = '';
        this.video = h('video', { class: 'tile-video', autoplay: true, playsinline: true, 'aria-hidden': 'true', disablepictureinpicture: true });
        this.video.muted = !!model.muted;
        this.ini = h('div', { class: 'tile-ini', 'aria-hidden': 'true' }, h('span', { class: 'ini ini-lg' }), h('span', { class: 'tile-note' }));
        this.status = h('div', { class: 'tile-status' }, h('span', { class: 'state' }), h('button', {
            type: 'button', class: 'btn btn-sm btn-quiet tile-retry', hidden: true,
            onclick: (event) => { event.stopPropagation(); hooks.onRetry(this.key); },
        }, h('span', {}, 'Retry')));
        this.hit = h('button', { type: 'button', class: 'tile-hit', 'aria-pressed': 'false', onclick: () => hooks.onPin(this.key) });
        this.nameEl = h('span', { class: 'tile-name' });
        this.flags = h('span', { class: 'tile-flags' });
        this.speakEl = h('span', { class: 'tile-speak' }, 'Speaking');
        this.label = h('div', { class: 'tile-label' }, this.nameEl, this.flags, this.speakEl);
        this.net = h('div', { class: 'tile-net', hidden: true }, h('span', { html: icon('signal', 'sm') }), h('span', { class: 'tile-net-word' }));
        this.el = h('div', { class: 'tile', 'data-key': model.key }, this.video, this.ini, this.status, this.hit, this.label, this.net);
        this.last = {};
    }

    update(m) {
        const el = this.el;
        const set = (name, value) => { if (this.last[name] !== value) { this.last[name] = value; return true; } return false; };

        if (set('kind', m.kind)) el.dataset.kind = m.kind;
        if (set('pen', m.pen)) { el.classList.remove('pen-1', 'pen-2', 'pen-3', 'pen-4'); el.classList.add('pen', `pen-${m.pen}`); }
        if (set('mine', !!m.mine)) el.classList.toggle('is-mine', !!m.mine);
        if (set('mirror', !!m.mirror)) this.video.classList.toggle('is-mirror', !!m.mirror);
        if (set('focus', !!m.focus)) el.classList.toggle('is-focus', !!m.focus);
        if (set('pinned', !!m.pinned)) { el.classList.toggle('is-pinned', !!m.pinned); this.hit.setAttribute('aria-pressed', m.pinned ? 'true' : 'false'); }
        if (set('speaking', !!m.speaking)) el.dataset.speaking = m.speaking ? 'true' : 'false';
        if (set('cam', m.showVideo)) el.dataset.cam = m.showVideo ? 'on' : 'off';
        if (set('state', m.state)) el.dataset.state = m.state;

        if (set('name', `${m.name}|${m.initialsOf || ''}`)) {
            this.nameEl.textContent = m.name;
            this.ini.firstChild.textContent = initials(m.initialsOf || m.name);
        }
        if (set('hit', m.hitLabel)) this.hit.setAttribute('aria-label', m.hitLabel);
        if (set('note', m.note)) this.ini.lastChild.textContent = m.note || '';

        const flagKey = `${m.micOff}|${m.camOff}|${m.sharing}|${m.host}`;
        if (set('flags', flagKey)) {
            this.flags.innerHTML = (m.host ? '<span class="tag tile-tag">Host</span>' : '')
                + (m.sharing ? '<span class="tag tile-tag">Sharing</span>' : '')
                + (m.micOff ? `<span class="tile-flag" role="img" aria-label="Microphone off" title="Microphone off">${icon('mic-off', 'sm')}</span>` : '')
                + (m.camOff ? `<span class="tile-flag" role="img" aria-label="Camera off" title="Camera off">${icon('cam-off', 'sm')}</span>` : '');
        }

        // Network quality: icon and a word, never colour alone.
        if (set('quality', m.quality || '')) {
            this.net.hidden = !m.quality;
            this.net.dataset.q = m.quality || '';
            this.net.querySelector('.tile-net-word').textContent = m.quality ? QUALITY_WORD[m.quality] : '';
            this.net.title = m.qualityTitle || '';
        }

        // Connection state of the link to this person.
        if (set('status', m.statusText || '')) {
            this.status.hidden = !m.statusText;
            this.status.querySelector('.state').textContent = m.statusText || '';
            this.status.querySelector('.state').className = `state ${m.statusTone || ''}`;
        }
        if (set('retry', !!m.canRetry)) this.status.querySelector('.tile-retry').hidden = !m.canRetry;

        this.bind(m.stream);
    }

    bind(stream) {
        if (this.stream === stream) return;
        this.stream = stream;
        this.video.srcObject = stream || null;
        if (stream) this.play();
    }

    play() {
        const p = this.video.play();
        if (p && p.catch) p.catch(() => this.hooks.onBlocked(this.key));
    }

    setSpeaking(on) {
        if (this.last.speaking === on) return;
        this.last.speaking = on;
        this.el.dataset.speaking = on ? 'true' : 'false';
    }

    setSink(id) {
        if (!this.video.setSinkId || this.video.muted || this.sink === id) return;
        this.sink = id;
        this.video.setSinkId(id || '').catch(() => {});
    }

    destroy() {
        this.video.pause();
        this.video.srcObject = null;
        this.el.remove();
    }
}

export class Grid {
    constructor(element, hooks) {
        this.el = element;
        this.hooks = hooks;
        this.tiles = new Map();
        this.mode = 'grid';
        this.sink = '';
        this.observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => this.layout()) : null;
        this.observer?.observe(element);
        this.onWindow = () => this.layout();
        window.addEventListener('resize', this.onWindow);
        window.addEventListener('orientationchange', this.onWindow);
    }

    render(models, focusKey) {
        const seen = new Set();
        let previous = null;
        for (const m of models) {
            seen.add(m.key);
            let tile = this.tiles.get(m.key);
            if (!tile) {
                tile = new Tile(m, this.hooks);
                this.tiles.set(m.key, tile);
            }
            tile.update({ ...m, focus: focusKey === m.key });
            if (this.sink) tile.setSink(this.sink);
            // Keep DOM order equal to model order without touching nodes that are already in place.
            const expected = previous ? previous.el.nextElementSibling : this.el.firstElementChild;
            if (expected !== tile.el) this.el.insertBefore(tile.el, expected);
            previous = tile;
        }
        for (const [key, tile] of this.tiles) {
            if (!seen.has(key)) { tile.destroy(); this.tiles.delete(key); }
        }
        this.mode = focusKey && this.tiles.has(focusKey) && models.length > 1 ? 'focus' : 'grid';
        this.el.dataset.mode = this.mode;
        this.el.dataset.count = String(models.length);
        this.layout();
    }

    setSpeaking(key, on) {
        this.tiles.get(key)?.setSpeaking(on);
    }

    setSink(id) {
        this.sink = id;
        for (const tile of this.tiles.values()) tile.setSink(id);
    }

    playAll() {
        for (const tile of this.tiles.values()) tile.play();
    }

    /** Best-fit sizes for the plain grid. Focus mode is pure CSS. */
    layout() {
        const count = this.tiles.size;
        if (this.mode !== 'grid' || !count) {
            this.el.style.removeProperty('--tw');
            this.el.style.removeProperty('--th');
            return;
        }
        const style = getComputedStyle(this.el);
        const W = this.el.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        const H = this.el.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
        if (W <= 0 || H <= 0) return;
        let best = { area: 0, w: 0, h: 0 };
        for (const aspect of ASPECTS) {
            for (let cols = 1; cols <= count; cols++) {
                const rows = Math.ceil(count / cols);
                const w = Math.min((W - GAP * (cols - 1)) / cols, ((H - GAP * (rows - 1)) / rows) * aspect);
                const hh = w / aspect;
                if (w * hh > best.area) best = { area: w * hh, w, h: hh };
            }
        }
        this.el.style.setProperty('--tw', `${Math.floor(best.w)}px`);
        this.el.style.setProperty('--th', `${Math.floor(best.h)}px`);
    }

    destroy() {
        this.observer?.disconnect();
        window.removeEventListener('resize', this.onWindow);
        window.removeEventListener('orientationchange', this.onWindow);
        for (const tile of this.tiles.values()) tile.destroy();
        this.tiles.clear();
    }
}
