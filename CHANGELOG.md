# Changelog

All notable changes to Pharos. The format follows [Keep a Changelog](https://keepachangelog.com/);
versions follow [SemVer](https://semver.org/). The signed manifest at
`https://pharos.solutionmax.net/releases/latest.json` always points at the newest entry below.

## [Unreleased]

### Added
- Updates: installing a release, making a backup and rolling back now open a dialog that shows each step as it happens (download, checksum, unpack, backup, install, migrate) with a live file count, instead of a bare button that returns when everything is over.

## [0.5.1] — 2026-08-30

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
