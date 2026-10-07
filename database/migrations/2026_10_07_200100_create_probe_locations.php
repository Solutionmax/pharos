<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('token_hash', 64)->unique();
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('check_probe_location', function (Blueprint $t) {
            $t->foreignId('check_id')->constrained()->cascadeOnDelete();
            $t->foreignId('probe_location_id')->constrained()->cascadeOnDelete();
            $t->primary(['check_id', 'probe_location_id']);
        });
        Schema::create('probe_samples', function (Blueprint $t) {
            $t->id();
            $t->foreignId('check_id')->constrained()->cascadeOnDelete();
            $t->foreignId('probe_location_id')->constrained()->cascadeOnDelete();
            $t->boolean('ok');
            $t->unsignedInteger('latency_ms')->nullable();
            $t->timestamp('checked_at');
            $t->index(['check_id', 'probe_location_id', 'checked_at']);
        });
        Schema::create('probe_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('check_id')->constrained()->cascadeOnDelete();
            $t->foreignId('probe_location_id')->constrained()->cascadeOnDelete();
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
        Schema::table('checks', fn (Blueprint $t) => $t->string('quorum_summary')->nullable());
    }

    public function down(): void
    {
        Schema::table('checks', fn (Blueprint $t) => $t->dropColumn('quorum_summary'));
        foreach (['probe_jobs', 'probe_samples', 'check_probe_location', 'probe_locations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
