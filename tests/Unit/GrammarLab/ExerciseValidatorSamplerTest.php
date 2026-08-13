<?php

declare(strict_types=1);

namespace Tests\Unit\GrammarLab;

use App\Services\GrammarLab\ExerciseSampler;
use App\Services\GrammarLab\ExerciseValidator;
use Tests\TestCase;

class ExerciseValidatorSamplerTest extends TestCase
{
    public function test_normalize_and_equivalence(): void
    {
        $v = new ExerciseValidator;
        $this->assertSame('guṇa', $v->normalize('  Guṇa  '));
        $this->assertTrue($v->answersMatch([
            'answer' => 'guṇa',
            'answer_normalized' => 'guṇa',
        ], 'GUṆA'));
        $this->assertFalse($v->answersMatch([
            'answer' => 'guṇa',
            'answer_normalized' => 'guṇa',
        ], 'vṛddhi'));
    }

    public function test_rejects_duplicate_distractors_and_missing_sources(): void
    {
        $v = new ExerciseValidator;
        $errors = $v->errors([
            'id' => 'ex:grammar-lab:bad',
            'topic_id' => 'subject:grammar-lab:ablaut-grades',
            'risk_class' => 'deterministic',
            'prompt_ru' => 'Какая ступень у kartum?',
            'choices' => ['guṇa', 'guṇa'],
            'answer' => 'guṇa',
            'answer_normalized' => 'не то',
            'distractors' => ['нулевая', 'нулевая'],
        ], false);
        $this->assertContains('answer_equivalence', $errors);
        $this->assertContains('choices_not_unique', $errors);
        $this->assertContains('distractors_not_unique', $errors);
        $this->assertContains('source_ids', $errors);
    }

    public function test_interpretive_without_approval_is_blocked(): void
    {
        $v = new ExerciseValidator;
        $errors = $v->errors($this->validInterpretive(['approval_record' => null]), false);
        $this->assertContains('interpretive_needs_approval', $errors);
    }

    public function test_sample_is_reproducible_and_twenty_percent(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = 'ex:grammar-lab:item-'.$i;
        }
        $sampler = new ExerciseSampler;
        $a = $sampler->draw($ids, 0.2, 'grammar-lab-g3-v1');
        $b = $sampler->draw($ids, 0.2, 'grammar-lab-g3-v1');
        $c = $sampler->draw(array_reverse($ids), 0.2, 'grammar-lab-g3-v1');
        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertCount(2, $a);
        $this->assertNotSame($a, $sampler->draw($ids, 0.2, 'other-seed'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validInterpretive(array $overrides): array
    {
        return array_merge([
            'id' => 'ex:grammar-lab:type-vs-class-essay',
            'topic_id' => 'subject:grammar-lab:ablaut-grades',
            'risk_class' => 'interpretive',
            'prompt_ru' => 'Почему тип I нельзя отождествлять с I классом?',
            'answer' => 'Тип описывает чередование; класс — презенсную основу.',
            'answer_normalized' => 'type=ablaut-by-position; class=present-stem',
            'source_ids' => ['zalizniak-1975:type-i'],
        ], $overrides);
    }
}
