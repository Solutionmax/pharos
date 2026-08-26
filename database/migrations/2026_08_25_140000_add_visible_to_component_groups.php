<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('component_groups', function (Blueprint $table) {
            // Separate from a component being enabled: a whole service can be
            // hidden from customers while its checks keep running.
            $table->boolean('visible')->default(true)->after('collapsed');
        });
    }

    public function down(): void
    {
        Schema::table('component_groups', function (Blueprint $table) {
            $table->dropColumn('visible');
        });
    }
};
