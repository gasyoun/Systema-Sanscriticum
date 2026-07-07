<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одноразовые magic-link токены для беспарольного входа в кабинет (H324,
 * подписка на рассылку). Токен хранится ТОЛЬКО в виде hash (`token_hash`) —
 * plaintext уходит в письмо и в БД не оседает. Живёт короткое время
 * (`expires_at`), гасится при первом использовании (`consumed_at`).
 * Гарантии совпадают с password-reset: single-use, expiring, hashed-at-rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magic_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose', 32)->default('newsletter');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magic_link_tokens');
    }
};
