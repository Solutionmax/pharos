# Notifications

Pharos publishes an outage on the status page. Telling people about it is a
separate step, and this is that step. It covers Slack first, because that is
where most teams already look. For Telegram, go directly to
[Telegram notifications](#telegram-notifications).

The chain has three links, and each one is worth proving on its own before the
next is added:

```
a check fails  →  Pharos opens an incident  →  Slack message
```

A fourth link exists when another monitor is the one that noticed:

```
Zabbix trigger →  POST /api/v1/incidents  →  Pharos incident  →  Slack message
```

---

## Step 1 — Create the Slack app

One app carries both credentials. Slack has two different ways to post, and the
two systems in this chain each want a different one:

| System | Wants | Why |
|--------|-------|-----|
| Pharos | An **Incoming Webhook URL** | A URL is the whole credential — nothing to store, nothing to refresh. Fits a product that must run on a plain PHP host. |
| Zabbix | A **bot token** (`xoxb-…`) | Zabbix's Slack media type resolves channel names and edits messages on recovery, which a webhook URL cannot do. |

Both come out of the same app, so create it once.

1. Go to <https://api.slack.com/apps> and choose **Create New App → From scratch**.
2. Name it after the estate it watches, not after Pharos — `SolutionMAX Alerts`,
   for example. The name appears on every message.
3. Pick the workspace and confirm.

### Create the channels first

Do this in Slack before wiring anything, because both credentials are bound to a
channel at the moment you create them.

| Channel | Who posts | What lands there |
|---------|-----------|------------------|
| `#status` | Pharos | Incidents that are on the public status page. Low volume, every message matters. |
| `#infra-alerts` | Zabbix | Raw monitoring: disk, swap, packages, agents. High volume, mostly informational. |

Keeping them apart is not tidiness. A status page has perhaps one message a
month; Zabbix has fifty. Put them in one channel and the outage scrolls away.

### Get the Incoming Webhook URL (for Pharos)

1. In the app, open **Features → Incoming Webhooks** and turn **Activate
   Incoming Webhooks** on.
2. Click **Add New Webhook to Workspace**.
3. Choose `#status` and **Allow**.
4. Copy the URL. It looks like
   `https://hooks.slack.com/services/T…/B…/…`.

⚠️ **That URL is a password.** Anyone holding it can post in that channel as the
app. It never expires and Slack will not show you a second copy of it in plain
sight — store it where you store passwords, not in a chat message.

The webhook is pinned to `#status` forever. Posting to a second channel means a
second webhook, not a changed one.

### Get the bot token (for Zabbix)

1. Open **Features → OAuth & Permissions**.
2. Under **Scopes → Bot Token Scopes**, add **`chat:write`**. That is the only
   scope needed to post. Add `chat:write.public` as well if you would rather not
   invite the bot to every channel by hand.
3. Scroll up and click **Install to Workspace**, then **Allow**.
4. Copy the **Bot User OAuth Token** — it starts with `xoxb-`.
5. In Slack, open `#infra-alerts` and run `/invite @SolutionMAX Alerts`. Without
   `chat:write.public`, a bot can only post in channels it is a member of, and
   the failure is a quiet `not_in_channel` in the Zabbix log rather than a
   visible error.

⚠️ Reinstalling the app issues a new token and invalidates the old one. If
alerts stop after an app change, that is the first thing to check.

### Where the two credentials live

| Credential | Belongs in |
|------------|-----------|
| Incoming Webhook URL | Pharos admin → **Integrations → Notifications**. Stored in the database, shown masked afterwards. |
| Bot token | The Zabbix media type, and a copy in your password store. |

Neither belongs in a repository, and neither belongs in `.env` for Pharos — the
webhook URL is configuration a non-technical operator changes from the admin,
which is why it is a database row and not an environment variable.

---

## Step 2 — Point Pharos at Slack

### Set the brand name first

The Slack message carries the brand name in its context line, next to the impact
and the affected components. Whatever is in **Admin → Branding → Name** is what
your colleagues will read. Correct it before sending a test, or the first
message anyone ever sees from the status page has the wrong name on it.

### Add the endpoint

**Admin → Integrations → Notifications**, and fill in three fields:

| Field | Value |
|-------|-------|
| **Name** | Only for you, so the list stays readable once there is more than one. `#status in Slack`. |
| **Shape** | **Slack**. |
| **Address** | The Incoming Webhook URL from step 1. |

Plain `http://` is fine and so is the rest of your LAN — an n8n next door is the
common case. Addresses on the Pharos machine itself (`127.0.0.1`, `::1`) and
link-local ranges (`169.254.0.0/16`, `fe80::/10`) are refused, both when the
endpoint is saved and again on every delivery, including IPv4 hidden inside
IPv6 (`::ffff:…`). Redirects are not followed.

The **Shape** field is the one that matters. Slack and Teams each demand their
own JSON, and a raw payload sent to a Slack webhook is answered with `400
invalid_payload` and shows nothing at all. Generic JSON is for anything else —
n8n, Zapier, your own receiver — and only Generic deliveries are signed with
`X-Pharos-Signature`. Slack and Teams ignore unknown headers, so for those the
URL is the credential and there is nothing to verify.

### Prove it

Click **Send test** on the row. Pharos builds a fake incident called *Test
notification from Pharos* and delivers it once. Within a second or two:

- Slack shows the message in the channel the webhook was bound to.
- The **Last attempt** column shows `HTTP 200`.

If it failed, the same column shows the error instead of hiding it in a log
file. The two you are likely to meet:

| What you see | What it means |
|--------------|---------------|
| `HTTP 404` | The webhook was revoked, or the app was reinstalled. Issue a new one. |
| `HTTP 400` | **Shape** is set to Generic JSON while the URL is a Slack one. |

Only the status is kept; the receiver's response body is never stored.

### What is sent, and when

Every incident change fires every enabled endpoint: opened, updated, and
resolved. Resolved messages carry a green check instead of the red light, so a
channel reads as a timeline rather than a pile of alarms.

Incident changes are queued in the database and delivered by the scheduler,
which must run every minute. Temporary failures retry up to six total attempts
with increasing delays. Rate limits respect the receiver's retry delay.
Most other HTTP 4xx responses stop retries; HTTP 408 and 429 can retry.
Each request has a five-second timeout. **Send test** makes one immediate
attempt and does not use the retry queue. Check delivery history under
**Integrations** for queued, delivered and stopped messages.

---

## Related: the audit log

Notifications tell people about outages. The **audit log** (Admin → Audit log)
answers a different question: who changed the configuration. Adding a
notification endpoint, rotating the signing secret and issuing an API token all
land there, along with who did it and from which address.

Two things it deliberately does not record. The **check runner writes nothing**,
because cron is not an actor and a row a minute would bury everything a person
did. And **no secret value is stored** — a rotated signing secret shows as
changed, not as its new value.


## Telegram notifications

### Create the bot and choose a destination

Create a bot through [@BotFather](https://t.me/BotFather) and copy its bot token.
For personal notifications, start a conversation with the bot first. For a
group or channel, add the bot with permission to send messages; in a channel,
give it administrator permission to post messages.

Use the destination's numeric chat ID, including the minus sign for a group or
supergroup, or the @username of a public channel. A personal Telegram username
is not a substitute for the numeric chat ID.

### Configure Pharos

Open **Admin → Integrations → Notifications** and enter:

| Field | Value |
|-------|-------|
| **Name** | A label such as Telegram operations. |
| **Shape** | **Telegram**. |
| **Telegram bot token** | The token supplied by BotFather. |
| **Chat ID or channel @username** | For example, `-1001234567890` or `@your_status_channel`. Replace these examples with your destination. |

The Address field is hidden for Telegram; Pharos builds the API address itself.
Click **Add notification**, then **Send test** and confirm receipt in Telegram.
A successful test proves immediate delivery; keep the scheduler running every
minute for actual incident notifications.

Messages contain the incident status, title and status-page link as plain text.
Opened, updated and resolved incidents use the same delivery queue and retry
rules described above. Pharos stores the token encrypted, masks it in the
notification list and excludes it from validation input retained in the session.
To replace a token or destination, remove the notification and add it again.

### If nothing arrives

| What you see | What to check |
|-------------|---------------|
| Telegram is missing from **Shape** | Update to Pharos 0.5.9 or later; Telegram was added in that release. |
| `HTTP 400` | Check the chat ID, including its minus sign, and the bot's membership of the destination. |
| `HTTP 401` or `HTTP 404` | Check the bot token and replace the notification if the token was revoked. |
| `HTTP 403` | Start the personal chat, unblock the bot, or check its permission to post in the group or channel. |
| `HTTP 429` | Telegram rate-limited delivery. Queued messages wait for its retry delay; an immediate test is not queued. |
| Test succeeds, incident messages do not arrive | Check the minute scheduler and delivery history in **Integrations**. |
| Connection failure | Check outbound HTTPS, DNS and TLS access to api.telegram.org. |

Pharos uses Telegram's [sendMessage API](https://core.telegram.org/bots/api#sendmessage).
