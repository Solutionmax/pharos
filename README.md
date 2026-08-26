# Pharos

An open-source status page that watches your services itself, and passes status
downstream to the people who resell them.

Built because Cachet 2.x is unmaintained, Cachet 3.x is no longer open source,
and neither of them can tell you that a server is down without a human pressing
a button.

## What it does that Cachet does not

- **Checks its own components.** HTTP, TCP and heartbeat checks set component
  status without anyone typing anything.
- **Opens and closes incidents by itself.** A failing check opens an incident;
  a recovered check closes it and posts the closing update.
- **Heartbeat checks.** Your backup cron calls in; silence is the alarm. For
  things you cannot see from the outside.
- **Uptime that means something.** Daily roll-ups give a 90-day bar and a real
  percentage. Days without data are grey, not green.
- **One incident, many components.** A hypervisor failure affects more than one
  server; Cachet 2.x allows exactly one.
- **Impact levels.** Minor, major, critical — separate from status.
- **Templates with variables.** `{{server}}` and `{{started_at}}` instead of
  retyping the same sentence.

## Modular by default

Every section of the public page is a checkbox in Settings: the overall banner,
the uptime bar, the services list, per-component bars, scheduled maintenance,
incident history, empty days, the subscribe button, the API link. Switch them
off and the page follows. A bare component list, an announcements-only page or
a single uptime figure are all configurations, not forks.

## Admin

Session auth with a rate-limited login. Components with their check
configuration, incidents across several components at once, search and
filtering, users, API tokens, an outgoing webhook, branding, and light/dark
with the visitor's own choice remembered.

## Compatibility with Cachet 2.x

Existing scripts do not need changing:

- `/api/v1/components` returns the same envelope, with the same status integers.
- `X-Cachet-Token` is accepted alongside `Authorization: Bearer`.
- `POST /api/v1/components/{id}` works as well as `PUT`.

## Requirements

PHP 8.3+, and either SQLite or MySQL. It runs on shared cPanel hosting as well
as in Docker; the only difference is how the scheduler is triggered.

## Install

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
php artisan migrate --force
php artisan pharos:token "n8n"      # shown once
```

Point the web server at `public/`.

### Docker

```bash
cp .env.docker.example .env
docker compose run --rm app php artisan key:generate --show   # paste into .env
docker compose up -d
```

Two containers: Apache with mod_php, and a scheduler running `schedule:work`.
Apache rather than something more fashionable, because it is the same shape as
the shared hosting this also has to run on.

### Keeping checks running

On a VPS, a systemd timer calling `php artisan schedule:run` every minute.
On shared hosting, one cPanel cron line does the same job:

```
* * * * * cd /home/you/pharos && php artisan schedule:run >/dev/null 2>&1
```

## API

```bash
curl -X POST https://status.example.com/api/v1/incidents \
  -H "Authorization: Bearer $PHAROS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "template":   "server-unreachable",
    "vars":       { "server": "web-06.example.net" },
    "status":     "investigating",
    "impact":     "major",
    "components": { "7": "major_outage", "8": "degraded" },
    "auto_resolve": true
  }'
```

Heartbeats need no token, only an unguessable path:

```
* * * * * restic backup /data && curl -fsS -X POST https://status.example.com/api/v1/heartbeat/hb_xxxxx
```

## Updates

Pharos checks once an hour for a **signed** release manifest and shows what is
available under Updates. How it installs depends on where it runs:

- **Shared hosting** — the app owns its files, so it downloads the release,
  checks the archive against the signed checksum, copies the current version to
  `storage/app/backups`, replaces the files and runs migrations. Your `.env`,
  database and uploads are never touched.
- **Docker** — the image belongs to the host, so the app cannot replace itself.
  A host-side updater writes a status file, the app shows the banner, and
  applying drops a trigger file the host watches. The same shape Portalis uses.

Both refuse anything that is not signed by the vendor key. A release manifest
carries `purpose: pharos-release` and a licence key does not, so one can never
be replayed as the other even though the same key signs both. A release server
that cannot be reached reads as "no news", never as an error on a status page.

```bash
php artisan pharos:update --check    # what is available
php artisan pharos:update            # install it
```

Cutting a release, vendor side:

```bash
php artisan pharos:release:sign 1.1.0 https://.../pharos-1.1.0.zip ./pharos-1.1.0.zip \
  --notes="What changed" --key=/path/to/secret.hex
```

Publish that one line as `latest.json`. The version itself comes from
`PHAROS_VERSION` at build time, never from a constant edited by hand.

## Tests

```bash
php artisan test
```

182 tests. They have already caught these real bugs: Carbon mutating `last_run_at`
so checks drifted apart, a date-cast mismatch that dropped today from the uptime
window, recovery logic that could never close an incident, and SQLite needing a
writable directory rather than just a writable file.

## Licence

Core: AGPL-3.0. The `ee/` directory (branding pack, reseller features) is under
a separate commercial licence — see `ee/LICENCE` once it exists.
