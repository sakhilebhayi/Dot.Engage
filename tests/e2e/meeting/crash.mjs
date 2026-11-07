import { launch, newPerson, login, createMeeting, openRoom, guestSignIn, waitPhase, closeAll, sleep, tiles } from './lib.mjs';
const browser = await launch();
try {
  const host = await newPerson(browser, { name: 'Host', kind: 'host' });
  const g2 = await newPerson(browser, { name: 'P', email: 'pieter@karoofresh.example' });
  await login(host);
  const key = await createMeeting(host, 'crash');
  await openRoom(host, key); await host.page.waitForSelector('#btn-join:not([disabled])'); await host.page.click('#btn-join'); await waitPhase(host, 'live');
  await guestSignIn(g2, key); await openRoom(g2, key); await g2.page.waitForSelector('#btn-join:not([disabled])'); await g2.page.click('#btn-join'); await waitPhase(g2, 'waiting');
  await host.page.waitForSelector('#banner button:has-text("Admit")'); await host.page.click('#banner button:has-text("Admit")');
  await waitPhase(g2, 'live'); await sleep(6000);
  console.log('before', JSON.stringify((await tiles(host.page)).map(t => [t.name, t.state])));
  const cdp = await g2.ctx.newCDPSession(g2.page);
  const t0 = Date.now();
  console.log('crashing');
  await Promise.race([cdp.send('Page.crash').catch((e) => console.log('crash err', e.message)), sleep(3000)]);
  console.log('crash sent');
  for (let i = 0; i < 40; i++) {
    await sleep(1000);
    const t = await tiles(host.page);
    const roster = await host.page.evaluate(() => document.getElementById('people').innerText.replace(/\n/g, ' | '));
    console.log(((Date.now() - t0) / 1000).toFixed(0), JSON.stringify(t.map(x => [x.name, x.state, x.status])), roster);
    if (!t.some(x => /Pieter/.test(x.name))) break;
  }
} catch (e) { console.error('FAILED', e); } finally { await closeAll(); await browser.close(); }
