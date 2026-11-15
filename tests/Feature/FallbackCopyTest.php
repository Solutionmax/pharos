<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\BackupDestination;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\FallbackCopy;
use App\Services\PageContext;
use App\Services\PageUrls;
use App\Services\RemoteBackup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\Support\GrantsBrandPack;
use Tests\TestCase;

class FallbackCopyTest extends TestCase
{
    use GrantsBrandPack, RefreshDatabase;

    private string $dir;

    private BackupDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['role' => UserRole::Admin]);
        Component::create(['name' => 'Website']);
        $this->destination = BackupDestination::create(['name' => 'Bucket site', 'driver' => 's3', 'configuration' => ['region' => 'eu-west-1', 'bucket' => 'status-copy'], 'credentials' => ['key' => 'k', 'secret' => 's']]);
        $this->dir = storage_path('framework/testing/fallback-'.Str::random(8));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_copy_is_one_self_contained_file_without_scripts_or_cookies(): void
    {
        $incident = Incident::create(['name' => 'Mail delayed', 'status' => IncidentStatus::Investigating, 'visibility' => 'public', 'occurred_at' => now()]);
        $incident->updates()->create(['status' => IncidentStatus::Investigating, 'message' => 'Looking into it.']);
        Setting::put('subscribers.enabled', '1');
        $this->switchOn();

        $result = app(FallbackCopy::class)->run();

        $this->assertNull($result['error']);
        $this->assertSame(['index.html'], array_keys($result['uploaded']));
        $html = (string) file_get_contents($this->dir.'/index.html');
        $this->assertSame(strlen($html), $result['uploaded']['index.html']);

        $this->assertDoesNotMatchRegularExpression('#<script|<form|<iframe|\snonce=#i', $html);
        $this->assertStringNotContainsStringIgnoringCase('cookie', $html);
        $this->assertSame([], Cookie::getQueuedCookies());
        // Everything is in the file: no stylesheet, script, font or image is fetched.
        $this->assertStringContainsString(substr((string) file_get_contents(public_path('assets/pharos-status.css')), 0, 120), $html);
        $this->assertDoesNotMatchRegularExpression('#<link(?![^>]*href="data:)|\ssrc="(?!data:)|url\((?![\'"]?data:)#i', $html);
        $this->assertStringContainsString('<img class="brand-logo-light" src="data:image/svg+xml;base64,', $html);

        // Links lead to the live site; the copy says what it is.
        $this->assertStringContainsString('href="'.PageUrls::route('public.incident', $incident).'"', $html);
        $this->assertStringContainsString('Mail delayed', $html);
        $this->assertStringContainsString('Saved copy · '.now()->translatedFormat('j M Y, H:i').' · this server may be unreachable', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('Get notified', $html);
    }

    public function test_an_uploaded_logo_travels_inside_the_file_and_foreign_images_are_dropped(): void
    {
        $this->grantBrandPack();
        Storage::fake('public');
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('brand/logo.png', $png);
        Setting::put('brand.logo_path', 'brand/logo.png');
        $incident = Incident::create(['name' => 'Picture', 'status' => IncidentStatus::Investigating, 'visibility' => 'public', 'occurred_at' => now()]);
        $incident->updates()->create(['status' => IncidentStatus::Investigating, 'message' => '![graph](https://images.example.net/graph.png)']);
        $this->switchOn();

        app(FallbackCopy::class)->run();

        $html = (string) file_get_contents($this->dir.'/index.html');
        $this->assertStringContainsString('src="data:image/png;base64,'.base64_encode($png).'"', $html);
        $this->assertStringNotContainsString('images.example.net', $html);
    }

    public function test_only_changed_pages_are_uploaded_and_each_page_has_its_own_path(): void
    {
        $other = StatusPage::create(['name' => 'Partners', 'slug' => 'partners', 'is_published' => true]);
        StatusPage::create(['name' => 'Draft', 'slug' => 'draft', 'is_published' => false]);
        $this->switchOn();
        $copy = app(FallbackCopy::class);

        $this->assertSame(['index.html', 'status/partners/index.html'], array_keys($copy->run()['uploaded']));
        $this->assertSame([], $copy->run()['uploaded']);
        $this->assertSame(['index.html', 'status/partners/index.html'], array_keys(json_decode(Setting::get(FallbackCopy::HASHES), true)));

        // The minute on the page moves on; what it says does not.
        $this->travel(1)->minutes();
        $this->assertSame([], $copy->run()['uploaded']);

        app(PageContext::class)->run($other->id, fn () => Component::create(['name' => 'Partner API', 'status' => ComponentStatus::MajorOutage]));
        $this->assertSame(['status/partners/index.html'], array_keys($copy->run()['uploaded']));
        $this->assertStringContainsString('Partner API', (string) file_get_contents($this->dir.'/status/partners/index.html'));

        // Another destination gets everything; so does --force.
        $second = BackupDestination::create(['name' => 'Second', 'driver' => 'sftp', 'configuration' => [], 'credentials' => []]);
        Setting::put(FallbackCopy::DESTINATION, (string) $second->id);
        $this->assertCount(2, $copy->run()['uploaded']);
        $this->assertCount(2, $copy->run(force: true)['uploaded']);
        $this->assertNull(Setting::get(FallbackCopy::LAST_ERROR));
        $this->assertNotNull(Setting::get(FallbackCopy::LAST_AT));
        $this->assertGreaterThan(1000, (int) Setting::get(FallbackCopy::LAST_BYTES));
    }

    public function test_a_failure_is_recorded_without_secrets_and_never_thrown(): void
    {
        Setting::put(FallbackCopy::ENABLED, '1');
        $this->assertSame('Choose a backup destination for the fallback copy.', app(FallbackCopy::class)->run()['error']);

        Setting::put(FallbackCopy::DESTINATION, (string) $this->destination->id);
        $this->mock(RemoteBackup::class, fn ($mock) => $mock->shouldReceive('filesystem')
            ->andThrow(new \RuntimeException('Could not reach https://k:s@storage.internal.example/status-copy')));

        $result = app(FallbackCopy::class)->run();

        $this->assertSame([], $result['uploaded']);
        $this->assertSame('The last copy failed. Check the destination, its credentials and that this server can reach it.', $result['error']);
        $this->assertSame($result['error'], Setting::get(FallbackCopy::LAST_ERROR));
        $this->assertNull(Setting::get(FallbackCopy::HASHES), 'nothing marked as uploaded, so the next run tries again');
        $this->artisan('pharos:fallback-copy')->expectsOutputToContain('The last copy failed')->assertExitCode(1);
    }

    public function test_the_command_prints_what_it_uploaded_and_the_scheduler_follows_the_switch(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'pharos:fallback-copy'));
        $this->assertNotNull($event);
        $this->assertTrue($event->isDue($this->app));
        $this->assertFalse($event->filtersPass($this->app), 'off: the scheduler leaves it alone');

        $this->artisan('pharos:fallback-copy')->expectsOutputToContain('The fallback copy is off')->assertExitCode(0);
        $this->assertDirectoryDoesNotExist($this->dir);

        $this->switchOn();
        $this->assertTrue($event->filtersPass($this->app));
        $this->artisan('pharos:fallback-copy')->expectsOutputToContain('index.html')->assertExitCode(0);
        $this->artisan('pharos:fallback-copy')->expectsOutputToContain('Nothing changed')->assertExitCode(0);
        $this->artisan('pharos:fallback-copy --force')->expectsOutputToContain('index.html')->assertExitCode(0);
    }

    public function test_the_settings_card_saves_the_choice_and_shows_the_last_copy(): void
    {
        $admin = User::where('role', UserRole::Admin)->firstOrFail();
        $general = ['timezone' => 'UTC', 'audit_days' => 180, 'keep_backups' => 3];

        $this->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSeeInOrder(['Fallback copy', 'Keep a copy of the public page elsewhere', 'Bucket site (S3)'])
            ->assertDontSee('name="fallback_copy" value="1" checked', false);

        $this->put('/admin/settings', $general + ['fallback_copy' => '1'])->assertSessionHasErrors('fallback_destination');
        $this->put('/admin/settings', $general + ['fallback_copy' => '1', 'fallback_destination' => 999])->assertSessionHasErrors('fallback_destination');
        $this->assertFalse(FallbackCopy::enabled());

        $this->put('/admin/settings', $general + ['fallback_copy' => '1', 'fallback_destination' => $this->destination->id])->assertSessionHasNoErrors();
        $this->assertTrue(FallbackCopy::enabled());
        $this->assertSame($this->destination->id, FallbackCopy::destination()?->id);
        $changes = AuditEntry::where('action', 'settings.saved')->latest('id')->firstOrFail()->changes;
        $this->assertSame(['from' => false, 'to' => true], $changes['fallback.enabled']);
        $this->assertSame(['from' => null, 'to' => $this->destination->id], $changes['fallback.destination_id']);

        $this->fakeStorage();
        app(FallbackCopy::class)->run();
        $this->get('/admin/settings')->assertOk()
            ->assertSee('name="fallback_copy" value="1" checked', false)
            ->assertSee('Bucket site · last copy '.now()->format('H:i'), false)
            ->assertSee('<a href="#fallback_destination">Change</a>', false);

        $this->put('/admin/settings', $general)->assertSessionHasNoErrors();
        $this->assertFalse(FallbackCopy::enabled());

        $this->actingAs(User::factory()->create(['role' => UserRole::User]))->get('/admin/settings')->assertForbidden();
    }

    private function switchOn(): void
    {
        Setting::put(FallbackCopy::ENABLED, '1');
        Setting::put(FallbackCopy::DESTINATION, (string) $this->destination->id);
        $this->fakeStorage();
    }

    /** The destination's real driver is RemoteBackup's business; here it is a folder. */
    private function fakeStorage(): void
    {
        $this->mock(RemoteBackup::class, fn ($mock) => $mock->shouldReceive('filesystem')
            ->andReturn(new Filesystem(new LocalFilesystemAdapter($this->dir))));
    }
}
