<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Исполняемый инвариант security-прохода (см. docs/webhook-security.md):
 * деньги-/доступ-критичные вебхуки ДОЛЖНЫ быть fail-closed — отклонять запрос
 * без валидного секрета/подписи. Регресс (случайный перевод в fail-open) ловится
 * здесь, а не в проде.
 */
class WebhookSecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function tochka_payment_webhook_rejects_forged_body(): void
    {
        // Подпись RS256 невалидна для произвольного тела → 401, выдача доступа не происходит.
        $this->call('POST', '/api/webhooks/tochka', [], [], [], [], 'not-a-valid-jwt')
            ->assertStatus(401);
    }

    /** @test */
    public function lesson_sync_rejects_without_secret(): void
    {
        config(['services.lesson_sync.secret' => 'audit-secret']);

        // Без заголовка X-Secret-Key — 401 (тот же guard, что и /lessons/from-zoom).
        $this->postJson('/api/sync-lessons', [['id' => 1, 'title' => 'X']])
            ->assertStatus(401);

        // Неверный секрет — тоже 401.
        $this->withHeader('X-Secret-Key', 'wrong')
            ->postJson('/api/sync-lessons', [['id' => 1, 'title' => 'X']])
            ->assertStatus(401);
    }

    /** @test */
    public function lesson_sync_is_fail_closed_when_secret_unset(): void
    {
        // Пустой секрет в конфиге → эндпоинт выключен (fail-closed), а не открыт.
        config(['services.lesson_sync.secret' => null]);

        $this->withHeader('X-Secret-Key', '')
            ->postJson('/api/sync-lessons', [['id' => 1, 'title' => 'X']])
            ->assertStatus(401);
    }
}
