<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\MarathonEnrollment;
use App\Support\RoleGate;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * H445 Phase 4 (H546) — curator review queue for the `deva` cohort's Day-2
 * mantra-reading voice notes. Paid track ONLY (H546 §2 — the free track is
 * self-assessed and never enters this queue, see
 * MarathonEnrollment::needsDay2VoiceReview()). Reuses
 * MarathonConsultationQuestions' read-mostly table shape, extended with the
 * one action this page actually needs: listen + mark reviewed with a note.
 */
class MarathonMantraReviews extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-microphone';

    protected static ?string $navigationLabel = 'Марафон: мантра (День 2)';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static string $view = 'filament.pages.marathon-mantra-reviews';

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MarathonEnrollment::query()
                ->with('lead')
                ->where('track', MarathonEnrollment::TRACK_PAID)
                ->whereNotNull('day2_voice_received_at'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped()
            ->defaultSort('day2_voice_reviewed_at', 'asc')
            ->columns([
                Tables\Columns\IconColumn::make('reviewed')
                    ->label('')
                    ->boolean()
                    ->getStateUsing(fn (MarathonEnrollment $r): bool => $r->day2_voice_reviewed_at !== null),

                Tables\Columns\TextColumn::make('lead.name')
                    ->label('Имя')
                    ->description(fn (MarathonEnrollment $r): string => (string) $r->lead?->contact)
                    ->searchable(),

                Tables\Columns\TextColumn::make('day2_voice_received_at')
                    ->label('Голосовое получено')
                    ->dateTime('d.m H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('day2_voice_curator_note')
                    ->label('Заметка куратора')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('listen')
                    ->label('Прослушать')
                    ->icon('heroicon-o-play-circle')
                    ->url(fn (MarathonEnrollment $r): ?string => $r->day2_voice_path !== null
                        ? route('admin.marathon.mantra-voice.download', ['enrollment' => $r->id])
                        : null)
                    ->openUrlInNewTab()
                    ->visible(fn (MarathonEnrollment $r): bool => $r->day2_voice_path !== null),

                Tables\Actions\Action::make('review')
                    ->label(fn (MarathonEnrollment $r): string => $r->day2_voice_reviewed_at === null ? 'Отметить проверенным' : 'Изменить заметку')
                    ->icon('heroicon-o-check-circle')
                    ->color(fn (MarathonEnrollment $r): string => $r->day2_voice_reviewed_at === null ? 'success' : 'gray')
                    ->form([
                        Textarea::make('note')
                            ->label('Заметка (необязательно)')
                            ->rows(3),
                    ])
                    ->fillForm(fn (MarathonEnrollment $r): array => ['note' => $r->day2_voice_curator_note])
                    ->action(function (MarathonEnrollment $r, array $data): void {
                        $r->update([
                            'day2_voice_reviewed_at' => $r->day2_voice_reviewed_at ?? now(),
                            'day2_voice_curator_note' => $data['note'] !== '' ? $data['note'] : null,
                        ]);

                        Notification::make()->title('Сохранено')->success()->send();
                    }),
            ]);
    }
}
