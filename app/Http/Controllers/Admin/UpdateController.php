<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Services\SelfUpdater;
use App\Services\Updater;
use App\Support\Bytes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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
            'sqlite' => DB::getDriverName() === 'sqlite',
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

    public function apply(Request $request)
    {
        if ($this->updater->managed()) {
            return $this->updater->requestManagedUpdate()
                ? redirect()->route('admin.updates')->with('status', 'Asked the host to pull the new image. It restarts in a moment.')
                : back()->withErrors(['update' => 'Could not reach the host updater.']);
        }

        // The screen asks for JSON so it can show the steps as they happen; the
        // plain form post (no JS) still gets the redirect and the flash.
        $result = $this->selfUpdater->apply();

        if ($request->wantsJson()) {
            return response()->json($result, $result['ok'] ? 200 : 422);
        }

        return $result['ok']
            ? redirect()->route('admin.updates')->with('status', $result['message'])
            : back()->withErrors(['update' => $result['message']]);
    }

    /** A backup on demand: before a manual change, or just because it has been a while. */
    public function backup(Request $request)
    {
        // The screen asks for JSON so it can show the progress bar; the plain
        // form post (no JS) still gets the redirect and the flash.
        try {
            $backup = $this->selfUpdater->backupCurrent();
        } catch (\Throwable $e) {
            $message = 'Backup failed: '.$e->getMessage();

            return $request->wantsJson()
                ? response()->json(['ok' => false, 'message' => $message], 422)
                : back()->withErrors(['backup' => $message]);
        }

        $name = basename($backup);
        $size = Bytes::human($this->selfUpdater->treeSize($backup));
        $pruned = $this->selfUpdater->pruned();
        Audit::record('backup.created', null, array_filter(['name' => $name, 'size' => $size, 'removed' => implode(', ', $pruned)]));

        $message = "Backup made: {$name} ({$size})".($pruned ? ' Removed the oldest: '.implode(', ', $pruned).'.' : '');

        return $request->wantsJson()
            ? response()->json(['ok' => true, 'name' => $name, 'size' => $size, 'message' => $message])
            : redirect()->route('admin.updates')->with('status', $message);
    }

    /** Polled by the screen while a backup runs. */
    public function progress()
    {
        return response()->json($this->selfUpdater->progress())->header('Cache-Control', 'no-store');
    }

    public function download(string $name)
    {
        abort_if($this->selfUpdater->backupPath($name) === null, 404);

        $zip = $this->selfUpdater->zipBackup($name);
        Audit::record('backup.downloaded', null, ['name' => $name]);

        return response()->download($zip, "{$name}.zip")->deleteFileAfterSend(true);
    }

    /** A kept backup put back over the running install; what it replaces is backed up first. */
    public function rollback(Request $request, string $name)
    {
        abort_if($this->selfUpdater->backupPath($name) === null, 404);

        // Taken before the database is swapped: the account doing this may not
        // exist in the copy being restored, and a user_id that no longer resolves
        // would fail the foreign key — after the rollback already happened.
        $actor = Audit::actor() ?? 'unknown';

        $result = $this->selfUpdater->rollback($name);

        if (! $result['ok']) {
            return $request->wantsJson()
                ? response()->json(['ok' => false, 'message' => $result['message']], 422)
                : back()->withErrors(['rollback' => $result['message']]);
        }

        Audit::recordAs($actor, 'backup.restored', null, ['name' => $name, 'safety' => $result['safety']]);

        // The session store came back with the database, so this session is gone
        // either way. Say it, rather than letting the next click land on a login
        // page with no explanation.
        $message = $result['message'].' You have been signed out because the session store was restored too — sign in again with the password you had at the time of that backup.';
        // Not Auth::logout(): that fires the logout event, whose audit line carries a
        // user_id that may no longer exist in the restored database.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::guard()->forgetUser();

        return $request->wantsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            // Not a flash: the session store was just replaced, so a flash would not survive.
            : redirect()->route('admin.login', ['after' => 'rollback']);
    }

    public function destroy(string $name)
    {
        $path = $this->selfUpdater->backupPath($name);
        abort_if($path === null, 404);

        File::deleteDirectory($path);
        Audit::record('backup.deleted', null, ['name' => $name]);

        return redirect()->route('admin.updates')->with('status', "Backup {$name} removed.");
    }
}
