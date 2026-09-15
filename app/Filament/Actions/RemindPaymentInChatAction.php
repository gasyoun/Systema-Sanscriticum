<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\Group;
use App\Models\User;
use App\Services\Telegram\SlotNoticeService;
use App\Support\RoleGate;
use Carbon\CarbonInterface;
use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Model;

/**
 * Кнопка «Напомнить в чат об оплате» — одна на Расписание (меню занятия) и
 * Группы. Черновик текста — SlotNoticeService::paymentDraft(), куратор видит и
 * правит его перед отправкой; шлёт @zapisi_ORSbot в Telegram-чат группы.
 *
 * Появилась после 14-09-2026: автонапоминание об оплате блока потерялось на
 * деплое, дослать его руками было нечем.
 */
final class RemindPaymentInChatAction
{
    /**
     * @param  Closure(Model): ?Group  $groupOf  группа строки таблицы
     * @param  Closure(Model): CarbonInterface  $afterOf  точка отсчёта для «до какого числа»
     */
    public static function make(Closure $groupOf, Closure $afterOf): Action
    {
        return Action::make('remind_payment_in_chat')
            ->label('Напомнить в чат об оплате')
            ->icon('heroicon-o-banknotes')
            ->color('warning')
            ->visible(fn (Model $record): bool => RoleGate::adminOnly()
                && (bool) config('features.telegram_zapisi_bot')
                && filled($groupOf($record)?->telegram_chat_id))
            ->modalHeading('Напомнить в чат об оплате')
            ->modalSubmitActionLabel('Отправить в чат')
            ->fillForm(function (Model $record) use ($groupOf, $afterOf): array {
                $group = $groupOf($record);
                $draft = $group !== null
                    ? app(SlotNoticeService::class)->paymentDraft($group, $afterOf($record))
                    : null;

                return ['text' => $draft['text'] ?? ''];
            })
            ->form(fn (Model $record): array => [
                Placeholder::make('chat')
                    ->label('Куда')
                    ->content('Telegram-чат группы «'.($groupOf($record)?->name ?? '—').'»'),
                Textarea::make('text')
                    ->label('Текст сообщения')
                    ->rows(7)
                    ->required()
                    ->helperText('Дата «до» — ближайшее занятие группы; поле пустое — будущих занятий нет, '
                        .'впишите дату сами. Жирный шрифт — теги <b>…</b> (разметка Telegram).'),
            ])
            ->action(function (Model $record, array $data) use ($groupOf): void {
                $group = $groupOf($record);
                $user = auth()->user();

                $status = $group !== null
                    ? app(SlotNoticeService::class)->sendManual(
                        $group,
                        (string) ($data['text'] ?? ''),
                        $user instanceof User ? $user : null,
                    )
                    : SlotNoticeService::MANUAL_NO_CHAT;

                match ($status) {
                    SlotNoticeService::MANUAL_QUEUED => Notification::make()
                        ->title('Напоминание отправляется в чат группы')
                        ->success()
                        ->send(),
                    SlotNoticeService::MANUAL_DUPLICATE => Notification::make()
                        ->title('Такой же текст уже уходил в этот чат за последние 24 часа')
                        ->body('Повтор не отправлен. Если нужно ещё раз — измените текст.')
                        ->warning()
                        ->send(),
                    default => Notification::make()
                        ->title('У группы не привязан Telegram-чат')
                        ->danger()
                        ->send(),
                };
            });
    }
}
