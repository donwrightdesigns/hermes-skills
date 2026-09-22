#!/usr/bin/env python3
"""Build a client photo proofing gallery from a folder of photos.

Writes three things into the output directory:

    thumbs/<name>.jpg    grid thumbnails (downscaled)
    full/<name>.jpg      byte-identical copies of the originals (lightbox)
    manifest.json        the index index.html reads

Original filenames are preserved at every tier, because the delivered name
list has to line up with the photographer's catalog / Lightroom selection.

Usage
-----
    python build_gallery.py --src ~/Photos/jane --out ~/web/jane \
        --client "Jane" --session "Portrait Session"

    # then copy index.html + submit.php from templates/ into ~/web/jane

Requires: Pillow  (pip install pillow)
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import sys

try:
    from PIL import Image, ImageOps
except ImportError:  # pragma: no cover
    sys.exit("Pillow is required:  pip install pillow")

IMAGE_EXTS = (".jpg", ".jpeg", ".png", ".webp")


def natural_key(name: str):
    """Sort SHOOT_2.jpg before SHOOT_10.jpg instead of lexically."""
    return [int(t) if t.isdigit() else t.lower() for t in re.split(r"(\d+)", name)]


def human(n: float) -> str:
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024:
            return f"{n:.1f} {unit}"
        n /= 1024
    return f"{n:.1f} TB"


def du(path: str) -> int:
    return sum(
        os.path.getsize(os.path.join(dp, f))
        for dp, _, fs in os.walk(path)
        for f in fs
    )


def build(src: str, out: str, client: str, session: str,
          thumb_px: int, quality: int, strip_exif: bool) -> dict:
    if not os.path.isdir(src):
        sys.exit(f"Source directory not found: {src}")

    thumbs = os.path.join(out, "thumbs")
    full = os.path.join(out, "full")
    for d in (out, thumbs, full, os.path.join(out, "_submissions")):
        os.makedirs(d, exist_ok=True)

    files = sorted(
        [f for f in os.listdir(src) if f.lower().endswith(IMAGE_EXTS)],
        key=natural_key,
    )
    if not files:
        sys.exit(f"No images found in {src}")

    photos = []
    for i, fn in enumerate(files, 1):
        source = os.path.join(src, fn)

        # ---- thumbnails -------------------------------------------------
        im = Image.open(source)
        im = ImageOps.exif_transpose(im)          # honour camera rotation
        im = im.convert("RGB")
        w, h = im.size

        thumb = im.copy()
        thumb.thumbnail((thumb_px, thumb_px), Image.LANCZOS)
        thumb.save(
            os.path.join(thumbs, fn), "JPEG",
            quality=quality, optimize=True, progressive=True,
        )

        # ---- full size: copy, never re-encode ---------------------------
        # A photographer's export is already the web deliverable. Re-encoding
        # it only loses quality, so copy the bytes.
        if strip_exif:
            keep = im.copy()
            keep.save(os.path.join(full, fn), "JPEG", quality=95,
                      optimize=True, progressive=True)
        else:
            shutil.copy2(source, os.path.join(full, fn))

        photos.append({
            "n": i,
            "file": fn,
            "w": w,
            "h": h,
            "ar": round(w / h, 4),
            "thumb": f"thumbs/{fn}",
            "full": f"full/{fn}",
        })

    manifest = {
        "client": client,
        "session": session,
        "count": len(photos),
        "photos": photos,
    }
    with open(os.path.join(out, "manifest.json"), "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, indent=1)

    return {
        "count": len(photos),
        "thumbs": du(thumbs),
        "full": du(full),
        "total": du(out),
    }


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--src", required=True, help="folder of source photos")
    ap.add_argument("--out", required=True, help="folder to build the gallery in")
    ap.add_argument("--client", default="", help="client name (shown as the heading)")
    ap.add_argument("--session", default="", help="session label, e.g. 'Portrait Session'")
    ap.add_argument("--thumb-px", type=int, default=600, help="thumbnail long edge (default 600)")
    ap.add_argument("--quality", type=int, default=82, help="thumbnail JPEG quality (default 82)")
    ap.add_argument("--strip-exif", action="store_true",
                    help="re-encode the full-size copies to drop EXIF/GPS "
                         "(costs a little quality; default is a byte-exact copy)")
    args = ap.parse_args()

    stats = build(args.src, args.out, args.client, args.session,
                  args.thumb_px, args.quality, args.strip_exif)

    print(f"photos : {stats['count']}")
    print(f"thumbs : {human(stats['thumbs'])}")
    print(f"full   : {human(stats['full'])}")
    print(f"total  : {human(stats['total'])}")
    print()
    print("Next: copy templates/index.html and templates/submit.php into "
          f"{args.out}, then serve the folder.")


if __name__ == "__main__":
    main()