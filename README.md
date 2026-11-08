# Zero_Redirects

A log of the storefront's 404s and a 301 redirect for each in one click.
For a store after a migration, a new URL structure or products taken off
the catalog: the addresses people and search engines still ask for, and
where to send them.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-redirects
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## The log

Every storefront "page not found" (Magento's no-route page) counts a hit
for its path, per store view, with the time first and last seen and the
last page that linked to it. Not logged: static files and media, files
that aren't pages (images, scripts, fonts), and addresses containing the
configured texts (by default what vulnerability scanners try: wp-admin,
.env, …). A 404 answered from the page cache isn't rendered and isn't
counted: the log shows which addresses are missing; hits are a lower bound.

A 404 never touches the database: it appends one line to
`var/zero_redirects/404.log` (about 20 µs). Cron, every 5 minutes, folds
the file into the log table (one row per store view and path, written in
batches) and works out the suggestions; opening the 404 Log page does the
same first, so the list is current without cron. A file a crashed run
took but never finished (`404.log.<pid>.<random>`, untouched for 10
minutes) is read by the next run first. Limits, so a flood of
404s (a scanner, a broken link on a busy page) can't grow without end: the
file stops taking lines at 20 MB until cron empties it, and the log keeps
at most 20,000 open addresses (the least asked for and longest unseen
go first).

Open rows nobody asked for in 90 days (configurable) leave the log (cron,
daily); redirected and ignored ones stay.

## Marketing > SEO & Search > 404 Log

Most asked for first, filtered by status (open, redirected, ignored) and
by address. For an open one:

- **Redirect**: a 301 to a path (`/new-page.html`) or another site's URL,
  saved as a Magento URL rewrite (custom, permanent), so Marketing >
  URL Rewrites shows and edits it like any other. The cached 404 page is
  cleaned, so the address answers with the redirect at once.
- **A suggestion**: the store view's product, category or CMS page URL
  most like the missing one (`joust-dufle-bag.html` → `joust-duffle-bag.html`),
  filled in as the target when one is close. Worked out once per address
  by cron, not each time the page opens.
- **Ignore**: out of the open list (and back with Reopen).

## Settings

Logging is **off** until switched on: with the button on the 404 Log page
(store-wide), or in Stores > Configuration > Zero > 404 Log and Redirects
(also per website or store view), with the texts not to log and how long
open 404s stay. Redirects already made keep working with logging off:
they are URL rewrites.

## Tests

`dev/tests/zero-e2e/tests/redirects-admin.spec.ts` (project
`redirects-admin`): a logged 404 with its hits and referrer, the suggested
target, Redirect answering 301 at once, Ignore.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

Zero is made by [Fixstacks](https://fixstacks.com).
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
