<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Mail\IncidentNoticeMail;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Models\User;
use App\Services\MailTemplates;
use App\Services\SubscriberNotifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** pharos:notify: the outbox, the batch, the retries, and what the mail says. */
class NotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        User::create(['name' => 'Admin', 'email' => 'admin@example.net', 'password' => Hash::make('correct-horse-battery')]);
    }

    protected function subscriber(string $email = 'ann@example.net'): Subscriber
    {
        return Subscriber::create(['email' => $email, 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
    }

    protected function update(string $message = 'Looking into it.', IncidentStatus $status = IncidentStatus::Investigating): IncidentUpdate
    {
        $incident = Incident::create(['name' => 'Mail queue backed up', 'status' => $status, 'occurred_at' => now()]);
        $incident->components()->attach(Component::create(['name' => 'web-01'])->id, ['status' => 3]);

        return IncidentUpdate::create(['incident_id' => $incident->id, 'status' => $status, 'message' => $message]);
    }

    public function test_it_sends_the_queued_mail_and_marks_it_sent(): void
    {
        $subscriber = $this->subscriber();
        $update = $this->update();

        $this->artisan('pharos:notify')->assertSuccessful()->expectsOutputToContain('Sent 1, failed 0');

        Mail::assertSent(IncidentNoticeMail::class, fn ($m) => $m->hasTo('ann@example.net'));

        $row = SubscriberNotification::firstOrFail();
        $this->assertNotNull($row->sent_at);
        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->error);
        $this->assertSame($update->id, $row->incident_update_id);
        $this->assertSame($subscriber->id, $row->subscriber_id);

        // Sent is sent: a second run has nothing to do.
        $this->artisan('pharos:notify')->assertSuccessful();
        Mail::assertSent(IncidentNoticeMail::class, 1);
    }

    public function test_a_failure_is_recorded_and_retried_up_to_three_times(): void
    {
        $this->subscriber();
        $this->update();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused'));

        for ($attempt = 1; $attempt <= SubscriberNotifier::MAX_ATTEMPTS + 2; $attempt++) {
            $this->artisan('pharos:notify')->assertSuccessful();
        }

        $row = SubscriberNotification::firstOrFail();
        $this->assertNull($row->sent_at);
        $this->assertSame(SubscriberNotifier::MAX_ATTEMPTS, $row->attempts);
        $this->assertSame('Connection refused', $row->error);
    }

    public function test_it_sends_at_most_one_batch_per_run(): void
    {
        for ($i = 0; $i < SubscriberNotifier::BATCH_SIZE + 3; $i++) {
            $this->subscriber("s{$i}@example.net");
        }
        $this->update();

        $this->artisan('pharos:notify')->assertSuccessful();
        $this->assertSame(SubscriberNotifier::BATCH_SIZE, SubscriberNotification::whereNotNull('sent_at')->count());

        $this->artisan('pharos:notify')->assertSuccessful();
        $this->assertSame(SubscriberNotifier::BATCH_SIZE + 3, SubscriberNotification::whereNotNull('sent_at')->count());
    }

    public function test_someone_who_unsubscribed_meanwhile_is_skipped(): void
    {
        $subscriber = $this->subscriber();
        $this->update();
        $subscriber->forceFill(['unsubscribed_at' => now()])->save();

        $this->artisan('pharos:notify')->assertSuccessful();

        Mail::assertNothingSent();
        $row = SubscriberNotification::firstOrFail();
        $this->assertNull($row->sent_at);
        $this->assertSame(SubscriberNotifier::MAX_ATTEMPTS, $row->attempts);
    }

    public function test_it_forgets_addresses_that_never_confirmed(): void
    {
        $stale = Subscriber::create(['email' => 'stale@example.net', 'token' => Subscriber::freshToken()]);
        $stale->forceFill(['updated_at' => now()->subDays(Subscriber::PENDING_DAYS + 1)])->save();
        Subscriber::create(['email' => 'fresh@example.net', 'token' => Subscriber::freshToken()]);
        $old = $this->subscriber('old-but-confirmed@example.net');
        $old->forceFill(['updated_at' => now()->subDays(30)])->save();

        $this->artisan('pharos:notify')->assertSuccessful()->expectsOutputToContain('forgot 1 unconfirmed');

        $this->assertEqualsCanonicalizing(
            ['fresh@example.net', 'old-but-confirmed@example.net'],
            Subscriber::pluck('email')->all(),
        );
    }

    public function test_the_notice_carries_the_brand_the_links_and_the_headers(): void
    {
        Setting::put('brand.name', 'Acme Cloud');
        Setting::put('brand.accent', '#ff6600');
        // A blank MAIL_FROM_NAME is the documented default; the local .env may say otherwise.
        config(['mail.from.name' => null]);
        $subscriber = $this->subscriber();
        $update = $this->update('Working on it.', IncidentStatus::Identified);

        $mail = new IncidentNoticeMail($update, $subscriber);
        $html = $mail->render();
        $headers = $mail->headers()->text;

        $this->assertSame('[Acme Cloud] Mail queue backed up — Identified', $mail->envelope()->subject);
        $this->assertSame('Acme Cloud', $mail->envelope()->from->name);
        $this->assertStringContainsString('Acme Cloud', $html);
        $this->assertStringContainsString('#ff6600', $html);
        $this->assertStringContainsString('Identified', $html);
        $this->assertStringContainsString('Working on it.', $html);
        $this->assertStringContainsString('web-01', $html);
        $this->assertStringContainsString(route('status'), $html);
        $this->assertStringContainsString('/unsubscribe/'.$subscriber->id, $html);
        $this->assertSame('<'.$subscriber->unsubscribeUrl().'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function test_the_from_name_in_env_still_wins_over_the_brand(): void
    {
        Setting::put('brand.name', 'Acme Cloud');
        config(['mail.from.name' => 'Ops Desk']);

        $mail = new IncidentNoticeMail($this->update(), $this->subscriber());

        $this->assertSame('Ops Desk', $mail->envelope()->from->name);
    }

    public function test_markdown_in_the_message_is_rendered_and_html_is_escaped(): void
    {
        $update = $this->update("**Bold** line\n<script>alert(1)</script>");

        $html = (new IncidentNoticeMail($update, $this->subscriber()))->render();

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_plain_text_part_has_the_message_and_the_unsubscribe_link(): void
    {
        $subscriber = $this->subscriber();
        $update = $this->update('Working on it.');

        $mail = new IncidentNoticeMail($update, $subscriber);
        $mail->render();
        $text = (string) view($mail->textView, $mail->buildViewData());

        $this->assertStringContainsString("Investigating\n\nMail queue backed up", $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Working on it.', $text);
        $this->assertStringContainsString('/unsubscribe/'.$subscriber->id, $text);
    }

    public function test_the_command_runs_every_minute_from_the_scheduler(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'pharos:notify'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    /** The status page colours an incident by its state; the mail about it must not say blue. */
    public function test_the_mail_rule_follows_the_incident_state(): void
    {
        $render = fn (string $key, string $tone) => app(MailTemplates::class)->render($key, [
            'brand' => 'B', 'incident' => 'I', 'status' => 'S', 'message' => 'm', 'components' => 'c',
            'link' => 'https://x.test', 'unsubscribe' => 'https://x.test/u', 'when' => 'now', 'name' => 'n', 'tone' => $tone,
        ])['html'];

        $this->assertStringContainsString('border-left:3px solid #12b76a', $render('incident_resolved', 'ok'));
        $this->assertStringContainsString('border-left:3px solid #f04438', $render('incident_updated', 'b'));
        $this->assertStringContainsString('border-left:4px solid #e86b1c', $render('incident_opened', 'p'));
        $this->assertStringNotContainsString('{tone}', $render('incident_opened', 'p'));
    }
}
