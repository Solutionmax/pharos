# Page permissions and integration controls

> Execute with superpowers:subagent-driven-development. User approved all three additions in the ongoing multi-page implementation.

**Goal:** Give each assigned user a page role, limit integration tokens, and filter delivery history.

**Architecture:** Extend existing membership and token records with a single role/scope column. Enforce page permissions after page resolution for both legacy and explicit routes. Reuse existing integration queries and pagination.

**Stack:** Existing Laravel 12, PHP 8.3, Blade, SQLite/MySQL. No new dependencies.

## Constraints

- Internal test installation only; no GitHub push or release.
- Existing assignments remain editors; existing tokens retain write scope.
- Global administrators retain access. Page administrators cannot manage installation settings, users, licences or page creation/publication.
- Membership revocation and role reduction apply immediately to sessions and owned API tokens.
- Viewer responses contain no API/webhook/heartbeat credentials. Mutation permissions are enforced server-side.
- No real notification test sends. Preserve database, APP_KEY, uploads and existing logo.

## Tasks

- [x] Roles: migration and fresh-query `User::canEditPage(int): bool` / `canAdministerPage(int): bool`; viewer/editor/admin. Guard page routes and edit forms, separate global admin routes, show role selectors and appropriate actions. Verify legacy/explicit route matrix, privilege boundaries and preserved assignments.
- [x] Tokens and filters: migration `scope` read/write; API writes require write scope and current owner edit access. New UI defaults to read; CLI accepts explicit scope. Page admins manage page tokens. Filter delivery history by owned destination, channel and delivery result, preserving pagination. Verify private reads, rejected writes, cross-page access and filtered counts.
- [x] Review and integration: inspect complete diff, run full suite, static analysis and formatting; test cached routes and both database engines. Update administration/FreeScout documentation and test browser workflows.
- [x] Internal deployment: snapshot matching application/database; pause scheduler and HTTP writes for migration; deploy only changed application files, migrate, restart and verify routes and preserved data. Commit locally only.

## File ownership and interfaces

Roles task owns User, assignment views/controllers, routes, permission middleware and page administration views. Token task owns ApiToken, TokenAccess, ApiTokenAuth, token command, API incident authorization and integration controller/views. Integration task owns docs and deployment verification. Token task consumes the two User permission methods. Both migrations are additive with compatibility defaults; no shared edits to the same controller or template.

## Verification

733 tests / 3,151 assertions passed. MySQL compatibility: 31 tests / 218 assertions; cached routes: 19 tests / 154 assertions. PHPStan zero errors; Pint and diff whitespace checks passed. Independent review approved after hiding viewer check targets. Browser verified page-role assignment, immediate session downgrade, filters/pagination and mobile layouts. Internal deployment preserved existing data and branding; GitHub push remains deferred.
