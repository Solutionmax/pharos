<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_page_user', function (Blueprint $table) {
            $table->string('role')->default('editor');
        });
    }

    public function down(): void
    {
        Schema::table('status_page_user', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
