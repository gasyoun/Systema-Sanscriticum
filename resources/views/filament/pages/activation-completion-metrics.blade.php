<x-filament-panels::page>
    @php
        $s = $snap;
        $a = $s['activation'];
        $c = $s['completion'];
        $pct = fn ($v) => $v === null ? 'нет данных' : number_format((float) $v, 1, ',', ' ').' %';
        $days = fn ($v) => $v === null ? 'нет данных' : number_format((float) $v, 1, ',', ' ').' дн.';
    @endphp

    <div class="rounded-xl ring-1 ring-amber-500/30 bg-amber-50/70 dark:bg-amber-500/10 px-4 py-3 text-sm mb-4" data-testid="metrics-denominators">
        <div class="font-semibold text-amber-900 dark:text-amber-200">Знаменатели — читать до процентов</div>
        <p class="mt-1 text-amber-900/80 dark:text-amber-100/80">{{ $a['denominator_hint'] }}</p>
        <p class="mt-1 text-amber-900/80 dark:text-amber-100/80">{{ $c['course_denominator_hint'] }}</p>
        <p class="mt-1 text-amber-900/80 dark:text-amber-100/80">{{ $c['group_denominator_hint'] }}</p>
        <p class="mt-1 text-amber-900/80 dark:text-amber-100/80">{{ $c['completion_source_hint'] }}</p>
        <p class="mt-1 text-amber-900/80 dark:text-amber-100/80">{{ $c['certificate_hint'] }}</p>
        @if (! empty($a['telemetry_hint']))
            <p class="mt-1 text-amber-900/80 dark:text-amber-100/80" data-testid="telemetry-hint">{{ $a['telemetry_hint'] }}</p>
        @endif
        <p class="mt-2 text-xs text-amber-900/70 dark:text-amber-100/70">
            Пороги живут в <code>{{ $s['config_source'] }}</code>, не в этой вёрстке.
            Знаменатель меньше {{ $s['min_denominator'] }} помечен как ненадёжный: «1 из 2» — это не 50 %, это шум.
            Снимок на {{ $s['as_of'] }}; страница считает из живой БД и ничего не пишет.
        </p>
    </div>

    {{-- ── O2. Активация ─────────────────────────────────────────────────── --}}
    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 mb-6">
        <div class="px-4 pt-3 text-sm font-semibold">
            O2 · Активация по когортам
            @if ($a['found'])
                <span class="font-normal text-gray-500">({{ $a['window_from'] }} — {{ $a['window_to'] }})</span>
            @endif
        </div>

        @if (! $a['found'])
            <p class="px-4 py-4 text-sm text-gray-500" data-testid="activation-empty">
                Нет данных: в окне {{ $a['window_from'] }} — {{ $a['window_to'] }} нет учеников с первой
                доступо-открывающей покупкой. Пустая воронка не заменяется нулями.
            </p>
        @else
            <table class="w-full text-sm mt-2" data-testid="activation-table">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Когорта</th>
                        <th class="px-4 py-2 text-right" title="{{ $a['denominator_hint'] }}">Оплатили (знаменатель)</th>
                        <th class="px-4 py-2 text-right">Вошли в кабинет</th>
                        <th class="px-4 py-2 text-right">Открыли урок</th>
                        <th class="px-4 py-2 text-right">Сдали домашнюю</th>
                        <th class="px-4 py-2 text-right">Медиана до первого урока</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($a['cohorts'] as $row)
                        <tr @class(['opacity-60' => ! $row['reliable']])>
                            <td class="px-4 py-2">
                                {{ $row['month'] }}
                                @unless ($row['reliable'])
                                    <span class="text-xs text-gray-500">· мало данных</span>
                                @endunless
                            </td>
                            <td class="px-4 py-2 text-right font-medium">{{ $row['denominator'] }}</td>
                            <td class="px-4 py-2 text-right">
                                {{ $pct($row['logged_in_pct']) }}
                                <span class="text-xs text-gray-500">({{ $row['logged_in'] }} / {{ $row['denominator'] }})</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if (! $row['lesson_measurable'])
                                    <span class="text-gray-500">нечем измерить</span>
                                @else
                                    {{ $pct($row['opened_lesson_pct']) }}
                                    <span class="text-xs text-gray-500">({{ $row['opened_lesson'] }} / {{ $row['denominator'] }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                @if (! $row['homework_measurable'])
                                    <span class="text-gray-500">нечем измерить</span>
                                @else
                                    {{ $pct($row['submitted_homework_pct']) }}
                                    <span class="text-xs text-gray-500">({{ $row['submitted_homework'] }} / {{ $row['denominator'] }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right" title="{{ $row['ttfl_denominator_hint'] }}">
                                @if (! $row['lesson_measurable'])
                                    <span class="text-gray-500">нечем измерить</span>
                                @else
                                    {{ $days($row['ttfl_median_days']) }}
                                    <span class="text-xs text-gray-500">(по {{ $row['ttfl_denominator'] }})</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($a['total'])
                        <tr class="bg-gray-50 dark:bg-white/5 font-semibold" data-testid="activation-total">
                            <td class="px-4 py-2">Всего за окно</td>
                            <td class="px-4 py-2 text-right">{{ $a['total']['denominator'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $pct($a['total']['logged_in_pct']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $pct($a['total']['opened_lesson_pct']) }}</td>
                            <td class="px-4 py-2 text-right">{{ $pct($a['total']['submitted_homework_pct']) }}</td>
                            <td class="px-4 py-2 text-right" title="{{ $a['total']['ttfl_denominator_hint'] }}">
                                {{ $days($a['total']['ttfl_median_days']) }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
            <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                Каждый шаг — доля от размера когорты, а не от предыдущего шага: так «где мы теряем людей»
                читается напрямую. Домашняя в статусе <code>draft</code> сдачей не считается.
            </p>
        @endif
    </div>

    {{-- ── C4. Завершаемость ─────────────────────────────────────────────── --}}
    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 mb-6">
        <div class="px-4 pt-3 text-sm font-semibold">
            C4 · Завершаемость по курсам
            <span class="font-normal text-gray-500">
                (порог: {{ number_format($c['lesson_ratio'] * 100, 0) }} % уроков курса)
            </span>
        </div>

        @if ($c['courses'] === [])
            <p class="px-4 py-4 text-sm text-gray-500" data-testid="completion-courses-empty">
                Нет данных: ни на одном курсе нет записанных учеников (<code>course_user</code>).
            </p>
        @else
            <table class="w-full text-sm mt-2" data-testid="completion-courses">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Курс</th>
                        <th class="px-4 py-2 text-right" title="{{ $c['course_denominator_hint'] }}">Записаны</th>
                        <th class="px-4 py-2 text-right">Из них с оплатой</th>
                        <th class="px-4 py-2 text-right">Уроков</th>
                        <th class="px-4 py-2 text-right">Дошли до порога</th>
                        <th class="px-4 py-2 text-right" title="{{ $c['certificate_hint'] }}">С сертификатом</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($c['courses'] as $row)
                        <tr @class(['opacity-60' => ! $row['reliable']])>
                            <td class="px-4 py-2">
                                {{ $row['name'] }}
                                @unless ($row['reliable'])
                                    <span class="text-xs text-gray-500">· мало данных</span>
                                @endunless
                            </td>
                            <td class="px-4 py-2 text-right font-medium">{{ $row['denominator'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $row['paid_students'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $row['lessons_total'] }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($row['no_lessons'])
                                    <span class="text-gray-500">нет уроков</span>
                                @else
                                    {{ $pct($row['completed_pct']) }}
                                    <span class="text-xs text-gray-500">({{ $row['completed'] }} / {{ $row['denominator'] }}, порог {{ $row['lessons_needed'] }} ур.)</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                {{ $pct($row['certified_pct']) }}
                                <span class="text-xs text-gray-500">({{ $row['certified'] }} / {{ $row['denominator'] }})</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
        <div class="px-4 pt-3 text-sm font-semibold">C4 · Завершаемость по потокам</div>

        @if ($c['groups'] === [])
            <p class="px-4 py-4 text-sm text-gray-500" data-testid="completion-groups-empty">
                Нет данных: ни у одного потока нет собственных уроков (<code>lessons.group_id</code>)
                вместе с составом (<code>group_user</code>). Это «нет данных», а не 0 % завершаемости.
            </p>
        @else
            <table class="w-full text-sm mt-2" data-testid="completion-groups">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-2 text-left">Поток</th>
                        <th class="px-4 py-2 text-right" title="{{ $c['group_denominator_hint'] }}">В составе</th>
                        <th class="px-4 py-2 text-right">Уроков</th>
                        <th class="px-4 py-2 text-right">Дошли до порога</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($c['groups'] as $row)
                        <tr @class(['opacity-60' => ! $row['reliable']])>
                            <td class="px-4 py-2">
                                {{ $row['name'] }}
                                @unless ($row['reliable'])
                                    <span class="text-xs text-gray-500">· мало данных</span>
                                @endunless
                            </td>
                            <td class="px-4 py-2 text-right font-medium">{{ $row['denominator'] }}</td>
                            <td class="px-4 py-2 text-right">{{ $row['lessons_total'] }}</td>
                            <td class="px-4 py-2 text-right">
                                {{ $pct($row['completed_pct']) }}
                                <span class="text-xs text-gray-500">({{ $row['completed'] }} / {{ $row['denominator'] }}, порог {{ $row['lessons_needed'] }} ур.)</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-filament-panels::page>
