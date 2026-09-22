<?php

use App\Models\StatusPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->foreignId('status_page_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('webhook_deliveries')->whereNull('status_page_id')->update([
            'status_page_id' => StatusPage::defaultId(),
        ]);

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('status_page_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_page_id');
        });
    }
};
