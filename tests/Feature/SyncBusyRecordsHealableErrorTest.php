<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TelegramSupportAccount;
use App\Services\Telegram\MadelineSessionContext;
use danog\MadelineProto\API;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Инцидент 19-09-2026: сессия поддержки застряла, синки минутами возвращали
 * session_busy с кодом 0 — и НЕ оставляли следа в аккаунте. Healthcheck читает
 * last_successful_sync_at / last_sync_error, видел «всё свежо» и молчал,
 * пока полоса не отвечала студентам два часа. Auto-heal не имел повода.
 *
 * Контракт после фикса: занятая сессия записывает last_sync_error словами из
 * HEALABLE_ERROR_NEEDLES («session is busy») и НЕ трогает
 * last_successful_sync_at — следующий healthcheck (раз в 15 мин) лечит сам.
 */
class SyncBusyRecordsHealableErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_busy_session_records_healable_error_without_faking_success(): void
    {
        $account = TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'last_successful_sync_at' => now()->subMinutes(40),
        ]);

        config([
            'services.telegram_support.enabled' => true,
            'services.telegram_support.api_id' => 'test',
            'services.telegram_support.api_hash' => 'test',
            'services.telegram_support.client_class' => API::class,
        ]);

        // Держим замок сессии — ровно то состояние «другая команда владеет
        // сессией», при котором синк обязан уйти в busy-ветку.
        $lock = Cache::lock(MadelineSessionContext::lockName(), 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('telegram-support:sync')
                ->expectsOutputToContain('session_busy')
                ->assertExitCode(0);
        } finally {
            $lock->release();
        }

        $account->refresh();

        $this->assertNotNull($account->last_synced_at, 'попытка синка зафиксирована');
        $this->assertStringContainsString(
            'session is busy',
            (string) $account->last_sync_error,
            'ошибка записана словами из HEALABLE_ERROR_NEEDLES — иначе auto-heal её не увидит',
        );
        $this->assertTrue(
            $account->last_successful_sync_at->lt(now()->subMinutes(39)),
            'успех не подделывается: last_successful_sync_at не тронут',
        );
    }
}
