<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarSlotResource\Pages;

use App\Filament\Resources\ContentCalendarSlotResource;
use App\Filament\Resources\ContentCalendarSlotResource\Concerns\NotifiesValidationErrors;
use App\Models\ContentCalendarPublication;
use App\Models\ContentCalendarSlot;
use App\Services\Content\CalendarBusinessStoryPublisher;
use App\Services\Content\CalendarPublicationRunner;
use App\Services\Content\ContentProhibitionsChecklist;
use App\Services\Content\PostFormatter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditContentCalendarSlot extends EditRecord
{
    use NotifiesValidationErrors;

    protected static string $resource = ContentCalendarSlotResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['body'] = PostFormatter::toEditorHtml($data['body'] ?? null);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Сторис отдельно от поста (26-10-2026): сохраняет форму и сразу
            // публикует только сторис; сам пост не отправляется и его
            // расписание не меняется. Повтор уже вышедшей сторис невозможен.
            Actions\Action::make('publishStory')
                ->label('Опубликовать сторис')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => self::storyDestinations() !== [])
                ->modalHeading('Опубликовать сторис сейчас')
                ->modalDescription('Уйдёт только сторис из поля «Картинка для сторис» — пост не публикуется. '
                    .'Форма будет сохранена перед публикацией.')
                ->modalSubmitActionLabel('Опубликовать')
                ->form([
                    Forms\Components\CheckboxList::make('targets')
                        ->label('Куда')
                        ->options(fn (): array => array_map(
                            static fn (array $d): string => (string) $d['label'],
                            self::storyDestinations(),
                        ))
                        ->default(fn (): array => array_keys(self::storyDestinations()))
                        ->required(),
                ])
                ->action(fn (array $data) => $this->publishStory((array) $data['targets'])),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function storyDestinations(): array
    {
        return array_filter(
            ContentCalendarSlot::enabledDestinations(),
            static fn (array $d): bool => ContentCalendarSlot::isStoryDestination($d),
        );
    }

    /** @param  list<string>  $targets */
    private function publishStory(array $targets): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        /** @var ContentCalendarSlot $slot */
        $slot = $this->getRecord()->refresh();
        if (blank($slot->story_media_path)) {
            Notification::make()->title('Сторис не опубликована')
                ->body('Загрузите картинку для сторис.')->danger()->send();

            return;
        }

        $checklist = app(ContentProhibitionsChecklist::class);
        $verdict = $checklist->check((string) $slot->title."\n".CalendarBusinessStoryPublisher::caption($slot));
        if (! $verdict['ok']) {
            Notification::make()->title('Сторис не опубликована')
                ->body($checklist->journalLine($verdict))->danger()->persistent()->send();

            return;
        }

        $destinations = self::storyDestinations();
        $runner = app(CalendarPublicationRunner::class);
        $lines = [];
        foreach (array_intersect($targets, array_keys($destinations)) as $key) {
            $result = $runner->run($slot, $key, $destinations[$key]);
            $label = (string) $destinations[$key]['label'];
            $lines[] = match ($result['outcome']) {
                ContentCalendarPublication::STATUS_PUBLISHED => "✅ {$label}",
                CalendarPublicationRunner::SKIPPED => "• {$label}: уже опубликована",
                ContentCalendarPublication::STATUS_PENDING => "⏳ {$label}: не вышла, повторите позже",
                default => (string) $result['line'],
            };
        }

        $ok = ! collect($lines)->contains(fn (string $l): bool => str_starts_with($l, '❌') || str_starts_with($l, '⏳'));
        $notification = Notification::make()
            ->title($ok ? 'Сторис опубликована' : 'Сторис: есть проблемы')
            ->body(implode("\n", $lines))
            ->persistent();
        ($ok ? $notification->success() : $notification->danger())->send();
    }
}
