<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1868 — «Первые вопросы» на витрине /online: ориентационная полоса для
 * первого визита, вопросы взяты из custdev-корпуса (~2 600 размеченных
 * диалогов; коды возражений В1–В6 в ORS-FAQ docs/FAQ_FUNNEL_OBJECTION_MAP.md).
 */
class ShopFirstQuestionsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function catalog_page_shows_first_questions_block(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('data-analytics="first-questions"', false)
            ->assertSee('Первые вопросы — короткие ответы')
            // Пять реальных первых вопросов из custdev-корпуса.
            ->assertSee('С чего начать, если я с нуля?')
            ->assertSee('У меня мало времени. Успею?')
            ->assertSee('Сколько это стоит?')
            ->assertSee('Когда старт? Я не опоздал?')
            ->assertSee('Справлюсь ли я? Это же сложно');
    }

    /** @test */
    public function first_questions_link_only_to_existing_surfaces(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('/online/s-chego-nachat')
            ->assertSee('format=recorded')
            ->assertSee('format=live')
            ->assertSee('#ceny', false)
            ->assertSee('/online/materialy');
    }

    /** @test */
    public function orientation_copy_carries_no_pressure_anti_patterns(): void
    {
        // Анти-приемы с измеренным отрицательным лифтом в Win/Loss-когорте
        // (срочность −7, «мест не осталось») в ориентационном слое запрещены.
        $this->get('/online')
            ->assertOk()
            ->assertDontSee('Осталось мест')
            ->assertDontSee('мест не осталось')
            ->assertDontSee('Успейте записаться');
    }

    /** @test */
    public function hero_subtitle_reads_as_orientation(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('от первых букв с нуля до чтения текстов в оригинале');
    }
}
