<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Support\SupportLlmDraftComposer;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H6090 / ростер v2, гейт Q5: pii-маскинг текста студента до LLM-вызова
 * в suggester-шве. Контракт — tools/school_pii_guard.py (Uprava, 05-10-2026):
 * standalone-токены, allow-лист, структурные фигуры, fail-closed.
 */
class SupportStudentPiiMaskerTest extends TestCase
{
    use RefreshDatabase;

    private function seedStudents(): void
    {
        // phone/telegram_username/vk_id вне $fillable — контактные поля через forceFill.
        User::factory()->create(['name' => 'Иван Петров', 'email' => 'ivan.petrov@example.com'])
            ->forceFill(['phone' => '+79161234567', 'telegram_username' => 'ivan_p', 'vk_id' => '100500'])->save();
        User::factory()->create(['name' => 'Анна Смирнова', 'email' => 'anna.smirnova@example.com'])
            ->forceFill(['phone' => '+79031234599', 'telegram_username' => 'anna_s', 'vk_id' => '200800'])->save();
        User::factory()->create(['name' => 'gasyoun']);
    }

    public function test_masks_standalone_identities_and_structural_shapes(): void
    {
        $this->seedStudents();
        $masker = new SupportStudentPiiMasker;

        $text = 'Студент Иван Петров (ivan.petrov@example.com, +79161234567, @ivan_p, vk 100500) '
            .'просит перенести вводное. Письмо дублируйте на unknown.person@example.net, тел 89031234599.';
        [$masked, $n] = $masker->maskOrFail($text);

        foreach (['Иван Петров', 'ivan.petrov@example.com', '+79161234567', '@ivan_p', '100500'] as $secret) {
            $this->assertStringNotContainsString($secret, $masked, $secret);
        }
        // структурные фигуры маскируются, даже если значения нет в users
        $this->assertStringNotContainsString('unknown.person@example.net', $masked);
        $this->assertStringNotContainsString('89031234599', $masked);
        $this->assertGreaterThanOrEqual(6, $n);
        $this->assertStringContainsString('<PII:', $masked);
    }

    public function test_collision_words_survive_and_allow_list_bare_handle_passes(): void
    {
        User::factory()->create(['name' => 'Подписчик']);
        $masker = new SupportStudentPiiMasker;

        // «Подписчики» — не standalone «Подписчик»; голый gasyoun — allow-лист;
        // email-shaped gasyoun@example.com маскируется (структурная фигура).
        [$masked, $n] = $masker->maskOrFail('Заголовок «Подписчики» обновлён, пишет gasyoun с gasyoun@example.com.');

        $this->assertStringContainsString('Подписчики', $masked);
        $this->assertStringContainsString(' gasyoun ', $masked);
        $this->assertStringNotContainsString('gasyoun@example.com', $masked);
        $this->assertSame(1, $n);
    }

    public function test_round_trip_is_clean(): void
    {
        $this->seedStudents();
        $masker = new SupportStudentPiiMasker;

        $text = 'Анна Смирнова, anna.smirnova@example.com, тел +79031234599 — где мой доступ к урокам?';
        [$masked] = $masker->maskOrFail($text);

        $this->assertFalse($masker->containsIdentity($masked), $masked);
    }

    public function test_prompt_text_is_masked_and_fails_closed(): void
    {
        $this->seedStudents();
        config(['features.support_ai_assist' => true]);

        $composer = new SupportLlmDraftComposer(
            $this->createMock(CuratorAi::class),
            new SupportStudentPiiMasker,
        );
        $method = new \ReflectionMethod(SupportLlmDraftComposer::class, 'maskedPromptText');

        [$promptText, $n] = $method->invoke(
            $composer,
            'Иван Петров не видит урок 3, почта для связи ivan.petrov@example.com',
            'chat_message',
        );

        $this->assertGreaterThan(0, $n);
        $this->assertStringNotContainsString('Иван Петров', $promptText);
        $this->assertStringNotContainsString('ivan.petrov@example.com', $promptText);
        $this->assertFalse((new SupportStudentPiiMasker)->containsIdentity($promptText));
    }
}
