<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\Maintenance;
use App\Services\Clock;
use App\Services\MaintenanceScheduler;
use App\Services\PageContext;
use App\Services\PageUrls;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaintenanceController extends Controller
{
    public function index()
    {
        $open = Maintenance::with('components')->whereNull('cancelled_at')->whereNull('completed_at')
            ->where('ends_at', '>', now())->orderBy('starts_at')->get();
        $past = Maintenance::with('components')
            ->where(fn ($q) => $q->whereNotNull('cancelled_at')->orWhereNotNull('completed_at')->orWhere('ends_at', '<=', now()))
            ->latest('starts_at')->paginate(15)->withQueryString();

        return view('admin.maintenance', [
            'open' => $open,
            'past' => $past,
            'canEditPage' => request()->user()->canEditPage(app(PageContext::class)->id()),
        ]);
    }

    public function create()
    {
        return $this->form(new Maintenance([
            'announce_minutes' => 1440,
            'starts_at' => now()->addDay()->setTime(22, 0),
            'ends_at' => now()->addDay()->setTime(23, 59),
        ]));
    }

    public function edit(Maintenance $maintenance)
    {
        abort_unless($maintenance->isOpen(), 404);

        return $this->form($maintenance->load('components'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $maintenance = DB::transaction(function () use ($data) {
            $maintenance = Maintenance::create(collect($data)->except('components')->all());
            $maintenance->components()->sync($data['components'] ?? []);

            return $maintenance;
        });

        return redirect()->to(PageUrls::route('admin.maintenance'))
            ->with('status', "Maintenance \"{$maintenance->title}\" scheduled.");
    }

    public function update(Request $request, Maintenance $maintenance)
    {
        abort_unless($maintenance->isOpen(), 404);
        $data = $this->validated($request, $maintenance);
        DB::transaction(function () use ($maintenance, $data) {
            $started = $maintenance->started_at !== null;
            $attributes = collect($data)->except('components')->all();
            // New times after the announcement: announce again, with the right ones.
            if (! $started && $maintenance->announced_at !== null
                && (! $maintenance->starts_at->equalTo($data['starts_at']) || ! $maintenance->ends_at->equalTo($data['ends_at']))) {
                $attributes['announced_at'] = null;
            }
            $maintenance->update($attributes);
            if (! $started) {
                $maintenance->components()->sync($data['components'] ?? []);
            }
        });

        return redirect()->to(PageUrls::route('admin.maintenance'))
            ->with('status', "Maintenance \"{$maintenance->title}\" saved.");
    }

    public function cancel(Maintenance $maintenance, MaintenanceScheduler $scheduler)
    {
        if (! $scheduler->cancel($maintenance)) {
            throw ValidationException::withMessages(['maintenance' => 'This maintenance is already over or cancelled.']);
        }

        return redirect()->to(PageUrls::route('admin.maintenance'))
            ->with('status', "Maintenance \"{$maintenance->title}\" cancelled. Affected components are back to how they were.");
    }

    protected function form(Maintenance $maintenance)
    {
        return view('admin.maintenance-form', [
            'maintenance' => $maintenance,
            'components' => Component::with('group')->orderBy('position')->get(),
            'selected' => old('components', $maintenance->exists ? $maintenance->components->pluck('id')->all() : []),
        ]);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Maintenance $maintenance): array
    {
        $started = $maintenance?->started_at !== null;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:5000'],
            'starts_at' => [$started ? 'exclude' : 'required', 'date'],
            'ends_at' => ['required', 'date', ...($started ? [] : ['after:starts_at'])],
            'announce_minutes' => [$started ? 'exclude' : 'required', 'integer', Rule::in(array_keys(Maintenance::LEAD_TIMES))],
            'components' => [$started ? 'exclude' : 'sometimes', 'array'],
            'components.*' => $started ? ['exclude'] : ['integer', 'distinct', Rule::exists('components', 'id')->where('status_page_id', app(PageContext::class)->id())],
        ], ['ends_at.after' => 'The end has to be after the start.']);
        // A datetime-local value is the customer's wall time, the same way the cast reads it.
        foreach (['starts_at', 'ends_at'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = CarbonImmutable::parse((string) $data[$field], Clock::timezone())->utc();
            }
        }
        $start = $data['starts_at'] ?? $maintenance?->starts_at;
        if ($data['ends_at']->lte(now()) || ($start !== null && $data['ends_at']->lte($start))) {
            throw ValidationException::withMessages(['ends_at' => 'The end has to be after the start, and still to come.']);
        }

        return $data;
    }
}
