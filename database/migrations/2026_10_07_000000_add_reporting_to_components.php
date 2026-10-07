<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('components', 'reported_at')) {
            Schema::table('components', function (Blueprint $table) {
                $table->timestamp('reported_at')->nullable();
                // The token may be revoked later; the component keeps its label then.
                $table->foreignId('reported_by_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            });
        }

        // "api" was written by older builds and is not a source the form knows.
        DB::table('components')->where('source', 'api')->update(['source' => 'webhook']);
    }

    public function down(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reported_by_token_id');
            $table->dropColumn('reported_at');
        });
    }
};
