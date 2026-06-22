<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CourseNoteBlockParser;
use PHPUnit\Framework\TestCase;

class CourseNoteBlockParserTest extends TestCase
{
    /**
     * @dataProvider notes
     */
    public function test_parses_entry_and_exit_blocks(string $note, ?int $entry, ?int $exit): void
    {
        $result = CourseNoteBlockParser::parse($note);

        $this->assertSame($entry, $result['entry'], "entry для: {$note}");
        $this->assertSame($exit, $result['exit'], "exit для: {$note}");
    }

    public static function notes(): array
    {
        return [
            'вход словом + число' => ['вошёл с 3 блока', 3, null],
            'выход словом + число' => ['ушёл после 5 блока', null, 5],
            'и вход и выход' => ['с блока 2, вышел после 4', 2, 4],
            'блок перед числом' => ['вход блок 3', 3, null],
            'сокращение бл' => ['вошёл с бл.3', 3, null],
            'номер с решёткой' => ['выход блок №6', null, 6],
            'присоединился' => ['присоединился к потоку с 2 блока', 2, null],
            'отчислен' => ['отчислен после 7 блока', null, 7],
            'нет блоков' => ['оплатил полностью', null, null],
            'число без слова блок' => ['прошёл 3 урока', null, null],
            'число про деньги' => ['внёс 2000 рублей', null, null],
            'пусто' => ['', null, null],
        ];
    }

    public function test_handles_null(): void
    {
        $this->assertSame(['entry' => null, 'exit' => null], CourseNoteBlockParser::parse(null));
    }
}
