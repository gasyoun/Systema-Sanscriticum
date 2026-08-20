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
 * Перепись навигации куратора (роль manager) (H3213, волна 2).
 *
 * Образец — teacher:nav-census: пользователь в памяти, прод не пишем.
 */
class ManagerNavCensus extends Command
{
    protected $signature = 'manager:nav-census
        {--user= : ID существующего пользователя вместо фикстуры в памяти (только чтение)}
        {--panel=admin : Панель Filament}
        {--output= : Куда писать JSON (по умолчанию docs/generated/manager_nav_census.json)}
        {--json : Печатать JSON в stdout вместо сводки}';

    protected $description = 'Перепись разделов панели, видимых куратору (manager)';

    public function handle(): int
    {
        $panelId = (string) $this->option('panel');

        try {
            $panel = Filament::getPanel($panelId);
        } catch (Throwable $e) {
            $this->error("Панель «{$panelId}» не найдена: ".$e->getMessage());

            return self::FAILURE;
        }

        $user = $this->resolveManager();

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
            'manager' => [
                'source' => $this->option('user') ? 'db' : 'fixture',
                'role' => $user->role,
            ],
            'total' => count($items),
            'by_group' => $this->countByGroup($items),
            'items' => $items,
            'widgets' => [
                'dashboard_reachable' => $dashboardReachable,
                'note' => $dashboardReachable
                    ? 'Главная доступна: карточки ниже куратор видит.'
                    : 'Главная куратору недоступна — карточки ниже не отображаются, хотя их собственный гейт пропускает.',
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

        file_put_contents($path, $json."\n");

        if (! $this->option('json')) {
            $this->summary($payload, $path);
        }

        return self::SUCCESS;
    }

    private function resolveManager(): ?User
    {
        $id = $this->option('user');

        if ($id !== null && $id !== '') {
            $user = User::find($id);

            if ($user === null) {
                $this->error("Пользователь #{$id} не найден.");

                return null;
            }

            if (! $user->isManager()) {
                $this->warn("Пользователь #{$id} не куратор — перепись покажет ЕГО видимость, не кураторскую.");
            }

            return $user;
        }

        $user = new User;
        $user->id = PHP_INT_MAX - 1;
        $user->name = 'Фикстура: куратор';
        $user->email = 'manager-nav-census@example.invalid';
        $user->role = Roles::MANAGER;
        $user->teacher_id = null;
        $user->exists = true;

        return $user;
    }

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
            try {
                $reflection = new \ReflectionClass($class);
                $heading = $reflection->getDefaultProperties()['heading'] ?? null;

                if (is_string($heading) && $heading !== '') {
                    return $heading;
                }
            } catch (Throwable) {
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

        return base_path('docs/generated/manager_nav_census.json');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function summary(array $payload, string $path): void
    {
        $this->info('Перепись навигации куратора');
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
