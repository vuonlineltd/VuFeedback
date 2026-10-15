# Changelog

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
