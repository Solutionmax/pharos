<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Mail\IncidentNoticeMail;
use App\Mail\TestMail;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\StatusPageSetting;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\MailConfig;
use App\Services\OutgoingWebhook;
use App\Services\PageContext;
use App\Services\Probe;
use App\Services\ProbeResult;
use App\Services\SubscriberNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PageDeliveryProbe extends Probe
{
    /** @var list<string> */
    public array $targets = [];

    public function run(Check $check): ProbeResult
    {
        $this->targets[] = $check->target;

        return new ProbeResult(true, 5, 'ok');
    }
}

class PageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mixed_page_batch_keeps_each_pages_brand_sender_and_links(): void
    {
        Mail::fake();
        config(['app.url' => 'https://status.example.test', 'mail.from.name' => null]);

        $a = StatusPage::default();
        $b = StatusPage::create(['name' => 'Beta page', 'slug' => 'beta', 'is_published' => true]);

        [$subscriberA, $updateA] = $this->onPage($a, function () {
            Setting::put('brand.name', 'Alpha Status');
            app(MailConfig::class)->savePage($this->pageMail([
                'mode' => 'custom',
                'host' => 'smtp.alpha.example.test',
                'port' => 587,
                'from_name' => 'Alpha sender',
            ]));

            return [$this->subscriber('same@example.test'), $this->update('Alpha outage')];
        });
        [$subscriberB, $updateB] = $this->onPage($b, function () {
            Setting::put('brand.name', 'Beta Status');
            app(MailConfig::class)->savePage($this->pageMail([
                'mode' => 'custom',
                'host' => 'smtp.beta.example.test',
                'port' => 465,
                'encryption' => 'ssl',
                'from_name' => 'Beta sender',
            ]));

            return [$this->subscriber('same@example.test'), $this->update('Beta outage')];
        });

        $rows = SubscriberNotification::withoutGlobalScope('status_page')->orderBy('id')->get();
        $this->assertSame([$a->id, $b->id], $rows->pluck('status_page_id')->all());
        $this->assertSame([$subscriberA->id, $subscriberB->id], $rows->pluck('subscriber_id')->all());

        $this->assertSame([2, 0], app(SubscriberNotifier::class)->sendPending());

        $sent = Mail::sent(IncidentNoticeMail::class)->keyBy(fn (IncidentNoticeMail $mail) => $mail->update->id);
        $this->assertCount(2, $sent);

        $mailA = $sent->get($updateA->id);
        $mailB = $sent->get($updateB->id);
        $this->assertSame('Alpha sender', $mailA->envelope()->from->name);
        $this->assertSame('Beta sender', $mailB->envelope()->from->name);
        $this->assertSame('pharos_page_'.$a->id, $mailA->mailer);
        $this->assertSame('pharos_page_'.$b->id, $mailB->mailer);
        $this->assertSame('smtp.alpha.example.test', config('mail.mailers.pharos_page_'.$a->id.'.host'));
        $this->assertSame('smtp.beta.example.test', config('mail.mailers.pharos_page_'.$b->id.'.host'));
        $this->assertStringContainsString('[Alpha Status] Alpha outage', $mailA->envelope()->subject);
        $this->assertStringContainsString('[Beta Status] Beta outage', $mailB->envelope()->subject);
        $this->assertStringContainsString('https://status.example.test', $mailA->render());
        $this->assertStringContainsString('https://status.example.test/status/beta', $mailB->render());
        $this->assertStringContainsString('/unsubscribe/'.$subscriberA->id, $mailA->render());
        $this->assertStringContainsString('/status/beta/unsubscribe/'.$subscriberB->id, $mailB->render());
    }

    public function test_private_incidents_and_non_public_pages_are_rechecked_before_delivery(): void
    {
        Mail::fake();
        $a = StatusPage::default();
        $archived = StatusPage::create(['name' => 'Archived later', 'slug' => 'archived-later', 'is_published' => true]);
        $unpublished = StatusPage::create(['name' => 'Private draft', 'slug' => 'private-draft', 'is_published' => false]);

        $privateIncident = $this->onPage($a, function () {
            $this->subscriber('private@example.test');

            return $this->update('Will become private')->incident;
        });
        $privateIncident->forceFill(['visibility' => 'internal'])->save();

        $this->onPage($archived, function () {
            $this->subscriber('archived@example.test');
            $this->update('Will be archived');
        });
        $archived->forceFill(['archived_at' => now()])->save();

        $this->onPage($unpublished, function () {
            $this->subscriber('draft@example.test');
            $this->update('Never public');
        });

        $rows = SubscriberNotification::withoutGlobalScope('status_page')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$a->id, $archived->id], $rows->pluck('status_page_id')->all());
        $this->assertSame([0, 2], app(SubscriberNotifier::class)->sendPending());
        Mail::assertNothingSent();

        $rows = SubscriberNotification::withoutGlobalScope('status_page')->orderBy('id')->get();
        $this->assertSame([SubscriberNotifier::MAX_ATTEMPTS, SubscriberNotifier::MAX_ATTEMPTS], $rows->pluck('attempts')->all());
    }

    public function test_a_custom_smtp_failure_never_falls_back_to_the_central_mailer(): void
    {
        config([
            'mail.default' => 'array',
            'mail.mailers.array' => ['transport' => 'array'],
        ]);
        app('mail.manager')->forgetMailers();

        app(MailConfig::class)->savePage($this->pageMail([
            'mode' => 'custom',
            'host' => '127.0.0.1',
            'port' => 1,
            'encryption' => 'none',
            'username' => 'page-user',
            'password' => 'page-secret',
        ]));
        $this->subscriber('custom@example.test');
        $this->update('Custom SMTP outage');

        $this->assertSame([0, 1], app(SubscriberNotifier::class)->sendPending());

        $row = SubscriberNotification::firstOrFail();
        $this->assertNull($row->sent_at);
        $this->assertSame(1, $row->attempts);
        $this->assertNotEmpty($row->error);
        $this->assertSame('page-secret', app(MailConfig::class)->pagePassword());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_the_check_command_runs_every_non_archived_page_in_its_context(): void
    {
        $probe = new PageDeliveryProbe;
        $this->app->instance(Probe::class, $probe);

        $a = StatusPage::default();
        $b = StatusPage::create(['name' => 'Published', 'slug' => 'published', 'is_published' => true]);
        $draft = StatusPage::create(['name' => 'Draft', 'slug' => 'draft', 'is_published' => false]);
        $archived = StatusPage::create(['name' => 'Archived', 'slug' => 'archived', 'is_published' => true]);

        $this->onPage($a, fn () => $this->check('https://a.example.test'));
        $this->onPage($b, fn () => $this->check('https://b.example.test'));
        $this->onPage($draft, fn () => $this->check('https://draft.example.test'));
        $this->onPage($archived, fn () => $this->check('https://archived.example.test'));
        $archived->forceFill(['archived_at' => now()])->save();

        $this->artisan('pharos:check --force')->assertSuccessful();

        $this->assertSame([
            'https://a.example.test',
            'https://b.example.test',
            'https://draft.example.test',
        ], $probe->targets);
    }

    public function test_a_mixed_webhook_batch_uses_the_owning_page_and_canonical_url(): void
    {
        config(['app.url' => 'https://status.example.test']);
        Http::fake();

        $a = StatusPage::default();
        $b = StatusPage::create(['name' => 'Beta page', 'slug' => 'beta', 'is_published' => true]);

        $this->onPage($a, function () {
            WebhookEndpoint::create([
                'label' => 'Alpha Discord',
                'url' => 'https://203.0.113.10/alpha',
                'format' => 'discord',
                'enabled' => true,
            ]);
            app(OutgoingWebhook::class)->incidentChanged($this->incident('Alpha webhook'), 'incident.created');
        });
        $this->onPage($b, function () {
            WebhookEndpoint::create([
                'label' => 'Beta Discord',
                'url' => 'https://203.0.113.10/beta',
                'format' => 'discord',
                'enabled' => true,
            ]);
            app(OutgoingWebhook::class)->incidentChanged($this->incident('Beta webhook'), 'incident.created');
        });

        $rows = WebhookDelivery::query()->orderBy('id')->get();
        $this->assertSame([$a->id, $b->id], $rows->pluck('status_page_id')->all());
        $this->assertSame(2, app(OutgoingWebhook::class)->sendPending());

        $requests = Http::recorded()->map(fn ($pair) => json_decode($pair[0]->body(), true));
        $this->assertCount(2, $requests);
        $this->assertStringEndsWith("\nhttps://status.example.test", $requests[0]['content']);
        $this->assertStringEndsWith("\nhttps://status.example.test/status/beta", $requests[1]['content']);
    }

    public function test_an_archived_pages_signed_unsubscribe_url_stays_usable(): void
    {
        config(['app.url' => 'https://status.example.test']);
        $page = StatusPage::create(['name' => 'Beta page', 'slug' => 'beta', 'is_published' => true]);
        $subscriber = $this->onPage($page, fn () => $this->subscriber('archived@example.test'));
        $page->forceFill(['archived_at' => now(), 'is_published' => false])->save();

        $url = $subscriber->unsubscribeUrl();

        $this->assertStringContainsString('/status/beta/unsubscribe/'.$subscriber->id, $url);
        // The confirmation posts back to the page's own signed URL, not the legacy one.
        $this->get($url)->assertOk()->assertSee('>Unsubscribe</button>', false)
            ->assertSee('action="/status/beta/unsubscribe/'.$subscriber->id.'?', false);
        $this->assertNull($subscriber->fresh()->unsubscribed_at);
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk();
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
    }

    public function test_page_mail_settings_store_custom_smtp_credentials_only_on_that_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $page = StatusPage::create(['name' => 'Beta page', 'slug' => 'beta']);

        $this->actingAs($admin)->get('/admin/mail')->assertOk()->assertSee('Delivery');

        $this->actingAs($admin)->put('/admin/pages/'.$page->id.'/mail', $this->pageMail([
            'mode' => 'custom',
            'host' => 'smtp.beta.example.test',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => 'beta-user',
            'password' => 'beta-secret',
            'from_address' => 'status@beta.example.test',
            'from_name' => 'Beta sender',
            'reply_to' => 'support@beta.example.test',
        ]))->assertRedirect('/admin/pages/'.$page->id.'/mail');

        $settings = StatusPageSetting::query()->where('status_page_id', $page->id)->pluck('value', 'key');
        $this->assertSame('custom', $settings['mail.mode']);
        $this->assertSame('smtp.beta.example.test', $settings['mail.host']);
        $this->assertSame('status@beta.example.test', $settings['mail.from_address']);
        $this->assertNotSame('beta-secret', $settings['mail.password']);
        $this->assertSame('beta-secret', Crypt::decryptString($settings['mail.password']));

        $this->assertDatabaseMissing('status_page_settings', [
            'status_page_id' => StatusPage::default()->id,
            'key' => 'mail.host',
        ]);
        $this->actingAs($admin)->get('/admin/pages/'.$page->id.'/mail')->assertOk()
            ->assertSee('smtp.beta.example.test')
            ->assertSee('Stored, leave empty to keep')
            ->assertDontSee('beta-secret');
    }

    public function test_page_mail_test_uses_the_selected_pages_sender_and_transport(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin@example.test']);
        $page = StatusPage::create(['name' => 'Beta page', 'slug' => 'beta']);
        $this->onPage($page, function () {
            Setting::put('brand.name', 'Beta Status');
            app(MailConfig::class)->savePage($this->pageMail([
                'mode' => 'custom',
                'host' => 'smtp.beta.example.test',
                'port' => 587,
                'username' => 'beta-user',
                'password' => 'beta-secret',
                'from_address' => 'status@beta.example.test',
                'from_name' => 'Beta sender',
                'reply_to' => 'support@beta.example.test',
            ]));
        });

        $this->actingAs($admin)->post('/admin/pages/'.$page->id.'/mail-test')
            ->assertRedirect('/admin/pages/'.$page->id.'/mail')
            ->assertSessionHas('status', 'Test email sent to admin@example.test.');

        Mail::assertSent(TestMail::class, function (TestMail $mail) use ($page) {
            return $mail->hasTo('admin@example.test')
                && $mail->mailer === 'pharos_page_'.$page->id
                && $mail->envelope()->from->address === 'status@beta.example.test'
                && $mail->envelope()->from->name === 'Beta sender'
                && $mail->envelope()->replyTo[0]->address === 'support@beta.example.test'
                && $mail->envelope()->subject === 'Test email from Beta Status';
        });

        $mail = Mail::sent(TestMail::class)->first();
        $html = $mail->render();
        $text = (string) view($mail->textView, $mail->buildViewData());
        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString('Email, Delivery for Beta page', $part);
            $this->assertStringContainsString('own SMTP server', $part);
            $this->assertStringContainsString('smtp.beta.example.test', $part);
            $this->assertStringNotContainsString('pharos_page_', $part);
            $this->assertStringNotContainsString('mailer', $part);
            $this->assertStringNotContainsString('Settings', $part);
        }
    }

    public function test_page_mail_test_on_central_delivery_names_the_central_transport(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'email' => 'admin@example.test']);
        $page = StatusPage::create(['name' => 'Gamma page', 'slug' => 'gamma']);

        $this->actingAs($admin)->post('/admin/pages/'.$page->id.'/mail-test')
            ->assertSessionHas('status', 'Test email sent to admin@example.test.');

        $mail = Mail::sent(TestMail::class)->first();
        $html = $mail->render();
        $text = (string) view($mail->textView, $mail->buildViewData());
        foreach ([$html, $text] as $part) {
            $this->assertStringContainsString('Email, Delivery for Gamma page', $part);
            $this->assertStringContainsString('the central mail transport', $part);
            $this->assertStringNotContainsString('own SMTP server', $part);
            $this->assertStringNotContainsString('mailer', $part);
        }
    }

    public function test_archived_or_unpublished_pages_do_not_queue_or_send_webhooks(): void
    {
        Http::fake();
        $page = StatusPage::create(['name' => 'Published', 'slug' => 'published', 'is_published' => true]);
        $this->onPage($page, function () {
            WebhookEndpoint::create([
                'label' => 'Receiver',
                'url' => 'https://203.0.113.10/hook',
                'format' => 'generic',
                'enabled' => true,
            ]);
            app(OutgoingWebhook::class)->incidentChanged($this->incident('Queued first'), 'incident.created');
        });
        $page->forceFill(['archived_at' => now()])->save();

        $this->assertSame(0, app(OutgoingWebhook::class)->sendPending());
        Http::assertNothingSent();
        $this->assertSame(6, WebhookDelivery::firstOrFail()->attempts);

        $draft = StatusPage::create(['name' => 'Draft', 'slug' => 'draft-webhook', 'is_published' => false]);
        $this->onPage($draft, function () {
            WebhookEndpoint::create([
                'label' => 'Draft receiver',
                'url' => 'https://203.0.113.11/hook',
                'format' => 'generic',
                'enabled' => true,
            ]);
            app(OutgoingWebhook::class)->incidentChanged($this->incident('Never queued'), 'incident.created');
        });

        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_notify_prunes_stale_pending_subscribers_on_every_page(): void
    {
        Mail::fake();
        $a = StatusPage::default();
        $b = StatusPage::create(['name' => 'Beta page', 'slug' => 'prune-beta']);

        $this->onPage($a, fn () => $this->staleSubscriber('stale-a@example.test'));
        $this->onPage($b, function () {
            $this->staleSubscriber('stale-b@example.test');
            Subscriber::create(['email' => 'fresh-b@example.test', 'token' => Subscriber::freshToken()]);
        });

        $this->artisan('pharos:notify')->assertSuccessful()->expectsOutputToContain('forgot 2 unconfirmed');

        $remaining = Subscriber::withoutGlobalScope('status_page')->pluck('email')->all();
        $this->assertSame(['fresh-b@example.test'], $remaining);
    }

    public function test_custom_page_smtp_can_be_configured_when_central_smtp_is_not(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '']);
        $this->assertFalse(app(MailConfig::class)->configured());

        app(MailConfig::class)->savePage($this->pageMail([
            'mode' => 'custom',
            'host' => 'smtp.page.example.test',
            'port' => 587,
            'from_address' => 'status@page.example.test',
        ]));

        $this->assertTrue(app(MailConfig::class)->configured());
    }

    public function test_an_unreadable_custom_password_fails_without_using_another_transport(): void
    {
        Mail::fake();
        app(MailConfig::class)->savePage($this->pageMail([
            'mode' => 'custom',
            'host' => 'smtp.page.example.test',
            'port' => 587,
            'password' => 'valid-before-key-change',
        ]));
        StatusPageSetting::query()->where('status_page_id', StatusPage::default()->id)
            ->where('key', MailConfig::PASSWORD_KEY)->update(['value' => 'unreadable-ciphertext']);
        $user = User::factory()->create();

        $this->assertFalse(app(MailConfig::class)->configured());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stored page SMTP password cannot be decrypted.');

        app(MailConfig::class)->sendTo($user->email, new TestMail($user));
    }

    public function test_a_page_that_selects_starttls_refuses_to_send_without_it(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $pageId = StatusPage::default()->id;

        foreach (['tls' => true, 'none' => false, 'ssl' => false] as $encryption => $required) {
            app(MailConfig::class)->savePage($this->pageMail([
                'mode' => 'custom',
                'host' => 'smtp.page.example.test',
                'port' => $encryption === 'ssl' ? 465 : 587,
                'encryption' => $encryption,
                'username' => 'page-user',
                'password' => 'page-secret',
            ]));
            app(MailConfig::class)->sendTo($user->email, new TestMail($user));

            // A server (or someone in between) that does not offer STARTTLS must not
            // receive the page's SMTP password in plain text.
            $transport = app('mail.manager')->createSymfonyTransport(config("mail.mailers.pharos_page_$pageId"));
            $this->assertSame($required, $transport->isTlsRequired(), "encryption=$encryption");
        }
    }

    /** @return array<string, mixed> */
    private function pageMail(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'central',
            'host' => '',
            'port' => '',
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => 'status@example.test',
            'from_name' => '',
            'reply_to' => '',
        ], $overrides);
    }

    private function subscriber(string $email): Subscriber
    {
        return Subscriber::create([
            'email' => $email,
            'token' => Subscriber::freshToken(),
            'verified_at' => now(),
        ]);
    }

    private function staleSubscriber(string $email): Subscriber
    {
        $subscriber = Subscriber::create(['email' => $email, 'token' => Subscriber::freshToken()]);
        $subscriber->forceFill(['updated_at' => now()->subDays(Subscriber::PENDING_DAYS + 1)])->save();

        return $subscriber;
    }

    private function update(string $name): IncidentUpdate
    {
        $incident = $this->incident($name);
        $incident->components()->attach(Component::create(['name' => $name.' component'])->id, ['status' => 3]);

        return IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Investigating.',
        ]);
    }

    private function incident(string $name): Incident
    {
        return Incident::create([
            'name' => $name,
            'status' => IncidentStatus::Investigating,
            'impact' => 'minor',
            'visibility' => 'public',
            'occurred_at' => now(),
        ]);
    }

    private function check(string $target): Check
    {
        $component = Component::create(['name' => parse_url($target, PHP_URL_HOST)]);

        return Check::create([
            'component_id' => $component->id,
            'type' => CheckType::Http,
            'target' => $target,
            'interval_seconds' => 60,
            'retries' => 2,
        ]);
    }

    private function onPage(StatusPage $page, callable $callback): mixed
    {
        return app(PageContext::class)->run($page->id, $callback);
    }
}
