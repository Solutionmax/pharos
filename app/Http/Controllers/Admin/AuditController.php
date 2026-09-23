<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Clock;
use App\Services\InstallSettings;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $entries = $this->query($filters)->with('statusPage:id,name')->paginate(50)->withQueryString();

        return view('admin.audit', [
            'entries' => $entries,
            'filters' => $filters,
            'actor' => $filters['actor'],
            // Not $action: partials.pagehead already uses that name for its button.
            'subject' => $filters['subject'],
            // Distinct subjects rather than full actions, so the filter stays
            // short as the list of verbs grows.
            'subjects' => AuditEntry::query()
                ->distinct()
                ->pluck('action')
                ->map(fn ($a) => explode('.', $a)[0])
                ->unique()->sort()->values(),
            'actionOptions' => AuditEntry::query()->distinct()->orderBy('action')->pluck('action'),
            'people' => User::whereIn('id', AuditEntry::query()->whereNotNull('user_id')->distinct()->select('user_id'))->orderBy('name')->get(['id', 'name', 'email']),
            'pages' => StatusPage::orderBy('id')->get(['id', 'name']),
            'retentionDays' => InstallSettings::auditDays(),
        ]);
    }

    /**
     * The whole filtered log as CSV, streamed so a year of entries does not
     * have to fit in memory. Same filter as the page: what you see is what
     * you get, only all of it.
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $name = 'pharos-audit-'.Clock::now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel then reads the arrows and dashes as UTF-8
            fputcsv($out, ['when', 'actor', 'ip', 'action', 'subject', 'changes']);

            $this->query($filters)->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $e) {
                    $changes = collect($e->changes ?? [])
                        ->map(fn ($c, $field) => is_array($c) ? $field.': '.($c['from'] ?? 'empty').' → '.($c['to'] ?? 'empty') : $field.': '.$c)
                        ->implode('; ');
                    fputcsv($out, array_map(Csv::cell(...), [$e->created_at->toIso8601String(), $e->actor, $e->ip, $e->action, $e->subject_label, $changes]));
                }
            }, 'id');

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{actor: string, subject: string, user: ?int, page_id: ?int, action: string} */
    protected function filters(Request $request): array
    {
        $data = $request->validate([
            'actor' => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:60'],
            'user' => ['nullable', 'integer', 'min:1'],
            'page_id' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:80'],
        ]);

        return [
            'actor' => (string) ($data['actor'] ?? ''),
            'subject' => (string) ($data['subject'] ?? ''),
            'user' => isset($data['user']) ? (int) $data['user'] : null,
            'page_id' => isset($data['page_id']) ? (int) $data['page_id'] : null,
            'action' => (string) ($data['action'] ?? ''),
        ];
    }

    /**
     * @param  array{actor: string, subject: string, user: ?int, page_id: ?int, action: string}  $filters
     * @return Builder<AuditEntry>
     */
    protected function query(array $filters): Builder
    {
        return AuditEntry::query()
            ->when($filters['actor'] !== '', fn ($q) => $q->where('actor', 'like', '%'.$filters['actor'].'%'))
            ->when($filters['subject'] !== '', fn ($q) => $q->where('action', 'like', $filters['subject'].'%'))
            ->when($filters['action'] !== '', fn ($q) => $q->where('action', $filters['action']))
            ->when($filters['user'] !== null, fn ($q) => $q->where('user_id', $filters['user']))
            ->when($filters['page_id'] !== null, fn ($q) => $q->where('status_page_id', $filters['page_id']))
            ->latest('id');
    }
}
