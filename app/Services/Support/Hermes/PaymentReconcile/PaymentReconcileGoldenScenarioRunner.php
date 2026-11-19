<?php

declare(strict_types=1);

namespace App\Services\Support\Hermes\PaymentReconcile;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hermes W1 golden-set runner для роли 65 `payment-reconciler` (H6188, пакет
 * hermes-24-01a10bca). Прогоняет синтетический сценарий из fixture
 * (tests/fixtures/hermes/w1-golden/role_65_payment_reconciler.json,
 * provenance H6181) через живой W1PaymentReconcileService и нормализует
 * наблюдение к формату expected-outcome golden-фикстуры.
 *
 * Инварианты:
 *  - только чтение: no_side_effects считается по снапшоту ВСЕХ таблиц до/после
 *    вызова — у роли 65 нет write-пути (класс money: zero мутаций), и каждая
 *    прогонка это доказывает;
 *  - никаких ученических диалогов: вход — машиночитаемый сценарий,
 *    авторизованный в H6181, не продовая переписка;
 *  - никаких исходящих отправок и LLM-вызовов (human-send per H6183 — за
 *    пределами роли).
 */
final class PaymentReconcileGoldenScenarioRunner
{
    public function __construct(
        private readonly W1PaymentReconcileService $service = new W1PaymentReconcileService,
    ) {}

    /**
     * @param  array<string, mixed>  $input  сценарий из fixture: {scenario: …}
     *                                       либо {message: …, context: …}
     * @return array<string, mixed> наблюдение для GoldenCaseEvaluator
     */
    public function __invoke(array $input): array
    {
        $eventKey = 'h6188:golden:role65:'.md5((string) json_encode($input));
        $before = $this->snapshot();

        if (isset($input['scenario'])) {
            $result = $this->service->reconcile($eventKey, (array) $input['scenario']);
        } else {
            $result = $this->service->handle($eventKey, (string) ($input['message'] ?? ''), (array) ($input['context'] ?? []));
        }

        $observation = $result->toObservation();
        $observation['no_side_effects'] = $before === $this->snapshot();

        return $observation;
    }

    /**
     * Снапшот всех таблиц: любое несовпадение после прогона = side-effect.
     *
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        $counts = [];
        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? (string) ($table['name'] ?? '') : (string) $table;
            if ($name !== '') {
                $counts[$name] = DB::table($name)->count();
            }
        }
        ksort($counts);

        return $counts;
    }
}
