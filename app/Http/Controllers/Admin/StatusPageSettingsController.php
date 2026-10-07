<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComponentGroup;
use App\Models\Setting;
use App\Services\Branding;
use App\Services\Localization;
use App\Services\PageUrls;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What the public page shows and how it looks. Open to every account: shaping
 * the page is operational work, unlike Settings, which changes the installation.
 */
class StatusPageSettingsController extends Controller
{
    public function __construct(protected Branding $branding) {}

    public function edit()
    {
        return view('admin.status-page', [
            'modules' => Branding::MODULES,
            'enabled' => $this->branding->modules(),
            'theme' => $this->branding->theme(),
            'incidentDays' => (int) Setting::get('page.incident_days', 5),
            'locale' => Localization::page(),
            'groups' => ComponentGroup::withCount('components')->orderBy('position')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['system', 'light', 'dark'])],
            'incident_days' => ['required', 'integer', 'min:1', 'max:30'],
            'locale' => ['sometimes', 'string', Rule::in(array_keys(Localization::LANGUAGES))],
            'modules' => ['sometimes', 'array'],
            'groups' => ['sometimes', 'array'],
        ]);

        Setting::put('brand.theme', $data['theme']);
        Setting::put('page.incident_days', (string) $data['incident_days']);
        if (isset($data['locale'])) {
            Setting::put('page.locale', $data['locale']);
        }

        // Unchecked boxes are absent from the request, so iterate over the known
        // module list rather than over what was submitted.
        foreach (array_keys(Branding::MODULES) as $key) {
            Setting::put($key, isset($data['modules'][$key]) ? '1' : '0');
        }

        // Same reason as the modules: an unticked box is absent from the request.
        foreach (ComponentGroup::all() as $group) {
            $group->update(['visible' => isset($data['groups'][$group->id])]);
        }

        return redirect()->to(PageUrls::route('admin.status-page'))->with('status', 'Settings saved.');
    }
}
