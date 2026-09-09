# Interface in Pharos 0.6

Incident status uses accessible radio choices: Investigating (1), Identified (2), Monitoring (3) and Resolved (4). The existing Watching enum and API input remain compatible. Selecting a status does not publish anything until the form is submitted.

The visual editor saves Markdown, as before. Bold, italic, lists, links, quotes and code work in the visual view; Markdown mode remains available. If JavaScript cannot load, the original textarea remains usable. All editor code and fonts are served by the installation. Editor usage statistics are disabled.

## Availability

Unknown days, including rollups with zero observed seconds, are grey and excluded from percentages. A component with no observations shows **No data**. Component percentages cover up to 90 days. Overall uptime averages the component percentages with observations. Service availability averages the daily mean of measured components over that same window. Service and component strips show the most recent 30 days; each day has a tooltip. This is an availability summary, not an SLA calculation.

## Automatic refresh

The public page checks its current URL every 30 seconds while visible. Returning to the tab or regaining a connection also triggers a check. The existing server visibility rules apply; there is no separate endpoint exposing internal incidents. Subscription forms remain in place and expanded service groups retain their state. Refresh is deferred while keyboard focus is inside the status content.

A real change updates the page, briefly highlights the affected items and shows a dismissible notification. Reduced-motion preferences disable the entrance and highlight effects. A connection failure leaves the last loaded content visible, labels the connection problem and retries automatically. Notifications confirm page changes or successful saves; they do not claim that subscribers received mail.

## Rebuilding the editor

Run `npm ci --ignore-scripts` and `npm run build:editor`. The generated `public/assets/editor/` files ship with the release, so installations do not need Node.js. `scripts/build-editor.mjs` replaces Toast UI's embedded DOMPurify with the pinned patched dependency before bundling; changing Toast UI's source layout intentionally fails the build for review. Do not copy upstream prebuilt JavaScript over this bundle.

The normal release gates rebuild the editor, run PHP formatting and static analysis, and run the application tests. No new migration is introduced in 0.6.

## Account recovery

The sign-in page includes **Forgot password?**. Recovery uses the existing Mail settings and `APP_URL` for the link's canonical origin, including an installation subdirectory. Configure an actual sending transport under Settings > Mail for delivery; the log transport only writes the email locally. Recovery mail is sent during the request and does not depend on a queue worker.

Links expire after 60 minutes and can be used once. Requests and reset attempts are rate limited. The request response does not disclose whether an email belongs to an account, including when mail delivery fails. Mail failures are reported to the application log. New passwords require at least 12 characters and confirmation. A completed reset rotates the remember token, causes existing authenticated sessions to be rejected on their next request, and returns to sign-in without disabling two-factor authentication. API tokens are not changed. The reset page uses a no-referrer policy and the existing no-store response headers.

Settings and Mail templates share the same section navigation, including an active border, icon and descriptive subtitle. Service metrics occupy consistent right-aligned columns, and the incident editor uses a compact toolbar and a 210-pixel initial height.

## Integrations

Integrations separates outgoing incident notifications from incoming monitoring. Each destination has its own labels, placeholders, required fields and setup instructions. Switching destinations keeps separate drafts in page memory only. Telegram uses bot credentials and a chat ID; Signal uses its bridge address and credentials. Without JavaScript, destination links reload the appropriate server-rendered form.

The incoming guides generate URLs and JSON examples for the selected enabled component without an active check. n8n can update a component or explicitly create an incident; component status changes alone do not create incidents or send incident notifications. The Uptime Kuma adapter accepts its default JSON webhook body and maps heartbeat status 1 to Operational and 0 to Major outage. Its test notification without heartbeat.status is rejected; use an actual monitor transition to check the connection. Heartbeat job instructions explain the secret URL and scheduler requirement.

Adding a notification saves its configuration. Send test on a saved destination performs a real delivery. Generic JSON receivers can verify the raw request body with the existing signing secret. API tokens retain the existing administrator-only controls.
