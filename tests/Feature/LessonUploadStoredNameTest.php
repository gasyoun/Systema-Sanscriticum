<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LessonResource;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

/**
 * Регрессия «419 при сохранении урока с материалами»: closure
 * getUploadedFileNameForStorageUsing тайпхинтил несуществующий класс
 * Filament\Forms\Components\TemporaryUploadedFile, а Livewire 3.8 передаёт
 * Livewire\Features\SupportFileUploads\TemporaryUploadedFile. Filament ронял
 * замыкание TypeError'ом, Livewire глотал его и отвечал abort(419)
 * «This page has expired» — без записей в логах.
 */
class LessonUploadStoredNameTest extends TestCase
{
    /** @test */
    public function naming_accepts_the_class_livewire_actually_passes(): void
    {
        $this->assertFalse(
            class_exists(\Filament\Forms\Components\TemporaryUploadedFile::class),
            'Filament вернул BC-алиас класса — проверь, расширяет ли он Livewire-класс, прежде чем возвращать старый тайпхинт',
        );

        $file = new TemporaryUploadedFile('lesson-material.pdf', 'local');

        $name = LessonResource::safeStoredName($file);

        $this->assertMatchesRegularExpression('/^lesson-material-[0-9a-f]{13}\.pdf$/', $name);
    }
}
