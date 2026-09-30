<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Course;
use App\Models\User;
use App\Services\DebtPaymentResolver;
use App\Services\StudentDebtsService;

/**
 * Ссылка «куда платить» для ответов поддержки (бот лички + хелпдеск), 30-09-2026.
 *
 * Шаблон «D2 — куда оплатить» рендерился без курса: студентке, спросившей
 * «как оплатить 66 Лейтана», уходило «Оплатить курс «»… /login». Здесь из
 * долгов студента выбирается курс и, если можно, конкретный блок:
 *  - номер из сообщения есть в долге → штатный чекаут тарифа этого block_N;
 *  - номер не назван, а блок в долге один → чекаут этого блока;
 *  - иначе (несколько блоков, рассрочка — там POST, блок без тарифа) →
 *    «Оплата и доступ», где есть все кнопки.
 * Ничего не создаёт и не пишет; платёжный путь — прежний чекаут.
 * Под флагом features.support_block_pay_link.
 */
final class SupportPayLinkResolver
{
    public function __construct(
        private readonly StudentDebtsService $debts,
        private readonly DebtPaymentResolver $options,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('features.support_block_pay_link');
    }

    /**
     * @return array{course: Course, block: ?int, url: string}|null null — долга не нашли однозначно
     */
    public function resolve(User $user, ?string $studentText = null): ?array
    {
        $debts = $this->debts->forUser($user)
            ->filter(fn (object $d) => ($d->course ?? null) instanceof Course)
            ->values();

        if ($debts->isEmpty()) {
            return null;
        }

        $named = $this->numbersIn($studentText);

        // Долг, в блоках которого есть названный номер, — если такой ровно один.
        $matching = $debts->filter(fn (object $d) => array_intersect(
            $named,
            array_map('intval', $d->debt_block_numbers ?? []),
        ) !== [])->values();

        if ($matching->count() === 1) {
            $debt = $matching->first();
        } elseif ($debts->count() === 1) {
            $debt = $debts->first();
        } else {
            return null;
        }

        $block = null;
        $url = null;
        $opts = $this->options->optionsFor($debt, $user);

        if (($opts['type'] ?? null) === 'tariff') {
            $blocks = collect($opts['blocks'] ?? []);
            $hit = $blocks->first(fn (array $b) => in_array((int) $b['number'], $named, true));

            // Номер не назван (или назван не из долга), а блок в долге один.
            if ($hit === null && $blocks->count() === 1
                && count($debt->debt_block_numbers ?? []) === 1) {
                $hit = $blocks->first();
            }

            if ($hit !== null) {
                $block = (int) $hit['number'];
                $url = (string) $hit['url'];
            }
        }

        return [
            'course' => $debt->course,
            'block' => $block,
            'url' => $url ?? $this->cabinetPaymentsUrl(),
        ];
    }

    /** @return list<int> */
    private function numbersIn(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        preg_match_all('/\d{1,4}/u', $text, $m);

        return array_values(array_unique(array_map('intval', $m[0])));
    }

    private function cabinetPaymentsUrl(): string
    {
        return config('features.cabinet_hybrid')
            ? route('student.access')
            : route('student.dashboard');
    }
}
