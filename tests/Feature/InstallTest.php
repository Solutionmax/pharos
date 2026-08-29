<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    protected array $valid = [
        'site' => 'Acme Hosting',
        'name' => 'Raymon',
        'email' => 'raymon@example.net',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ];

    private function existingAdmin(): User
    {
        return User::create([
            'name' => 'Someone',
            'email' => 'someone@example.net',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
    }

    public function test_a_fresh_install_sends_the_root_url_to_setup(): void
    {
        $this->get('/')->assertRedirect(route('admin.install'));
    }

    public function test_a_fresh_install_sends_the_login_form_to_setup(): void
    {
        $this->get('/admin/login')->assertRedirect(route('admin.install'));
    }

    public function test_it_creates_the_administrator_names_the_page_and_signs_them_in(): void
    {
        $this->post('/admin/install', $this->valid)
            ->assertRedirect(route('admin.components'));

        $user = User::sole();

        $this->assertSame('Raymon', $user->name);
        $this->assertSame('raymon@example.net', $user->email);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->password));
        $this->assertSame('Acme Hosting', Setting::get('brand.name'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_setup_screen_is_gone_once_an_account_exists(): void
    {
        $this->existingAdmin();

        $this->get('/admin/install')->assertRedirect(route('admin.login'));
    }

    /**
     * The guard that matters. Without it the setup form stays a permanent
     * unauthenticated route for creating administrators.
     */
    public function test_it_refuses_to_create_a_second_administrator(): void
    {
        $this->existingAdmin();

        $this->post('/admin/install', $this->valid)
            ->assertRedirect(route('admin.login'));

        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->post('/admin/install', [...$this->valid, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_a_mistyped_confirmation(): void
    {
        $this->post('/admin/install', [...$this->valid, 'password_confirmation' => 'something-else-entirely'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_the_status_page_works_again_once_installed(): void
    {
        $this->post('/admin/install', $this->valid);

        $this->get('/')->assertOk()->assertSee('Acme Hosting');
    }

    public function test_the_wizard_takes_a_time_zone_and_defaults_to_utc(): void
    {
        $this->get('/admin/install')->assertOk()
            ->assertSee('<option value="UTC" selected', false)
            ->assertSee('Europe/Amsterdam');

        $this->post('/admin/install', $this->valid);
        $this->assertSame('UTC', Setting::get('app.timezone'));
    }

    public function test_the_wizard_stores_the_chosen_time_zone(): void
    {
        $this->post('/admin/install', [...$this->valid, 'timezone' => 'Europe/Amsterdam'])
            ->assertRedirect(route('admin.components'));

        $this->assertSame('Europe/Amsterdam', Setting::get('app.timezone'));
    }

    public function test_the_wizard_refuses_an_unknown_time_zone(): void
    {
        $this->post('/admin/install', [...$this->valid, 'timezone' => 'Not/AZone'])
            ->assertSessionHasErrors('timezone');

        $this->assertSame(0, User::count());
    }
}
