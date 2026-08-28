<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Ids of the "Good to know" notes this person has hidden. Per account,
            // not per install: what is old news to one colleague is new to the next.
            $table->json('dismissed_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('dismissed_notes'));
    }
};
