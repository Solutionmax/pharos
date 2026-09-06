<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use Illuminate\Http\Request;

class KumaController extends Controller
{
    public function __invoke(Request $request, Component $component)
    {
        $data = $request->validate(['heartbeat.status' => ['required', 'integer', 'in:0,1']]);
        abort_if($component->check?->enabled, 422, 'Use a manually managed component for Kuma notifications.');
        $component->update(['status' => (int) $data['heartbeat']['status'] === 1 ? ComponentStatus::Operational : ComponentStatus::MajorOutage]);

        return response()->json(['ok' => true]);
    }
}
