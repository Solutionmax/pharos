<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ComponentGroup;
use App\Models\Setting;
use App\Services\Branding;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(protected Branding $branding) {}

    public function edit()
    {
        return view('admin.settings', [
            'modules' => Branding::MODULES,
            'enabled' => $this->branding->modules(),
            'theme' => $this->branding->theme(),
            'incidentDays' => (int) Setting::get('page.incident_days', 5),
            'groups' => ComponentGroup::withCount('components')->orderBy('position')->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'theme' => ['required', Rule::in(['system', 'light', 'dark'])],
            'incident_days' => ['required', 'integer', 'min:1', 'max:30'],
            'modules' => ['sometimes', 'array'],
            'groups' => ['sometimes', 'array'],
        ]);

        Setting::put('brand.theme', $data['theme']);
        Setting::put('page.incident_days', (string) $data['incident_days']);

        // Unchecked boxes are absent from the request, so iterate over the known
        // module list rather than over what was submitted.
        foreach (array_keys(Branding::MODULES) as $key) {
            Setting::put($key, isset($data['modules'][$key]) ? '1' : '0');
        }

        // Same reason as the modules: an unticked box is absent from the request.
        foreach (ComponentGroup::all() as $group) {
            $group->update(['visible' => isset($data['groups'][$group->id])]);
        }

        return redirect()->route('admin.settings')->with('status', 'Settings saved.');
    }
}
