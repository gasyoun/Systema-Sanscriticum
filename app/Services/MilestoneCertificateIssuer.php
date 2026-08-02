<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateMilestone;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Автовыдача сертификатов по вехам — единственный источник логики «кто получает».
 * Страница/команда/action только вызывают его.
 *
 * Право на сертификат вехи — payment-driven, зеркалит семантику ключей доступа
 * Lesson::unlockingKeys(), но СТРОЖЕ: урок половины блока открывается одним
 * ключом block_N_hH, а для сертификата блок N считается пройденным только при
 * 'full', 'block_N' или ОБЕИХ половинах block_N_h1 + block_N_h2 — половина
 * блока не даёт сертификата за блок. Веха закрыта, когда оплачены все блоки
 * start_block..end_block.
 *
 * Conditional-платежи (is_conditional, доступ «под обещание») уроки открывают,
 * но оплатой не являются → scopeReal() обязателен. Нулевые sibling-платежи
 * access_grant_# от BlockAccessMaterializer — не conditional, поэтому bundles
 * с диапазоном блоков покрываются автоматически.
 */
class MilestoneCertificateIssuer
{
    /**
     * Вехи, «созревшие» на дату: активные, автовыдаваемые (не «Санка»),
     * у которых ends_at блока end_block уже прошёл, но не раньше
     * lookback-окна (certificate_auto_issue_lookback_days) — чтобы включение
     * тумблера не осыпало сертификатами давно закончившиеся потоки.
     */
    public function dueMilestones(?CarbonInterface $today = null): Collection
    {
        $today = $today ?? today();
        $lookbackDays = (int) (MarketingSetting::cached()?->certificate_auto_issue_lookback_days ?? 14);
        $windowStart = $today->copy()->subDays($lookbackDays)->startOfDay();

        return CertificateMilestone::query()
            ->where('is_active', true)
            ->where('auto_issue_enabled', true)
            ->where('template', '!=', 'sanka')
            ->with('course')
            ->get()
            ->filter(function (CertificateMilestone $m) use ($today, $windowStart): bool {
                $endsAt = $m->endBlock()?->ends_at;

                return $endsAt !== null
                    && $endsAt->lt($today->copy()->startOfDay())
                    && $endsAt->gte($windowStart);
            })
            ->values();
    }

    /**
     * Выдать сертификаты по вехе. Возвращает только СОЗДАННЫЕ Certificate
     * (повторный запуск — пустая коллекция). $force (ручной action «выдать
     * сейчас») игнорирует окно дат и isAutoIssuable-гейт по датам, но НЕ
     * оплату, членство и идемпотентность; «Санка» не выдаётся и с force.
     */
    public function issueForMilestone(CertificateMilestone $milestone, bool $force = false): Collection
    {
        if ($milestone->template === 'sanka' || (! $force && ! $milestone->isAutoIssuable())) {
            return collect();
        }

        return $this->eligibleUsers($milestone)
            ->map(fn (User $user): ?Certificate => $this->issueTo($milestone, $user))
            ->filter()
            ->values();
    }

    /**
     * Кому положен сертификат вехи (без учёта уже выданных): активный состав
     * (left_at IS NULL) групп курса в статусе active/archived — итоговая веха
     * срабатывает после конца последнего блока, когда группа уже может быть
     * заархивирована; от раскопок старых потоков защищает lookback-окно в
     * dueMilestones(). forming не участвует. Далее фильтр по оплате блоков.
     */
    public function eligibleUsers(CertificateMilestone $milestone): Collection
    {
        $users = $milestone->course->groups()
            ->whereIn('status', ['active', 'archived'])
            ->get()
            ->flatMap(fn ($group) => $group->activeUsers()->get())
            ->unique('id')
            ->values();

        return $users
            ->filter(fn (User $user): bool => $this->hasPaidMilestone($milestone, $user))
            ->reject(fn (User $user): bool => $this->coveredByManualCertificate($milestone, $user))
            ->values();
    }

    /** Оплачены ли ВСЕ блоки вехи (см. правила ключей в докблоке класса). */
    public function hasPaidMilestone(CertificateMilestone $milestone, User $user): bool
    {
        $keys = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $milestone->course_id)
            ->paid()
            ->real()
            ->pluck('tariff')
            ->filter()
            ->all();

        if (in_array('full', $keys, true)) {
            return true;
        }

        foreach (range($milestone->start_block, $milestone->end_block) as $n) {
            $blockPaid = in_array("block_{$n}", $keys, true)
                || (in_array("block_{$n}_h1", $keys, true) && in_array("block_{$n}_h2", $keys, true));

            if (! $blockPaid) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ручной итоговый сертификат (milestone NULL) уже закрывает курс — если
     * веха покрывает все блоки курса, второй «итоговый» не выдаём. Промежуточные
     * вехи ручной сертификат не блокирует: это другой документ.
     */
    private function coveredByManualCertificate(CertificateMilestone $milestone, User $user): bool
    {
        if (! $this->coversWholeCourse($milestone)) {
            return false;
        }

        return Certificate::query()
            ->where('user_id', $user->id)
            ->where('course_id', $milestone->course_id)
            ->whereNull('certificate_milestone_id')
            ->exists();
    }

    private function coversWholeCourse(CertificateMilestone $milestone): bool
    {
        $numbers = $milestone->course->blocks()->pluck('number');

        return $numbers->isNotEmpty()
            && $milestone->start_block <= $numbers->min()
            && $milestone->end_block >= $numbers->max();
    }

    /** Идемпотентная выдача одному студенту; null — сертификат уже был. */
    private function issueTo(CertificateMilestone $milestone, User $user): ?Certificate
    {
        try {
            $certificate = Certificate::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'course_id' => $milestone->course_id,
                    'certificate_milestone_id' => $milestone->id,
                ],
                [
                    'student_name' => $user->name,
                    'course_title' => $milestone->resolvedCertificateTitle(),
                    'template' => $milestone->template,
                ],
            );
        } catch (QueryException) {
            // Гонка двух процессов: unique-индекс отработал, сертификат уже есть.
            return null;
        }

        return $certificate->wasRecentlyCreated ? $certificate : null;
    }
}
