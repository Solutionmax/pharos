# Public sharing, reports, subscriptions and Cachet migration

Every address below has a page variant under `/status/{slug}`. Paths are generated
from the selected page and configured installation URL, including a hosting base
folder. A published page is required for every public response. Archived pages,
foreign IDs, disabled components and components in hidden groups are unavailable.

* `/badges/components/{id}.svg` and `/badges/groups/{id}.svg`: current escaped SVG
  status, with a link to the public page. Cache lifetime: 30 seconds.
* `/feed.xml`: RSS 2.0 with up to 50 public incidents and 50 maintenance windows,
  canonical incident links and XML escaping. Cache lifetime: 60 seconds.
* `/incidents/{id}`: public incident page in the style of the status page: state,
  start and end, duration, affected services, a course strip with a dot per message
  and every message, safe Markdown, no internal incident or hidden component disclosure.
* `/embed.js`: copy its script tag from the **Share your status** card on **Reports**.
  It inserts a linked status label after the script, fetches only public
  `/widget.json` without cookies, refreshes each minute and shows an unavailable
  state if the request fails. JSON and script allow public CORS; DOM text is
  rendered with `textContent`, never `innerHTML`.
* `/.well-known/security.txt` (RFC 9116): once an installation administrator fills
  in **Settings → General → Security contact** (an email address or an `https://`
  link), this file names it, with an expiry a year ahead, the page languages and
  its canonical address. Without a contact the address is a plain 404. One file for
  the whole installation, on every domain it answers to. Cache lifetime: one hour.

## Caching and cookies

Badges, the feed, `/widget.json`, `/embed.js`, report downloads, `/metrics` and
uploaded files never start a session or set a cookie. Since 1.2.2 the readable
public pages (the status page, `/incidents/{id}` and `/reports`) do the same for a
visitor who has no session: no session row, no cookie, and a `200` answer with
`Cache-Control: public, max-age=30, stale-while-revalidate=300` and `Vary: Cookie`,
so a proxy or CDN in front of Pharos may keep it for half a minute. The subscribe
form fetches its CSRF token from `/subscribe/token` when it is opened. Someone who
already has a session (a signed in administrator, a visitor who just subscribed)
keeps it, and gets an answer that is not shared.

## Service subscriptions

The sign-up form defaults to all services, including future services. To receive
only selected components, uncheck All services and select one or more components.
Confirmation is still by signed email link. Existing subscribers retain their
all-service behavior. Public sign-up cannot change an already active subscriber.

The confirmation page and notification email link to service preferences. This
uses a signed relative URL and the subscriber's current token. Both are required;
a rotated token invalidates earlier links. The existing long-lived unsubscribe
URLs remain compatible. Preference forms also require CSRF protection.

Incidents and maintenance are filtered by the selected components, both when
queued and before delivery. Subscribers already told about an incident keep its
updates and resolution even if its component associations change. Changing service
preferences invalidates that earlier selection history, so queued irrelevant mail
is skipped. General notices without component associations go to all subscribers.

## Monthly uptime reports

`/reports`, `/reports.csv`, `/reports.pdf` provide public reports. The matching
`/admin/reports*` routes also include this page's hidden or disabled components,
and require current page access. People with the Read only role can download reports.
Choose `?month=YYYY-MM`, January 2000 through the current month.

Since 1.2.2 the public `/reports` page uses the style of the status page and shows
four columns: **Service**, **Uptime**, **Downtime** and **Incidents** (public incidents
that started in that month). The technical columns (coverage, up, down, excluded
maintenance and unobserved seconds) stay in the CSV and in the report in the admin.

Reports use **UTC calendar months** because daily rollups cannot establish exact
local intraday boundaries. Do not interpret them as a local-time SLA. Percentages
are weighted by observed up/down seconds. Missing data is unobserved, never
assumed available. Coverage compares observed seconds to elapsed month seconds
minus actual recorded maintenance windows; overlapping windows are counted once.
New check rollups exclude only the maintenance portion of the capped observed UTC
interval. Scheduled-but-unstarted work does not count as an exclusion; cancellation
ends the exclusion. Raw check history and old daily totals remain intact. Historical
daily totals recorded before this accounting change may still include maintenance
observations; reports/export metadata state this limitation rather than fabricating
a retrospective correction.
Manually set maintenance states and delayed/missing monitoring samples may appear
as unobserved time. The current month stops at the present time.

CSV cells are spreadsheet-formula escaped. PDF is rendered by Dompdf with Unicode
fonts, remote loading, PHP and JavaScript disabled and an escaped controlled
report template. Reports are bounded to 500 components and 2000 maintenance
windows per page per month. Reports reject pages/months above those limits instead of silently omitting
records. Split large pages before generating a report.

## Scoped API and Prometheus

Legacy `/api/v1/components` and `/api/v1/incidents` contracts remain. New routes
also exist under `/api/v1/pages/{slug}`. Authenticate using `Authorization: Bearer
TOKEN` or `X-Cachet-Token`. A read token can read; mutations require a write token
and the owner's **current** page editor/admin rights. Revoking page membership or
lowering the role immediately removes the corresponding token access.

The full description, every path with its fields, answers and errors, is an
OpenAPI 3.1 document: [docs/openapi.json](openapi.json) in this repository, also
published at <https://pharos.solutionmax.net/openapi.json>. Load it in Swagger UI,
Redoc, Postman or a client generator; pick the second server for a page other
than the first. `tests/Feature/OpenApiSpecTest.php` fails when a route under
`/api/v1` is added or removed without the description following.

Errors are JSON. Token problems answer `401` (`{"error":"Missing API token"}`,
`{"error":"Invalid API token"}`) or `403` (`{"error":"Token has no access to this page"}`).
Anything the API does not know or the caller may not see answers one plain
`404` `{"message":"Not found."}`. Validation failures answer `422` with `message` and
an `errors` object per field; rate limits answer `429` with `Retry-After`.

* `GET /components/{id}`: one public component, in the shape of the list, no token.
* `GET /ping`: Cachet style `{"data":"Pong!"}` on published pages.
* `/groups`: GET list, POST create; `/{group}` GET, PUT and DELETE. Fields:
  `name`, `visible`, `collapsed`, `position`. Like `/components`, the GETs work
  without a token on a published page (since 1.2.2) and then return only the groups
  the public page shows; with a token they return every group of the page.
* `POST /components`: create using `name`, optional `description`, `status` (1–5),
  `group_id`, `enabled`, `show_uptime`, `position`. DELETE `/{component}` removes
  a component. Existing PUT/POST status updates continue to work.
* DELETE `/incidents/{incident}`. Existing create and timeline-update routes remain.
* `/subscribers`: GET (paginated, default 50, maximum 100), POST creates a pending
  unconfirmed subscriber; `/{subscriber}` GET, PUT service preferences and DELETE.
  Subscriber routes need a `write` token for every method, reads included, because
  they return email addresses, and the token owner must administer this page.
  Tokens and signup IPs are never returned. Subscriber creation sends no email;
  resend confirmation from the subscriber administration screen.
* `/maintenance`: GET list, POST create; `/{maintenance}` GET, PUT and DELETE.
  Times require ISO 8601 offsets (`2026-10-09T20:00:00+02:00`), end after start,
  and bounded component IDs belonging to this page. PUT edits only scheduled
  windows; deleting running work restores held component states before removal.
  Announcement minutes follow the existing allowed lead times.

Private new read endpoints also work on unpublished pages, with a valid scoped
read token. Archived pages are unavailable. New API request bodies are capped at
256 KiB before native request capture decodes JSON, including requests without
Content-Length, encoded route prefixes and trailing slashes. JSON POSTs to legacy
component/incident ID paths use this same limit because a body `_method` can select
the new DELETE route; ordinary update verbs, fields and authority remain compatible.
Other legacy API paths retain their existing limits. The shared native guard also
bounds probe request bodies to 16 KiB before capture; globally prepended middleware
retains these checks for alternate Kernel entry points. Resource arrays and lists
are bounded and per-minute throttles apply.

Prometheus: authenticated `GET /metrics` (or `/api/v1/metrics`) exposes gauges
`pharos_component_status` (1–5) and `pharos_component_uptime_ratio` (observed up
fraction over 30 UTC days; `NaN` with no observations). Only ID and escaped name
labels are emitted, never probe targets, check messages or credentials. Up to
5000 enabled components are included. Use the central installation hostname;
custom public-page domains do not accept API credentials. Page variants:
`/status/{slug}/metrics` and `/api/v1/pages/{slug}/metrics`.

## Cachet 2.x importer

Open **Integrations & API → Cachet import** (the screen is called **Import Cachet
2.x**) in the selected page's administration (requires page admin), upload JSON, review the named resources/counts, then import. The preview
expires after 20 minutes, is encrypted in server cache, belongs to the current
user and selected page and is consumed once. Revoked page rights block application.
Application validates again and runs in one database transaction. Identical
normalized exports cannot be applied twice to the same page. Resource order,
object key order, equivalent UTC timestamps, explicit default fields and ignored
metadata do not create a new import identity. Component/group source references
remain significant; incident/subscriber IDs with no destination use are ignored.

Accepts one local JSON object with `groups` (alias `component_groups`), `components`,
`incidents`, `subscribers`. Each value is either a list or a Cachet API envelope
`{"data":[...]}`. Combine complete, paginated Cachet API resources into those
lists before uploading; Pharos does not fetch arbitrary URLs or remote credentials.
Maximum: 4 MiB, 1000 rows per resource, 100 updates per incident. A minimal export:

```json
{
  "groups": [{"id": 1, "name": "Website", "visible": 1}],
  "components": [{"id": 10, "name": "API", "group_id": 1, "status": 1}],
  "incidents": [{"id": 20, "name": "Past outage", "component_id": 10,
    "status": 4, "visible": 1, "message": "Recovered",
    "occurred_at": "2026-09-01T12:00:00Z", "updated_at": "2026-09-01T13:00:00Z",
    "updates": [{"status": 2, "message": "Cause found", "created_at": "2026-09-01T12:30:00Z"}]}],
  "subscribers": [{"id": 30, "email": "reader@example.com", "global": false,
    "verified_at": "2026-09-01", "subscriptions": [{"component_id": 10}]}]
}
```

IDs are source references, mapped to newly created destination IDs. Referenced
groups/components must be present in this export; no destination-page IDs are
accepted as source relations. Incident visibility and group visibility are
preserved; absent visibility defaults private. Dates without offsets mean UTC.
Verified dates preserve subscriber consent. Existing destination subscribers are
left intact, including opt-outs and preferences. Source verification codes,
credentials and unrecognized metadata are discarded. No mail, webhook or
confirmation storm is generated. This adds history; it never overwrites existing
groups, components or incidents and does not import checks/settings/users.

CLI uses a local file and defaults to dry run:

```sh
php artisan pharos:cachet-import /absolute/path/export.json --page=2
php artisan pharos:cachet-import /absolute/path/export.json --page=2 --apply
```

Technical references: [Cachet 2.4 models](https://github.com/CachetHQ/Cachet/tree/2.4/app/Models),
[Dompdf](https://github.com/dompdf/dompdf),
[Prometheus text exposition](https://prometheus.io/docs/instrumenting/exposition_formats/).
