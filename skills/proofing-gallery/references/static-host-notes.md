# Deployment notes for NAS / static hosts

Practical gotchas from deploying this gallery onto a Synology NAS serving a web
folder over SSH. The general lessons apply to any appliance-style host.

## Serving

- A NAS web folder is usually served under a path (`<share>/web/<client>/`), so
  the gallery lives at `https://host/<client>/`. Keep `thumbs/`, `full/`,
  `index.html`, `manifest.json`, `submit.php` and `_submissions/` all inside
  that one folder — the page fetches `manifest.json` relatively.
- The receiver needs a writable `_submissions/`. PHP typically runs as a
  dedicated unprivileged user (often `http`, `www-data` or `nobody`) — **not**
  as you. Give that user **ownership** of the directory:

  ```bash
  mkdir -p <gallery>/_submissions
  chown -R <webuser>:<webuser> <gallery>/_submissions
  ```

  Find out which user it is before guessing (inspect the running PHP or web
  worker process). Prefer ownership over widening permissions: the receiver
  only ever needs a single writer, and a world-writable directory is a poor
  habit even for a throwaway gallery.
- Static assets need to be readable by the web server, and nothing more:

  ```bash
  chmod -R a+rX <gallery>/thumbs <gallery>/full
  chmod 644    <gallery>/index.html <gallery>/manifest.json <gallery>/submit.php
  ```

## Driving an appliance host over SSH

These hosts are appliances, not general-purpose servers, and the usual
remote-administration conventions have sharp edges:

- **SFTP is often disabled** even when SSH itself works. Don't rely on an SFTP
  client — write files over the network share, or base64 the content through a
  normal command channel.
- **Elevated commands read the password from stdin.** If you also pipe a payload
  in, that payload is consumed as the password and the command hangs with no
  useful error. Never combine "pipe some data" and "authenticate" in one
  invocation. Stage the file somewhere you can already write, then move it into
  place with elevated privileges.
- **A redirect is evaluated by the shell that parses it**, not by the program
  you elevate. Writing to a privileged path with a plain redirection fails,
  while the command itself appears to succeed. Write elsewhere and move the
  file instead.
- **The volume root may not be writable even by root.** Creating a file beside a
  share can fail with `Permission denied` in an otherwise-working elevated
  session. Put configuration inside a share you control.
- **Over SMB/CIFS you may be unable to delete** files in the web folder even
  when you created them. Delete over SSH instead.
- Run multi-statement command chains as separate calls where you can — a long
  chain behind an elevated session can fail partway and report nothing useful.

## Mail

Mail delivery is frequently compiled in but has no transport configured on an
appliance, so the send call fails **silently**. Don't rely on it for the
notification — use the webhook (`NOTIFY_URL`), which is a single outbound POST
and needs no mail relay. Verify the push actually arrives (poll the target)
rather than assuming it worked.

## Verification tooling

If a built-in browser driver is unavailable, **Playwright with the Python
bindings** is a reliable substitute: it ships multi-arch browsers, gives real
touch emulation (`is_mobile`, `has_touch`), and can dispatch genuine multi-touch
gestures through CDP:

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