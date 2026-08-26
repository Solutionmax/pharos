<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentTemplate extends Model
{
    protected $guarded = [];

    /** Replaces {{name}} placeholders. Unknown placeholders are left alone, never blanked. */
    public function render(string $field, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn ($m) => $vars[$m[1]] ?? $m[0],
            (string) $this->{$field},
        );
    }
}
