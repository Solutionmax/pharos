<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the admin menu sits for this person: the sidebar or a top bar.
        Schema::table('users', fn (Blueprint $table) => $table->string('nav_layout', 8)->default('sidebar'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('nav_layout'));
    }
};
