<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use Illuminate\Http\Request;

/**
 * Uptime Kuma's Webhook notification, as it sends it. Kuma knows four states;
 * pending means "not sure yet", which is not something a status page should say.
 */
class KumaController extends Controller
{
    /** heartbeat.status => component status; null leaves the component alone. */
    public const MAP = [
        0 => ComponentStatus::MajorOutage,
        1 => ComponentStatus::Operational,
        2 => null,
        3 => ComponentStatus::UnderMaintenance,
    ];

    public function __invoke(Request $request, Component $component)
    {
        $data = $request->validate([
            'heartbeat' => ['required', 'array'],
            'heartbeat.status' => ['required', 'integer', 'in:0,1,2,3'],
        ]);
        abort_if($component->check?->enabled, 422, 'Use a manually managed component for Kuma notifications.');

        $status = self::MAP[(int) $data['heartbeat']['status']];
        $changed = $status !== null && $component->status !== $status;
        if ($changed) {
            $component->update(['status' => $status]);
        }

        return response()->json(['ok' => true, 'changed' => $changed, 'status' => $component->status->value]);
    }
}
