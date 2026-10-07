<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\Setting;
use App\Models\User;
use App\Services\RemoteBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebCronTest extends TestCase
{
    use RefreshDatabase;

    public function test_probe_traffic_does_not_consume_cron_or_passkey_limits_and_cron_aliases_share_their_cap(): void
    {
        config(['monitoring.web_cron_token' => str_repeat('x', 40), 'monitoring.webauthn_origin' => 'https://localhost']);
        Setting::put('cron.web_enabled', '1');
        Artisan::shouldReceive('call')->never();
        for ($i = 0; $i < 11; $i++) {
            $this->getJson('/api/v1/probe/jobs')->assertUnauthorized();
        }
        $lock = Cache::lock('pharos:web-scheduler', 300);
        $this->assertTrue($lock->get());
        try {
            $this->withToken(str_repeat('x', 40))->getJson('/cron/run')->assertStatus(409);
            $this->withToken('bad')->postJson('/api/cron')->assertUnauthorized();
            $this->withToken('bad')->getJson('/cron/run')->assertStatus(429);
            $this->postJson('/admin/passkeys/login/options')->assertOk();
        } finally {
            $lock->release();
        }
    }

    public function test_profile_ceremonies_do_not_consume_remote_backup_limit(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)->postJson('/admin/profile/passkeys/options', ['current_password' => 'password'])->assertOk();
        }
        $destination = BackupDestination::create(['name' => 'Isolated rate fixture', 'driver' => 's3', 'configuration' => ['bucket' => 'fixture', 'region' => 'us-east-1'], 'credentials' => ['key' => 'fixture', 'secret' => 'fixture']]);
        $this->mock(RemoteBackup::class, fn ($mock) => $mock->shouldReceive('run')->once()->andReturn(false));
        $this->post('/admin/backup-destinations/'.$destination->id.'/run')->assertRedirect();
    }

    public function test_disabled_or_wrong_credentials_never_run_scheduler(): void
    {
        config(['monitoring.web_cron_token' => 'high-entropy-secret-at-least-thirty-two-characters']);
        Artisan::shouldReceive('call')->never();
        $this->withToken('high-entropy-secret-at-least-thirty-two-characters')->postJson('/api/cron')->assertNotFound();
        Setting::put('cron.web_enabled', '1');
        $this->withToken('bad')->postJson('/api/cron')->assertUnauthorized();
        Cache::flush();
        $this->postJson('/api/cron?token=high-entropy-secret-at-least-thirty-two-characters')->assertUnauthorized();
    }

    public function test_authenticated_cron_runs_scheduler_but_refuses_overlap(): void
    {
        config(['monitoring.web_cron_token' => 'high-entropy-secret-at-least-thirty-two-characters']);
        Setting::put('cron.web_enabled', '1');
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
        $this->withToken('high-entropy-secret-at-least-thirty-two-characters')->postJson('/api/cron')->assertOk()->assertExactJson(['ok' => true]);
        $lock = Cache::lock('pharos:web-scheduler', 300);
        $lock->get();
        $this->withToken('high-entropy-secret-at-least-thirty-two-characters')->postJson('/api/cron')->assertStatus(409);
        $lock->release();
    }

    public function test_get_fallback_uses_header_auth_and_never_accepts_query_token(): void
    {
        config(['monitoring.web_cron_token' => 'high-entropy-secret-at-least-thirty-two-characters']);
        Setting::put('cron.web_enabled', '1');
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
        $this->getJson('/cron/run?token=high-entropy-secret-at-least-thirty-two-characters')->assertUnauthorized();
        $this->withToken('high-entropy-secret-at-least-thirty-two-characters')->getJson('/cron/run')->assertOk()->assertExactJson(['ok' => true]);
    }
}
