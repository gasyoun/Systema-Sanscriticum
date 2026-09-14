<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Bot\BotKnowledgeBase;
use Tests\TestCase;

/**
 * Каркас мульти-персоны ИИ-куратора (H3520): флаг OFF = контракт не меняется.
 */
class BotMultiPersonaTest extends TestCase
{
    public function test_flag_off_any_key_returns_the_default_contract(): void
    {
        config(['features.bot_multi_persona' => false]);

        $kb = app(BotKnowledgeBase::class);

        $this->assertSame($kb->persona(), $kb->persona('debts'));
        $this->assertSame($kb->persona(), $kb->persona('несуществующий'));
        $this->assertStringContainsString('Ты — ИИ-куратор Академии Санскрита', $kb->persona());
        $this->assertStringContainsString('Мягкая продажа', $kb->persona());
    }

    public function test_flag_on_unknown_or_disabled_key_still_falls_back_to_default(): void
    {
        config([
            'features.bot_multi_persona' => true,
            'bot_personas.personas.disabled_voice' => ['label' => 'X', 'enabled' => false],
        ]);

        $kb = app(BotKnowledgeBase::class);

        $this->assertSame($kb->persona(), $kb->persona('нет-такого'));
        $this->assertSame($kb->persona(), $kb->persona('disabled_voice'));
    }

    public function test_flag_on_enabled_key_serves_its_text(): void
    {
        config([
            'features.bot_multi_persona' => true,
            'bot_personas.personas.debts' => [
                'label' => 'Долги',
                'enabled' => true,
                'text' => "Ты — куратор по оплатам. Факты впереди, без давления.\n",
            ],
        ]);

        $this->assertSame(
            "Ты — куратор по оплатам. Факты впереди, без давления.\n",
            app(BotKnowledgeBase::class)->persona('debts')
        );
    }

    public function test_flag_on_empty_text_falls_back_to_default(): void
    {
        config([
            'features.bot_multi_persona' => true,
            'bot_personas.personas.broken' => ['label' => 'X', 'enabled' => true, 'text' => '   '],
        ]);

        $kb = app(BotKnowledgeBase::class);

        $this->assertSame($kb->persona(), $kb->persona('broken'));
    }
}
