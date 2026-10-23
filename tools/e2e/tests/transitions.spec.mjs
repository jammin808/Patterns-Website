// The site's motion around the shell. Page changes dissolve (a cross-document view transition) and never show the
// other theme: for each OS colour scheme and each stored choice, links between four pages and Back, a page restored
// from the back/forward cache, Back after a theme change on the next page or a change of the system's scheme, and a
// theme change followed at once by a page change; with storage blocked, a choice made on a page holds there. The
// screen is filmed through every navigation (a frame each time it changes; headless Chromium draws a dissolve's
// frames 50 to 70ms apart, so the theme checks play it at quarter speed) and the page background's luminance is read
// from each frame. Every page gives the header its view-transition-name and names nothing twice. The Products panel
// and the drawer fade and move a few pixels as they open and close, without holding up focus, moving the page or
// needing scripting, and are instant under reduced motion; the closed drawer never shows as the window narrows
// across 72rem.
//
// Full Chromium (the "chromium" channel, not the headless shell) with the back/forward cache on, which Playwright
// turns off by default: these checks are about what a visitor's browser shows.
import { test, expect } from '@playwright/test';

const BG = { light: 'rgb(243, 244, 246)', dark: 'rgb(9, 12, 16)' };
const ROUTE = ['/ecosystem/', '/workflows/', '/licensing/', '/support/'];

test.use({ launchOptions: { channel: 'chromium', ignoreDefaultArgs: ['--disable-back-forward-cache'] } });

/**
 * A browser whose storage holds this theme (or none, to follow the system), with statistics cookies answered, for
 * the origin the run serves (the config's baseURL).
 */
const stored = (theme) => ({
  storageState: async ({ baseURL }, use) => use({
    cookies: [],
    origins: [{
      origin: new URL(baseURL).origin,
      localStorage: [{ name: 'psc-consent', value: `denied:${Date.now()}` }, ...(theme === 'system' ? [] : [{ name: 'psc-theme', value: theme }])],
    }],
  }),
});

/**
 * Every pagereveal, kept in sessionStorage so it outlives the page: whether a view transition came with it, whether
 * it ran or was cut, its animations (pseudo-element, keyframes, duration) and when it began and ended.
 */
async function logReveals(page) {
  await page.addInitScript(() => {
    const KEY = 'e2e-reveals';
    const read = () => JSON.parse(sessionStorage.getItem(KEY) || '[]');
    addEventListener('pagereveal', (e) => {
      const log = read();
      const i = log.push({ path: location.pathname, vt: !!e.viewTransition, at: Date.now() }) - 1;
      sessionStorage.setItem(KEY, JSON.stringify(log));
      if (!e.viewTransition) return;
      const note = (fields) => { const l = read(); Object.assign(l[i], fields); sessionStorage.setItem(KEY, JSON.stringify(l)); };
      const animations = () => document.documentElement.getAnimations({ subtree: true })
        .filter((a) => a.effect?.pseudoElement?.startsWith('::view-transition'))
        .map((a) => `${a.effect.pseudoElement} ${a.animationName} ${Math.round(a.effect.getTiming().duration)}`).sort();
      e.viewTransition.ready.then(() => note({ ran: true, animations: animations() }), () => note({ ran: false }));
      e.viewTransition.finished.then(() => note({ done: Date.now() }));
    });
  });
}

/**
 * Plays every dissolve four times slower (960ms), for this test only, so the filming holds many frames from inside
 * each one: headless Chromium draws a dissolve's frames 50 to 70ms apart. A constructed stylesheet, which the CSP
 * allows; nothing else about the page changes.
 */
async function slowMotion(page) {
  await page.addInitScript(() => {
    const sheet = new CSSStyleSheet();
    sheet.replaceSync('::view-transition-group(*), ::view-transition-old(*), ::view-transition-new(*) { animation-duration: 960ms !important; }');
    document.adoptedStyleSheets = [...document.adoptedStyleSheets, sheet];
  });
}
const lastReveal = async (page) => {
  try {
    return await page.evaluate(() => JSON.parse(sessionStorage.getItem('e2e-reveals') || '[]').at(-1) ?? null);
  } catch {
    return null; // between documents
  }
};

/** Films the page: a frame each time the screen changes. */
async function film(page) {
  const cdp = await page.context().newCDPSession(page);
  const frames = [];
  cdp.on('Page.screencastFrame', (f) => {
    frames.push({ t: f.metadata.timestamp * 1000, data: f.data });
    cdp.send('Page.screencastFrameAck', { sessionId: f.sessionId }).catch(() => {});
  });
  await cdp.send('Page.startScreencast', { format: 'png', maxWidth: 480, maxHeight: 300, everyNthFrame: 1 });
  return {
    frames,
    async stop() {
      await cdp.send('Page.stopScreencast').catch(() => {});
      await cdp.detach().catch(() => {});
    },
  };
}

/**
 * The page background's relative luminance in each frame: the median of nine points down the right-hand gutter,
 * where no content reaches (below the header, above the assistant's launcher). The dark page is 0.004 and the light
 * page 0.904. The frames are decoded in a page of their own.
 */
async function luminance(browser, frames) {
  const ctx = await browser.newContext();
  const decoder = await ctx.newPage();
  const values = await decoder.evaluate(async (frames) => {
    const lin = (v) => { const c = v / 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
    const out = [];
    for (const f of frames) {
      const bmp = await createImageBitmap(await (await fetch(`data:image/png;base64,${f.data}`)).blob());
      const canvas = new OffscreenCanvas(bmp.width, bmp.height);
      const g = canvas.getContext('2d');
      g.drawImage(bmp, 0, 0);
      const ls = [];
      for (let i = 0; i < 9; i++) {
        const [r, gr, b] = g.getImageData(Math.floor(bmp.width * 0.985), Math.floor(bmp.height * (0.3 + i * 0.05)), 1, 1).data;
        ls.push(0.2126 * lin(r) + 0.7152 * lin(gr) + 0.0722 * lin(b));
      }
      out.push(ls.sort((a, b) => a - b)[4]);
    }
    return out;
  }, frames);
  await ctx.close();
  return values.map((l, i) => ({ t: frames[i].t, l }));
}
const themeOf = (l) => (l > 0.5 ? 'light' : l < 0.2 ? 'dark' : 'between');

/** The page shows `theme` for the stored `choice`: the attribute, the body's background and the pressed control. */
async function expectTheme(page, theme, choice) {
  await expect.poll(() => page.evaluate(() => document.documentElement.getAttribute('data-theme'))).toBe(choice === 'system' ? null : choice);
  expect(await page.evaluate(() => getComputedStyle(document.body).backgroundColor)).toBe(BG[theme]);
  await expect(page.locator(`[data-theme-set="${choice}"]`)).toHaveAttribute('aria-pressed', 'true');
}

/**
 * Films one page change (by default a click on the header's link to `path`) until the arriving page's dissolve has
 * finished, or until it has been revealed without one. Returns that reveal and the frames' luminance.
 */
async function navigate(page, browser, path, go) {
  const reel = await film(page);
  const from = Date.now();
  await (go ? go() : page.locator(`.c-nav-link[href="${path}"]`).click());
  await expect.poll(async () => {
    const r = await lastReveal(page);
    return Boolean(r && r.path === path && r.at >= from && (r.vt === false || r.done !== undefined));
  }, { timeout: 10_000, message: `${path} revealed` }).toBe(true);
  await page.waitForTimeout(120);
  await reel.stop();
  return { reveal: await lastReveal(page), frames: (await luminance(browser, reel.frames)).filter((f) => f.t >= from - 50) };
}

/** Every frame shows `theme`, and the filming caught the page change (`least` frames at least). */
function expectSteady(frames, theme, label, least = 4) {
  expect(frames.length, `${label}: frames filmed`).toBeGreaterThanOrEqual(least);
  expect(frames.filter((f) => themeOf(f.l) !== theme).map((f) => f.l.toFixed(3)), `${label}: frames that were not ${theme}`).toEqual([]);
}

/** The dissolve ran, and frames were filmed while it ran. */
function expectDissolved(reveal, frames, label) {
  expect(reveal, label).toMatchObject({ vt: true, ran: true });
  expect(frames.filter((f) => f.t >= reveal.at && f.t <= reveal.done).length, `${label}: frames during the dissolve`).toBeGreaterThan(2);
}

/* ------------------------------------------------------------------------------------------- the dissolve */

for (const os of ['light', 'dark']) {
  for (const choice of ['light', 'dark', 'system']) {
    const theme = choice === 'system' ? os : choice;
    test.describe(`OS ${os}, stored ${choice}`, () => {
      test.use({ colorScheme: os, contextOptions: { reducedMotion: 'no-preference' }, ...stored(choice) });

      test(`pages dissolve into each other in the ${theme} theme, Back included`, async ({ page, browser }) => {
        await logReveals(page);
        await slowMotion(page);
        await page.goto(ROUTE[0]);
        await expectTheme(page, theme, choice);
        for (const path of ROUTE.slice(1)) {
          const { reveal, frames } = await navigate(page, browser, path);
          expectDissolved(reveal, frames, path);
          expectSteady(frames, theme, path);
          await expectTheme(page, theme, choice);
        }
        const back = await navigate(page, browser, ROUTE[2], () => page.goBack({ waitUntil: 'commit' }));
        expectDissolved(back.reveal, back.frames, 'Back');
        expectSteady(back.frames, theme, 'Back');
        await expectTheme(page, theme, choice);
      });
    });
  }
}

test.describe('the back/forward cache', () => {
  test.use({ colorScheme: 'light', contextOptions: { reducedMotion: 'no-preference' }, ...stored('system') });

  test('a page restored by Back is the page that was left, and dissolves in', async ({ page, browser }) => {
    await logReveals(page);
    await slowMotion(page);
    await page.goto('/ecosystem/');
    await page.evaluate(() => { window.e2eMarker = 'still here'; });
    await navigate(page, browser, '/workflows/');
    const back = await navigate(page, browser, '/ecosystem/', () => page.goBack({ waitUntil: 'commit' }));
    expect(await page.evaluate(() => window.e2eMarker), 'restored from the back/forward cache').toBe('still here');
    expectDissolved(back.reveal, back.frames, 'Back');
    expectSteady(back.frames, 'light', 'Back');
    await expectTheme(page, 'light', 'system');
  });

  test('Back after choosing a theme on the next page shows that theme from the first frame', async ({ page, browser }) => {
    await logReveals(page);
    await slowMotion(page);
    await page.goto('/ecosystem/');
    await navigate(page, browser, '/workflows/');
    await page.locator('[data-theme-set="dark"]').click();
    await expectTheme(page, 'dark', 'dark');
    await page.waitForTimeout(150);
    const back = await navigate(page, browser, '/ecosystem/', () => page.goBack({ waitUntil: 'commit' }));
    expectSteady(back.frames, 'dark', 'Back');
    await expectTheme(page, 'dark', 'dark');
  });
});

test.describe('the system scheme changing while a page is cached', () => {
  test.use({ colorScheme: 'light', contextOptions: { reducedMotion: 'no-preference' }, ...stored('system') });

  test('Back shows the new scheme from the first frame', async ({ page, browser }) => {
    await logReveals(page);
    await slowMotion(page);
    await page.goto('/ecosystem/');
    await page.evaluate(() => { window.e2eMarker = 'still here'; });
    await navigate(page, browser, '/workflows/');
    await page.emulateMedia({ colorScheme: 'dark' });
    await expectTheme(page, 'dark', 'system');
    await page.waitForTimeout(150);
    const back = await navigate(page, browser, '/ecosystem/', () => page.goBack({ waitUntil: 'commit' }));
    expect(await page.evaluate(() => window.e2eMarker), 'the cached page was evicted and loaded afresh').toBeUndefined();
    expectSteady(back.frames, 'dark', 'Back');
    await expectTheme(page, 'dark', 'system');
  });
});

test.describe('with storage blocked', () => {
  test.use({ colorScheme: 'dark', contextOptions: { reducedMotion: 'no-preference' } });

  test('a theme chosen on a page holds there, whatever another page chooses or the system does', async ({ context }) => {
    await context.addInitScript(() => {
      for (const name of ['localStorage', 'sessionStorage']) {
        Object.defineProperty(window, name, { configurable: true, get() { throw new DOMException('The site data is blocked.', 'SecurityError'); } });
      }
    });
    const pageAt = async (path) => { const p = await context.newPage(); await p.goto(path); return p; };
    const shows = (p) => p.evaluate(() => [document.documentElement.getAttribute('data-theme'),
      document.querySelector('[data-theme-set][aria-pressed="true"]')?.dataset.themeSet]);
    const a = await pageAt('/ecosystem/');
    const b = await pageAt('/workflows/');
    const c = await pageAt('/support/');
    await a.locator('[data-theme-set="light"]').click();
    await b.locator('[data-theme-set="dark"]').click();
    await b.waitForTimeout(300);
    expect(await shows(a), 'the page where Light was chosen').toEqual(['light', 'light']);
    expect(await shows(b), 'the page where Dark was chosen').toEqual(['dark', 'dark']);

    // The page where Light was chosen waits in the back/forward cache while the system's scheme changes under a page
    // that follows it; Back restores it as it was left, Light and all.
    await a.evaluate(() => { window.e2eMarker = 'still here'; });
    await a.locator('.c-nav-link[href="/licensing/"]').click();
    await expect(a).toHaveURL(/\/licensing\/$/);
    await c.emulateMedia({ colorScheme: 'light' });
    await c.waitForTimeout(300);
    expect(await shows(c), 'the page that follows the system').toEqual([null, 'system']);
    expect(await shows(b), 'the page where Dark was chosen, after the system changed').toEqual(['dark', 'dark']);
    await a.goBack({ waitUntil: 'commit' });
    await expect.poll(() => a.evaluate(() => window.e2eMarker).catch(() => null), 'restored from the back/forward cache').toBe('still here');
    expect(await shows(a), 'the page where Light was chosen, after Back').toEqual(['light', 'light']);
  });
});

test.describe('a theme change, then straight away a page change', () => {
  for (const [os, from, to] of [['light', 'system', 'dark'], ['dark', 'system', 'light'], ['light', 'dark', 'light'], ['dark', 'light', 'system']]) {
    test.describe(`OS ${os}, ${from} to ${to}`, () => {
      test.use({ colorScheme: os, contextOptions: { reducedMotion: 'no-preference' }, ...stored(from) });

      test('the theme changes once, at the click, and holds through the dissolve', async ({ page, browser }) => {
        const before = from === 'system' ? os : from;
        const after = to === 'system' ? os : to;
        await logReveals(page);
        await slowMotion(page);
        await page.goto('/ecosystem/');
        await expectTheme(page, before, from);
        const { reveal, frames } = await navigate(page, browser, '/workflows/', () => page.evaluate((to) => {
          document.querySelector(`[data-theme-set="${to}"]`).click();
          document.querySelector('.c-nav-link[href="/workflows/"]').click();
        }, to).catch(() => {}));
        expect(reveal).toMatchObject({ path: '/workflows/', vt: true });
        // One change: frames up to the click may show the old theme; from the first in the new one, all are new.
        const first = frames.findIndex((f) => themeOf(f.l) === after);
        expect(first, 'a frame in the new theme').toBeGreaterThanOrEqual(0);
        expectSteady(frames.slice(first), after, 'after the click');
        await expectTheme(page, after, to);
      });
    });
  }
});

test.describe('a theme change while a page dissolves in', () => {
  test.use({ colorScheme: 'light', contextOptions: { reducedMotion: 'no-preference' }, ...stored('light') });

  test('cuts the dissolve short, so the old snapshot never shows beside the new theme', async ({ page, browser }) => {
    await logReveals(page);
    await slowMotion(page);
    await page.goto('/ecosystem/');
    let clicked = 0;
    const { reveal, frames } = await navigate(page, browser, '/workflows/', async () => {
      await page.locator('.c-nav-link[href="/workflows/"]').click();
      await expect.poll(async () => { const r = await lastReveal(page); return Boolean(r && r.path === '/workflows/' && r.ran); }).toBe(true);
      clicked = Date.now();
      await page.evaluate(() => document.querySelector('[data-theme-set="dark"]').click());
      await page.waitForTimeout(600); // a theme change takes 50 to 200ms to reach the screen in headless Chromium
    });
    expect(reveal).toMatchObject({ path: '/workflows/', vt: true, ran: true });
    expect(reveal.done - clicked, 'the 960ms dissolve ended at the click').toBeLessThan(300);
    const first = frames.findIndex((f) => themeOf(f.l) === 'dark');
    expect(first, 'a frame in the new theme').toBeGreaterThanOrEqual(0);
    expectSteady(frames.slice(first), 'dark', 'after the click', 1);
    await expectTheme(page, 'dark', 'dark');
  });
});

test.describe('at full speed', () => {
  test.use({ colorScheme: 'dark', contextOptions: { reducedMotion: 'no-preference' }, ...stored('system') });

  test('the page cross-fades in 240ms while the new header holds still and the old one fades from under it', async ({ page, browser }) => {
    await logReveals(page);
    await page.goto('/ecosystem/');
    const { reveal, frames } = await navigate(page, browser, '/workflows/');
    expect(reveal).toMatchObject({ path: '/workflows/', vt: true, ran: true });
    expect(reveal.animations).toEqual(expect.arrayContaining([
      '::view-transition-old(root) -ua-view-transition-fade-out 240',
      '::view-transition-new(root) -ua-view-transition-fade-in 240',
      '::view-transition-old(site-header) psc-fade-out 240',
    ]));
    expect(reveal.animations.filter((a) => a.startsWith('::view-transition-new(site-header)')), 'the new header does not animate').toEqual([]);
    expect(reveal.animations.filter((a) => a.includes('site-header') && a.includes('plus-lighter')), 'the headers are not blended').toEqual([]);
    expectSteady(frames, 'dark', '/workflows/');
  });
});

test.describe('reduced motion', () => {
  test.use({ colorScheme: 'dark', contextOptions: { reducedMotion: 'reduce' }, ...stored('light') });

  test('pages change at once, with no view transition', async ({ page, browser }) => {
    await logReveals(page);
    await page.goto('/ecosystem/');
    const { reveal, frames } = await navigate(page, browser, '/workflows/');
    expect(reveal).toMatchObject({ path: '/workflows/', vt: false });
    expectSteady(frames, 'light', '/workflows/', 1);
    await expectTheme(page, 'light', 'light');
  });
});

test('every page gives the header its view-transition-name and names nothing twice', async ({ page, request }) => {
  test.setTimeout(5 * 60_000);
  const xml = await (await request.get('/sitemap.xml')).text();
  const paths = [...new Set([...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]).pathname))];
  expect(paths.length).toBeGreaterThan(30);
  paths.push('/portal/login/', '/no-such-page/');
  const problems = [];
  for (const path of paths) {
    await page.goto(path, { waitUntil: 'domcontentloaded' });
    for (const menu of ['closed', 'open']) {
      if (menu === 'open') await page.evaluate(() => document.querySelector('[data-mega] button[aria-controls]')?.setAttribute('aria-expanded', 'true'));
      const names = await page.evaluate(() => [...document.querySelectorAll('*')]
        .filter((el) => el.getClientRects().length > 0)
        .map((el) => getComputedStyle(el).viewTransitionName)
        .filter((n) => n && n !== 'none'));
      const twice = names.filter((n, i) => names.indexOf(n) !== i);
      if (twice.length) problems.push(`${path} (menu ${menu}): ${[...new Set(twice)].join(', ')} named more than once`);
      if (!names.includes('site-header')) problems.push(`${path} (menu ${menu}): the header is not named`);
    }
  }
  expect(problems).toEqual([]);
});

/* ------------------------------------------------------------------------------------- the Products menu */

/**
 * Presses `trigger` and, in the same task, lists the transitions that press started on each `targets` element
 * (property, duration and delay in ms), so a slow test machine cannot miss them.
 */
const press = (page, trigger, targets) => page.evaluate(([trigger, targets]) => {
  document.querySelector(trigger).click();
  return targets.map((sel) => [...document.querySelectorAll(sel)].map((el) => el.getAnimations().map((a) => ({
    property: a.transitionProperty, duration: Math.round(a.effect.getTiming().duration), delay: Math.round(a.effect.getTiming().delay),
  }))));
}, [trigger, targets]);
const rect = (page, sel) => page.evaluate((sel) => { const r = document.querySelector(sel).getBoundingClientRect(); return { top: r.top, height: r.height }; }, sel);
const opacity = (locator) => locator.evaluate((el) => getComputedStyle(el).opacity);

for (const width of [1152, 1280, 1440]) {
  test(`the Products panel fades in and out at ${width}px, without moving the page or delaying focus`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('/workflows/');
    const button = page.locator('[data-mega] > button');
    const panel = page.locator('#mega-products');
    const main = await rect(page, 'main');
    const header = await rect(page, '[data-header]');

    const [[opening], columns] = await press(page, '[data-mega] > button', ['#mega-products', '.c-mega-product']);
    await expect(button).toHaveAttribute('aria-expanded', 'true');
    expect(opening).toEqual(expect.arrayContaining([
      { property: 'opacity', duration: 200, delay: 0 }, { property: 'translate', duration: 200, delay: 0 },
    ]));
    // The four products follow 20ms apart.
    expect(columns.map((c) => c.find((a) => a.property === 'opacity')?.delay)).toEqual([0, 20, 40, 60]);
    await expect.poll(() => opacity(panel)).toBe('1');
    expect(await panel.evaluate((el) => getComputedStyle(el).translate)).toBe('none');
    expect(await rect(page, 'main'), 'the page did not move').toEqual(main);
    expect(await rect(page, '[data-header]'), 'the header did not grow').toEqual(header);

    const [[closing]] = await press(page, '[data-mega] > button', ['#mega-products']);
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    expect(closing).toEqual(expect.arrayContaining([{ property: 'opacity', duration: 150, delay: 0 }]));
    expect(await panel.evaluate((el) => getComputedStyle(el).pointerEvents), 'a closing panel takes no pointer').toBe('none');
    await expect(panel).toBeHidden();

    // ArrowDown opens the panel and focuses its first link in the same keystroke (read by a listener on window, which
    // runs once the button's own has handled that keydown); Escape closes it and hands focus back.
    await button.focus();
    await page.evaluate(() => addEventListener('keydown', () => {
      window.e2eFocusAfterKeydown = document.activeElement === document.querySelector('#mega-products a');
    }, { once: true }));
    await page.keyboard.press('ArrowDown');
    expect(await page.evaluate(() => window.e2eFocusAfterKeydown), 'focus moved in the same keystroke').toBe(true);
    await page.keyboard.press('ArrowDown');
    expect(await page.evaluate(() => document.activeElement === document.querySelectorAll('#mega-products a')[1])).toBe(true);
    await page.keyboard.press('Escape');
    await expect(button).toBeFocused();
    await expect(button).toHaveAttribute('aria-expanded', 'false');

    // Following one of its links closes it (fading while the next page loads); the page change is held back here.
    await button.click();
    await expect.poll(() => opacity(panel)).toBe('1');
    await page.evaluate(() => addEventListener('click', (e) => e.preventDefault(), { capture: true, once: true }));
    const [[following]] = await press(page, '#mega-products a[href="/stagewatch/"]', ['#mega-products']);
    await expect(button).toHaveAttribute('aria-expanded', 'false');
    expect(following).toEqual(expect.arrayContaining([{ property: 'opacity', duration: 150, delay: 0 }]));
    await expect(panel).toBeHidden();
  });
}

test('on a phone the drawer and the Products list in it fade in and out, and nothing under them moves', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/workflows/');
  const toggle = page.locator('[data-nav-toggle]');
  const drawer = page.locator('#site-nav');
  const main = await rect(page, 'main');

  const [[opening]] = await press(page, '[data-nav-toggle]', ['#site-nav']);
  await expect(toggle).toHaveAttribute('aria-expanded', 'true');
  expect(opening).toEqual(expect.arrayContaining([{ property: 'opacity', duration: 200, delay: 0 }, { property: 'translate', duration: 200, delay: 0 }]));
  await expect.poll(() => opacity(drawer)).toBe('1');
  expect(await rect(page, 'main'), 'the drawer lies over the page').toEqual(main);

  const [[products]] = await press(page, '[data-mega] > button', ['#mega-products']);
  expect(products).toEqual(expect.arrayContaining([{ property: 'opacity', duration: 200, delay: 0 }]));
  await expect.poll(() => opacity(page.locator('#mega-products'))).toBe('1');

  const [[closing]] = await press(page, '[data-nav-toggle]', ['#site-nav']);
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  expect(closing).toEqual(expect.arrayContaining([{ property: 'opacity', duration: 150, delay: 0 }]));
  await expect(drawer).toBeHidden();
  await expect(toggle.locator('[data-nav-label]')).toHaveText('Menu');
});

/**
 * From the next frame on, notes the closed drawer's display and running animations at every frame and every resize
 * while the window is below 72rem.
 */
const watchDrawer = (page) => page.evaluate(() => {
  const nav = document.getElementById('site-nav');
  const narrow = matchMedia('(width < 72rem)');
  window.e2eDrawer = [];
  const note = (at) => {
    if (narrow.matches) window.e2eDrawer.push({ at, display: getComputedStyle(nav).display, animations: nav.getAnimations().length });
  };
  addEventListener('resize', () => note('resize'));
  const frame = () => { note('frame'); requestAnimationFrame(frame); };
  requestAnimationFrame(frame);
});
const drawerSeen = (page) => page.evaluate(() => window.e2eDrawer.splice(0));

test.describe('the drawer as the window narrows across 72rem', () => {
  test.use({ contextOptions: { reducedMotion: 'no-preference' } });

  for (const narrow of [820, 1000]) {
    test(`from 1440 to ${narrow}px the closed drawer is hidden from the first frame, after a close from it too`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.goto('/workflows/');
      await watchDrawer(page);
      const flip = async (label) => {
        await page.setViewportSize({ width: narrow, height: 900 });
        await page.waitForTimeout(400);
        const seen = await drawerSeen(page);
        expect(seen.length, `${label}: frames at ${narrow}px`).toBeGreaterThan(5);
        expect(seen.filter((s) => s.display !== 'none' || s.animations > 0), `${label}: frames that showed the closed drawer`).toEqual([]);
      };
      await flip('first narrowing');

      // The drawer opened and closed by its button fades out, and leaves nothing behind for the next narrowing.
      const toggle = page.locator('[data-nav-toggle]');
      await toggle.click();
      await expect.poll(() => opacity(page.locator('#site-nav'))).toBe('1');
      await toggle.click();
      await expect(page.locator('#site-nav')).toBeHidden();
      await expect(page.locator('[data-header]')).not.toHaveClass(/is-nav-closing/);
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.waitForTimeout(100);
      await drawerSeen(page);
      await flip('after a close from the drawer');
    });
  }
});

test.describe('the menus under reduced motion', () => {
  test.use({ contextOptions: { reducedMotion: 'reduce' } });

  test('open and close at once', async ({ page }) => {
    await page.goto('/workflows/');
    const panel = page.locator('#mega-products');
    const [[opening]] = await press(page, '[data-mega] > button', ['#mega-products']);
    expect(opening).toEqual([]);
    expect(await panel.evaluate((el) => [getComputedStyle(el).opacity, getComputedStyle(el).display])).toEqual(['1', 'block']);
    const [[closing]] = await press(page, '[data-mega] > button', ['#mega-products']);
    expect(closing).toEqual([]);
    expect(await panel.evaluate((el) => getComputedStyle(el).display)).toBe('none');

    await page.setViewportSize({ width: 390, height: 844 });
    const [[drawer]] = await press(page, '[data-nav-toggle]', ['#site-nav']);
    expect(drawer).toEqual([]);
    expect(await opacity(page.locator('#site-nav'))).toBe('1');
  });
});

test.describe('the menus without scripting', () => {
  test.use({ javaScriptEnabled: false });

  test('the Products panel opens on hover and on focus, in full, and the phone drawer sits open', async ({ page }) => {
    await page.goto('/workflows/');
    const button = page.locator('[data-mega] > button');
    const panel = page.locator('#mega-products');
    await expect(panel).toBeHidden();
    await button.hover();
    await expect(panel).toBeVisible();
    await expect.poll(() => opacity(panel)).toBe('1');
    await page.mouse.move(5, 600);
    await expect(panel).toBeHidden();
    await button.focus();
    await expect(panel).toBeVisible();
    await expect.poll(() => opacity(panel)).toBe('1');

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('#site-nav')).toBeVisible();
    expect(await opacity(page.locator('#site-nav'))).toBe('1');
  });
});
