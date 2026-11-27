<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\LearnerErrorCorpus\LearnerErrorCorpusExtractor;
use Tests\TestCase;

/**
 * H6252 — offline canary for the learner-error corpus extractor.
 *
 * 152-FZ: no real student text is used. The 20 control examples are synthetic
 * PII shapes; the extractor must never emit them as lemma/form, and must never
 * emit raw context — only a one-way hash.
 *
 * _Created: 25-11-2026 · Last updated: 25-11-2026_
 * _Dr. Mārcis Gasūns_
 */
class LearnerErrorCorpusExportTest extends TestCase
{
    public function test_header_and_row_contract(): void
    {
        $rows = (new LearnerErrorCorpusExtractor)->extract([
            ['anon_id' => 'u_abc', 'source' => 'homework-review', 'body' => 'Обратите внимание на सहना и bāt, долгое ā.'],
        ]);

        $this->assertNotSame([], $rows);
        foreach ($rows as $row) {
            $this->assertSame(
                ['anon_id', 'source', 'lemma', 'form', 'error_type', 'context_hash'],
                array_keys($row)
            );
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['context_hash']);
            $this->assertContains($row['form'], ['devanagari', 'iast']);
            $this->assertStringStartsWith('u_', $row['anon_id']);
        }
    }

    public function test_twenty_known_pii_shapes_never_become_a_lemma(): void
    {
        $samples = [
            'Мария Ковалёва', 'Иван Петрович Сидоров', 'Анна Смирнова',
            'иван@example.com', 'student.mail@yandex.ru', 'a.b+tag@school.ru',
            '+7 (916) 123-45-67', '8-916-123-45-67', '+79161234567',
            'Меня зовут Екатерина', 'Меня зовут Дмитрий', '@gasyoun_student',
            'Оплатите заказ № 482913', 'счёт № 77120', '+7 999 123-45 67',
            'Ольга Петрова', 'Ольга Алексеевна', '@user_name_1990',
            'сергей电视台@mail.ru', 'Наталия Гаврилова',
        ];

        $rows = (new LearnerErrorCorpusExtractor)->extract(array_map(
            static fn (string $s): array => ['anon_id' => 'u_canary', 'source' => 'synthetic', 'body' => $s],
            $samples
        ));

        $this->assertCount(20, $samples);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('@', $row['lemma']);
            $this->assertStringNotContainsString('PII', $row['lemma']);
            $this->assertDoesNotMatchRegularExpression('/\d{4,}/', $row['lemma']);
            $this->assertDoesNotMatchRegularExpression('/[\p{Cyrillic}]/u', $row['lemma']);
        }
    }

    public function test_output_is_deterministic_and_ranked(): void
    {
        $input = [
            ['anon_id' => 'u_a', 'source' => 'homework-review', 'body' => 'सहना सहना bāt bāt'],
            ['anon_id' => 'u_b', 'source' => 'stenogrammy', 'body' => 'долгое ā, कर्म'],
        ];
        $extractor = new LearnerErrorCorpusExtractor;
        $a = $extractor->extract($input);
        $b = $extractor->extract($input);

        $this->assertSame($a, $b);
        $this->assertSame($extractor->toCsv($a), $extractor->toCsv($b));
        $this->assertStringStartsWith("anon_id,source,lemma,form,error_type,context_hash\n", $extractor->toCsv($a));
    }
}
