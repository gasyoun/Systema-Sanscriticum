<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\PublicCabinetGuideController;
use App\Http\Controllers\StudentCabinetGuideController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Анти-стейл гварда кабинетных доков (H6315).
 *
 * Карта кабинета (docs/student-manual.md), публичный гид (STUDENT_CABINET_GUIDE_RU.md
 * через шапку /help/kabinet) и ENV-инвентарь обязаны знать о студенчески-видимой
 * поверхности кабинета столько же, сколько код: пункт меню без якоря в доках,
 * шапка без бот-пути из единственного источника, ENV-строка без прод-пометки
 * или карта старше последнего коммита поверхностей — всё это краснеть.
 *
 * Паттерн — продолжение CuratorAdminGuideCoverageTest (перепись меню) и
 * StudentCabinetGuideCoverageTest (9 сценариев / 7 кадров), не новый фреймворк.
 */
class CabinetDocsFreshnessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Пункт меню студенческого сайдбара (layouts/student.blade.php) → хотя бы один
     * якорь в STUDENT_CABINET_GUIDE_RU.md ∪ student-manual.md. Новый пункт меню
     * без строки в этой карте красит test_every_student_nav_item_has_a_doc_anchor.
     *
     * @var array<string, list<string>>
     */
    private const NAV_ANCHORS = [
        'student.dashboard' => ['сегодня', 'кабинет'],
        'student.calendar' => ['календарь', 'расписание'],
        'student.library' => ['записи'],
        'student.progress' => ['прогресс'],
        'student.access' => ['оплата и доступ', 'мои оплаты', 'мои долги'],
        'student.open-lessons' => ['открытые уроки'],
        'student.help' => ['как пользоваться'],
        'student.programme.hindi' => ['мой хинди'],
        'student.srs' => ['карточки'],
        'student.srs.decks' => ['мои колоды', 'колода'],
        'student.srs.stats' => ['статистика карточек'],
        'student.skill-drills' => ['тренажеры', 'лила'],
        'student.grammar-lab.index' => ['грамматика'],
        'student.visualdcs.hub' => ['visualdcs'],
        'student.messages' => ['сообщения', 'помощь'],
        'student.support.callback' => ['обратный звонок'],
        'student.course' => ['мои курсы', 'мои материалы'],
    ];

    /**
     * Поверхности кабинета, после правки которых карта (docs/student-manual.md)
     * обязана получить новый «Last updated» в тот же проход.
     *
     * @var list<string>
     */
    private const MANUAL_SURFACES = [
        'resources/views/layouts/student.blade.php',
        'resources/views/student',
        'resources/views/help/cabinet-guide.blade.php',
        'resources/views/course-interest',
        'app/Http/Controllers/PublicCabinetGuideController.php',
        'app/Http/Controllers/StudentCabinetGuideController.php',
        'app/Services/Bot/CabinetProvisionBotCommand.php',
    ];

    private const PROD_NOTE_ON = 'вкл. на проде (09-10-2026)';

    private const PROD_NOTE_OFF = 'выкл. на проде (09-10-2026)';

    public function test_student_manual_names_bot_self_service_and_feature_anchors(): void
    {
        $manual = $this->read('docs/student-manual.md');

        foreach (['samskrtamru_bot', '/кабинет', 'H5066', 'H5773', 'H5709'] as $anchor) {
            $this->assertStringContainsString(
                $anchor,
                $manual,
                "Карта кабинета потеряла якорь «{$anchor}»."
            );
        }
    }

    public function test_every_student_nav_item_has_a_doc_anchor(): void
    {
        $layout = $this->read('resources/views/layouts/student.blade.php');

        preg_match_all("/route\('([a-z0-9.\-]+)'/", $layout, $matches);
        $routes = array_values(array_unique(array_filter(
            $matches[1],
            fn (string $route): bool => str_starts_with($route, 'student.')
        )));

        $this->assertGreaterThanOrEqual(
            15,
            count($routes),
            'Перепись меню нашла меньше 15 пунктов — сайдбар переехал из layouts/student.blade.php, обнови источник переписи.'
        );

        $unknown = array_values(array_diff($routes, array_keys(self::NAV_ANCHORS)));

        $this->assertSame(
            [],
            $unknown,
            "Новые пункты студенческого меню без якоря в CabinetDocsFreshnessTest::NAV_ANCHORS:\n  "
                .implode("\n  ", $unknown)
                ."\nДобавь пункт в карту и упомяни его в docs/student-manual.md (раздел «Меню слева»)."
        );

        $union = $this->normalize(
            $this->read(StudentCabinetGuideController::SOURCE)
            .$this->read('docs/student-manual.md')
        );

        $missing = [];

        foreach (self::NAV_ANCHORS as $route => $anchors) {
            if (! in_array($route, $routes, true)) {
                continue;
            }

            $covered = false;

            foreach ($anchors as $anchor) {
                if (str_contains($union, $this->normalize($anchor))) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $missing[] = $route.' → ['.implode('|', $anchors).']';
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Пункты студенческого меню без якоря в докaх (гид + карта кабинета):\n  "
                .implode("\n  ", $missing)
        );
    }

    public function test_public_guide_header_pulls_bot_path_from_the_guide_source(): void
    {
        $blade = $this->read('resources/views/help/cabinet-guide.blade.php');

        $this->assertStringContainsString(
            '$botPathLine',
            $blade,
            'Шапка публичного гида не тянет бот-путь из гида.'
        );

        $this->assertStringNotContainsString(
            'samskrtamru_bot',
            $blade,
            'Шапка затвердела собственную копию бот-пути — единый источник STUDENT_CABINET_GUIDE_RU.md.'
        );

        $controller = $this->read('app/Http/Controllers/PublicCabinetGuideController.php');

        $this->assertStringContainsString(
            'Кабинета нет, хочу без сайта и без куратора',
            $controller,
            'Контроллер потерял якорь FAQ-строки — шапка перестала быть single-source.'
        );

        $line = (new PublicCabinetGuideController)->botPathLine();

        $this->assertStringContainsString('samskrtamru_bot', $line, 'FAQ-строка про «Кабинета нет» пропала из гида.');
        $this->assertStringContainsString('/кабинет', $line, 'В бот-строке шапки нет команды /кабинет.');

        $this->get(route('help.cabinet-guide'))
            ->assertOk()
            ->assertSee('samskrtamru_bot', false);
    }

    public function test_env_inventory_rows_carry_prod_notes_and_survive_regen(): void
    {
        $inventory = $this->read('docs/ENVIRONMENT_VARIABLES.md');

        foreach (['TELEGRAM_CABINET_PROVISION', 'TELEGRAM_CABINET_LOGIN'] as $key) {
            $this->assertMatchesRegularExpression(
                '#\| `'.$key.'` \|[^\n]*'.preg_quote(self::PROD_NOTE_ON, '#').'#u',
                $inventory,
                "ENV-строка {$key} без прод-пометки «".self::PROD_NOTE_ON.'».'
            );
        }

        $this->assertMatchesRegularExpression(
            '#\| `TELEGRAM_CABINET_EMAIL_LINK` \|[^\n]*'.preg_quote(self::PROD_NOTE_OFF, '#').'#u',
            $inventory,
            'ENV-строка TELEGRAM_CABINET_EMAIL_LINK без прод-пометки «'.self::PROD_NOTE_OFF.'».'
        );

        $generator = $this->read('scripts/generate_env_inventory.php');

        $this->assertStringContainsString(
            '$prodNotes',
            $generator,
            'Прод-пометки живут только в регеняемом md — следующий прогон генератора их сотрёт. Источник: $prodNotes в scripts/generate_env_inventory.php.'
        );

        foreach ([self::PROD_NOTE_ON, self::PROD_NOTE_OFF] as $note) {
            $this->assertStringContainsString($note, $generator, 'В генераторе нет пометки «'.$note.'».');
        }
    }

    public function test_manual_last_updated_is_not_older_than_doc_surfaces(): void
    {
        $manual = $this->read('docs/student-manual.md');

        $this->assertMatchesRegularExpression(
            '/Last updated:\s*(\d{2})-(\d{2})-(\d{4})_/u',
            $manual,
            'У карты кабинета нет даты «Last updated: DD-MM-YYYY_».'
        );

        preg_match('/Last updated:\s*(\d{2})-(\d{2})-(\d{4})_/u', $manual, $m);
        $docDate = $m[3].'-'.$m[2].'-'.$m[1];

        $escaped = implode(' ', array_map('escapeshellarg', self::MANUAL_SURFACES));
        $command = 'cd '.escapeshellarg(base_path())
            ." && git log -1 --date=short --format=%cd -- {$escaped} 2>/dev/null";

        exec($command, $output, $exit);

        if ($exit !== 0 || $output === [] || trim((string) $output[0]) === '') {
            $this->markTestSkipped('git-история поверхностей недоступна (мелкий клон без истории) — проверка свежести пропущена.');
        }

        $lastSurfaceCommit = trim((string) $output[0]);

        $this->assertLessThanOrEqual(
            $docDate,
            $lastSurfaceCommit,
            "Поверхности кабинета правились {$lastSurfaceCommit}, а карта (docs/student-manual.md) стоит на {$docDate}. "
                .'Обнови карту в том же коммите (разделы: вход/бот, заявки на курс, меню слева).'
        );
    }

    private function read(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path, 'Файл кабинетных доков отсутствует: '.$relative);

        return (string) file_get_contents($path);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(['ё', '—', '–', '−'], ['е', '-', '-', '-'], $value);
        $value = preg_replace('/[«»"“”\'`]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
