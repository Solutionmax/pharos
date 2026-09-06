<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table): void {
            $table->string('subject_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep text IDs: narrowing back to integers would destroy setting audit records.
        // Older application versions can read this backwards-compatible column.
    }
};
