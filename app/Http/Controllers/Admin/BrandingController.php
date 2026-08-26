<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Branding;
use App\Services\License;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BrandingController extends Controller
{
    public function __construct(protected License $license, protected Branding $branding) {}

    public function edit()
    {
        return view('admin.branding', [
            'brand' => [
                'name' => $this->branding->name(),
                'accent' => $this->branding->accent(),
                'credit_hidden' => $this->branding->creditHidden(),
                'logo' => $this->branding->logoUrl(),
                'favicon' => $this->branding->faviconUrl(),
            ],
            'licensed' => $this->license->has(License::FEATURE_BRAND_PACK),
            'issuedTo' => $this->license->issuedTo(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'accent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'credit_hidden' => ['sometimes', 'boolean'],
            // SVG is deliberately not accepted: it can carry script, and this file
            // is served to every visitor of the status page.
            'logo' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=1200,max_height=400'],
            'favicon' => ['sometimes', 'image', 'mimes:png,ico,webp', 'max:128', 'dimensions:max_width=512,max_height=512'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        Setting::put('brand.name', $data['name']);
        Setting::put('brand.accent', strtolower($data['accent']));

        // Everything below is the paid half. Gated server side; hiding the inputs
        // alone would only be decoration.
        if (! $this->license->has(License::FEATURE_BRAND_PACK)) {
            return redirect()->route('admin.branding')->with('status', 'Branding saved.');
        }

        Setting::put('brand.credit_hidden', ($data['credit_hidden'] ?? false) ? '1' : '0');

        if ($request->boolean('remove_logo')) {
            $this->deleteStored('brand.logo_path');
        }

        if ($request->hasFile('logo')) {
            $this->deleteStored('brand.logo_path');
            Setting::put('brand.logo_path', $request->file('logo')->store('brand', 'public'));
        }

        if ($request->hasFile('favicon')) {
            $this->deleteStored('brand.favicon_path');
            Setting::put('brand.favicon_path', $request->file('favicon')->store('brand', 'public'));
        }

        return redirect()->route('admin.branding')->with('status', 'Branding saved.');
    }

    public function activate(Request $request)
    {
        $data = $request->validate(['key' => ['required', 'string']]);

        if (! $this->license->store($data['key'])) {
            return back()->withErrors(['key' => 'That key is not valid for this product.']);
        }

        return redirect()->route('admin.branding')
            ->with('status', 'Brand pack activated for '.$this->license->issuedTo().'.');
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
