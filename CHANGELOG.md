# Changelog

All notable changes to Pharos. The format follows [Keep a Changelog](https://keepachangelog.com/);
versions follow [SemVer](https://semver.org/). The signed manifest at
`https://pharos.solutionmax.net/releases/latest.json` points at the newest published release.

## [Unreleased]

## [0.7.0] — 2026-09-23

Several status pages from one installation, each with its own roles and API tokens; a rebuilt admin with an Overview and global search; scheduled maintenance; and a new installer journey.

### Added
- Multiple status pages from one installation. Each page has its own services, components, incidents, subscribers, branding, email settings, mail templates and notification destinations. The first page keeps `/` and every existing API and subscription link; extra pages live at `/status/{slug}` and can be draft, published or archived. Archiving keeps the data and stops publishing, monitoring and notifications for that page. A page can have its own domain; DNS and TLS are set up outside Pharos.
- Page roles: give a user Read only, Editor or Page admin on each page, next to global administrators. Read only users never see check targets or integration credentials. Lowering or removing a role takes effect at once, also in sessions that are already open.
- API tokens belong to one owner and one page and have Read or Write access. Page scoped routes live under `/api/v1/pages/{slug}/`; the existing `/api/v1/` routes keep serving the first page. Write tokens also need the owner's current edit rights, and revoking page access revokes the token's rights with it.
- Page limit per plan: Free and Brand pack run 1 status page, Supported up to 5, the Commercial licence has no limit. The Status pages overview says how many pages are in use before you reach the limit. When a yearly key lapses, branding and the pages that are already active keep working; creating or reactivating pages beyond 1 is blocked.
- New admin navigation grouped into This page and Installation, with expandable sections that only list what you may open, breadcrumbs, and a page selector that shows each page's current status in words. Status pages are shown as cards with live status, 90 day uptime, open incidents and subscribers.
- Overview, the new landing screen per page: live status, key figures, 90 day availability, services, the open incident, page health and a Get this page ready checklist.
- Global search as a command palette (Ctrl K or Cmd K): pages, services, components, incidents, maintenance windows, users (administrators only), admin screens by keyword and quick actions, grouped with status and limited to the pages you hold a role on. It remembers recent searches per account and opens as a full screen sheet on phones.
- Scheduled maintenance per page with affected components. Subscribers and destinations get an announcement a chosen time ahead, the components switch to Under maintenance at the start and go back to their previous status at the end, unless someone changed them in the meantime. Cancelling a running window restores them too. Failing checks do not open outages during a running window. The public page lists upcoming and ongoing maintenance, and a new mail template carries the announcement.
- Incidents workspace: open incidents lead as cards with status, impact, affected components and the latest updates, with Post update inline and a one click Resolve; resolved incidents follow as searchable history. Reporting an incident takes three steps with a live preview of the public card and can start from a template. Incident templates can be created, edited and deleted in the admin and share their slug with the API.
- Components grouped by service, with 30 day cells, uptime and who sets each status: checked by Pharos (HTTP, TCP or heartbeat) or set from outside (Uptime Kuma, API, upstream). Editors change a status right in the row; every change is recorded in the audit log.
- Integrations split into four screens: Send out, Bring in, API tokens and Delivery log. Send out adds a destination in numbered steps with save and test and shows the health of each destination; Bring in has ready to copy instructions for n8n, Uptime Kuma, scripts and heartbeats that follow the chosen component and status; a new token is shown once; the Delivery log has counters and filters by destination, channel and result. The old Integrations address redirects to the matching screen.
- Choose which events each destination receives: incident opened, update posted, resolved, and maintenance. Destinations saved before this release keep receiving every event, maintenance included.
- Uptime Kuma endpoint `POST /api/v1/integrations/kuma/{component}` (plus a page scoped variant) that maps Kuma's states: up to Operational, down to Major outage, maintenance to Under maintenance; pending leaves the component alone. The previous Kuma address keeps working.
- Official partner logos: the Slack and Microsoft Teams marks are shown unaltered, and Discord, Telegram, Signal, n8n and Uptime Kuma get their real glyphs.
- Users screen with search, filter chips, role, two factor state, page roles and last seen. New accounts can be invited by email with a set password link that is valid for three days and can be resent; typing a password yourself still works. Administrators can require an account to turn on two factor at its first sign in.
- Profile with the devices you are signed in on: sign out one session or all others. Plus a Light, System or Dark theme preference and a personal time zone for the admin screens; public pages, emails, webhooks and the API keep the installation time zone.
- New sign in and two factor screens: floating labels, show password, a stay signed in switch, six digit code boxes and a recovery code toggle. Without JavaScript every form posts as before, and nothing from your status data is shown on these public screens.
- Branding screen with a live preview of the browser tab, the page header in light and dark and the email header, drop zones for logos and the favicon, and a plan card that shows what this installation has and where to buy a plan or request a commercial quote. Locked Brand pack sections say what unlocks them.
- The web installer follows a seven step journey (Unlock, Check the server, Download, Configure, Scheduler, Your account, Done) with live progress. Pharos itself now finishes steps 6 and 7: name the page and create the first administrator with a password strength meter, then a done screen with version, address, database and scheduler state. The installer notices the first scheduler run by itself.
- Audit log filters by person, page and action; the CSV export follows the same filters, and each change reads as the old value, an arrow and the new value.

### Changed
- Settings, Subscribers and Updates follow the new navigation and use the shared cards, counters and state pills; Settings sections moved from tabs into the sidebar.
- New pages start with subscriptions off; existing pages keep their setting. The Subscribers switch names the page it applies to.
- Tokens created in the admin default to Read. Existing tokens and tokens made with `php artisan pharos:token` keep Write, and tokens without an owner only work on the first page.
- Interface text, default email subjects and default email bodies no longer use dashes or hyphenated words (email, sign in, two factor, built in).
- Signing in, or opening the sign in page while signed in, lands on the Overview.

### Fixed
- An ICO favicon was always refused on upload.
- An expired licence no longer shows as running out soon with a negative number of days.
- Activating a key names the plan it unlocks instead of always saying Brand pack activated.
- The quick theme toggle no longer flashes the system theme before applying your choice, on the admin, installer and public status pages.
- SQLite now waits up to 5 seconds for another writer instead of failing at once with "database is locked" (for example an API call arriving while checks run). Set `DB_BUSY_TIMEOUT` to change it.

### Security
- HSTS: `Strict-Transport-Security` with a one year max age is sent on HTTPS requests only, without `includeSubDomains` or `preload`. Behind a TLS proxy this needs `TRUSTED_PROXIES`; a header the proxy sets itself still wins.
- Session fixation: with two factor on, the session id is now renewed right after the password step instead of only after the code.
- Signing out other sessions also rotates the remember token, and sessions are addressed by a hash, never by their raw id.
- Page scope is enforced on routes, model binding, background jobs, notifications and API tokens, not only in the interface.

### Upgrade
- Ten new database migrations: status pages, page tags, page roles, token scopes, delivery page and events per destination, maintenance windows, user theme, time zone and two factor requirement, and invitation tokens. Existing data moves to a published default page; ids, monitoring history, users, legacy API tokens and subscription links are kept.
- Updating from the Updates screen (or with the `get` script, which hands over to the same updater) runs `php artisan migrate --force` by itself and puts the previous version back if a migration fails. The Docker image migrates on start. After a manual update (git pull or unpacking the zip by hand), run `php artisan migrate --force` yourself.
- Back up files, database, uploads and APP_KEY together first. Rolling back means restoring the matching backups; do not run a down migration after creating extra pages.
- Keep the scheduler running every minute: it now also announces, starts and ends maintenance windows.
- The sessions list and last seen need `SESSION_DRIVER=database`, the default in `.env.example`. Optional new settings: `PHAROS_PORTAL_BUY_URL`, `PHAROS_QUOTE_URL`, `DB_BUSY_TIMEOUT`, and `DB_JOURNAL_MODE=wal` for installations on a local disk (leave it off on shared hosting with a network file system).

## [0.6.0] — 2026-09-09

### Added
- Provider-specific integration fields, examples and setup guides, with separate incoming monitoring and outgoing notification flows.
- Interactive n8n and Uptime Kuma instructions with component-specific URLs, valid incident examples and copy controls.
- Account password recovery using one-time, expiring email links, rate limits, generic request responses and unchanged two-factor requirements. Resetting a password invalidates old remembered logins and authenticated sessions.
- Shared, clearly highlighted section navigation for Settings and Mail templates.
- Accessible, colour-coded status choices for reporting incidents and posting updates.
- Locally bundled WYSIWYG editor with Markdown mode and a plain-text fallback. Messages remain Markdown in storage and notifications.
- Incident cards with the latest update, affected components, visibility, source and repeat counts.
- Service availability bars with day tooltips and measured uptime, plus visible component bars on mobile.
- Public status refresh every 30 seconds while the tab is visible. Real changes trigger a dismissible notification and a brief highlight, respecting reduced-motion preferences.
- Connection-loss feedback and automatic retry without discarding the last loaded status.

### Changed
- Service bars, percentages and status labels align in a fixed area on the right. Expanded component bars use the same height, spacing and 30-day display as their service; percentages still cover 90 days.
- The visual editor and formatting toolbar use more compact dimensions.
- The displayed incident status Watching is now Monitoring. The stored value 3 and the existing Watching API name remain compatible; Monitoring is accepted as an alias.
- Saved-action notifications appear at the bottom right. Delivery success is never inferred from an update being posted.
- Incident timelines and status headers use the existing status colours in light and dark themes.
- Fonts are self-hosted, and editor usage statistics are disabled.

### Fixed
- Editor initialization no longer runs before its textarea exists; failed validation preserves the selected status and message.
- Missing or empty uptime samples show No data and are excluded from averages.
- Reopening an incident clears its previous resolution timestamp.

### Upgrade
- No new database migration or environment setting is required. Built editor assets are included; Node.js is only needed to rebuild them.

## [0.5.10] — 2026-09-08

### Changed
- The Update link on an incident is now a proper button with a pencil icon, placed before the status label. On a mouse it appears when you hover the incident, so a signed-out visitor's view stays uncluttered; on touch it is always visible, and keyboard focus brings it back. A long incident title now wraps instead of pushing the button off the card.

## [0.5.9] — 2026-09-08

### Added
- Signed-in users can open an incident update directly from the public status page.
- Telegram notifications with a bot token and chat ID, encrypted credentials, test delivery, and queued retries.

## [0.5.8] — 2026-09-08

### Fixed
- A note's dismiss button no longer brings its own `<form>`. When a note sits inside another form — the Status page screen, or a component's heartbeat note — the nested form was invalid HTML, and the browser silently closed the outer form at that point. That detached everything after the note (theme, incident days, Save/Undo) from the real form, so the Status page settings screen showed no live preview and neither Save nor any toggle did anything whenever no service group existed yet.
- Reporting an incident no longer fails with "The components.N field must be an integer" when a component is left on "— leave unchanged —". Every component's status selector posted a value, even the placeholder one, so picking a real status for one component while leaving any other unchanged rejected the whole report.
- Settings → Mail's "Send test e-mail" now shows the failure when the test can't actually be sent (bad host, refused connection, ...) instead of silently redirecting back with nothing to see — the same information the button already collects, just never rendered.
- Mail templates no longer tells an install without the brand pack to "press Save" on a screen that has no Save button.
- Reporting an incident that fails validation for an unrelated reason (a missing title, say) no longer resets every component's status back to "— leave unchanged —"; the choices you made are kept so you don't have to redo them.

## [0.5.7] — 2026-09-07

### Fixed
- Built-in logos and icons use content-versioned URLs after PHP updates. Logo theme selection is scoped consistently, and the fixed dark setup panel always uses the white variant.
- Docker updates no longer offer a non-functional pull-and-restart button or report success after writing an unconsumed trigger. The Updates screen explains host-side Compose updates and version pins; direct update requests fail explicitly and cannot replace container files.

## [0.5.6] — 2026-09-07

### Changed
- Updated the built-in Pharos logos, light/dark variants, browser icon and default email logo from the new brand kit. Custom installation names and uploaded branding remain supported.

## [0.5.5] — 2026-09-06

### Fixed
- Services: the empty state uses the same layout as Components. Creating a service from the component dialog reports session, validation and server errors separately.
- Browser requests and live previews retain the public origin behind HTTPS proxies, including installations in a subdirectory. The status-page preview follows the form's theme without overwriting the visitor's preference; mail preview failures are visible.
- Proxy trust now loads after the environment and works with cached configuration. Docker Compose forwards `TRUSTED_PROXIES`, so configured HTTPS proxies produce correct form actions and redirects.
- Self-updates and rollbacks reset the web OPcache after replacing files, including recovery from failed updates. Hosts that restrict the reset API receive a restart warning; command-line updates explicitly require checking/restarting the separate web PHP cache.

### Upgrade notes
- The updater running in 0.5.4 does not include this fix. The first upgrade from 0.5.4 may still require a one-time PHP service or container restart. Docker image updates already recreate the container.

## [0.5.4] — 2026-09-06

### Added
- Discord notifications, with mentions disabled and rate-limit-aware retries.
- Signal notifications through your own authenticated HTTPS bridge. An optional Docker Compose and gateway configuration is included; linking a Signal account is still required.
- A persistent notification outbox with bounded retries and delivery history, so a temporary delivery failure does not silently lose an incident notification.
- Optional automatic cron setup through DirectAdmin or cPanel, plus `php artisan pharos:cron --install` for hosts with shell access. Existing tasks are read first, preserved and checked after installation.
- A dedicated authenticated Uptime Kuma status webhook for manually managed components.

### Fixed
- Installer: detects versioned CLI PHP installations for DirectAdmin, cPanel, CloudLinux and Plesk and distinguishes web PHP from cron PHP. Restricted filesystem access produces an explicit warning instead of a false success.
- Installer: a private installation key protects the initial setup; CSRF checks and installation locks prevent unintended configuration changes. Existing installations and APP_KEY are preserved, and database passwords retain special characters.
- MySQL: setting changes no longer fail because the audit log expected a numeric ID for a text setting key.
- Updates and rollback: copied public files follow the exact release or backup; tracked obsolete release files are removed without deleting uploads or custom files. Symlink destinations are refused, and failed backups or migrations stop the update.
- Monitoring: DNS resolution fails closed and TCP checks use the same validated address throughout. Failed scheduler runs no longer appear as successful checks.
- Notifications: connection credentials are stored encrypted and excluded from delivery errors. Signal credentials are not rendered back into the page.
- Integrations: clearer heartbeat and Kuma instructions and long API addresses that fit mobile screens.
- Shell installer: unverified releases, non-empty installation directories and unsafe pinned-version overwrites are refused. Older releases receive manual cron instructions when automatic setup is unavailable.

### Upgrade notes
- Back up your installation and database, then run `php artisan migrate --force` after updating. Keep the scheduler running every minute for checks and notification retries.
- Discord requires a channel webhook. Signal requires a linked bridge, HTTPS endpoint and bearer token; it is not hosted by Pharos.
- Cron availability depends on hosting permissions. Plesk can use Scheduled Tasks or the CLI command. A saved cron task is only confirmed operational after a successful scheduler run.
- MySQL database backups and restores remain operator-managed. Public files from before the new ownership manifest are not deleted automatically.

## [0.5.3] — 2026-09-02

### Added
- Status page: the incident history is paged. *Days of incident history* now sets how many days one page shows; *Older incidents* and *Newer incidents* at the bottom of the list walk through the earlier days, and the Older link only appears when there is something earlier to see. Open incidents that outlive the first page stay pinned there and are not repeated on later pages.

## [0.5.2] — 2026-09-01

### Fixed
- Updates: when a migration or the file copy fails halfway, the version that was just backed up is put back automatically — files the new release added are removed first — instead of leaving new code on an old database. The message names the backup either way.
- Backups and the Updates screen no longer need the `intl` PHP extension to show a size; hosts and the Docker image without it saw a backup "fail" right after it had been written.
- HTTP and TCP checks go through the same guard as webhooks and single sign-on: a check can watch your own network, but never this machine, `169.254.169.254` or another link-local address, and it follows no redirects — the response code is the result. Names are resolved once and the connection pinned to that address.
- CSV exports (subscribers, audit log) neutralise cells that a spreadsheet would run as a formula, such as an address starting with `=`.
- The public status page fetched uptime twice per component; it is one query for the whole page now.
- Session cookies are marked `Secure` automatically when `APP_URL` is https; set `SESSION_SECURE_COOKIE` to override.
- A test that compared "1 August" with "31 August" failed on the first day of a month.

### Added
- `TRUSTED_PROXIES` in `.env` (addresses, or `*`) for installs behind Cloudflare or a Docker reverse proxy, so rate limits and the audit log see the visitor's address rather than the proxy's.
- Docker: the image carries a `HEALTHCHECK` on `/up`, and the scheduler container waits for the app to be healthy.
- Release page: canonical URL and social-sharing tags.

### Changed
- The README's cron line redirects output, as the installers already did; without it a control panel mails the scheduler's output every minute.
- "Cachet 2.x compatible" is now described as what it is: components and incidents in Cachet's shape, without `ping`, `version`, groups, metrics, subscribers or schedules.
- Release archives no longer include `.phpunit.result.cache` and the release-page generator.

## [0.5.1] — 2026-08-30

### Fixed (added to the same release later that day)
- A fresh install showed the "Get notified" form before any mail settings existed, and a visitor who used it got a server error. The form now stays off the public page until Settings → Mail has an SMTP host, the Subscribers screen says so, and a mail transport that fails answers with a message instead of a 500.
- Opening an incident with a component id that does not exist no longer fails with a database error; unknown ids are dropped.

### Added
- Updates: installing a release, making a backup and rolling back now open a dialog that shows each step as it happens (download, checksum, unpack, backup, install, migrate) with a live file count, instead of a bare button that returns when everything is over.

### Fixed
- Release archives left out `resources/views/vendor/` (the Pharos pagination view): the Audit log returned a 500 as soon as it had more than one page. Fresh installs of 0.5.0 are affected; installs updated from an older checkout were not.
- Installers: DirectAdmin support — the cron line is pinned to `/usr/local/php<MM>/bin/php`, and `DirectoryIndex index.php` keeps the panel's placeholder `index.html` from shadowing the status page.
- `/get` verifies the release manifest with PHP's sodium first; hosts whose OpenSSL cannot do Ed25519 one-shot verification (CloudLinux 1.1.1k without `-rawin`) refused every manifest.

## [0.5.0] — 2026-08-29

First packaged release: this is the version installers and the Updates screen download.

### Added
- Signed release manifests (Ed25519) with one-click updates, automatic backups, rollback and retention.
- Licence keys tied to the status page domain given at checkout; a **Remove key** button on Branding.
- Zabbix → Pharos incident webhook: opens an incident on a trigger, adds updates, resolves when every check on the host recovers.
- Ongoing block on the public page for incidents older than the history window.
- New sign-in screen: the network of checks, white-label under a Brand pack.
- CI: Pint, Larastan (level 5), PHPUnit and `composer audit` on every push.

### Changed
- The Brand pack outlives a lapsed Supported key; branding is checked when shown, not only when saved.
- The public API hides what the page hides (disabled components, invisible services); a valid token sees internal incidents.
- The "Buy the brand pack" button opens the pricing page.

### Fixed
- Headline and service pills followed the first component instead of the worst one.
- Hidden services no longer turn the public headline red.
- `env()` calls that returned null once the config was cached (licence signing, version pin).
