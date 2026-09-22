---
name: proofing-gallery
description: Use when a client needs to pick favorites from photos.
version: 1.0.0
author: Don Wright
license: MIT
platforms: [linux, macos, windows]
metadata:
  hermes:
    tags: [photography, gallery, proofing, static-site, php, lightroom, client-delivery]
---

# Client photo proofing gallery

Let a client browse a photo set, tap the ones they want, and send you the
filenames — with no account, subscription, database, or third-party service.

You build a **static gallery plus one small PHP receiver**, drop both on any
host that serves files and runs PHP, and hand the client a URL. When they hit
send, you get a plain list of filenames.

## When to use

- A client needs to cull, select, or approve photos before you retouch.
- You want the result as filenames — not a screenshot, not a reply in a chat.
- You already have web hosting (or a NAS serving a web folder).
- You don't want a SaaS proofing plan for one delivery.

## Why filenames matter

The whole point is the output. The client's picks come back as original
filenames, so they line up with your catalog:

| file | use |
|---|---|
| `*.names.txt` | bare filenames, one per line |
| `*.names.csv` | same, with a `Filename` header |
| `*.txt` | readable summary (client, session, count, time) |
| `submissions.jsonl` | append-only machine log |

The `.names.txt` / `.names.csv` pair drops straight into
**Lightroom Statistics → Photo List Importer** to select the picks inside
Lightroom. Any similar tool that consumes a name list works too.

This is why you must **never renumber the photos**. `SHOOT_8678.jpg` stays
`SHOOT_8678.jpg` in the grid, in the full-size view, and in the list.

## Procedure

### 1. Build the image tiers

```bash
python scripts/build_gallery.py \
    --src  ~/Photos/jane \
    --out  ~/web/jane \
    --client "Jane" --session "Portrait Session"
```

That produces `thumbs/` (grid), `full/` (lightbox) and `manifest.json`.

**Two tiers only.** A photographer's export is usually already web-sized
(3000px at quality 75 is a normal Lightroom web export), so it *is* the web
deliverable. The script copies those originals byte-for-byte into `full/`
rather than re-encoding. Re-encoding a finished export only throws away
quality. Thumbnails are the only images worth generating.

### 2. Drop in the page and receiver

```bash
cp templates/index.html templates/submit.php ~/web/jane/
```

### 3. Configure

- `templates/index.html` — the `CONFIG` block at the top of the `<script>`:
  `brand`, `client`, `session`, `email`. Anything left empty falls back to
  `manifest.json`.
- `templates/submit.php` — the constants at the top: `RECIPIENT` (email the
  picks go to; `''` skips email), `FROM_ADDRESS`, and `NOTIFY_URL` (optional
  webhook, e.g. `https://ntfy.sh/<your-topic>`).

Keep the webhook URL **inside `submit.php`**, not in a sidecar file. PHP is
executed, never served, so the source stays private — any file in a public web
root is fetchable by anyone.

### 4. Make the receiver writable

`submit.php` writes into `_submissions/` next to itself, so the web server user
must be able to write there:

```bash
mkdir -p ~/web/jane/_submissions
chmod 777 ~/web/jane/_submissions      # or chown it to the web server user
```

### 5. Test for real

Post a payload and confirm the files appear:

```bash
curl -X POST https://example.com/jane/submit.php \
  -H 'Content-Type: application/json' \
  -d '{"client":"Jane","session":"Portrait","count":2,
       "photos":["SHOOT_8678.jpg","SHOOT_8694.jpg"]}'
```

Expect `{"ok":true,"count":2,...}` and two name lists in `_submissions/`.

## Alternatives when PHP isn't available

Any host that runs PHP is the simplest case (the receiver is ~40 lines and has
no dependencies). If you're on purely static hosting:

- Point the page's submit at a form-relay endpoint (FormSubmit, Web3Forms,
  Netlify Forms, or a serverless function) instead of `submit.php`. Most need
  either a one-time activation click or a free key.
- Or leave `email` empty in `CONFIG`: the page then falls back to showing the
  client their list with a **Copy** affordance, and they paste it to you.

The gallery itself is pure static HTML/CSS/JS, so it runs anywhere.

## Verification

Serve it and actually exercise it — this page is used on **phones**, which is
where it breaks:

- Load at a mobile viewport (`390x844`, touch enabled). Assert there is **no
  horizontal overflow** (`scrollWidth - innerWidth == 0`).
- Tap tiles using **real coordinates**, not `element.click()`. Programmatic
  clicks bypass hit-testing and will pass even when an overlay is swallowing
  every tap.
- Pinch, drag, and double-tap the lightbox; confirm zoom, pan, and reset.
- Submit end to end and confirm the files land on disk.
- **Look at the screenshots.** Metrics alone will not tell you that the grid
  reads wrong.

Playwright works well and gives you genuine touch/pinch input via CDP
`Input.dispatchTouchEvent`.

## Pitfalls

- **A full-bleed `::after` overlay swallows every tap.** A decorative
  pseudo-element with `inset:0` and default `pointer-events` paints above the
  image, so clicks land on the parent and never reach the `<img>` your handler
  is bound to. Set `pointer-events:none` on decorative overlays *and* bind the
  click handler to the tile rather than the image.
- **Don't gate image visibility on a one-shot `onload`.** If the full-size
  image is already cached it finishes loading *before* the state that should
  reveal it, so the check never re-runs. Recompute visibility from current
  state on every state change instead.
- **A touch double-tap also fires a synthesised `dblclick`.** Handle one and
  ignore the other, or the action runs twice and cancels itself out.
- **Unselected tiles must read as empty.** A visible checkmark on unselected
  tiles makes the entire grid look pre-selected. Use a hollow ring.
- **One column in portrait on phones.** Two columns makes each photo too small
  to judge — which defeats the purpose of a proofing gallery. Keep the sticky
  action bar to a single line; a block-level tally wraps it to three.
- **Landscape phones need their own breakpoint.** A landscape phone is ~844px
  wide, so a width breakpoint alone misses it and it falls through to the
  desktop rule. Key it on `orientation:landscape` + `max-height:520px`.

## Files

- `scripts/build_gallery.py` — image tiers + `manifest.json`
- `templates/index.html` — the gallery page (configure the `CONFIG` block)
- `templates/submit.php` — the receiver (configure the constants)
- `references/static-host-notes.md` — deployment gotchas for NAS/static hosts