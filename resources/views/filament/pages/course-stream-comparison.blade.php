@php
    use App\Support\CourseFamilyMatcher;
    // Plural::ru, а не trans_choice: locale приложения — 'en', и правило
    // множественного числа там английское, поэтому «6 платежа» вместо
    // «6 платежей» (H3084).
    use App\Support\Plural;

    $roleLabel = fn (string $role): string => match ($role) {
        CourseFamilyMatcher::ROLE_LIVE => 'живой поток',
        CourseFamilyMatcher::ROLE_RECORDING => 'в записи',
        default => 'роль не определена',
    };
    $money = fn ($v): string => number_format((float) $v, 2, ',', ' ');
@endphp

<x-filament-panels::page>
    {{-- Выбор семьи потоков --}}
    <div class="max-w-md">
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Семья потоков</label>
        <select
            wire:model.live="family"
            class="fi-select-input block w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm"
        >
            @foreach ($familyOptions as $slug => $label)
                <option value="{{ $slug }}">{{ $label }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs text-gray-400">
            Семья — это ярлык, которым связаны потоки одной программы. Он проставляется в карточке курса,
            поле «Семья потоков»; пустой список означает, что ни одному курсу семья ещё не назначена
            (команда <code>courses:backfill-families</code>).
        </p>
    </div>

    @if (! $report)
        <div class="rounded-xl bg-gray-50 p-6 text-sm text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
            Ни одной семьи потоков не заведено — сравнивать нечего.
        </div>
    @else
        @php
            $streams = $report['streams'];
            $salary = $report['salary'];
            $attendance = $report['attendance'];
        @endphp

        {{-- ── Блок 1. Ученики по блокам и удержание ───────────────────────── --}}
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Ученики по блокам и удержание</h2>

            <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-300">
                        <tr>
                            <th class="px-4 py-2 text-left font-semibold">Показатель</th>
                            @foreach ($streams as $s)
                                <th class="px-4 py-2 text-center font-semibold">
                                    {{ $s['title'] }}
                                    <div class="text-xs font-normal text-gray-400">
                                        id {{ $s['course_id'] }} · {{ $roleLabel($s['role']) }}
                                        @if (! $s['is_active']) · неактивен @endif
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @php
                            $blockNumbers = collect($streams)->flatMap(fn ($s) => collect($s['blocks'])->pluck('number'))->unique()->sort()->values();
                        @endphp

                        @foreach ($blockNumbers as $n)
                            <tr>
                                <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">Блок {{ $n }}</td>
                                @foreach ($streams as $s)
                                    @php $b = collect($s['blocks'])->firstWhere('number', $n); @endphp
                                    <td class="px-4 py-2 text-center">
                                        @if ($b)
                                            <span class="font-bold text-gray-900 dark:text-white">{{ $b['buyers'] }}</span>
                                            <span class="text-gray-400">чел.</span>
                                            <div class="text-xs text-gray-400">
                                                {{ $money($b['revenue']) }} ₽
                                                @if ($b['access'] !== $b['buyers'])
                                                    · доступ у {{ $b['access'] }}
                                                @endif
                                            </div>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach

                        <tr class="bg-gray-50/60 dark:bg-white/5">
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">Уникальных плательщиков</td>
                            @foreach ($streams as $s)
                                <td class="px-4 py-2 text-center font-bold text-gray-900 dark:text-white">{{ $s['payers'] }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">Удержание, блок 1 → последний</td>
                            @foreach ($streams as $s)
                                <td class="px-4 py-2 text-center text-gray-700 dark:text-gray-200">
                                    {{ $s['retention_first_to_last'] !== null ? $s['retention_first_to_last'].' %' : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="text-xs text-gray-400">
                «Чел.» — сколько человек купили <b>именно этот блок</b>: то же определение, что у выручки блока.
                «Доступ у N» появляется, когда у блока есть ещё и те, кто купил курс целиком, — они за блок
                отдельно не платили, поэтому в выручку блока не входят. Учтены только оплаченные платежи,
                без «обещанного» доступа.
            </p>
        </section>

        {{-- ── Блок 2. Деньги ──────────────────────────────────────────────── --}}
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Деньги</h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-{{ min(count($streams), 3) }}">
                @foreach ($streams as $s)
                    <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $s['title'] }}</div>
                        <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $money($s['revenue']) }} ₽</div>
                        <dl class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <div class="flex justify-between"><dt>Средний чек</dt><dd>{{ $money($s['avg_check']) }} ₽</dd></div>
                            <div class="flex justify-between"><dt>Скидки</dt><dd>{{ $money($s['discount_total']) }} ₽</dd></div>
                            <div class="flex justify-between">
                                <dt>Начислено преподавателю</dt>
                                <dd>{{ $money($s['accrued']) }} ₽</dd>
                            </div>
                        </dl>
                        @if ($s['salary_scheme'] === null)
                            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">
                                У курса не задана схема оплаты преподавателя, поэтому начисление здесь ноль.
                                Это не ошибка расчёта: пока схемы нет, начислять не с чего.
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="text-xs text-gray-400">
                Выручка — оплаченные платежи курса без возвратов и зеркал выплат. Начислено — <b>валовое</b>
                начисление по схеме курса (процент от выручки); выплаченное вычитается ниже, отдельной строкой,
                чтобы одни и те же деньги не были вычтены дважды.
            </p>
        </section>

        {{-- ── Блок 3. ЗП и остаток ────────────────────────────────────────── --}}
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                Начислено, выплачено, остаток
                @if ($salary['teacher_name']) <span class="text-sm font-normal text-gray-400">· {{ $salary['teacher_name'] }}</span> @endif
            </h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Начислено (валовое)</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $money($salary['accrued']) }} ₽</div>
                </div>
                <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">Выплачено (подтверждённо)</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $money($salary['paid_out']) }} ₽</div>
                </div>
                <div @class([
                    'rounded-xl p-4 ring-1',
                    'bg-amber-50 ring-amber-500/20 dark:bg-amber-500/10' => ! $salary['attribution_confirmed'],
                    'bg-gray-50 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10' => $salary['attribution_confirmed'],
                ])>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Остаток</div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ $money($salary['remainder']) }} ₽
                        @unless ($salary['attribution_confirmed'])
                            <span class="block text-sm font-semibold text-amber-700 dark:text-amber-400">предварительно</span>
                        @endunless
                    </div>
                </div>
            </div>

            @unless ($salary['attribution_confirmed'])
                <div class="rounded-xl bg-amber-50 p-4 text-sm ring-1 ring-amber-500/20 dark:bg-amber-500/10">
                    <p class="font-semibold text-amber-800 dark:text-amber-300">
                        Почему «предварительно» и что с этим делать
                    </p>
                    <p class="mt-1 text-amber-900/80 dark:text-amber-200/80">
                        {{ count($salary['pending_candidates']) }}
                        {{ Plural::ru(count($salary['pending_candidates']), 'платёж', 'платежа', 'платежей') }}
                        на {{ $money($salary['pending_total']) }} ₽ проведены по этим курсам как «Расход», но из данных
                        неотличимы от аренды или рекламы: они заведены на служебного пользователя, а не на
                        преподавателя. Пока человек не подтвердит, что это именно выплаты преподавателю, они
                        не входят в «выплачено», и остаток не является готовым ответом.
                    </p>
                    <p class="mt-1 text-amber-900/80 dark:text-amber-200/80">
                        Если подтвердить все {{ count($salary['pending_candidates']) }} — остаток станет
                        <b>{{ $money($salary['remainder_if_all_confirmed']) }} ₽</b>.
                    </p>
                    {{-- H3084: очередь подтверждения. Ссылка появляется только у того,
                         кому ресурс доступен — гейт тот же, RoleGate::accounting(). --}}
                    @if (\App\Filament\Resources\TeacherPayoutAttributionSuggestionResource::canViewAny())
                        <p class="mt-2">
                            <a href="{{ \App\Filament\Resources\TeacherPayoutAttributionSuggestionResource::getUrl() }}"
                               class="font-semibold text-amber-800 underline dark:text-amber-300">
                                Открыть очередь подтверждения →
                            </a>
                        </p>
                    @endif

                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-amber-900/70 dark:text-amber-200/70">
                                <tr>
                                    <th class="px-2 py-1 text-left font-semibold">Платёж</th>
                                    <th class="px-2 py-1 text-left font-semibold">Дата</th>
                                    <th class="px-2 py-1 text-left font-semibold">Курс</th>
                                    <th class="px-2 py-1 text-left font-semibold">На кого заведён</th>
                                    <th class="px-2 py-1 text-right font-semibold">Сумма, ₽</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-amber-500/10">
                                @foreach ($salary['pending_candidates'] as $c)
                                    <tr>
                                        <td class="px-2 py-1">#{{ $c['payment_id'] }}</td>
                                        <td class="px-2 py-1">{{ $c['date'] ?? '—' }}</td>
                                        <td class="px-2 py-1">{{ $c['course_title'] }}</td>
                                        <td class="px-2 py-1">{{ $c['user_name'] ?? '—' }}</td>
                                        <td class="px-2 py-1 text-right">{{ $money($c['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endunless

            @if (count($salary['paid_out_lines']))
                <details class="text-sm">
                    <summary class="cursor-pointer text-gray-500 dark:text-gray-400">Из чего сложилось «выплачено»</summary>
                    <ul class="mt-2 space-y-1 text-xs text-gray-500 dark:text-gray-400">
                        @foreach ($salary['paid_out_lines'] as $line)
                            <li>{{ $line['date'] ?? '—' }} · {{ $money($line['amount']) }} ₽ · {{ $line['note'] }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        {{-- ── Блок 4. Пересечение потоков ─────────────────────────────────── --}}
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Пересечение потоков</h2>

            <div class="space-y-2">
                @foreach ($report['crossover']['pairs'] as $pair)
                    <div class="rounded-xl bg-gray-50 p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <b class="text-gray-900 dark:text-white">{{ $pair['count'] }}</b>
                        {{ Plural::ru($pair['count'], 'человек', 'человека', 'человек') }} есть и в «{{ $pair['from_title'] }}», и в «{{ $pair['to_title'] }}».
                        @if (count($pair['users']))
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ collect($pair['users'])->pluck('name')->implode(', ') }}
                            </div>
                        @endif
                    </div>
                @endforeach

                @foreach ($report['crossover']['recording'] as $rec)
                    <div class="rounded-xl bg-gray-50 p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        «{{ $rec['title'] }}» купили <b class="text-gray-900 dark:text-white">{{ $rec['buyers'] }}</b>;
                        из них {{ $rec['also_live'] }} учились на живом потоке, а {{ $rec['only_recording'] }} — нет.
                        @if (count($rec['users']))
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Только запись: {{ collect($rec['users'])->pluck('name')->implode(', ') }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ── Блок 5. Отток поимённо + плашка покрытия ────────────────────── --}}
        <section class="space-y-3">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Отток и посещаемость</h2>

            {{-- Плашка покрытия: обязательна. Пустая колонка посещаемости без неё
                 читается как «никто не ходил» — это была бы ложь в отчёте. --}}
            <div class="rounded-xl bg-blue-50 p-4 text-sm ring-1 ring-blue-500/20 dark:bg-blue-500/10">
                <p class="font-semibold text-blue-900 dark:text-blue-200">
                    Данные о посещаемости есть по {{ $attendance['covered_users'] }} из {{ $attendance['total_users'] }}
                    человек ({{ (int) round($attendance['coverage_ratio'] * 100) }} %)
                </p>
                <p class="mt-1 text-blue-900/80 dark:text-blue-200/80">
                    Просмотры уроков: {{ $attendance['lesson_view_users'] }} чел. · отметки на вебинарах:
                    {{ $attendance['webinar_users'] }} чел. Пустая клетка означает «не собрано», а не «не ходил»:
                    посещаемость по этим потокам почти не собиралась. Считать по ней отток нельзя — для этого
                    ниже срез по оплатам.
                </p>
            </div>

            <div class="space-y-2">
                @foreach ($streams as $s)
                    @if (count($s['dropped_between_blocks']))
                        <div class="rounded-xl bg-gray-50 p-4 text-sm ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <div class="font-medium text-gray-900 dark:text-white">{{ $s['title'] }}</div>
                            @foreach ($s['dropped_between_blocks'] as $drop)
                                <div class="mt-1">
                                    <span class="text-gray-700 dark:text-gray-200">
                                        Блок {{ $drop['block_from'] }} → {{ $drop['block_to'] }}: ушли
                                        <b>{{ $drop['count'] }}</b>
                                    </span>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ collect($drop['users'])->pluck('name')->implode(', ') }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>

            @if (count($attendance['bought_all_never_watched']))
                <details class="text-sm">
                    <summary class="cursor-pointer text-gray-500 dark:text-gray-400">
                        Оплатили, но ни одного просмотра и ни одной отметки —
                        {{ count($attendance['bought_all_never_watched']) }} чел.
                    </summary>
                    <p class="mt-1 text-xs text-gray-400">
                        При покрытии {{ (int) round($attendance['coverage_ratio'] * 100) }} % этот список почти совпадает
                        со списком плательщиков — он говорит о сборе данных, а не о людях.
                    </p>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ collect($attendance['bought_all_never_watched'])->pluck('name')->implode(', ') }}
                    </div>
                </details>
            @endif
        </section>
    @endif
</x-filament-panels::page>
