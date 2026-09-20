<?php

use App\Models\StatusPage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $ownedTables = [
        'component_groups',
        'components',
        'incident_templates',
        'incidents',
        'subscribers',
        'webhook_endpoints',
        'subscriber_notifications',
    ];

    public function up(): void
    {
        Schema::create('status_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_published')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->string('domain')->nullable()->unique();
            $table->timestamps();
        });

        $defaultId = DB::table('status_pages')->insertGetId([
            'name' => (string) (DB::table('settings')->where('key', 'brand.name')->value('value') ?: 'Status page'),
            'slug' => 'default',
            'is_published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('settings')->updateOrInsert(
            ['key' => StatusPage::DEFAULT_ID_SETTING],
            ['value' => (string) $defaultId],
        );

        Schema::create('status_page_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['status_page_id', 'key']);
        });

        $pageSettings = DB::table('settings')
            ->where(function ($query): void {
                foreach (['brand.%', 'page.%', 'subscribers.%', 'mail.template.%'] as $pattern) {
                    $query->orWhere('key', 'like', $pattern);
                }
                $query->orWhere('key', 'integrations.webhook_secret');
            })
            ->get();

        foreach ($pageSettings as $setting) {
            DB::table('status_page_settings')->insert([
                'status_page_id' => $defaultId,
                'key' => $setting->key,
                'value' => $setting->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($pageSettings->isNotEmpty()) {
            DB::table('settings')->whereIn('key', $pageSettings->pluck('key')->all())->delete();
        }

        Schema::create('status_page_user', function (Blueprint $table) {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['status_page_id', 'user_id']);
        });

        $userIds = DB::table('users')->pluck('id');
        foreach ($userIds as $userId) {
            DB::table('status_page_user')->insert([
                'status_page_id' => $defaultId,
                'user_id' => $userId,
            ]);
        }

        foreach ($this->ownedTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('status_page_id')->nullable()->constrained()->restrictOnDelete();
            });
            DB::table($tableName)->update(['status_page_id' => $defaultId]);
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('status_page_id')->nullable(false)->change();
            });
        }

        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropUnique('subscribers_email_unique');
            $table->unique(['status_page_id', 'email']);
        });
        Schema::table('incident_templates', function (Blueprint $table) {
            $table->dropUnique('incident_templates_slug_unique');
            $table->unique(['status_page_id', 'slug']);
        });

        Schema::table('api_tokens', function (Blueprint $table) {
            $table->foreignId('status_page_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });
        DB::table('api_tokens')->update(['status_page_id' => $defaultId]);
        Schema::table('api_tokens', function (Blueprint $table) use ($defaultId) {
            $table->unsignedBigInteger('status_page_id')->default($defaultId)->nullable(false)->change();
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreignId('status_page_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('status_pages') && DB::table('status_pages')->count() > 1) {
            throw new RuntimeException('Cannot remove status-page ownership after additional pages exist.');
        }

        if (Schema::hasTable('status_page_settings')) {
            foreach (DB::table('status_page_settings')->get() as $setting) {
                DB::table('settings')->updateOrInsert(
                    ['key' => $setting->key],
                    ['value' => $setting->value],
                );
            }
        }

        Schema::table('audit_log', fn (Blueprint $table) => $table->dropConstrainedForeignId('status_page_id'));
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('status_page_id');
        });
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropUnique(['status_page_id', 'email']);
            $table->unique('email');
        });
        Schema::table('incident_templates', function (Blueprint $table) {
            $table->dropUnique(['status_page_id', 'slug']);
            $table->unique('slug');
        });
        foreach (array_reverse($this->ownedTables) as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('status_page_id'));
        }

        DB::table('settings')->where('key', StatusPage::DEFAULT_ID_SETTING)->delete();
        Schema::dropIfExists('status_page_user');
        Schema::dropIfExists('status_page_settings');
        Schema::dropIfExists('status_pages');
    }
};
