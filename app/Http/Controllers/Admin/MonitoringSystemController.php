<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use App\Models\Setting;
use App\Services\RemoteBackup;
use App\Services\SafeHttp;
use App\Services\SftpFingerprint;
use Illuminate\Http\Request;

class MonitoringSystemController extends Controller
{
    public function index()
    {
        return view('admin.monitoring-system', ['destinations' => BackupDestination::orderBy('name')->get(), 'webEnabled' => Setting::get('cron.web_enabled') === '1', 'cronConfigured' => strlen((string) config('monitoring.web_cron_token')) >= 32]);
    }

    public function cron(Request $r)
    {
        $r->validate(['enabled' => ['required', 'boolean']]);
        if ($r->boolean('enabled') && strlen((string) config('monitoring.web_cron_token')) < 32) {
            return back()->withErrors(['enabled' => __('Configure a cron credential in the private environment first.')]);
        }Setting::put('cron.web_enabled', $r->boolean('enabled') ? '1' : '0');

        return back()->with('status', __('Web scheduler setting saved.'));
    }

    public function store(Request $r, SafeHttp $safe)
    {
        $input = $r->all();
        $r->replace(collect($input)->except(['password', 'key', 'secret', 'endpoint'])->all());
        $driver = $input['driver'] ?? '';
        $rules = ['name' => 'required|string|max:100', 'driver' => 'required|in:s3,sftp', 'allow_private' => 'sometimes|boolean'];
        if ($driver === 'sftp') {
            $rules += ['host' => 'required|string|max:253|regex:/^[a-zA-Z0-9.:_-]+$/', 'port' => 'nullable|integer|min:1|max:65535', 'username' => 'required|string|max:100', 'password' => 'required|string|max:4096', 'fingerprint' => ['required', 'string', 'max:200', function ($attribute, $value, $fail) {
                if (! SftpFingerprint::valid($value)) {
                    $fail(__('Use a complete SHA256 or SHA512 SSH host fingerprint.'));
                }
            }], 'root' => 'required|string|max:255|starts_with:/'];
        } else {
            $rules += ['endpoint' => ['nullable', 'url:https', 'max:255', function ($attribute, $value, $fail) {
                $parts = parse_url($value);
                if (! is_array($parts) || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
                    $fail(__('Use an HTTPS endpoint without credentials, query strings or fragments.'));
                }
            }], 'region' => 'required|string|max:100|regex:/^[a-z0-9-]+$/', 'bucket' => 'required|string|max:63|regex:/^[a-z0-9][a-z0-9.-]+$/', 'prefix' => 'nullable|string|max:200|regex:/^[a-zA-Z0-9_\/-]*$/', 'key' => 'required|string|max:200', 'secret' => 'required|string|max:4096'];
        }
        $data = validator($input, $rules)->validate();
        try {
            if ($driver === 'sftp') {
                $host = $data['host'];
            } else {
                $host = parse_url($data['endpoint'] ?? 'https://s3.'.$data['region'].'.amazonaws.com', PHP_URL_HOST);
            }($data['allow_private'] ?? false) ? $safe->resolveOwn($host) : $safe->resolve($host);
        } catch (\Throwable $e) {
            return back()->withErrors(['host' => __('That destination is not reachable or allowed.')]);
        }abort_if(BackupDestination::count() >= 10, 422);
        BackupDestination::create(['name' => $data['name'], 'driver' => $driver, 'configuration' => collect($data)->except(['name', 'driver', 'password', 'key', 'secret'])->all(), 'credentials' => collect($data)->only(['password', 'key', 'secret'])->all()]);

        return back()->with('status', __('Backup destination saved.'));
    }

    public function run(BackupDestination $destination, RemoteBackup $backup)
    {
        $ok = $backup->run($destination);

        return back()->with('status', $ok ? __('Backup delivered and verified.') : __('Backup failed. Review the destination status.'));
    }

    public function destroy(BackupDestination $destination)
    {
        $destination->delete();

        return back()->with('status', __('Backup destination removed.'));
    }
}
