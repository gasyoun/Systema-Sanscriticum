<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Привязки внешних OAuth-аккаунтов (Google/VK/Yandex…) к пользователю.
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');         // google | vkontakte | yandex
            $table->string('provider_id');      // id пользователя у провайдера
            $table->string('email')->nullable();
            $table->timestamps();

            // Один и тот же внешний аккаунт не может быть привязан дважды.
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
