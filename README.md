# VuFeedback

On-page feedback for client review, by Vu Digital. Reviewers click anywhere on a page,
leave a comment, and a numbered pin stays attached to that spot. You work through the
comments on a review page. It works on WordPress and on plain HTML sites, with no
external services, no accounts and no database.

## What's in the folder

| File | Purpose |
|---|---|
| `vufeedback.php` | WordPress plugin: loads the widget and adds **Settings > VuFeedback**. Not used on plain HTML sites. |
| `feedback.js` | The widget. Draws inside a shadow root so it never clashes with the site's CSS. Does nothing for visitors without a key. |
| `feedback.php` | The API for plain HTML sites. On WordPress it loads WordPress and gives the same answers as the WordPress route. |
| `api.php` | The request handler shared by both routes. |
| `slack.php` | WordPress only: posts new comments to the site's Slack channel. |
| `storage.php` | Reads and writes settings and comments: the database on WordPress, files on a plain HTML site. |
| `review.html` | Your triage view: filter, change status, delete, jump to each pin on its page, CSV export. |
| `uninstall.php` | Removes VuFeedback's settings, comments and attachments when the plugin is deleted. |
| `data/` | Plain HTML sites only: settings, comments and uploads. Must be writable by PHP. Never committed. |

### Where the data lives, and how it's protected

**WordPress:** settings and comments are in the database (options `vufeedback_config` and
`vufeedback_pins`), and the widget reaches them through WordPress's own
`admin-ajax.php?action=vufeedback`, which hosts never cache. Attachments go in
`wp-content/uploads/vufeedback-<random>/files/` under random names that are only listed in
the protected comments, and are served through the API after a key check. This is the
setup that works on WP Engine, where PHP files requested directly from a plugin folder
cannot write files and plugin responses get cached.

**Plain HTML:** settings and comments are JSON stored in `data/config.php` and
`data/pins.php`. Each file starts with `<?php exit; ?>`, so requesting it from a browser
returns a blank page on any server. Uploads sit in `data/files/` under random names.

Every read the widget makes carries a unique value, so no cache can hand one visitor's
response to another.

## Install on WordPress

1. Upload the folder as `wp-content/plugins/vufeedback/` (zip it and use Plugins > Add New >
   Upload, or deploy it with the site).
2. Activate **VuFeedback**. Keys are generated for that site.
3. **Settings > VuFeedback** shows the client link, your admin link and a button to the review
   page, and lets you regenerate keys or switch the widget off. A red notice appears if `data/`
   isn't writable.
4. When the review is over, untick **Enabled** (or deactivate). Comments stay in `data/` until
   the plugin is deleted.

Upgrading from an earlier VuFeedback or Site Feedback: replace the plugin's files. Existing
file data (the plugin's `data/` folder or `uploads/vufeedback-…/`) is imported into the
database on first use, keys included. From Site Feedback, copy its `data/` folder into
`vufeedback/data/` first.

## Slack notifications (WordPress)

Each site can post a nod to its Slack channel when a reviewer adds a comment or a reply.

1. In Slack, open the site's channel → **Integrations** (or channel settings) → **Add an
   app** → **Incoming Webhooks** → choose the channel → copy the **Webhook URL**.
2. Paste it into **Settings > VuFeedback > Slack**, tick **Save and send a test message**.

The first comment is posted at once; anything else within five minutes becomes one
summary message. Our own comments (made with the admin key) are skipped unless you untick
that option.

Security: the webhook URL never leaves the server (the settings page only shows its last
four characters), and only `hooks.slack.com` addresses are accepted. What reviewers type
is posted as plain text, so it can't @channel or mention anyone. Messages link to the page
on the site, never to a key. If a webhook URL ever leaks, remove it in Slack and paste a
new one; **Disconnect** stops posting immediately.

Plain HTML sites don't have this: the webhook would have to sit in a file on the server.

## Install on a plain HTML site

1. Upload the folder to the site root as `/feedback/` (leave out `vufeedback.php`).
2. Open `feedback.php` and set `HTML_REVIEW_KEY` and `HTML_ADMIN_KEY` to fresh values
   (`openssl rand -hex 8`). Until both are set, the API refuses every request.
3. Add one line before `</body>` on every page:
   ```html
   <script src="feedback/feedback.js" defer></script>
   ```
   Paths are relative to the script, so a subfolder install works unchanged.
4. Make `feedback/data/` writable (755 and owned by PHP, or 775).
5. Check: `https://site/feedback/feedback.php?action=list&key=ADMIN_KEY` returns
   `{"pins":[],"admin":true}`, and `https://site/feedback/data/config.php` returns a blank page.

## Links to hand out

| Who | URL |
|---|---|
| Client | `https://site/any-page?review=CLIENT_KEY` |
| You, on the page (status and delete) | `https://site/any-page?review=ADMIN_KEY` |
| You, the review page | WordPress: the **Open review page** button in Settings > VuFeedback; HTML: `/feedback/review.html?key=ADMIN_KEY` |
| Switch off in a browser | `?review=off` |

The key is remembered in that browser after the first visit, so reviewers can move around
the site normally. Caching is fine: the key is handled in the browser, not by PHP.

## For the client

Open the review link. A **Feedback** button sits bottom right on every page. Click it, then
click the spot you want to comment on. Add your name and comment, choose Change, Bug or
Question and a priority, and save. A numbered pin stays on the page; click it to read or
reply. The ☰ button lists every comment on the page, and ✓ hides resolved ones. Nothing you
add is visible to the public.

**Sending files:** every comment and reply has *Attach images or documents*, for a new
photo, logo, PDF or a Word document of replacement copy, pinned to the spot it belongs.

## Working the list

`review.html` groups comments by page. Each card shows the comment, author, the text of the
element it's pinned to, the screen size, and **Open on page**. Set status to In progress or
Resolved as you go; the client sees the colour change on their next visit. **Export CSV**
gives you the list as a spreadsheet.

The API (`…/admin-ajax.php?action=vufeedback&op=list&key=ADMIN_KEY` on WordPress,
`feedback.php?action=list&key=ADMIN_KEY` on HTML) returns JSON including each pin's CSS
selector, which is enough for a developer or an AI assistant to find and act on each comment.

## API reference

WordPress: `admin-ajax.php?action=vufeedback&op=<op>`. Plain HTML: `feedback.php?action=<op>`.
Every call takes the key as header `X-Feedback-Key` or `?key=`. Write calls are POST with a
JSON body.

| Op | Body | Key |
|---|---|---|
| `list` | | client |
| `add` | `{ "pin": {...} }` | client |
| `reply` | `{ "id", "author", "text" }` | client |
| `status` | `{ "id", "status": "open\|progress\|resolved" }` | admin |
| `delete` | `{ "id" }` | admin |
| `upload` | multipart form: `id`, `author`, `files[]` | client |
| `file&f=<fileid>` | GET; add `&dl=1` to download | client |
| `unattach` | `{ "id", "file" }` | admin |

Uploads: images (jpg, png, gif, webp, svg), pdf, Office documents, txt/md/rtf/csv, zip,
mp4/mov and design files (ai, eps, psd, indd, sketch, fig). 25 MB per file (`MAX_FILE` in
`feedback.php`); the server's `upload_max_filesize` and `post_max_size` must allow it too.

## Notes and limits

Pins attach by CSS selector plus an offset inside the element, falling back to page
coordinates. If the element is removed or restructured, the pin turns dashed and says
"position approximate"; the saved text snippet still shows what was meant.

One key per audience, not per person: the name field records who said what, and reviewers
can see and reply to each other's comments. No screenshots are taken; the selector, snippet,
screen size and browser are usually enough.

## Removal

WordPress: deactivate and delete the plugin (this deletes its comments and uploads).
HTML: remove the script tag and the `/feedback/` folder.
