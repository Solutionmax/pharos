# Subscribers

Visitors can ask to be e-mailed about incidents. This page covers what happens
between the "Get notified" button and the mail landing, what the install needs
for it, and how to honour a "forget me" request.

---

## How it works

```
"Get notified"  →  confirmation mail  →  click  →  active subscriber
incident update →  one outbox row per active subscriber  →  pharos:notify  →  mail
```

1. A visitor enters an address on the status page. The reply is always the same
   sentence — *If that address is new, a confirmation is on its way* — whether the
   address is new, pending or already confirmed, so the form cannot be used to
   find out who subscribed. Five sign-ups per ten minutes per IP; a hidden field
   catches bots.
2. The confirmation mail carries a **signed link, good for 24 hours**. Asking again
   sends a fresh link and voids the old one. An address that never clicks is
   **forgotten after 7 days**.
3. Every update on a **public** incident — posted by hand, through the API, or by a
   check that opened or resolved one — queues one row per active subscriber in
   `subscriber_notifications`. The request that posts the update never talks to
   a mail server.
4. `pharos:notify`, run every minute by the scheduler, sends **up to 50 mails per
   run** from that outbox. A failure is stored on the row with the error text and
   retried on later runs, **three attempts** at most.

Internal and authenticated incidents never reach subscribers.

## What the install needs

**The cron line.** The same single entry that runs the checks also sends the mail:

```
* * * * * cd ~/pharos && php artisan schedule:run
```

Without it, sign-ups still work and the outbox fills, but nothing goes out.

**Mail settings**, in `.env`:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.net
MAIL_PORT=587
MAIL_USERNAME=status@example.net
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="status@example.net"
MAIL_FROM_NAME=
```

A blank `MAIL_FROM_NAME` signs mail with the brand name from **Branding**. Set it
only if the sender should read differently. Prove the settings with
**Settings → Mail → Send test e-mail**: it mails the signed-in administrator and
shows the transport's own error text when it fails.

`APP_URL` must be the public address. The links in the mails are built from it,
because a cron run has no request to read the host from.

## The mail

By default (see **Templates** below) the subject is `[Brand] Incident name — Status` and the body is: the status label, the update
text (Markdown rendered with the same escaping as the status page — anything that
looks like a tag is shown, never run), the affected components, the time in the
install's time zone, a button to the status page and an unsubscribe link. A
plain-text alternative goes with it.

## Templates

Admin → Configuration → **Mail templates**. Four templates, one per screen tab:

| Template | Sent when | Tags |
|---|---|---|
| Subscribe confirmation | someone signs up | `{brand}` `{link}` `{hours}` `{name}` |
| Incident opened | the first update of an incident | `{brand}` `{incident}` `{status}` `{message}` `{components}` `{link}` `{unsubscribe}` `{when}` `{name}` |
| Incident updated | any later update that is not Resolved | same as above |
| Incident resolved | an update with status Resolved | same as above |

Tag meanings: `{brand}` the name from Branding · `{link}` the confirmation link on the
confirmation mail, the status page on the incident mails · `{hours}` how long the
confirmation link is good for · `{name}` the part of the subscriber's address before
the `@` · `{incident}` the incident's name · `{status}` Investigating, Identified,
Watching or Resolved · `{message}` the update text · `{components}` the affected
components, comma-separated · `{unsubscribe}` the subscriber's signed unsubscribe link ·
`{when}` the update's time in the install's time zone.

**Subject and body are Markdown** (`**bold**`, `*italic*`, `# heading`, `- list`,
`> quote`, `[text](url)`). A link on a line of its own becomes a button. Tag values
are printed exactly as typed — a `*` in an incident name stays a `*` — with one
exception: `{message}` is the operator's own Markdown and is rendered as such, with
the same escaping as the status page, so anything that looks like an HTML tag is
shown, never run. `> {message}` quotes the whole message, not just its first line.
A line whose only tag is empty is left out, so `Affects {components}` disappears
when no component is affected. A tag that does not exist stays as typed.

**Body, not frame.** The logo, the accent colour, the link to the status page and —
on every incident mail — the unsubscribe link sit in the frame around the body and
are always there. A template therefore cannot lose the unsubscribe link, and
`{unsubscribe}` in the body is optional. The plain-text part is derived from the
same Markdown with the links written out raw.

The screen shows a live preview with a sample incident, rendered from the unsaved
wording on the left. **Send test to me** mails that rendering to the signed-in
admin through the configured mailer. Templates are stored as
`mail.template.<key>.subject` and `.body` in the settings table; a missing row
means the default. Saving and resetting are logged as `mail_template.saved` and
`mail_template.reset`.

**Brand pack.** Every install sees the screen, the defaults and the preview; saving,
resetting and sending a test need the brand pack licence (checked on the server,
not just in the form). Subjects are limited to 200 characters, bodies to 20 000,
and neither may contain `<script`.

## Unsubscribing

Two roads, both signed and both without expiry:

- The **link in the footer** of every mail (`GET /unsubscribe/{id}?token=…&signature=…`).
- **One-click** (RFC 8058): every mail carries `List-Unsubscribe` and
  `List-Unsubscribe-Post` headers, so Gmail, Apple Mail and others show their own
  unsubscribe button. That is a `POST` to the same signed URL with no session
  and no CSRF token; the signature is the credential.

Both are idempotent — a mail scanner opening the link, or a second click, changes
nothing further. Someone who unsubscribed can come back through "Get notified"
and a fresh confirmation.

## GDPR: export and delete

Under **Subscribers** in the admin (open to every account):

- **Export CSV** — active addresses with their confirmation date. Recorded in the
  audit log as `subscribers.exported`.
- **Delete** on a row — removes the address and everything that was sent to it.
  Recorded as `subscriber.removed` with the address as the subject. This is the
  "forget me" button.
- **Resend confirmation** for a pending address — new token, new mail, recorded
  as `subscriber.confirmation_resent`.

What is stored per subscriber: the address, the confirmation and unsubscribe
times, and the IP the sign-up came from. Unconfirmed addresses are deleted on
their own after 7 days.

## Limits

- Mail goes through whatever `MAIL_*` points at; on shared hosting that usually
  means the host's SMTP and its hourly cap. The batch size of 50 per minute is
  deliberately below most of them.
- The mail text is fixed in this release. Editable templates are the next phase.
