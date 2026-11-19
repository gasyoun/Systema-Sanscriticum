<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LessonResource;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Tests\TestCase;

/**
 * Регрессия «419 при сохранении урока с материалами» (инцидент 13-10-2026):
 * closure getUploadedFileNameForStorageUsing тайпхинтил несуществующий класс
 * Filament\Forms\Components\TemporaryUploadedFile, а Livewire 3.8 передаёт
 * Livewire\Features\SupportFileUploads\TemporaryUploadedFile. Filament ронял
 * замыкание TypeError'ом на каждом файле, Livewire глотал TypeError и отвечал
 * abort(419) «This page has expired» — мимо csrf-mismatch-лога и laravel.log.
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

        $source = file_get_contents((new \ReflectionClass(LessonResource::class))->getFileName());
        $this->assertStringContainsString(
            'use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;',
            $source,
            'Замыкания урока должны тайпхинтить класс, который Livewire реально передаёт',
        );
        $this->assertStringNotContainsString('Filament\Forms\Components\TemporaryUploadedFile', $source);

        // Путь tmp-файла, как его строит Livewire при реальной загрузке:
        // livewire-tmp/<token>-meta<base64(оригинальное имя)>.
        $file = new TemporaryUploadedFile(
            'livewire-tmp/'.Str::random(20).'-meta'.base64_encode('lesson-material.pdf'),
            'local',
        );

        $name = LessonResource::safeStoredName($file);

        $this->assertMatchesRegularExpression('/^lesson-material-[0-9a-f]{13}\.pdf$/', $name);
    }
}
