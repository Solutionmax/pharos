<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable();
            // Set only once a code from the app has been accepted: a secret that
            // was copied over wrong must not be able to lock someone out.
            $table->timestamp('totp_confirmed_at')->nullable();
            // Last accepted time step, so a code cannot be used twice.
            $table->unsignedBigInteger('totp_last_step')->nullable();
        });

        Schema::create('recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Hashed, not encrypted: these are high-entropy one-shot codes, so
            // there is nothing to brute-force and a database leak gives up nothing.
            $table->string('code_hash', 64)->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_codes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_step']));
    }
};
