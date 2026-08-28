<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            // Nullable and nullOnDelete on purpose: removing an account must not
            // erase the record of what that account did.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor');           // "Anita (anita@example.net)" or "API token: n8n"
            $table->string('action');          // component.created, user.deleted, auth.login
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
