<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\UptimeDay;

class MetricsController extends Controller
{
    public function __invoke()
    {
        $components = Component::where('enabled', true)->orderBy('id')->limit(5000)->get(['id', 'name', 'status']);
        $days = UptimeDay::whereIn('component_id', $components->modelKeys())->where('day', '>=', now('UTC')->subDays(29)->startOfDay())->where('day', '<=', now('UTC')->endOfDay())
            ->groupBy('component_id')->selectRaw('component_id, SUM(up_seconds) as up_seconds, SUM(down_seconds) as down_seconds')->get()->keyBy('component_id');
        $text = "# HELP pharos_component_status Current component status (1 operational, 2 degraded, 3 partial outage, 4 major outage, 5 maintenance).\n# TYPE pharos_component_status gauge\n";
        $ratios = "# HELP pharos_component_uptime_ratio Fraction of observed seconds up over 30 UTC days; NaN means no observations.\n# TYPE pharos_component_uptime_ratio gauge\n";
        foreach ($components as $component) {
            $name = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', mb_substr($component->name, 0, 255));
            $name = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $name);
            $labels = '{component_id="'.$component->id.'",name="'.$name.'"}';
            $text .= 'pharos_component_status'.$labels.' '.$component->status->value."\n";
            $day = $days->get($component->id);
            $total = ($day->up_seconds ?? 0) + ($day->down_seconds ?? 0);
            $ratios .= 'pharos_component_uptime_ratio'.$labels.' '.($total > 0 ? number_format($day->up_seconds / $total, 6, '.', '') : 'NaN')."\n";
        }

        return response($text.$ratios)->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->header('Cache-Control', 'no-store');
    }
}
