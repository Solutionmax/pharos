<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('probe_locations', fn (Blueprint $table) => $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete());
        // Existing credentials have no attributable issuer. Keep their setup/history, revoke credentials.
        DB::table('probe_locations')->whereNull('owner_id')->update(['enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('probe_locations', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_id'));
    }
};
