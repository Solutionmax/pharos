<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\InstallSettings;
use App\Models\AuditEntry;
use App\Services\Clock;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->string('actor')->toString();
        $subject = $request->string('subject')->toString();

        $entries = $this->query($filter, $subject)->paginate(50)->withQueryString();

        return view('admin.audit', [
            'entries' => $entries,
            'actor' => $filter,
            // Not $action: partials.pagehead already uses that name for its button.
            'subject' => $subject,
            // Distinct subjects rather than full actions, so the filter stays
            // short as the list of verbs grows.
            'subjects' => AuditEntry::query()
                ->distinct()
                ->pluck('action')
                ->map(fn ($a) => explode('.', $a)[0])
                ->unique()->sort()->values(),
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
        $filter = $request->string('actor')->toString();
        $subject = $request->string('subject')->toString();
        $name = 'pharos-audit-'.Clock::now()->format('Ymd-Hi').'.csv';

        return response()->streamDownload(function () use ($filter, $subject) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['when', 'actor', 'ip', 'action', 'subject', 'changes']);

            $this->query($filter, $subject)->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $e) {
                    $changes = collect($e->changes ?? [])
                        ->map(fn ($c, $field) => $field.': '.($c['from'] ?? '—').' → '.($c['to'] ?? '—'))
                        ->implode('; ');
                    fputcsv($out, [$e->created_at->toIso8601String(), $e->actor, $e->ip, $e->action, $e->subject_label, $changes]);
                }
            }, 'id');

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function query(string $filter, string $subject)
    {
        return AuditEntry::query()
            ->when($filter !== '', fn ($q) => $q->where('actor', 'like', '%'.$filter.'%'))
            ->when($subject !== '', fn ($q) => $q->where('action', 'like', $subject.'%'))
            ->latest('id');
    }
}
