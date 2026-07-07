<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('telegram_username')->nullable();
            // Уникальный партнёрский код (last-touch атрибуция клиента).
            $table->string('code')->unique();
            $table->string('status')->default('pending')->index();
            // Как выплачивать (карта/СБП/TG) и реквизиты.
            $table->string('payout_method')->nullable();
            $table->text('payout_details')->nullable();
            // Собственный аккаунт партнёра, если он также наш студент (не обязателен).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Индивидуальная ставка вознаграждения (иначе — глобальная из config).
            $table->decimal('reward_amount_override', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
