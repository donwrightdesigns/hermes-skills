# Hermes skills

A small collection of [Hermes Agent](https://github.com/NousResearch/hermes-agent)
skills. Add this repo as a tap and install any of them.

```bash
hermes skills tap add donwrightdesigns/hermes-skills
hermes skills search proofing
hermes skills install donwrightdesigns/hermes-skills/proofing-gallery
```

## Skills

### `proofing-gallery`

Let a client browse a photo set, tap the ones they want, and send you the
filenames — no account, subscription, database, or third-party service.

You build a **static gallery plus one small PHP receiver**, drop both on any
host that serves files and runs PHP, and hand the client a URL. Their picks
come back as a plain list of original filenames that feeds straight into
Lightroom (Photo List Importer) or any tool that takes a name list.

Shipped with:

- `scripts/build_gallery.py` — builds the thumbnail grid, byte-exact
  full-size copies, and the manifest
- `templates/index.html` — the gallery page (tap to select, pinch/drag
  lightbox with zoom)
- `templates/submit.php` — the ~40-line receiver that writes the name lists
  and fires an optional webhook
- `references/static-host-notes.md` — deployment gotchas for NAS/static hosts

Works on a Synology/QNAP web folder, shared hosting, or any box with PHP. The
gallery page itself is pure static HTML/CSS/JS.

## Installing manually

Copy a skill directory into `~/.hermes/skills/`:

```bash
cp -r skills/proofing-gallery ~/.hermes/skills/
```

## License

MIT — see [LICENSE](LICENSE).