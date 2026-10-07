<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cachet_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('digest', 64);
            $table->json('counts');
            $table->timestamp('created_at');
            $table->unique(['status_page_id', 'digest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cachet_import_batches');
    }
};
