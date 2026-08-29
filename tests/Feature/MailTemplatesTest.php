<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Mail\IncidentNoticeMail;
use App\Mail\SubscribeConfirmMail;
use App\Models\AuditEntry;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\MailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GrantsBrandPack;
use Tests\TestCase;

/** Phase 2 of Subscribers: the mails are Markdown templates the customer may edit. */
class MailTemplatesTest extends TestCase
{
    use GrantsBrandPack, RefreshDatabase;

    protected User $admin;

    protected User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.from.name' => null]);

        $this->admin = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::Admin,
        ]);
        $this->member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::User,
        ]);
    }

    protected function subscriber(string $email = 'ann.smith@example.net'): Subscriber
    {
        return Subscriber::create(['email' => $email, 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
    }

    protected function incident(IncidentStatus $status = IncidentStatus::Investigating): Incident
    {
        $incident = Incident::create(['name' => 'Mail queue backed up', 'status' => $status, 'occurred_at' => now()]);
        $incident->components()->attach(Component::create(['name' => 'web-01'])->id, ['status' => 3]);
        $incident->components()->attach(Component::create(['name' => 'smtp-01'])->id, ['status' => 3]);

        return $incident;
    }

    protected function update(Incident $incident, string $message, IncidentStatus $status = IncidentStatus::Investigating): IncidentUpdate
    {
        return IncidentUpdate::create(['incident_id' => $incident->id, 'status' => $status, 'message' => $message]);
    }

    /* ------------------------------------------------------------------ registry */

    public function test_the_registry_knows_four_templates_and_their_tags(): void
    {
        $this->assertSame(['subscribe_confirm', 'incident_opened', 'incident_updated', 'incident_resolved'], MailTemplates::keys());
        $this->assertSame(['{brand}', '{link}', '{hours}', '{name}'], MailTemplates::tags('subscribe_confirm'));

        foreach (['incident_opened', 'incident_updated', 'incident_resolved'] as $key) {
            $this->assertSame(
                ['{brand}', '{incident}', '{status}', '{message}', '{components}', '{link}', '{unsubscribe}', '{when}', '{name}'],
                MailTemplates::tags($key),
            );
        }
    }

    public function test_the_default_confirmation_mail_says_what_phase_one_said(): void
    {
        Setting::put('brand.name', 'Acme Cloud');
        $subscriber = $this->subscriber();

        $mail = new SubscribeConfirmMail($subscriber);
        $html = $mail->render();
        $text = (string) view($mail->textView, $mail->buildViewData());

        $this->assertSame('Confirm your subscription to Acme Cloud status updates', $mail->envelope()->subject);
        $this->assertStringContainsString('Confirm your subscription', $html);
        $this->assertStringContainsString('You asked to be told about incidents on the Acme Cloud status page.', $html);
        $this->assertStringContainsString('Confirm subscription', $html);
        $this->assertStringContainsString('The link is good for 24 hours.', $html);
        $this->assertStringContainsString('/subscribe/confirm/'.$subscriber->id, $html);
        $this->assertStringContainsString('&amp;token='.$subscriber->token, $html);
        // The text part: same words, raw links.
        $this->assertStringContainsString('&token='.$subscriber->token, $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringContainsString('The link is good for 24 hours.', $text);
    }

    public function test_the_default_incident_mail_says_what_phase_one_said(): void
    {
        Setting::put('brand.name', 'Acme Cloud');
        Setting::put('brand.accent', '#ff6600');
        $subscriber = $this->subscriber();
        $update = $this->update($this->incident(), 'Working on it.', IncidentStatus::Identified);

        $mail = new IncidentNoticeMail($update, $subscriber);
        $html = $mail->render();

        $this->assertSame('[Acme Cloud] Mail queue backed up — Identified', $mail->envelope()->subject);
        $this->assertStringContainsString('Identified', $html);
        $this->assertStringContainsString('Mail queue backed up', $html);
        $this->assertStringContainsString('web-01, smtp-01', $html);
        $this->assertStringContainsString('Working on it.', $html);
        $this->assertStringContainsString('View status page', $html);
        $this->assertStringContainsString('#ff6600', $html);
        $this->assertStringContainsString($update->created_at->format('j F Y, H:i'), $html);
        $this->assertStringContainsString('/unsubscribe/'.$subscriber->id, $html);
        $this->assertStringContainsString(route('status'), $html);
    }

    public function test_the_incident_template_is_picked_by_the_update(): void
    {
        $incident = $this->incident();
        $first = $this->update($incident, 'Looking.');
        $second = $this->update($incident, 'Found it.', IncidentStatus::Identified);
        $last = $this->update($incident, 'Fixed.', IncidentStatus::Resolved);

        $this->assertSame('incident_opened', MailTemplates::forUpdate($first));
        $this->assertSame('incident_updated', MailTemplates::forUpdate($second));
        $this->assertSame('incident_resolved', MailTemplates::forUpdate($last));

        // An incident reported as already resolved: resolved wins over "first".
        $resolvedAtOnce = $this->update($this->incident(IncidentStatus::Resolved), 'Was brief.', IncidentStatus::Resolved);
        $this->assertSame('incident_resolved', MailTemplates::forUpdate($resolvedAtOnce));
    }

    public function test_a_saved_template_changes_the_sent_mail(): void
    {
        // Custom wording is Brand pack; the mail is rendered under a licence.
        $this->mock(\App\Services\License::class)->shouldReceive('has')->andReturn(true);
        $templates = app(MailTemplates::class);
        $templates->save('incident_opened', 'Heads up: {incident}', "Dear {name},\n\n{incident} is now **{status}**.");
        $subscriber = $this->subscriber();
        $update = $this->update($this->incident(), 'Looking.');

        $mail = new IncidentNoticeMail($update, $subscriber);

        $this->assertSame('Heads up: Mail queue backed up', $mail->envelope()->subject);
        $this->assertStringContainsString('Dear ann.smith,', $mail->render());
        $this->assertStringContainsString('Mail queue backed up is now <strong>Investigating</strong>.', $mail->render());
        // The other two incident templates are untouched.
        $this->assertTrue($templates->isDefault('incident_updated'));
        $this->assertFalse($templates->isDefault('incident_opened'));
    }

    public function test_tag_values_are_text_but_the_message_is_markdown(): void
    {
        $r = app(MailTemplates::class)->render('incident_updated', [
            'brand' => 'Acme <b>Cloud</b>',
            'incident' => '*Not* italic',
            'status' => 'Identified',
            'message' => "**Bold** line\n<script>alert(1)</script>",
            'components' => 'a, b',
            'link' => 'https://status.example.net/',
            'unsubscribe' => 'https://status.example.net/unsubscribe/1?token=x&signature=y',
            'when' => '28 August 2026, 09:15',
            'name' => 'ann',
        ], subject: '{brand}: {incident}', body: "# {incident}\n\n{brand}\n\n{message}\n\n{nope} stays");

        $this->assertSame('Acme <b>Cloud</b>: *Not* italic', $r['subject']);
        $this->assertStringContainsString('Acme &lt;b&gt;Cloud&lt;/b&gt;', $r['html']);
        $this->assertStringNotContainsString('<b>Cloud</b>', $r['html']);
        $this->assertStringContainsString('*Not* italic', $r['html']);
        $this->assertStringNotContainsString('<em>Not</em>', $r['html']);
        $this->assertStringContainsString('<strong>Bold</strong>', $r['html']);
        $this->assertStringNotContainsString('<script>', $r['html']);
        $this->assertStringContainsString('&lt;script&gt;', $r['html']);
        $this->assertStringContainsString('{nope} stays', $r['html']);
        // The frame always carries the unsubscribe link, whatever the body says.
        $this->assertStringContainsString('href="https://status.example.net/unsubscribe/1?token=x&amp;signature=y"', $r['html']);
    }

    public function test_the_unsubscribe_tag_in_the_body_is_the_signed_url(): void
    {
        app(MailTemplates::class)->save('incident_opened', 'x', 'Leave: [stop]({unsubscribe})');
        $subscriber = $this->subscriber();
        $update = $this->update($this->incident(), 'Looking.');

        $html = (new IncidentNoticeMail($update, $subscriber))->render();

        $this->assertStringContainsString('href="'.e($subscriber->unsubscribeUrl()).'"', $html);
    }

    public function test_a_quoted_message_stays_quoted_across_its_lines(): void
    {
        $r = app(MailTemplates::class)->render('incident_updated', [
            'message' => "First line\n\n- one\n- two",
        ] + $this->emptyIncidentVars(), body: "> {message}\n\nAfter");

        $this->assertSame(1, substr_count($r['html'], '<blockquote'));
        $this->assertStringContainsString('<li>two</li>', $r['html']);
        $this->assertMatchesRegularExpression('/<blockquote[^>]*>.*two.*<\/blockquote>/s', $r['html']);
    }

    public function test_a_line_whose_only_tag_is_empty_is_left_out(): void
    {
        $r = app(MailTemplates::class)->render('incident_updated', ['components' => ''] + $this->emptyIncidentVars(),
            body: "Affects **{components}**\n\nStill here");

        $this->assertStringNotContainsString('Affects', $r['html']);
        $this->assertStringContainsString('Still here', $r['html']);
        $this->assertStringNotContainsString('Affects', $r['text']);
    }

    public function test_the_text_part_has_no_tags_and_raw_urls(): void
    {
        $r = app(MailTemplates::class)->render('incident_updated', [
            'message' => "**Bold** and a list:\n\n- one\n- two",
            'unsubscribe' => 'https://s.example.net/unsubscribe/1?token=x&signature=y',
            'link' => 'https://s.example.net/',
        ] + $this->emptyIncidentVars(), body: "# Title\n\n{message}\n\n[View status page]({link})");

        $text = $r['text'];
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringContainsString("Title\n\nBold and a list:\n\n- one\n- two", $text);
        $this->assertStringContainsString('View status page: https://s.example.net/', $text);
        $this->assertStringContainsString("Unsubscribe: https://s.example.net/unsubscribe/1?token=x&signature=y", $text);
    }

    public function test_reset_restores_the_default(): void
    {
        $templates = app(MailTemplates::class);
        $templates->save('subscribe_confirm', 'Custom', 'Custom body');
        $templates->reset('subscribe_confirm');

        $this->assertTrue($templates->isDefault('subscribe_confirm'));
        $this->assertSame(MailTemplates::defaultSubject('subscribe_confirm'), $templates->subject('subscribe_confirm'));
    }

    /** @return array<string, string> */
    protected function emptyIncidentVars(): array
    {
        return array_fill_keys(['brand', 'incident', 'status', 'message', 'components', 'link', 'unsubscribe', 'when', 'name'], '');
    }

    /* ------------------------------------------------------------------ the screen */

    public function test_a_member_is_kept_out(): void
    {
        $this->actingAs($this->member)->get('/admin/mail-templates')->assertForbidden();
        $this->actingAs($this->member)->put('/admin/mail-templates', ['template' => 'incident_opened', 'subject' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($this->member)->get('/admin/mail-templates/preview?template=incident_opened')->assertForbidden();
    }

    public function test_without_the_brand_pack_the_screen_is_read_only_and_saving_is_refused(): void
    {
        $this->actingAs($this->admin)->get('/admin/mail-templates?template=incident_updated')
            ->assertOk()
            ->assertSee('Mail templates')
            ->assertSee('What subscribers receive')
            ->assertSee('Brand pack')
            ->assertSee('Buy the brand pack')
            ->assertSee('{unsubscribe}')
            ->assertSee('disabled', false)
            ->assertDontSee('Send test to me')
            ->assertSee(e(MailTemplates::defaultSubject('incident_updated')), false);

        $this->actingAs($this->admin)
            ->put('/admin/mail-templates', ['template' => 'incident_updated', 'subject' => 'Paid', 'body' => 'Paid body'])
            ->assertRedirect('/admin/mail-templates?template=incident_updated')
            ->assertSessionHasErrors('template');
        $this->actingAs($this->admin)
            ->post('/admin/mail-templates/reset', ['template' => 'incident_updated'])
            ->assertSessionHasErrors('template');
        $this->actingAs($this->admin)
            ->post('/admin/mail-templates/test', ['template' => 'incident_updated', 'subject' => 'x', 'body' => 'y'])
            ->assertSessionHasErrors('template');

        $this->assertTrue(app(MailTemplates::class)->isDefault('incident_updated'));
        Mail::assertNothingSent();
    }

    public function test_with_the_brand_pack_an_admin_saves_a_template(): void
    {
        $this->grantBrandPack();

        $this->actingAs($this->admin)->get('/admin/mail-templates')
            ->assertOk()
            ->assertSee('Send test to me')
            ->assertSee('Reset to default')
            ->assertDontSee('Buy the brand pack');

        $this->actingAs($this->admin)
            ->put('/admin/mail-templates', ['template' => 'incident_resolved', 'subject' => 'All clear: {incident}', 'body' => "Fixed.\n\n{message}"])
            ->assertRedirect('/admin/mail-templates?template=incident_resolved')
            ->assertSessionHas('status');

        $this->assertSame('All clear: {incident}', Setting::get('mail.template.incident_resolved.subject'));
        $this->assertSame("Fixed.\n\n{message}", Setting::get('mail.template.incident_resolved.body'));
        $this->assertSame(1, AuditEntry::where('action', 'mail_template.saved')->count());

        $this->actingAs($this->admin)
            ->post('/admin/mail-templates/reset', ['template' => 'incident_resolved'])
            ->assertRedirect('/admin/mail-templates?template=incident_resolved');

        $this->assertTrue(app(MailTemplates::class)->isDefault('incident_resolved'));
        $this->assertSame(1, AuditEntry::where('action', 'mail_template.reset')->count());
    }

    public function test_validation_limits(): void
    {
        $this->grantBrandPack();
        $put = fn (array $data) => $this->actingAs($this->admin)->put('/admin/mail-templates', $data + ['template' => 'incident_opened']);

        $put(['subject' => str_repeat('s', 201), 'body' => 'ok'])->assertSessionHasErrors('subject');
        $put(['subject' => 'ok', 'body' => str_repeat('b', 20001)])->assertSessionHasErrors('body');
        $put(['subject' => 'ok', 'body' => 'hi <SCRIPT>alert(1)</script>'])->assertSessionHasErrors('body');
        $put(['subject' => '', 'body' => 'ok'])->assertSessionHasErrors('subject');
        $put(['subject' => 'ok', 'body' => ''])->assertSessionHasErrors('body');
        $this->actingAs($this->admin)->put('/admin/mail-templates', ['template' => 'nope', 'subject' => 'ok', 'body' => 'ok'])
            ->assertSessionHasErrors('template');

        $this->assertTrue(app(MailTemplates::class)->isDefault('incident_opened'));
    }

    public function test_the_preview_renders_the_sample_with_unsaved_values_and_may_be_framed(): void
    {
        $get = $this->actingAs($this->admin)->get('/admin/mail-templates/preview?template=incident_updated');

        $get->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'")
            ->assertSee('Outbound e-mail delayed')
            ->assertSee('Mail, Outbound queue')
            ->assertSee('<strong>', false)
            ->assertSee('<li>', false);

        // Unsaved values from the form, as JSON for the live preview.
        $post = $this->actingAs($this->admin)->postJson('/admin/mail-templates/preview', [
            'template' => 'incident_resolved',
            'subject' => 'Sorted: {incident} ({status})',
            'body' => 'Custom {status} body for {name}',
        ]);

        $post->assertOk()
            ->assertJsonPath('subject', 'Sorted: Outbound e-mail delayed (Resolved)')
            ->assertJsonPath('html', fn ($html) => str_contains($html, 'Custom Resolved body for raymon'));

        // No licence needed to look: it still renders, nothing was saved.
        $this->assertTrue(app(MailTemplates::class)->isDefault('incident_resolved'));
    }

    public function test_send_test_mails_the_signed_in_admin(): void
    {
        $this->grantBrandPack();

        $this->actingAs($this->admin)
            ->post('/admin/mail-templates/test', ['template' => 'incident_opened', 'subject' => 'Trial {incident}', 'body' => 'Hello {name}'])
            ->assertRedirect('/admin/mail-templates?template=incident_opened')
            ->assertSessionHas('status', 'Test e-mail sent to raymon@example.net.');

        Mail::assertSent(\App\Mail\TemplatePreviewMail::class, function ($mail) {
            return $mail->hasTo('raymon@example.net')
                && $mail->envelope()->subject === 'Trial Outbound e-mail delayed'
                && str_contains($mail->render(), 'Hello raymon');
        });
        $this->assertSame(1, AuditEntry::where('action', 'mail.test')->count());
    }

    public function test_a_failed_test_mail_is_shown_not_hidden(): void
    {
        $this->grantBrandPack();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused'));

        $this->actingAs($this->admin)
            ->post('/admin/mail-templates/test', ['template' => 'subscribe_confirm', 'subject' => 'x', 'body' => 'y'])
            ->assertRedirect('/admin/mail-templates?template=subscribe_confirm')
            ->assertSessionHasErrors('mail');
    }

    public function test_the_sidebar_links_to_the_screen_for_an_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/admin/branding')->assertSee('/admin/mail-templates');
        $this->actingAs($this->member)->get('/admin/subscribers')->assertDontSee('/admin/mail-templates');
    }
}
