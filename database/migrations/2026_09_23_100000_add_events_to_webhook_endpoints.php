<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null means every event, so destinations saved before the choice existed
        // keep receiving exactly what they received before.
        Schema::table('webhook_endpoints', fn (Blueprint $table) => $table->json('events')->nullable());
        Schema::table('webhook_deliveries', fn (Blueprint $table) => $table->string('event', 40)->nullable());
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', fn (Blueprint $table) => $table->dropColumn('events'));
        Schema::table('webhook_deliveries', fn (Blueprint $table) => $table->dropColumn('event'));
    }
};
