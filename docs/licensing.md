# How licensing works

Written so that in six months you can answer "how does someone actually buy this"
without reading the code.

## The short version

A Pharos licence is **a signed sentence, not a database record.** There is no licence
server to call, nothing to check, nothing that can go down.

```
key = base64url(payload) . "." . base64url(signature)

payload = {"product":"pharos","issued_to":"...","features":["brand_pack"],"issued_at":"..."}
```

The customer pastes that string into **Admin → Branding**. Pharos verifies the signature
against a public key compiled into the app and unlocks the features listed in the payload.
That is the whole mechanism.

**The private key is the entire product.** Anyone holding it can mint licences. Anyone
without it cannot forge one, no matter what they patch — they would have to change the
public key in their own copy, which is legal under the AGPL and also means they are no
longer running your build.

## Plans

| Plan | Price | What the key carries | Pages |
|---|---|---|---|
| Free | none | no key | 1 |
| Brand pack | € 79, one time | `brand_pack`, no `expires_at` | 1 |
| Supported | € 149 per year | `brand_pack`, `multi_pages`, `limits.status_pages` = 5, `expires_at` one year out | up to 5 |
| Commercial licence | from € 599 per year, by quote | `brand_pack`, `multi_pages`, no page limit, `expires_at` one year out | unlimited |

- **Free**: every feature of the core, 1 status page, the "Powered by Pharos" footer credit.
- **Brand pack**: own logo (light and dark), favicon, logo in email and editable mail
  templates, footer credit removed. Keeps working forever.
- **Supported**: the Brand pack included and kept, email support, and up to 5 status pages, each with its own components, subscribers, branding, email,
  integrations and user roles per page.
- **Commercial licence**: everything in Supported, unlimited status pages, and the AGPL
  publication duty lifted (that part is the written agreement, not the key).

When a yearly key lapses, `brand_pack` stays (it is in `License::PERPETUAL`) and existing
pages keep running, but creating or reactivating pages beyond 1 is blocked.

Signing a Supported key by hand:

```bash
php artisan pharos:license:sign customer@example.net \
  --features=brand_pack,multi_pages --status-pages=5 --months=12 \
  --domain=status.customer.example --key=/root/secrets/pharos-license-secret.hex
```

For a commercial key, leave `--status-pages` off (no limit means unlimited).

The portal plans (`/account/buy/brand-pack`, `/account/buy/supported`) must sign keys with
these features and limits; prices stay as Stripe price IDs in the portal config. The
commercial licence is quoted individually and its key is signed by hand.

## The keys

| | |
|---|---|
| Secret (signing) | `/root/secrets/pharos-license-secret.hex` — **never leaves our side** |
| Public (verifying) | `/root/secrets/pharos-license-public.hex` → `PHAROS_LICENSE_PUBLIC_KEY` in every install |

The same pair signs **release manifests** for the updater. A `purpose` field keeps the two
apart, so a licence key can never be replayed as a fake update and the other way round.

If the secret ever leaks: generate a new pair, ship a release with the new public key, and
re-issue keys to existing customers. Old keys stop verifying on the new version only —
nobody's site breaks.

## Issuing a key by hand

For a commercial licence, or a customer who paid by invoice:

```bash
php artisan pharos:license:sign customer@example.net \
  --features=brand_pack \
  --key=/root/secrets/pharos-license-secret.hex
```

Prints the key. Mail it. Done.

Features currently understood by the app: `brand_pack` and `multi_pages`. Everything else in the payload is
ignored, so adding a future feature name to a key is harmless.

## Issuing a key after a payment

The portal (`/account`) does this automatically:

1. Customer clicks **Buy** on the pricing table → `/account/buy/{plan}`
2. The portal creates a Stripe Checkout session for the price ID in its config
3. Stripe redirects the customer, takes the money, and posts
   `checkout.session.completed` to the portal's webhook
4. The portal verifies the Stripe signature, records the customer, signs a licence key
   and emails it
5. The customer can log in later with a magic link to fetch the key again

Prices live in the portal's config as Stripe **price IDs**, not amounts. Changing a price
in Stripe therefore does not require a code change, and the pricing table on the website
never links to a Stripe URL that can go stale.

## What the customer sees when it is wrong

Every failure reads as *not licensed*, never as an error:

- Malformed key → not licensed
- Valid signature, wrong product → not licensed
- No key at all → not licensed

A status page must not go down because a licence check had a bad day. That rule is in
`App\Services\License::verify()` and there is a test for it.

## Offline operation and page licences

No phone-home is required. Licence rights are verified locally. Keys may include
expiry and a central-installation domain binding; see the sections below.

Multiple pages use the signed `multi_pages` feature and optional positive integer
`limits.status_pages`. Supported keys carry a limit of 5; commercial keys carry no limit.
Both also include `brand_pack`. Creation
and reactivation above the active-page limit are blocked; existing pages keep running.
See [multiple status pages](multiple-status-pages.md) for signing examples and behavior.

## Keys that run out

A key may carry `expires_at`. Sign one with a term:

```bash
php artisan pharos:license:sign klant@example.net --features=brand_pack --months=12
```

A key may carry `issued_for`, the status page hostname it was sold for; Pharos refuses it on any
other host (case-insensitive, a leading `www.` is ignored). The portal fills it from the domain
typed at checkout. Sign one by hand with:

```bash
php artisan pharos:license:sign klant@example.net --features=brand_pack --domain=status.klant.nl
```

Leave `--months` off and the claim is absent, which means the key never expires —
that is what every key signed before this existed does, and they keep working.

Expired keys lose time-limited rights such as creating extra pages. Brand Pack is
a perpetual feature and remains usable. Existing status pages continue operating.
The branding screen shows the expiry date and warns for the last thirty days.

There is still nothing to revoke a key that is already out there. That is the
price of checking offline, and expiry is the answer: a key that was passed around
runs out on its own.
