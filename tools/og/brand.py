"""Builds the brand icons from the brand mark (docs/DESIGN.md 8.3 and 8.33): run `python3 -I tools/og/brand.py`.

The mark is the seven SMPTE 75% bars over a dark strip that holds one short light segment, as `.c-brand-mark`
draws it in site.css (28 x 22 units: bars 16 units tall, a 1-unit gap, the light segment 5 units tall and 9 wide).
Every size is laid out on whole pixels so the bars stay crisp; only tile corners are anti-aliased (drawn at 4x and
reduced). Writes public/favicon.svg, public/favicon.ico (16, 32, 48) and public/assets/img/brand/*.png.
"""

from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[2]
BRAND = ROOT / "public" / "assets" / "img" / "brand"

TILE = (9, 12, 16)  # --bg in the dark theme, #090C10
LIGHT = (232, 236, 241)  # --text in the dark theme, #E8ECF1
BARS = [  # the seven 75% bars, in order: grey, yellow, cyan, green, magenta, red, blue
    (191, 195, 200), (232, 220, 44), (24, 200, 216), (34, 209, 99), (224, 82, 208), (255, 64, 64), (91, 123, 255),
]
SS = 4  # supersampling for the rounded corners


def mark(draw, x, y, bar_w, bars_h, gap, light_h, light_w, s=1):
    """Draws the mark with its top-left corner at (x, y); every measure is in output pixels, scaled by s."""
    for i, colour in enumerate(BARS):
        draw.rectangle([(x + i * bar_w) * s, y * s, (x + (i + 1) * bar_w) * s - 1, (y + bars_h) * s - 1], fill=colour)
    ly = y + bars_h + gap
    draw.rectangle([x * s, ly * s, (x + light_w) * s - 1, (ly + light_h) * s - 1], fill=LIGHT)


def icon(size, radius, layout, full_bleed=False):
    """A square icon: a dark tile (rounded unless full bleed) with the mark; layout = mark() arguments."""
    big = Image.new("RGBA", (size * SS, size * SS), (0, 0, 0, 0) if not full_bleed else TILE + (255,))
    d = ImageDraw.Draw(big)
    if not full_bleed:
        d.rounded_rectangle([0, 0, size * SS - 1, size * SS - 1], radius=radius * SS, fill=TILE + (255,))
    mark(d, *layout, s=SS)
    return big.resize((size, size), Image.Resampling.BOX)


def proportional(size, unit):
    """The mark at the site's proportions (28 x 22 units), centred on whole pixels."""
    w, h = 28 * unit, 22 * unit
    x, y = (size - w) // 2, (size - h) // 2
    return (x, y, 4 * unit, 16 * unit, unit, 5 * unit, 9 * unit)


def main():
    BRAND.mkdir(parents=True, exist_ok=True)

    # Favicons: tuned per size so bars, gap and light segment land on whole pixels (the light segment is two bars).
    ico = {
        16: icon(16, 3, (1, 3, 2, 7, 1, 2, 4)),
        32: icon(32, 6, (2, 6, 4, 14, 2, 4, 8)),
        48: icon(48, 9, (3, 9, 6, 21, 3, 6, 12)),
    }
    ico[48].save(ROOT / "public" / "favicon.ico", format="ICO", sizes=[(16, 16), (32, 32), (48, 48)],
                 append_images=[ico[16], ico[32]])

    # Touch and app icons. iOS masks the touch icon itself, so it is full bleed; the "any" icons are rounded tiles;
    # the maskable icon keeps the whole mark inside the 80% safe circle (half-diagonal 196 px < 204.8 px).
    outputs = {
        "apple-touch-icon.png": icon(180, 0, proportional(180, 4), full_bleed=True),
        "icon-144.png": icon(144, 27, proportional(144, 4)),
        "icon-192.png": icon(192, 36, proportional(192, 5)),
        "icon-512.png": icon(512, 96, proportional(512, 13)),
        "icon-maskable-512.png": icon(512, 0, proportional(512, 11), full_bleed=True),
        "logo-512.png": icon(512, 0, proportional(512, 12), full_bleed=True),
    }
    for name, img in outputs.items():
        img.save(BRAND / name, format="PNG", optimize=True)

    # The SVG favicon: the 32 px layout on a 32-unit grid, so every edge is a whole pixel at 16 and 32 px.
    hexes = ["#%02X%02X%02X" % c for c in BARS]
    bars = "".join(f'<rect x="{2 + 4 * i}" y="6" width="4" height="14" fill="{c}"/>' for i, c in enumerate(hexes))
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
        '<rect width="32" height="32" rx="6" fill="#090C10"/>'
        f'<g shape-rendering="crispEdges">{bars}<rect x="2" y="22" width="8" height="4" fill="#E8ECF1"/></g>'
        '</svg>\n'
    )
    (ROOT / "public" / "favicon.svg").write_text(svg, encoding="utf-8")

    for path in [ROOT / "public" / "favicon.ico", ROOT / "public" / "favicon.svg", *sorted(BRAND.glob("*.png"))]:
        print(f"{path.relative_to(ROOT)}  {path.stat().st_size:,} bytes")


if __name__ == "__main__":
    main()
