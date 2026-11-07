// Small helpers shared by the meeting room modules.

export const $ = (id) => document.getElementById(id);

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function randomId(bytes = 9) {
    const a = new Uint8Array(bytes);
    (self.crypto || window.crypto).getRandomValues(a);
    return Array.from(a, (b) => b.toString(36).padStart(2, '0')).join('').slice(0, bytes * 2 - 2);
}

export function safeParse(text) {
    try { return JSON.parse(text); } catch (e) { return null; }
}

export function initials(name) {
    const parts = String(name || '?').replace(/\(.*?\)/g, '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    const first = Array.from(parts[0])[0] || '?';
    const last = parts.length > 1 ? (Array.from(parts[parts.length - 1])[0] || '') : '';
    return (first + last).toUpperCase();
}

/** One of four pen colours, stable per person. */
export function penOf(id) {
    const n = Number(id) || 0;
    return ((n % 4) + 4) % 4 + 1;
}

export function fmtClock(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = s % 60;
    const mm = String(m).padStart(2, '0');
    const rr = String(r).padStart(2, '0');
    return h > 0 ? `${h}:${mm}:${rr}` : `${mm}:${rr}`;
}

export function icon(name, size = '') {
    return `<svg class="icon${size ? ' icon-' + size : ''}" aria-hidden="true" focusable="false"><use href="#i-${name}"/></svg>`;
}

/** Create an element: h('button', {class: 'x', type: 'button'}, 'text', childNode). */
export function h(tag, attrs = {}, ...children) {
    const node = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === false || value === null || value === undefined) continue;
        if (key === 'class') node.className = value;
        else if (key === 'html') node.innerHTML = value;
        else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2), value);
        else node.setAttribute(key, value === true ? '' : String(value));
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        node.append(child.nodeType ? child : document.createTextNode(String(child)));
    }
    return node;
}

export function swapIcon(button, name) {
    const use = button?.querySelector('use');
    if (use) use.setAttribute('href', `#i-${name}`);
}

export function isTyping(target) {
    if (!target || !target.tagName) return false;
    const tag = target.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable === true;
}

export const QUALITY_WORD = { good: 'Good', fair: 'Fair', poor: 'Poor' };

export function worstQuality(list) {
    const order = { good: 0, fair: 1, poor: 2 };
    let worst = null;
    for (const q of list) {
        if (!q) continue;
        if (worst === null || order[q] > order[worst]) worst = q;
    }
    return worst;
}
