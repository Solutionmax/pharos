<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Which certificate warning went out last, as "expiry date:days", so it goes out once. */
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $t) {
            $t->string('tls_notice', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $t) {
            $t->dropColumn('tls_notice');
        });
    }
};
