<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class WebCronController extends Controller
{
    public function __invoke(Request $request)
    {
        abort_unless(Setting::get('cron.web_enabled') === '1', 404);
        $secret = (string) config('monitoring.web_cron_token');
        $token = (string) $request->bearerToken();
        abort_unless(strlen($secret) >= 32 && strlen($token) <= 200 && hash_equals(hash('sha256', $secret), hash('sha256', $token)), 401);
        $lock = Cache::lock('pharos:web-scheduler', 300);
        abort_unless($lock->get(), 409);
        try {
            $exit = Artisan::call('schedule:run');

            return response()->json(['ok' => $exit === 0], $exit === 0 ? 200 : 503);
        } finally {
            $lock->release();
        }
    }
}
