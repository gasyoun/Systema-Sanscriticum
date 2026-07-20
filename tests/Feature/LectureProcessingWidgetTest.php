<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Editor\Widgets\LectureProcessingWidget;
use App\Models\LectureDraft;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Виджет-баннер: пока идёт обработка — держит wasProcessing; как только meta.processing
 * снимается, перезагружает страницу редактора (чтобы вернулись кнопки и статус).
 */
class LectureProcessingWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('editor'));
    }

    /** @test */
    public function reloads_page_once_processing_finishes(): void
    {
        $draft = LectureDraft::create([
            'title' => 'Лекция',
            'status' => LectureDraft::STATUS_EDITING,
        ]);
        $draft->markProcessing('ИИ: корректура текста');

        $component = Livewire::test(LectureProcessingWidget::class, ['record' => $draft]);

        // Пока идёт обработка — запоминаем факт, не редиректим.
        $component->call('refreshStatus')
            ->assertSet('wasProcessing', true)
            ->assertNoRedirect();

        // Задача завершилась → следующий поллинг перезагружает страницу.
        $draft->clearProcessing();
        $component->call('refreshStatus')->assertRedirect();
    }

    /** @test */
    public function does_not_redirect_when_never_processing(): void
    {
        $draft = LectureDraft::create([
            'title' => 'Лекция',
            'status' => LectureDraft::STATUS_BUILT,
        ]);

        Livewire::test(LectureProcessingWidget::class, ['record' => $draft])
            ->call('refreshStatus')
            ->assertSet('wasProcessing', false)
            ->assertNoRedirect();
    }
}
