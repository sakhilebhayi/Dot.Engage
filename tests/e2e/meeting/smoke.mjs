import { launch, newPerson, login, createMeeting, openRoom, shot, closeAll, sleep } from './lib.mjs';
const browser = await launch();
try {
  const host = await newPerson(browser, { name: 'Naledi', kind: 'host' });
  await login(host);
  const key = await createMeeting(host, 'Smoke meeting');
  console.log('meeting', key);
  await openRoom(host, key);
  await sleep(3500);
  await shot(host, 'smoke-lobby.png');
  console.log('errors', host.errors, 'warnings', host.warnings);
  console.log(await host.page.evaluate(() => document.getElementById('lobby-checks').innerText));
  console.log(await host.page.evaluate(() => document.getElementById('lobby-msg').innerText));
} finally { await closeAll(); await browser.close(); }
