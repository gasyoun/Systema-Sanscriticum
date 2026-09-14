<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\Course;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H3914: отчёт «невыданные дипломы/сертификаты» (категория F, «кому какие
 * дипломы не выданы») — только чтение, ни certificates, ни users не пишутся.
 *
 * Строка отчёта = (студент × веха × итерация), для которой документ ДОЛЖЕН был
 * быть выдан, но его нет: веха активна и автовыдаваема, триггер уже созрел
 * (блок закончился / занятия состоялись), студент проходит фильтр права
 * {@see MilestoneCertificateIssuer::eligibleUsers()} (активный состав группы,
 * оплата блоков, баллы «Санки», ручной итоговый сертификат), а строки
 * certificates с ключом (user_id, certificate_milestone_id, occurrence) ещё
 * нет — тот же ключ идемпотентности, что у {@see MilestoneCertificateIssuer}.
 *
 * Два НАМЕРЕННЫХ отличия от логики автовыдачи:
 *  - lookback-окно (certificate_auto_issue_lookback_days) игнорируется:
 *    куратору нужна полная картина «кому не выдано», а не только последние
 *    14 дней. Окно существует, чтобы включение тумблера не осыпало документами
 *    давно закончившиеся потоки; у отчёта такой риск отсутствует — он ничего
 *    не выдаёт;
 *  - глобальный тумблер certificate_auto_issue_enabled не проверяется: даже
 *    при выключенной автоматике документ числится положенным — выдача руками
 *    через веху/карточку курса остаётся зоной куратора.
 *
 * Техника запроса — та же, что у {@see DebtorsReport::query()}: пары
 * вычисляются в PHP (переиспользуется вся логика права из Issuer), затем
 * инлайнятся в SQL через UNION ALL-подзапрос и joinSub к users, поэтому
 * поиск/сортировки/экспорт Filament работают как над обычным Eloquent-запросом.
 */
class UnissuedCertificatesReport
{
    /**
     * Вычисленные строки отчёта. Ключ уникальности — «user:milestone:occurrence»:
     * студент, активный в двух группах одного курса, не должен дублироваться.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tuples(): Collection
    {
        $issuer = app(MilestoneCertificateIssuer::class);
        $today = today();

        $seen = [];
        $rows = collect();

        foreach ($issuer->dueTargets($today, ignoreLookback: true) as $target) {
            $milestone = $target->milestone;
            $users = $issuer->eligibleUsers($milestone, $target->group, $target->occurrence);

            if ($users->isEmpty()) {
                continue;
            }

            $issuedUserIds = Certificate::query()
                ->where('certificate_milestone_id', $milestone->id)
                ->where('occurrence', $target->occurrence)
                ->whereIn('user_id', $users->pluck('id')->all())
                ->pluck('user_id')
                ->flip();

            $triggerDate = $this->triggerDate($issuer, $target, $today);
            $groupName = $target->group?->name;
            $groupId = $target->group?->id;

            foreach ($users as $user) {
                if ($issuedUserIds->has($user->id)) {
                    continue;
                }

                $key = $user->id.':'.$milestone->id.':'.$target->occurrence;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $rows->push([
                    'user_id' => (int) $user->id,
                    'milestone_id' => (int) $milestone->id,
                    'occurrence' => $target->occurrence,
                    'course_id' => (int) $milestone->course_id,
                    'document_type' => (string) $milestone->document_type,
                    'milestone_title' => (string) $milestone->title,
                    'group_id' => $groupId,
                    'group_name' => $groupName,
                    'trigger_date' => $triggerDate?->toDateString(),
                ]);
            }
        }

        return $rows;
    }

    /**
     * Eloquent-запрос строк отчёта: users, обогащённые колонками пары
     * d.* (milestone_id, occurrence, course_id, document_type,
     * milestone_title, group_id, group_name, trigger_date). Имён этих колонок
     * в users нет — коллизий с users.* не возникает.
     */
    public function query(): Builder
    {
        $tuples = $this->tuples();

        if ($tuples->isEmpty()) {
            return User::query()->whereRaw('1 = 0');
        }

        [$sql, $bindings] = $this->buildTuplesInlineSql($tuples);
        $sub = DB::query()->fromRaw('('.$sql.') AS d', $bindings);

        return User::query()
            ->joinSub($sub, 'd', 'd.user_id', '=', 'users.id')
            ->select([
                'users.*',
                'd.milestone_id',
                'd.occurrence',
                'd.course_id',
                'd.document_type',
                'd.milestone_title',
                'd.group_id',
                'd.group_name',
                'd.trigger_date',
            ]);
    }

    /**
     * Названия курсов строк отчёта — для Filament-колонки и экспорта.
     *
     * @return array<int, string>
     */
    public function courseTitles(): array
    {
        $courseIds = $this->tuples()->pluck('course_id')->unique()->values()->all();

        if ($courseIds === []) {
            return [];
        }

        return Course::query()->whereIn('id', $courseIds)->pluck('title', 'id')->all();
    }

    /**
     * Дата созревания триггера: у block-вех — конец end_block, у lesson-триггеров —
     * lesson_date последнего занятия окна итерации.
     */
    private function triggerDate(
        MilestoneCertificateIssuer $issuer,
        MilestoneIssueTarget $target,
        CarbonInterface $today,
    ): ?CarbonInterface {
        if ($target->milestone->trigger_type === CertificateMilestone::TRIGGER_BLOCK) {
            return $target->milestone->endBlock()?->ends_at;
        }

        if ($target->group === null) {
            return null;
        }

        return $issuer->readyOccurrences($target->milestone, $target->group, $today)[$target->occurrence] ?? null;
    }

    /**
     * Инлайн-SQL вычисленных пар — та же техника literal-SELECT'ов, что
     * DebtorsReport::buildReferenceInlineSql(): работает одинаково на sqlite
     * (тесты) и MySQL (прод).
     *
     * @param  Collection<int, array<string, mixed>>  $tuples
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildTuplesInlineSql(Collection $tuples): array
    {
        $parts = [];
        $bindings = [];
        $first = true;

        foreach ($tuples as $t) {
            $parts[] = $first
                ? 'SELECT ? AS user_id, ? AS milestone_id, ? AS occurrence, ? AS course_id, ? AS document_type, ? AS milestone_title, ? AS group_id, ? AS group_name, ? AS trigger_date'
                : 'SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?';

            array_push(
                $bindings,
                (int) $t['user_id'],
                (int) $t['milestone_id'],
                (int) $t['occurrence'],
                (int) $t['course_id'],
                (string) $t['document_type'],
                (string) $t['milestone_title'],
                $t['group_id'],
                $t['group_name'],
                $t['trigger_date'],
            );

            $first = false;
        }

        return [implode(' UNION ALL ', $parts), $bindings];
    }
}
