<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SelfUpdater;
use App\Services\Updater;
use Illuminate\Http\Request;

class UpdateController extends Controller
{
    public function __construct(protected Updater $updater, protected SelfUpdater $selfUpdater) {}

    public function index(Request $request)
    {
        return view('admin.updates', [
            'current' => $this->updater->current(),
            'latest' => $this->updater->latest(fresh: $request->boolean('refresh')),
            'available' => $this->updater->updateAvailable(),
            'managed' => $this->updater->managed(),
            'managedStatus' => $this->updater->managedStatus(),
            'writable' => $this->selfUpdater->canWrite(),
            'versionPinned' => $this->updater->versionIsPinned(),
        ]);
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
