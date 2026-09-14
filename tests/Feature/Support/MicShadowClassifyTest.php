<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\MicShadowClassification;
use App\Services\Support\MicShadowClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * H4608 — MIC shadow classify-all-inbound: флаг default OFF = ровно ноль
 * поведения; ON = log-only per-plane {category, reason, null} + near-miss,
 * без текста сообщения; дайджест top-50 без эха текста.
 */
class MicShadowClassifyTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_MATCH = 'Подскажите, где посмотреть видеозаписи прошлого занятия?';

    private const SAMPLE_NULL = 'фывапролджэ йцукенгшщзхъ';

    public function test_flag_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('features.mic_shadow_classify'));
    }

    public function test_flag_off_means_zero_rows_and_unchanged_reply_path(): void
    {
        $response = $this->postJson(route('chat.message'), ['text' => self::SAMPLE_MATCH]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertArrayHasKey('conversation_id', $response->json());
        $this->assertSame(0, MicShadowClassification::query()->count());
    }

    public function test_flag_on_records_one_row_per_plane_with_winner_and_hash(): void
    {
        config(['features.mic_shadow_classify' => true]);

        $response = $this->postJson(route('chat.message'), ['text' => self::SAMPLE_MATCH]);

        $response->assertOk();
        $rows = MicShadowClassification::query()->get();
        $this->assertSame(4, $rows->count(), 'one row per MIC plane');

        $topic = $rows->firstWhere('plane', 'topic');
        $this->assertNotNull($topic);
        $this->assertSame('web', $topic->channel);
        $this->assertSame('recording_access', $topic->category);
        $this->assertStringStartsWith('keyword:', (string) $topic->reason);
        $this->assertNotNull($topic->conversation_id);
        $this->assertNotNull($topic->message_id);

        // PII-фенс: текст нигде не хранится, только sha256 нормализованного текста.
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('видеозапис', (string) $row);
            $this->assertSame(64, strlen((string) $row->text_hash));
            $this->assertDatabaseMissing('mic_shadow_classifications', ['text_hash' => self::SAMPLE_MATCH]);
        }
    }

    public function test_flag_on_records_null_telemetry_for_unmatched_text(): void
    {
        config(['features.mic_shadow_classify' => true]);

        MicShadowClassifier::instance()?->record('telegram', 41, 42, self::SAMPLE_NULL);

        $topic = MicShadowClassification::query()->where('plane', 'topic')->first();
        $this->assertNotNull($topic, 'null telemetry must still be written (G2)');
        $this->assertSame('telegram', $topic->channel);
        $this->assertNull($topic->category);
        $this->assertNull($topic->reason);
    }

    public function test_near_miss_carries_negation_reason(): void
    {
        config(['features.mic_shadow_classify' => true]);

        // recording_access patterns match, но negation «не работает» блокирует
        // правило — near-miss обязан зафиксировать причину (G8).
        MicShadowClassifier::instance()?->record('web', null, 7, 'Ссылка на запись не работает');

        $topic = MicShadowClassification::query()->where('plane', 'topic')->where('message_id', 7)->first();
        $this->assertNotNull($topic);
        $nearMiss = (array) ($topic->near_miss ?? []);
        $recording = collect($nearMiss)->firstWhere('category', 'recording_access');
        $this->assertNotNull($recording, json_encode($nearMiss, JSON_UNESCAPED_UNICODE));
        $this->assertStringStartsWith('negation:', (string) $recording['reason']);
    }

    public function test_reprocessing_the_same_message_is_idempotent(): void
    {
        config(['features.mic_shadow_classify' => true]);

        $classifier = MicShadowClassifier::instance();
        $classifier?->record('telegram', 5, 6, self::SAMPLE_MATCH);
        $classifier?->record('telegram', 5, 6, self::SAMPLE_MATCH);

        $this->assertSame(4, MicShadowClassification::query()->count());
    }

    public function test_service_is_harmless_when_flag_turns_off_mid_flight(): void
    {
        config(['features.mic_shadow_classify' => true]);
        MicShadowClassifier::instance()?->record('web', 1, 2, self::SAMPLE_MATCH);
        config(['features.mic_shadow_classify' => false]);
        MicShadowClassifier::instance()?->record('web', 1, 3, self::SAMPLE_MATCH);

        $this->assertSame(4, MicShadowClassification::query()->where('message_id', 2)->count());
        $this->assertSame(0, MicShadowClassification::query()->where('message_id', 3)->count());
    }

    public function test_weekly_digest_writes_top50_report_without_echoing_text(): void
    {
        config(['features.mic_shadow_classify' => true]);

        $classifier = MicShadowClassifier::instance();
        $classifier?->record('web', 11, 12, self::SAMPLE_MATCH); // categorized
        $classifier?->record('telegram', 13, 14, self::SAMPLE_NULL); // uncategorized

        $path = storage_path('app/testing/mic-null-digest-test.md');
        try {
            $this->artisan('support:mic-null-digest', ['--days' => 7, '--write' => $path])
                ->assertSuccessful();

            $this->assertFileExists($path);
            $report = (string) File::get($path);
            $this->assertStringContainsString('Uncategorized top-1 of 1', $report);
            $this->assertStringContainsString('Per-plane coverage', $report);
            $this->assertStringContainsString('recording_access', $report);
            $this->assertStringNotContainsString('видеозапис', $report, 'raw message text must never reach the report');
            $this->assertStringNotContainsString(self::SAMPLE_NULL, $report);
        } finally {
            File::delete($path);
        }
    }
}
