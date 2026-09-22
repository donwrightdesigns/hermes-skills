# Deployment notes for NAS / static hosts

Practical gotchas from deploying this gallery onto a Synology NAS serving a web
folder over SSH. The general lessons apply to any appliance-style host.

## Serving

- A NAS web folder is usually served under a path (`/volume1/web/<client>/`),
  so the gallery lives at `https://host/<client>/`. Keep `thumbs/`, `full/`,
  `index.html`, `manifest.json`, `submit.php` and `_submissions/` all inside
  that one folder — the page fetches `manifest.json` relatively.
- The receiver needs a writable `_submissions/`. PHP typically runs as a
  dedicated unprivileged user (e.g. `http`, uid 1023), **not** as you:

  ```
  chmod 777  <gallery>/_submissions
  chown -R <webuser>:<webuser> <gallery>/_submissions
  ```

- Make the static assets world-readable and the scripts world-readable but not
  necessarily writable:

  ```
  chmod -R a+rX <gallery>/thumbs <gallery>/full
  chmod 644    <gallery>/index.html <gallery>/manifest.json <gallery>/submit.php
  ```

## SSH quirks worth knowing

- **SFTP is often disabled** on NAS SSH even when SSH itself works. Don't rely
  on Paramiko's `open_sftp()` — write files over the network share, or base64
  the content through a normal `exec_command`.
- **Never pipe data into `sudo -S`.** `sudo -S` reads the password from stdin,
  so it consumes the first line of your payload and then hangs waiting for a
  password that never arrives. Stage the file somewhere you *can* write, then
  `sudo mv` it into place.
- **A redirect is evaluated by the outer shell.** `sudo cmd > /root/path`
  fails, because the `>` runs unprivileged. You need
  `sudo sh -c 'cmd > /root/path'`, or write elsewhere and move it.
- **The volume root may not be writable even as root.** Creating a config file
  beside the share (`/volume1/mydir/`) can fail with `Permission denied` under
  an otherwise-working `sudo`. Put config inside a share you control.
- **Over SMB/CIFS you may be unable to delete** files in the web folder even
  when you created them (`WinError 5`). Delete over SSH instead.
- Run multi-command `&&` chains as separate calls where possible; a long chain
  behind `sudo` can fail partway and report nothing.

## Mail

`mail()` is frequently compiled in but has no transport configured on an
appliance, so it fails **silently**. Don't rely on it for the notification —
use the webhook (`NOTIFY_URL`), which is a single outbound POST and needs no
mail relay. Verify the push actually arrives (e.g. poll the ntfy topic) rather
than assuming it worked.

## Verification tooling

If the agent's built-in browser driver is unavailable, **Playwright with the
Python bindings** is a reliable substitute: it ships multi-arch browsers, gives
real touch emulation (`is_mobile`, `has_touch`), and can dispatch genuine
multi-touch gestures through CDP:

```python
cdp = context.new_cdp_session(page)
def touch(kind, points):
    cdp.send("Input.dispatchTouchEvent", {"type": kind, "touchPoints": points})

touch("touchStart", [{"x": 150, "y": 420}, {"x": 250, "y": 420}])
touch("touchMove",  [{"x": 110, "y": 420}, {"x": 290, "y": 420}])
touch("touchEnd",   [])
```

Use `ignore_https_errors=True` for self-signed certificates, which is the norm
on a NAS.