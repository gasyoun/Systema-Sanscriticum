<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1197 — Jivo-паритет Pillar 2: проактивный монитор посетителей.
 *
 * Эфемерная таблица присутствия: одна строка на активного посетителя витрины
 * (ключ — session-scoped guest_token, тот же, что владеет гостевым тредом H536).
 * Виджет периодически шлёт beacon (POST /support/presence) → сервер апсертит
 * строку (текущая страница, время на сайте, город из S1-резолвера). Куратор в
 * Filament-странице «Посетители онлайн» видит живой список и может написать
 * первым. Строки старше config('support_presence.prune_after_minutes')
 * выметает PruneStaleVisitorPresencesJob — персональные данные анонимного
 * посетителя не залёживаются (152-ФЗ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_visitor_presences', function (Blueprint $table) {
            $table->id();

            // Владение: guest_token — на каждого посетителя (мятится GuestChat::token),
            // user_id — если посетитель залогинен (информативно, для карточки студента).
            $table->string('guest_token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Контекст посетителя (реюз S1: те же поля, тот же VisitorGeoResolver).
            $table->string('visitor_ip', 45)->nullable();
            $table->string('visitor_city', 120)->nullable();
            $table->string('visitor_region', 120)->nullable();
            $table->string('visitor_country', 120)->nullable();
            $table->timestamp('visitor_geo_resolved_at')->nullable();

            // Что делает на сайте сейчас.
            $table->string('current_url', 2048)->nullable();
            $table->string('referrer', 2048)->nullable();

            // Время на сайте = last_seen_at - first_seen_at; «онлайн» = last_seen_at свежий.
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_visitor_presences');
    }
};
