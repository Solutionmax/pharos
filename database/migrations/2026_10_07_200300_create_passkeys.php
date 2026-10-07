<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('credential_id', 512)->unique();
            $t->text('public_key');
            $t->string('name', 100);
            $t->unsignedBigInteger('counter')->default(0);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
        Schema::create('passkey_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('ceremony', 8);
            $t->string('challenge', 100);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkey_challenges');
        Schema::dropIfExists('passkeys');
    }
};
