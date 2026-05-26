<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landing_page_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // Случайный ключ в URL вебхука: /api/webhooks/telegram-magnet/{webhook_key}.
            // Так один эндпоинт обслуживает разных ботов, а лендинг резолвится по ключу.
            $table->string('webhook_key', 64)->unique();

            $table->boolean('is_active')->default(true);

            $table->string('tg_bot_username')->nullable();
            // token/secret шифруются на уровне модели (encrypted cast) — шифр длиннее
            // plaintext, поэтому text, а не string.
            $table->text('tg_bot_token')->nullable();
            $table->text('tg_webhook_secret')->nullable();

            // URL ноды Webhook в n8n, куда форвардятся апдейты для анкеты/прогрева.
            $table->string('n8n_forward_url', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_bots');
    }
};
