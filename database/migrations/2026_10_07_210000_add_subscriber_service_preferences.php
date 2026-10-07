<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', fn (Blueprint $table) => $table->boolean('all_services')->default(true));
        Schema::create('component_subscriber', function (Blueprint $table) {
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->primary(['subscriber_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_subscriber');
        Schema::table('subscribers', fn (Blueprint $table) => $table->dropColumn('all_services'));
    }
};
