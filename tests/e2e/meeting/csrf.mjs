import { launch, newPerson, login, createMeeting, openRoom, waitPhase, closeAll, sleep } from './lib.mjs';
const browser = await launch();
try {
  const host = await newPerson(browser, { name: 'Host', kind: 'host' });
  await login(host);
  const key = await createMeeting(host, 'csrf');
  await openRoom(host, key); await host.page.waitForSelector('#btn-join:not([disabled])'); await host.page.click('#btn-join'); await waitPhase(host, 'live');
  const r = await host.page.evaluate(async (key) => {
    const res = await fetch(`/meetings/${key}/state`, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': 'bad', 'X-Client-Id': 'x' }, body: '{"mic":true}' });
    return [res.status, (await res.text()).slice(0, 200)];
  }, key);
  console.log(JSON.stringify(r));
  await host.page.evaluate(() => { document.querySelector('meta[name="csrf-token"]').content = 'bad'; });
  await host.page.click('#ctl-mic');
  await sleep(3000);
  console.log(await host.page.evaluate(() => document.body.dataset.state + ' ' + (document.getElementById('notice-h')?.textContent || '')));
} finally { await closeAll(); await browser.close(); }
