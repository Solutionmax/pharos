<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->text('url')->change();
            $table->text('options')->nullable();
        });
        foreach (DB::table('webhook_endpoints')->cursor() as $endpoint) {
            if (str_starts_with($endpoint->url, 'http')) {
                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['url' => Crypt::encryptString($endpoint->url)]);
            }
        }
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 64);
            $table->text('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['webhook_endpoint_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        foreach (DB::table('webhook_endpoints')->cursor() as $endpoint) {
            if (! str_starts_with($endpoint->url, 'http')) {
                DB::table('webhook_endpoints')->where('id', $endpoint->id)->update(['url' => Crypt::decryptString($endpoint->url)]);
            }
        }
        Schema::table('webhook_endpoints', fn (Blueprint $table) => $table->dropColumn('options'));
    }
};
