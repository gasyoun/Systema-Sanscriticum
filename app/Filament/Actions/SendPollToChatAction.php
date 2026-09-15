<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\Group;
use App\Models\User;
use App\Services\Telegram\ZapisiPollService;
use App\Support\RoleGate;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use InvalidArgumentException;

/**
 * «Опрос в чат» — произвольный опрос @zapisi_ORSbot в Telegram-чат группы.
 * Одна схема формы и один обработчик для кнопки на строке группы
 * (tableAction) и для «Новый опрос» на странице «Записи (бот) → Опросы».
 */
final class SendPollToChatAction
{
    public static function tableAction(): Action
    {
        return Action::make('send_poll_to_chat')
            ->label('Опрос в чат')
            ->icon('heroicon-o-chart-bar')
            ->color('info')
            ->visible(fn (Group $record): bool => self::canSendTo($record))
            ->modalHeading(fn (Group $record): string => 'Опрос в чат — '.$record->name)
            ->modalSubmitActionLabel('Отправить опрос')
            ->form(self::formSchema())
            ->action(fn (Group $record, array $data) => self::submit($record, $data));
    }

    public static function canSendTo(Group $group): bool
    {
        return RoleGate::adminOnly()
            && (bool) config('features.telegram_zapisi_bot')
            && filled($group->telegram_chat_id);
    }

    /** @return array<int, Component> */
    public static function formSchema(): array
    {
        return [
            TextInput::make('question')
                ->label('Вопрос')
                ->required()
                ->maxLength(ZapisiPollService::QUESTION_MAX),
            Repeater::make('options')
                ->label('Варианты ответа')
                ->simple(
                    TextInput::make('text')
                        ->required()
                        ->maxLength(ZapisiPollService::OPTION_MAX),
                )
                ->minItems(ZapisiPollService::OPTIONS_MIN)
                ->maxItems(ZapisiPollService::OPTIONS_MAX)
                ->defaultItems(2)
                ->reorderable()
                ->addActionLabel('+ Вариант'),
            Toggle::make('allows_multiple')
                ->label('Можно выбрать несколько вариантов'),
            Placeholder::make('open_poll_note')
                ->hiddenLabel()
                ->content('Опрос открытый: участники чата видят, кто как проголосовал, а в админке голоса видны поимённо '
                    .'(«Записи (бот)» → «Опросы» → «Результаты»).'),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function submit(?Group $group, array $data): void
    {
        if ($group === null) {
            Notification::make()->title('Группа не найдена')->danger()->send();

            return;
        }

        $user = auth()->user();

        try {
            app(ZapisiPollService::class)->create(
                $group,
                (string) ($data['question'] ?? ''),
                (array) ($data['options'] ?? []),
                (bool) ($data['allows_multiple'] ?? false),
                $user instanceof User ? $user : null,
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Опрос не отправлен')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Опрос отправляется в чат группы')
            ->body('Результаты — «Записи (бот)» → «Опросы».')
            ->success()
            ->send();
    }
}
