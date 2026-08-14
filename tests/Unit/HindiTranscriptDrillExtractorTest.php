<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HindiTranscriptDrillExtractor;
use App\Support\TranscriptParser;
use Tests\TestCase;

/**
 * H2443 — fixture transcript → deterministic cloze / translate / vocab pick.
 */
class HindiTranscriptDrillExtractorTest extends TestCase
{
    public function test_fixture_yields_translate_cloze_and_vocab_pick(): void
    {
        $sentences = $this->fixtureSentences();
        $items = (new HindiTranscriptDrillExtractor)->extract($sentences);

        $types = array_column($items, 'type');
        $this->assertContains(HindiTranscriptDrillExtractor::TYPE_TRANSLATE, $types);
        $this->assertContains(HindiTranscriptDrillExtractor::TYPE_CLOZE, $types);
        $this->assertContains(HindiTranscriptDrillExtractor::TYPE_VOCAB_PICK, $types);

        $kitab = $this->firstOfType($items, HindiTranscriptDrillExtractor::TYPE_TRANSLATE);
        $this->assertSame('kitāb', $this->hindiOf($kitab));
        $this->assertSame('книга', $kitab['answer']);
        $this->assertSame('Как по-русски: kitāb', $kitab['prompt']);

        $namaste = collect($items)->first(
            fn (array $i): bool => $i['type'] === HindiTranscriptDrillExtractor::TYPE_TRANSLATE
                && str_contains($i['prompt'], 'नमस्ते'),
        );
        $this->assertNotNull($namaste);
        $this->assertSame('здравствуйте', $namaste['answer']);

        $pani = collect($items)->first(
            fn (array $i): bool => $i['type'] === HindiTranscriptDrillExtractor::TYPE_CLOZE
                && $i['answer'] === 'पानी',
        );
        $this->assertNotNull($pani);
        $this->assertStringContainsString('_____', $pani['prompt']);
        $this->assertStringContainsString('Повторите слово', $pani['prompt']);

        $kaise = collect($items)->first(
            fn (array $i): bool => $i['type'] === HindiTranscriptDrillExtractor::TYPE_CLOZE
                && $i['answer'] === 'kaise',
        );
        $this->assertNotNull($kaise);

        $pick = $this->firstOfType($items, HindiTranscriptDrillExtractor::TYPE_VOCAB_PICK);
        $this->assertIsArray($pick['choices']);
        $this->assertContains($pick['answer'], $pick['choices']);
        $this->assertCount(3, $pick['choices']);

        $again = (new HindiTranscriptDrillExtractor)->extract($sentences);
        $this->assertSame(array_column($items, 'id'), array_column($again, 'id'));
        $this->assertSame(array_column($items, 'answer'), array_column($again, 'answer'));
    }

    public function test_russian_only_sentence_is_skipped(): void
    {
        $items = (new HindiTranscriptDrillExtractor)->extract([
            ['text' => 'Сегодня мы повторяем прошедшие формы.', 'start' => 1.0],
        ]);

        $this->assertSame([], $items);
    }

    public function test_answer_match_is_case_and_punctuation_insensitive(): void
    {
        $this->assertTrue(HindiTranscriptDrillExtractor::answersMatch('книга', 'Книга!'));
        $this->assertTrue(HindiTranscriptDrillExtractor::answersMatch('здравствуйте', 'Здравствуйте'));
        $this->assertFalse(HindiTranscriptDrillExtractor::answersMatch('книга', 'тетрадь'));
    }

    /**
     * @return list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>
     */
    private function fixtureSentences(): array
    {
        $path = dirname(__DIR__).'/fixtures/hindi_transcript/hindi_lesson_sample.json';
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($payload);

        $words = TranscriptParser::wordsFrom($payload);
        $this->assertNotSame([], $words);

        $sentences = [];
        $current = '';
        $start = 0.0;
        $end = 0.0;
        foreach ($words as $wordData) {
            if ($current === '') {
                $start = (float) ($wordData['start'] ?? 0);
            }
            $word = (string) ($wordData['punctuated_word'] ?? $wordData['word'] ?? '');
            $current .= $word.' ';
            $end = (float) ($wordData['end'] ?? $start);
            if (preg_match('/[.!?]$/', trim($word))) {
                $text = trim($current);
                $sentences[] = [
                    'formatted_time' => '00:00',
                    'start' => $start,
                    'end' => $end,
                    'text' => $text,
                    'safe_text' => mb_strtolower($text),
                ];
                $current = '';
            }
        }

        return $sentences;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function firstOfType(array $items, string $type): array
    {
        foreach ($items as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }
        $this->fail('missing type '.$type);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function hindiOf(array $item): string
    {
        $this->assertIsString($item['prompt']);
        $this->assertMatchesRegularExpression('/Как по-русски: (.+)$/u', $item['prompt']);
        preg_match('/Как по-русски: (.+)$/u', $item['prompt'], $m);

        return $m[1];
    }
}
