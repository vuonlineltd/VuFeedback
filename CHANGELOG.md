# Changelog

## 1.4.1 — 2026-10-16

- **Fix:** CSV export wrote attachment links with the review page's folder in front of
  an already-complete address (`…/plugins/vufeedback/https://…`).
- **Security:** CSV attachment links no longer include the key. A CSV gets forwarded,
  and the admin key went with it. Open attachments from the review page, or add
  `&key=…` to a link.

## 1.4.0 — 2026-10-15

- **Fix:** on WP Engine, no comment could be saved by a reviewer: PHP requested
  directly from a plugin folder cannot write files there, logged in or not
  ("Failed: storage not writable"). On WordPress, settings and comments now live in
  the database (options `vufeedback_config`, `vufeedback_pins`), and the widget talks
  to WordPress through `admin-ajax.php?action=vufeedback&op=…` instead of
  `feedback.php`. File data from 1.2–1.3.2 is imported automatically, keys included.
  Plain HTML sites are unchanged (files in `./data`).
- **Security:** WP Engine was caching `feedback.php` responses for logged-out visitors
  for 10 minutes, overriding `Cache-Control: no-store` and ignoring the key, so a
  request with no key could be served the comment list. Every read now carries a unique
  value so no cache can reuse a response, and the WordPress route (admin-ajax) is never
  cached by hosts. `feedback.php` still works on WordPress for pages cached before 1.4:
  it loads WordPress and uses the same database storage.
- The save lock on WordPress is a database row, which holds across every server a host
  routes requests to.
- Attachments stay in `wp-content/uploads/vufeedback-<random>/files/`; the settings
  page warns separately if that folder can't be written.
- Deleting the plugin now removes its database options and attachment folder
  (`uninstall.php`), as the README always said it would.
- Review page accepts `?api=` (same site only) so it uses the WordPress route.

## 1.3.2 — 2026-10-15

- **Fix:** "Failed: storage not writable" on WP Engine, which makes plugin folders
  read-only to PHP. On WordPress, settings, comments and attachments now live in
  `wp-content/uploads/vufeedback-<random>/` (uploads is writable on every host). The
  folder name is random so it cannot be guessed, and it carries an Apache deny rule
  and an `index.php` on top of the guarded `.php` data files. Plain HTML sites keep
  using `./data`.
- Data in the plugin's own `data/` folder (1.3.0/1.3.1, or 1.2 `.json`) is copied
  across automatically on first use, keys included.
- The settings page shows where comments are kept.
- No database changes: VuFeedback has never used the database.

## 1.3.1 — 2026-10-15

- **Fix:** "Failed: lock" when saving a comment on hosts with network storage
  (WP Engine and other managed hosts). Saving no longer uses PHP's `flock()`, which
  fails on NFS: the lock is now a directory (atomic on any filesystem), and every save
  writes a temporary file and renames it into place, so files are never half-written.
- Errors now say what went wrong ("storage not writable", "busy, please try again")
  instead of "lock".
- Settings > VuFeedback runs a storage check on load and says plainly whether
  comments can be saved, and if not, why.

## 1.3.0 — 2026-10-15

Renamed from **Site Feedback** to **VuFeedback**; now its own repository.

- **Security:** settings and comments are now stored as `data/config.php` and
  `data/pins.php`, which return a blank page if requested directly. 1.2 stored them
  as `.json` and relied on `.htaccess`, which nginx ignores (Herd, WP Engine), so the
  admin key, every comment and the attachment names were publicly readable. Existing
  `.json` data is converted automatically on first use and the old files deleted.
- **Security:** removed the built-in fallback keys (the same two keys shipped with
  every copy). The endpoint now refuses all requests until keys are configured, and
  never accepts an empty key.
- `data/files/` gets an `index.php` so the folder can't be listed.
- WordPress: `Update URI: false`, so a wordpress.org plugin with the same slug can
  never be offered as an "update".
- WordPress: settings page is now Settings > VuFeedback.

## 1.2.0 — 2026-09-18

Attachments on comments and replies (images, PDFs, Office documents and more).

## 1.1.0

WordPress plugin wrapper and settings page.
