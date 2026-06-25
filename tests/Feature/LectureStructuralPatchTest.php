<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LectureDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B: структурная правка через HTTP-эндпоинт редактора применяется к data.json
 * на диске (с бэкапом). rebuild=false — не дёргаем Python-сборщик.
 */
class LectureStructuralPatchTest extends TestCase
{
    use RefreshDatabase;

    private function draftWithData(): LectureDraft
    {
        Storage::fake('local');

        $draft = LectureDraft::create([
            'title' => 'Лекция',
            'status' => LectureDraft::STATUS_BUILT,
        ]);

        Storage::disk('local')->put("lectures/{$draft->id}/data.json", json_encode([
            'sections' => [[
                'id' => 's1',
                'content' => [
                    ['type' => 'speech', 'paragraphs' => [['text' => 'Первый', 't' => 1]]],
                    ['type' => 'figure', 'src' => 'a.jpg', 'caption' => 'К'],
                    ['type' => 'speech', 'paragraphs' => [['text' => 'Второй']]],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE));

        return $draft;
    }

    private function dataOnDisk(LectureDraft $draft): array
    {
        return json_decode(Storage::disk('local')->get("lectures/{$draft->id}/data.json"), true);
    }

    /** @test */
    public function delete_block_op_mutates_data_json_on_disk(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $draft = $this->draftWithData();

        $this->actingAs($admin)
            ->postJson(route('editor.lecture.patch', $draft), [
                'patches' => [
                    ['section_id' => 's1', 'op' => 'delete_block', 'block_index' => 1],
                ],
                'rebuild' => false,
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'count' => 1]);

        $content = $this->dataOnDisk($draft)['sections'][0]['content'];
        $this->assertCount(2, $content);
        $this->assertSame(['speech', 'speech'], array_column($content, 'type'));
    }

    /** @test */
    public function invalid_op_returns_422(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $draft = $this->draftWithData();

        // op не из белого списка → отсекается валидацией.
        $this->actingAs($admin)
            ->postJson(route('editor.lecture.patch', $draft), [
                'patches' => [['section_id' => 's1', 'op' => 'drop_database', 'block_index' => 0]],
            ])
            ->assertStatus(422);
    }

    /** @test */
    public function out_of_range_block_index_returns_422_from_patcher(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $draft = $this->draftWithData();

        $this->actingAs($admin)
            ->postJson(route('editor.lecture.patch', $draft), [
                'patches' => [['section_id' => 's1', 'op' => 'delete_block', 'block_index' => 99]],
                'rebuild' => false,
            ])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    /** @test */
    public function non_editor_is_forbidden(): void
    {
        $student = User::factory()->create(['is_admin' => false, 'is_lecture_editor' => false]);
        $draft = $this->draftWithData();

        $this->actingAs($student)
            ->postJson(route('editor.lecture.patch', $draft), [
                'patches' => [['section_id' => 's1', 'op' => 'delete_block', 'block_index' => 0]],
            ])
            ->assertStatus(403);
    }
}
