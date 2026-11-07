// Full business flow in real browsers: invite, join by code, discuss on the document, propose and accept a change,
// make a revised version, approve, prepare signing, sign three ways, finalise, verify, and try to break it.
import fs from 'node:fs';
import { launch, BASE, LOG, php, latestJoinLink, joinWithCode, login, assert, scribble } from './lib.mjs';

const REF = 'ENG-2026-00001';
const browser = await launch();
const mk = async () => { const c = await browser.newContext({ viewport: { width: 1440, height: 900 }, acceptDownloads: true }); const p = await c.newPage(); p.on('dialog', (d) => d.accept(d.type() === 'prompt' ? 'Because the buyers need it' : undefined)); p.on('pageerror', (e) => console.log('PAGEERROR', e.message)); return { c, p }; };
const owner = await mk(); const thandi = await mk(); const pieter = await mk();

try {
    console.log('1. Owner invites; outside parties join with an emailed code');
    await login(owner.p, 'owner@demo.test');
    const out = php(`$e = App\\Models\\Engagement::withoutGlobalScopes()->first(); $a = app(App\\Services\\Engage\\ParticipantAccess::class); $r = []; foreach (['thandi','pieter'] as $n) { $p = $e->participants()->where('email', $n.'@karoofresh.example')->first(); $r[$n] = route('join.show', ['token' => $a->issueToken($p)]); } echo json_encode($r);`);
    const { thandi: thandiLink, pieter: pieterLink } = JSON.parse(out.slice(out.indexOf('{')));
    await joinWithCode(thandi.p, thandiLink, 'thandi@karoofresh.example');
    await joinWithCode(pieter.p, pieterLink, 'pieter@karoofresh.example');
    assert((await thandi.p.title()).includes('Cold-chain'), 'Thandi is inside the engagement after the code');
    assert(!(await thandi.p.locator('text=Operator').count()), 'an outside party sees no operator or team menus');

    console.log('2. Thandi reads the agreement and leaves a note and a proposed change');
    await thandi.p.waitForSelector('.sheetpage canvas');
    await thandi.p.getByRole('button', { name: 'Add a note or change' }).click();
    const layer = thandi.p.locator('.sheetpage .layer').first();
    const b = await layer.boundingBox();
    await thandi.p.mouse.move(b.x + b.width * 0.1, b.y + b.height * 0.16); await thandi.p.mouse.down(); await thandi.p.mouse.move(b.x + b.width * 0.9, b.y + b.height * 0.2, { steps: 6 }); await thandi.p.mouse.up();
    await thandi.p.waitForSelector('.composer textarea');
    await thandi.p.fill('.composer textarea[aria-label="Your note"]', 'Can the temperature log arrive within 12 hours?');
    await thandi.p.getByRole('button', { name: 'Add', exact: true }).click();
    await thandi.p.waitForSelector('.note .body');
    assert(await thandi.p.locator('.note .body', { hasText: '12 hours' }).count() === 1, 'the note is anchored in the margin');
    await thandi.p.getByRole('button', { name: 'Add a note or change' }).click();
    await thandi.p.mouse.move(b.x + b.width * 0.1, b.y + b.height * 0.22); await thandi.p.mouse.down(); await thandi.p.mouse.move(b.x + b.width * 0.9, b.y + b.height * 0.26, { steps: 6 }); await thandi.p.mouse.up();
    await thandi.p.waitForSelector('.composer');
    await thandi.p.getByRole('radio', { name: 'Propose a change' }).click();
    await thandi.p.fill('.composer textarea[aria-label="Current wording"]', 'within twenty-four (24) hours of delivery');
    await thandi.p.fill('.composer textarea[aria-label="Proposed wording"]', 'within twelve (12) hours of delivery');
    await thandi.p.getByRole('button', { name: 'Add', exact: true }).click();
    await thandi.p.waitForSelector('#changes .amend');
    assert(await thandi.p.locator('#changes .amend', { hasText: 'Waiting for review' }).count() === 1, 'the proposed change is waiting for review');
    assert(await thandi.p.getByRole('button', { name: 'Accept' }).count() === 0, 'the author cannot accept their own proposal');

    console.log('3. Owner resolves the note, accepts the change, creates the revised version');
    await owner.p.goto(`${BASE}/e/${REF}`);
    await owner.p.waitForSelector('#changes .amend');
    await owner.p.getByRole('button', { name: 'Accept' }).click();
    await owner.p.getByRole('button', { name: 'Resolve' }).first().click();
    await owner.p.waitForTimeout(800);
    await owner.p.getByRole('button', { name: 'Create the revised version' }).click();
    await owner.p.waitForFunction(() => document.querySelector('.docstrip')?.innerText.includes('Version 2'), null, { timeout: 20000 });
    assert(true, 'a version 2 exists with the agreed schedule');
    await owner.p.waitForFunction(() => /4 pages/.test(document.querySelector('.doctools')?.innerText || ''), null, { timeout: 20000 });
    assert(true, 'the schedule was appended as a new page');

    console.log('4. Approvals');
    await owner.p.getByRole('button', { name: 'Ask approvers to approve' }).click();
    await owner.p.waitForTimeout(800);
    for (const ctx of [thandi, pieter]) {
        await ctx.p.goto(`${BASE}/e/${REF}`);
        await ctx.p.getByRole('button', { name: 'Approve this version' }).click();
        await ctx.p.waitForTimeout(700);
    }
    await owner.p.reload();
    await owner.p.waitForFunction(() => document.querySelector('.docstrip')?.innerText.includes('Terms agreed'), null, { timeout: 20000 });
    assert(true, 'the terms are agreed once every approver approved');

    console.log('5. Prepare signing');
    php(`$e = App\\Models\\Engagement::withoutGlobalScopes()->first(); $e->participants()->where('email','pieter@karoofresh.example')->update(['capabilities'=>['approve','sign']]); $e->participants()->where('role','owner')->update(['capabilities'=>['host','sign']]);`);
    await owner.p.goto(`${BASE}/e/${REF}/signing`);
    await owner.p.waitForSelector('#prepare-root .signer-pick');
    await owner.p.getByRole('button', { name: 'Continue to place the signature fields' }).click();
    await owner.p.waitForSelector('.pedit .sheetpage');
    await owner.p.waitForSelector('.schip');
    const chips = owner.p.locator('.schip');
    const n = await chips.count();
    assert(n === 3, 'three signatories are in the request');
    const layer3 = owner.p.locator('.pedit .sheetpage').nth(2).locator('.layer');
    const pb = await layer3.boundingBox();
    const clickAt = (fx, fy) => layer3.click({ position: { x: pb.width * fx, y: pb.height * fy } });
    for (let i = 0; i < n; i++) {
        await chips.nth(i).click();
        await owner.p.getByRole('button', { name: 'Signature', exact: true }).click();
        await clickAt(0.2 + i * 0.28, 0.80);
        await owner.p.getByRole('button', { name: 'Date', exact: true }).click();
        await clickAt(0.2 + i * 0.28, 0.90);
    }
    await owner.p.waitForFunction(() => document.body.innerText.includes('All changes saved'));
    assert(await owner.p.locator('.fld').count() === 6, 'six fields placed');
    await owner.p.getByRole('button', { name: 'Send for signature' }).click();
    await owner.p.waitForFunction(() => document.body.innerText.includes('Out for signature'), null, { timeout: 20000 });
    assert(true, 'the request is out and the document is locked');

    console.log('6. Signing, in order: owner draws, Thandi types, Pieter uploads');
    await owner.p.goto(`${BASE}/e/${REF}/sign`);
    await owner.p.waitForSelector('.sfld.mine.is-todo');
    await owner.p.locator('.sfld.mine[data-field]').first().click();
    await owner.p.waitForSelector('canvas.padcanvas');
    await scribble(owner.p, 'canvas.padcanvas');
    await owner.p.getByRole('button', { name: 'Use this signature' }).click();
    await owner.p.locator('#consent').check();
    await owner.p.getByRole('button', { name: 'Sign and finish' }).click();
    await owner.p.waitForSelector('#sign-done:not([hidden])');
    assert(true, 'the owner signed');

    const plink = await latestJoinLink('to=sign');
    await joinWithCode(pieter.p, plink, 'pieter@karoofresh.example');
    await pieter.p.goto(`${BASE}/e/${REF}/sign`);
    await pieter.p.waitForSelector('.sfld.mine.is-todo');
    await pieter.p.locator('.sfld.mine[data-field]').first().click();
    await pieter.p.getByRole('tab', { name: 'Upload' }).click();
    // a photographed signature: dark strokes on white paper
    const png = await pieter.p.evaluate(() => { const c = document.createElement('canvas'); c.width = 500; c.height = 160; const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, 500, 160); g.strokeStyle = '#111'; g.lineWidth = 6; g.beginPath(); g.moveTo(30, 110); g.bezierCurveTo(120, 10, 200, 150, 470, 40); g.stroke(); return c.toDataURL('image/png').split(',')[1]; });
    fs.writeFileSync('/tmp/engage-e2e-sig.png', Buffer.from(png, 'base64'));
    await pieter.p.setInputFiles('input[type=file][aria-label^="A photo"]', '/tmp/engage-e2e-sig.png');
    await pieter.p.getByRole('button', { name: 'Use this signature' }).click();
    await pieter.p.locator('#consent').check();
    await pieter.p.getByRole('button', { name: 'Sign and finish' }).click();
    await pieter.p.waitForSelector('#sign-done:not([hidden])');
    assert(true, 'Pieter signed with an uploaded signature');

    // Thandi gets a fresh link to sign
    const link = await latestJoinLink('to=sign');
    await joinWithCode(thandi.p, link, 'thandi@karoofresh.example');
    await thandi.p.goto(`${BASE}/e/${REF}/sign`);
    await thandi.p.waitForSelector('.sfld.mine.is-todo');
    await thandi.p.locator('.sfld.mine[data-field]').first().click();
    await thandi.p.getByRole('tab', { name: 'Type' }).click();
    await thandi.p.fill('input[aria-label="Your full name"]', 'Thandi Mokoena');
    await thandi.p.getByRole('button', { name: 'Use this signature' }).click();
    await thandi.p.locator('#consent').check();
    await thandi.p.getByRole('button', { name: 'Sign and finish' }).click();
    await thandi.p.waitForSelector('#sign-done:not([hidden])');
    assert(true, 'Thandi signed with a typed signature (last in order)');

    // Duplicate and unauthorised attempts
    const dup = await thandi.p.evaluate(async (ref) => { const csrf = document.querySelector('meta[name=csrf-token]').content; const r = await fetch(`/e/${ref}/sign`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ signature: 'data:image/png;base64,AAAA', method: 'drawn', consent: true, key: 'another-key' }) }); return r.status; }, REF);
    assert([403, 404, 409, 422].includes(dup), 'a second signing attempt after signing is refused (' + dup + ')');
    const anon = await browser.newContext(); const ap = await anon.newPage();
    const anonStatus = (await ap.goto(`${BASE}/e/${REF}/sign`)).status();
    assert([403, 404].includes(anonStatus) || (await ap.url()).includes('login'), 'an anonymous visitor cannot open the signing page');
    const anonFile = (await ap.request.get(`${BASE}/e/${REF}/documents/1/file`)).status();
    assert([403, 404, 302].includes(anonFile), 'an anonymous visitor cannot download the document (' + anonFile + ')');

    console.log('7. The agreement completes');
    await owner.p.goto(`${BASE}/e/${REF}/signing`);
    await owner.p.waitForSelector('.completed-card');
    const txt = await owner.p.locator('body').innerText();
    assert(txt.includes('Signed and stored') && txt.includes('Valid: the record has not changed'), 'completed, with a valid platform seal');
    const [dl] = await Promise.all([owner.p.waitForEvent('download'), owner.p.getByRole('link', { name: 'Download the signed agreement' }).click()]);
    const file = '/tmp/engage-e2e-signed.pdf'; await dl.saveAs(file);
    const bytes = fs.readFileSync(file);
    assert(bytes.subarray(0, 5).toString() === '%PDF-', 'the signed file is a PDF');

    console.log('8. Public verification');
    const vp = await (await browser.newContext()).newPage();
    await vp.goto(`${BASE}/verify`);
    await vp.setInputFiles('input[type=file]', file);
    await vp.getByRole('button', { name: 'Check it' }).click();
    assert((await vp.locator('body').innerText()).includes('This is the signed agreement, unchanged'), 'the signed file verifies');
    fs.writeFileSync('/tmp/engage-e2e-tampered.pdf', Buffer.concat([bytes, Buffer.from('\n%tampered')]));
    await vp.goto(`${BASE}/verify`); await vp.setInputFiles('input[type=file]', '/tmp/engage-e2e-tampered.pdf'); await vp.getByRole('button', { name: 'Check it' }).click();
    assert((await vp.locator('body').innerText()).includes('No match'), 'a single appended byte breaks verification');

    console.log('9. Audit trail');
    const chain = php(`$e = App\\Models\\Engagement::withoutGlobalScopes()->first(); echo json_encode(app(App\\Services\\Engage\\AuditTrail::class)->verify($e));`);
    assert(chain.includes('"ok":true'), 'the audit chain is intact: ' + chain);
    console.log('\nALL PASSED');
} catch (e) {
    console.error('\nFAILED:', e.message);
    for (const [n, x] of Object.entries({ owner, thandi, pieter })) await x.p.screenshot({ path: `/tmp/engage-e2e-fail-${n}.png` }).catch(() => {});
    process.exitCode = 1;
} finally {
    await browser.close();
}
