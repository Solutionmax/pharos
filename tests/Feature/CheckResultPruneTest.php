<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\UptimeDay;
use App\Services\Uptime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CheckResultPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function makeComponent(string $name = 'web-06'): Component
    {
        return Component::create(['name' => $name, 'status' => ComponentStatus::Operational]);
    }

    protected function ranAt(Component $component, int $daysAgo, bool $ok = true): CheckResult
    {
        return CheckResult::create([
            'component_id' => $component->id,
            'ok' => $ok,
            'latency_ms' => 12,
            'checked_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_old_results_go_and_recent_ones_stay(): void
    {
        config(['pharos.check_result_days' => 40]);
        $component = $this->makeComponent();
        $old = $this->ranAt($component, 41);
        $edge = $this->ranAt($component, 39);
        $fresh = $this->ranAt($component, 0);

        $this->assertSame(1, CheckResult::prune());

        $this->assertDatabaseMissing('check_results', ['id' => $old->id]);
        $this->assertDatabaseHas('check_results', ['id' => $edge->id]);
        $this->assertDatabaseHas('check_results', ['id' => $fresh->id]);
    }

    public function test_the_newest_result_of_a_component_stays_even_when_old(): void
    {
        config(['pharos.check_result_days' => 40]);
        $paused = $this->makeComponent('paused');
        $this->ranAt($paused, 200);
        $newest = $this->ranAt($paused, 100);
        $other = $this->makeComponent('other');
        $this->ranAt($other, 90);
        $otherNewest = $this->ranAt($other, 50);

        $this->assertSame(2, CheckResult::prune());

        $this->assertSame([$newest->id, $otherNewest->id], CheckResult::orderBy('id')->pluck('id')->all());
    }

    public function test_the_newest_is_judged_by_time_not_by_id(): void
    {
        config(['pharos.check_result_days' => 40]);
        $component = $this->makeComponent();
        $newestByTime = $this->ranAt($component, 60);
        $this->ranAt($component, 90); // higher id, older run

        CheckResult::prune();

        $this->assertSame([$newestByTime->id], CheckResult::pluck('id')->all());
    }

    public function test_it_deletes_in_bounded_batches_and_is_safe_to_run_twice(): void
    {
        config(['pharos.check_result_days' => 40]);
        $component = $this->makeComponent();
        foreach (range(1, 7) as $i) {
            $this->ranAt($component, 100 + $i);
        }
        $this->ranAt($component, 1);

        $this->assertSame(7, CheckResult::prune(batch: 3));
        $this->assertSame(0, CheckResult::prune(batch: 3));
        $this->assertSame(1, CheckResult::count());
    }

    public function test_the_uptime_bar_is_identical_before_and_after_pruning(): void
    {
        config(['pharos.check_result_days' => 40]);
        $component = $this->makeComponent();
        $today = Carbon::today();
        foreach ([0, 1, 5, 30, 60, 89] as $daysAgo) {
            UptimeDay::create([
                'component_id' => $component->id,
                'day' => $today->copy()->subDays($daysAgo),
                'up_seconds' => 86000,
                'down_seconds' => $daysAgo === 5 ? 400 : 0,
            ]);
        }
        foreach ([0, 1, 5, 30, 60, 89] as $daysAgo) {
            $this->ranAt($component, $daysAgo, ok: $daysAgo !== 5);
        }

        $uptime = app(Uptime::class);
        $before = [$uptime->bar($component), $uptime->percentage($component)];

        $this->assertSame(2, CheckResult::prune());

        $this->assertSame($before, [$uptime->bar($component), $uptime->percentage($component)]);
        $this->assertSame(6, UptimeDay::count());
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => $e->description === 'prune-check-results');

        $this->assertCount(1, $events);
        $this->assertSame('25 3 * * *', $events->first()->expression);
    }
}
