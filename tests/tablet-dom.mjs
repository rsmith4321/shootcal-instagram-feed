// Real CSS and runtime regression. Chromium supports the feed's nested :has()
// selectors; jsdom's nwsapi parser does not. No browser profile or WordPress store.
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
const require = createRequire('/Volumes/Recent Pictures SSD/Xcode/Development/ShootCal/shootcal-web/react/package.json');
const { chromium } = require('playwright');
const root = fileURLToPath(new URL('..', import.meta.url)).replace(/\/$/, '');
const rendered = spawnSync('php', [`${root}/tests/shortcode-media-test.php`, root, '--tablet-fixtures'], { encoding: 'utf8' });
assert.equal(rendered.status, 0, rendered.stderr);
const fixtures = JSON.parse(rendered.stdout);
const script = readFileSync(`${root}/assets/feed.js`, 'utf8');
const css = readFileSync(`${root}/assets/feed.css`, 'utf8');
const capturePath = process.argv[2] === '--capture' ? process.argv[3] : null;
const evidencePath = process.argv[2] === '--evidence' ? process.argv[3] : capturePath;
const widths = [360, 390, 639, 640, 641, 768, 1023, 1024, 1025, 1440];
const measurements = [];
let assertions = 0;
const check = (actual, expected, message) => { if (!capturePath) { assert.deepEqual(actual, expected, message); assertions++; } };
const browser = await chromium.launch({ headless: true });
try {
  const context = await browser.newContext();
  // Synthetic images are fulfilled locally. No provider traffic is permitted.
  await context.route('**/*', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320"/>' }));
  const page = await context.newPage();
  const mount = async (html, width, enhance) => {
    await page.setViewportSize({ width, height: 900 });
    await page.setContent(`<!doctype html><html><head><style>body{margin:8px}${css}</style></head><body>${html}</body></html>`);
    if (enhance) await page.addScriptTag({ content: script });
  };
  const read = () => page.evaluate(() => {
    const grid = document.querySelector('.shootcal-instagram-feed');
    const items = [...grid.querySelectorAll(':scope > a')];
    const button = document.querySelector('button');
    const label = document.querySelector('.shootcal-instagram-feed__type:not(.shootcal-instagram-feed__type--carousel)');
    return {
      columns: getComputedStyle(grid).gridTemplateColumns.split(/\s+/).length,
      mounted: items.length,
      visible: items.filter(item => getComputedStyle(item).display !== 'none').length,
      mediaFont: Number.parseFloat(getComputedStyle(label).fontSize),
      buttonFont: Number.parseFloat(getComputedStyle(button).fontSize),
      buttonHidden: button.hidden,
      focusIndex: items.indexOf(document.activeElement),
      sources: items.map(item => item.querySelector('img')?.sizes || ''),
    };
  });
  for (const width of widths) {
    const columns = width <= 640 ? 2 : width <= 1024 ? 3 : 5;
    const initial = width <= 640 ? 4 : 5;
    await mount(fixtures.phone, width, false);
    const fallback = await read();
    check(fallback.visible, initial, `no-JS initial posts at ${width}`);
    check(fallback.columns, columns, `no-JS columns at ${width}`);
    check(fallback.buttonHidden, true, `no-JS View more hidden at ${width}`);
    await page.addScriptTag({ content: script });
    const before = await read();
    check(before.mounted, initial, `enhanced initial posts at ${width}`);
    check(before.columns, columns, `enhanced columns at ${width}`);
    if (!capturePath) {
      assert.ok(before.mediaFont >= (width >= 641 && width <= 1024 ? 15 : 12), `media label minimum at ${width}`); assertions++;
      assert.ok(before.buttonFont >= (width >= 641 && width <= 1024 ? 15 : 12), `control minimum at ${width}`); assertions++;
    }
    await page.locator('button').click();
    const expanded = await read();
    check(expanded.mounted, initial + columns, `one computed row revealed at ${width}`);
    check(expanded.focusIndex, initial, `focus follows first new post at ${width}`);
    // Expanding once is persistent across both breakpoint transitions.
    await page.setViewportSize({ width: width <= 640 ? 1025 : 640, height: 900 });
    const resized = await read();
    check(resized.mounted, expanded.mounted, `expanded post count survives resize from ${width}`);
    check(resized.visible, expanded.mounted, `revealed posts remain visible after resize from ${width}`);
    check(resized.columns, width <= 640 ? 5 : 2, `expanded columns after resize from ${width}`);
    measurements.push({ width, fallback, before, expanded, resized });
  }
  for (const width of [639, 640, 641, 1023, 1024, 1025]) {
    for (const configured of [1, 2, 3, 4, 5, 6]) {
      // Four posts in either initial PHP or enhanced output, with inert
      // deferred templates present only for the progressive variant.
      const tablet = width >= 641 && width <= 1024;
      const expected = width <= 640 ? Math.min(configured, 2) : tablet ? Math.min(configured, 2) : configured;
      await mount(fixtures[`static-${configured}`], width, false);
      check((await page.locator('.shootcal-instagram-feed').evaluate(grid => getComputedStyle(grid).gridTemplateColumns.split(/\s+/).length)), expected, `four static posts: width ${width}, configured ${configured}`);
      await mount(fixtures[`four-${configured}`], width, true);
      if (width <= 640) {
        check((await read()).columns, Math.min(configured, 2), `four enhanced phone posts ${width}/${configured}`);
      } else {
        check((await read()).columns, expected, `four mounted posts ignore templates ${width}/${configured}`);
        await page.locator('button').click();
        const after = await read();
        check(after.columns, tablet ? Math.min(configured, 3) : configured, `normal columns resume after reveal ${width}/${configured}`);
      }
    }
  }
  // Follow=false must survive PHP rendering and enhancement.
  await mount(fixtures['no-follow'], 641, true);
  check(await page.locator('.shootcal-instagram-feed__follow-link').count(), 0, 'follow opt-out retained');
  if (evidencePath) writeFileSync(evidencePath, JSON.stringify({ browser: 'Playwright bundled Chromium', assertions, measurements }, null, 2) + '\n');
  console.log(capturePath ? `WordPress baseline: ${measurements.length} widths captured` : `WordPress responsive browser: ${assertions} assertions passed`);
} finally { await browser.close(); }
