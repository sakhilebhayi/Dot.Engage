// Helpers for the engagement end-to-end tests. Needs: a running app (E2E_BASE), its DB file (E2E_DB), the app folder (E2E_APP),
// and Playwright resolvable from PLAYWRIGHT_DIR (a folder that has node_modules/playwright) or the current directory.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

export const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8782';
export const APP = process.env.E2E_APP || process.cwd();
export const DB = process.env.E2E_DB;
export const LOG = process.env.E2E_LOG || '/tmp/engage-e2e.log';
const req = createRequire(path.join(process.env.PLAYWRIGHT_DIR || process.cwd(), 'noop.js'));

export async function launch() {
    const mod = await import(req.resolve('playwright'));
    const chromium = mod.chromium || mod.default.chromium;
    const args = ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--autoplay-policy=no-user-gesture-required'];
    try { return await chromium.launch({ args }); } catch (e) { /* try a cached build */ }
    const roots = [process.env.PLAYWRIGHT_BROWSERS_PATH, path.join(os.homedir(), 'Library/Caches/ms-playwright'), path.join(os.homedir(), '.cache/ms-playwright')].filter(Boolean);
    for (const root of roots) {
        if (!fs.existsSync(root)) continue;
        for (const dir of fs.readdirSync(root).filter((d) => /^chromium-\d+/.test(d)).sort().reverse()) {
            for (const rel of ['chrome-mac-arm64/Google Chrome for Testing.app/Contents/MacOS/Google Chrome for Testing', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium', 'chrome-linux/chrome']) {
                const exe = path.join(root, dir, rel);
                if (fs.existsSync(exe)) { try { return await chromium.launch({ executablePath: exe, args }); } catch (e) { /* next */ } }
            }
        }
    }
    return chromium.launch({ channel: 'chrome', args });
}

export function php(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: APP, env: { ...process.env, DB_DATABASE: DB, APP_ENV: 'local', APP_URL: BASE, LOG_FILE: LOG }, encoding: 'utf8' }).trim();
}

export function logTail(bytes = 400000) {
    const f = LOG;
    const size = fs.statSync(f).size;
    const fd = fs.openSync(f, 'r');
    const buf = Buffer.alloc(Math.min(bytes, size));
    fs.readSync(fd, buf, 0, buf.length, Math.max(0, size - buf.length));
    fs.closeSync(fd);
    return buf.toString('utf8');
}

/** The newest emailed six-digit code for an address. */
export async function latestCode(email, since) {
    for (let i = 0; i < 40; i++) {
        const log = logTail();
        const idx = log.lastIndexOf('To: ' + email);
        const hits = [...log.matchAll(/Your Dot\.Engage code: (\d{6})/g)];
        if (hits.length && logSize() > since && idx >= 0) return hits[hits.length - 1][1];
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error('No code mail found for ' + email);
}

export async function latestJoinLink(match = '') {
    for (let i = 0; i < 40; i++) {
        const links = [...logTail().matchAll(/https?:\/\/[^\s"'<>)]*\/join\/([A-Za-z0-9]{64})([^\s"'<>)]*)/g)].map((m) => m[0].replace(/&amp;/g, '&'));
        const hit = links.reverse().find((l) => l.includes(match));
        if (hit) return hit;
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error('No join link in the mail log');
}

export const logSize = () => fs.statSync(LOG).size;

export async function login(page, email, password = 'password') {
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([page.waitForURL(/dashboard|teams/), page.click('button[type=submit]')]);
}

/** Join through the emailed link and the one-time code. */
export async function joinWithCode(page, link, email) {
    const before = logSize();
    await page.goto(link);
    if (!page.url().includes('/join/')) return; // this browser already proved the address
    await page.getByRole('button', { name: /Email me a code|Send a new code/ }).click();
    await page.waitForSelector('#code');
    const code = await latestCode(email, before);
    await page.fill('#code', code);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Open the engagement' }).click()]);
}

export function assert(cond, msg) {
    if (!cond) throw new Error('ASSERT FAILED: ' + msg);
    console.log('  ok  ' + msg);
}

/** Draw a short signature stroke on a canvas. */
export async function scribble(page, selector) {
    const box = await page.locator(selector).boundingBox();
    await page.mouse.move(box.x + 30, box.y + box.height * 0.7);
    await page.mouse.down();
    for (let i = 0; i <= 20; i++) await page.mouse.move(box.x + 30 + i * 9, box.y + box.height * (0.7 - Math.sin(i / 3) * 0.25));
    await page.mouse.up();
}
