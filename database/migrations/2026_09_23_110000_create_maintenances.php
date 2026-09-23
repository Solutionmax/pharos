<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            // Minutes before the start that subscribers and destinations are told; 0 is never.
            $table->unsignedInteger('announce_minutes')->default(1440);
            // Each stamp is set once, by whoever claims it first; that is what makes
            // a late or doubled scheduler run harmless.
            $table->timestamp('announced_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['status_page_id', 'ends_at']);
        });

        Schema::create('component_maintenance', function (Blueprint $table) {
            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            // The status before the window, and the one Pharos set: a component
            // someone changed in between is left the way they left it.
            $table->unsignedTinyInteger('previous_status')->nullable();
            $table->unsignedTinyInteger('applied_status')->nullable();
            $table->primary(['maintenance_id', 'component_id']);
        });

        Schema::create('maintenance_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['subscriber_id', 'maintenance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_notifications');
        Schema::dropIfExists('component_maintenance');
        Schema::dropIfExists('maintenances');
    }
};
