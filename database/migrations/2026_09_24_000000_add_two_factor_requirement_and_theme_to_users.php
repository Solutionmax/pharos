<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set by an administrator when inviting someone: the account has to
            // switch two factor on before it can use anything else. Cleared once it is on.
            $table->boolean('require_two_factor')->default(false);
            // light, dark or system; null follows the installation's default.
            $table->string('theme', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['require_two_factor', 'theme']));
    }
};
