<x-filament-panels::page>
    <style>
        .sqw-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        .sqw-table-wrap { overflow-x: auto; }
        @media (min-width: 1024px) {
            .sqw-grid { grid-template-columns: minmax(340px, 1fr) minmax(340px, 1fr); }
        }
    </style>

    <div class="space-y-4">
        @php($latest = $this->latest)

        @if ($latest === null)
            <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-lg font-semibold text-gray-950 dark:text-white">Снапшотов ещё нет</div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Запустите <code>php artisan support:questions-weekly</code> (или <code>--backfill</code>) — здесь появится последняя неделя и тренды.
                </div>
            </div>
        @else
            @php($payload = $latest['payload'])
            @php($coverage = $payload['coverage'])
            @php($sync = $coverage['sync'])

            <div class="rounded-xl border {{ $latest['is_incomplete'] ? 'border-amber-300 bg-amber-50 dark:bg-amber-950/30' : 'border-gray-200 bg-white' }} p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div class="text-lg font-bold text-gray-950 dark:text-white">
                        Неделя {{ \Carbon\CarbonImmutable::parse($payload['window']['from'])->format('d.m.Y') }} — {{ \Carbon\CarbonImmutable::parse($payload['window']['to'])->modify('-1 day')->format('d.m.Y') }}
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        классификатор <code>{{ $payload['classifier_version'] }}</code> · студент = <code>{{ $payload['student_definition'] }}</code>
                    </div>
                </div>
                @if ($latest['is_incomplete'])
                    <div class="mt-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                        ⚠️ Снапшот неполный: {{ $latest['incompleteness_reason'] }} — проценты изменения подавлены.
                    </div>
                @endif
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Покрытие: {{ $coverage['days_with_incoming'] }}/{{ $coverage['days_in_window'] }} дн ·
                    чатов {{ $coverage['chats_active'] }} (ЛС {{ $coverage['private_chats'] }}, групп {{ $coverage['groups'] }}) ·
                    синк {{ ($sync['fresh'] ?? false) ? 'свежий' : 'несвежий/с ошибкой'.($sync['has_error'] ? '' : '') }}
                </div>
            </div>

            <div class="sqw-grid">
                @foreach ([
                    'student' => 'Подтверждённые студенты',
                    'enquiry' => 'Незалинкованные обращения',
                    'staff_internal' => 'Внутренняя координация (legacy-корпус)',
                    'unknown' => 'Неопознанные отправители',
                ] as $populationCode => $populationTitle)
                    @php($population = $payload['populations'][$populationCode] ?? null)
                    @if ($population === null) @continue @endif
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $populationTitle }}</div>
                        <div class="mt-1 text-3xl font-bold text-gray-950 dark:text-white">{{ $population['questions'] }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            входящих {{ $population['incoming'] }} · уникальных спрашивающих {{ $population['unique_questioners'] }} ·
                            доля вопросов {{ $population['question_share'] !== null ? round($population['question_share'] * 100).'%' : 'н/д' }} ·
                            неклассифицированных {{ $population['unclassified'] }} ({{ $population['unclassified_share'] !== null ? round($population['unclassified_share'] * 100).'%' : 'н/д' }})
                        </div>
                        <div class="sqw-table-wrap mt-3">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        <th class="py-1 pr-2">Категория</th>
                                        <th class="py-1 pr-2 text-right">Счёт</th>
                                        @if ($this->comparison && ($populationCode === 'student' || $populationCode === 'enquiry'))
                                            <th class="py-1 text-right">Δ пред. нед.</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                    @foreach (\App\Services\SupportQuestions\QuestionMessageClassifier::CATEGORY_ORDER as $category)
                                        @php($count = $population['by_category'][$category] ?? 0)
                                        @if ($count === 0 && ($this->comparison === null)) @continue @endif
                                        <tr class="text-gray-700 dark:text-gray-200">
                                            <td class="py-1 pr-2">{{ $category }} · {{ \App\Services\SupportQuestions\QuestionMessageClassifier::CATEGORY_TITLES[$category] }}</td>
                                            <td class="py-1 pr-2 text-right font-medium">{{ $count }}</td>
                                            @if ($this->comparison && ($populationCode === 'student' || $populationCode === 'enquiry'))
                                                @php($delta = $this->comparison['deltas'][$category] ?? null)
                                                <td class="py-1 text-right {{ $delta && $delta['change'] > 0 ? 'text-red-500' : ($delta && $delta['change'] < 0 ? 'text-emerald-500' : '') }}">
                                                    @if ($delta)
                                                        {{ $delta['change'] > 0 ? '+' : '' }}{{ $delta['change'] }}
                                                        @if ($delta['pct'] !== null)
                                                            <span class="text-xs text-gray-400">({{ $delta['pct'] > 0 ? '+' : '' }}{{ $delta['pct'] }}%)</span>
                                                        @endif
                                                    @endif
                                                </td>
                                            @endif
                                        </tr>
                                    @endforeach
                                    <tr class="text-gray-400">
                                        <td class="py-1 pr-2">неклассифицированные</td>
                                        <td class="py-1 pr-2 text-right">{{ $population['unclassified'] }}</td>
                                        @if ($this->comparison && ($populationCode === 'student' || $populationCode === 'enquiry'))<td></td>@endif
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        @if ($populationCode === 'staff_internal')
                            <div class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                Исторический июльский корпус внутреннего чата — учитывается отдельно и в топ тем студентов не входит.
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            @php($comparison = $this->comparison)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Сравнение с предыдущей неделей</div>
                @if ($comparison === null)
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Меньше двух снапшотов — сравнивать не с чем.</div>
                @elseif (! $comparison['ready'])
                    <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        Изменения подавлены: {{ $comparison['reason'] }} (несовместимые/неполные окна или нулевые знаменатели).
                    </div>
                @else
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Против недели {{ $comparison['previous_week'] }}; проценты показаны только при ненулевом знаменателе.
                    </div>
                @endif
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Тренды (внешние вопросы по неделям)</div>
                <div class="sqw-table-wrap mt-2">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <th class="py-1 pr-3">Неделя</th>
                                <th class="py-1 pr-3 text-right">Всего</th>
                                @foreach (\App\Services\SupportQuestions\QuestionMessageClassifier::CATEGORY_ORDER as $category)
                                    <th class="py-1 pr-3 text-right">{{ $category }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($this->history as $row)
                                <tr class="text-gray-700 dark:text-gray-200 {{ $row['complete'] ? '' : 'opacity-50' }}">
                                    <td class="py-1 pr-3">{{ $row['week'] }}{{ $row['complete'] ? '' : ' ⚠' }}</td>
                                    <td class="py-1 pr-3 text-right font-medium">{{ $row['external'] }}</td>
                                    @foreach (\App\Services\SupportQuestions\QuestionMessageClassifier::CATEGORY_ORDER as $category)
                                        <td class="py-1 pr-3 text-right">{{ $row['by_category'][$category] ?? 0 }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-1 text-xs text-gray-400">⚠ — неполное окно (свежесть/покрытие); тренд по нему читать нельзя.</div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-sm font-semibold text-gray-950 dark:text-white">Нормировка по активным студентам</div>
                @php($activity = $payload['activity'] ?? [])
                @if (($activity['active_students'] ?? 0) > 0)
                    <div class="mt-1 text-sm text-gray-700 dark:text-gray-200">
                        Активных студентов: {{ $activity['active_students'] }} ·
                        вопросов на 100 активных: {{ $activity['questions_per_100_active'] !== null ? number_format((float) $activity['questions_per_100_active'], 1) : 'н/д' }}
                    </div>
                @else
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Недоступно: в окне нет занятий с датой — авторитетного знаменателя нет, подставной не выдумываем.
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
