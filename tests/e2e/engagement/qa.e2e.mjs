// Quality sweep over the real pages: console and CSP errors, axe violations, horizontal overflow, phone and tablet, light and dark.
// Output: /tmp/engage-qa/<page>-<scheme>-<width>.png and a summary. Exit code 1 on any serious axe violation, console error or overflow.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { launch, BASE, login, php, LOG, latestJoinLink, joinWithCode } from './lib.mjs';

const req = createRequire(path.join(process.env.PLAYWRIGHT_DIR || process.cwd(), 'noop.js'));
const axeSource = fs.readFileSync(req.resolve('axe-core/axe.min.js'), 'utf8');
const OUT = '/tmp/engage-qa'; fs.mkdirSync(OUT, { recursive: true });
const REF = process.env.E2E_REF || 'ENG-2026-00001';
const pages = [
    ['desk', '/dashboard'], ['engagements', '/engagements'], ['create', '/engagements/create'],
    ['agreement', `/e/${REF}`], ['discussion', `/e/${REF}/discussion`], ['people', `/e/${REF}/people`],
    ['meetings', `/e/${REF}/meetings`], ['signing', `/e/${REF}/signing`], ['history', `/e/${REF}/activity`],
    ['meetings-index', '/meetings'], ['agreements', '/agreements'], ['templates', '/contracts/templates'],
    ['notifications', '/notifications'], ['profile', '/user/profile'], ['team', '/teams/1'], ['chat', '/chat'],
];
const publicPages = [['welcome', '/'], ['signing-explained', '/how-signing-works'], ['verify', '/verify'], ['login', '/login'], ['404', '/nothing-here']];
const widths = [390, 768, 1440];
const browser = await launch();
let failed = 0; const summary = [];

async function sweep(ctx, list, who) {
    for (const scheme of ['light', 'dark']) {
        await ctx.close?.call; // no-op to keep lint quiet
        for (const width of widths) {
            const c = await browser.newContext({ viewport: { width, height: width < 500 ? 844 : 900 }, colorScheme: scheme, storageState: ctx.__state });
            const p = await c.newPage(); const errors = [];
            p.on('console', (m) => { if (m.type() === 'error' && !/_boost|favicon/.test(m.text())) errors.push(m.text().slice(0, 160)); });
            p.on('pageerror', (e) => errors.push('pageerror ' + e.message.slice(0, 160)));
            for (const [name, url] of list) {
                if (scheme === 'dark' && width === 768) continue;
                errors.length = 0;
                const res = await p.goto(BASE + url, { waitUntil: 'networkidle' }).catch((e) => ({ status: () => 0, err: e }));
                await p.waitForTimeout(600);
                await p.screenshot({ path: `${OUT}/${who}-${name}-${scheme}-${width}.png`, fullPage: false }).catch(() => {});
                await p.evaluate(axeSource);
                const axe = await p.evaluate(async () => (await window.axe.run(document, { runOnly: ['wcag2a', 'wcag2aa', 'best-practice'] })).violations.map((v) => ({ id: v.id, impact: v.impact, n: v.nodes.length, ex: v.nodes[0]?.html.slice(0, 120) })));
                const overflow = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                const bad = axe.filter((v) => ['serious', 'critical'].includes(v.impact));
                const row = { who, name, scheme, width, status: res.status?.(), errors: errors.filter((e) => !(res.status?.() === 404 && /status of 404/.test(e))), axe: bad, minor: axe.length - bad.length, overflow };
                if (bad.length || row.errors.length || overflow > 1) { failed++; summary.push(row); }
            }
            await c.close();
        }
    }
}

try {
    const oc = await browser.newContext(); const op = await oc.newPage();
    await login(op, 'owner@demo.test'); const ownerState = await oc.storageState();
    await sweep({ __state: ownerState }, pages, 'owner');
    await sweep({ __state: undefined }, publicPages, 'guest');
} finally {
    await browser.close();
}
fs.writeFileSync(`${OUT}/summary.json`, JSON.stringify(summary, null, 1));
console.log(failed ? `${failed} page checks with problems; see ${OUT}/summary.json` : 'QA sweep clean');
for (const r of summary.slice(0, 40)) console.log(r.who, r.name, r.scheme, r.width, 'status', r.status, 'overflow', r.overflow, 'errors', r.errors.length, 'axe', r.axe.map((a) => `${a.id}x${a.n}`).join(','));
process.exitCode = failed ? 1 : 0;
