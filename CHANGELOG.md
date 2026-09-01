# Changelog

All notable changes to Pharos. The format follows [Keep a Changelog](https://keepachangelog.com/);
versions follow [SemVer](https://semver.org/). The signed manifest at
`https://pharos.solutionmax.net/releases/latest.json` always points at the newest entry below.

## [Unreleased]

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
