# Contributing to Pharos

Thanks for considering it. Bug reports, fixes and features are all welcome.

## Before you write code

Open an issue first for anything larger than a bug fix. It saves you building
something that does not fit, and me reviewing something I have to turn down.

## The rules the code follows

- **PHP 8.3, Laravel 12 LTS.** Follow the style already in the file you are editing.
- **Tests.** Anything that can break has a test. Run `php artisan test` before opening a
  pull request — 186 of them pass today and that number should only go up.
- **Small files.** If a class is past ~400 lines, it is probably two classes.
- **No new dependencies** without saying why in the issue. This has to install on a plain
  cPanel, DirectAdmin or Plesk account where people cannot compile things.
- **Honest documentation.** If something is half-finished, the docs say so. There is a
  "Not implemented yet" section in the README and it is there on purpose.

## Contributor Licence Agreement

Pharos is published under the AGPL-3.0, and is also offered under a separate commercial
licence to organisations that cannot accept the AGPL's terms. See
[COMMERCIAL-LICENSE.md](COMMERCIAL-LICENSE.md).

That second option only works if one party can license the whole codebase. So, by opening
a pull request against this repository, you confirm that:

1. **You wrote it, or you have the right to submit it.** The contribution is your own
   work, or you have permission from whoever owns it — including your employer, if the
   work was done on their time or equipment.

2. **You keep your copyright.** You are not signing it away. You may use your own
   contribution anywhere else, however you like.

3. **You grant SolutionMAX a licence to use it.** Specifically: a perpetual, worldwide,
   non-exclusive, royalty-free, irrevocable licence to reproduce, modify, publish and
   distribute your contribution, **including under licence terms other than the AGPL-3.0**
   — which is what makes the commercial licence possible.

4. **You grant the same to everyone else under the AGPL-3.0**, which is the licence this
   repository is published under.

5. **It comes as-is.** You provide the contribution without warranty of any kind.

If you cannot agree to point 3, say so in the pull request. There may still be a way to
take the change — as a plugin, as a documented workaround, or with me rewriting it — but I
need to know before it is merged, not after.

## What this is not

This is not a copyright assignment. Nothing here transfers ownership of your work to
anyone. It is a licence grant, and it exists for one reason: so that a company which
cannot publish its modifications can still pay to use Pharos, and that money can go back
into maintaining it.

---

Questions about any of this: <mail@solutionmax.net>.
