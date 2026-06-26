<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Lecture\LecturePatcher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Phase B: структурные операции патчера по собранной форме лекции
 * (блоки speech/figure, абзацы speech.paragraphs = объекты {text,t?,dg_ref?}).
 */
class LecturePatcherStructuralTest extends TestCase
{
    private LecturePatcher $patcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patcher = new LecturePatcher;
    }

    private function lecture(): array
    {
        return [
            'sections' => [
                [
                    'id' => 's1',
                    'content' => [
                        ['type' => 'speech', 'paragraphs' => [
                            ['text' => 'Один два три', 't' => 2, 'dg_ref' => 0],
                            ['text' => 'Четыре пять'],
                        ]],
                        ['type' => 'figure', 'src' => 'a.jpg', 'caption' => 'Подпись'],
                        ['type' => 'speech', 'paragraphs' => [['text' => 'Шесть']]],
                    ],
                ],
            ],
        ];
    }

    private function content(array $lecture): array
    {
        return $lecture['sections'][0]['content'];
    }

    /** @test */
    public function move_block_reorders_within_section(): void
    {
        $out = $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'move_block', 'block_index' => 0, 'to_index' => 2],
        ]);

        $types = array_column($this->content($out), 'type');
        $this->assertSame(['figure', 'speech', 'speech'], $types);
        // Перемещённый блок — первый speech (с таймкодом) — теперь последний.
        $this->assertSame(2, $this->content($out)[2]['paragraphs'][0]['t']);
    }

    /** @test */
    public function move_block_clamps_out_of_range_target(): void
    {
        $out = $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'move_block', 'block_index' => 0, 'to_index' => 99],
        ]);

        $this->assertSame('speech', $this->content($out)[2]['type']);
        $this->assertCount(3, $this->content($out));
    }

    /** @test */
    public function delete_block_removes_and_reindexes(): void
    {
        $out = $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'delete_block', 'block_index' => 1],
        ]);

        $content = $this->content($out);
        $this->assertCount(2, $content);
        $this->assertSame(['speech', 'speech'], array_column($content, 'type'));
        $this->assertSame([0, 1], array_keys($content)); // переиндексировано
    }

    /** @test */
    public function split_para_keeps_timecode_on_first_half(): void
    {
        $out = $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'split_para', 'block_index' => 0, 'para_index' => 0, 'offset' => 8],
        ]);

        $paras = $this->content($out)[0]['paragraphs'];
        $this->assertCount(3, $paras); // было 2 → стало 3
        $this->assertSame('Один два', $paras[0]['text']);
        $this->assertSame(2, $paras[0]['t']);              // таймкод остался у первой половины
        $this->assertSame('три', $paras[1]['text']);
        $this->assertArrayNotHasKey('t', $paras[1]);       // вторая — чистый абзац
        $this->assertSame('Четыре пять', $paras[2]['text']); // прежний сдвинулся
    }

    /** @test */
    public function split_para_at_boundary_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'split_para', 'block_index' => 0, 'para_index' => 0, 'offset' => 0],
        ]);
    }

    /** @test */
    public function merge_para_joins_into_previous_keeping_meta(): void
    {
        $out = $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'merge_para', 'block_index' => 0, 'para_index' => 1],
        ]);

        $paras = $this->content($out)[0]['paragraphs'];
        $this->assertCount(1, $paras);
        $this->assertSame('Один два три Четыре пять', $paras[0]['text']);
        $this->assertSame(2, $paras[0]['t']); // метаданные предыдущего абзаца сохранены
    }

    /** @test */
    public function merge_para_on_first_paragraph_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'merge_para', 'block_index' => 0, 'para_index' => 0],
        ]);
    }

    /** @test */
    public function unknown_op_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'op' => 'frobnicate', 'block_index' => 0],
        ]);
    }

    /** @test */
    public function field_edit_still_works_alongside_ops(): void
    {
        // Регрессия: обычная правка текста абзаца не сломана расширением.
        $out = $this->patcher->apply($this->lecture(), [
            ['section_id' => 's1', 'block_index' => 0, 'para_index' => 0, 'field' => 'text', 'value' => 'Новый текст'],
        ]);

        $this->assertSame('Новый текст', $this->content($out)[0]['paragraphs'][0]['text']);
    }
}
