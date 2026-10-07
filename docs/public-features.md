# Public sharing, reports, subscriptions and Cachet migration

Every address below has a page variant under `/status/{slug}`. Paths are generated
from the selected page and configured installation URL, including a hosting base
folder. A published page is required for every public response. Archived pages,
foreign IDs, disabled components and components in hidden groups are unavailable.

* `/badges/components/{id}.svg` and `/badges/groups/{id}.svg`: current escaped SVG
  status, with a link to the public page. Cache lifetime: 30 seconds.
* `/feed.xml`: RSS 2.0 with up to 50 public incidents and 50 maintenance windows,
  canonical incident links and XML escaping. Cache lifetime: 60 seconds.
* `/incidents/{id}`: public incident timeline, safe Markdown, no internal incident
  or hidden-component disclosure.
* `/embed.js`: copy its script tag from **Monthly reports → Share your status**.
  It inserts a linked status label after the script, fetches only public
  `/widget.json` without cookies, refreshes each minute and shows an unavailable
  state if the request fails. JSON and script allow public CORS; DOM text is
  rendered with `textContent`, never `innerHTML`.

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
and require current page access. Read-only page viewers can download reports.
Choose `?month=YYYY-MM`, January 2000 through the current month.

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

* `GET /ping`: Cachet-style `{"data":"Pong!"}` on published pages.
* `/groups`: GET list, POST create; `/{group}` GET, PUT and DELETE. Fields:
  `name`, `visible`, `collapsed`, `position`.
* `POST /components`: create using `name`, optional `description`, `status` (1–5),
  `group_id`, `enabled`, `show_uptime`, `position`. DELETE `/{component}` removes
  a component. Existing PUT/POST status updates continue to work.
* DELETE `/incidents/{incident}`. Existing create and timeline-update routes remain.
* `/subscribers`: GET (paginated, default 50, maximum 100), POST creates a pending
  unconfirmed subscriber; `/{subscriber}` GET, PUT service preferences and DELETE.
  Subscriber routes additionally require the token owner to administer this page.
  Tokens and signup IPs are never returned. Subscriber creation sends no email;
  resend confirmation from the subscriber administration screen.
* `/maintenance`: GET list, POST create; `/{maintenance}` GET, PUT and DELETE.
  Times require ISO 8601 offsets (`2026-10-09T20:00:00+02:00`), end after start,
  and bounded component IDs belonging to this page. PUT edits only scheduled
  windows; deleting running work restores held component states before removal.
  Announcement minutes follow the existing allowed lead times.

Private new read endpoints also work on unpublished pages, with a valid scoped
read token. Archived pages are unavailable. New API request bodies are capped at
256 KiB, resource arrays and lists are bounded and per-minute throttles apply.

Prometheus: authenticated `GET /metrics` (or `/api/v1/metrics`) exposes gauges
`pharos_component_status` (1–5) and `pharos_component_uptime_ratio` (observed up
fraction over 30 UTC days; `NaN` with no observations). Only ID and escaped name
labels are emitted, never probe targets, check messages or credentials. Up to
5000 enabled components are included. Use the central installation hostname;
custom public-page domains do not accept API credentials. Page variants:
`/status/{slug}/metrics` and `/api/v1/pages/{slug}/metrics`.

## Cachet 2.x importer

Open **Import Cachet 2.x** in the selected page's administration (requires page
admin), upload JSON, review the named resources/counts, then import. The preview
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

Development evidence includes PHP feature tests plus native Node execution of the
widget DOM and `pdftotext` extraction of a real Unicode report. These tools are
used for verification, not at runtime by Pharos.
