<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Clock;
use App\Services\Sso;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How this installation behaves: the time zone and single sign-on. Admin only.
 * What the public page shows lives on the Status page screen instead.
 */
class SettingsController extends Controller
{
    public function edit(Sso $sso)
    {
        return view('admin.settings', [
            'timezone' => Clock::timezone(),
            'offset' => Clock::offsetLabel(),
            'sso' => $sso,
            'callbackUrl' => route('admin.sso.callback'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
        ]);

        Setting::put('app.timezone', $data['timezone']);

        return redirect()->route('admin.settings')->with('status', 'Settings saved.');
    }
}
