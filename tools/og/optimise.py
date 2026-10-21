"""Shrinks the Open Graph cards in place: python3 -I tools/og/optimise.py public/assets/img/og/*.png

Each card is saved twice, as full-colour PNG and as a 256-colour palette PNG (median cut, no dithering, so flat
fills and text edges stay clean), and the smaller file is kept unless the palette version strays visibly from the
original (mean channel error over 1.5). Cards over 150 KB are reported.
"""

import io
import sys

from PIL import Image, ImageChops, ImageStat

LIMIT = 150 * 1024


def encode(img):
    buf = io.BytesIO()
    img.save(buf, format="PNG", optimize=True)
    return buf.getvalue()


def main(paths):
    status = 0
    for path in paths:
        src = Image.open(path).convert("RGB")
        full = encode(src)
        pal = src.quantize(colors=256, method=Image.Quantize.MEDIANCUT, dither=Image.Dither.NONE)
        error = sum(ImageStat.Stat(ImageChops.difference(src, pal.convert("RGB"))).mean) / 3
        small = encode(pal)
        best, kind = (small, "palette") if len(small) < len(full) and error <= 1.5 else (full, "full colour")
        with open(path, "wb") as f:
            f.write(best)
        size = len(best)
        flag = "" if size <= LIMIT else "  OVER 150 KB"
        print(f"{path}  {size / 1024:.0f} KB  {kind} (palette error {error:.2f}){flag}")
        status |= size > LIMIT
    return status


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
