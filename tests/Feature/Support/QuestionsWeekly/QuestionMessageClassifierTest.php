<?php

declare(strict_types=1);

namespace Tests\Feature\Support\QuestionsWeekly;

use App\Models\SupportTopicRule;
use App\Services\SupportQuestions\QuestionMessageClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5709 — посообщенный классификатор: детекция вопроса (в т.ч. без '?'),
 * стабильный primary при нескольких темах, secondary-метки, unclassified,
 * детерминизм. Синтетические регрессионные фикстуры, никакой живой переписки.
 */
class QuestionMessageClassifierTest extends TestCase
{
    use RefreshDatabase;

    private QuestionMessageClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = app(QuestionMessageClassifier::class);
    }

    public function test_question_mark_detection(): void
    {
        $verdict = $this->classifier->classifyMessage('Где мои материалы?');
        $this->assertTrue($verdict['is_question']);
        $this->assertSame('F', $verdict['primary_category']);
        $this->assertContains('question_mark', $verdict['signals']);
    }

    /** Вопрос без '?': сигнальные слова всё равно дают is_question. */
    public function test_question_without_question_mark(): void
    {
        $verdict = $this->classifier->classifyMessage('Подскажите, когда будет запись занятия');
        $this->assertTrue($verdict['is_question']);
        $this->assertNotNull($verdict['primary_category']);
    }

    public function test_problem_phrase_is_question(): void
    {
        $verdict = $this->classifier->classifyMessage('зум не могу зайти, ошибка');
        $this->assertTrue($verdict['is_question']);
        $this->assertSame('A', $verdict['primary_category']);
    }

    public function test_greeting_is_not_question(): void
    {
        $verdict = $this->classifier->classifyMessage('Намасте! Спасибо, всё получилось');
        $this->assertFalse($verdict['is_question']);
        $this->assertNull($verdict['primary_category']);
    }

    /** Несколько тем: стабильный primary (специфичнее фраза — выше). */
    public function test_multiple_topics_stable_primary(): void
    {
        $text = 'Сколько стоит курс, дайте ссылку на оплату и скажите когда старт занятий';
        $first = $this->classifier->classifyMessage($text);
        $second = $this->classifier->classifyMessage($text);

        $this->assertSame($first, $second);
        $this->assertSame('D', $first['primary_category']);
        $this->assertNotEmpty($first['secondary_categories']);
        $this->assertNotContains('D', $first['secondary_categories']);
    }

    /** Специфичная фраза бьёт generic-стем той же категории. */
    public function test_specific_phrase_beats_generic_stem(): void
    {
        $verdict = $this->classifier->classifyMessage('не получается оплатить, пришлите ссылку на оплату курса');
        $this->assertSame('D', $verdict['primary_category']);
    }

    public function test_unknown_category_stays_unclassified(): void
    {
        $verdict = $this->classifier->classifyMessage('а что насчёт этимологии слова намаскара, интересно');
        $this->assertNull($verdict['primary_category']);
        $this->assertSame([], $verdict['secondary_categories']);
    }

    /** Голое «ссылка» не должно само по себе давать топик A (precision-first). */
    public function test_bare_link_word_does_not_classify(): void
    {
        $verdict = $this->classifier->classifyMessage('пришлите ссылку');
        $this->assertNull($verdict['primary_category']);
        $this->assertTrue($verdict['is_question']); // просьба — вопросительный сигнал
    }

    public function test_all_letter_categories_reachable(): void
    {
        $samples = [
            'A' => 'как зайти по ссылке в zoom',
            'B' => 'когда появится запись занятия',
            'C' => 'расписание изменилось? во сколько урок',
            'D' => 'сколько стоит тариф',
            'E' => 'не могу войти в личный кабинет',
            'F' => 'когда выдадут сертификат',
            'G' => 'прошла ли оплата, занятий оплачено сколько',
            'H' => 'кто ведёт нашу группу, куратор кто',
            'I' => 'добавьте меня в чат группы пожалуйста',
        ];
        foreach ($samples as $letter => $text) {
            $verdict = $this->classifier->classifyMessage($text);
            $this->assertSame(
                $letter,
                $verdict['primary_category'],
                "expected {$letter} for: {$text}, got ".(string) $verdict['primary_category']
            );
        }
    }

    /** Переиспользование существующих keyword-правил через карту категорий. */
    public function test_existing_topic_rule_reused_through_map(): void
    {
        SupportTopicRule::create([
            'category' => 'zoom_link',
            'keywords' => ['зуум-встреча'],
            'priority' => 10,
            'is_enabled' => true,
        ]);

        // Новая инстанция, чтобы закешированные keywords не мешали.
        $verdict = (new QuestionMessageClassifier)->classifyMessage('зуум-встреча не открывается');
        $this->assertSame('A', $verdict['primary_category']);
    }

    /** Staged pattern_hash-правила НЕ включаются (спека H5709). */
    public function test_staged_pattern_hash_rule_is_ignored(): void
    {
        SupportTopicRule::create([
            'category' => 'zoom_link',
            'keywords' => ['секретный-стем-х5709'],
            'priority' => 1,
            'is_enabled' => true,
            'pattern_hash' => 'abc123',
        ]);

        $verdict = (new QuestionMessageClassifier)->classifyMessage('подскажите секретный-стем-х5709?');
        $this->assertNull($verdict['primary_category']);
    }
}
