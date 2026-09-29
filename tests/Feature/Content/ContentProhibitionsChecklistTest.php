<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentProhibitionsChecklist;
use Tests\TestCase;

/**
 * H5020: the ratified §2.8 list (config/content_prohibitions.php) as a
 * deterministic pre-send checklist — block on the four prohibited
 * categories, warn (not block) on price / live-date mentions.
 */
class ContentProhibitionsChecklistTest extends TestCase
{
    private ContentProhibitionsChecklist $checklist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checklist = new ContentProhibitionsChecklist;
    }

    public function test_clean_sanskrit_post_passes(): void
    {
        $r = $this->checklist->check('Слово дня: ॐ — oṃ. Читаем Бхагавадгиту в клубе @rusamskrtam, приходите.');

        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['blocks']);
        $this->assertSame([], $r['warns']);
    }

    public function test_crm_personal_data_blocks(): void
    {
        foreach ([
            'Напишите на ivan@example.com',
            'Звоните +7 (999) 123-45-67',
            'Ученица Мария Иванова сдала все домашки',
            'Как написал мне студент в личке…',
            'Спасибо @some_student_42 за вопрос',
        ] as $text) {
            $r = $this->checklist->check($text);
            $this->assertFalse($r['ok'], $text);
            $this->assertSame('crm_personal_data', $r['blocks'][0]['rule'], $text);
        }
    }

    public function test_politics_competitors_and_unpublished_research_block(): void
    {
        $this->assertSame('politics_religion_guru', $this->checklist->check('Наш гуру расскажет об истинной вере')['blocks'][0]['rule']);
        $this->assertSame('competitors', $this->checklist->check('Мы лучше, чем Окаруто')['blocks'][0]['rule']);
        $this->assertSame('unpublished_research', $this->checklist->check('Читайте наш препринт по санскритской лексикографии')['blocks'][0]['rule']);
    }

    public function test_price_or_live_date_only_warns(): void
    {
        $r = $this->checklist->check('Курс стоит 12 000 ₽, эфир 20 сентября в 19:00 мск');

        $this->assertTrue($r['ok'], 'prices/dates are locked for agents but not prohibited content — warn, not block');
        $this->assertSame('price_or_live_date', $r['warns'][0]['rule']);
    }

    public function test_journal_line_names_rule_and_match(): void
    {
        $line = $this->checklist->journalLine($this->checklist->check('пишите на ivan@example.com'));

        $this->assertStringStartsWith('prohibition-hold §2.8: crm_personal_data', $line);
        $this->assertStringContainsString('ivan@example.com', $line);
    }
}
