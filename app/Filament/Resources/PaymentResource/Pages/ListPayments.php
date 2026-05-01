<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Models\Course;
use App\Services\PaymentImportService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('importPayments')
                ->label('Импорт оплат')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->modalHeading('Импорт оплат для курса')
                ->modalDescription('Загрузите файл, затем сопоставьте колонки с полями БД.')
                ->modalSubmitActionLabel('Импортировать')
                ->modalWidth('3xl')
                ->form(fn () => $this->getImportFormSchema())
                ->action(fn (array $data, PaymentImportService $service) => $this->handleImport($data, $service)),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    protected function getImportFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Шаг 1: Курс и файл')
                ->schema([
                    Forms\Components\Select::make('course_id')
                        ->label('Курс, куда импортировать оплаты')
                        ->options(Course::orderBy('title')->pluck('title', 'id'))
                        ->searchable()
                        ->required(),

                    Forms\Components\FileUpload::make('file')
                        ->label('Файл оплат (CSV / XLSX)')
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->disk('local')
                        ->directory('imports/tmp')
                        ->visibility('private')
                        ->maxSize(20480)
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            // Когда файл загружен — читаем заголовки и кладём в state формы
                            if (! $state) {
                                $set('headers', []);
                                return;
                            }

                            $path = is_string($state)
                                ? Storage::disk('local')->path($state)
                                : Storage::disk('local')->path($state->store('imports/tmp', 'local'));

                            if (! file_exists($path)) {
                                $set('headers', []);
                                return;
                            }

                            try {
                                $service = app(PaymentImportService::class);
                                $set('headers', $service->readHeaders($path));
                            } catch (\Throwable $e) {
                                $set('headers', []);
                                Notification::make()
                                    ->title('Не удалось прочитать файл')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    // Скрытое поле для хранения заголовков между шагами
                    Forms\Components\Hidden::make('headers')
                        ->default([]),
                ]),

            Forms\Components\Section::make('Шаг 2: Сопоставление колонок')
                ->description('Укажите, какая колонка файла соответствует какому полю БД')
                ->visible(fn (Forms\Get $get): bool => ! empty($get('headers')))
                ->schema([
                    Forms\Components\Select::make('user_lookup_field')
                        ->label('По какому полю искать студента?')
                        ->options([
                            'name'  => 'ФИО (User.name)',
                            'email' => 'Email (User.email)',
                            'phone' => 'Телефон (User.phone)',
                        ])
                        ->default('name')
                        ->required()
                        ->helperText('Значение из колонки «Студент» будет искаться в этом поле модели User'),

                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\Select::make('map_user')
                            ->label('Колонка: Студент *')
                            ->options(fn (Forms\Get $get) => $get('headers') ?: [])
                            ->required()
                            ->searchable(),

                        Forms\Components\Select::make('map_amount')
                            ->label('Колонка: Сумма *')
                            ->options(fn (Forms\Get $get) => $get('headers') ?: [])
                            ->required()
                            ->searchable(),

                        Forms\Components\Select::make('map_date')
                            ->label('Колонка: Дата оплаты')
                            ->options(fn (Forms\Get $get) => $get('headers') ?: [])
                            ->searchable()
                            ->helperText('Если не указано — будет использована текущая дата'),

                        Forms\Components\Select::make('map_start_block')
                            ->label('Колонка: Блок «с»')
                            ->options(fn (Forms\Get $get) => $get('headers') ?: [])
                            ->searchable(),

                        Forms\Components\Select::make('map_end_block')
                            ->label('Колонка: Блок «по»')
                            ->options(fn (Forms\Get $get) => $get('headers') ?: [])
                            ->searchable(),

                        Forms\Components\Select::make('map_tariff')
                            ->label('Колонка: Тариф')
                            ->options(fn (Forms\Get $get) => $get('headers') ?: [])
                            ->searchable()
                            ->helperText('Если не указано — определится из блоков'),

                        Forms\Components\Select::make('map_transaction_id')
                            ->label('Колонка: Примечание')
                            ->options(fn (Forms\Get $get) => $get('headers') ?: [])
                            ->searchable(),
                    ]),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleImport(array $data, PaymentImportService $service): void
    {
        /** @var Course|null $course */
        $course = Course::find($data['course_id']);
        if (! $course) {
            Notification::make()->title('Курс не найден')->danger()->send();
            return;
        }

        $relativePath = $data['file'];
        $absolutePath = Storage::disk('local')->path($relativePath);

        if (! file_exists($absolutePath)) {
            Notification::make()
                ->title('Файл не найден на сервере')
                ->body('Путь: ' . $relativePath)
                ->danger()
                ->send();
            return;
        }

        // Собираем маппинг: только те поля, для которых указали колонку
        $mapping = [];
        foreach (PaymentImportService::FIELDS as $field => $_label) {
            $key = "map_{$field}";
            if (! empty($data[$key])) {
                // В Select лежит значение вида "B: Студент" — нам нужна только буква
                $mapping[$field] = $this->extractColumnLetter($data[$key]);
            }
        }

        try {
            $stats = $service->import(
                absolutePath: $absolutePath,
                course: $course,
                mapping: $mapping,
                userLookupField: $data['user_lookup_field'] ?? 'name',
            );
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->title('Ошибка импорта')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
            return;
        } finally {
            Storage::disk('local')->delete($relativePath);
        }

        $body = sprintf(
            "✅ Вставлено: %d\n♻️ Дубликатов: %d\n👤 Студент не найден: %d\n📉 Расходы (пропущено): %d\n📊 Всего строк: %d",
            $stats['inserted'],
            $stats['duplicates'],
            $stats['no_user'],
            $stats['negative'],
            $stats['total_rows'],
        );

        if (! empty($stats['missing_users'])) {
            $preview = array_slice($stats['missing_users'], 0, 10);
            $body .= "\n\n⚠️ Не найдены студенты:\n• " . implode("\n• ", $preview);
            if (count($stats['missing_users']) > 10) {
                $body .= "\n…и ещё " . (count($stats['missing_users']) - 10);
            }
        }

        Notification::make()
            ->title('Импорт завершён')
            ->body($body)
            ->success()
            ->persistent()
            ->send();
    }

    /**
     * Из ключа Select-а ("B" — это буква, которую мы клали в options как ключ)
     * вытаскиваем чистую букву колонки. На самом деле ключ — это и есть буква,
     * метод оставлен для будущей расширяемости.
     */
    private function extractColumnLetter(string $value): string
    {
        // value уже содержит букву колонки (это ключ массива headers)
        return strtoupper(trim($value));
    }
}