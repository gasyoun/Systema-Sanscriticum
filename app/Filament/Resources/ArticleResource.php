<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Awcodes\Curator\Components\Forms\CuratorPicker;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Блог';
    protected static ?int $navigationSort = 20; // ниже рубрик

    protected static ?string $navigationLabel = 'Статьи';
    protected static ?string $modelLabel = 'Статья';
    protected static ?string $pluralModelLabel = 'Статьи';

    // Количество записей в сайдбаре (опубликованные)
    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::where('is_published', true)->count();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([

            // ═══════════════════════════════════════════════
            // ТАБЫ — чтобы форма не была километровой
            // ═══════════════════════════════════════════════
            Forms\Components\Tabs::make('Article Tabs')
                ->columnSpanFull()
                ->tabs([

                    // ── Вкладка 1: ОСНОВНОЕ ──
                    Forms\Components\Tabs\Tab::make('Основное')
                        ->icon('heroicon-o-pencil-square')
                        ->schema([
                            Forms\Components\Section::make('Hero-секция')
                                ->description('Эти поля формируют шапку статьи: бейдж, заголовок, подзаголовок, лид и мета.')
                                ->schema([

                                    Forms\Components\TextInput::make('title')
                                        ->label('Заголовок (H1)')
                                        ->required()
                                        ->maxLength(255)
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (string $operation, ?string $state, Forms\Set $set): void {
                                            if ($operation === 'create' && !empty($state)) {
                                                $set('slug', Str::slug($state));
                                            }
                                        })
                                        ->helperText('Например: "Санскрит для взрослого мозга:"'),

                                    Forms\Components\TextInput::make('subtitle')
                                        ->label('Подзаголовок (курсив в H1)')
                                        ->maxLength(255)
                                        ->helperText('Необязательно. Вторая строка заголовка — в hero отображается курсивом.'),

                                    Forms\Components\TextInput::make('slug')
                                        ->label('URL (slug)')
                                        ->required()
                                        ->maxLength(255)
                                        ->unique(ignoreRecord: true)
                                        ->prefix('/s/')
                                        ->helperText('Латиницей. Меняйте осторожно — это ломает поисковую выдачу.'),

                                    Forms\Components\Textarea::make('excerpt')
                                        ->label('Лид / анонс')
                                        ->rows(3)
                                        ->maxLength(500)
                                        ->helperText('Отображается в hero под заголовком и на карточке в списке /s/.')
                                        ->columnSpanFull(),

                                    Forms\Components\Grid::make(3)->schema([
                                        Forms\Components\Select::make('category_id')
                                            ->label('Рубрика')
                                            ->relationship('category', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->createOptionForm([
                                                Forms\Components\TextInput::make('name')->required()->label('Название'),
                                                Forms\Components\TextInput::make('slug')->required()->label('Slug'),
                                            ]),

                                        Forms\Components\TextInput::make('reading_time')
                                            ->label('Время чтения, мин')
                                            ->numeric()
                                            ->default(5)
                                            ->minValue(1)
                                            ->maxValue(120)
                                            ->required(),

                                        Forms\Components\TextInput::make('author_name')
                                            ->label('Автор')
                                            ->default('Общество ревнителей санскрита')
                                            ->maxLength(255),
                                    ]),

                                    Forms\Components\FileUpload::make('cover_path')
                                        ->label('Обложка (для списка /s/)')
                                        ->image()
                                        ->imageEditor() // Filament сам даст crop/rotate
                                        ->directory('articles/covers')
                                        ->disk('public')
                                        ->maxSize(5120) // 5MB
                                        ->helperText('Необязательно. Если пусто — на карточке будет заглушка.'),
                                ])
                                ->columns(2),
                        ]),

                    // ── Вкладка 2: КОНТЕНТ (HTML) ──
                    Forms\Components\Tabs\Tab::make('Контент (HTML)')
                        ->icon('heroicon-o-code-bracket')
                        ->schema([
                            Forms\Components\Section::make('HTML-разметка статьи')
                                ->description(
                                    'Вставьте содержимое <main class="article-body">...</main> из подготовленного HTML-файла. ' .
                                    'Только внутренность main — без шапки сайта, hero-секции и футера.'
                                )
                                ->schema([
                                    Forms\Components\Textarea::make('body')
                                        ->label('HTML-код статьи')
                                        ->required()
                                        ->rows(30)
                                        ->extraInputAttributes([
                                            'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 13px; line-height: 1.5;',
                                            'spellcheck' => 'false',
                                        ])
                                        ->columnSpanFull(),
                                ]),
                                
                                // ══════════════════════════════════════════════════
// КАРТИНКИ В СТАТЬЕ
// ══════════════════════════════════════════════════
Forms\Components\Section::make('Картинки в статье')
    ->description('Выберите картинки из медиатеки. Под каждой будет готовый HTML-сниппет для копирования в поле "HTML-код статьи" выше.')
    ->collapsible()
    ->schema([
        CuratorPicker::make('inline_images')
            ->label('Картинки')
            ->multiple()
            ->relationship('inlineImages', 'id') // см. шаг C — связь many-to-many
            ->buttonLabel('Открыть медиатеку')
            ->color('primary')
            ->size('md')
            ->helperText('Можно выбрать несколько. После сохранения формы под каждой картинкой появится HTML-сниппет.')
            ->columnSpanFull(),

        // Сниппеты — рендерятся через blade-компонент
        Forms\Components\Placeholder::make('snippets')
    ->label('HTML-сниппеты для вставки')
    ->content(function (?Article $record): \Illuminate\Support\HtmlString {
        if (!$record || $record->inlineImages->isEmpty()) {
            return new \Illuminate\Support\HtmlString(
                '<div style="padding:16px; background:#f3f4f6; border-radius:8px; color:#6b7280; font-size:14px;">'
                . 'Сохраните статью с выбранными картинками — здесь появятся готовые сниппеты для копирования.'
                . '</div>'
            );
        }

        $html = '<div style="display:flex; flex-direction:column; gap:14px;">';

        foreach ($record->inlineImages as $img) {
            $url = $img->url;
            $alt = e($img->alt ?? $img->name ?? '');
            $caption = e($img->caption ?? '');

            // Сами сниппеты — экранируем для безопасного вывода в textarea
            $simpleSnippet = sprintf('<img src="%s" alt="%s">', $url, $alt);

            $figureSnippet = $caption
                ? sprintf(
                    "<figure class=\"article-figure\">\n    <img src=\"%s\" alt=\"%s\">\n    <figcaption>%s</figcaption>\n</figure>",
                    $url, $alt, $caption
                )
                : sprintf(
                    "<figure class=\"article-figure\">\n    <img src=\"%s\" alt=\"%s\">\n</figure>",
                    $url, $alt
                );

            // Экранируем HTML-теги, чтобы они показались как текст в <textarea>
            $simpleEscaped = htmlspecialchars($simpleSnippet, ENT_QUOTES, 'UTF-8');
            $figureEscaped = htmlspecialchars($figureSnippet, ENT_QUOTES, 'UTF-8');

            $thumbUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $cardId = 'snippet-' . $img->id;

            $html .= <<<HTML
<div style="display:grid; grid-template-columns:90px 1fr; gap:14px; padding:12px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
    <div style="width:90px; height:90px; background:#f3f4f6; border-radius:8px; overflow:hidden; flex-shrink:0;">
        <img src="{$thumbUrl}" alt="" style="width:90px !important; height:90px !important; max-height:90px !important; object-fit:cover !important; display:block; margin:0 !important;">
    </div>
    <div style="min-width:0; display:flex; flex-direction:column; gap:10px;">
        <div>
            <div style="font-size:11px; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em; font-weight:600;">Простой</div>
            <div style="display:flex; gap:8px; align-items:flex-start;">
                <textarea readonly id="{$cardId}-simple" style="flex:1; min-height:38px; padding:8px 10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; font-size:12px; font-family:ui-monospace,monospace; resize:none; outline:none; color:#111;">{$simpleEscaped}</textarea>
                <button type="button" data-target="{$cardId}-simple" class="article-copy-btn" style="padding:8px 14px; background:#3b82f6; color:#fff; border:0; border-radius:6px; font-size:12px; cursor:pointer; white-space:nowrap; font-weight:600;">Копировать</button>
            </div>
        </div>
        <div>
            <div style="font-size:11px; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.05em; font-weight:600;">С обёрткой &lt;figure&gt;</div>
            <div style="display:flex; gap:8px; align-items:flex-start;">
                <textarea readonly id="{$cardId}-figure" style="flex:1; min-height:80px; padding:8px 10px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; font-size:12px; font-family:ui-monospace,monospace; resize:vertical; outline:none; color:#111;">{$figureEscaped}</textarea>
                <button type="button" data-target="{$cardId}-figure" class="article-copy-btn" style="padding:8px 14px; background:#3b82f6; color:#fff; border:0; border-radius:6px; font-size:12px; cursor:pointer; white-space:nowrap; font-weight:600;">Копировать</button>
            </div>
        </div>
    </div>
</div>
HTML;
        }

        // Один обработчик через делегирование событий — работает для всех кнопок
        $html .= <<<'HTML'
</div>
<script>
(function(){
    if (window.__articleCopyBound) return;
    window.__articleCopyBound = true;

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.article-copy-btn');
        if (!btn) return;

        e.preventDefault();
        const targetId = btn.getAttribute('data-target');
        const textarea = document.getElementById(targetId);
        if (!textarea) return;

        // Способ 1: современный clipboard API (нужен HTTPS)
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textarea.value).then(() => {
                showCopied(btn);
            }).catch(() => {
                fallbackCopy(textarea, btn);
            });
        } else {
            // Способ 2: старый fallback (работает на http)
            fallbackCopy(textarea, btn);
        }
    });

    function fallbackCopy(textarea, btn) {
        textarea.focus();
        textarea.select();
        try {
            document.execCommand('copy');
            showCopied(btn);
        } catch (err) {
            btn.textContent = 'Ошибка';
            setTimeout(() => btn.textContent = 'Копировать', 1500);
        }
    }

    function showCopied(btn) {
        const original = btn.textContent;
        btn.textContent = '✓ Скопировано';
        btn.style.background = '#10b981';
        setTimeout(() => {
            btn.textContent = 'Копировать';
            btn.style.background = '#3b82f6';
        }, 1500);
    }
})();
</script>
HTML;

        return new \Illuminate\Support\HtmlString($html);
    })
    ->columnSpanFull(),
    ]),

                            // Блок предпросмотра (рендерит body как HTML)
                            Forms\Components\Section::make('Предпросмотр')
                                ->collapsible()
                                ->collapsed() // закрыт по умолчанию, чтобы не грузить глаза при открытии формы
                                ->schema([
                                    Forms\Components\Placeholder::make('body_preview')
                                        ->label('')
                                        ->content(fn (Forms\Get $get) => new \Illuminate\Support\HtmlString(
                                            '<div class="prose max-w-none" style="padding: 16px; background: #fff; border-radius: 8px;">'
                                            . ($get('body') ?: '<em style="color:#888">Пусто — начните вводить HTML в поле выше.</em>')
                                            . '</div>'
                                        ))
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    // ── Вкладка 3: ПУБЛИКАЦИЯ ──
                    Forms\Components\Tabs\Tab::make('Публикация')
                        ->icon('heroicon-o-globe-alt')
                        ->schema([
                            Forms\Components\Section::make('Статус')
                                ->schema([
                                    Forms\Components\Toggle::make('is_published')
                                        ->label('Опубликована')
                                        ->helperText('Если выключено — статья видна только вам в админке.')
                                        ->default(false)
                                        ->onColor('success')
                                        ->live(),

                                    Forms\Components\DateTimePicker::make('published_at')
                                        ->label('Дата публикации')
                                        ->displayFormat('d.m.Y H:i')
                                        ->seconds(false)
                                        ->helperText('Оставьте пустым — проставится автоматически при сохранении.')
                                        ->visible(fn (Forms\Get $get) => $get('is_published')),
                                ])
                                ->columns(2),
                        ]),

                    // ── Вкладка 4: SEO ──
                    Forms\Components\Tabs\Tab::make('SEO')
                        ->icon('heroicon-o-magnifying-glass')
                        ->schema([
                            Forms\Components\Section::make('Мета-теги для поисковиков')
                                ->description('Если поля пустые — подставятся автоматически из заголовка и анонса.')
                                ->schema([
                                    Forms\Components\TextInput::make('meta_title')
                                        ->label('Meta title')
                                        ->maxLength(255)
                                        ->helperText('Попадает в <title> и вкладку браузера. Оптимально: 50–60 символов.'),

                                    Forms\Components\Textarea::make('meta_description')
                                        ->label('Meta description')
                                        ->maxLength(500)
                                        ->rows(3)
                                        ->helperText('Сниппет в выдаче Яндекса/Google. Оптимально: 140–160 символов.'),
                                ]),
                        ]),
                        
                        // ── Вкладка 5: АНАЛИТИКА ──
Forms\Components\Tabs\Tab::make('Аналитика')
    ->icon('heroicon-o-chart-bar')
    ->schema([
        Forms\Components\Section::make('Счётчики аналитики')
            ->description(
                'ID счётчиков для этой статьи. Если оставить пустыми — будут использоваться дефолтные ID из глобальных настроек блога (если они там заданы).'
            )
            ->schema([
                Forms\Components\TextInput::make('yandex_metrika_id')
                    ->label('ID Яндекс.Метрики')
                    ->placeholder('12345678')
                    ->numeric()
                    ->maxLength(20)
                    ->helperText('Только цифры, без https://...'),

                Forms\Components\TextInput::make('vk_pixel_id')
                    ->label('ID VK Пикселя')
                    ->placeholder('3000000')
                    ->maxLength(20)
                    ->helperText('Числовой ID или VK-RTRG-...'),
            ])
            ->columns(2),

        Forms\Components\Section::make('Цели для отслеживания')
            ->description('Эти цели срабатывают автоматически на странице статьи. Создайте их в Яндекс.Метрике с такими же идентификаторами.')
            ->collapsible()
            ->collapsed()
            ->schema([
                Forms\Components\Placeholder::make('goals_list')
                    ->label('')
                    ->content(new \Illuminate\Support\HtmlString(
                        '<div style="font-family: ui-monospace, monospace; font-size: 13px; line-height: 1.8;">'
                        . '<div><code style="background:#f3f4f6; padding:2px 8px; border-radius:4px; color:#dc2626;">article_time_60s</code> — 60 секунд на странице</div>'
                        . '<div><code style="background:#f3f4f6; padding:2px 8px; border-radius:4px; color:#dc2626;">article_scroll_75</code> — доскроллил до 75% статьи</div>'
                        . '<div><code style="background:#f3f4f6; padding:2px 8px; border-radius:4px; color:#dc2626;">article_trial_modal_open</code> — открыл модалку записи</div>'
                        . '<div><code style="background:#f3f4f6; padding:2px 8px; border-radius:4px; color:#dc2626;">article_cta_click</code> — клик по CTA-кнопке</div>'
                        . '<div><code style="background:#f3f4f6; padding:2px 8px; border-radius:4px; color:#dc2626;">article_lead_form_submit</code> — отправил форму</div>'
                        . '<div style="margin-top:8px; padding-top:8px; border-top:1px solid #e5e7eb;"><code style="background:#dcfce7; padding:2px 8px; border-radius:4px; color:#166534; font-weight:bold;">lead_from_article</code> — <strong>главная конверсия</strong> (срабатывает на странице "Спасибо")</div>'
                        . '</div>'
                    )),
            ]),
    ]),

                    // ── Вкладка 5: СТАТИСТИКА (read-only) ──
                    Forms\Components\Tabs\Tab::make('Статистика')
                        ->icon('heroicon-o-chart-bar')
                        ->visible(fn (?Article $record) => $record !== null) // только при редактировании
                        ->schema([
                            Forms\Components\Placeholder::make('views_count')
                                ->label('Всего просмотров')
                                ->content(fn (?Article $record) => $record ? number_format($record->views_count, 0, '.', ' ') : '0'),

                            Forms\Components\Placeholder::make('unique_views')
                                ->label('Уникальных посетителей')
                                ->content(fn (?Article $record) => $record ? number_format($record->unique_views_count, 0, '.', ' ') : '0'),

                            Forms\Components\Placeholder::make('created_at')
                                ->label('Создана')
                                ->content(fn (?Article $record) => $record?->created_at?->format('d.m.Y H:i') ?? '—'),

                            Forms\Components\Placeholder::make('updated_at')
                                ->label('Последнее изменение')
                                ->content(fn (?Article $record) => $record?->updated_at?->format('d.m.Y H:i') ?? '—'),
                        ])
                        ->columns(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                Tables\Columns\ImageColumn::make('cover_path')
                    ->label('Обложка')
                    ->disk('public')
                    ->square()
                    ->size(60)
                    ->defaultImageUrl(asset('images/logo.png')),

                Tables\Columns\TextColumn::make('title')
                    ->label('Заголовок')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->limit(60)
                    ->weight('bold')
                    ->description(fn (Article $record): ?string => $record->subtitle),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Рубрика')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->placeholder('Без рубрики'),

                Tables\Columns\IconColumn::make('is_published')
                    ->label('Опубл.')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('views_count')
                    ->label('Просмотры')
                    ->numeric()
                    ->sortable()
                    ->alignRight()
                    ->icon('heroicon-m-eye'),

                Tables\Columns\TextColumn::make('reading_time')
                    ->label('Чтение')
                    ->suffix(' мин')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Дата публикации')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('slug')
                    ->label('URL')
                    ->prefix('/s/')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('Статус публикации')
                    ->placeholder('Все')
                    ->trueLabel('Только опубликованные')
                    ->falseLabel('Только черновики'),

                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Рубрика')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                // Быстрая кнопка "посмотреть на сайте" — откроется в новой вкладке
                Tables\Actions\Action::make('view_live')
                    ->label('На сайте')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (Article $record): string => url('/s/' . $record->slug))
                    ->openUrlInNewTab()
                    ->visible(fn (Article $record): bool => $record->is_published),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    // Массовая публикация/снятие с публикации
                    Tables\Actions\BulkAction::make('publish')
                        ->label('Опубликовать')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update([
                            'is_published' => true,
                            'published_at' => now(),
                        ]))
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation(),

                    Tables\Actions\BulkAction::make('unpublish')
                        ->label('Снять с публикации')
                        ->icon('heroicon-m-x-circle')
                        ->color('danger')
                        ->action(fn ($records) => $records->each->update(['is_published' => false]))
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation(),
                ]),
            ])
            ->emptyStateHeading('Статей пока нет')
            ->emptyStateDescription('Создайте первую статью или запустите импорт из public/articles/.')
            ->emptyStateIcon('heroicon-o-document-plus');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'edit'   => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}