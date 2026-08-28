<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * One place that answers "what does this install look like and what does it show".
 * Every default is the free behaviour, so an unlicensed install is complete,
 * not crippled.
 */
class Branding
{
    /** Page modules, with their defaults. Everything on: a new install shows a full page. */
    public const MODULES = [
        'page.show_overall' => ['label' => 'Overall status banner', 'help' => 'The "All systems operational" headline.', 'default' => true],
        'page.show_uptime' => ['label' => 'Uptime bar and percentage', 'help' => 'The 90-day bar under the headline.', 'default' => true],
        'page.show_services' => ['label' => 'Services list', 'help' => 'Components grouped into services.', 'default' => true],
        'page.show_component_uptime' => ['label' => 'Uptime bar per component', 'help' => 'The small bar next to each component.', 'default' => true],
        'page.show_incidents' => ['label' => 'Incident history', 'help' => 'Incidents grouped per day.', 'default' => true],
        'page.show_empty_days' => ['label' => 'Days without incidents', 'help' => 'Shows "No incidents" rather than skipping the day.', 'default' => true],
        'page.show_api_link' => ['label' => 'API link in the footer', 'help' => 'Points at the public JSON feed.', 'default' => true],
        'page.show_subscribe' => ['label' => '"Get notified" button', 'help' => 'Lets visitors subscribe to incident e-mails. Needs mail settings (Settings → Mail) and the switch on the Subscribers screen.', 'default' => true],
    ];

    public function module(string $key): bool
    {
        $default = self::MODULES[$key]['default'] ?? true;

        return Setting::get($key, $default ? '1' : '0') === '1';
    }

    /** @return array<string, bool> */
    public function modules(): array
    {
        return collect(self::MODULES)->map(fn ($m, $key) => $this->module($key))->all();
    }

    public function name(): string
    {
        return Setting::get('brand.name', 'Pharos');
    }

    public function accent(): string
    {
        return Setting::get('brand.accent', '#0079d2');
    }

    /** system | light | dark — what the page starts on before a visitor chooses. */
    public function theme(): string
    {
        $theme = Setting::get('brand.theme', 'system');

        return in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
    }

    public function creditHidden(): bool
    {
        return Setting::get('brand.credit_hidden', '0') === '1';
    }

    /** Uploaded logo, or null to fall back to the built-in mark. */
    public function logoUrl(): ?string
    {
        return $this->assetUrl('brand.logo_path');
    }

    /** Second logo for the dark theme; the light one is the fallback. */
    public function logoDarkUrl(): ?string
    {
        return $this->assetUrl('brand.logo_dark_path');
    }

    public function faviconUrl(): string
    {
        return $this->assetUrl('brand.favicon_path') ?? asset('brand/pharos-favicon.svg');
    }

    protected function assetUrl(string $key): ?string
    {
        $path = Setting::get($key);

        // A setting pointing at a file that is gone must not render a broken image.
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
