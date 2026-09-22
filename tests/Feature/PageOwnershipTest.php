<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PageOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_creates_one_published_default_page(): void
    {
        $page = StatusPage::default();

        $this->assertSame(1, StatusPage::count());
        $this->assertTrue($page->is_published);
        $this->assertSame($page->id, app(PageContext::class)->id());
        $this->assertSame(rtrim((string) config('app.url'), '/'), $page->publicUrl());
        $this->assertDatabaseHas('settings', [
            'key' => StatusPage::DEFAULT_ID_SETTING,
            'value' => (string) $page->id,
        ]);
    }

    public function test_context_run_restores_the_previous_page_even_after_an_exception(): void
    {
        $default = StatusPage::default();
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $context = app(PageContext::class);

        try {
            $context->run($second->id, function () use ($context, $second): void {
                $this->assertSame($second->id, $context->id());
                $this->assertTrue($context->page()->is($second));
                throw new \RuntimeException('stop');
            });
            $this->fail('The callback should have thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('stop', $exception->getMessage());
        }

        $this->assertSame($default->id, $context->id());
        $this->assertTrue($context->page()->is($default));
    }

    public function test_owned_models_are_assigned_to_and_filtered_by_the_current_page(): void
    {
        $default = StatusPage::default();
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $context = app(PageContext::class);

        $a = Component::create(['name' => 'Default service']);
        $b = $context->run($second->id, fn () => Component::create(['name' => 'Second service']));

        $this->assertSame($default->id, $a->status_page_id);
        $this->assertSame($second->id, $b->status_page_id);
        $this->assertSame(['Default service'], Component::pluck('name')->all());
        $this->assertSame(['Second service'], $context->run($second->id, fn () => Component::pluck('name')->all()));

        $this->expectException(\LogicException::class);
        $a->status_page_id = $second->id;
        $a->save();
    }

    public function test_page_settings_are_isolated_while_central_settings_are_shared(): void
    {
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $context = app(PageContext::class);

        Setting::put('brand.name', 'Default brand');
        Setting::put('integrations.webhook_secret', 'default-secret');
        Setting::put('app.timezone', 'Europe/Amsterdam');

        $context->run($second->id, function (): void {
            $this->assertNull(Setting::get('brand.name'));
            $this->assertNull(Setting::get('integrations.webhook_secret'));
            $this->assertSame('Europe/Amsterdam', Setting::get('app.timezone'));
            Setting::put('brand.name', 'Second brand');
            Setting::put('integrations.webhook_secret', 'second-secret');
        });

        $this->assertSame('Default brand', Setting::get('brand.name'));
        $this->assertSame('default-secret', Setting::get('integrations.webhook_secret'));
        $this->assertDatabaseMissing('settings', ['key' => 'brand.name']);
        $this->assertDatabaseCount('status_page_settings', 4);
    }

    public function test_the_same_subscriber_email_is_allowed_once_per_page(): void
    {
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $context = app(PageContext::class);

        Subscriber::create(['email' => 'person@example.net', 'token' => Subscriber::freshToken()]);
        $context->run($second->id, fn () => Subscriber::create([
            'email' => 'person@example.net',
            'token' => Subscriber::freshToken(),
        ]));

        $this->assertSame(1, Subscriber::where('email', 'person@example.net')->count());
        $this->assertSame(1, $context->run($second->id, fn () => Subscriber::where('email', 'person@example.net')->count()));

        $this->expectException(QueryException::class);
        Subscriber::create(['email' => 'person@example.net', 'token' => Subscriber::freshToken()]);
    }

    public function test_incident_template_slugs_are_unique_per_page(): void
    {
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $attributes = [
            'name' => 'Outage',
            'slug' => 'outage',
            'title_template' => 'Outage at {{name}}',
            'body_template' => 'Investigating',
        ];

        IncidentTemplate::create($attributes);
        app(PageContext::class)->run($second->id, fn () => IncidentTemplate::create($attributes));

        $this->assertSame(1, IncidentTemplate::where('slug', 'outage')->count());
        $this->assertSame(1, app(PageContext::class)->run(
            $second->id,
            fn () => IncidentTemplate::where('slug', 'outage')->count(),
        ));
    }

    public function test_subscriber_notifications_are_owned_and_filtered_directly(): void
    {
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);

        app(PageContext::class)->run($second->id, function () use ($second): void {
            $subscriber = Subscriber::create(['email' => 'b@example.net', 'token' => Subscriber::freshToken()]);
            $incident = Incident::create(['name' => 'B incident', 'status' => 1, 'occurred_at' => now()]);
            $update = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => 1, 'message' => 'Investigating']);
            $notification = SubscriberNotification::create([
                'subscriber_id' => $subscriber->id,
                'incident_update_id' => $update->id,
            ]);

            $this->assertSame($second->id, $notification->status_page_id);
            $this->assertSame(1, SubscriberNotification::count());
        });

        $this->assertSame(0, SubscriberNotification::count());
        $this->assertSame(1, SubscriberNotification::withoutGlobalScope('status_page')->count());
    }

    public function test_page_access_requires_assignment_for_non_admin_users(): void
    {
        $default = StatusPage::default();
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $member = User::factory()->create(['role' => UserRole::User]);

        $this->assertTrue($admin->canAccessPage($default->id));
        $this->assertTrue($admin->canAccessPage($second->id));
        $this->assertFalse($member->canAccessPage($default->id));

        $member->statusPages()->attach($second);

        $this->assertTrue($member->canAccessPage($second->id));
        $this->assertTrue($second->users()->whereKey($member->id)->exists());
    }

    public function test_tokens_record_page_and_optional_owner_without_a_global_scope(): void
    {
        $default = StatusPage::default();
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $member = User::factory()->create(['role' => UserRole::User]);

        [$legacy] = ApiToken::issue('Legacy system token');
        [$owned] = ApiToken::issue('Owned token', $member, $second->id);

        $this->assertNull($legacy->user_id);
        $this->assertSame($default->id, $legacy->status_page_id);
        $this->assertTrue($owned->user->is($member));
        $this->assertTrue($owned->statusPage->is($second));
        $this->assertSame(2, ApiToken::count());
    }

    public function test_audits_identify_owned_pages_but_leave_central_actions_unassigned(): void
    {
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        app(PageContext::class)->run($second->id, fn () => Component::create(['name' => 'Second service']));
        $this->assertSame($second->id, AuditEntry::latest('id')->value('status_page_id'));

        User::factory()->create(['role' => UserRole::User]);
        $this->assertNull(AuditEntry::latest('id')->value('status_page_id'));
    }

    public function test_page_public_urls_use_the_canonical_installation_or_configured_domain(): void
    {
        config(['app.url' => 'https://status.example.net/']);
        $default = StatusPage::default();
        $second = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $custom = StatusPage::create(['name' => 'Custom', 'slug' => 'custom', 'domain' => 'status.customer.test']);

        $this->assertSame('https://status.example.net', $default->publicUrl());
        $this->assertSame('https://status.example.net/status/second', $second->publicUrl());
        $this->assertSame('https://status.customer.test', $custom->publicUrl());
    }

    public function test_the_migration_upgrades_a_populated_single_page_database(): void
    {
        $this->runOnIsolatedUpgradeDatabase(fn () => $this->assertPopulatedDatabaseUpgrade());
    }

    private function assertPopulatedDatabaseUpgrade(): void
    {
        $legacyMigrations = $this->migrateLegacySchema();
        $migration = require database_path('migrations/2026_09_20_120000_create_status_pages.php');

        try {
            $this->exercisePopulatedDatabaseUpgrade($migration);
        } finally {
            if (Schema::hasTable('status_pages')) {
                $migration->down();
            }

            foreach (array_reverse($legacyMigrations) as $legacyMigration) {
                $legacyMigration->down();
            }
        }
    }

    private function exercisePopulatedDatabaseUpgrade(object $migration): void
    {

        DB::table('users')->insert([
            'id' => 41,
            'name' => 'Existing operator',
            'email' => 'existing@example.net',
            'password' => 'hash',
            'role' => UserRole::User->value,
        ]);
        DB::table('component_groups')->insert(['id' => 42, 'name' => 'Existing group']);
        DB::table('components')->insert(['id' => 43, 'name' => 'Existing component']);
        DB::table('incident_templates')->insert([
            'id' => 44,
            'name' => 'Existing template',
            'slug' => 'existing',
            'title_template' => 'Existing title',
            'body_template' => 'Existing body',
        ]);
        DB::table('incidents')->insert([
            'id' => 45,
            'name' => 'Existing incident',
            'occurred_at' => now(),
        ]);
        DB::table('incident_updates')->insert([
            'id' => 46,
            'incident_id' => 45,
            'status' => 1,
            'message' => 'Existing update',
        ]);
        DB::table('subscribers')->insert([
            'id' => 47,
            'email' => 'existing@example.net',
            'token' => str_repeat('a', 40),
        ]);
        DB::table('subscriber_notifications')->insert([
            'id' => 48,
            'subscriber_id' => 47,
            'incident_update_id' => 46,
        ]);
        DB::table('webhook_endpoints')->insert([
            'id' => 49,
            'label' => 'Existing hook',
            'url' => Crypt::encryptString('https://hooks.example.net/pharos'),
        ]);
        DB::table('api_tokens')->insert([
            'id' => 50,
            'name' => 'Existing token',
            'token_hash' => str_repeat('b', 64),
        ]);
        DB::table('settings')->insert([
            ['key' => 'brand.name', 'value' => 'Existing brand'],
            ['key' => 'integrations.webhook_secret', 'value' => 'existing-secret'],
            ['key' => 'app.timezone', 'value' => 'Europe/Amsterdam'],
        ]);

        $migration->up();
        $defaultId = (int) DB::table('settings')->where('key', StatusPage::DEFAULT_ID_SETTING)->value('value');

        $this->assertSame('Existing brand', StatusPage::findOrFail($defaultId)->name);
        foreach ([
            'component_groups' => 42,
            'components' => 43,
            'incident_templates' => 44,
            'incidents' => 45,
            'subscribers' => 47,
            'subscriber_notifications' => 48,
            'webhook_endpoints' => 49,
        ] as $table => $id) {
            $this->assertSame(
                $defaultId,
                (int) DB::table($table)->where('id', $id)->value('status_page_id'),
                "$table was not backfilled",
            );
        }
        $this->assertDatabaseHas('status_page_user', ['status_page_id' => $defaultId, 'user_id' => 41]);
        $this->assertDatabaseHas('api_tokens', [
            'id' => 50,
            'status_page_id' => $defaultId,
            'user_id' => null,
        ]);
        $this->assertDatabaseHas('status_page_settings', [
            'status_page_id' => $defaultId,
            'key' => 'brand.name',
            'value' => 'Existing brand',
        ]);
        $this->assertDatabaseHas('status_page_settings', [
            'status_page_id' => $defaultId,
            'key' => 'integrations.webhook_secret',
            'value' => 'existing-secret',
        ]);
        $this->assertDatabaseMissing('settings', ['key' => 'brand.name']);
        $this->assertDatabaseHas('settings', ['key' => 'app.timezone', 'value' => 'Europe/Amsterdam']);

        $migration->down();

        $this->assertFalse(Schema::hasTable('status_pages'));
        $this->assertFalse(Schema::hasColumn('components', 'status_page_id'));
        $this->assertDatabaseHas('components', ['id' => 43, 'name' => 'Existing component']);
        $this->assertDatabaseHas('settings', ['key' => 'brand.name', 'value' => 'Existing brand']);
    }

    /** @return list<object> */
    private function migrateLegacySchema(): array
    {
        $paths = glob(database_path('migrations/*.php')) ?: [];
        sort($paths);
        $migrations = [];

        foreach ($paths as $path) {
            if (basename($path) >= '2026_09_20_120000_create_status_pages.php') {
                break;
            }

            $migration = require $path;
            $migration->up();
            $migrations[] = $migration;
        }

        return $migrations;
    }

    private function runOnIsolatedUpgradeDatabase(callable $callback): mixed
    {
        $original = DB::getDefaultConnection();
        $connectionName = 'ownership_upgrade_'.bin2hex(random_bytes(3));
        $connection = config("database.connections.$original");
        $provisionConnection = null;
        $temporaryDatabase = null;

        if (($connection['driver'] ?? null) === 'sqlite') {
            $connection['database'] = ':memory:';
            $connection['prefix'] = '';
        } else {
            $provisionConnection = $connectionName.'_provision';
            $temporaryDatabase = 'pharos_ownership_'.bin2hex(random_bytes(4));
            config(["database.connections.$provisionConnection" => $connection]);
            DB::purge($provisionConnection);
            DB::connection($provisionConnection)->statement("CREATE DATABASE `$temporaryDatabase`");

            $connection['url'] = null;
            $connection['database'] = $temporaryDatabase;
            $connection['prefix'] = '';
        }

        config(["database.connections.$connectionName" => $connection]);
        DB::purge($connectionName);
        DB::setDefaultConnection($connectionName);

        try {
            return $callback();
        } finally {
            DB::setDefaultConnection($original);
            DB::purge($connectionName);
            config(["database.connections.$connectionName" => null]);

            if ($provisionConnection !== null && $temporaryDatabase !== null) {
                DB::connection($provisionConnection)->statement("DROP DATABASE `$temporaryDatabase`");
                DB::purge($provisionConnection);
                config(["database.connections.$provisionConnection" => null]);
            }
        }
    }
}
