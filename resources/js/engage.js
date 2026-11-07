// Small behaviours shared by every page. No framework; Livewire brings Alpine for the rest.

const root = document.documentElement;

// Theme: follow the system until the person chooses; remember the choice.
function applyTheme(value) {
    if (value === 'light' || value === 'dark') {
        root.setAttribute('data-theme', value);
    } else {
        root.removeAttribute('data-theme');
    }
}
try {
    applyTheme(localStorage.getItem('engage-theme'));
} catch (e) { /* storage blocked: follow the system */ }

document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-theme-toggle]');
    if (toggle) {
        const dark = (root.getAttribute('data-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark';
        const next = dark ? 'light' : 'dark';
        applyTheme(next);
        try { localStorage.setItem('engage-theme', next); } catch (e) { /* ignore */ }
        return;
    }

    const open = event.target.closest('[data-open-dialog]');
    if (open) {
        const dialog = document.getElementById(open.getAttribute('data-open-dialog'));
        if (dialog && typeof dialog.showModal === 'function') {
            event.preventDefault();
            dialog.showModal();
            dialog.querySelector('[autofocus], input:not([type=hidden]), textarea, select, button')?.focus();
        }
        return;
    }

    const close = event.target.closest('[data-close-dialog]');
    if (close) {
        close.closest('dialog')?.close();
        return;
    }

    const copy = event.target.closest('[data-copy]');
    if (copy) {
        const text = copy.getAttribute('data-copy');
        navigator.clipboard?.writeText(text).then(() => {
            const old = copy.textContent;
            copy.textContent = 'Copied';
            setTimeout(() => { copy.textContent = old; }, 1600);
        });
    }

    // Clicking the backdrop of a native dialog closes it.
    if (event.target instanceof HTMLDialogElement && event.target.classList.contains('sheet')) {
        event.target.close();
    }
});

// A form that is submitting shows it, and cannot be submitted twice.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy')) {
        return;
    }
    if (form.dataset.submitting === '1') {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = '1';
    form.querySelectorAll('button[type=submit], button:not([type])').forEach((button) => {
        button.setAttribute('data-busy', 'true');
    });
    // If the browser comes back from the bfcache, allow the form again.
    setTimeout(() => { form.dataset.submitting = '0'; form.querySelectorAll('[data-busy]').forEach((b) => b.removeAttribute('data-busy')); }, 15000);
});
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        document.querySelectorAll('form[data-submitting]').forEach((form) => { form.dataset.submitting = '0'; });
        document.querySelectorAll('[data-busy]').forEach((b) => b.removeAttribute('data-busy'));
    }
});

// Times are stored in UTC and shown in the reader's own time zone.
const formatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const dayFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
function localise(scope = document) {
    scope.querySelectorAll('time[data-local]').forEach((el) => {
        const date = new Date(el.getAttribute('datetime'));
        if (!isNaN(date)) {
            el.textContent = el.getAttribute('data-local') === 'day' ? dayFormatter.format(date) : formatter.format(date);
        }
    });
}
localise();
document.addEventListener('livewire:navigated', () => localise());
document.addEventListener('livewire:initialized', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morphed', () => localise());
    }
});
window.engageLocalise = localise;
