<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarSlotResource\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Форма поста длинная: Filament уводит страницу к первому полю с ошибкой, а
 * текст ошибки под длинным редактором оставался за краем экрана — казалось,
 * что «Create» просто прыгает наверх (26-10-2026). Уведомление видно всегда.
 */
trait NotifiesValidationErrors
{
    protected function onValidationError(ValidationException $exception): void
    {
        Notification::make()
            ->title('Пост не сохранён')
            ->body(implode("\n", array_unique(array_merge(...array_values($exception->errors())))))
            ->danger()
            ->persistent()
            ->send();
    }
}
