<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $t) {
            $t->string('expected_keyword', 1000)->nullable();
            $t->string('dns_type', 8)->nullable();
            $t->string('dns_expected', 1000)->nullable();
            $t->timestamp('tls_expires_at')->nullable();
            $t->string('tls_warning')->nullable();
        });
        Schema::table('components', fn (Blueprint $t) => $t->boolean('show_latency')->default(false));
    }

    public function down(): void
    {
        Schema::table('checks', fn (Blueprint $t) => $t->dropColumn(['expected_keyword', 'dns_type', 'dns_expected', 'tls_expires_at', 'tls_warning']));
        Schema::table('components', fn (Blueprint $t) => $t->dropColumn('show_latency'));
    }
};
