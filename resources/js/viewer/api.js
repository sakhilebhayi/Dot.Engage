const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

export async function getJson(url) {
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    if (!res.ok) throw await asError(res);
    return res.json();
}

export async function postJson(url, body = {}) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    if (!res.ok) throw await asError(res);
    return res.json().catch(() => ({}));
}

async function asError(res) {
    let message = 'Something went wrong. Try again.';
    try {
        const data = await res.json();
        message = data.message || (data.errors && Object.values(data.errors)[0]?.[0]) || message;
    } catch (e) { /* not JSON */ }
    if (res.status === 419) message = 'Your session expired. Reload the page to continue.';
    if (res.status === 403) message = message === 'Something went wrong. Try again.' ? 'You do not have permission to do that.' : message;
    if (res.status === 429) message = 'Too many requests. Wait a moment and try again.';
    const err = new Error(message);
    err.status = res.status;
    return err;
}
