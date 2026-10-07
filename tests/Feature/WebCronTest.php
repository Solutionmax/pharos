<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WebCronTest extends TestCase
{
    use RefreshDatabase;

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
}
