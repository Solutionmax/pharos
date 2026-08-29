# Commercial licence

Pharos is free software under the **AGPL-3.0**. Most people need nothing else, including
hosting companies running it for their own customers. Read this only if the AGPL does not
work for you.

## When you need one

The AGPL has one obligation the ordinary GPL does not:

> If you modify Pharos **and let other people reach it over a network**, you must offer
> those people the source of your modified version.

For most installations that costs nothing — you did not modify anything, so there is
nothing to publish. It becomes a problem in exactly one case:

**You changed Pharos, the change is part of how you compete, and you do not want to
publish it.**

A reseller who wired Pharos into their own provisioning system. A hosting company whose
version pulls status from their internal monitoring. An agency that built a multi-tenant
layer on top. Under the AGPL, all of that has to be published. A commercial licence
removes that requirement.

## What you get

A commercial licence grants the same rights as the AGPL, minus the obligation to publish
your own modifications. Specifically:

- Use Pharos in a closed-source product or internal system
- Modify it without publishing your changes
- Offer it to your customers as part of a paid service
- Remove the "Powered by Pharos" credit
- Keep the AGPL-3.0 as a fallback if you ever stop renewing — you simply return to the
  obligations everyone else has

It does **not** grant you the right to sell Pharos itself as a standalone product under
your own name. That is a separate conversation, and the answer is usually yes.

## What it costs

| | |
|---|---|
| **Brand pack** | One-time · your logo and favicon, editable mail templates, credit removed |
| **Supported** | Yearly · support by e-mail within a working day, Brand pack included and yours to keep |
| **Commercial licence** | Yearly · the AGPL publication requirement lifted, for one organisation |

Current prices are on <https://pharos.solutionmax.net/#pricing>. If you are a one-person
shop and the number does not work for you, write anyway.

## What it does not change

- **The code stays open.** Buying a commercial licence does not take Pharos away from
  anyone else. Every release is still published under the AGPL-3.0.
- **No phone-home.** Licences are verified locally with an Ed25519 signature. Pharos does
  not call our servers to check whether you are allowed to run it, and it keeps working if
  our servers are down.
- **You are not locked in.** Stop paying and Pharos keeps running. You go back to the
  AGPL, like everybody else.

## How to get one

Write to <mail@solutionmax.net> with your organisation's name, roughly what you are
building, and how many installations. You get a quote and, once paid, a signed licence
key and an invoice.

---

<sub>This page describes the offer in plain language; the licence agreement itself is the
document you sign. Nothing here is legal advice — if the distinction matters to your
organisation, have your own counsel read the agreement.</sub>
