<?php

declare(strict_types=1);

namespace App\Filament\Resources\TeacherPayoutAttributionSuggestionResource\Pages;

use App\Filament\Resources\TeacherPayoutAttributionSuggestionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * H3084 — заголовок и хлебная крошка задаются явно.
 *
 * По умолчанию Filament прогоняет `pluralModelLabel` через `Str::title()` и
 * печатает «Подтверждение Выплат Преподавателям» — в русском заголовке каждое
 * слово с большой буквы читается как ошибка. Крошка по умолчанию — английское
 * «List». Экран открывает бухгалтер, а не разработчик, поэтому оба значения
 * проставлены руками.
 */
class ListTeacherPayoutAttributionSuggestions extends ListRecords
{
    protected static string $resource = TeacherPayoutAttributionSuggestionResource::class;

    public function getTitle(): string
    {
        return 'Подтверждение выплат преподавателям';
    }

    public function getBreadcrumb(): string
    {
        return 'Список';
    }

    public function getSubheading(): ?string
    {
        return 'Отметьте, какие платежи-«Расходы» были выплатами преподавателю. '
            .'Подтверждение меняет только эту отметку — строк в «Историю выплат» и «Финансы» оно не создаёт.';
    }
}
