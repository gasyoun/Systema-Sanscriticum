<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CourseMaterialSubmissionResource;
use App\Models\Course;
use App\Models\CourseMaterialSubmission;
use App\Models\User;
use App\Services\CourseMaterialSubmissionService;
use App\Support\RoleGate;
use App\Support\VideoEmbed;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * «Мои материалы» (H4325, H4310 3/3) — препод сдаёт видео-анонс, бейдж 4:3 и
 * конспект по своим курсам. Форма страницы взята с CourseDesignAssets — тот же
 * готовый в проекте паттерн «матрица курс × загрузка».
 *
 * Отправленное здесь — ЧЕРНОВИК: витрину курса меняет только куратор, публикуя
 * заявку в очереди {@see CourseMaterialSubmissionResource}
 * (анти-цель H4325 — заявка не публикует себя сама).
 */
class MyMaterials extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationLabel = 'Мои материалы';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 31;

    protected static ?string $title = 'Мои материалы';

    protected static ?string $slug = 'my-materials';

    protected static string $view = 'filament.pages.my-materials';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->isTeacher() || RoleGate::seesTeacherSurfaces($user));
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->coursesQuery())
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label('Курс')->searchable()->wrap()->limit(70),

                BadgeColumn::make('submission_status')->label('Статус заявки')
                    ->getStateUsing(fn (Course $record): string => $record->openMaterialSubmission()?->statusLabel() ?? 'Не подано')
                    ->color(function (Course $record): string {
                        $status = $record->openMaterialSubmission()?->status;

                        return match ($status) {
                            CourseMaterialSubmission::STATUS_ACCEPTED => 'warning',
                            CourseMaterialSubmission::STATUS_IN_PROGRESS => 'info',
                            default => 'gray',
                        };
                    }),

                TextColumn::make('video_announce_url')->label('Видео на витрине')->alignCenter()
                    ->getStateUsing(fn (Course $record): string => filled($record->video_announce_url) ? 'есть' : '—')
                    ->color(fn (Course $record): string => filled($record->video_announce_url) ? 'success' : 'gray'),

                TextColumn::make('badge')->label('Бейдж на витрине')->alignCenter()
                    ->getStateUsing(fn (Course $record): string => filled($record->designAssets->firstWhere('format', '4:3')?->path) ? 'есть' : '—')
                    ->color(fn (Course $record): string => filled($record->designAssets->firstWhere('format', '4:3')?->path) ? 'success' : 'gray'),

                TextColumn::make('teacher_notes')->label('Конспект на витрине')->alignCenter()
                    ->getStateUsing(fn (Course $record): string => filled($record->teacher_notes) ? 'есть' : '—')
                    ->color(fn (Course $record): string => filled($record->teacher_notes) ? 'success' : 'gray'),
            ])
            ->actions([
                $this->submitAction(),
            ])
            ->defaultPaginationPageOption(25)
            ->paginated([25, 50, 'all']);
    }

    private function coursesQuery(): Builder
    {
        $user = auth()->user();
        $query = Course::query()->with(['materialSubmissions', 'designAssets']);

        if (! RoleGate::seesTeacherSurfaces($user)) {
            $query->forTeacher($user?->teacher_id);
        }

        return $query;
    }

    private function submitAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('submit')
            ->label('Подать / обновить материалы')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('primary')
            ->modalHeading(fn (Course $record): string => 'Материалы курса «'.$record->title.'»')
            ->modalDescription('Присланное здесь не публикуется само — куратор проверяет заявку и публикует материалы на витрину.')
            ->modalSubmitActionLabel('Отправить куратору')
            ->form(fn (Course $record): array => [
                Forms\Components\TextInput::make('video_announce_url')
                    ->label('Видео-анонс курса')
                    ->url()
                    ->maxLength(500)
                    ->default(fn (): ?string => $record->openMaterialSubmission()?->video_announce_url)
                    ->placeholder('https://www.youtube.com/watch?v=... или https://rutube.ru/video/... или https://vk.com/video...')
                    ->helperText('Ссылка на YouTube, RuTube или VK video. После публикации куратором заменит обложку в hero-блоке продающей страницы.')
                    ->rule(
                        fn () => function (string $attribute, $value, \Closure $fail): void {
                            if ($value && ! VideoEmbed::embed($value)) {
                                $fail('Ссылка не распознана как YouTube, RuTube или VK video.');
                            }
                        }
                    ),

                Forms\Components\FileUpload::make('badge')
                    ->label('Бейдж курса (формат 4:3)')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->storeFiles(false)
                    ->maxSize((int) config('design_assets.max_image_kb'))
                    ->helperText('Картинка в пропорции 4:3 для карточки курса в каталоге. Оставьте пустым, чтобы не менять уже присланный файл.'),

                Forms\Components\Textarea::make('notes')
                    ->label('Конспект лекций')
                    ->rows(8)
                    ->maxLength(20000)
                    ->default(fn (): ?string => $record->openMaterialSubmission()?->notes)
                    ->helperText('Свободный текст — после публикации куратором появится на странице курса.'),
            ])
            ->action(function (Course $record, array $data, CourseMaterialSubmissionService $service): void {
                $submission = $service->submit(
                    $record,
                    auth()->user(),
                    filled($data['video_announce_url'] ?? null) ? (string) $data['video_announce_url'] : null,
                    self::file($data['badge'] ?? null),
                    filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                );

                Notification::make()
                    ->title('Заявка отправлена куратору')
                    ->body('Статус: '.$submission->statusLabel().'. Куратор проверит и опубликует материалы на витрину.')
                    ->success()
                    ->send();
            });
    }

    /** Из состояния FileUpload (storeFiles(false)) достаём загруженный файл. */
    private static function file(mixed $state): ?UploadedFile
    {
        $file = is_array($state) ? Arr::first($state) : $state;

        return $file instanceof UploadedFile ? $file : null;
    }
}
