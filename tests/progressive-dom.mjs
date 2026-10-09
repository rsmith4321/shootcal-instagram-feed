// Run after progressive-fixture.php; uses the installed local QA jsdom runtime.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { JSDOM } = await import(process.env.JSDOM_MODULE || 'jsdom');
const fixtures = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const script = readFileSync(new URL('../assets/feed.js', import.meta.url), 'utf8');
let assertions = 0;
for (const columns of [1, 2, 3, 4, 5, 6]) {
  const dom = new JSDOM(`<!doctype html><body>${fixtures[columns]}</body>`, { runScripts: 'outside-only', url: 'http://localhost/' });
  const win = dom.window;
  let requests = 0;
  win.fetch = () => { requests++; throw new Error('View more must not fetch'); };
  win.matchMedia = () => ({ matches: false });
  win.getComputedStyle = () => ({ gridTemplateColumns: Array(columns).fill('100px').join(' ') });
  win.eval(script);
  win.document.dispatchEvent(new win.Event('DOMContentLoaded'));
  const grid = win.document.querySelector('.shootcal-instagram-feed');
  const button = win.document.querySelector('button');
  assert.equal(grid.querySelectorAll('img').length, 6, 'extra images remain inert');
  button.click();
  assert.equal(grid.querySelectorAll('img').length, 6 + columns, 'one configured row appears');
  assert.equal(win.document.activeElement, grid.querySelectorAll('a')[6], 'focus follows first new post');
  while (!button.hidden) button.click();
  assert.equal(grid.querySelectorAll('img').length, 30, 'partial last row exhausts correctly');
  assert.equal(grid.querySelectorAll('template').length, 0);
  assert.equal(requests, 0);
  assert.match(win.document.querySelector('[role="status"]').textContent, /Showing 30 of 30/);
  assertions += 7;
  dom.window.close();
}
const phone = new JSDOM(`<!doctype html><body>${fixtures.phone}${fixtures[3]}</body>`, { runScripts: 'outside-only', url: 'http://localhost/' });
phone.window.matchMedia = () => ({ matches: true });
phone.window.getComputedStyle = () => ({ gridTemplateColumns: '150px 150px' });
phone.window.eval(script);
phone.window.document.dispatchEvent(new phone.window.Event('DOMContentLoaded'));
const shells = phone.window.document.querySelectorAll('[data-scif-more]');
assert.notEqual(shells[0].querySelector('button').getAttribute('aria-controls'), shells[1].querySelector('button').getAttribute('aria-controls'));
assert.equal(phone.window.document.getElementById(shells[0].querySelector('button').getAttribute('aria-controls')), shells[0].querySelector('.shootcal-instagram-feed'));
assert.equal(shells[0].querySelectorAll('img').length, 4);
shells[0].querySelector('button').click();
assert.equal(shells[0].querySelectorAll('img').length, 6);
assert.equal(shells[1].querySelectorAll('img').length, 6, 'the second feed is unchanged');
assert.equal(shells[0].querySelectorAll('a')[4].getAttribute('href'), 'https://www.instagram.com/p/Preview5/');
phone.window.close();
console.log(`WordPress progressive DOM: ${assertions + 6} assertions passed`);

// A visitor can expand the static fallback before the cache-only refresh returns.
const dynamic = new JSDOM(`<!doctype html><body><div class="shootcal-instagram-feed-loader" data-endpoint="/wp-json/shootcal-instagram-feed/v1/feed">${fixtures[3]}</div></body>`, { runScripts: 'outside-only', url: 'http://localhost/' });
let finishRefresh; let requestCount = 0;
dynamic.window.matchMedia = () => ({ matches: false });
dynamic.window.getComputedStyle = () => ({ gridTemplateColumns: '100px 100px 100px' });
dynamic.window.fetch = () => { requestCount++; return new Promise(resolve => { finishRefresh = resolve; }); };
dynamic.window.eval(script);
dynamic.window.document.dispatchEvent(new dynamic.window.Event('DOMContentLoaded'));
await new Promise(resolve => setTimeout(resolve, 0));
dynamic.window.document.querySelector('button').click();
assert.equal(dynamic.window.document.querySelectorAll('img').length, 9);
finishRefresh({ ok: true, json: async () => ({ html: fixtures[3] }) });
await new Promise(resolve => setTimeout(resolve, 0));
assert.equal(dynamic.window.document.querySelectorAll('img').length, 9, 'late cached refresh preserves the expanded count');
dynamic.window.document.querySelector('button').click();
assert.equal(dynamic.window.document.querySelectorAll('img').length, 12);
assert.equal(requestCount, 1, 'clicks add no requests beyond the existing initial cache refresh');
dynamic.window.close();
console.log('WordPress delayed-refresh race: 4 assertions passed');

// A wide viewport must not select an oversized source for a narrow feed container.
const sizing = new JSDOM(`<!doctype html><body>${fixtures[3]}</body>`, {runScripts:'outside-only',url:'http://localhost/'});
let tileWidth=263;
sizing.window.matchMedia=()=>({matches:false});
sizing.window.getComputedStyle=()=>({gridTemplateColumns:'263px 263px 263px'});
sizing.window.HTMLElement.prototype.getBoundingClientRect=function(){return {width:tileWidth};};
for(const template of sizing.window.document.querySelectorAll('template'))for(const img of template.content.querySelectorAll('img')){img.srcset='https://example.test/320.jpg 320w, https://example.test/640.jpg 640w';img.sizes='auto, 20vw';}
sizing.window.eval(script);sizing.window.document.dispatchEvent(new sizing.window.Event('DOMContentLoaded'));
sizing.window.document.querySelector('button').click();
const revealed=sizing.window.document.querySelectorAll('img')[6];
assert.equal(revealed.sizes,'263px','activation measures the actual tile before eager loading');
tileWidth=170;sizing.window.dispatchEvent(new sizing.window.Event('resize'));
assert.equal(revealed.sizes,'170px','responsive sizing follows viewport changes');
sizing.window.close();console.log('WordPress responsive source selection: 2 assertions passed');
