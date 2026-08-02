<?php

namespace App\Filament\Resources\CourseResource\RelationManagers;

use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Lesson;
use App\Services\LessonMaterialTagger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Вехи выдачи сертификатов/справок курса. Пять типов триггера (см.
 * CertificateMilestone): по блокам, по числу занятий (с повторами «каждые R»),
 * по уроку учебника, по занятиям материала (авторазметка стенограмм), вручную.
 * Выдаёт ежедневная certificates:issue-milestones (гейт — в настройках
 * маркетинга); бэкафилл прошлых лет — certificates:backfill-milestones.
 */
class CertificateMilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'certificateMilestones';

    protected static ?string $title = 'Вехи сертификатов';

    protected static ?string $recordTitleAttribute = 'title';

    /** Блоки курса для селектов диапазона: number => «№N — название». */
    private function blockOptions(): array
    {
        return $this->getOwnerRecord()
            ->blocks()
            ->get()
            ->mapWithKeys(fn ($b) => [$b->number => '№'.$b->number.($b->title ? " — {$b->title}" : '')])
            ->all();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Название вехи')
                    ->helperText('Служебное, видно только в админке. Например: «Деванагари, первые 6 занятий».')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('certificate_title')
                    ->label('Название в документе')
                    ->helperText('Символ | — перенос строки. Пусто — берётся название курса. У повторяющихся вех окно занятий допишется автоматически.')
                    ->columnSpanFull(),

                Forms\Components\Select::make('trigger_type')
                    ->label('Триггер выдачи')
                    ->options(CertificateMilestone::TRIGGER_TYPES)
                    ->default(CertificateMilestone::TRIGGER_BLOCK)
                    ->required()
                    ->live(),

                Forms\Components\Select::make('document_type')
                    ->label('Тип документа')
                    ->options(CertificateMilestone::DOCUMENT_TYPES)
                    ->default(CertificateMilestone::DOC_CERTIFICATE)
                    ->required(),

                // --- block ---
                Forms\Components\Select::make('start_block')
                    ->label('С блока')
                    ->options($this->blockOptions())
                    ->visible(fn (Forms\Get $get) => $get('trigger_type') === CertificateMilestone::TRIGGER_BLOCK)
                    ->requiredIf('trigger_type', CertificateMilestone::TRIGGER_BLOCK),

                Forms\Components\Select::make('end_block')
                    ->label('По блок (его конец — триггер)')
                    ->options($this->blockOptions())
                    ->visible(fn (Forms\Get $get) => $get('trigger_type') === CertificateMilestone::TRIGGER_BLOCK)
                    ->requiredIf('trigger_type', CertificateMilestone::TRIGGER_BLOCK)
                    ->rule(fn (Forms\Get $get) => $get('trigger_type') === CertificateMilestone::TRIGGER_BLOCK
                        ? 'gte:'.($get('start_block') ?? 0)
                        : null),

                // --- lesson_count ---
                Forms\Components\TextInput::make('trigger_lesson')
                    ->label(fn (Forms\Get $get) => match ($get('trigger_type')) {
                        CertificateMilestone::TRIGGER_TEXTBOOK => 'Номер занятия учебника',
                        CertificateMilestone::TRIGGER_MATERIAL => 'После скольких занятий материала',
                        default => 'После какого занятия (N-е состоявшееся)',
                    })
                    ->helperText(fn (Forms\Get $get) => match ($get('trigger_type')) {
                        CertificateMilestone::TRIGGER_TEXTBOOK => 'Сработает, когда у группы пройдёт урок с этим номером занятия учебника (поле урока «Занятие учебника»). Например 20 — справка за полкурса Кочергиной.',
                        CertificateMilestone::TRIGGER_MATERIAL => 'Считаются занятия, размеченные тегом материала (ниже). Например 15 занятий по Эмено.',
                        default => 'Считаются состоявшиеся занятия группы (по датам уроков). Например 6 — сертификат за деванагари.',
                    })
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn (Forms\Get $get) => in_array($get('trigger_type'), [
                        CertificateMilestone::TRIGGER_LESSON_COUNT,
                        CertificateMilestone::TRIGGER_TEXTBOOK,
                        CertificateMilestone::TRIGGER_MATERIAL,
                    ], true))
                    // У повторяющейся вехи порог = repeat_every, это поле не нужно.
                    ->required(fn (Forms\Get $get) => in_array($get('trigger_type'), [
                        CertificateMilestone::TRIGGER_TEXTBOOK,
                        CertificateMilestone::TRIGGER_MATERIAL,
                    ], true) || ($get('trigger_type') === CertificateMilestone::TRIGGER_LESSON_COUNT
                        && blank($get('repeat_every')))),

                Forms\Components\TextInput::make('repeat_every')
                    ->label('Повторять каждые N занятий')
                    ->helperText('Для вех «каждые 10/16 занятий» (Бюлер, вечные курсы): документ выдаётся за каждое окно занятий. Пусто — веха разовая. Поле «После какого занятия» тогда игнорируется — порог равен размеру окна.')
                    ->numeric()
                    ->minValue(2)
                    ->visible(fn (Forms\Get $get) => $get('trigger_type') === CertificateMilestone::TRIGGER_LESSON_COUNT),

                // --- material_count ---
                Forms\Components\TextInput::make('material_tag')
                    ->label('Тег материала')
                    ->helperText('Латиницей, без пробелов: emeno, buhler. Этим тегом размечаются уроки (автоматически по стенограммам или руками в уроке).')
                    ->maxLength(50)
                    ->visible(fn (Forms\Get $get) => $get('trigger_type') === CertificateMilestone::TRIGGER_MATERIAL)
                    ->requiredIf('trigger_type', CertificateMilestone::TRIGGER_MATERIAL),

                Forms\Components\TextInput::make('material_keywords')
                    ->label('Ключевые слова стенограммы')
                    ->helperText('Через запятую: «Эмено, Эменеу, Emeneau». Урок размечается автоматически, если слова встречаются в стенограмме не реже '.config('certificates.material_min_mentions', 3).' раз. Распознавание речи пишет имена по-разному — добавляйте варианты.')
                    ->maxLength(500)
                    ->visible(fn (Forms\Get $get) => $get('trigger_type') === CertificateMilestone::TRIGGER_MATERIAL),

                // --- manual ---
                Forms\Components\Placeholder::make('manual_hint')
                    ->label('Ручная веха')
                    ->content('Автоматика её не трогает — выдача только кнопкой «Выдать по вехе» на странице сертификатов, когда вы сами решите, что момент настал (например, по стенограммам).')
                    ->visible(fn (Forms\Get $get) => $get('trigger_type') === CertificateMilestone::TRIGGER_MANUAL),

                Forms\Components\Select::make('template')
                    ->label('Шаблон (роспись)')
                    ->options(Certificate::templateOptions())
                    ->default('gasuns')
                    ->required()
                    ->live()
                    ->helperText(fn (Forms\Get $get) => $get('template') === 'sanka'
                        ? 'Экзаменационный шаблон: автовыдача сработает только для студентов, у которых есть баллы экзамена (затягиваются из гугл-таблицы).'
                        : null),

                Forms\Components\Toggle::make('auto_issue_enabled')
                    ->label('Автовыдача включена')
                    ->default(true)
                    ->visible(fn (Forms\Get $get) => $get('trigger_type') !== CertificateMilestone::TRIGGER_MANUAL),

                Forms\Components\Toggle::make('is_active')
                    ->label('Веха активна')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Веха')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('trigger_type')
                    ->label('Триггер')
                    ->formatStateUsing(fn (CertificateMilestone $record) => match ($record->trigger_type) {
                        CertificateMilestone::TRIGGER_BLOCK => $record->start_block === $record->end_block
                            ? "Блок №{$record->start_block}"
                            : "Блоки №{$record->start_block}–{$record->end_block}",
                        CertificateMilestone::TRIGGER_LESSON_COUNT => $record->isRepeating()
                            ? "Каждые {$record->repeat_every} занятий"
                            : "Первые {$record->trigger_lesson} занятий",
                        CertificateMilestone::TRIGGER_TEXTBOOK => "Урок учебника ≥ {$record->trigger_lesson}",
                        CertificateMilestone::TRIGGER_MATERIAL => "{$record->trigger_lesson} занятий «{$record->material_tag}»",
                        default => 'Вручную',
                    }),

                Tables\Columns\TextColumn::make('document_type')
                    ->label('Документ')
                    ->formatStateUsing(fn ($state) => CertificateMilestone::DOCUMENT_TYPES[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => $state === CertificateMilestone::DOC_SPRAVKA ? 'info' : 'success'),

                Tables\Columns\TextColumn::make('template')
                    ->label('Шаблон')
                    ->formatStateUsing(fn ($state) => Certificate::TEMPLATES[$state]['label'] ?? $state)
                    ->badge()
                    ->color(fn ($state) => $state === 'sanka' ? 'warning' : 'gray'),

                Tables\Columns\IconColumn::make('auto_issue_enabled')
                    ->label('Авто')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),

                Tables\Columns\TextColumn::make('certificates_count')
                    ->label('Выдано')
                    ->counts('certificates'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Добавить веху')
                    ->icon('heroicon-o-plus'),
            ])
            ->actions([
                // Прозрачность авторазметки material-вех: сколько упоминаний
                // ключевых слов в стенограмме каждого урока и какой тег присвоен.
                Tables\Actions\Action::make('check_material_tags')
                    ->label('Проверить разметку')
                    ->icon('heroicon-o-magnifying-glass')
                    ->visible(fn (CertificateMilestone $record) => $record->trigger_type === CertificateMilestone::TRIGGER_MATERIAL)
                    ->action(function (CertificateMilestone $record) {
                        $tagger = app(LessonMaterialTagger::class);
                        $keywords = $record->materialKeywords();

                        $lines = Lesson::query()
                            ->where('course_id', $record->course_id)
                            ->orderBy('lesson_date')
                            ->orderBy('sort_order')
                            ->get()
                            ->map(function (Lesson $lesson) use ($tagger, $keywords) {
                                $mentions = empty($lesson->transcript_file)
                                    ? '— (нет стенограммы)'
                                    : (string) $tagger->mentionCount($lesson, $keywords);
                                $tag = $lesson->material_tag
                                    ? "{$lesson->material_tag} ({$lesson->material_tag_source})"
                                    : '—';

                                return "«{$lesson->title}»: упоминаний {$mentions}, тег: {$tag}";
                            });

                        Notification::make()
                            ->title('Разметка уроков курса')
                            ->body($lines->isEmpty() ? 'Уроков нет.' : $lines->implode("\n"))
                            ->info()
                            ->persistent()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
