@php
    /** @var \App\Services\Discipline\DisciplineScore $score */
    $score = app(\App\Services\DisciplineScoreService::class)->forUser($getRecord());
    $signals = $score->signals;
@endphp

<div class="space-y-4 text-sm">
    <div class="flex items-center gap-3">
        <span class="text-3xl font-bold tabular-nums">{{ $score->score }}</span>
        <span @class([
            'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium',
            'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-300' => $score->tier === 'good',
            'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-300' => $score->tier === 'watch',
            'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300' => $score->tier === 'poor',
        ])>{{ $score->label() }}</span>
    </div>

    <dl class="grid grid-cols-2 gap-3">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Оплата вовремя</dt>
            <dd class="font-medium tabular-nums">{{ round($signals['on_time_rate'] * 100) }}%</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Обещания выполнены</dt>
            <dd class="font-medium tabular-nums">{{ round($signals['promise_keep_rate'] * 100) }}%</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Доступ в кредит</dt>
            <dd class="font-medium tabular-nums">{{ round($signals['credit_reliance_rate'] * 100) }}%</dd>
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Случаев молчания</dt>
            <dd class="font-medium tabular-nums">{{ $signals['silence_events'] }}</dd>
        </div>
    </dl>

    <p class="text-xs text-gray-400">
        С момента зачисления на курс(ы). Не влияет на скидки, рассрочку или conditional-доступ — контекст для менеджера/преподавателя.
    </p>
</div>
