<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocalTimestamps;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IncidentUpdate extends Model
{
    use Auditable, LocalTimestamps;

    protected $auditName = 'incident_update';

    protected $guarded = [];

    protected $casts = [
        'status' => IncidentStatus::class,
        'automatic' => 'boolean',
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * The message as HTML. Operators write Markdown, because the same text is
     * also delivered to Slack, Teams and any generic receiver, where HTML tags
     * would show up literally.
     *
     * html_input=escape is what keeps this safe to print unescaped: anything
     * that looks like a tag is rendered as text, so a pasted <script> ends up
     * visible instead of running. Do not relax it.
     */
    public function messageHtml(): string
    {
        return Str::markdown($this->message ?? '', [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            // Plain CommonMark folds a single newline into a space, so two
            // typed lines arrive as one. An operator writing an outage notice
            // means the line break they pressed; a blank line still starts a
            // new paragraph. Same rule as a GitHub comment.
            'renderer' => ['soft_break' => "<br>\n"],
        ]);
    }
}
