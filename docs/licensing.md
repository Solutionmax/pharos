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

Features currently understood by the app: `brand_pack`. Everything else in the payload is
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

## What is deliberately absent

- **No phone-home.** Pharos never asks a server whether it is allowed to run.
- **No expiry inside the key.** A Supported subscription lapsing does not switch anything
  off; it stops the updates and the support. The customer keeps what they paid for.
- **No per-domain binding.** A key is issued to an email address, not to a hostname. It is
  a receipt, not a lock, and pretending otherwise would only punish honest customers who
  move their install.

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

An expired key stops being a licence: `verify()` returns null, so its features go
back to the free set. The branding screen shows the date, and warns for the last
thirty days.

There is still nothing to revoke a key that is already out there. That is the
price of checking offline, and expiry is the answer: a key that was passed around
runs out on its own.
