# Brand icons and Open Graph cards

**Regenerate the Open Graph cards** (`public/assets/img/og/*.png`, 1200 × 630, each under 150 KB) after changing a
headline, a product's name or role, a StageWatch price or the workflow list, an Industry Updates category's title or
artwork (its card is `news-{key}`), or after adding an `og` image to `content/pages.php`:

```sh
(cd tools/e2e && npm ci)        # once: Playwright and its Chromium
node tools/og/build.mjs         # every card; or only some: node tools/og/build.mjs home news-video
```

It needs Node 20+, PHP (to read `content/`) and Python 3 with Pillow (to optimise). It starts its own static server
on a free port, renders `template.html` with the site's CSS, fonts and icon sprite, and fails if a page names an
`og` image that has no card in `build.mjs`.

**Regenerate the favicons and app icons** (`public/favicon.svg`, `public/favicon.ico`, `public/assets/img/brand/*`)
after changing the brand mark or its colours: `python3 -I tools/og/brand.py`.

| File | What it does |
|---|---|
| `build.mjs` | The card list (headline, line, visual) and the renderer |
| `template.html` | The card frame and the drawn product screens |
| `optimise.py` | Keeps the smaller of a full-colour and a 256-colour PNG, if the palette one is visually identical |
| `brand.py` | Draws the brand mark on whole pixels at every icon size |
