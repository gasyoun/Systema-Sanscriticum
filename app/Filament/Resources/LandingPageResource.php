<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\LandingPageResource\Pages;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\PromoCode;
use App\Models\Tariff;
use Awcodes\Curator\Components\Forms\CuratorPicker;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form; // <-- ДОБАВЛЕНО
use Filament\Forms\Get;      // <-- ДОБАВЛЕНО
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class LandingPageResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = LandingPage::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 130;

    protected static ?string $navigationGroup = 'Маркетинг';

    protected static ?string $navigationLabel = 'Лендинги';

    protected static ?string $pluralModelLabel = 'Лендинги';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([

                // === 1. ОБЩИЕ НАСТРОЙКИ ===
                Section::make('Основные настройки')
                    ->schema([
                        TextInput::make('title')
                            ->label('Заголовок страницы (Title)')
                            ->required(),
                        TextInput::make('slug')
                            ->label('URL адрес (slug)')
                            ->prefix(config('app.url').'/promo/')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Toggle::make('is_active')
                            ->label('Опубликовано')
                            ->default(true),
                        Toggle::make('is_listed')
                            ->label('Показывать в каталоге и блоке «Наши курсы»')
                            ->helperText('Выключите для страницы записи вебинара — она останется доступной только по прямой ссылке.')
                            ->default(true),
                    ]),

                // === Шапка лендинга ===
                Section::make('Шапка лендинга')
                    ->description('По умолчанию в шапке меню сайта (Главная / Все курсы / Блог). Для вебинарного лендинга его можно скрыть и показать вместо него заметку-ссылку.')
                    ->collapsed()
                    ->schema([
                        Toggle::make('hide_default_nav')
                            ->label('Скрыть меню сайта (Главная / Все курсы / Блог)')
                            ->live()
                            ->default(false),
                        TextInput::make('header_note_text')
                            ->label('Заметка вместо меню')
                            ->placeholder('Для участия нужен Zoom — скачать')
                            ->helperText('Показывается в шапке вместо меню. Пусто → в шапке только логотип.')
                            ->visible(fn (Get $get): bool => (bool) $get('hide_default_nav')),
                        TextInput::make('header_note_url')
                            ->label('Ссылка заметки')
                            ->url()
                            ->placeholder('https://zoom.us/download')
                            ->visible(fn (Get $get): bool => (bool) $get('hide_default_nav')),
                    ]),

                // === Вебинар: письмо лиду + время выдачи лид-магнита ===
                Section::make('Вебинар (письмо лиду и выдача лид-магнита)')
                    ->description('Дата/время вебинара управляют и письмом-приглашением (n8n зовёт /api/webhooks/lead-step), и моментом выдачи лид-магнита в боте — он уходит за N минут до старта.')
                    ->collapsed()
                    ->schema([
                        TextInput::make('webinar_url')
                            ->label('Ссылка на вебинар (Zoom / трансляция)')
                            ->url()
                            ->helperText('Пусто → письмо не уходит. Дата и название берутся из полей ниже.'),
                        TextInput::make('webinar_recording_url')
                            ->label('Ссылка на запись вебинара')
                            ->url()
                            ->helperText('Как заполните — лидам этого лендинга автоматически уйдёт письмо со ссылкой на запись (в течение 15 минут).'),
                        DateTimePicker::make('webinar_date')
                            ->label('Дата и время старта (МСК)')
                            ->native(false)
                            ->seconds(false)
                            ->displayFormat('d.m.Y H:i')
                            ->helperText('Точное время старта. Лид-магнит выдаётся за указанное ниже число минут до него.'),
                        TextInput::make('magnet_lead_minutes')
                            ->label('Выдать лид-магнит за N минут до старта')
                            ->numeric()
                            ->minValue(1)
                            ->default(60)
                            ->suffix('мин')
                            ->helperText('Если вебинар не задан — лид-магнит выдаётся сразу при запуске бота.'),
                        TextInput::make('webinar_label')
                            ->label('Название вебинара')
                            ->default('Бесплатный вебинар'),
                    ]),

                // === 2. КОНСТРУКТОР (BUILDER) ===
                Section::make('Конструктор Лендинга')
                    ->description('Собирайте страницу из блоков.')
                    ->collapsible()
                    ->schema([
                        Builder::make('content')
                            ->label('')
                            ->blocks([

                                // 1. HERO (Первый экран - Текст по центру)
                                Block::make('hero_block')
                                    ->label('1. Первый экран (Текст по центру)')
                                    ->icon('heroicon-m-star')
                                    ->schema([
                                        TextInput::make('subtitle')
                                            ->label('Надзаголовок (оранжевый)')
                                            ->placeholder('Старт 15 марта'),

                                        TextInput::make('title')
                                            ->label('Заголовок (Оффер)')
                                            ->required()
                                            ->helperText('Оберните слово в *звёздочки*, чтобы выделить его акцентным цветом. Пример: Санскрит с нуля — *и сразу текст*.'),

                                        Textarea::make('description')
                                            ->label('Текст под заголовком')
                                            ->rows(3),

                                        Toggle::make('show_button')
                                            ->label('Показывать кнопку')
                                            ->helperText('Выключите, если на первом экране кнопка не нужна (например, заявка идёт ниже по странице).')
                                            ->live()
                                            ->default(true),

                                        TextInput::make('button_text')
                                            ->label('Текст кнопки')
                                            ->default('Записаться')
                                            ->visible(fn (Get $get): bool => $get('show_button') ?? true),

                                        Repeater::make('badges')
                                            ->label('Плашки под кнопкой')
                                            ->schema([
                                                TextInput::make('text')->label('Текст плашки')->required(),
                                            ])
                                            ->grid(3)
                                            ->maxItems(4)
                                            ->default([
                                                ['text' => 'Старт потока скоро'],
                                                ['text' => 'Места ограничены'],
                                                ['text' => 'Онлайн формат'],
                                            ]),

                                        Toggle::make('registered_dynamic')
                                            ->label('Считать регистрации автоматически')
                                            ->helperText('N = стартовое число + реальные лиды этого лендинга. Текст «Ещё X мест» считается до ближайшей десятки.')
                                            ->live()
                                            ->default(false),

                                        TextInput::make('registered_base')
                                            ->label('Стартовое число регистраций')
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(30)
                                            ->visible(fn (Get $get): bool => (bool) $get('registered_dynamic')),

                                        TextInput::make('registered_note')
                                            ->label('Соц. доказательство (ручной текст)')
                                            ->placeholder('✅ Уже зарегистрировались 137 человек')
                                            ->helperText('Используется, когда автосчёт выключен. Очистите поле, чтобы скрыть плашку.')
                                            ->visible(fn (Get $get): bool => ! $get('registered_dynamic')),
                                    ]),

                                // 2. VIDEO
                                Block::make('video_block')
                                    ->label('2. Видео анонс')
                                    ->icon('heroicon-m-video-camera')
                                    ->schema([
                                        TextInput::make('title')->label('Заголовок'),
                                        TextInput::make('vk_url')
                                            ->label('ВК Видео')
                                            ->helperText('Ссылка «Поделиться» или iframe-embed (vk.com/video_ext.php…).'),
                                        TextInput::make('youtube_url')
                                            ->label('YouTube')
                                            ->helperText('Любая ссылка на ролик (watch / youtu.be / embed).'),
                                        TextInput::make('rutube_url')
                                            ->label('RuTube')
                                            ->helperText('Ссылка на ролик RuTube (video / play/embed).'),
                                        TextInput::make('video_url')
                                            ->label('Прочий iframe (запасной)')
                                            ->helperText('Необязательно. Готовая embed-ссылка для прочих хостингов (Kinescope и т.п.). Заполните хотя бы один источник.'),
                                    ]),

                                // 2.1. WEBINAR TRANSCRIPT (Стенограмма вебинара + синхронизация с видео)
                                Block::make('webinar_transcript_block')
                                    ->label('2.1. Стенограмма вебинара (синхрон с видео)')
                                    ->icon('heroicon-m-document-text')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('Стенограмма вебинара')
                                            ->helperText('Оберните слово в *звёздочки*, чтобы выделить его акцентным цветом. Пример: Стенограмма *вебинара*.'),
                                        TextInput::make('youtube_url')
                                            ->label('YouTube')
                                            ->helperText('Любая ссылка на ролик (watch / youtu.be / embed). Перемотка по тексту работает с YouTube и RuTube.'),
                                        TextInput::make('rutube_url')
                                            ->label('RuTube')
                                            ->helperText('Ссылка на ролик RuTube (video / play/embed).'),
                                        FileUpload::make('transcript_file')
                                            ->label('JSON-расшифровка')
                                            ->disk('public')
                                            ->directory('transcripts')
                                            ->acceptedFileTypes(['application/json'])
                                            ->helperText('JSON-расшифровка (формат Deepgram) — тот же файл, что у транскрипта урока. По нему строится кликабельная стенограмма с подсветкой.'),
                                        Select::make('sync_source')
                                            ->label('Стенограмма сделана с видео')
                                            ->options([
                                                'youtube' => 'YouTube',
                                                'rutube' => 'RuTube',
                                            ])
                                            ->default('youtube')
                                            ->helperText('Этот плеер откроется по умолчанию — у него таймкоды совпадают со стенограммой.'),
                                        Textarea::make('chapters_text')
                                            ->label('Главы списком (быстрый ввод)')
                                            ->rows(8)
                                            ->placeholder("00:00:00 Введение\n05:30 Тема 1\n1:02:15 Тема 2")
                                            ->helperText('По одной главе в строке: сначала таймкод (m:ss или h:mm:ss), затем через пробел название. Удобно вставить готовый список целиком. Объединяется с главами ниже.'),
                                        Repeater::make('chapters')
                                            ->label('Главы по одной (необязательно)')
                                            ->schema([
                                                TextInput::make('time')
                                                    ->label('Таймкод')
                                                    ->placeholder('5:30 или 1:02:15')
                                                    ->required(),
                                                TextInput::make('title')
                                                    ->label('Название главы')
                                                    ->required(),
                                            ])
                                            ->grid(2)
                                            ->reorderableWithButtons()
                                            ->collapsed()
                                            ->itemLabel(fn (array $state): ?string => trim(($state['time'] ?? '').' — '.($state['title'] ?? ''), ' —') ?: null)
                                            ->addActionLabel('Добавить главу')
                                            ->helperText('Необязательно. Если заполнить — справа от стенограммы появится навигация по главам (клик = перемотка).'),
                                    ]),

                                // 3. AUDIENCE
                                Block::make('audience_block')
                                    ->label('3. Для кого этот курс')
                                    ->icon('heroicon-m-users')
                                    ->schema([
                                        TextInput::make('title')->label('Заголовок')->default('Для кого этот курс'),
                                        Repeater::make('items')
                                            ->label('Карточки')
                                            ->schema([
                                                TextInput::make('title')->label('Заголовок карточки'),
                                                Textarea::make('description')->label('Текст'),
                                            ])->grid(2),
                                    ]),

                                // 11.1. RECORDED COURSES (Мини-блок «Курсы в записи»)
                                Block::make('recorded_courses_block')
                                    ->label('11.1. Курсы в записи (слайдер по 6)')
                                    ->icon('heroicon-m-rectangle-stack')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок блока')
                                            ->default('Курсы в записи'),
                                        Textarea::make('subtitle')
                                            ->label('Подзаголовок')
                                            ->default('Учитесь в своём темпе — записи лекций с пожизненным доступом.')
                                            ->rows(2),
                                        Select::make('variant')
                                            ->label('Цветовая схема')
                                            ->options([
                                                'light' => 'Светлая (под тёплый фон лендинга)',
                                                'dark' => 'Тёмная',
                                            ])
                                            ->default('light'),
                                    ]),

                                // 11. COURSES GRID (Сетка других курсов)
                                Block::make('courses_grid')
                                    ->label('11. Сетка курсов (Светлая)')
                                    ->icon('heroicon-m-squares-2x2') // Иконка плитки
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок блока')
                                            ->default('Наши курсы'),

                                        Textarea::make('subtitle')
                                            ->label('Подзаголовок')
                                            ->default('Выберите курс для начала обучения.')
                                            ->rows(2),

                                        TextInput::make('limit')
                                            ->label('Сколько курсов показать?')
                                            ->numeric()
                                            ->default(6)
                                            ->helperText('Если курсов много, лучше ограничить их число (например, 3 или 6), чтобы не перегружать лендинг.'),
                                    ]),

                                // 13. ABOUT PLATFORM (Светлая карточка)
                                Block::make('about_platform_light')
                                    ->label('13. Светлая карточка (О платформе/Дисклеймер)')
                                    ->icon('heroicon-m-information-circle')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('О нашей платформе')
                                            ->columnSpanFull(),

                                        RichEditor::make('content')
                                            ->label('Текст')
                                            ->toolbarButtons([
                                                'bold',
                                                'italic',
                                                'strike',
                                                'link',
                                                'h2',
                                                'h3',
                                                'blockquote',
                                                'bulletList',
                                                'orderedList',
                                                'codeBlock',
                                            ])
                                            ->columnSpanFull() // Растягиваем редактор на всю ширину
                                            ->default('<p>Все программы Общества ревнителей санскрита носят исключительно просветительский характер. Участие в них не ведёт к присвоению квалификации, профессии или получению документов об образовании.</p><p>Наша главная цель — популяризация санскрита, знакомство с богатым культурным и философским наследием Индии, а также создание сообщества единомышленников для совместного изучения этого древнего языка.</p><p>Наши лекторы — это индологи, востоковеды, филологи, философы, йоги с большим практическим опытом.</p><p>До встречи на занятиях!</p>'),
                                    ]),

                                // 4. RESULTS (Бенто-сетка)
                                Block::make('results_block')
                                    ->label('4. Результаты (Сетка карточек)')
                                    ->icon('heroicon-m-squares-2x2')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('Вот, что могут 90% наших учеников'),

                                        Repeater::make('items')
                                            ->label('Карточки преимуществ')
                                            ->schema([
                                                CuratorPicker::make('icon')
                                                    ->label('Иконка (желательно PNG/SVG)')
                                                    ->buttonLabel('Выбрать из медиатеки'),

                                                TextInput::make('title')
                                                    ->label('Заголовок карточки')
                                                    ->required(),

                                                Textarea::make('description')
                                                    ->label('Текст карточки')
                                                    ->rows(2),

                                                Toggle::make('is_wide')
                                                    ->label('Широкая карточка (на 2 колонки)')
                                                    ->default(false),
                                            ])
                                            ->grid(2)
                                            ->defaultItems(3),
                                    ]),

                                // ==============================================================
                                // 13. НОВЫЙ БЛОК: ГЛАВНЫЙ ЭКРАН СО СТИКИ ФОРМОЙ И КАСТОМИЗАЦИЕЙ
                                // ==============================================================
                                Block::make('new_hero_with_form')
                                    ->label('13. Главный экран с формой (Стики/Универсальный)')
                                    ->icon('heroicon-o-document')
                                    ->schema([
                                        // --- КОНТЕНТ ---
                                        Section::make('Текст и контент')
                                            ->schema([
                                                TextInput::make('subtitle')
                                                    ->label('Надзаголовок (Плашка)')
                                                    ->default('ОНЛАЙН-КУРС В 2-Х ЧАСТЯХ'),

                                                Toggle::make('show_days_countdown')
                                                    ->label('Плашка «Осталось N дней» (справа сверху, над формой)')
                                                    ->helperText('Считается от даты вебинара. Появляется, только если до вебинара ещё есть время.')
                                                    ->default(false),

                                                Textarea::make('title')
                                                    ->label('Главный заголовок')
                                                    ->default('Название вашего невероятного курса')
                                                    ->rows(3)
                                                    ->helperText('Enter — перенос строки. Оберните слово в *звёздочки*, чтобы выделить его акцентным цветом. Пример: Санскрит и *вебинар* 17 июня.'),

                                                Textarea::make('description')
                                                    ->label('Описание')
                                                    ->default('Из первых уст от топовых спикеров. Освойте новую профессию за 3 месяца и начните зарабатывать из любой точки мира.')
                                                    ->rows(3),

                                                TextInput::make('button_text')
                                                    ->label('Текст кнопки')
                                                    ->default('Оставить заявку'),

                                                Repeater::make('badges')
                                                    ->label('Плашки под кнопкой')
                                                    ->schema([
                                                        TextInput::make('text')->label('Текст плашки')->required(),
                                                    ])
                                                    ->grid(3)
                                                    ->maxItems(4)
                                                    ->default([
                                                        ['text' => 'Старт потока скоро'],
                                                        ['text' => 'Места ограничены'],
                                                        ['text' => 'Онлайн формат'],
                                                    ]),
                                            ]),

                                        // --- ФОРМА ЗАЯВКИ (тексты полей) ---
                                        Section::make('Форма заявки')
                                            ->collapsed()
                                            ->schema([
                                                Toggle::make('form_minimal')
                                                    ->label('Упрощённая форма (без имени)')
                                                    ->helperText('Минимум полей: телефон и email, один обязательный чекбокс согласия и опциональное поле Telegram для подарка. Имя соберём позже в боте.')
                                                    ->default(false)
                                                    ->live(),

                                                // --- УПРОЩЁННЫЙ ВАРИАНТ ---
                                                TextInput::make('min_contact_placeholder')
                                                    ->label('Плейсхолдер поля телефона')
                                                    ->default('Телефон')
                                                    ->visible(fn (Get $get): bool => (bool) $get('form_minimal')),

                                                TextInput::make('min_email_placeholder')
                                                    ->label('Плейсхолдер поля email')
                                                    ->default('Email')
                                                    ->visible(fn (Get $get): bool => (bool) $get('form_minimal')),

                                                TextInput::make('min_button_text')
                                                    ->label('Текст кнопки')
                                                    ->default('Занять бесплатное место →')
                                                    ->visible(fn (Get $get): bool => (bool) $get('form_minimal')),

                                                Textarea::make('min_consent_note')
                                                    ->label('Подпись под кнопкой')
                                                    ->rows(2)
                                                    ->default('Нажимая кнопку, вы соглашаетесь с {link}. Ссылку пришлём в Telegram — спросим контакт после.')
                                                    ->helperText('{link} превратится в кликабельную «политику конфиденциальности». Enter — перенос строки.')
                                                    ->visible(fn (Get $get): bool => (bool) $get('form_minimal')),

                                                TextInput::make('min_tg_label')
                                                    ->label('Подпись опционального поля Telegram')
                                                    ->default('📩 Укажите Telegram — пришлём PDF «Алфавит деванагари» перед эфиром')
                                                    ->visible(fn (Get $get): bool => (bool) $get('form_minimal')),

                                                TextInput::make('min_tg_placeholder')
                                                    ->label('Плейсхолдер поля Telegram')
                                                    ->default('@username')
                                                    ->visible(fn (Get $get): bool => (bool) $get('form_minimal')),

                                                // --- ЗАГОЛОВОК/ПОДЗАГОЛОВОК (общие для обоих вариантов) ---
                                                Textarea::make('form_title')
                                                    ->label('Заголовок над формой')
                                                    ->default('Записаться на курс')
                                                    ->rows(2)
                                                    ->helperText('Enter — перенос строки.'),

                                                Textarea::make('form_subtitle')
                                                    ->label('Подзаголовок над формой')
                                                    ->default('Оставьте заявку, и мы свяжемся с вами в Telegram.')
                                                    ->rows(2)
                                                    ->helperText('Enter — перенос строки.'),

                                                // --- ПОЛНЫЙ ВАРИАНТ ---
                                                TextInput::make('submit_text')
                                                    ->label('Текст кнопки отправки')
                                                    ->default('Записаться')
                                                    ->helperText('Если пусто — возьмётся текст основной кнопки.')
                                                    ->visible(fn (Get $get): bool => ! $get('form_minimal')),

                                                Fieldset::make('Подписи и плейсхолдеры полей')
                                                    ->visible(fn (Get $get): bool => ! $get('form_minimal'))
                                                    ->schema([
                                                        TextInput::make('label_name')->label('Подпись «Имя»')->default('Ваше имя'),
                                                        TextInput::make('ph_name')->label('Плейсхолдер «Имя»')->default('Имя и фамилия'),

                                                        TextInput::make('label_contact')->label('Подпись «Телефон»')->default('Телефон'),
                                                        TextInput::make('ph_contact')->label('Плейсхолдер «Телефон»')->default('+7 999 000-00-00'),

                                                        TextInput::make('label_email')->label('Подпись «Email»')->default('Email'),
                                                        TextInput::make('ph_email')->label('Плейсхолдер «Email»')->default('mail@example.com'),

                                                        TextInput::make('label_social')->label('Подпись «Соцсеть»')->default('Telegram / VK / Instagram'),
                                                        TextInput::make('ph_social')->label('Плейсхолдер «Соцсеть»')->default('@username или ссылка'),
                                                    ])->columns(2),

                                                Textarea::make('social_gift_note')
                                                    ->label('Подарок за соцсеть (над полем «Соцсеть»)')
                                                    ->rows(2)
                                                    ->default('Заполните это поле — и мы пришлём вам подарок в указанный мессенджер 🎁')
                                                    ->helperText('Показывается над полем «Соцсеть». Очистите поле, чтобы скрыть.')
                                                    ->visible(fn (Get $get): bool => ! $get('form_minimal')),
                                            ]),

                                        // --- НАСТРОЙКИ ДИЗАЙНА ---
                                        Section::make('🎨 Настройки дизайна')
                                            ->collapsed()
                                            ->schema([
                                                ColorPicker::make('bg_color')
                                                    ->label('Цвет фона блока')
                                                    ->default('#FFFFFF'),

                                                ColorPicker::make('form_bg_color')
                                                    ->label('Цвет фона формы')
                                                    ->default('#FFFFFF'),

                                                ColorPicker::make('text_color')
                                                    ->label('Цвет заголовка')
                                                    ->default('#101010'),

                                                ColorPicker::make('accent_color')
                                                    ->label('Цвет акцентов (Кнопки, выделения)')
                                                    ->default('#E3122C'),

                                                Select::make('font_family')
                                                    ->label('Шрифт заголовка')
                                                    ->options([
                                                        "'Nunito Sans', sans-serif" => 'Nunito Sans (Современный)',
                                                        "'Charis SIL', serif" => 'Charis SIL (С засечками)',
                                                        "'Inter', sans-serif" => 'Inter (Строгий)',
                                                        'system-ui, sans-serif' => 'Системный шрифт',
                                                    ])
                                                    ->default("'Nunito Sans', sans-serif"),

                                                Select::make('title_size')
                                                    ->label('Размер заголовка (на ПК)')
                                                    ->options([
                                                        'text-4xl lg:text-5xl' => 'Средний (Medium)',
                                                        'text-5xl lg:text-6xl' => 'Большой (Large)',
                                                        'text-6xl lg:text-[75px]' => 'Огромный (Extra Large)',
                                                    ])
                                                    ->default('text-5xl lg:text-6xl'),
                                            ])->columns(2),
                                    ]),

                                // ==============================================================
                                // 14. БАННЕР-ПРИЗЫВ (Горизонтальный блок с кнопкой)
                                // ==============================================================
                                Block::make('cta_banner_block')
                                    ->label('14. Горизонтальный Баннер-призыв')
                                    ->icon('heroicon-o-megaphone')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('ВОПРОСЫ И ОТВЕТЫ А.В.ПАРИБКУ'),

                                        Textarea::make('subtitle')
                                            ->label('Подзаголовок')
                                            ->default('Посмотрите вводное занятие, где Андрей Всеволодович более развернуто отвечает на вопросы о курсе →')
                                            ->rows(2),

                                        TextInput::make('button_text')
                                            ->label('Текст кнопки')
                                            ->default('СМОТРЕТЬ'),

                                        TextInput::make('button_url')
                                            ->label('Ссылка кнопки')
                                            ->default('#'),

                                        Section::make('🎨 Настройки дизайна')
                                            ->collapsed()
                                            ->schema([
                                                ColorPicker::make('bg_color')
                                                    ->label('Основной цвет фона')
                                                    ->default('#4b9b74'), // Зеленый цвет с вашего скриншота

                                                CuratorPicker::make('bg_image')
                                                    ->label('Фоновое изображение (Облака/Узоры)')
                                                    ->helperText('Изображение будет наложено поверх цвета фона с полупрозрачностью')
                                                    ->buttonLabel('Выбрать фон'),

                                                ColorPicker::make('text_color')
                                                    ->label('Цвет текста')
                                                    ->default('#ffffff'),

                                                ColorPicker::make('button_bg_color')
                                                    ->label('Цвет фона кнопки')
                                                    ->default('#ffffff'),

                                                ColorPicker::make('button_text_color')
                                                    ->label('Цвет текста кнопки')
                                                    ->default('#1E4633'), // Темно-зеленый текст на кнопке
                                            ])->columns(2),
                                    ]),

                                // ==============================================================
                                // 14.1. КУРС ЗАВЕРШЁН — запись в продаже (кнопка → карточка курса)
                                // ==============================================================
                                Block::make('course_finished_block')
                                    ->label('14.1. Курс завершён → запись в продаже')
                                    ->icon('heroicon-o-archive-box')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('Курс завершён'),

                                        Textarea::make('subtitle')
                                            ->label('Подзаголовок')
                                            ->rows(3)
                                            ->default('Повторный набор не планируется. Все лекции доступны в записи — учитесь в своём темпе с пожизненным доступом.'),

                                        Repeater::make('badges')
                                            ->label('Плашки-факты (необязательно)')
                                            ->schema([
                                                TextInput::make('text')->label('Текст плашки')->required(),
                                            ])
                                            ->grid(3)
                                            ->maxItems(4)
                                            ->default([
                                                ['text' => 'Повтор не планируется'],
                                                ['text' => 'Доступно в записи'],
                                                ['text' => 'Пожизненный доступ'],
                                            ]),

                                        Select::make('course_id')
                                            ->label('Курс (карточка для покупки)')
                                            ->options(fn () => Course::query()
                                                ->where('is_visible', true)
                                                ->orderBy('title')
                                                ->pluck('title', 'id'))
                                            ->searchable()
                                            ->required()
                                            ->helperText('Клик по кнопке ведёт на карточку этого курса в витрине (/online/kursy/…), где можно купить запись.'),

                                        TextInput::make('button_text')
                                            ->label('Текст кнопки')
                                            ->default('Купить запись курса'),

                                        Section::make('🎨 Настройки дизайна')
                                            ->collapsed()
                                            ->schema([
                                                ColorPicker::make('bg_color')
                                                    ->label('Цвет фона')
                                                    ->default('#101010'),
                                                ColorPicker::make('text_color')
                                                    ->label('Цвет текста')
                                                    ->default('#ffffff'),
                                                ColorPicker::make('button_bg_color')
                                                    ->label('Цвет фона кнопки')
                                                    ->default('#E85C24'),
                                                ColorPicker::make('button_text_color')
                                                    ->label('Цвет текста кнопки')
                                                    ->default('#ffffff'),
                                            ])->columns(2),
                                    ]),

                                // 5.1. WEBINAR TOPICS (Темы, которые разбираются на вебинарах)
                                Block::make('webinar_topics_block')
                                    ->label('5.1. Темы вебинаров')
                                    ->icon('heroicon-m-presentation-chart-bar')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('Что разберём на вебинарах'),

                                        Textarea::make('subtitle')
                                            ->label('Подзаголовок (необязательно)')
                                            ->rows(2)
                                            ->placeholder('Темы, которые мы обсудим в прямом эфире'),

                                        Repeater::make('topics')
                                            ->label('Темы')
                                            ->schema([
                                                TextInput::make('title')
                                                    ->label('Тема')
                                                    ->required(),
                                                RichEditor::make('description')
                                                    ->label('Краткое описание (необязательно)')
                                                    ->toolbarButtons([
                                                        'bold', 'italic', 'link', 'bulletList', 'orderedList', 'undo', 'redo',
                                                    ]),
                                            ])
                                            ->grid(2)
                                            ->defaultItems(3)
                                            ->reorderableWithButtons()
                                            ->cloneable()
                                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),

                                        TextInput::make('button_text')
                                            ->label('Текст кнопки (необязательно)')
                                            ->placeholder('Записаться на вебинар')
                                            ->helperText('Если пусто — кнопка не показывается. Кнопка открывает плавающую форму заявки.'),
                                    ]),

                                // 5.2. WEBINAR TOPICS — светлый «editorial»-вариант (строки с номерами)
                                Block::make('webinar_topics_spotlight_block')
                                    ->label('5.2. Темы вебинаров (строки с номерами)')
                                    ->icon('heroicon-m-bolt')
                                    ->schema([
                                        TextInput::make('badge')
                                            ->label('Плашка-надзаголовок')
                                            ->default('В прямом эфире'),

                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('О чём будем говорить'),

                                        Textarea::make('subtitle')
                                            ->label('Подзаголовок (необязательно)')
                                            ->rows(2)
                                            ->placeholder('Каждая тема — отдельный разбор с ответами на ваши вопросы'),

                                        Repeater::make('topics')
                                            ->label('Темы')
                                            ->schema([
                                                TextInput::make('title')
                                                    ->label('Тема')
                                                    ->required(),
                                                RichEditor::make('description')
                                                    ->label('Краткое описание (необязательно)')
                                                    ->toolbarButtons([
                                                        'bold', 'italic', 'link', 'bulletList', 'orderedList', 'undo', 'redo',
                                                    ]),
                                            ])
                                            ->defaultItems(3)
                                            ->reorderableWithButtons()
                                            ->cloneable()
                                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),

                                        TextInput::make('button_text')
                                            ->label('Текст кнопки (необязательно)')
                                            ->placeholder('Занять место на вебинаре')
                                            ->helperText('Если пусто — кнопка не показывается. Кнопка открывает плавающую форму заявки.'),
                                    ]),

                                // 5. PROGRAM
                                Block::make('program_block')
                                    ->label('5. Программа курса')
                                    ->icon('heroicon-m-academic-cap')
                                    ->schema([
                                        TextInput::make('title')->label('Заголовок')->default('Программа курса'),
                                        Repeater::make('modules')
                                            ->label('Модули')
                                            ->schema([
                                                TextInput::make('module_title')->label('Название модуля')->required(),
                                                RichEditor::make('module_content')->label('Содержание модуля'),
                                            ])->collapsible(),
                                    ]),

                                // 6. FORMAT
                                Block::make('format_block')
                                    ->label('6. Формат обучения')
                                    ->icon('heroicon-m-clock')
                                    ->schema([
                                        TextInput::make('title')->label('Заголовок')->default('Как проходит обучение'),
                                        Repeater::make('items')
                                            ->label('Параметры')
                                            ->schema([
                                                TextInput::make('label')->label('Название'),
                                                TextInput::make('value')->label('Значение'),
                                            ])->grid(3),
                                    ]),

                                // 7. PRICE (Тарифы + Дефицит)
                                Block::make('price_block')
                                    ->label('7. Стоимость (Тарифы)')
                                    ->icon('heroicon-m-currency-dollar')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок секции')
                                            ->default('Выберите формат участия'),

                                        TextInput::make('subtitle')
                                            ->label('Подзаголовок')
                                            ->default('Доступна рассрочка'),

                                        // === СЮДА ПЕРЕНЕСЛИ НАСТРОЙКИ ДЕФИЦИТА ===
                                        Section::make('Настройки дефицита (Дедлайн и Места)')
                                            ->description('Только реальные данные: что не заполнено — то не показывается. Выдуманных значений по умолчанию больше нет.')
                                            ->schema([
                                                DateTimePicker::make('timer_end')
                                                    ->label('Текущая цена действует до (дата и время)')
                                                    ->helperText('Указывайте только реальную дату изменения цены. Не заполнено или дата прошла — блок дедлайна не показывается.'),

                                                Grid::make(2)->schema([
                                                    TextInput::make('seats_taken')
                                                        ->label('Занято мест')
                                                        ->numeric(),
                                                    TextInput::make('seats_total')
                                                        ->label('Всего мест')
                                                        ->numeric(),
                                                ]),
                                            ])->collapsible(),
                                        // ==========================================

                                        Repeater::make('tariffs')
                                            ->label('Карточки тарифов')
                                            ->schema([
                                                TextInput::make('name')->label('Название')->required(),
                                                TextInput::make('price')->label('Цена')->required(),
                                                TextInput::make('old_price')->label('Старая цена'),
                                                RichEditor::make('features')->label('Список опций')->toolbarButtons(['bulletList', 'bold']),
                                                Toggle::make('is_popular')->label('Хит продаж')->default(false),
                                                TextInput::make('button_text')->label('Текст кнопки')->default('Записаться на курс'),
                                            ])
                                            ->grid(3)
                                            ->defaultItems(3),
                                    ]),

                                // 7.1. ВЫБОР ПОТОКА (одна программа, разные дни/время → оплата тарифа со скидкой)
                                Block::make('course_streams_block')
                                    ->label('7.1. Выбор потока (разные дни/время → оплата)')
                                    ->icon('heroicon-m-calendar-days')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок секции')
                                            ->default('Выберите удобный поток'),

                                        TextInput::make('subtitle')
                                            ->label('Подзаголовок'),

                                        // Дедлайн + счётчик мест (как в блоке тарифов)
                                        Section::make('Настройки дефицита (Дедлайн и Места)')
                                            ->description('Только реальные данные: что не заполнено — то не показывается. Выдуманных значений по умолчанию больше нет.')
                                            ->schema([
                                                DateTimePicker::make('timer_end')
                                                    ->label('Текущая цена действует до (дата и время)')
                                                    ->helperText('Указывайте только реальную дату изменения цены. Не заполнено или дата прошла — блок дедлайна не показывается.'),

                                                Grid::make(2)->schema([
                                                    TextInput::make('seats_taken')
                                                        ->label('Занято мест')
                                                        ->numeric(),
                                                    TextInput::make('seats_total')
                                                        ->label('Всего мест')
                                                        ->numeric(),
                                                ]),
                                            ])->collapsible(),

                                        Repeater::make('streams')
                                            ->label('Потоки')
                                            ->schema([
                                                TextInput::make('name')
                                                    ->label('Название потока')
                                                    ->placeholder('Поток 1')
                                                    ->required(),
                                                TextInput::make('schedule')
                                                    ->label('Дни и время занятий')
                                                    ->placeholder('Пн/Ср 19:00')
                                                    ->required(),
                                                TextInput::make('start_note')
                                                    ->label('Подпись (необязательно)')
                                                    ->placeholder('Старт 1 сентября'),
                                                Select::make('tariff_id')
                                                    ->label('Тариф для оплаты')
                                                    ->options(fn () => Tariff::query()
                                                        ->with('course')
                                                        ->where('is_active', true)
                                                        ->get()
                                                        ->mapWithKeys(fn (Tariff $t) => [
                                                            $t->id => trim(($t->course?->title ? $t->course->title.' — ' : '').$t->title)
                                                                .' ('.number_format((float) $t->price, 0, '', ' ').' ₽)',
                                                        ]))
                                                    ->searchable()
                                                    ->required()
                                                    ->helperText('Клик по потоку ведёт на оплату этого тарифа.'),
                                                Select::make('promo_code')
                                                    ->label('Промокод (скидка)')
                                                    ->options(fn () => PromoCode::orderBy('code')->pluck('code', 'code'))
                                                    ->searchable()
                                                    ->helperText('Необязательно. Применится автоматически на странице оплаты.'),
                                                TextInput::make('price')->label('Цена (для показа)'),
                                                TextInput::make('old_price')->label('Старая цена (зачёркнутая)'),
                                                RichEditor::make('features')
                                                    ->label('Список опций')
                                                    ->toolbarButtons(['bulletList', 'bold']),
                                                Toggle::make('is_popular')->label('Выделить')->default(false),
                                                TextInput::make('button_text')
                                                    ->label('Текст кнопки')
                                                    ->default('Выбрать поток'),
                                            ])
                                            ->grid(3)
                                            ->defaultItems(2),
                                    ]),

                                // 8. REVIEWS (Отзывы + Скриншоты)
                                Block::make('reviews_block')
                                    ->label('8. Отзывы (Слайдер + Скриншоты)')
                                    ->icon('heroicon-m-chat-bubble-left-right')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('Отзывы наших учеников'),

                                        Repeater::make('reviews')
                                            ->label('Список отзывов')
                                            ->schema([
                                                // 1. Блок с изображениями
                                                Grid::make(2)->schema([
                                                    FileUpload::make('avatar')
                                                        ->label('Фото ученика')
                                                        ->image()
                                                        ->avatar()
                                                        ->directory('promo'),

                                                    CuratorPicker::make('images')
                                                        ->label('Скриншоты (переписка/результат)')
                                                        ->multiple() // Curator сам поймет, что нужно выбрать несколько!
                                                        ->buttonLabel('Добавить скриншоты'),

                                                    TextInput::make('youtube_link')
                                                        ->label('Видео на YouTube')
                                                        ->url()
                                                        ->hint('Обложка подтянется автоматически.'),

                                                    TextInput::make('rutube_link')
                                                        ->label('Видео на RuTube')
                                                        ->url()
                                                        ->hint('Поддерживаются и shorts (rutube.ru/shorts/…).'),

                                                    TextInput::make('vk_link')
                                                        ->label('Видео ВКонтакте')
                                                        ->url()
                                                        ->hint('Поддерживаются и клипы (vk.com/clip…). Заполните несколько платформ — при клике зритель выберет, где смотреть (выбор запомнится).'),

                                                    TextInput::make('video_link')
                                                        ->label('Видео — другая ссылка (Vimeo, запасное)')
                                                        ->url()
                                                        ->hint('Используется, если не заполнены поля выше.'),
                                                ]),

                                                // 2. Блок с данными
                                                Grid::make(2)->schema([
                                                    TextInput::make('name')
                                                        ->label('Имя')
                                                        ->required(),

                                                    TextInput::make('date')
                                                        ->label('Дата')
                                                        ->placeholder('20.09.2024'),
                                                ]),

                                                // 3. НОВОЕ ПОЛЕ: Ссылка на контакт
                                                TextInput::make('contact_link')
                                                    ->label('Ссылка на связь (VK/TG)')
                                                    ->placeholder('https://vk.com/username')
                                                    ->url() // Проверка, что введена именно ссылка
                                                    ->prefixIcon('heroicon-m-link'),

                                                Textarea::make('quote')
                                                    ->label('Крупная цитата (показывается над текстом)')
                                                    ->placeholder('Самая яркая фраза из отзыва в 1–2 строки')
                                                    ->hint('Короткий акцент, который реально прочитают. Текст ниже — для тех, кто захочет подробностей.')
                                                    ->rows(2)
                                                    ->columnSpanFull(),

                                                Textarea::make('text')
                                                    ->label('Текст отзыва')
                                                    ->rows(3)
                                                    ->required()
                                                    ->columnSpanFull(), // Растянуть на всю ширину
                                            ])
                                            ->grid(2)
                                            ->defaultItems(3),
                                    ]),

                                // 8b. ИСТОРИИ УЧЕНИКОВ (нарративный пруф + анти-кейс)
                                Block::make('student_story_block')
                                    ->label('8б. Истории учеников (путь + анти-кейс)')
                                    ->icon('heroicon-m-user-group')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('Истории учеников'),

                                        TextInput::make('subtitle')
                                            ->label('Подзаголовок (необязательно)')
                                            ->placeholder('Как ученики приходили с нуля и куда добрались'),

                                        Repeater::make('stories')
                                            ->label('Истории (путь ученика: крючок → путь → результат)')
                                            ->schema([
                                                Grid::make(2)->schema([
                                                    TextInput::make('date')
                                                        ->label('Дата / год')
                                                        ->placeholder('2026'),

                                                    TextInput::make('track')
                                                        ->label('Трек')
                                                        ->placeholder('с нуля / для йоги / грамматика'),

                                                    FileUpload::make('avatar')
                                                        ->label('Фото ученика (с согласия)')
                                                        ->image()
                                                        ->avatar()
                                                        ->directory('promo'),

                                                    TextInput::make('name')
                                                        ->label('Имя')
                                                        ->required(),

                                                    TextInput::make('city')
                                                        ->label('Город (необязательно)')
                                                        ->placeholder('Казань'),
                                                ]),

                                                Textarea::make('hook')
                                                    ->label('Крючок-проблема')
                                                    ->placeholder('пришла читать мантры, боялась, что поздно в 45')
                                                    ->hint('Боль, в которой читатель узнаёт себя.')
                                                    ->rows(2)
                                                    ->columnSpanFull(),

                                                Textarea::make('path')
                                                    ->label('Путь: что было трудно и как помог куратор')
                                                    ->rows(3)
                                                    ->columnSpanFull(),

                                                Textarea::make('result')
                                                    ->label('Результат-цифра (конкретика, не «улучшили»)')
                                                    ->placeholder('за 8 блоков читает Гиту в оригинале / сдал внутренний зачёт')
                                                    ->rows(2)
                                                    ->columnSpanFull(),

                                                Textarea::make('not_for')
                                                    ->label('Честность: кому курс НЕ зайдёт (анти-кейс)')
                                                    ->hint('Отсекает не-ЦА и поднимает доверие — их сильнейший приём.')
                                                    ->rows(2)
                                                    ->columnSpanFull(),
                                            ])
                                            ->grid(1)
                                            ->defaultItems(2),
                                    ]),

                                // 8b. СТАТУС НАБОРА (живые данные группы, H3327)
                                Block::make('status_block')
                                    ->label('Статус набора группы (живой)')
                                    ->icon('heroicon-m-signal')
                                    ->schema([
                                        TextInput::make('group_id')
                                            ->label('ID группы (точная привязка)')
                                            ->numeric()
                                            ->helperText('Явная привязка к группе. Если пусто — берётся набирающаяся группа семьи ниже с заданным порогом min_size и датой старта; оболочки без порога/даты игнорируются.'),
                                        TextInput::make('course_family')
                                            ->label('Семья потоков (courses.course_family)')
                                            ->placeholder('kasmirskii-sivaizm')
                                            ->helperText('Фолбэк-поиск по семье, когда ID группы не указан. Пока подходящей группы нет — блок молчит.'),
                                    ]),

                                // 9. FORM (С ДЕФИЦИТОМ)
                                Block::make('form_block')
                                    ->label('9. Форма заявки (CTA + Дефицит)')
                                    ->icon('heroicon-m-envelope')
                                    ->schema([
                                        Section::make('Настройки формы')
                                            ->schema([
                                                TextInput::make('title')
                                                    ->label('Заголовок формы')
                                                    ->default('Записаться на курс'),
                                                TextInput::make('subtitle')
                                                    ->label('Подзаголовок формы')
                                                    ->default('Оставьте заявку, и мы свяжемся с вами в Telegram.'),
                                                RichEditor::make('description')
                                                    ->label('Текст слева от формы (Описание)'),
                                                TextInput::make('button_text')
                                                    ->label('Текст кнопки')
                                                    ->default('Записаться'),
                                            ]),

                                    ]),
                                Block::make('team_block')
                                    ->label('9. Команда (Плитка преподавателей)')
                                    ->icon('heroicon-m-users') // Иконка группы людей
                                    ->schema([
                                        // Общие настройки блока
                                        TextInput::make('title')
                                            ->label('Заголовок блока')
                                            ->default('Преподаватели курса'),

                                        TextInput::make('subtitle')
                                            ->label('Подзаголовок (необязательно)')
                                            ->placeholder('Опытные наставники, которые приведут вас к результату'),

                                        // Список преподавателей
                                        Repeater::make('items')
                                            ->label('Список преподавателей')
                                            ->schema([
                                                CuratorPicker::make('image')
                                                    ->label('Фото')
                                                    ->required()
                                                    ->buttonLabel('Выбрать фото'),

                                                TextInput::make('name')
                                                    ->label('Имя Фамилия')
                                                    ->required()
                                                    ->placeholder('Иван Иванов'),

                                                TextInput::make('role')
                                                    ->label('Роль / Специализация')
                                                    ->placeholder('Эксперт по грамматике'),

                                                RichEditor::make('description')
                                                    ->label('Краткое описание')
                                                    ->placeholder('10 лет опыта, автор 5 книг...'),
                                            ])
                                            ->grid(4) // В админке показывать по 4 в ряд
                                            ->defaultItems(4),
                                    ]),
                                // 10. INSTRUCTOR (Преподаватель - НОВЫЙ БЛОК)
                                Block::make('instructor_block')
                                    ->label('10. Блок "О преподавателе"')
                                    ->icon('heroicon-m-user')
                                    ->schema([
                                        Section::make('Контент')
                                            ->schema([
                                                Grid::make(3)->schema([
                                                    CuratorPicker::make('image')
                                                        ->label('Фото преподавателя')
                                                        ->size('xs')
                                                        ->buttonLabel('Выбрать фото')
                                                        ->columnSpan(1),

                                                    Grid::make(1)->schema([
                                                        TextInput::make('name')
                                                            ->label('Имя Фамилия')
                                                            ->required(),

                                                        TextInput::make('role')
                                                            ->label('Должность / Статус')
                                                            ->default('Автор курса'),
                                                    ])->columnSpan(2),
                                                ]),

                                                RichEditor::make('bio')
                                                    ->label('Биография / Описание')
                                                    ->toolbarButtons(['bold', 'bulletList', 'italic']),
                                            ]),

                                        Repeater::make('stats')
                                            ->label('Факты в цифрах (под именем)')
                                            ->schema([
                                                TextInput::make('value')->label('Цифра')->placeholder('10 лет'),
                                                TextInput::make('label')->label('Подпись')->placeholder('Опыта'),
                                            ])
                                            ->grid(3)
                                            ->defaultItems(3),

                                        // === НАЧАЛО: ПУБЛИКАЦИИ ===
                                        Repeater::make('publications')
                                            ->label('Публикации и книги')
                                            ->schema([
                                                Grid::make(3)->schema([
                                                    CuratorPicker::make('image')
                                                        ->label('Обложка (миниатюра)')
                                                        ->size('xs')
                                                        ->buttonLabel('Выбрать обложку')
                                                        ->columnSpan(1),

                                                    Grid::make(1)->schema([
                                                        TextInput::make('title')
                                                            ->label('Название книги/статьи')
                                                            ->required()
                                                            ->maxLength(255),

                                                        TextInput::make('type')
                                                            ->label('Тип (например: Монография, Статья)')
                                                            ->maxLength(50),

                                                        TextInput::make('url')
                                                            ->label('Ссылка (если есть)')
                                                            ->url()
                                                            ->maxLength(255),
                                                    ])->columnSpan(2),
                                                ]),
                                            ])
                                            ->collapsed()
                                            ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
                                        // === КОНЕЦ: ПУБЛИКАЦИИ ===
                                    ]),

                                // 16. TRIAL BLOCK (Пробное занятие)
                                Block::make('trial_block')
                                    ->label('16. Блок "Сомневаетесь?" (Пробный урок)')
                                    ->icon('heroicon-m-hand-raised')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок')
                                            ->default('Сомневаетесь, что у вас получится?'),

                                        Textarea::make('description')
                                            ->label('Текст описания')
                                            ->rows(4)
                                            ->default('Мы понимаем. Начинать новое всегда волнительно. Поэтому приглашаем вас на бесплатное пробное занятие — без обязательств и оплаты. Вы познакомитесь с преподавателем, попробуете свои силы и поймёте, насколько это комфортно именно для вас. Если не зайдет — останетесь при своём, ничего не потеряв.'),

                                        TextInput::make('button_text')
                                            ->label('Текст кнопки')
                                            ->default('Да, хочу попробовать'),

                                        // --- НОВЫЕ ПОЛЯ ДЛЯ НАСТРОЙКИ МОДАЛКИ ---
                                        Fieldset::make('Настройки всплывающей формы')
                                            ->schema([
                                                TextInput::make('modal_title')
                                                    ->label('Заголовок формы')
                                                    ->default('Запись на пробный урок')
                                                    ->columnSpanFull(),

                                                TextInput::make('modal_text')
                                                    ->label('Текст-подсказка под заголовком')
                                                    ->default('Оставьте контакты, и мы согласуем удобное время.')
                                                    ->columnSpanFull(),

                                                TextInput::make('form_name')
                                                    ->label('Скрытая метка формы (увидите в Excel/CRM)')
                                                    ->default('Бесплатное пробное занятие')
                                                    ->required()
                                                    ->columnSpanFull(),
                                            ]),

                                        Section::make('🎨 Дизайн')
                                            ->collapsed()
                                            ->schema([
                                                ColorPicker::make('bg_color')->label('Цвет фона')->default('#FFFFFF'),
                                                ColorPicker::make('text_color')->label('Цвет текста')->default('#101010'),
                                                ColorPicker::make('button_bg')->label('Цвет кнопки')->default('#E85C24'),
                                            ])->columns(3),
                                    ]),

                                // 14. FAQ (Частые вопросы)
                                Block::make('faq_block')
                                    ->label('14. FAQ (Частые вопросы)')
                                    ->icon('heroicon-m-question-mark-circle')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок блока')
                                            ->default('Частые вопросы')
                                            ->required(),

                                        Textarea::make('subtitle')
                                            ->label('Подзаголовок (опционально)')
                                            ->placeholder('Собрали ответы на самые популярные вопросы о курсе')
                                            ->rows(2),

                                        Repeater::make('items')
                                            ->label('Вопросы и ответы')
                                            ->schema([
                                                TextInput::make('question')
                                                    ->label('Вопрос')
                                                    ->required()
                                                    ->maxLength(255),

                                                RichEditor::make('answer')
                                                    ->label('Ответ')
                                                    ->required()
                                                    ->toolbarButtons([
                                                        'bold', 'italic', 'link', 'bulletList', 'orderedList', 'undo', 'redo',
                                                    ]),
                                            ])
                                            ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                                            ->collapsible()
                                            ->collapsed()
                                            ->reorderableWithButtons()
                                            ->cloneable()
                                            ->minItems(1)
                                            ->defaultItems(3),
                                    ]),

                                // 17. ЖИЗНЕННЫЕ ПРАВИЛА (манифест школы, H1224)
                                Block::make('life_rules_block')
                                    ->label('17. Жизненные правила для санскритологов (манифест)')
                                    ->icon('heroicon-m-book-open')
                                    ->schema([
                                        TextInput::make('title')
                                            ->label('Заголовок блока')
                                            ->default('Жизненные правила для санскритологов')
                                            ->required(),

                                        Textarea::make('epigraph')
                                            ->label('Эпиграф (курсивом, под заголовком)')
                                            ->default('По образцу «Жизненных правил для музыкантов» Роберта Шумана (1848).')
                                            ->rows(2),

                                        TextInput::make('collapsed_count')
                                            ->label('Сколько правил показывать до кнопки «Показать все»')
                                            ->numeric()
                                            ->default(7),

                                        TextInput::make('byline')
                                            ->label('Подпись под манифестом')
                                            ->default('Dr. Mārcis Gasūns'),

                                        Repeater::make('items')
                                            ->label('Правила (по одному на строку, показываются по порядку)')
                                            ->schema([
                                                Textarea::make('text')
                                                    ->label('Текст правила')
                                                    ->required()
                                                    ->rows(3),
                                            ])
                                            ->itemLabel(fn (array $state): ?string => Str::limit($state['text'] ?? '', 60))
                                            ->collapsible()
                                            ->collapsed()
                                            ->reorderableWithButtons()
                                            ->cloneable()
                                            ->minItems(1)
                                            ->default([
                                                ['text' => 'У санскрита два тела: звучащее и записанное. Своего алфавита у него нет — он веками ночевал в чужих письменностях, а Индия три тысячи лет передавала его из уст в уста, и устная передача оказалась точнее письменной: переписчик ошибается чаще, чем хор повторяющих. Тебе понадобятся оба тела — но помни, которое из них старше.'],
                                                ['text' => 'Начинай со звука: алфавит сперва наслушивают, как песню, и лишь потом разбирают глазами. Уши, говорят на распевах, должны работать как локаторы. Что в тебе не звучит, то не выучено, а только записано.'],
                                                ['text' => 'Читай вслух всё, что читаешь, — хотя бы страницу в день, не стесняясь ни соседей, ни собственного произношения. Полстраницы, произнесенные губами, стоят пяти, пробеганных глазами; чтение вслух — тот ключ к успеху, которым пренебрегают чаще всего.'],
                                                ['text' => 'Учи наизусть. Немного — строфу, полстрофы, — но твердо: при встрече с пандитом, который сыплет строфами, неприкосновенный запас тебя выручит. Это библиотека, которую нельзя отнять, но можно потерять самому: что год не повторял, то рассыпалось. Устраивай переучет.'],
                                                ['text' => 'Сандхи не зубрят — сандхи начитывают. Читай, пока стечения звуков не начнут исправляться сами; таблицей проверяй ухо, но не подменяй его.'],
                                                ['text' => 'Разбирай санскритское слово с конца: главное оно говорит последним. Сначала сними сандхи и восстанови словоформу, потом открывай таблицу — кто начинает с догадки о смысле, тот подбирает падежи под буквы. Перевод длинного сложного слова тоже начинается справа.'],
                                                ['text' => 'Двадцать минут ежедневно сильнее пяти часов в воскресенье: быстро — это медленно, но регулярно. Язык растет от частоты встреч, а не от их героической длины; и начинай домашнюю работу не в пятницу — начатая в четверг, она вдвое короче.'],
                                                ['text' => 'Не спрашивай, есть ли у тебя способности. Из семидесяти начавших через год остаются четверо, и это не самые одаренные, а самые усидчивые: продвижение здесь измеряется часами, просиженными ровно. Способности подтянутся.'],
                                                ['text' => 'Пропустив месяц, не стыдись вернуться. Бросают не те, кто пропустил, а те, кто постеснялся прийти после пропуска; вернувшийся через год — все еще ученик, исчезнувший молча — уже нет.'],
                                                ['text' => 'Возвращайся к прочитанному. Старый текст, перечитанный через год, — новый текст: он не изменился, изменился ты. У рабочего учебника от перелистываний сереет обрез; когда посереет — книга вошла в тебя. Это самый дешевый способ измерить свой рост.'],
                                                ['text' => 'Пиши деванагари от руки и не спеши к экрану: когда мы пишем рукой, не мы подражаем шрифту — это печатный шрифт подражает нам. Рука медленнее клавиатуры, в этом ее педагогическое преимущество: она успевает запомнить то, мимо чего печатающий палец пролетает. И слово, над которым ты не попотел, листая бумажный словарь, забывается первым.'],
                                                ['text' => 'Таблица склонения — не язык, а его фотография. Но чужая фотография и своя — разные вещи: таблицу, составленную своей рукой под свои примеры, повесь на стену — запомнится сама, без зубрежки. Печатной же верь с оглядкой: опечатки бывают и в таблицах, проверяй фотографию по живому.'],
                                                ['text' => 'Словарь — не оракул, а собеседник со своей биографией. За Моньер-Вильямсом стоит Бетлингк, за Бетлингком — индийская традиция составителей кош. Спрашивая словарь, знай, кого именно ты спрашиваешь; а иногда читай его просто так, без надобности, как читают стихи.'],
                                                ['text' => 'Читай предисловия словарей и грамматик: там авторы сами говорят, чего их книги не умеют. Это самые полезные и самые нечитаные страницы нашей науки. С переводами так же: знакомство с книгой правильнее всего начинать со вступительной статьи переводчика.'],
                                                ['text' => 'Одного словаря мало всегда: всего, что тебе встретится, нет ни в одном из них. Два словаря, разошедшиеся в значении, — не досадная помеха, а готовая тема исследования: лексикографы списывают друг у друга, и чехарда их разночтений — старейший жанр нашей науки.'],
                                                ['text' => 'Начинай с малого словаря, но знай дорогу к большим. Кнауэр мал, да вымерен до миллиметра; Кочергина — прихожая, прожиточный минимум; Моньер-Вильямс — дом, на девять десятых перестроенный из бетлингковского кирпича; Бетлингк — целый город, до сих пор никем не превзойденный. Живи в доме, гуляй по городу.'],
                                                ['text' => 'Не ищи единственно правильный учебник — его нет. Нормальные языки учат по одной книге; санскрит учат по вороху: грамматика, хрестоматия, два словаря и справочник лежат на столе одновременно, и это не беспорядок, а рабочее состояние ненормального языка.'],
                                                ['text' => 'Не начинай со стихов, даже если пришел ради них. Стих — язык, изогнутый метром и вольным порядком слов; выпрямлять его учатся на прозе, на простых рассказах, которыми брезгуют торопливые. Кто начинает с любимой поэмы, на ней обычно и заканчивает.'],
                                                ['text' => 'Двуязычное чтение — протез. Ходи с ним, пока хромаешь, и не стыдись: подстрочник — честное пособие. Жульничество начинается там, где перевод подменяет разбор: гадать по чужому переводу о своем тексте — значит не читать, а делать вид. Время от времени оставляй перевод закрытым — так узнаешь, что уже умеешь ходить сам.'],
                                                ['text' => 'Чужой перевод — свидетель, а не судья. Выслушай его показания и сверь с текстом: переводчики ошибаются реже, чем кажется новичку, и чаще, чем кажется публике. Пинать переводчика — любого — дешевое удовольствие; лучше учись у верности ремеслу: Смирнов перевел Гиту двадцать три раза, а услышав впервые живую рецитацию, сел переводить в двадцать четвертый.'],
                                                ['text' => 'У санскрита два ряда учителей: Панини с его комментаторами — и Бетлингк, Уитни, Кильхорн. Первые видели язык изнутри, вторые — в сравнении с другими языками. Ряды ссорились всерьез: Рот ни во что не ставил туземных толкователей, а Щербатской после четырех лет европейской выучки сел в Индии переучиваться у пандитов заново. Слушай оба ряда и не верь тому, кто требует выбрать раз и навсегда: до сих пор есть вопросы, на которые не ответил ни один. И не начинай с Панини — вершина не вход.'],
                                                ['text' => 'Программа-анализатор — суфлер, а не учитель. В хорошей школе сильнейший словарный сайт показывают не сразу: сперва год ищи руками, потом получай инструмент — иначе инструмент получит тебя. Подсказки суфлера принимай, но экзамен сдаешь ты, и спрашивать будут с тебя.'],
                                                ['text' => 'Машины переводят санскрит всё лучше; пользуйся ими смело и проверяй строго, как первокурсника. Нынешние сети складно говорят о смысле и спотыкаются на морфологии — то есть врут именно там, где новичку труднее всего их поймать. Уверенный тон и у машин не признак знания.'],
                                                ['text' => 'Корпус отвечает за секунды — но лишь на те вопросы, которые ты догадался задать. Медленное чтение рождает вопросы, которых у тебя не было; не меняй второе на первое. И помни: первый корпус нашей науки — шестьсот пятьдесят тысяч выписок Бетлингка — собран рукой, и рука эта знала тексты лучше, чем мы со всеми нашими поисковиками.'],
                                                ['text' => 'Красивая гипотеза еще не истина. «Красиво» — это чувство; проверка начинается там, где кончается восхищение. Сочинять не запрещено — запрещено выдавать сочиненное за проверенное; и гипотезу «все лебеди белые» отменяет один черный лебедь.'],
                                                ['text' => 'Внешнее сходство слов — самый сладкий и самый дешевый из соблазнов. Родство доказывается регулярными соответствиями звуков, а не похожестью; закон скучнее чуда — тем и надежен. Больше того: два слова, похожие как близнецы, почти наверняка не родня — за тысячи лет звуки расходятся, и настоящее родство обычно выглядит непохоже.'],
                                                ['text' => 'Санскрит и русский действительно родственны. Будь с этим фактом вдвое строже: правда, которая нравится публике, притягивает фантазеров.'],
                                                ['text' => '«Все языки произошли из санскрита» — по этой фразе любитель опознается, как певец по фальшивой ноте. Забавно, что рядом стоит правда: из санскрита действительно вышло многое — только не языки, а наука о языке: встреча Европы с Панини родила сравнительное языкознание. Не путай родословную языков с родословной науки. И не спорь с любителем свысока: покажи, как выглядит доказательство, — а второй час спора отдай текстам.'],
                                                ['text' => 'Не бойся говорить «не знаю». Зализняк повторял: главное — понять, что именно ты не понимаешь. Из честного «не знаю» вырастают статьи, из уверенного вида — только репутация, и ненадолго. Рот сказал о темных местах Ригведы: темными были, темными останутся, — и наука от этого не рухнула.'],
                                                ['text' => 'Цитируй по изданию, которое держал в руках, а не по пересказу: пересказ — тоже перевод, только анонимный. Даже древние комментаторы цитировали по памяти и подменяли слово-другое — а ты потом год ищи, откуда строка. Не умножай этот жанр.'],
                                                ['text' => 'Помни о времени. «В санскрите так» — пустые слова, пока не сказано, где и когда: от гимнов Ригведы до Калидасы дальше, чем от «Слова о полку Игореве» до сегодняшней газеты.'],
                                                ['text' => 'Различай текст и издание текста. Хорошее критическое издание показывает свои швы — разночтения, сомнения, выбор издателя; дорожи книгами, которые не скрывают швов, и людьми тоже. Но не молись и на критический текст: склейка сорока рукописей — инструмент исследователя, а читать поэму как поэму бывает честнее по вульгате, тексту, которым жила традиция.'],
                                                ['text' => 'Ошибка, найденная у тебя другим, — подарок; поблагодари и исправь печатно. Говорят, ошибки ученика — дождь на засеянном поле. Зализняк, получив от ученика список опечаток к своей книге, обрадовался и исправил; учись радоваться — наука отличается от самолюбия тем, что умеет праздновать опровержение.'],
                                                ['text' => 'Истина существует, и ее не выбирают голосованием — ни большинства, ни авторитета, ни собственного вкуса. Старая буддийская школа учила: слова Будды истинны не потому, что это слова Будды, а потому, что каждый может их проверить. Наука занята поиском истины; всем остальным заняты мнения о науке.'],
                                                ['text' => 'Профессионал ошибается, и нередко. Но он знает, как выглядит доказательство, — и потому его можно поправить: серьезный лектор принимает студенческую поправку словом «спасибо». Любителя поправить нельзя: у него вместо доказательств убежденность. Стань тем, кого можно поправить, — и не повторяй чужой ошибки лишь потому, что ею ходили до тебя: дорога прадеда не перестает вести на грабли.'],
                                                ['text' => 'Не презирай простых вопросов. «Почему здесь долгий гласный?» — из вопросов такого роста выросло сравнительное языкознание. И не жди времени, когда начнешь задавать только умные вопросы: оно не придет ни к кому. Спрашивай сейчас.'],
                                                ['text' => 'Объясняй выученное — школьнику, соседу, себе самому вслух. Что не объясняется просто, то понято наполовину; Зализняк разбирал свежие берестяные грамоты в зале, где школьники сидели рядом с академиками, и скучно не было никому. Санскрит объясним и семилетнему — проверено.'],
                                                ['text' => 'Бетлингк работал по рукописям, когда критических изданий еще не было; девять десятых семитомного словаря сделал один — и знал, что ошибается. Полтора века спустя вся наука живет его работой: словарь не превзойден, а его прочтения — вместе с промахами — кочуют из словаря в словарь по сей день. Постарайся ошибаться так.'],
                                                ['text' => 'Уважай старое и встречай новое без страха: корпус и алгоритм — такие же орудия, какими в свое время были картотека и конкорданс. Судят не орудие, а работу.'],
                                                ['text' => 'Не работай в одиночестве. Наука знает великих отшельников — Бетлингк дробил свой камень почти один, — но сначала проверь, Бетлингк ли ты: из тех, кто садится за санскрит сам, до конца доходят единицы за десятилетие. Показывай разборы, проси возражений, отвечай на чужие: наука — разговор, а речь, которой никто не отвечает, глохнет.'],
                                                ['text' => 'Издавай сделанное. Несовершенная работа, вышедшая в свет, полезнее совершенной, лежащей в столе: изданную хотя бы можно опровергнуть, а книг без опечаток не бывает — бывают списки поправок к спискам поправок. Одна оговорка: сделанное — не то же, что начатое; полуфабрикату дай дозреть.'],
                                                ['text' => 'Учитель нужен не для тайного знания — тайного знания нет. На рукописях посчитано: три года работы в одиночку дают примерно то же, что час со специалистом. Учитель знает, где ты заблудишься завтра, потому что сам там блуждал; он экономит тебе не истину, а годы.'],
                                                ['text' => 'Спи. Парадигма, не дававшаяся вечером, к утру нередко оказывается выученной: память работает и тогда, когда ты отдыхаешь. Есть и верная примета настоящей учебы: санскрит начинает сниться — но заметь, выученное приходит во сне только к тому, кто учил наяву. И вообще береги себя: мертвый ученик — плохой ученик.'],
                                                ['text' => 'Санскрит не сделает тебя мудрецом и не откроет тайн — он сделает нечто более редкое: научит читать медленно. Филология и есть медленное чтение — слова Ницше, филолога по ремеслу; Топоров назвал свой перевод древнеиндийской драмы приглашением к медленному чтению. В век быстрого чтения это умение почти вымерло; у санскритологов оно ремесло.'],
                                                ['text' => 'Правила Шумана кончаются словами: учению нет конца. Фраза проверена почти двумя веками; опровержений не поступало.'],
                                            ]),
                                    ]),

                                // 15. херо - НОВЫЙ БЛОК)
                                Block::make('new_paribok_hero')
                                    ->label('Главный экран (Спец. дизайн)')
                                    ->icon('heroicon-o-star')
                                    ->schema([
                                        // --- 1. КОНТЕНТ ---
                                        FileUpload::make('logo_image')
                                            ->label('Логотип (image-2.png)')
                                            ->directory('landing-blocks')
                                            ->image(),

                                        TextInput::make('super_title')
                                            ->label('Надзаголовок (Организатор)')
                                            ->default('Общество ревнителей санскрита'),

                                        Textarea::make('title')
                                            ->label('Главный заголовок')
                                            ->default("Разбор Йога-сутр\nПатанджали\nс А.В.Парибком")
                                            ->rows(3),

                                        TextInput::make('badge_top')
                                            ->label('Плашка 1 (Текст 1)')
                                            ->default('ОНЛАЙН-КУРС'),

                                        TextInput::make('badge_bottom')
                                            ->label('Плашка 1 (Текст 2)')
                                            ->default('В 2-х ЧАСТЯХ'),

                                        TextInput::make('orange_badge')
                                            ->label('Оранжевая плашка (Даты)')
                                            ->default('Вторая часть стартовала с 4 октября 2025'),

                                        Textarea::make('description')
                                            ->label('Описание (Из первых уст...)')
                                            ->default('Из первых уст от востоковеда с мировым именем и йога с 60-летним стажем!')
                                            ->rows(3),

                                        // --- 2. ДИЗАЙН И ФОНЫ ---
                                        Section::make('🎨 Фоны и Дизайн')
                                            ->description('Загрузите слои из Figma и настройте цвета')
                                            ->collapsed()
                                            ->schema([
                                                FileUpload::make('bg_image')
                                                    ->label('Задний фон (bg.png)')
                                                    ->directory('landing-blocks')
                                                    ->image(),

                                                FileUpload::make('clouds_image')
                                                    ->label('Слой облаков (clouds.png)')
                                                    ->directory('landing-blocks')
                                                    ->image(),

                                                FileUpload::make('speaker_image')
                                                    ->label('Фото спикера (image.png)')
                                                    ->directory('landing-blocks')
                                                    ->image(),

                                                ColorPicker::make('text_color')
                                                    ->label('Цвет основного текста')
                                                    ->default('#07191e'), // Точный цвет из вашей Figma

                                                ColorPicker::make('accent_color')
                                                    ->label('Цвет акцентов (оранжевый)')
                                                    ->default('#E85C24'),
                                            ])->columns(2),
                                    ]),

                            ])
                            ->collapsible()
                            ->cloneable(),
                    ]),

                // === 3. АНАЛИТИКА ===
                Section::make('Аналитика')
                    ->schema([
                        TextInput::make('yandex_metrika_id')->label('ID Яндекс.Метрики')->numeric(),
                        TextInput::make('vk_pixel_id')->label('ID Пикселя VK')->numeric(),
                    ]),

                // === 3.5 LEAD MAGNET (файл-подарок после заявки) ===
                Section::make('🎁 Lead Magnet')
                    ->description('Файл, который бот автоматически пришлёт пользователю после заявки. Канал выбирается из поля social лида либо берётся дефолтный.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Toggle::make('lead_magnet_enabled')
                            ->label('Включить lead-magnet для этого лендинга')
                            ->live(),
                        FileUpload::make('lead_magnet_file_path')
                            ->label('Файл (PDF / DOC / DOCX)')
                            ->directory('magnets')
                            ->disk('public')
                            ->acceptedFileTypes([
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ])
                            ->maxSize(10240)
                            ->visible(fn ($get) => (bool) $get('lead_magnet_enabled')),
                        TextInput::make('lead_magnet_title')
                            ->label('Название магнита')
                            ->placeholder('Алфавит деванагари')
                            ->maxLength(255)
                            ->visible(fn ($get) => (bool) $get('lead_magnet_enabled')),
                        Textarea::make('lead_magnet_caption')
                            ->label('Подпись к файлу в боте')
                            ->placeholder('Ваш бесплатный подарок — Алфавит деванагари. Приятного изучения!')
                            ->rows(3)
                            ->visible(fn ($get) => (bool) $get('lead_magnet_enabled')),
                        Select::make('lead_magnet_default_channel')
                            ->label('Дефолтный канал (если social не указан)')
                            ->options([
                                'telegram' => 'Telegram',
                                'vk' => 'ВКонтакте',
                                'max' => 'Max',
                            ])
                            ->default('telegram')
                            ->visible(fn ($get) => (bool) $get('lead_magnet_enabled')),
                    ]),

                // === 3.6 БОТ ЛИД-МАГНИТА (свой бот на лендинг → n8n) ===
                Section::make('🤖 Бот лид-магнита (Telegram → n8n)')
                    ->description('Отдельный Telegram-бот этого лендинга. Laravel отдаёт магнит и трекает выдачу, остальные апдейты форвардит в n8n (анкета + прогрев). Заполните токен — бот сохранится.')
                    ->relationship('bot', condition: fn (?array $state): bool => filled($state['tg_bot_token'] ?? null) || filled($state['vk_n8n_forward_url'] ?? null))
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Бот активен')
                            ->default(true),
                        TextInput::make('tg_bot_username')
                            ->label('Username бота (без @)')
                            ->placeholder('my_landing_bot')
                            ->maxLength(100),
                        TextInput::make('tg_bot_token')
                            ->label('Bot Token')
                            ->password()
                            ->revealable()
                            ->placeholder('Получить у @BotFather')
                            ->maxLength(255),
                        TextInput::make('tg_webhook_secret')
                            ->label('Webhook Secret (случайная строка 32+ символов)')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('Та же строка ставится в Telegram setWebhook. После заполнения запусти на проде: php artisan telegram:set-magnet-webhook <slug>'),
                        TextInput::make('n8n_forward_url')
                            ->label('URL ноды Webhook в n8n')
                            ->url()
                            ->maxLength(500)
                            ->helperText('Сюда форвардятся апдейты для анкеты/прогрева. В n8n замени Telegram Trigger на ноду Webhook и вставь её URL.'),
                        TextInput::make('webhook_key')
                            ->label('Webhook key (генерируется автоматически)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Часть URL вебхука: <APP_URL>/api/webhooks/telegram-magnet/<этот ключ>'),

                        Fieldset::make('VK (одно сообщество на все лендинги — креды в «Маркетинг»)')
                            ->schema([
                                Toggle::make('vk_is_active')
                                    ->label('Пересылать VK-сообщения этого лендинга в n8n')
                                    ->default(false),
                                TextInput::make('vk_n8n_forward_url')
                                    ->label('VK: URL ноды Webhook в n8n')
                                    ->url()
                                    ->maxLength(500)
                                    ->helperText('Лид VK привязывается к лендингу по ref deep-link (vk.me/<сообщество>?ref=<token>). Сюда форвардятся его сообщения для анкеты/прогрева. Креды самого VK-сообщества — в настройках «Маркетинг».'),
                            ])->columns(1),
                    ]),

                // === 4. LEGACY (СТАРЫЕ ПОЛЯ) ===
                Section::make('Старые настройки (Legacy)')
                    ->collapsed()
                    ->schema([
                        TextInput::make('subtitle')->default('Авторский курс'),
                        Textarea::make('hero_description')->rows(3),
                        TextInput::make('bullet_1'),
                        TextInput::make('bullet_2'),
                        TextInput::make('instructor_label'),
                        TextInput::make('instructor_name'),
                        TextInput::make('video_url'),
                        CuratorPicker::make('image_path')
                            ->label('Главное изображение')
                            ->buttonLabel('Медиатека'),
                        RichEditor::make('description')->columnSpanFull(),
                        Grid::make(3)->schema([
                            Fieldset::make('Карточка 1')->schema([
                                TextInput::make('feature_1_title'),
                                Textarea::make('feature_1_text')->rows(4),
                            ])->columnSpan(1),
                            Fieldset::make('Карточка 2')->schema([
                                TextInput::make('feature_2_title'),
                                Textarea::make('feature_2_text')->rows(4),
                            ])->columnSpan(1),
                            Fieldset::make('Карточка 3')->schema([
                                TextInput::make('feature_3_title'),
                                Textarea::make('feature_3_text')->rows(4),
                            ])->columnSpan(1),
                        ]),
                        TextInput::make('button_text')->default('Записаться'),
                        TextInput::make('button_subtext'),
                        TextInput::make('telegram_url')
                            ->label('Ссылка на Telegram-канал (для блока на лендинге)')
                            ->helperText('Используется как ссылка "Подписаться на канал" в legacy-блоках. НЕ управляет редиректом после заявки.')
                            ->url()
                            ->maxLength(500),

                        TextInput::make('redirect_after_submit_url')
                            ->label('Редирект после отправки заявки (URL)')
                            ->helperText('Если заполнено — после страницы "Спасибо" пользователь автоматически перейдёт по этой ссылке (например, https://t.me/hindiruss). Цели в Метрику/VK успеют отстреляться. Оставь пустым, если редирект не нужен.')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://t.me/hindiruss'),
                    ]),
            ]);
    }

    /**
     * Свободный слаг для копии: {slug}-copy, при занятости — -copy-2, -copy-3…
     */
    private static function freeSlugFor(string $slug): string
    {
        $base = $slug.'-copy';
        $candidate = $base;
        for ($i = 2; LandingPage::where('slug', $candidate)->exists(); $i++) {
            $candidate = $base.'-'.$i;
        }

        return $candidate;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Заголовок'),
                Tables\Columns\TextColumn::make('slug')->label('URL'),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Активен'),
                Tables\Columns\IconColumn::make('is_listed')->boolean()->label('В каталоге'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()
                    ->label('Дублировать')
                    ->icon('heroicon-o-document-duplicate')
                    ->modalHeading(fn (LandingPage $record): string => 'Дублировать лендинг — '.$record->title)
                    ->modalDescription('Копируются все настройки и блоки страницы. Копия создаётся выключенной («Опубликовано» и «В каталоге» сняты) — включите после правки.')
                    ->form([
                        TextInput::make('title')
                            ->label('Заголовок копии')
                            ->required(),
                        TextInput::make('slug')
                            ->label('URL адрес (slug) копии')
                            ->prefix(config('app.url').'/promo/')
                            ->required()
                            ->unique('landing_pages', 'slug'),
                    ])
                    ->mutateRecordDataUsing(function (array $data): array {
                        $data['title'] = ($data['title'] ?? '').' (копия)';
                        $data['slug'] = self::freeSlugFor((string) ($data['slug'] ?? ''));

                        return $data;
                    })
                    ->beforeReplicaSaved(function (LandingPage $replica): void {
                        // Копия не должна мгновенно попасть в витрину и sitemap
                        // (каталог фильтрует is_active && is_listed).
                        $replica->is_active = false;
                        $replica->is_listed = false;
                    })
                    ->successRedirectUrl(fn (LandingPage $replica): string => static::getUrl('edit', ['record' => $replica]))
                    ->successNotificationTitle('Лендинг скопирован — не забудьте включить «Опубликовано»'),
                Tables\Actions\Action::make('open')
                    ->label('Открыть')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (LandingPage $record) => url('/promo/'.$record->slug))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingPages::route('/'),
            'create' => Pages\CreateLandingPage::route('/create'),
            'edit' => Pages\EditLandingPage::route('/{record}/edit'),
        ];
    }
}
