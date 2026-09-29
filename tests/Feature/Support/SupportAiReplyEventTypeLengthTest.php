<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Services\Support\SupportAnswerEventLogger;
use App\Services\Support\SupportDmAutoReply;
use App\Services\Support\SupportDmLinkInvite;
use ReflectionClass;
use Tests\TestCase;

/**
 * 28-09-2026: `dm_shadow_would_send_facts` (26) не влез в varchar(24) и на проде
 * ронял синк Telegram-support (SQLSTATE 22001). sqlite длину varchar не
 * проверяет — тест на базе зелёный при любой длине, поэтому длину пинним здесь:
 * каждый event_type, который пишет код, обязан помещаться в колонку.
 */
class SupportAiReplyEventTypeLengthTest extends TestCase
{
    /** @return list<class-string> */
    private function writers(): array
    {
        return [
            SupportAiReplyEvent::class,
            SupportDmAutoReply::class,
            SupportAnswerEventLogger::class,
            SupportDmLinkInvite::class,
        ];
    }

    public function test_every_event_constant_fits_the_column(): void
    {
        $seen = 0;

        foreach ($this->writers() as $class) {
            foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
                if (! str_starts_with($constant->getName(), 'EVENT_')
                    || $constant->getName() === 'EVENT_TYPE_MAX_LENGTH') {
                    continue;
                }

                $value = $constant->getValue();
                $seen++;

                $this->assertLessThanOrEqual(
                    SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH,
                    strlen((string) $value),
                    "{$class}::{$constant->getName()} = '{$value}' не влезает в support_ai_reply_events.event_type",
                );
            }
        }

        $this->assertGreaterThan(10, $seen, 'Reflection не нашла EVENT_*-константы — тест ничего не охраняет.');
    }

    public function test_migration_length_matches_the_model_constant(): void
    {
        $migration = file_get_contents(
            database_path('migrations/2026_09_28_120000_widen_support_ai_reply_events_event_type.php'),
        );

        $this->assertStringContainsString(
            "string('event_type', ".SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH.')->change()',
            $migration,
            'Длина в миграции разошлась с SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH.',
        );
    }

    public function test_the_fact_shadow_event_that_broke_prod_is_covered(): void
    {
        $this->assertSame(26, strlen(SupportDmAutoReply::EVENT_FACT_SHADOW_WOULD_SEND));
        $this->assertGreaterThan(24, strlen(SupportDmAutoReply::EVENT_FACT_SHADOW_WOULD_SEND));
        $this->assertLessThanOrEqual(
            SupportAiReplyEvent::EVENT_TYPE_MAX_LENGTH,
            strlen(SupportDmAutoReply::EVENT_FACT_SHADOW_WOULD_SEND),
        );
    }
}
