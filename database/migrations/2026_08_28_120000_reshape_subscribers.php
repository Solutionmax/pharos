<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original subscribers table shipped with a flow that never existed, so no
 * install has a row in it. Rebuilding it is simpler and safer than altering a
 * unique NOT NULL column into place on three database engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscribers');

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            // Rotated whenever a new confirmation goes out, so an older mail's link stops working.
            $table->string('token', 40)->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();
        });

        // One row per subscriber per incident update: the outbox pharos:notify works through.
        Schema::create('subscriber_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_update_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['subscriber_id', 'incident_update_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriber_notifications');
        Schema::dropIfExists('subscribers');

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('verify_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }
};
