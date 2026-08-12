<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Перепись навигации преподавателя (H2501, волна 1).
 *
 * Отвечает механически на вопрос «что вообще видит преподаватель в /admin»:
 * проходит зарегистрированные ресурсы, страницы и виджеты панели и спрашивает
 * у каждого его собственный гейт от имени пользователя-преподавателя.
 *
 * Результат работает в двух местах: тест покрытия сверяет его с оглавлением
 * руководства, а человек читает JSON как доказательную часть находки о
 * перенасыщенности меню.
 *
 * Прод не трогаем: по умолчанию пользователь собирается В ПАМЯТИ
 * (Auth::setUser, ни одной записи в БД). Флаг --user=<id> позволяет свериться
 * с настоящим аккаунтом — только чтение.
 */
class TeacherNavCensus extends Command
{
    protected $signature = 'teacher:nav-census
        {--user= : ID существующего пользователя вместо фикстуры в памяти (только чтение)}
        {--panel=admin : Панель Filament}
        {--output= : Куда писать JSON (по умолчанию docs/generated/teacher_nav_census.json)}
        {--json : Печатать JSON в stdout вместо сводки}';

    protected $description = 'Перепись разделов панели, видимых преподавателю (JSON + сводка)';

    public function handle(): int
    {
        $panelId = (string) $this->option('panel');

        try {
            $panel = Filament::getPanel($panelId);
        } catch (Throwable $e) {
            $this->error("Панель «{$panelId}» не найдена: ".$e->getMessage());

            return self::FAILURE;
        }

        $user = $this->resolveTeacher();

        if ($user === null) {
            return self::FAILURE;
        }

        Auth::setUser($user);
        Filament::setCurrentPanel($panel);
        Filament::setTenant(null, true);

        $items = array_merge(
            $this->collect($panel->getResources(), 'resource'),
            $this->collect($panel->getPages(), 'page'),
        );

        usort($items, static function (array $a, array $b): int {
            return [$a['group'], $a['label']] <=> [$b['group'], $b['label']];
        });

        $widgets = $this->collect($panel->getWidgets(), 'widget');
        $dashboardReachable = $this->dashboardReachable($panel);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'panel' => $panelId,
            'teacher' => [
                'source' => $this->option('user') ? 'db' : 'fixture',
                'role' => $user->role,
                'has_teacher_id' => $user->teacher_id !== null,
            ],
            'total' => count($items),
            'by_group' => $this->countByGroup($items),
            'items' => $items,
            // Виджеты живут ОТДЕЛЬНО от пунктов меню и не входят в items
            // сознательно. Они рисуются только на главной панели, а
            // преподавателя с главной уводит редирект — то есть собственный
            // гейт виджета «видно» ещё не означает, что преподаватель до него
            // доходит. Сложи их в один список — и оглавление руководства
            // получило бы разделы, которых человек не видит.
            'widgets' => [
                'dashboard_reachable' => $dashboardReachable,
                'note' => $dashboardReachable
                    ? 'Главная доступна: карточки ниже преподаватель видит.'
                    : 'Главная преподавателю недоступна (пункт меню скрыт либо срабатывает переадресация) — карточки ниже не отображаются, хотя их собственный гейт пропускает.',
                'total' => count($widgets),
                'items' => $widgets,
            ],
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->option('json')) {
            $this->line((string) $json);
        }

        $path = $this->outputPath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        // Перевод строки жёстко "\n", а не PHP_EOL: файл коммитится, и на Windows
        // PHP_EOL дал бы CRLF-блоб — ровно тот мусор, который потом не вычистить.
        file_put_contents($path, $json."\n");

        if (! $this->option('json')) {
            $this->summary($payload, $path);
        }

        return self::SUCCESS;
    }

    /**
     * Пользователь-преподаватель. По умолчанию — в памяти, без записи в БД.
     */
    private function resolveTeacher(): ?User
    {
        $id = $this->option('user');

        if ($id !== null && $id !== '') {
            $user = User::find($id);

            if ($user === null) {
                $this->error("Пользователь #{$id} не найден.");

                return null;
            }

            if (! $user->isTeacher() && $user->teacher_id === null) {
                $this->warn("Пользователь #{$id} не преподаватель — перепись покажет ЕГО видимость, не преподавательскую.");
            }

            return $user;
        }

        $user = new User;
        $user->id = PHP_INT_MAX;          // заведомо несуществующий — связи вернут пусто, записи нет
        $user->name = 'Фикстура: преподаватель';
        $user->email = 'teacher-nav-census@example.invalid';
        $user->role = Roles::TEACHER;
        $user->teacher_id = PHP_INT_MAX;
        $user->exists = true;             // гейты, вызывающие ->exists, не должны считать его гостем

        return $user;
    }

    /**
     * Доходит ли пользователь до главной панели, где и живут виджеты.
     *
     * Мало спросить canAccess(): главная у преподавателя проходит по роуту, но
     * уводит его переадресацией в рабочий раздел. Единственный устойчивый
     * признак, не требующий рендера страницы, — скрыт ли пункт «главная» в
     * меню: он и переадресация выключаются одним и тем же условием.
     */
    private function dashboardReachable(mixed $panel): bool
    {
        foreach ($panel->getPages() as $class) {
            if (! is_subclass_of($class, Dashboard::class) && ! is_a($class, Dashboard::class, true)) {
                continue;
            }

            try {
                if (method_exists($class, 'canAccess') && ! $class::canAccess()) {
                    return false;
                }

                if (method_exists($class, 'shouldRegisterNavigation') && ! $class::shouldRegisterNavigation()) {
                    return false;
                }

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param  array<int, class-string>  $classes
     * @return array<int, array<string, mixed>>
     */
    private function collect(array $classes, string $kind): array
    {
        $items = [];

        foreach ($classes as $class) {
            if (! $this->isVisible($class, $kind)) {
                continue;
            }

            $items[] = [
                'kind' => $kind,
                'group' => $this->group($class, $kind),
                'label' => $this->label($class, $kind),
                'class' => $class,
                'url' => $this->url($class, $kind),
            ];
        }

        return $items;
    }

    /**
     * @param  class-string  $class
     */
    private function isVisible(string $class, string $kind): bool
    {
        try {
            if ($kind === 'widget') {
                return method_exists($class, 'canView') ? (bool) $class::canView() : true;
            }

            if (method_exists($class, 'canAccess') && ! $class::canAccess()) {
                return false;
            }

            if (method_exists($class, 'canViewAny') && ! $class::canViewAny()) {
                return false;
            }

            if (method_exists($class, 'shouldRegisterNavigation') && ! $class::shouldRegisterNavigation()) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            // Гейт, упавший на фикстуре без связей, — не «видно». Сообщаем, но не роняем перепись.
            $this->warn("Гейт {$class} упал: ".$e->getMessage());

            return false;
        }
    }

    /**
     * @param  class-string  $class
     */
    private function group(string $class, string $kind): string
    {
        if ($kind === 'widget') {
            return 'Виджеты';
        }

        try {
            $group = method_exists($class, 'getNavigationGroup') ? $class::getNavigationGroup() : null;
        } catch (Throwable) {
            $group = null;
        }

        return (string) ($group ?: 'Без группы');
    }

    /**
     * @param  class-string  $class
     */
    private function label(string $class, string $kind): string
    {
        if ($kind === 'widget') {
            // У виджета человеческого имени может не быть вовсе — тогда честнее
            // показать короткое имя класса, чем выдумать заголовок.
            try {
                $reflection = new \ReflectionClass($class);
                $heading = $reflection->getDefaultProperties()['heading'] ?? null;

                if (is_string($heading) && $heading !== '') {
                    return $heading;
                }
            } catch (Throwable) {
                // падаем в короткое имя класса ниже
            }

            return class_basename($class);
        }

        try {
            if (method_exists($class, 'getNavigationLabel')) {
                $label = (string) $class::getNavigationLabel();

                if ($label !== '') {
                    return $label;
                }
            }
        } catch (Throwable) {
            // падаем в короткое имя класса ниже
        }

        return class_basename($class);
    }

    /**
     * @param  class-string  $class
     */
    private function url(string $class, string $kind): ?string
    {
        if ($kind === 'widget') {
            return null;
        }

        try {
            return method_exists($class, 'getUrl') ? (string) $class::getUrl() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function countByGroup(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            $group = (string) $item['group'];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function outputPath(): string
    {
        $option = $this->option('output');

        if ($option !== null && $option !== '') {
            return (string) $option;
        }

        return base_path('docs/generated/teacher_nav_census.json');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summary(array $payload, string $path): void
    {
        $this->info('Перепись навигации преподавателя');
        $this->line('  пунктов меню видно: '.$payload['total']);
        $this->newLine();

        $rows = [];

        foreach ($payload['by_group'] as $group => $count) {
            $rows[] = [$group, $count];
        }

        $this->table(['Группа', 'Разделов'], $rows);

        $widgets = $payload['widgets'];
        $this->line('  карточек главной проходят свой гейт: '.$widgets['total']);
        $this->line('  '.$widgets['note']);
        $this->newLine();
        $this->line('JSON: '.$path);
    }
}
