<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\SubscribeController;
use App\Mail\IncidentNoticeMail;
use App\Mail\SubscribeConfirmMail;
use App\Models\AuditEntry;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class SubscribersTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.net',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    protected function active(string $email = 'ann@example.net'): Subscriber
    {
        return Subscriber::create([
            'email' => $email, 'token' => Subscriber::freshToken(), 'verified_at' => now(),
        ]);
    }

    protected function pending(string $email = 'pat@example.net'): Subscriber
    {
        return Subscriber::create(['email' => $email, 'token' => Subscriber::freshToken()]);
    }

    // ---------- opt-in ----------

    public function test_signing_up_creates_a_pending_subscriber_and_mails_a_signed_link(): void
    {
        $this->post('/subscribe', ['email' => 'New@Example.net'])
            ->assertRedirect('/')
            ->assertSessionHas('subscribed', SubscribeController::REPLY);

        $subscriber = Subscriber::where('email', 'new@example.net')->firstOrFail();
        $this->assertNull($subscriber->verified_at);
        $this->assertSame(40, strlen($subscriber->token));

        Mail::assertSent(SubscribeConfirmMail::class, function (SubscribeConfirmMail $mail) use ($subscriber) {
            $html = $mail->render();

            return $mail->hasTo('new@example.net')
                && str_contains($html, '/subscribe/confirm/'.$subscriber->id)
                && str_contains($html, 'signature=')
                && str_contains($html, 'token='.$subscriber->token);
        });
    }

    public function test_the_reply_is_shown_on_the_status_page(): void
    {
        $this->followingRedirects()->post('/subscribe', ['email' => 'new@example.net'])
            ->assertOk()
            ->assertSee(SubscribeController::REPLY);
    }

    public function test_a_confirmed_address_gets_nothing_and_the_same_answer(): void
    {
        $this->active();

        $this->post('/subscribe', ['email' => 'ann@example.net'])
            ->assertRedirect('/')
            ->assertSessionHas('subscribed', SubscribeController::REPLY);

        Mail::assertNothingSent();
        $this->assertSame(1, Subscriber::count());
    }

    public function test_a_pending_address_gets_a_fresh_link_and_the_old_one_dies(): void
    {
        $pending = $this->pending();
        $oldUrl = $pending->confirmUrl();

        $this->post('/subscribe', ['email' => 'pat@example.net'])->assertRedirect('/');

        Mail::assertSent(SubscribeConfirmMail::class, 1);
        $this->assertNotSame($pending->token, $pending->fresh()->token);
        $this->get($oldUrl)->assertForbidden();
    }

    public function test_the_honeypot_swallows_a_bot_with_the_same_answer(): void
    {
        $this->post('/subscribe', ['email' => 'bot@example.net', 'website' => 'http://spam'])
            ->assertRedirect('/')
            ->assertSessionHas('subscribed', SubscribeController::REPLY);

        Mail::assertNothingSent();
        $this->assertSame(0, Subscriber::count());
    }

    public function test_a_broken_address_is_refused(): void
    {
        $this->from('/')->post('/subscribe', ['email' => 'not-an-address'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Subscriber::count());
    }

    public function test_the_form_is_hidden_until_mail_can_actually_be_sent(): void
    {
        // A fresh install: smtp selected, no host — the state every new install starts in.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);

        $this->get('/')->assertOk()->assertDontSee('Get notified');
        $this->actingAs($this->user)->get('/admin/subscribers')->assertOk()->assertSee('No mail transport yet');

        config(['mail.default' => 'array']);

        $this->get('/')->assertOk()->assertSee('Get notified');
        $this->actingAs($this->user)->get('/admin/subscribers')->assertOk()->assertDontSee('No mail transport yet');
    }

    public function test_a_mail_transport_that_cannot_send_is_a_message_not_a_500(): void
    {
        // What a fresh install's empty SMTP host throws, minus the socket.
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection could not be established with host ":587"'));

        $this->from('/')->post('/subscribe', ['email' => 'someone@example.com'])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['email' => 'The confirmation e-mail could not be sent right now. Please try again later.']);

        // The address is kept pending: the next attempt, once mail works, needs no second sign-up.
        $this->assertNotNull(Subscriber::where('email', 'someone@example.com')->first());
    }

    public function test_the_form_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/subscribe', ['email' => "n{$i}@example.net"])->assertRedirect('/');
        }

        $this->post('/subscribe', ['email' => 'n6@example.net'])->assertStatus(429);
    }

    // ---------- confirm ----------

    public function test_the_confirmation_link_verifies_the_address_once(): void
    {
        $pending = $this->pending();

        $this->get($pending->confirmUrl())
            ->assertOk()
            ->assertSee("You're subscribed")
            ->assertSee('pat@example.net')
            ->assertSee('/unsubscribe/'.$pending->id, false);

        $verifiedAt = $pending->fresh()->verified_at;
        $this->assertNotNull($verifiedAt);

        // A second click (a mail scanner, a double tap) changes nothing.
        $this->travel(5)->minutes();
        $this->get($pending->confirmUrl())->assertOk();
        $this->assertTrue($verifiedAt->equalTo($pending->fresh()->verified_at));
    }

    public function test_confirming_again_after_unsubscribing_brings_them_back(): void
    {
        $gone = $this->active();
        $gone->forceFill(['unsubscribed_at' => now()])->save();

        $this->get($gone->confirmUrl())->assertOk();

        $this->assertTrue($gone->fresh()->isActive());
    }

    public function test_an_expired_confirmation_link_is_refused(): void
    {
        $pending = $this->pending();
        $url = $pending->confirmUrl();

        $this->travel(Subscriber::CONFIRM_HOURS + 1)->hours();

        $this->get($url)->assertForbidden();
        $this->assertNull($pending->fresh()->verified_at);
    }

    public function test_a_tampered_confirmation_link_is_refused(): void
    {
        $pending = $this->pending();
        $other = $this->pending('other@example.net');

        $tampered = str_replace('/subscribe/confirm/'.$pending->id, '/subscribe/confirm/'.$other->id, $pending->confirmUrl());

        $this->get($tampered)->assertForbidden();
        $this->get('/subscribe/confirm/'.$pending->id.'?token='.$pending->token)->assertForbidden();
        $this->assertNull($other->fresh()->verified_at);
    }

    // ---------- unsubscribe ----------

    public function test_the_unsubscribe_link_works_and_is_idempotent(): void
    {
        $subscriber = $this->active();

        $this->get($subscriber->unsubscribeUrl())->assertOk()->assertSee('Unsubscribed');
        $first = $subscriber->fresh()->unsubscribed_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $this->get($subscriber->unsubscribeUrl())->assertOk();
        $this->assertTrue($first->equalTo($subscriber->fresh()->unsubscribed_at));
        $this->assertFalse($subscriber->fresh()->isActive());
    }

    public function test_one_click_unsubscribe_accepts_a_post_without_a_session(): void
    {
        $subscriber = $this->active();

        // What Gmail sends: a bare POST with this exact body, no cookies, no CSRF token.
        $this->post($subscriber->unsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
    }

    public function test_an_unsigned_unsubscribe_link_does_nothing(): void
    {
        $subscriber = $this->active();

        $this->get('/unsubscribe/'.$subscriber->id.'?token='.$subscriber->token)->assertForbidden();
        $this->post('/unsubscribe/'.$subscriber->id)->assertForbidden();

        $this->assertTrue($subscriber->fresh()->isActive());
    }

    // ---------- queueing ----------

    public function test_an_update_on_a_public_incident_queues_one_row_per_active_subscriber(): void
    {
        $this->active('a@example.net');
        $this->active('b@example.net');
        $this->pending('p@example.net');
        $gone = $this->active('gone@example.net');
        $gone->forceFill(['unsubscribed_at' => now()])->save();

        $incident = Incident::create(['name' => 'Mail down', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
        $update = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.']);

        $queued = SubscriberNotification::where('incident_update_id', $update->id)->get();

        $this->assertCount(2, $queued);
        $this->assertEqualsCanonicalizing(
            Subscriber::whereIn('email', ['a@example.net', 'b@example.net'])->pluck('id')->all(),
            $queued->pluck('subscriber_id')->all(),
        );
        $this->assertTrue($queued->every(fn ($n) => $n->sent_at === null && $n->attempts === 0));
    }

    public function test_a_private_incident_queues_nothing(): void
    {
        $this->active();

        foreach (['internal', 'authenticated'] as $visibility) {
            $incident = Incident::create(['name' => 'Quiet', 'visibility' => $visibility, 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
            IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Shh.']);
        }

        $this->assertSame(0, SubscriberNotification::count());
    }

    public function test_posting_an_update_from_the_admin_queues_but_sends_nothing_yet(): void
    {
        $this->active();
        $incident = Incident::create(['name' => 'Mail down', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);

        $this->actingAs($this->user)
            ->post("/admin/incidents/{$incident->id}/update", ['status' => 4, 'message' => 'Fixed.'])
            ->assertRedirect('/admin/incidents');

        $this->assertSame(1, SubscriberNotification::count());
        Mail::assertNothingSent();
    }

    // ---------- the section toggle ----------

    public function test_the_button_can_be_switched_off_from_the_status_page_screen(): void
    {
        $this->get('/')->assertOk()->assertSee('Get notified');

        $this->actingAs($this->user)->get('/admin/status-page')->assertOk()
            ->assertSee('modules[page.show_subscribe]', false);

        Setting::put('page.show_subscribe', '0');

        $this->get('/')->assertOk()->assertDontSee('Get notified')->assertDontSee('/subscribe', false);
    }

    // ---------- the master switch ----------

    protected function flip(string $enabled)
    {
        return $this->actingAs($this->user)->post('/admin/subscribers/enabled', ['enabled' => $enabled]);
    }

    public function test_switching_off_removes_the_button_and_closes_the_doors_but_not_the_exit(): void
    {
        $active = $this->active();
        $pending = $this->pending();

        $this->flip('0')
            ->assertRedirect('/admin/subscribers')
            ->assertSessionHas('status');

        $this->assertSame('0', Setting::get('subscribers.enabled'));
        $this->assertSame(1, AuditEntry::where('action', 'subscribers.disabled')->count());

        // page.show_subscribe is still on; the master switch wins.
        $this->get('/')->assertOk()->assertDontSee('Get notified')->assertDontSee('action="'.route('subscribe').'"', false);

        $this->post('/subscribe', ['email' => 'new@example.net'])->assertNotFound();
        $this->assertNull(Subscriber::where('email', 'new@example.net')->first());
        Mail::assertNothingSent();

        $this->get($pending->confirmUrl())->assertNotFound();
        $this->assertNull($pending->fresh()->verified_at);

        // Leaving must always work: the mails already sent carry these links.
        $this->get($active->unsubscribeUrl())->assertOk()->assertSee('Unsubscribed');
        $this->assertNotNull($active->fresh()->unsubscribed_at);
        $this->post($this->active('one-click@example.net')->unsubscribeUrl(), ['List-Unsubscribe' => 'One-Click'])->assertOk();

        // Existing addresses are kept, not wiped.
        $this->assertSame(3, Subscriber::count());
    }

    public function test_nothing_is_queued_while_off_but_the_outbox_still_drains(): void
    {
        $this->active('ann@example.net');
        $incident = Incident::create(['name' => 'Mail down', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.']);
        $this->assertSame(1, SubscriberNotification::count());

        $this->flip('0');

        // Switched off mid-incident: the next update queues nothing new...
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Resolved, 'message' => 'Fixed.']);
        $this->assertSame(1, SubscriberNotification::count());

        // ...but the row queued before the flip is still delivered, not stranded.
        $this->artisan('pharos:notify')->assertSuccessful()->expectsOutputToContain('Sent 1, failed 0');
        Mail::assertSent(IncidentNoticeMail::class, 1);
        $this->assertNotNull(SubscriberNotification::first()->sent_at);
    }

    public function test_the_screen_and_the_sidebar_show_the_state_and_the_way_back_on(): void
    {
        $this->actingAs($this->user)->get('/admin/subscribers')->assertOk()
            ->assertSee('Subscriptions')
            ->assertSee('visitors can subscribe')
            ->assertSee('Switch off')
            ->assertDontSee('<span class="navhint">off</span>', false);

        $this->flip('0');

        $this->actingAs($this->user)->get('/admin/subscribers')->assertOk()
            ->assertSee('no button, no mail, existing addresses kept')
            ->assertSee('Switch on')
            ->assertSee('<span class="navhint">off</span>', false)
            // The tiles stay: the numbers are still true.
            ->assertSee('Pending confirmation');

        // The hint follows the sidebar onto every screen, not just this one.
        $this->actingAs($this->user)->get('/admin/incidents')->assertOk()
            ->assertSee('<span class="navhint">off</span>', false);

        $this->flip('1')->assertRedirect('/admin/subscribers');

        $this->assertSame('1', Setting::get('subscribers.enabled'));
        $this->assertSame(1, AuditEntry::where('action', 'subscribers.enabled')->count());
        $this->get('/')->assertOk()->assertSee('Get notified');
        $this->post('/subscribe', ['email' => 'back@example.net'])->assertRedirect('/');
        $this->assertNotNull(Subscriber::where('email', 'back@example.net')->first());
        $this->actingAs($this->user)->get('/admin/incidents')->assertOk()
            ->assertDontSee('<span class="navhint">off</span>', false);
    }

    public function test_the_switch_is_operational_so_a_plain_user_may_flip_it(): void
    {
        $member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::User,
        ]);

        $this->actingAs($member)->post('/admin/subscribers/enabled', ['enabled' => '0'])
            ->assertRedirect('/admin/subscribers');

        $this->assertSame('0', Setting::get('subscribers.enabled'));
    }

    public function test_the_switch_wants_a_plain_yes_or_no(): void
    {
        $this->actingAs($this->user)->postJson('/admin/subscribers/enabled', ['enabled' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors('enabled');
    }

    // ---------- the admin screen ----------

    public function test_the_subscribers_screen_shows_tiles_and_the_table(): void
    {
        $ann = $this->active('ann@example.net');
        $this->pending('pat@example.net');
        $gone = $this->active('gone@example.net');
        $gone->forceFill(['unsubscribed_at' => now()])->save();

        $incident = Incident::create(['name' => 'Mail down', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
        $update = IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.']);
        SubscriberNotification::where('subscriber_id', $ann->id)->update(['sent_at' => '2026-08-28 09:15:00']);

        $this->actingAs($this->user)->get('/admin/subscribers')->assertOk()
            ->assertSeeInOrder(['Active', '1', 'Pending confirmation', '1', 'Unsubscribed', '1'])
            ->assertSee('ann@example.net')
            ->assertSee('pat@example.net')
            ->assertSee('28 Aug 09:15')
            ->assertSee('Resend confirmation')
            ->assertSee('Export CSV');
    }

    public function test_the_subscribers_screen_searches_by_email(): void
    {
        $this->active('ann@example.net');
        $this->active('bob@other.org');

        $this->actingAs($this->user)->get('/admin/subscribers?q=other')->assertOk()
            ->assertSee('bob@other.org')
            ->assertDontSee('ann@example.net');
    }

    public function test_deleting_a_subscriber_takes_their_history_and_is_audited(): void
    {
        $subscriber = $this->active();
        $incident = Incident::create(['name' => 'Mail down', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
        IncidentUpdate::create(['incident_id' => $incident->id, 'status' => IncidentStatus::Investigating, 'message' => 'Looking.']);
        $this->assertSame(1, SubscriberNotification::count());

        $this->actingAs($this->user)->delete("/admin/subscribers/{$subscriber->id}")
            ->assertRedirect('/admin/subscribers');

        $this->assertDatabaseMissing('subscribers', ['id' => $subscriber->id]);
        $this->assertSame(0, SubscriberNotification::count());

        $entry = AuditEntry::where('action', 'subscriber.removed')->firstOrFail();
        $this->assertSame('ann@example.net', $entry->subject_label);
    }

    public function test_resending_a_confirmation_rotates_the_token_and_is_audited(): void
    {
        $pending = $this->pending();
        $old = $pending->token;

        $this->actingAs($this->user)->post("/admin/subscribers/{$pending->id}/resend")
            ->assertRedirect('/admin/subscribers');

        $this->assertNotSame($old, $pending->fresh()->token);
        Mail::assertSent(SubscribeConfirmMail::class, fn ($m) => $m->hasTo('pat@example.net'));
        $this->assertSame(1, AuditEntry::where('action', 'subscriber.confirmation_resent')->count());
    }

    public function test_a_confirmed_address_cannot_be_sent_a_confirmation(): void
    {
        $active = $this->active();

        $this->actingAs($this->user)->post("/admin/subscribers/{$active->id}/resend")
            ->assertRedirect('/admin/subscribers')
            ->assertSessionHasErrors('resend');

        Mail::assertNothingSent();
    }

    public function test_the_csv_export_streams_active_addresses_only(): void
    {
        $this->active('ann@example.net');
        $this->pending('pat@example.net');
        $gone = $this->active('gone@example.net');
        $gone->forceFill(['unsubscribed_at' => now()])->save();

        $response = $this->actingAs($this->user)->get('/admin/subscribers/export')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('email,subscribed_at', $csv);
        $this->assertStringContainsString('ann@example.net', $csv);
        $this->assertStringNotContainsString('pat@example.net', $csv);
        $this->assertStringNotContainsString('gone@example.net', $csv);
        $this->assertSame(1, AuditEntry::where('action', 'subscribers.exported')->count());
    }

    public function test_the_screen_is_open_to_a_plain_user(): void
    {
        $member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::User,
        ]);

        $this->actingAs($member)->get('/admin/subscribers')->assertOk();
    }

    /** A text-only mail client reads the text part; an &amp; there breaks the link. */
    public function test_links_in_the_text_part_are_not_html_escaped(): void
    {
        Mail::fake();
        $this->post('/subscribe', ['email' => 'text@example.net']);

        Mail::assertSent(SubscribeConfirmMail::class, function ($mail) {
            $rendered = $mail->render();           // html part
            $text = (string) view($mail->textView, $mail->buildViewData());
            $this->assertStringContainsString('&token=', $text);
            $this->assertStringNotContainsString('&amp;', $text);
            $this->assertStringContainsString('&amp;token=', $rendered);

            return true;
        });
    }
}
