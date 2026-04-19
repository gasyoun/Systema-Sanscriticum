<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LandingPageResource\Pages;
use App\Models\LandingPage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block; // <-- ДОБАВЛЕНО
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\ColorPicker; // <-- ДОБАВЛЕНО
use Filament\Forms\Components\Select;      // <-- ДОБАВЛЕНО
use Awcodes\Curator\Components\Forms\CuratorPicker;

class LandingPageResource extends Resource
{
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
                            ->prefix(config('app.url') . '/promo/')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Toggle::make('is_active')
                            ->label('Опубликовано')
                            ->default(true),
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
                                Builder\Block::make('hero_block')
                                    ->label('1. Первый экран (Текст по центру)')
                                    ->icon('heroicon-m-star')
                                    ->schema([
                                        TextInput::make('subtitle')
                                            ->label('Надзаголовок (оранжевый)')
                                            ->placeholder('Старт 15 марта'),

                                        TextInput::make('title')
                                            ->label('Заголовок (Оффер)')
                                            ->required(),

                                        Textarea::make('description')
                                            ->label('Текст под заголовком')
                                            ->rows(3),

                                        TextInput::make('button_text')
                                            ->label('Текст кнопки')
                                            ->default('Записаться'),
                                    ]),

                                // 2. VIDEO
                                Builder\Block::make('video_block')
                                    ->label('2. Видео анонс')
                                    ->icon('heroicon-m-video-camera')
                                    ->schema([
                                        TextInput::make('title')->label('Заголовок'),
                                        TextInput::make('video_url')->label('Ссылка на видео')->required(),
                                    ]),

                                // 3. AUDIENCE
                                Builder\Block::make('audience_block')
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
                                    
                                // 11. COURSES GRID (Сетка других курсов)
                                Builder\Block::make('courses_grid')
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
Builder\Block::make('about_platform_light')
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
                'codeBlock'
            ])
            ->columnSpanFull() // Растягиваем редактор на всю ширину
            ->default('<p>Все программы Общества ревнителей санскрита носят исключительно просветительский характер. Участие в них не ведёт к присвоению квалификации, профессии или получению документов об образовании.</p><p>Наша главная цель — популяризация санскрита, знакомство с богатым культурным и философским наследием Индии, а также создание сообщества единомышленников для совместного изучения этого древнего языка.</p><p>Наши лекторы — это индологи, востоковеды, филологи, философы, йоги с большим практическим опытом.</p><p>До встречи на занятиях!</p>'),
    ]),    

                                // 4. RESULTS (Бенто-сетка)
                                Builder\Block::make('results_block')
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
                                Builder\Block::make('new_hero_with_form')
                                    ->label('13. Главный экран с формой (Стики/Универсальный)')
                                    ->icon('heroicon-o-document')
                                    ->schema([
                                        // --- КОНТЕНТ ---
                                        Section::make('Текст и контент')
                                            ->schema([
                                                TextInput::make('subtitle')
                                                    ->label('Надзаголовок (Плашка)')
                                                    ->default('ОНЛАЙН-КУРС В 2-Х ЧАСТЯХ'),
                                                    
                                                Textarea::make('title')
                                                    ->label('Главный заголовок')
                                                    ->default('Название вашего невероятного курса')
                                                    ->rows(3),
                                                    
                                                Textarea::make('description')
                                                    ->label('Описание')
                                                    ->default('Из первых уст от топовых спикеров. Освойте новую профессию за 3 месяца и начните зарабатывать из любой точки мира.')
                                                    ->rows(3),
                                                    
                                                TextInput::make('button_text')
                                                    ->label('Текст кнопки')
                                                    ->default('Оставить заявку'),
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
                                                        "system-ui, sans-serif" => 'Системный шрифт',
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
                                Builder\Block::make('cta_banner_block')
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

                                // 5. PROGRAM
                                Builder\Block::make('program_block')
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
                                Builder\Block::make('format_block')
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
Builder\Block::make('price_block')
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
        Section::make('Настройки дефицита (Таймер и Места)')
            ->schema([
                DateTimePicker::make('timer_end')
                    ->label('Таймер до (Дата и время)')
                    ->helperText('Если не заполнено — возьмется дата вебинара или +24 часа'),
                
                Grid::make(2)->schema([
                    TextInput::make('seats_taken')
                        ->label('Занято мест')
                        ->numeric()
                        ->default(16),
                    TextInput::make('seats_total')
                        ->label('Всего мест')
                        ->numeric()
                        ->default(20),
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

                                // 8. REVIEWS (Отзывы + Скриншоты)
                                Builder\Block::make('reviews_block')
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
                        
                    TextInput::make('video_link')
                        ->label('Ссылка на видеоотзыв (YouTube/Vimeo)')
                        ->url() // проверяет, что ввели именно ссылку
                        ->hint('Вставьте обычную ссылку на YouTube, и плеер сформируется автоматически.'),   
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

                Textarea::make('text')
                    ->label('Текст отзыва')
                    ->rows(3)
                    ->required()
                    ->columnSpanFull(), // Растянуть на всю ширину
            ])
            ->grid(2)
            ->defaultItems(3),
    ]),

                                // 9. FORM (С ДЕФИЦИТОМ)
                                Builder\Block::make('form_block')
                                    ->label('9. Форма заявки (CTA + Дефицит)')
                                    ->icon('heroicon-m-envelope')
                                    ->schema([
                                        Section::make('Настройки формы')
                                            ->schema([
                                                TextInput::make('title')
                                                    ->label('Заголовок формы')
                                                    ->default('Записаться на курс'),
                                                RichEditor::make('description')
                                                    ->label('Текст слева от формы (Описание)'),
                                                TextInput::make('button_text')
                                                    ->label('Текст кнопки')
                                                    ->default('Записаться'),
                                            ]),
                                        
                                    ]),
                                    Builder\Block::make('team_block')
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
                                Builder\Block::make('instructor_block')
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
Builder\Block::make('trial_block')
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
        \Filament\Forms\Components\Fieldset::make('Настройки всплывающей формы')
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
Builder\Block::make('faq_block')
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
                            ->cloneable()
                    ]),

                // === 3. АНАЛИТИКА ===
                Section::make('Аналитика')
                    ->schema([
                        TextInput::make('yandex_metrika_id')->label('ID Яндекс.Метрики')->numeric(),
                        TextInput::make('vk_pixel_id')->label('ID Пикселя VK')->numeric(),    
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
                        DatePicker::make('webinar_date')->native(false)->displayFormat('d.m.Y'),
                        TextInput::make('webinar_label')->default('Бесплатный вебинар'),    
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
                        TextInput::make('telegram_url')->url(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Заголовок'),
                Tables\Columns\TextColumn::make('slug')->label('URL'),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Активен'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('open')
                    ->label('Открыть')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (LandingPage $record) => url('/promo/' . $record->slug))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingPages::route('/'),
            'create' => Pages\CreateLandingPage::route('/create'),
            'edit' => Pages\EditLandingPage::route('/{record}/edit'),
        ];
    }
}