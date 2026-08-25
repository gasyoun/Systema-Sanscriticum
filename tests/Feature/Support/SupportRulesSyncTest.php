<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportTopicRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * H3529 (self-serve волна 1, шаг 5): контракт artisan support:rules-sync.
 *
 * Источник — vendored снапшот tools/message-intent-classifier/rules/v1/*.json
 * (прекомпилированные близнецы *.yaml; symfony/yaml в проде отсутствует).
 * Ключ идемпотентности — plane + category + pattern_hash; исчезнувшие правила
 * выключаются, не удаляются; легаси-строки (pattern_hash NULL) не трогаются.
 */
class SupportRulesSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Число правил в vendored JSON — тот же файл, что читает команда.
     */
    private function expectedRuleCount(): int
    {
        $files = File::glob(base_path('tools/message-intent-classifier/rules/v1/*.json'));

        $this->assertNotEmpty($files, 'vendored rules/v1/*.json missing — vendor step broken');

        return collect($files)
            ->sum(fn (string $path): int => count(json_decode((string) file_get_contents($path), true)['rules'] ?? []));
    }

    public function test_sync_seeds_all_vendored_rules_and_is_idempotent(): void
    {
        $legacy = SupportTopicRule::create([
            'category' => 'payment_billing_legacy',
            'keywords' => ['оплат'],
            'priority' => 5,
            'is_enabled' => true,
        ]);

        $this->artisan('support:rules-sync')->assertExitCode(0);

        $expected = $this->expectedRuleCount();

        $this->assertSame(
            $expected,
            SupportTopicRule::query()->whereNotNull('pattern_hash')->count(),
            'synced rule count must equal vendored YAML rule count',
        );

        // Легаси-строка жива и включена: sync её не касается.
        $this->assertDatabaseHas('support_topic_rules', [
            'id' => $legacy->id,
            'pattern_hash' => null,
            'is_enabled' => true,
        ]);

        // Второй прогон — пустой diff, ничего не задвоено.
        $this->artisan('support:rules-sync')
            ->expectsOutputToContain('created=0 updated=0 disabled=0')
            ->assertExitCode(0);

        $this->assertSame(
            $expected + 1,
            SupportTopicRule::query()->count(),
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('support:rules-sync', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(0);

        $this->assertSame(0, SupportTopicRule::query()->count());
    }

    public function test_dry_run_after_real_run_diff_is_empty(): void
    {
        $this->artisan('support:rules-sync')->assertExitCode(0);

        // Приёмка H3529: «second consecutive dry-run diff empty».
        $this->artisan('support:rules-sync', ['--dry-run' => true])
            ->expectsOutputToContain('created=0 updated=0 disabled=0')
            ->assertExitCode(0);
    }

    public function test_vanished_rule_is_disabled_not_deleted(): void
    {
        SupportTopicRule::create([
            'plane' => 'topic',
            'category' => 'ghost_category',
            'pattern_hash' => str_repeat('a', 64),
            'keywords' => ['призрак'],
            'negations' => [],
            'priority' => 10,
            'is_enabled' => true,
        ]);

        $this->artisan('support:rules-sync')->assertExitCode(0);

        $this->assertDatabaseHas('support_topic_rules', [
            'category' => 'ghost_category',
            'is_enabled' => false,
        ]);

        // Уже выключенное исчезнувшее правило — не «изменение»: второй прогон пуст.
        $this->artisan('support:rules-sync')
            ->expectsOutputToContain('created=0 updated=0 disabled=0')
            ->assertExitCode(0);
    }

    public function test_pattern_change_creates_new_row_and_disables_stale_one(): void
    {
        $this->artisan('support:rules-sync')->assertExitCode(0);

        $first = SupportTopicRule::query()
            ->whereNotNull('pattern_hash')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($first);

        // Эмулируем изменение паттернов правила в пакете: правим JSON в копии
        // правил? НЕТ — vendored дерево неприкосновенно. Вместо этого сдвигаем
        // строку БД на «старый» хэш и проверяем, что sync создаст новую строку
        // под актуальный хэш, а чужой ключ выключит.
        $staleHash = str_repeat('b', 64);
        $first->update(['pattern_hash' => $staleHash]);

        $this->artisan('support:rules-sync')->assertExitCode(0);

        // Актуальный набор восстановлен полностью…
        $this->assertSame(
            $this->expectedRuleCount(),
            SupportTopicRule::query()->whereNotNull('pattern_hash')->where('is_enabled', true)->count(),
        );
        // …а строка с несуществующим ключом выключена, но жива.
        $this->assertDatabaseHas('support_topic_rules', [
            'pattern_hash' => $staleHash,
            'is_enabled' => false,
        ]);
    }
}
