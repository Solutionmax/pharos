# Single sign-on (OIDC)

Written so that in six months you can answer "what does SSO actually do here"
without reading the code.

## The short version

Pharos speaks OpenID Connect as a **confidential client using the authorization
code flow with PKCE**. It signs in people who **already have a Pharos account**;
it never creates one. Local sign-in always keeps working, so a broken identity
provider can never be the reason nobody can reach the status page.

## Why there is no JWT library

The `id_token` is read from the **token endpoint over the back channel** — an
outgoing TLS request that authenticates with the client secret. The transport
already proves who the issuer is, so the signature does not have to be verified
against a JWKS separately. That removes the only part of OIDC that would have
needed a crypto dependency, which matters for a product installed on shared
hosting where the customer cannot run composer.

What is still checked, every time:

| Claim | Why |
|---|---|
| `iss` | The token came from the issuer we configured, not another one |
| `aud` | It was minted for *this* client, not another app at the same provider |
| `nonce` | It answers *our* request, not a replayed older one |
| `exp` / `iat` | It is current |
| `email_verified` | See below — the one that matters most |

## email_verified is not optional

The email claim decides which local account you become. If the provider did not
verify the address, anyone who can register at that provider could claim a
colleague's address and take over their Pharos account — password and two-factor
included. Portalis parsed this field but never read it; that was a real hole.

An unverified address is refused, with `sso.rejected` in the audit log.

## The issuer URL is attacker-controlled input

An administrator types the issuer URL and the server fetches
`/.well-known/openid-configuration` from it. Without a guard that turns the
server into a probe for networks the administrator cannot reach: internal
services, and cloud metadata at `169.254.169.254`, which on most providers hands
instance credentials to anything that asks.

`SafeHttp` therefore resolves the hostname itself, refuses private, loopback and
link-local addresses, **pins the resolved address** so a second DNS answer cannot
differ from the one that was checked (rebinding), and **follows no redirects**.

### Providers on your own network

Plenty of self-hosted installs run Pharos and their identity provider on the same
internal network, where a private issuer is simply the normal case. **Internal
provider hosts** under **Settings → Single sign-on** vouches for named hosts:

```
id.intern.example.net, 192.168.1.20
```

A list rather than a switch, so it opens one door instead of all of them — and
**link-local stays blocked whatever is in that box**, because `169.254.169.254` is
the address the guard exists for.

## Two-factor still applies

Somebody who switched two-factor on gets the code screen after the SSO step as
well. A second door that skips the gate makes the gate decorative — and if the
identity provider does not enforce MFA itself, signing in through it would
quietly undo what the user chose. Trust the provider instead? Then switch your
own two-factor off; that is the user's decision, not the login flow's.

## What an administrator configures

Under **Admin → Settings → Single sign-on** (`/admin/settings#sso`; the old
`/admin/sso` address redirects there): `sso.enabled`, `sso.provider_name` (the button text), `sso.issuer`,
`sso.client_id`, `sso.client_secret`. The secret is stored encrypted and never
rendered back into the form.

Redirect URI to register at the provider: `<your site>/admin/sso/callback`.

## What is deliberately absent

- **No account creation.** Unknown address, no entry; an administrator adds the
  account first.
- **No SAML.** XML signatures need a library, and OIDC covers Authentik, Keycloak,
  Entra, Google and Okta.
- **No SSO-only mode.** Local sign-in stays, always.
