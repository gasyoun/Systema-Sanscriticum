<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\FaqRag;

use Illuminate\Support\Facades\DB;

/**
 * Hermes W1 golden-set runner для роли 20 `faq-rag` (H6185, пакет
 * hermes-24-01a10bca). Прогоняет синтетический сценарий из fixture
 * (tests/fixtures/hermes/w1-golden/role_20_faq_rag.json, provenance H6181)
 * через живой W1FaqRagAnswerComposer и нормализует наблюдение к формату
 * expected-outcome из golden-фикстуры.
 *
 * Инварианты:
 *  - только чтение: no_side_effects считается по снапшоту canonical-таблиц
 *    до/после вызова — у роли 20 нет write-пути, и каждая прогонка это
 *    доказывает;
 *  - никаких ученических диалогов: вход — машиночитаемый сценарий,
 *    авторизованный в H6181, не продовая переписка;
 *  - никаких исходящих отправок (human-send per H6183 — за пределами роли).
 */
final class W1FaqRagGoldenScenarioRunner
{
    public function __construct(
        private readonly W1FaqRagAnswerComposer $composer = new W1FaqRagAnswerComposer,
    ) {}

    /**
     * @param  array<string, mixed>  $input  сценарий из fixture (message, context)
     * @return array<string, mixed> наблюдение для GoldenCaseEvaluator
     */
    public function __invoke(array $input): array
    {
        $before = $this->snapshot();

        $result = $this->composer->compose(
            (string) ($input['message'] ?? ''),
            (array) ($input['context'] ?? []),
        );

        $observation = $result->toObservation();
        $observation['no_side_effects'] = $before === $this->snapshot();

        return $observation;
    }

    /**
     * Снапшот canonical-таблиц: любое несовпадение после прогона = side-effect.
     *
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'groups' => DB::table('groups')->count(),
            'memberships' => DB::table('group_user')->count(),
            'schedules' => DB::table('schedules')->count(),
            'payments' => DB::table('payments')->count(),
            'suggestions' => DB::table('support_answer_suggestions')->count(),
        ];
    }
}
