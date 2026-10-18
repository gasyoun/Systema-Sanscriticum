<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\User;
use App\Services\Bot\CuratorAi;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H6352-r3 (рулинг 5 грилла 10-10): CuratorAI → OpenRouter только с маскированным
 * PII; ответ восстанавливается, маскировщик недоступен → внешнего вызова нет.
 */
class CuratorAiPiiMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.openrouter.api_key', 'test-key');
        config()->set('services.openrouter.model', 'deepseek/deepseek-chat');
    }

    public function test_openrouter_receives_pseudonyms_instead_of_student_identities(): void
    {
        User::factory()->create([
            'name' => 'Анна Петрова',
            'email' => 'anna.petrova@example.com',
            'phone' => '+7 (916) 123-45-67',
            'telegram_username' => 'anna_p',
        ]);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();
            // Эхо-ответ моделью: возвращаем тот же псевдоним имени, что ушёл в промпт.
            preg_match('/<PII:name:\d+>/u', $captured['messages'][0]['content'], $tag);

            return Http::response([
                'model' => 'deepseek/deepseek-chat',
                'choices' => [['message' => ['content' => 'Здравствуйте, '.($tag[0] ?? 'студент').'!']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200);
        });

        $result = app(CuratorAi::class)->chatWithUsage([
            ['role' => 'user', 'content' => 'Меня зовут Анна Петрова, почта anna.petrova@example.com, телефон +7 (916) 123-45-67'],
        ]);

        // Ответ студенту — с настоящим именем, без служебного тега.
        $this->assertSame('Здравствуйте, Анна Петрова!', $result['content']);

        $json = (string) json_encode($captured, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('<PII:', $json);
        $this->assertStringNotContainsString('Анна Петрова', $json);
        $this->assertStringNotContainsString('anna.petrova@example.com', $json);
        $this->assertStringNotContainsString('123-45-67', $json);
        $this->assertStringNotContainsString('anna_p', $json);
    }

    public function test_one_alias_scope_spans_all_prompt_messages(): void
    {
        User::factory()->create(['name' => 'Анна Петрова', 'email' => 'anna.petrova@example.com']);

        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data();

            return Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ], 200);
        });

        app(CuratorAi::class)->chatWithUsage([
            ['role' => 'system', 'content' => 'Клиент: Анна Петрова'],
            ['role' => 'user', 'content' => 'Это снова Анна Петрова'],
        ]);

        $system = $captured['messages'][0]['content'];
        $user = $captured['messages'][1]['content'];

        // Один человек в двух сообщениях — один псевдоним (общая область вызова).
        preg_match('/<PII:name:\d+>/u', $system, $m1);
        preg_match('/<PII:name:\d+>/u', $user, $m2);
        $this->assertNotEmpty($m1, $system);
        $this->assertNotEmpty($m2, $user);
        $this->assertSame($m1[0], $m2[0]);
    }

    public function test_masker_unavailable_fails_closed_without_external_call(): void
    {
        $this->mock(SupportStudentPiiMasker::class, function ($mock): void {
            $mock->shouldReceive('begin')->andThrow(new \RuntimeException('identity source unreachable'));
        });
        Http::fake();

        $result = app(CuratorAi::class)->chatWithUsage([
            ['role' => 'user', 'content' => 'Мой номер +7 (916) 123-45-67'],
        ]);

        $this->assertNull($result['content']);
        Http::assertNothingSent();
    }

    public function test_unmask_restores_only_tags_of_the_current_scope(): void
    {
        User::factory()->create(['name' => 'Анна Петрова']);

        $masker = new SupportStudentPiiMasker;
        $masker->begin();
        [$masked] = $masker->mask('Пишет Анна Петрова');

        // Чужой/несуществующий в этой области тег остаётся как есть.
        $this->assertSame('Пишет Анна Петрова <PII:name:999>', $masker->unmask($masked.' <PII:name:999>'));
    }
}
