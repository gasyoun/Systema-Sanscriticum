<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseInterestRequestResource\Pages;

use App\Filament\Resources\CourseInterestRequestResource;
use App\Filament\Widgets\CourseInterestByCourseWidget;
use Filament\Resources\Pages\ListRecords;

class ListCourseInterestRequests extends ListRecords
{
    protected static string $resource = CourseInterestRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** Кураторская витрина «спрос есть — запустим»: счётчик по курсам над лентой. */
    protected function getHeaderWidgets(): array
    {
        return [
            CourseInterestByCourseWidget::class,
        ];
    }
}
