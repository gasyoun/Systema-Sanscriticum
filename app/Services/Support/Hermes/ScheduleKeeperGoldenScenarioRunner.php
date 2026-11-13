<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Support\ScheduleKeeper\ScheduleKeeperService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hermes W1 golden-set runner для роли 23 `schedule-keeper` (H6181, пакет
 * hermes-24-01a10bca). Материализует СИНТЕТИЧЕСКИЙ сценарий из fixture
 * (H6181-паттерн, см. tests/Feature/Support/ScheduleKeeperTest.php) в
 * тестовую БД и прогоняет живой ScheduleKeeperService (H6187), нормализуя
 * наблюдение к формату expected-outcome из golden-фикстуры.
 *
 * Инварианты:
 *  - только чтение: no_side_effects считается по снапшоту canonical-таблиц
 *    (schedules/groups/group_user) до/после вызова — роль не имеет write-пути,
 *    и golden case категории `forbidden` доказывает это на каждой прогоне;
 *  - никаких ученических диалогов: вход — машинно-читаемый сценарий,
 *    авторизованный в H6181, не продовая переписка.
 */
final class ScheduleKeeperGoldenScenarioRunner
{
    /**
     * @param  array<string, mixed>  $input  сценарий из fixture (now, user_timezone, group, lessons, …)
     * @return array{outcome: string, ok: bool, requires_human_verification: bool, verification_reason: string|null, draft: string|null, no_side_effects: bool}
     */
    public function __invoke(array $input): array
    {
        Carbon::setTestNow(Carbon::parse((string) $input['now'], config('app.timezone')));

        $userAttrs = [];
        if (! empty($input['user_timezone'])) {
            $userAttrs['timezone'] = (string) $input['user_timezone'];
        }

        $user = User::factory()->create($userAttrs);
        $groupAttrs = [
            'name' => (string) ($input['group']['name'] ?? 'Бхагавадгита-1'),
            // Слаг уникален на каждый материалайз: все golden-сценарии носят
            // одно синтетическое имя группы, а groups.slug — UNIQUE.
            'slug' => 'h6181-golden-'.uniqid('', false),
            'is_on_vacation' => (bool) ($input['group']['is_on_vacation'] ?? false),
            'vacation_resume_date' => $input['group']['vacation_resume_date'] ?? null,
        ];
        $group = Group::create($groupAttrs);
        $user->groups()->attach($group->id);
        $user = $user->fresh();

        foreach ($input['lessons'] ?? [] as $lessonSpec) {
            $lesson = Schedule::create([
                'title' => (string) ($lessonSpec['title'] ?? ''),
                'group_id' => $group->id,
                'start' => (string) $lessonSpec['start'],
            ]);

            // created_at/updated_at Eloquent перезатирает при save() — правим напрямую.
            DB::table('schedules')->where('id', $lesson->id)->update([
                'created_at' => (string) ($lessonSpec['created_at'] ?? $lessonSpec['start']),
                'updated_at' => (string) ($lessonSpec['updated_at'] ?? $lessonSpec['created_at'] ?? $lessonSpec['start']),
            ]);

            if (! empty($lessonSpec['deleted'])) {
                $lesson->delete(); // отмена = soft delete (H3790)
            }
        }

        $before = $this->snapshot();

        /** @var array<string, mixed> $result */
        $result = app(ScheduleKeeperService::class)->answerFor($user);

        return [
            'outcome' => $this->outcomeOf($result),
            'ok' => (bool) ($result['ok'] ?? false),
            'requires_human_verification' => (bool) ($result['requires_human_verification'] ?? false),
            'verification_reason' => isset($result['verification_reason']) ? ($result['verification_reason'] !== null ? (string) $result['verification_reason'] : null) : null,
            'draft' => isset($result['draft']) ? (string) $result['draft'] : null,
            'no_side_effects' => $before === $this->snapshot(),
        ];
    }

    /** @param array<string, mixed> $result */
    private function outcomeOf(array $result): string
    {
        if (! ($result['ok'] ?? false)) {
            return 'refused';
        }

        return ($result['requires_human_verification'] ?? false) ? 'requires_human' : 'drafted';
    }

    /** Снапшот canonical-таблиц: любое несовпадение после прогона = side-effect. */
    private function snapshot(): array
    {
        return [
            'schedules' => DB::table('schedules')->count(),
            'schedules_updated_at' => DB::table('schedules')->max('updated_at'),
            'groups' => DB::table('groups')->count(),
            'memberships' => DB::table('group_user')->count(),
        ];
    }
}
