# АУДИТ LARAVEL-ПРОЕКТА SYSTEMA SANSCRITICUM

**Дата:** 2026-04-24
**Проверено:** `app/`, `routes/`, `resources/`, `database/`

---

## 1. БЕЗОПАСНОСТЬ

### 1.1 Незащищённые роуты

**[КРИТИЧЕСКИЙ] Debug-роут без аутентификации**
- **Файл:** `routes/web.php:126`
- **Проблема:** Маршрут `/files-debug` доступен без middleware `auth`. Любой пользователь может получить информацию о структуре файлов.
```php
// Текущий код
Route::get('/files-debug', function () {
    // ...
});
```
- **Исправление:** Удалить маршрут или защитить:
```php
Route::get('/files-debug', function () {
    // ...
})->middleware(['auth', 'admin']);
```

---

**[ВЫСОКИЙ] Admin-роут без middleware `admin`**
- **Файл:** `routes/web.php:106`
- **Проблема:** `/admin/leads/export` находится в группе `['auth', 'track.activity']`, но не имеет middleware `admin`. Проверка прав выполняется только в контроллере через `abort_unless()`.
```php
Route::get('/admin/leads/export', [LeadController::class, 'export'])->name('leads.export');
```
- **Исправление:**
```php
Route::middleware(['auth', 'track.activity', 'admin'])->group(function () {
    Route::get('/admin/leads/export', [LeadController::class, 'export'])->name('leads.export');
});
```

---

**[СРЕДНИЙ] API sync-lessons без аутентификации**
- **Файл:** `routes/api.php:24`
- **Проблема:** Защита через custom header `X-Secret-Key` вместо стандартного middleware. Уязвим к перехвату HTTP-заголовков.
```php
Route::post('/sync-lessons', [LessonController::class, 'sync']);
```
- **Исправление:** Использовать API-токены (Sanctum) или JWT вместо custom header.

---

**[ВЫСОКИЙ] Hardcoded email администратора**
- **Файл:** `routes/web.php:77`
- **Проблема:** Email зашит в коде для определения прав администратора.
```php
if ($user->is_admin || $user->email === 'pe4kinsmart@gmail.com') {
    return redirect('/admin');
}
```
- **Исправление:**
```php
if ($user->is_admin) {
    return redirect('/admin');
}
```

---

### 1.2 Отсутствие авторизации через Policies/Gates

**[СРЕДНИЙ] abort_unless вместо Policy**
- **Файл:** `app/Http/Controllers/LeadController.php:101`
- **Проблема:** Авторизация через `abort_unless()` вместо Laravel Policy.
```php
abort_unless(auth()->check() && auth()->user()->is_admin, 403, 'Доступ к выгрузке запрещен.');
```
- **Исправление:** Создать `LeadPolicy` и использовать:
```php
$this->authorize('export', Lead::class);
```

---

### 1.3 XSS-уязвимости в Blade ({!! !!})

**[ВЫСОКИЙ] Неэскейпированный вывод пользовательского контента**

| Файл | Строка | Код |
|------|--------|-----|
| `resources/views/student/messages.blade.php` | 110 | `{!! $msg->content !!}` |
| `resources/views/articles/show.blade.php` | 90 | `{!! $article->body !!}` |
| `resources/views/emails/announcement.blade.php` | 34 | `{!! $announcement->content !!}` |
| `resources/views/promo/blocks/instructor_block.blade.php` | 182 | `{!! $data['bio'] ?? '' !!}` |
| `resources/views/promo/blocks/about_platform_light.blade.php` | 37 | `{!! $content !!}` |
| `resources/views/promo/blocks/faq_block.blade.php` | 86 | `{!! $item['answer'] !!}` |
| `resources/views/promo/blocks/form_block.blade.php` | 9 | `{!! $data['description'] !!}` |
| `resources/views/promo/blocks/price_block.blade.php` | 163 | `{!! $item['features'] ?? '' !!}` |
| `resources/views/promo/blocks/program_block.blade.php` | 59 | `{!! $module['module_content'] !!}` |
| `resources/views/promo/blocks/team_block.blade.php` | 90 | `{!! $item['description'] !!}` |
| `resources/views/promo/legacy.blade.php` | 327 | `{!! $page->description !!}` |
| `resources/views/livewire/student-dictionary.blade.php` | 87-117 | `{!! $highlight(...) !!}` (8 мест) |

- **Исправление:** Установить HTMLPurifier и санитизировать контент:
```bash
composer require stevebauman/purify
```
```php
// app/Services/HtmlSanitizer.php
namespace App\Services;

use Stevebauman\Purify\Facades\Purify;

class HtmlSanitizer
{
    public static function clean(?string $html): string
    {
        return Purify::clean($html ?? '');
    }
}
```
Применять перед сохранением в БД или при выводе:
```php
$article->body = HtmlSanitizer::clean($request->input('body'));
```

---

**[СРЕДНИЙ] Потенциальная XSS через javascript: URL**
- **Файл:** `resources/views/promo/blocks/instructor_block.blade.php:59`
- **Проблема:** URL из БД подставляется в `href` без валидации. Если `url = "javascript:alert('xss')"`, код выполнится.
```php
<{{ $tag }} {!! $href !!}
```
- **Исправление:** Валидировать URL:
```php
$url = filter_var($data['url'], FILTER_VALIDATE_URL) ? $data['url'] : '#';
```

---

**[СРЕДНИЙ] XSS в Telegram-сообщениях**
- **Файл:** `app/Http/Controllers/TelegramWebhookController.php:89-90`
- **Проблема:** Пользовательский текст встраивается в HTML без экранирования.
```php
$alertMessage = "🔴 <b>Новое сообщение от {$user->name}:</b>\n\n<i>{$question}</i>";
```
- **Исправление:**
```php
$alertMessage = "🔴 <b>Новое сообщение от " . htmlspecialchars($user->name) . ":</b>\n\n<i>" . htmlspecialchars($question) . "</i>";
```

---

### 1.4 Mass assignment без $fillable/$guarded

**[КРИТИЧЕСКИЙ] $guarded = [] — все поля доступны для массового присваивания**

| Файл | Строка |
|------|--------|
| `app/Models/ChatMessage.php` | 12 |
| `app/Models/Dictionary.php` | 13 |
| `app/Models/DictionaryWord.php` | 12 |

- **Исправление (ChatMessage):**
```php
protected $fillable = [
    'user_id',
    'role',
    'text',
    'is_read',
];
```
- **Исправление (Dictionary):**
```php
protected $fillable = [
    'name',
    'description',
    'is_active',
];
```
- **Исправление (DictionaryWord):**
```php
protected $fillable = [
    'dictionary_id',
    'devanagari',
    'iast',
    'cyrillic',
    'translation',
    'page',
];
```

---

### 1.5 Отсутствие валидации входящих данных

**[ВЫСОКИЙ] request->all() без валидации в webhook-контроллерах**

- **Файл:** `app/Http/Controllers/Api/LessonController.php:26`
```php
$courses = $request->all();
foreach ($courses as $course) {
    $courseId = $course['id']; // Без проверки
```
- **Исправление:**
```php
$validated = $request->validate([
    '*.id'         => 'required|integer',
    '*.title'      => 'required|string|max:255',
    '*.videoLinks' => 'nullable|array',
]);
```

---

- **Файл:** `app/Http/Controllers/Api/VkBotController.php:17`
```php
$data = $request->all();
$messageObj = $data['object']['message']; // Без проверки структуры
```
- **Исправление:** Добавить валидацию:
```php
$validated = $request->validate([
    'type'                   => 'required|string',
    'object.message.from_id' => 'required|integer',
    'object.message.text'    => 'nullable|string',
]);
```

---

- **Файл:** `app/Http/Controllers/TelegramWebhookController.php:17`
```php
$data = $request->all(); // Без валидации
```

---

- **Файл:** `app/Http/Controllers/StudentController.php:250`
- **Проблема:** `courseSlug` и `lessonId` из URL не валидируются.
```php
public function saveNote(Request $request, $courseSlug, $lessonId)
```
- **Исправление:**
```php
public function saveNote(Request $request, string $courseSlug, int $lessonId): JsonResponse
```

---

### 1.6 SQL-инъекции (raw-запросы без bindings)

**[ВЫСОКИЙ] DB::raw с интерполяцией переменной**
- **Файл:** `app/Http/Controllers/Api/HeartbeatController.php:95`
- **Проблема:** Переменная `$delta` вставляется в SQL через интерполяцию строки. Хотя она валидирована как integer, это плохая практика.
```php
'total_time_on_page' => DB::raw("total_time_on_page + {$delta}"),
```
- **Исправление:**
```php
'total_time_on_page' => DB::raw("total_time_on_page + ?"),
// И в update использовать bindings
```
Или использовать `increment()`:
```php
$view->increment('total_time_on_page', $delta);
```

---

**[СРЕДНИЙ] LIKE без экранирования спецсимволов**
- **Файл:** `app/Http/Controllers/ShopController.php:19`
```php
->when($search, fn ($q, $s) => $q->where('title', 'LIKE', "%{$s}%"))
```
- **Исправление:**
```php
->when($search, fn ($q, $s) => $q->where('title', 'LIKE', '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%'))
```

---

### 1.7 Другие проблемы безопасности

**[КРИТИЧЕСКИЙ] Hardcoded пароль администратора в seeder**
- **Файл:** `database/seeders/DatabaseSeeder.php:18`
```php
'password' => Hash::make('240885'),
```
- **Исправление:**
```php
'password' => Hash::make(env('ADMIN_PASSWORD', Str::random(16))),
```

---

**[ВЫСОКИЙ] Неправильные CSRF-исключения**
- **Файл:** `app/Http/Middleware/VerifyCsrfToken.php:18-19`
- **Проблема:** Пути в CSRF-исключениях не совпадают с реальными маршрутами.
```php
// Текущее:
'/telegram-webhook',  // Реальный маршрут: /api/telegram/webhook
'api/heartbeat',      // Без слеша: /api/heartbeat
// Отсутствует:
// '/api/webhooks/tochka'
```
- **Исправление:**
```php
protected $except = [
    '/vk-webhook',
    '/api/vk-webhook',
    '/api/telegram/webhook',
    '/api/webhooks/tochka',
    '/api/heartbeat',
];
```

---

**[СРЕДНИЙ] postMessage с wildcard origin**
- **Файл:** `resources/views/student/lesson.blade.php:110-123`
```javascript
yt.contentWindow.postMessage(JSON.stringify({event: 'listening', id: 1}), '*');
```
- **Исправление:**
```javascript
yt.contentWindow.postMessage(JSON.stringify({event: 'listening', id: 1}), 'https://www.youtube.com');
```

---

**[СРЕДНИЙ] Небезопасное хранение в сессии**
- **Файл:** `app/Http/Controllers/CheckoutController.php:47-80`
- **Проблема:** Промокод хранится в сессии, пользователь может манипулировать значением.
- **Исправление:** Привязывать промокод к записи платежа в БД.

---

## 2. ПРОИЗВОДИТЕЛЬНОСТЬ

### 2.1 N+1 запросы

**[СРЕДНИЙ] Отсутствие eager loading**
- **Файл:** `app/Http/Controllers/StudentController.php:280`
```php
$course = Course::where('slug', $slug)->firstOrFail();
$lessons = $course->lessons()->orderBy('created_at', 'asc')->get();
// Затем для каждого lesson могут загружаться связи
```
- **Исправление:**
```php
$course = Course::where('slug', $slug)
    ->with(['lessons' => fn($q) => $q->orderBy('created_at', 'asc')])
    ->firstOrFail();
```

---

**[СРЕДНИЙ] DB-запросы в Blade-шаблонах**
- **Файл:** `resources/views/promo/blocks/team_block.blade.php:38-41`
```php
@php
    $media = \Awcodes\Curator\Models\Media::find($item['image']);
@endphp
```
- **Файл:** `resources/views/promo/blocks/instructor_block.blade.php:21-22`
```php
$instructorImageUrl = !empty($data['image']) ? \Awcodes\Curator\Models\Media::find($data['image'])?->url : null;
```
- **Исправление:** Предзагружать медиа в контроллере и передавать готовые URL в шаблон.

---

**[СРЕДНИЙ] Вычисление прогресса в Blade**
- **Файл:** `resources/views/student/dashboard.blade.php:203-206`
```php
@php
    $totalLessons = $course->lessons->count();
    $completedLessons = auth()->user()->completedLessons->whereIn('id', $course->lessons->pluck('id'))->count();
@endphp
```
- **Проблема:** Если у пользователя много курсов, это N+1 для каждого курса.
- **Исправление:** Добавить метод в модель `Course`:
```php
public function getProgressForUser(User $user): int
{
    $total = $this->lessons->count();
    $completed = $user->completedLessons->whereIn('id', $this->lessons->pluck('id'))->count();
    return $total > 0 ? round(($completed / $total) * 100) : 0;
}
```
И предзагрузить `lessons` и `completedLessons` в контроллере с `with()`.

---

### 2.2 Отсутствие индексов на часто используемых колонках

**[ВЫСОКИЙ] Отсутствие индексов на внешних ключах**

| Таблица | Колонка | Файл миграции |
|---------|---------|---------------|
| `payments` | `user_id` | `2026_03_07_210403_create_payments_table.php` |
| `payments` | `course_id` | `2026_03_07_210403_create_payments_table.php` |
| `certificates` | `user_id` | `2026_02_13_174630_create_certificates_table.php` |
| `teacher_payouts` | `teacher_id` | `2026_03_23_212539_create_teacher_payouts_table.php` |
| `tariffs` | `course_id` | `2026_03_08_084558_create_tariffs_table.php` |
| `chat_messages` | `user_id` | `2026_03_16_184711_create_chat_messages_table.php` |
| `imports` | `user_id` | `2026_03_09_065804_create_imports_table.php` |

- **Исправление:** Создать миграцию:
```php
public function up(): void
{
    Schema::table('payments', function (Blueprint $table) {
        $table->index('user_id');
        $table->index('course_id');
        $table->index(['course_id', 'status']);
    });
    Schema::table('certificates', fn (Blueprint $t) => $t->index('user_id'));
    Schema::table('teacher_payouts', fn (Blueprint $t) => $t->index('teacher_id'));
    Schema::table('tariffs', fn (Blueprint $t) => $t->index('course_id'));
    Schema::table('chat_messages', function (Blueprint $table) {
        $table->index('user_id');
        $table->index('is_read');
    });
    Schema::table('imports', fn (Blueprint $t) => $t->index('user_id'));
}
```

---

### 2.3 Race condition

**[СРЕДНИЙ] Проверка и создание без атомарности**
- **Файл:** `app/Http/Controllers/Api/HeartbeatController.php:45-66`
```php
$view = LessonView::where('user_id', $user->id)
    ->where('lesson_id', $lessonId)
    ->first();

if ($view === null) {
    $view = LessonView::create([...]); // Race condition
}
```
- **Исправление:**
```php
$view = LessonView::firstOrCreate(
    ['user_id' => $user->id, 'lesson_id' => $lessonId],
    ['course_id' => $lesson->course_id, ...]
);
```

---

### 2.4 Отсутствие unique constraints на pivot-таблицах

**[СРЕДНИЙ] Возможны дубликаты в pivot-таблицах**

| Таблица | Файл |
|---------|------|
| `group_user` | `2026_02_11_165623_repair_group_user_table.php` |
| `lesson_user` | `2026_02_17_155216_create_lesson_user_table.php` |

- **Исправление:**
```php
Schema::table('group_user', fn (Blueprint $t) => $t->unique(['user_id', 'group_id']));
Schema::table('lesson_user', fn (Blueprint $t) => $t->unique(['user_id', 'lesson_id']));
```

---

## 3. КАЧЕСТВО КОДА

### 3.1 Жирные контроллеры

**[СРЕДНИЙ] Бизнес-логика парсинга транскрипции в контроллере**
- **Файл:** `app/Http/Controllers/StudentController.php:163-222`
- **Проблема:** ~60 строк логики парсинга JSON транскрипции находятся в контроллере.
- **Исправление:** Создать `App\Services\TranscriptService`:
```php
class TranscriptService
{
    public function parse(Lesson $lesson): array
    {
        // Переместить сюда логику парсинга
    }
}
```

---

### 3.2 Бизнес-логика в Blade-шаблонах

**[СРЕДНИЙ] Парсинг YouTube/RuTube URL в Blade**
- **Файл:** `resources/views/student/lesson.blade.php:57-89`
- **Проблема:** Регулярные выражения и логика парсинга видео-URL в шаблоне.
- **Исправление:** Создать `App\Services\VideoParser`:
```php
class VideoParser
{
    public static function extractYoutubeId(?string $url): ?string { /* ... */ }
    public static function extractRutubeId(?string $url): ?string { /* ... */ }
    public static function formatTimecodes(?string $text): string { /* ... */ }
}
```

---

**[СРЕДНИЙ] Фильтрация тарифов в Blade**
- **Файл:** `resources/views/shop/show.blade.php:105-109`
```php
@php
    $fullTariffs = $course->tariffs->where('type', '!=', 'block');
    $blockTariffs = $course->tariffs->where('type', 'block')->sortBy('block_number');
@endphp
```
- **Исправление:** Перенести в контроллер:
```php
$fullTariffs = $course->tariffs->where('type', '!=', 'block');
$blockTariffs = $course->tariffs->where('type', 'block')->sortBy('block_number');
return view('shop.show', compact('course', 'fullTariffs', 'blockTariffs'));
```

---

**[СРЕДНИЙ] Вычисление доступа к блокам в Blade**
- **Файл:** `resources/views/student/lesson.blade.php:240-250`
- **Исправление:** Перенести в контроллер и передать готовые данные.

---

### 3.3 Замыкания в маршрутах вместо контроллеров

**[НИЗКИЙ]** 5 маршрутов используют closure вместо методов контроллера:

| Строка | Маршрут | Рекомендация |
|--------|---------|--------------|
| `web.php:38` | `GET /` | Перенести в `HomeController` |
| `web.php:31` | `GET /promo/{slug}` | Заменить на `Route::redirect()` |
| `web.php:75` | `GET /home` | Перенести в `HomeController` |
| `web.php:115` | `GET /force-download/{file}` | Перенести в `DownloadController` |
| `web.php:126` | `GET /files-debug` | Удалить |

---

### 3.4 Использование env() вместо config()

**[СРЕДНИЙ] env() вызывается в runtime вместо config()**

| Файл | Строки |
|------|--------|
| `app/Http/Controllers/TelegramWebhookController.php` | 73, 87, 105, 115-117, 190 |
| `app/Http/Controllers/TelegramController.php` | 23 |
| `app/Http/Controllers/Api/VkBotController.php` | 25, 53, 66, 81, 92-93, 215, 232 |
| `resources/views/student/dashboard.blade.php` | 127 |

- **Проблема:** `env()` возвращает `null` при кэшированной конфигурации (`php artisan config:cache`).
- **Исправление:** Добавить в `config/services.php`:
```php
'telegram' => [
    'bot_token'    => env('TELEGRAM_BOT_TOKEN'),
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),
    'admin_id'     => env('ADMIN_TELEGRAM_ID'),
],
'vk' => [
    'token'        => env('VK_BOT_TOKEN'),
    'group_id'     => env('VK_GROUP_ID'),
    'secret'       => env('VK_SECRET_KEY'),
    'confirmation' => env('VK_CONFIRMATION_TOKEN'),
],
'yandex' => [
    'api_key'    => env('YANDEX_API_KEY'),
    'folder_id'  => env('YANDEX_FOLDER_ID'),
],
```
И в контроллерах заменить `env('TELEGRAM_BOT_TOKEN')` на `config('services.telegram.bot_token')`.

---

### 3.5 Магические числа и строки

**[НИЗКИЙ]** Магические числа без констант:

| Файл | Строка | Значение | Что значит |
|------|--------|----------|------------|
| `app/Http/Controllers/ArticleController.php` | 50 | `9` | Статей на странице |
| `app/Http/Controllers/CheckoutController.php` | 50 | неявные числа | Скидки |
| `routes/web.php` | 42 | `9` | Лендингов на странице |

- **Исправление:** Вынести в константы:
```php
private const PER_PAGE = 9;
```

---

### 3.6 Неиспользуемые импорты

**[НИЗКИЙ]**
- **Файл:** `app/Http/Controllers/StudentController.php:11`
```php
use Illuminate\Support\Carbon; // Не используется, используется helper now()
```

---

### 3.7 Debug-логирование в production

**[НИЗКИЙ]**
- **Файл:** `app/Http/Middleware/TrackUserActivity.php:46-49`
```php
\Illuminate\Support\Facades\Log::info('TRACK STEP 1: entered', [
    'url'  => $request->getRequestUri(),
    'auth' => Auth::check(),
]);
```
- **Исправление:** Удалить или обернуть в `if (config('app.debug'))`.

---

### 3.8 Отсутствие return type hints

**[НИЗКИЙ]**
- **Файл:** `app/Http/Controllers/AuthController.php:12-54`
```php
public function showLoginForm()    // Нет return type
public function login(Request $request)  // Нет return type
```
- **Исправление:**
```php
public function showLoginForm(): View
public function login(Request $request): RedirectResponse
```

---

## 4. АРХИТЕКТУРА

### 4.1 Неправильный тип course_id в миграции

**[КРИТИЧЕСКИЙ]**
- **Файл:** `database/migrations/2026_02_11_003940_create_lessons_table.php:13`
```php
$table->string('course_id')->index();
```
- **Проблема:** `course_id` определён как `string` вместо `foreignId`. Нет constraint и каскадного удаления.
- **Исправление:** Создать миграцию для изменения типа:
```php
Schema::table('lessons', function (Blueprint $table) {
    $table->unsignedBigInteger('course_id')->change();
    $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
});
```

---

### 4.2 Отсутствие $casts в моделях

| Модель | Поле | Нужный cast |
|--------|------|-------------|
| `Certificate.php` | `issued_at` | `'datetime'` |
| `ChatMessage.php` | `is_read` | `'boolean'` |
| `TeacherPayout.php` | `amount` | `'decimal:2'` |
| `User.php` | `telegram_id`, `vk_id` | `'integer'` |
| `Announcement.php` | `target_groups`, `target_courses` | `'array'` |

---

### 4.3 Отсутствие полей в $fillable

**[СРЕДНИЙ]**
- **Файл:** `app/Models/Lesson.php` — отсутствуют `slug` и `group_id` в `$fillable`
- **Файл:** `app/Models/Announcement.php` — отсутствуют `send_to_telegram`, `send_to_vk`

---

### 4.4 Отсутствие фабрик для моделей

**[НИЗКИЙ]** Только `UserFactory` создана. Отсутствуют фабрики для 24 других моделей, что затрудняет тестирование.

---

### 4.5 Игнорирование ошибок HTTP-запросов

**[СРЕДНИЙ]**
- **Файл:** `app/Http/Controllers/TelegramWebhookController.php:192`
- **Файл:** `app/Http/Controllers/Api/VkBotController.php:233`
```php
Http::post("https://api.telegram.org/bot{$token}/sendMessage", [...]);
// Нет проверки ответа
```
- **Исправление:**
```php
$response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [...]);
if (!$response->successful()) {
    Log::error('Telegram API error', ['status' => $response->status(), 'body' => $response->body()]);
}
```

---

### 4.6 Отсутствие rate limiting на webhook-ах

**[СРЕДНИЙ]**
- **Файлы:** `VkBotController.php`, `TelegramWebhookController.php`
- **Проблема:** Webhook-методы не имеют rate limiting. Возможен DoS и чрезмерные запросы к Yandex AI API.
- **Исправление:** Добавить middleware `throttle:60,1` или реализовать cache-based throttling.

---

## СВОДКА

### По критичности

| Уровень | Кол-во | Ключевые проблемы |
|---------|--------|-------------------|
| КРИТИЧЕСКИЙ | 5 | Debug-роут без защиты, hardcoded пароль, $guarded=[], тип course_id, CSRF пути |
| ВЫСОКИЙ | 8 | XSS в 12+ файлах, SQL injection, отсутствие валидации webhook, индексы |
| СРЕДНИЙ | 15 | N+1, env() вместо config(), бизнес-логика в Blade, race condition |
| НИЗКИЙ | 6 | Магические числа, неиспользуемые импорты, отсутствие type hints |

### Приоритет исправлений

**Немедленно (неделя 1):**
1. Удалить/защитить `/files-debug`
2. Убрать hardcoded пароль из seeder
3. Убрать hardcoded email из маршрутов
4. Заменить `$guarded = []` на `$fillable`
5. Исправить CSRF-исключения
6. Добавить санитизацию HTML (HTMLPurifier) для всех `{!! !!}`

**Высокий приоритет (неделя 2):**
7. Добавить валидацию в webhook-контроллеры
8. Исправить `DB::raw` с интерполяцией
9. Добавить индексы на внешние ключи
10. Заменить `env()` на `config()` везде

**Средний приоритет (неделя 3-4):**
11. Вынести бизнес-логику из Blade в сервисы
12. Вынести closure из маршрутов в контроллеры
13. Добавить unique constraints на pivot-таблицы
14. Исправить race condition в HeartbeatController
15. Добавить rate limiting на webhook-и
