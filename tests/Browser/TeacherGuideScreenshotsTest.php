<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Скриншоты руководства преподавателя (H2502, волна 2).
 *
 * Тест снимает по одному кадру на каждый раздел ПЕРЕПИСИ навигации
 * `docs/generated/teacher_nav_census.json` (её делает волна 1, команда
 * `teacher:nav-census`) и складывает их в `docs/screenshots/teacher-guide/`.
 * Руководство `docs/TEACHER_CABINET_GUIDE_RU.md` ссылается на эту папку.
 *
 * Почему список экранов читается из переписи, а не задан здесь списком: перепись
 * спрашивает у каждого ресурса и страницы панели ЕГО СОБСТВЕННЫЙ гейт от имени
 * преподавателя. Свой список в тесте разъехался бы с меню при первой же правке
 * гейта — и руководство тихо получило бы кадр раздела, которого преподаватель не
 * видит, либо потеряло бы кадр появившегося.
 *
 * ФЕНС (H2502, не ослаблять). Денежные экраны не снимаются никогда — ни на dev,
 * ни на проде: TeacherSalaries, MutualSettlements, TeacherPayoutResource,
 * PaymentResource. Фильтр стоит ДВАЖДЫ и по двум разным ключам — по классу и по
 * URL, — потому что перепись собирается динамически: сегодня денежного экрана в
 * преподавательском меню нет, а гейт правят чужие handoff'ы. Плюс третья
 * проверка постфактум: имена файлов в готовой папке. Один фильтр — это надежда,
 * что перепись не изменится; три — это фенс.
 *
 * Данные под кадрами — фикстура, а не прод и не дамп: имена студентов выдуманы,
 * начислений, задолженностей и выплат в кадре нет по построению (денежные
 * экраны не снимаются, а денежных колонок на снимаемых нет). Фикстура нужна,
 * чтобы в руководстве не стояло пятнадцать пустых таблиц: человек, открывший
 * гид, должен увидеть тот же экран, что у него на работе.
 *
 * DatabaseTruncation, а не RefreshDatabase — по той же причине, что в
 * SmokeTest: транзакция RefreshDatabase живёт в тест-процессе, а браузер ходит к
 * отдельному процессу `artisan serve` и её не видит.
 *
 * Как запускать (Windows) — docs/DUSK_LOCAL_WINDOWS.md.
 */
class TeacherGuideScreenshotsTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** Куда кладём кадры — путь от корня репозитория. */
    private const TARGET_DIR = 'docs/screenshots/teacher-guide';

    /** Перепись навигации волны 1 — источник списка разделов. */
    private const CENSUS = 'docs/generated/teacher_nav_census.json';

    /**
     * Денежные экраны по классам. Полное имя класса, не basename: пусть
     * совпадение будет точным, а не «что-то похожее».
     *
     * @var array<int, string>
     */
    private const MONEY_CLASSES = [
        'App\Filament\Pages\TeacherSalaries',
        'App\Filament\Pages\MutualSettlements',
        'App\Filament\Resources\TeacherPayoutResource',
        'App\Filament\Resources\PaymentResource',
    ];

    /**
     * Денежные корни в URL и в имени класса — второй ключ того же фенса.
     * Список шире четырёх экранов сознательно: соседние денежные страницы
     * (дебиторка, фонды, инвест-модель) преподавателю сейчас не видны, но если
     * гейт когда-нибудь ослабят, кадр не должен появиться молча.
     *
     * @var array<int, string>
     */
    private const MONEY_WORDS = [
        'salary', 'salaries', 'payout', 'settlement', 'payment',
        'debtor', 'receivable', 'profit', 'invest', 'finance', 'earning',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Dusk по умолчанию пишет кадры в tests/Browser/screenshots (там же
        // лежат кадры упавших тестов, папка в .gitignore). Нам нужна папка
        // руководства — она коммитится.
        Browser::$storeScreenshotsAt = base_path(self::TARGET_DIR);
    }

    public function test_it_shoots_one_screenshot_per_guide_section(): void
    {
        $sections = $this->sections();

        $this->assertNotEmpty(
            $sections,
            'Перепись '.self::CENSUS.' не дала ни одного раздела — сначала обновите её: php artisan teacher:nav-census'
        );

        $this->freshTargetDir();

        $teacher = $this->seedFixture();

        $this->browse(function (Browser $browser) use ($teacher, $sections) {
            $browser->loginAs($teacher);

            foreach ($sections as $section) {
                $browser->visit($section['path'])
                    // Дошли до панели, а не отлетели на форму входа и не в 403.
                    ->assertPathBeginsWith('/admin')
                    ->assertDontSee('Забыли пароль')
                    // Якорь текстовый (подпись группы меню), а не CSS-селектор
                    // вёрстки: редизайн ломает `.fi-sidebar-nav a:nth-child(3)`,
                    // а название раздела переживает его.
                    ->waitForText('Обучение', 20)
                    // Таблицы и графики Filament догружаются Livewire'ом уже
                    // после того, как каркас страницы отрисован.
                    ->pause(1500)
                    ->screenshot($section['slug']);
            }
        });

        $this->assertSectionsAreCovered($sections);
        $this->assertNoMoneyScreenshotLanded();
    }

    /**
     * Разделы переписи, годные для съёмки: с URL и не денежные.
     *
     * @return array<int, array{slug: string, path: string, label: string, class: string}>
     */
    private function sections(): array
    {
        $path = base_path(self::CENSUS);

        $this->assertFileExists($path, 'Нет переписи навигации — её делает волна 1 (H2501).');

        $census = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $sections = [];

        foreach ($census['items'] ?? [] as $item) {
            $url = $item['url'] ?? null;

            // Виджеты и всё без собственного URL снять нельзя — у них нет
            // страницы. Перепись держит их отдельным списком не случайно.
            if (! is_string($url) || $url === '') {
                continue;
            }

            $class = (string) ($item['class'] ?? '');

            if ($this->isMoney($class, $url)) {
                continue;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);

            $sections[] = [
                'slug' => $this->slug($path),
                'path' => $path,
                'label' => (string) ($item['label'] ?? $class),
                'class' => $class,
            ];
        }

        return $sections;
    }

    /** Денежный ли экран — по классу И по URL, оба ключа независимы. */
    private function isMoney(string $class, string $url): bool
    {
        if (in_array($class, self::MONEY_CLASSES, true)) {
            return true;
        }

        $haystack = mb_strtolower($class.' '.$url);

        foreach (self::MONEY_WORDS as $word) {
            if (str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Имя файла из пути раздела: `/admin/srs-cards` → `srs-cards`.
     *
     * Имя ведётся от URL, а не от русской подписи: подпись меняют копирайтом, а
     * ссылки в руководстве от этого не должны протухать; плюс имя остаётся
     * ASCII, что важно для кроссплатформенного чекаута.
     */
    private function slug(string $path): string
    {
        $trimmed = trim($path, '/');
        $trimmed = preg_replace('#^admin/#', '', $trimmed) ?? $trimmed;

        return str_replace('/', '-', $trimmed);
    }

    /**
     * Папка пересобирается с нуля: иначе кадр исчезнувшего раздела остался бы
     * лежать и руководство ссылалось бы на экран, которого больше нет.
     */
    private function freshTargetDir(): void
    {
        $dir = base_path(self::TARGET_DIR);

        if (is_dir($dir)) {
            foreach (glob($dir.'/*.png') ?: [] as $stale) {
                unlink($stale);
            }
        } else {
            mkdir($dir, 0o775, true);
        }
    }

    /** @param array<int, array{slug: string, label: string}> $sections */
    private function assertSectionsAreCovered(array $sections): void
    {
        $dir = base_path(self::TARGET_DIR);

        foreach ($sections as $section) {
            $file = $dir.'/'.$section['slug'].'.png';

            $this->assertFileExists($file, "Раздел «{$section['label']}» остался без кадра.");
            $this->assertGreaterThan(
                1024,
                (int) filesize($file),
                "Кадр раздела «{$section['label']}» подозрительно мал — вероятно, снят пустой белый экран."
            );
        }

        $this->assertCount(
            count($sections),
            glob($dir.'/*.png') ?: [],
            'В папке кадров есть лишние файлы — она обязана в точности повторять перепись.'
        );
    }

    /** Третий, постфактумный виток фенса — по именам готовых файлов. */
    private function assertNoMoneyScreenshotLanded(): void
    {
        foreach (glob(base_path(self::TARGET_DIR).'/*.png') ?: [] as $file) {
            $name = mb_strtolower(basename($file));

            foreach (self::MONEY_WORDS as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $name,
                    "Денежный кадр {$name} — фенс H2502 нарушен."
                );
            }
        }
    }

    /**
     * Фикстура под кадрами: один преподаватель, его курс, группа с учениками и
     * по несколько строк в каждом разделе меню. Значения заданы явно, а не
     * фейкером: руководство переснимают раз в несколько месяцев, и diff должен
     * показывать изменения ИНТЕРФЕЙСА, а не новые случайные имена.
     */
    private function seedFixture(): User
    {
        $teacher = Teacher::create([
            'name' => 'Анна Дмитриевна Соколова',
            'email' => 'teacher.guide@example.test',
        ]);

        $user = User::factory()->create([
            'name' => 'Анна Дмитриевна Соколова',
            'email' => 'teacher.guide@example.test',
            'role' => Roles::TEACHER,
            'teacher_id' => $teacher->id,
        ]);

        $course = Course::create([
            'title' => 'Санскрит: первый год',
            'slug' => 'sanskrit-first-year',
            'description' => 'Годовой курс для начинающих: письмо, чтение, базовая грамматика.',
            'is_visible' => true,
            'is_active' => true,
            'teacher_id' => $teacher->id,
            'lessons_count' => 3,
        ]);

        $group = Group::create([
            'name' => 'Первый год · вечерняя',
            'slug' => 'first-year-evening',
            'status' => 'active',
            'min_size' => 4,
            'planned_start_date' => now()->subMonth()->toDateString(),
        ]);
        $group->courses()->attach($course->id);

        $students = collect([
            'Мария Крылова',
            'Пётр Заславский',
            'Ольга Ким',
            'Тимур Насыров',
        ])->map(fn (string $name, int $i) => User::factory()->create([
            'name' => $name,
            'email' => 'student'.($i + 1).'.guide@example.test',
        ]));

        $group->users()->attach($students->pluck('id')->all());

        $lessons = collect([
            ['Урок 1. Деванагари: гласные', 'lesson-1-vowels', 1],
            ['Урок 2. Деванагари: согласные', 'lesson-2-consonants', 2],
            ['Урок 3. Слог и лигатуры', 'lesson-3-ligatures', 3],
        ])->map(fn (array $row) => Lesson::create([
            'title' => $row[0],
            'slug' => $row[1],
            'topic' => 'Письмо',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'lesson_date' => now()->subWeeks(4 - $row[2])->toDateString(),
            'is_published' => true,
            'sort_order' => $row[2],
            'homework_enabled' => true,
            'homework_prompt' => 'Перепишите таблицу знаков и пришлите фото тетради.',
        ]));

        foreach ([-2, 5, 12] as $offset) {
            Schedule::create([
                'title' => 'Санскрит: первый год — занятие',
                'description' => 'Вечерняя группа, разбор домашней работы и новая тема.',
                'start' => now()->addDays($offset)->setTime(19, 0),
                'end' => now()->addDays($offset)->setTime(20, 30),
                'course_id' => $course->id,
                'group_id' => $group->id,
                'color' => '#b45309',
            ]);
        }

        $statuses = [
            HomeworkSubmission::STATUS_SUBMITTED,
            HomeworkSubmission::STATUS_NEEDS_REVISION,
            HomeworkSubmission::STATUS_ACCEPTED,
        ];

        foreach ($students->take(3)->values() as $i => $student) {
            HomeworkSubmission::create([
                'user_id' => $student->id,
                'lesson_id' => $lessons[$i % $lessons->count()]->id,
                'course_id' => $course->id,
                'status' => $statuses[$i],
                'last_activity_at' => now()->subDays($i + 1),
            ]);
        }

        Certificate::create([
            'user_id' => $students->first()->id,
            'course_id' => $course->id,
            'student_name' => $students->first()->name,
            'course_title' => $course->title,
            'number' => 'SF-2026-0001',
            'issued_at' => now()->subWeek()->toDateString(),
        ]);

        foreach ([
            ['Бюлер. Руководство по санскриту', 'Георг Бюлер', 1888, 'https://archive.org/details/leitfadenfrdene00bhgoog'],
            ['Кочергина. Санскритско-русский словарь', 'В. А. Кочергина', 1996, 'https://archive.org/details/sanskrit-russian-dictionary'],
        ] as $i => $material) {
            CourseMaterial::create([
                'course_id' => $course->id,
                'title' => $material[0],
                'author' => $material[1],
                'year' => $material[2],
                'source_url' => $material[3],
                'kind' => 'book',
                'is_visible' => true,
                'sort_order' => $i + 1,
            ]);
        }

        $noteType = SrsNoteType::create([
            'key' => 'guide-basic',
            'name' => 'Слово → перевод',
            'language' => 'sa',
            'fields' => ['front', 'back'],
        ]);

        $deck = SrsDeck::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'note_type_id' => $noteType->id,
            'name' => 'Первый год: базовая лексика',
            'slug' => 'first-year-basic',
            'language' => 'sa',
            'visibility' => 'public',
            'description' => 'Слова первых трёх уроков.',
        ]);

        $dictionary = Dictionary::create([
            'name' => 'Учебный словарь курса',
            'description' => 'Слова, разбираемые на занятиях первого года.',
            'is_active' => true,
        ]);

        foreach ([
            ['गुरु', 'guru', 'гуру', 'учитель, наставник'],
            ['शिष्य', 'śiṣya', 'шишья', 'ученик'],
            ['पुस्तक', 'pustaka', 'пустака', 'книга'],
        ] as $row) {
            $word = DictionaryWord::create([
                'dictionary_id' => $dictionary->id,
                'devanagari' => $row[0],
                'iast' => $row[1],
                'cyrillic' => $row[2],
                'translation' => $row[3],
                'is_indexable' => true,
            ]);

            SrsCard::create([
                'deck_id' => $deck->id,
                'fields' => ['front' => $row[1], 'back' => $row[3]],
                'direction' => 'front_back',
                'source_word_id' => $word->id,
            ]);
        }

        return $user;
    }
}
