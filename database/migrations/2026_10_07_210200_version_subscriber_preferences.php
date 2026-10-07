<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', fn (Blueprint $table) => $table->unsignedInteger('preferences_version')->default(0));
        Schema::table('subscriber_notifications', fn (Blueprint $table) => $table->unsignedInteger('preference_version')->default(0));
    }

    public function down(): void
    {
        Schema::table('subscriber_notifications', fn (Blueprint $table) => $table->dropColumn('preference_version'));
        Schema::table('subscribers', fn (Blueprint $table) => $table->dropColumn('preferences_version'));
    }
};
