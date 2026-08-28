<?php

use App\Models\Setting;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('url');
            $table->string('format')->default('generic');   // generic|slack|teams
            $table->boolean('enabled')->default(true);
            // Enough to answer "did the last one arrive?" without a delivery log.
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });

        // The single webhook that used to live in settings becomes the first row,
        // so an existing install keeps firing without anyone touching it.
        if ($url = Setting::get('integrations.webhook_url')) {
            WebhookEndpoint::create([
                'label' => 'Webhook',
                'url' => $url,
                'format' => 'generic',
                'enabled' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
