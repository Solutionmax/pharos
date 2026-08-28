<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->string('actor')->toString();
        $subject = $request->string('subject')->toString();

        $entries = AuditEntry::query()
            ->when($filter !== '', fn ($q) => $q->where('actor', 'like', '%'.$filter.'%'))
            ->when($subject !== '', fn ($q) => $q->where('action', 'like', $subject.'%'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

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
            'retentionDays' => (int) config('pharos.audit_days'),
        ]);
    }
}
