<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LessonPackRepository;
use App\Support\ServerGuards\GuardFinding;
use App\Support\ServerGuards\GuardSpec;
use App\Support\ServerGuards\LywPacksGuard;
use App\Support\ServerGuards\ServerGuardsAuditor;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeSystemInspector;
use Tests\TestCase;

/**
 * H7069: конфиг говорит «LYW включён», а пака включённой группы нет — фича
 * отдаёт 404 на каждом уроке. Стража обязана назвать группу и путь, а не
 * промолчать; выключенный рубильник и отклонённая голосованием группа —
 * наоборот, не тревога.
 */
class LywPacksGuardTest extends TestCase
{
    private string $packs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packs = sys_get_temp_dir().'/lyw-guard-'.uniqid('', true);
        File::copyDirectory(base_path('tests/fixtures/lesson_packs'), $this->packs);

        config([
            'lyw.enabled' => true,
            'lyw.packs_path' => $this->packs,
            'lyw.enabled_groups' => [
                'base' => true,
                'nol-yoga' => true,
                'nol-ayurveda' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->packs);

        parent::tearDown();
    }

    private function findings(): array
    {
        return (new LywPacksGuard(app(LessonPackRepository::class)))->findings();
    }

    private function guardMessages(array $findings): string
    {
        return implode("\n", array_map(
            static fn (GuardFinding $f): string => $f->guard.': '.$f->message,
            $findings,
        ));
    }

    public function test_a_complete_tree_of_enabled_packs_is_not_a_finding(): void
    {
        $this->assertSame([], $this->findings());
    }

    public function test_master_switch_off_is_not_a_finding_even_with_an_empty_tree(): void
    {
        File::deleteDirectory($this->packs);
        config(['lyw.enabled' => false]);

        $this->assertSame([], $this->findings());
    }

    public function test_a_voted_down_group_is_not_a_finding(): void
    {
        // prodolzhayushchiy-kino лежит в фикстуре, но голосование 14-10 его
        // отклонило: его 404 — решение, а не авария, и тревожить им нельзя.
        config(['lyw.enabled_groups' => ['base' => true, 'prodolzhayushchiy-kino' => false]]);

        $this->assertSame([], $this->findings());
    }

    public function test_a_missing_manifest_names_the_group_and_the_expected_path(): void
    {
        File::delete($this->packs.'/zan1/nol/yoga/manifest.json');

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame(GuardFinding::CRITICAL, $findings[0]->severity);
        $this->assertSame(LywPacksGuard::GUARD, $findings[0]->guard);
        $this->assertStringContainsString('nol-yoga', $findings[0]->message);
        $this->assertStringContainsString('zan1/nol/yoga/manifest.json', $findings[0]->message);
        $this->assertStringContainsString('lesson-packs-sync.sh', $findings[0]->message);
    }

    public function test_an_emptied_packs_path_is_critical_for_every_enabled_group(): void
    {
        File::deleteDirectory($this->packs);
        File::makeDirectory($this->packs, 0775, true);

        $messages = $this->guardMessages($this->findings());

        $this->assertStringContainsString('base', $messages);
        $this->assertStringContainsString('nol-yoga', $messages);
        $this->assertStringContainsString('nol-ayurveda', $messages);
    }

    public function test_a_pack_that_does_not_load_is_critical_not_a_pass(): void
    {
        // Манифест на месте, но схема чужая: LessonPackRepository::load()
        // вернёт null, и студент получит 404. «Файл есть» — не доказательство.
        file_put_contents(
            $this->packs.'/zan1/base/manifest.json',
            (string) json_encode(['schema' => 'lyw-pack-v2', 'zan' => 1]),
        );

        $messages = $this->guardMessages($this->findings());

        $this->assertStringContainsString('не читается', $messages);
        $this->assertStringContainsString('«base»', $messages);
    }

    public function test_a_typo_in_the_group_key_is_named(): void
    {
        config(['lyw.enabled_groups' => ['base' => true, 'nol-yogaa' => true]]);

        $messages = $this->guardMessages($this->findings());

        $this->assertStringContainsString('неизвестная группа «nol-yogaa»', $messages);
    }

    public function test_guards_verify_surfaces_the_finding_as_blocking(): void
    {
        // Не только класс: находка обязана доехать до аудитора, который кормит
        // guards:verify и cabinet:probe, иначе она не стража, а библиотека.
        File::delete($this->packs.'/zan1/base/manifest.json');

        $templateRoot = base_path('scripts/server_guards');
        $spec = GuardSpec::fromFile(base_path('scripts/server_guards.conf'));
        $auditor = new ServerGuardsAuditor(
            $spec,
            FakeSystemInspector::healthy(
                $spec,
                $templateRoot,
                (string) file_get_contents($templateRoot.'/manifest.psv'),
            ),
            $templateRoot,
        );

        $findings = $auditor->audit();
        $lyw = array_values(array_filter(
            $findings,
            static fn (GuardFinding $f): bool => $f->guard === LywPacksGuard::GUARD,
        ));

        $this->assertNotEmpty($lyw, 'аудитор не увидел пропажу пака LYW');
        $this->assertSame(GuardFinding::CRITICAL, $lyw[0]->severity);
        $this->assertTrue(ServerGuardsAuditor::hasBlocking($findings));
    }
}
