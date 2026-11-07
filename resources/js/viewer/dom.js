// Tiny DOM helpers. User-written text only ever goes in through textContent, never innerHTML.

export function h(tag, attrs = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') el.className = value;
        else if (key === 'dataset') Object.assign(el.dataset, value);
        else if (key === 'style' && typeof value === 'object') Object.assign(el.style, value);
        else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2).toLowerCase(), value);
        else if (value === true) el.setAttribute(key, '');
        else el.setAttribute(key, String(value));
    }
    for (const child of children.flat()) {
        if (child === null || child === undefined || child === false) continue;
        el.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return el;
}

export function icon(name, cls = 'icon icon-sm') {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', cls);
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#i-' + name);
    svg.append(use);
    return svg;
}

export function clear(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
}

const shortTime = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
export function when(iso) {
    const d = new Date(iso);
    return isNaN(d) ? '' : shortTime.format(d);
}

export function initials(name) {
    return (name || '?').trim().slice(0, 1).toUpperCase();
}

let toastZone;
export function toast(message, isError = false) {
    if (!toastZone) {
        toastZone = h('div', { class: 'toast-zone', role: 'status', 'aria-live': 'polite' });
        document.body.append(toastZone);
    }
    const t = h('div', { class: 'toast' + (isError ? ' is-error' : '') }, message);
    toastZone.append(t);
    setTimeout(() => t.remove(), isError ? 7000 : 3500);
}
