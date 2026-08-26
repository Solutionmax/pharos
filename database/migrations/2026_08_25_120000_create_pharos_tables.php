<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('collapsed')->default(true);
            $table->timestamps();
        });

        Schema::create('components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('link')->nullable();
            $table->string('tags')->nullable();              // comma separated, as in Cachet
            $table->unsignedTinyInteger('status')->default(1);
            $table->boolean('enabled')->default(true);
            $table->boolean('show_uptime')->default(true);
            $table->string('source')->default('manual');     // manual|check|kuma|webhook|heartbeat|upstream
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->string('type');                          // http|tcp|heartbeat
            $table->string('target');
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedTinyInteger('retries')->default(2);
            $table->unsignedTinyInteger('degraded_after_ms')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(10);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->boolean('ok');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message')->nullable();
            $table->timestamp('checked_at')->index();
            $table->index(['component_id', 'checked_at']);
        });

        // One row per component per day. Keeps the 90-day bar cheap regardless of check volume.
        Schema::create('uptime_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('up_seconds')->default(0);
            $table->unsignedInteger('down_seconds')->default(0);
            $table->unsignedTinyInteger('worst_status')->default(1);
            $table->unique(['component_id', 'day']);
        });

        Schema::create('incident_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('title_template');
            $table->text('body_template');
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('status')->default(1);
            $table->string('impact')->default('minor');
            $table->string('visibility')->default('public');  // public|authenticated|internal
            $table->boolean('pinned')->default(false);
            $table->boolean('auto_resolve')->default(false);
            $table->string('source')->default('manual');      // manual|api|check|upstream
            $table->string('grouping_key')->nullable()->index(); // groups repeat outages per target
            $table->timestamp('occurred_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->string('seo_title')->nullable();
            $table->string('seo_description')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('status');
            $table->text('message');
            $table->boolean('automatic')->default(false);
            $table->timestamps();
        });

        Schema::create('component_incident', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('status');            // status this incident set on the component
            $table->unique(['incident_id', 'component_id']);
        });

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('verify_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        foreach ([
            'settings', 'api_tokens', 'subscribers', 'component_incident', 'incident_updates',
            'incidents', 'incident_templates', 'uptime_days', 'check_results', 'checks',
            'components', 'component_groups',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
