// The Ecosystem tagline's particles (particles.js, docs/DESIGN.md 8.10): the canvas draws and is removed, the words
// end visible and selectable, nothing shifts, no frame is asked for once it has settled, nothing of it is kept, a
// hidden tab pauses it, a theme change mid-flight recolours it, and forced colours coming on mid-flight end it at
// once. Reduced motion, Save-Data and a page without scripting show the words at once with no canvas; a script that
// never arrives, or a page too slow to start it before the CSS hold ends, leaves them to fade in. On a phone it plays as
// soon as any of the paragraph is in view, waits unseen while none is, and never scrolls sideways.
import { test, expect } from '@playwright/test';

// reducedMotion is a context option: test.use only takes it inside contextOptions.
test.use({ contextOptions: { reducedMotion: 'no-preference' } });

const TAGLINE = 'Every pixel. Every second. Every person. Every surface.';

/* Counts the effect's frames (its rAF callback is named tick), and every layout shift. */
function watch() {
  window.__fx = { ticks: 0, shift: 0 };
  const raf = window.requestAnimationFrame.bind(window);
  window.requestAnimationFrame = (cb) => raf((t) => { if (cb.name === 'tick') window.__fx.ticks++; cb(t); });
  new PerformanceObserver((list) => { for (const e of list.getEntries()) if (!e.hadRecentInput) window.__fx.shift += e.value; })
    .observe({ type: 'layout-shift', buffered: true });
}

const state = (page) => page.locator('[data-particles]').getAttribute('data-state');
const canvases = (page) => page.locator('[data-particles] canvas').count();

/* The words as a reader sees them, fully: an opaque colour, the fill following it, and the full stops shown. */
async function wordsShown(page) {
  return page.locator('[data-particles-line]').evaluateAll((lines) => lines.every((l) => {
    const cs = getComputedStyle(l), stop = l.querySelector('[data-product]');
    return /^rgb\(/.test(cs.color) && cs.getPropertyValue('-webkit-text-fill-color') === cs.color && (!stop || getComputedStyle(stop).opacity === '1');
  }));
}

/* Canvases the page holds but has not in its document, after a full collection, counted by their 2D contexts (the
   DevTools protocol, so Chromium only). The probe's own handles are released, so they keep nothing alive. */
async function detachedCanvases(page) {
  const cdp = await page.context().newCDPSession(page);
  try {
    await cdp.send('HeapProfiler.enable');
    await cdp.send('HeapProfiler.collectGarbage');
    const { result } = await cdp.send('Runtime.evaluate', { expression: 'CanvasRenderingContext2D.prototype', objectGroup: 'probe' });
    const { objects } = await cdp.send('Runtime.queryObjects', { prototypeObjectId: result.objectId, objectGroup: 'probe' });
    const counted = await cdp.send('Runtime.callFunctionOn', {
      objectId: objects.objectId, returnByValue: true,
      functionDeclaration: 'function () { return this.filter((c) => !c.canvas.isConnected).length; }',
    });
    await cdp.send('Runtime.releaseObjectGroup', { objectGroup: 'probe' });
    return counted.result.value;
  } finally {
    await cdp.detach();
  }
}

/* The share of the paragraph inside the viewport, from 0 to 1. */
const inView = (page) => page.locator('[data-particles]').evaluate((p) => {
  const r = p.getBoundingClientRect();
  return Math.max(0, Math.min(innerHeight, r.bottom) - Math.max(0, r.top)) / r.height;
});

function collectErrors(page) {
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('pageerror', (e) => errors.push(e.message));
  return errors;
}

test('the tagline gathers, the canvas goes, and the words are back and selectable', async ({ page }) => {
  const errors = collectErrors(page);
  await page.addInitScript(watch);
  await page.goto('/ecosystem/');
  await expect.poll(() => state(page)).toBe('run');
  expect(await canvases(page)).toBe(1);
  expect(await page.locator('[data-particles] canvas').getAttribute('aria-hidden')).toBe('true');
  await expect(page.locator('[data-particles]')).toHaveText(TAGLINE); // the words stay in the page throughout

  await expect.poll(() => state(page), { timeout: 10_000 }).toBe('done');
  await expect.poll(() => canvases(page)).toBe(0);
  await expect.poll(() => wordsShown(page)).toBe(true);

  // Settled means stopped: no frame is asked for after the canvas has gone.
  const ticks = await page.evaluate(() => window.__fx.ticks);
  expect(ticks).toBeGreaterThan(30);
  await page.waitForTimeout(500);
  expect(await page.evaluate(() => window.__fx.ticks)).toBe(ticks);
  expect(await page.evaluate(() => window.__fx.shift)).toBe(0);
  // Ended means let go: no canvas of it, its targets' sampler or its colour probe, is still held by the page.
  await expect.poll(() => detachedCanvases(page), { timeout: 5_000 }).toBe(0);

  await page.locator('[data-particles-line]').first().click({ clickCount: 3 });
  expect((await page.evaluate(() => getSelection().toString())).replace(/\s+/g, ' ')).toContain('Every pixel.');
  expect(errors).toEqual([]);
});

test('a hidden tab pauses it, and a theme change mid-flight recolours it', async ({ page }) => {
  const errors = collectErrors(page);
  await page.addInitScript(watch);
  await page.goto('/ecosystem/');
  await expect.poll(() => state(page)).toBe('run');
  await page.waitForTimeout(1200); // the first lines have landed in the text colour

  const hide = (hidden) => page.evaluate((h) => {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => h });
    document.dispatchEvent(new Event('visibilitychange'));
  }, hidden);
  await hide(true);
  const ticks = await page.evaluate(() => window.__fx.ticks);
  await page.waitForTimeout(2500); // longer than the whole gather: paused, it neither moves nor ends
  expect(await page.evaluate(() => window.__fx.ticks)).toBe(ticks);
  expect(await state(page)).toBe('run');

  // The landed particles carry the text colour of the theme on show: count the canvas pixels in a colour.
  const ink = () => page.locator('[data-particles-line]').first().evaluate((l) => getComputedStyle(l).color.match(/\d+/g).map(Number));
  const count = (rgb) => page.evaluate(([r, g, b]) => {
    const c = document.querySelector('[data-particles] canvas');
    const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
    let n = 0;
    for (let i = 0; i < d.length; i += 4) if (d[i + 3] === 255 && d[i] === r && d[i + 1] === g && d[i + 2] === b) n++;
    return n;
  }, rgb);
  const was = await ink();
  expect(await count(was)).toBeGreaterThan(100);
  const theme = await page.evaluate(() => (getComputedStyle(document.documentElement).colorScheme.includes('light') ? 'dark' : 'light'));
  await page.evaluate((t) => { document.documentElement.dataset.theme = t; }, theme);
  const now = await ink();
  expect(now).not.toEqual(was);
  await hide(false);
  await expect.poll(() => count(now)).toBeGreaterThan(100); // read again from the page, not kept from the start
  expect(await count(was)).toBe(0);
  await expect.poll(() => state(page), { timeout: 10_000 }).toBe('done');
  await expect.poll(() => wordsShown(page)).toBe(true);
  expect(errors).toEqual([]);
});

test('forced colours coming on mid-flight end it, and nothing is hidden under them', async ({ page }) => {
  const errors = collectErrors(page);
  await page.goto('/ecosystem/');
  await expect.poll(() => state(page)).toBe('run');
  await page.emulateMedia({ forcedColors: 'active' });
  await expect.poll(() => state(page)).toBe('done');
  await expect.poll(() => canvases(page)).toBe(0);
  // Forced colours paint the text in a system colour, so a shown word is one whose own fill is opaque.
  const opaque = () => page.locator('[data-particles-line]').evaluateAll((lines) => lines.every((l) => {
    const stop = l.querySelector('[data-product]');
    return /^rgb\(/.test(getComputedStyle(l).getPropertyValue('-webkit-text-fill-color')) && getComputedStyle(stop).opacity === '1';
  }));
  await expect.poll(opaque).toBe(true);
  // The CSS hides nothing under forced colours, whatever the state says, so the words show before the script reacts.
  await page.locator('[data-particles]').evaluate((p) => { p.dataset.state = 'run'; });
  expect(await opaque()).toBe(true);
  expect(errors).toEqual([]);
});

test('a page too slow to start it before the CSS hold ends lets the words fade in, with no effect', async ({ page }) => {
  // The fonts are held, so neither the load nor the fonts are ready when the CSS hold would end.
  let letFontsIn;
  const held = new Promise((resolve) => { letFontsIn = resolve; });
  await page.route('**/assets/fonts/**', async (route) => { await held; await route.continue(); });
  await page.addInitScript(() => {
    window.__states = {};
    new MutationObserver((records) => records.forEach((r) => {
      const p = r.target, now = p.dataset.state;
      if (now && !window.__states[now]) window.__states[now] = { t: performance.now(), fades: p.querySelector('[data-particles-line]').getAnimations().length };
    })).observe(document, { subtree: true, attributes: true, attributeFilter: ['data-state'] });
  });
  await page.goto('/ecosystem/', { waitUntil: 'domcontentloaded' });
  await expect.poll(() => state(page), { timeout: 5_000 }).toBe('done'); // taken, so it ends "done", not "off"
  const { armed, done } = await page.evaluate(() => window.__states);
  expect(armed).toBeTruthy();
  expect(done.t).toBeGreaterThan(1900); // the hold's two seconds, which start after the page does
  expect(done.t - armed.t).toBeLessThan(2300); // counted on the hold's clock, not two and a half from the script's
  expect(done.fades).toBe(1); // the words fade in rather than snap
  expect(await canvases(page)).toBe(0);
  letFontsIn();
  await page.waitForLoadState('load');
  await expect.poll(() => wordsShown(page)).toBe(true);
  await page.waitForTimeout(600); // the load and the fonts, late, do not start it after all
  expect(await canvases(page)).toBe(0);
  expect(await state(page)).toBe('done');
});

test.describe('reduced motion', () => {
  test.use({ contextOptions: { reducedMotion: 'reduce' } });
  test('shows the words at once, with no canvas', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/ecosystem/');
    expect(await state(page)).toBe('off');
    expect(await wordsShown(page)).toBe(true);
    expect(await page.locator('[data-particles-line]').first().evaluate((l) => l.getAnimations().length)).toBe(0);
    await page.waitForTimeout(600);
    expect(await canvases(page)).toBe(0);
    expect(errors).toEqual([]);
  });
});

test('Save-Data shows the words at once, with no canvas', async ({ page }) => {
  await page.addInitScript(() => Object.defineProperty(Navigator.prototype, 'connection', { configurable: true, get: () => ({ saveData: true }) }));
  await page.goto('/ecosystem/');
  expect(await state(page)).toBe('off');
  expect(await wordsShown(page)).toBe(true);
  await page.waitForTimeout(600);
  expect(await canvases(page)).toBe(0);
});

test('if the script never comes, the words fade in on their own', async ({ page }) => {
  await page.route('**/assets/js/particles.js*', (route) => route.abort());
  await page.goto('/ecosystem/');
  expect(await state(page)).toBeNull();
  expect(await wordsShown(page)).toBe(false); // held unseen, so they never show and then vanish
  await expect.poll(() => wordsShown(page), { timeout: 5_000 }).toBe(true); // the CSS hold ends after two seconds
});

test.describe('without scripting', () => {
  test.use({ javaScriptEnabled: false });
  test('the words are simply there', async ({ page }) => {
    await page.goto('/ecosystem/');
    expect(await state(page)).toBeNull();
    expect(await wordsShown(page)).toBe(true);
    await expect(page.locator('[data-particles]')).toHaveText(TAGLINE);
  });
});

for (const width of [390, 320]) {
  test.describe(`a ${width}px phone`, () => {
    test.use({ viewport: { width, height: 844 }, deviceScaleFactor: 3, isMobile: true, hasTouch: true });
    test('has no sideways scroll while it runs or after', async ({ page }) => {
      const errors = collectErrors(page);
      await page.addInitScript(watch);
      await page.goto('/ecosystem/');
      // Partly below the fold on a phone: it plays once any of the paragraph is in view, scrolled to or not.
      await page.locator('[data-particles]').scrollIntoViewIfNeeded();
      await expect.poll(() => state(page)).toBe('run');
      expect(await canvases(page)).toBe(1);
      const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      expect(await overflow()).toBeLessThanOrEqual(0);
      await expect.poll(() => state(page), { timeout: 10_000 }).toBe('done');
      await expect.poll(() => canvases(page)).toBe(0);
      expect(await overflow()).toBeLessThanOrEqual(0);
      expect(await page.evaluate(() => window.__fx.shift)).toBe(0);
      expect(errors).toEqual([]);
    });
  });
}

// A Pixel 5's first screen shows the tagline's first line and about a third of the paragraph: it plays, unscrolled.
test.describe('a phone that shows under half of the paragraph', () => {
  test.use({ viewport: { width: 393, height: 727 }, deviceScaleFactor: 2.75, isMobile: true, hasTouch: true });
  test('plays without a scroll, and the words end shown', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/ecosystem/');
    const share = await inView(page);
    expect(share).toBeGreaterThan(0); // the case under test: on screen, but under half of it
    expect(share).toBeLessThan(0.5);
    await expect.poll(() => state(page), { timeout: 5_000 }).toBe('done');
    await expect.poll(() => wordsShown(page)).toBe(true);
    expect(errors).toEqual([]);
  });
});

// A short phone: the paragraph starts wholly below the fold, so it waits unseen, then plays once scrolled to.
test.describe('a phone that shows none of the paragraph', () => {
  test.use({ viewport: { width: 320, height: 568 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  test('waits until any of it is in view, then plays', async ({ page }) => {
    const errors = collectErrors(page);
    await page.goto('/ecosystem/');
    expect(await inView(page)).toBe(0);
    await page.waitForTimeout(2500); // past the CSS hold: unseen words off screen wait for the visitor
    expect(await state(page)).toBe('armed');
    expect(await canvases(page)).toBe(0);
    await page.evaluate(() => scrollBy(0, document.querySelector('[data-particles]').getBoundingClientRect().top - innerHeight + 40));
    await expect.poll(() => state(page)).toBe('run');
    await expect.poll(() => state(page), { timeout: 10_000 }).toBe('done');
    await expect.poll(() => wordsShown(page)).toBe(true);
    expect(errors).toEqual([]);
  });
});
