<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Branding;
use App\Services\License;
use App\Services\PageContext;
use App\Services\PageUrls;
use App\Support\LicencePlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    /**
     * The upload rules in one table, so the screen can print the same limits the
     * validator enforces. SVG is deliberately not accepted: it can carry script,
     * and these files are served to every visitor of the status page.
     */
    public const UPLOADS = [
        'logo' => ['mimes' => ['png', 'jpg', 'jpeg', 'webp'], 'max_kb' => 512, 'max_width' => 1200, 'max_height' => 400],
        'logo_dark' => ['mimes' => ['png', 'jpg', 'jpeg', 'webp'], 'max_kb' => 512, 'max_width' => 1200, 'max_height' => 400],
        'favicon' => ['mimes' => ['png', 'ico', 'webp'], 'max_kb' => 128, 'max_width' => 512, 'max_height' => 512],
    ];

    /** Setting key per upload field. */
    protected const PATHS = ['logo' => 'brand.logo_path', 'logo_dark' => 'brand.logo_dark_path', 'favicon' => 'brand.favicon_path'];

    public function __construct(protected License $license, protected Branding $branding) {}

    /** @return list<string> */
    public static function uploadRules(string $field): array
    {
        $spec = self::UPLOADS[$field];

        return [
            'sometimes',
            // Laravel's "image" rule knows no ICO, so it would refuse every .ico
            // favicon the form invites. The mimes rule reads the file's content.
            ...($field === 'favicon' ? [] : ['image']),
            'mimes:'.implode(',', $spec['mimes']),
            'max:'.$spec['max_kb'],
            'dimensions:max_width='.$spec['max_width'].',max_height='.$spec['max_height'],
        ];
    }

    public function edit()
    {
        $plan = LicencePlan::read($this->license);

        return view('admin.branding', [
            'plan' => $plan,
            'uploads' => self::UPLOADS,
            // Kept on disk while no key is active, so a returning key restores them.
            'saved' => collect(self::PATHS)->map(fn (string $key) => (bool) Setting::get($key))->all(),
            'defaults' => [
                'logo' => $this->branding->builtInAssetUrl('pharos-logo.svg'),
                'logo_white' => $this->branding->builtInAssetUrl('pharos-logo-white.svg'),
                'mark' => $this->branding->builtInAssetUrl('pharos-mark.svg'),
                'mark_white' => $this->branding->builtInAssetUrl('pharos-mark-white.svg'),
                'favicon' => $this->branding->builtInAssetUrl('pharos-favicon.svg'),
                'email_logo' => $this->branding->builtInAssetUrl('pharos-email-logo.png'),
            ],
            'brand' => [
                'name' => $this->branding->name(),
                'accent' => $this->branding->accent(),
                'credit_hidden' => $this->branding->creditHidden(),
                'logo' => $this->branding->logoUrl(),
                'logo_dark' => $this->branding->logoDarkUrl(),
                'favicon' => $this->branding->faviconUrl(),
                'favicon_custom' => $this->branding->licensed() && $this->branding->faviconUrl() !== $this->branding->builtInAssetUrl('pharos-favicon.svg'),
            ],
            'licensed' => $this->license->has(License::FEATURE_BRAND_PACK),
            'issuedTo' => $this->license->issuedTo(),
            'expiresAt' => $this->license->expiresAt(),
            'daysLeft' => $this->license->daysLeft(),
            'expiringSoon' => $this->license->expiringSoon(),
            'boundTo' => $this->license->payload() ? $this->license->boundTo($this->license->payload()) : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'credit_hidden' => ['sometimes', 'boolean'],
            'logo' => self::uploadRules('logo'),
            'favicon' => self::uploadRules('favicon'),
            'logo_dark' => self::uploadRules('logo_dark'),
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_logo_dark' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
        ]);

        Setting::put('brand.name', $data['name']);
        Setting::put('brand.accent', strtolower($data['accent']));

        // Everything below is the paid half. Gated server side; hiding the inputs
        // alone would only be decoration.
        if (! $this->license->has(License::FEATURE_BRAND_PACK)) {
            return redirect()->to(PageUrls::route('admin.branding'))->with('status', 'Branding saved.');
        }

        Setting::put('brand.credit_hidden', ($data['credit_hidden'] ?? false) ? '1' : '0');

        if ($request->boolean('remove_logo')) {
            $this->deleteStored('brand.logo_path');
        }

        if ($request->hasFile('logo')) {
            $this->deleteStored('brand.logo_path');
            Setting::put('brand.logo_path', $request->file('logo')->store('brand/pages/'.app(PageContext::class)->id(), 'public'));
        }

        // A dark logo on its own would leave the light theme with the built-in mark
        // next to a custom dark one; harmless, so not forbidden.
        if ($request->boolean('remove_logo_dark')) {
            $this->deleteStored('brand.logo_dark_path');
        }

        if ($request->hasFile('logo_dark')) {
            $this->deleteStored('brand.logo_dark_path');
            Setting::put('brand.logo_dark_path', $request->file('logo_dark')->store('brand/pages/'.app(PageContext::class)->id(), 'public'));
        }

        if ($request->boolean('remove_favicon')) {
            $this->deleteStored('brand.favicon_path');
        }

        if ($request->hasFile('favicon')) {
            $this->deleteStored('brand.favicon_path');
            Setting::put('brand.favicon_path', $request->file('favicon')->store('brand/pages/'.app(PageContext::class)->id(), 'public'));
        }

        return redirect()->to(PageUrls::route('admin.branding'))->with('status', 'Branding saved.');
    }

    public function activate(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string']]);

        if (! $this->license->store($data['key'])) {
            return back()->withErrors(['key' => $this->license->whyNot($data['key']) ?? 'That key is not valid for this product.']);
        }

        // Not "Brand pack activated": a Multi page key carries no Brand pack.
        $to = $this->license->issuedTo();

        return redirect()->to(PageUrls::route('admin.branding'))
            ->with('status', 'Key activated'.($to ? ' for '.$to : '').'. Plan: '.LicencePlan::read($this->license)->name().'.');
    }

    /** Takes the key out; uploads stay on disk so pasting it back restores everything. */
    public function deactivate()
    {
        $this->license->forget();

        return redirect()->to(PageUrls::route('admin.branding'))->with('status', 'Key removed. Branding is back to the free defaults; paste the key again to restore it.');
    }

    /** Replacing an upload must not leave the old file behind on disk. */
    protected function deleteStored(string $key): void
    {
        if ($path = Setting::get($key)) {
            Storage::disk('public')->delete($path);
            Setting::put($key, null);
        }
    }
}
