# Multiple status pages implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Track checkboxes and evidence in progress.md.

**Goal:** Ship internally testable, isolated status pages with page rights, branding, mail and licensed creation.
**Architecture:** Page-owned Eloquent models use a common scope driven by a container-owned PageContext. HTTP middleware selects the page before binding; background services explicitly enter the owning page context. Central account/configuration models remain unscoped.
**Tech Stack:** Existing Laravel 12, PHP 8.3+, SQLite/MySQL, PHPUnit, Blade.
**Spec:** ../specs/2026-09-20-multiple-status-pages-design.md

## Global constraints

No push, public deployment, real customer mail, Stripe writes or shared services. Preserve existing default-page URLs, ids and tokens. New pages start unpublished. No new runtime dependencies. Non-admin access requires assignment; roles still apply. Unknown page/object returns 404. Page limit applies server-side under a transaction. Tests use isolated databases and fake external delivery.

## Task 1: Schema, context and ownership

Files: new migration `database/migrations/2026_09_20_120000_create_status_pages.php`; new `app/Models/StatusPage.php`, `StatusPageSetting.php`, `app/Services/PageContext.php`, `app/Models/Concerns/BelongsToStatusPage.php`; modify owned models, Setting, User, ApiToken, Audit and AppServiceProvider; tests `tests/Feature/PageOwnershipTest.php`.

Interface contract:
```php
PageContext::id(): int; // instance method, default page when no selection
PageContext::page(): StatusPage;
PageContext::run(int $pageId, callable $callback): mixed; // restore in finally
StatusPage::default(): StatusPage;
StatusPage::publicUrl(): string; // configured domain or APP_URL/status/slug; default APP_URL
User::canAccessPage(int $pageId): bool;
User::statusPages(): BelongsToMany;
```
Context resolved as `app(PageContext::class)` singleton. Global scope named `status_page` on Component, ComponentGroup, Incident, IncidentTemplate, Subscriber, WebhookEndpoint and SubscriberNotification. Existing code with no context selects default page. Creating owned models assigns context id; prevent changing ownership. Add optional audit page id; token page id and nullable user_id (legacy system token). Settings delegates page keys (`brand.`, `page.`, `subscribers.`, `mail.template.`) to StatusPageSetting, central keys stay global. Mail transport is explicit in task 3. Store default page id centrally. Migration backfills existing records/settings/users; subscriber uniqueness becomes page+email. No destructive down migration after multiple pages exist.

- [x] Write tests for default migration, separate settings/subscribers, context restoration on exception, ownership filtering and assignment.
```php
$a = StatusPage::default();
$b = StatusPage::create(['name'=>'B','slug'=>'b']);
app(PageContext::class)->run($b->id, fn () => Component::create(['name'=>'B service']));
$this->assertSame(0, Component::where('name', 'B service')->count());
$this->assertSame(1, app(PageContext::class)->run($b->id, fn () => Component::count()));
```
- [x] Run `vendor/bin/phpunit tests/Feature/PageOwnershipTest.php`, confirm missing behavior.
- [x] Implement migration/model/context contract; use database backfill without Eloquent boot side effects.
- [x] Run new tests and full existing suite, fix compatibility regressions; commit only task files.

## Task 2: HTTP selection, rights, API and public routes

Files: `routes/web.php`, `routes/api.php`, `bootstrap/app.php`, new `app/Http/Middleware/ResolveStatusPage.php`, `ApiTokenAuth.php`; relevant Admin/API/Subscribe controllers and User/Integration/IssueToken entrypoints; tests `PageRoutesTest.php`.
Consumes task 1 interfaces. Page-aware route helpers must preserve existing route names in each context and avoid rewriting account/system routes. Routes use `/admin/pages/{statusPage}/...`, `/status/{slug}/...`, `/api/v1/pages/{slug}/...`. Middleware must select before SubstituteBindings and restore after each request. No page selector taken from arbitrary body parameters. Only assigned/admin users may enter page admin. Legacy endpoints always default except explicit confirmed domain public bindings.

- [x] Tests request A with B ids (read/write/preview/export), list routes, multi-tab links, legacy routes, missing assignment and revoked token owner.
```php
$this->actingAs($userA)->get('/admin/pages/'.$pageB->id.'/components')->assertNotFound();
$this->getJson('/api/v1/pages/a/components/'.$componentB->id)->assertNotFound();
```
- [x] Run targeted test, implement routes/context middleware and scoped exists/in rules; reject cross-page component/group attachments.
- [x] Bind token rights to current page and current owner rights, preserve legacy system token default-page scope.
- [x] Verify default routes and all existing request tests; commit task files.

## Task 3: Mail, branding, scheduler and integrations

Files: `MailConfig`, `MailTemplates`, `Branding`, `SubscriberNotifier`, `OutgoingWebhook`, `CheckRunner`, subscriber mailables/models, SubscribeController where necessary, RunChecks/NotifySubscribers, routes/console.php, page mail controller/view; tests `PageDeliveryTest.php`.
Consumes PageContext and Settings scope. Outbox page id is captured at queueing; sender resolves rows across pages then enters correct context. Checks run for each active page; incidents derive ownership from component. Archived/unpublished pages do not send public subscriber mail. Unsubscribe stays valid on archived pages. Central account reset mail unaffected. Page mail mode central/custom; sender overrides per page, encrypted password and dedicated named mailer config without stale transport reuse. Shared global MailConfig::apply cannot apply page settings during application boot.

- [x] Test mixed page batch, address on two pages, private incident after queue, archived page, failed SMTP never falls back.
```php
$this->assertSame([$subscriberA->id], SubscriberNotification::withoutGlobalScope('status_page')->pluck('subscriber_id')->all());
```
- [x] Run failing tests; implement explicit contexts and finally restoration for every background path.
- [x] Namespace uploaded brand assets by page; use canonical saved page URLs in template links and signed subscription routes.
- [x] Test two messages in same process with different branding/from/mailer and existing subscriptions/notifier/check/webhook tests; commit task files.

## Task 4: Page administration, licenses and domain settings

Files: new Admin PagesController, pages Blade views, shared layout selector; License and MakeLicense; tests PageManagementTest and LicenseTest additions. Consume context/user interfaces and task 2 routes. Keep file ownership coordination with task 2 for shared routes.

- [x] Test free second-page denial, paid creation, limit and invalid claims, archive/default restrictions and editing existing page after expiration.
```php
$this->actingAs($admin)->post('/admin/pages', ['name'=>'B','slug'=>'b'])->assertForbidden();
$this->assertSame(1, StatusPage::count());
```
- [x] Implement signed `multi_pages` feature and optional validated `limits.status_pages`. Transaction locks default page while count/check/insert/reactivation happen.
- [x] Add page list/create/edit/archive and assignments UI; domain is unique normalized host, optional and only installation admin can activate after DNS/TLS confirmation.
- [x] Preserve branding on existing pages when applicable, preserve current perpetual Brand Pack semantics; expired license prevents new pages only.
- [x] Wire selector with explicit links and empty-state for unassigned users; verify admin role boundaries.
- [x] Run task tests and lint, commit.

## Task 5: Integration review, migration rehearsal and internal preview

Files: tests for migration backup fixture, `docs/multiple-status-pages.md`, update licensing/subscriber docs and progress ledger.

- [x] Review whole branch for unscoped queries, page-agnostic URL helpers, raw DB validations, secrets in audit, stale mailer config and inherited relationship leaks.
- [x] Run `vendor/bin/phpunit`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `git diff --check`.
- [x] Rehearse upgrade from populated 0.6.0 SQLite and MySQL with fake transports; verify ids/history, old links/tokens and page-unique email behavior.
- [x] Serve isolated local preview with two synthetic brands; browser-check creation/navigation/branding/service separation at desktop and mobile sizes.
- [ ] Compare CT106 application to base, take consistent backup before any authorized test deployment; no scheduler or external delivery enabled in rehearsal.
- [ ] Provide internal test location, verification evidence and limitations. Keep branch local, do not push or publish.

## Review decisions

No global scope on derived Check/IncidentUpdate models: callers resolving them directly must scope through parent or explicit page context. Background queries that intentionally span pages must say withoutGlobalScope and then enter correct context before relationships/mutations. All interactions involving public page data require regression tests, not source-text assertions. PageContext has no static mutable state.
