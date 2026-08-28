<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SelfUpdater;
use App\Services\Updater;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UpdateController extends Controller
{
    public function __construct(protected Updater $updater, protected SelfUpdater $selfUpdater) {}

    public function index(Request $request)
    {
        if ($request->boolean('refresh')) {
            // Redirect so the ?refresh=1 URL cannot be bookmarked or reloaded
            // into a fresh request each time.
            $check = $this->updater->lastCheck(fresh: true);

            return redirect()->route('admin.updates')->with('status', $this->checkedMessage($check));
        }

        $check = $this->updater->lastCheck();

        return view('admin.updates', [
            'current' => $this->updater->current(),
            'latest' => $check['manifest'],
            'available' => $this->updater->updateAvailable(),
            'check' => $check,
            'checkedAt' => $check['checked_at'] ? Carbon::parse($check['checked_at']) : null,
            'nextCheckAt' => $check['checked_at'] ? Carbon::parse($check['checked_at'])->addMinutes(Updater::CHECK_INTERVAL_MINUTES) : null,
            'manifestHost' => $this->updater->manifestHost(),
            'backups' => $this->selfUpdater->backups(),
            'managed' => $this->updater->managed(),
            'managedStatus' => $this->updater->managedStatus(),
            'writable' => $this->selfUpdater->canWrite(),
            'versionPinned' => $this->updater->versionIsPinned(),
        ]);
    }

    protected function checkedMessage(array $check): string
    {
        return 'Checked just now — '.match ($check['state']) {
            'ok' => $this->updater->updateAvailable() ? "{$check['manifest']['version']} is available." : 'nothing new.',
            'no_release' => 'nothing new.',
            'unreachable' => 'the release server could not be reached.',
            'invalid' => 'the release server answered, but the manifest is not signed by our key.',
            default => 'checking is switched off.',
        };
    }

    public function apply()
    {
        if ($this->updater->managed()) {
            return $this->updater->requestManagedUpdate()
                ? redirect()->route('admin.updates')->with('status', 'Asked the host to pull the new image. It restarts in a moment.')
                : back()->withErrors(['update' => 'Could not reach the host updater.']);
        }

        $result = $this->selfUpdater->apply();

        return $result['ok']
            ? redirect()->route('admin.updates')->with('status', $result['message'])
            : back()->withErrors(['update' => $result['message']]);
    }
}
