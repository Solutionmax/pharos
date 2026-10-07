<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_destinations', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('driver', 8);
            $t->text('configuration');
            $t->text('credentials');
            $t->boolean('enabled')->default(true);
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_destinations');
    }
};
