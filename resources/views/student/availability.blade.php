@extends('layouts.student')

@section('title', 'Удобное время занятий')
@section('header', 'Удобное время занятий')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">
    <div class="mb-6 mt-6">
        <a href="{{ route('student.dashboard') }}" class="text-sm text-brand hover:underline">← Личный кабинет</a>
        <h2 class="text-3xl font-extrabold text-[#101010] tracking-tight mt-3">Когда вам удобно заниматься</h2>
        <p class="text-gray-500 mt-2">
            Для каждого интервала выберите режим: «Удобное время» или «Неудобное время»,
            а также повтор: «Каждую неделю» либо цикл «2 дня можно / 2 дня нельзя» с датой старта —
            он подойдёт для плавающего графика. Новые курсы и факультативы будут предлагаться
            только в удобные интервалы, а неудобные исключаются.
            Если ничего не отметить — ограничений не будет. Уточнения куратора имеют приоритет.
        </p>
    </div>

    @include('student.partials.flash-messages')

    <form method="POST" action="{{ route('student.availability.store') }}"
          class="rounded-2xl border border-gray-100 bg-white p-5 md:p-6 shadow-sm">
        @csrf
        <input type="hidden" name="tzid" value="{{ $tzid }}">

        <div class="space-y-2">
            @foreach (\App\Models\StudentAvailabilityWindow::WEEKDAYS as $day)
                @php $dayRows = $windows->filter(fn ($w) => in_array($day, $w->weekdays(), true))->values(); @endphp
                @foreach ([0, 1] as $slot)
                    @php $row = $dayRows[$slot] ?? null; @endphp
                    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-100 px-4 py-3">
                        <label class="flex items-center gap-2 w-28 shrink-0">
                            <input type="checkbox" name="days[{{ $day }}][{{ $slot }}][enabled]" value="1"
                                   class="rounded border-gray-300 text-brand"
                                   @checked($row !== null)>
                            <span class="font-bold text-sm text-gray-800">{{ $slot === 0 ? $day : '' }}</span>
                        </label>
                        <input type="time" name="days[{{ $day }}][{{ $slot }}][start]"
                               value="{{ old('days.'.$day.'.'.$slot.'.start', $row?->start_time ?? '09:00') }}"
                               class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm">
                        <span class="text-gray-400">—</span>
                        <input type="time" name="days[{{ $day }}][{{ $slot }}][end]"
                               value="{{ old('days.'.$day.'.'.$slot.'.end', $row?->end_time ?? '23:59') }}"
                               class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm">
                        <select name="days[{{ $day }}][{{ $slot }}][kind]"
                                class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm"
                                title="Удобное или неудобное время">
                            <option value="{{ \App\Models\StudentAvailabilityWindow::KIND_ALLOW }}"
                                @selected(old('days.'.$day.'.'.$slot.'.kind', $row?->kind ?? \App\Models\StudentAvailabilityWindow::KIND_ALLOW) === \App\Models\StudentAvailabilityWindow::KIND_ALLOW)>
                                Удобное время
                            </option>
                            <option value="{{ \App\Models\StudentAvailabilityWindow::KIND_DENY }}"
                                @selected(old('days.'.$day.'.'.$slot.'.kind', $row?->kind ?? \App\Models\StudentAvailabilityWindow::KIND_ALLOW) === \App\Models\StudentAvailabilityWindow::KIND_DENY)>
                                Неудобное время
                            </option>
                        </select>
                        <select name="days[{{ $day }}][{{ $slot }}][repeat_mode]"
                                class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm"
                                title="Как повторять интервал">
                            <option value="{{ \App\Models\StudentAvailabilityWindow::REPEAT_WEEKLY }}"
                                @selected(old('days.'.$day.'.'.$slot.'.repeat_mode', $row?->repeat_mode ?? \App\Models\StudentAvailabilityWindow::REPEAT_WEEKLY) === \App\Models\StudentAvailabilityWindow::REPEAT_WEEKLY)>
                                Каждую неделю
                            </option>
                            <option value="{{ \App\Models\StudentAvailabilityWindow::REPEAT_CYCLE }}"
                                @selected(old('days.'.$day.'.'.$slot.'.repeat_mode', $row?->repeat_mode ?? \App\Models\StudentAvailabilityWindow::REPEAT_WEEKLY) === \App\Models\StudentAvailabilityWindow::REPEAT_CYCLE)>
                                Цикл (например, 2/2)
                            </option>
                        </select>
                        <span class="text-xs text-gray-400" title="Только для цикла: можно / нельзя дней и дата старта">
                            можно
                            <input type="number" min="1" max="14" class="w-12 rounded-lg border border-gray-200 px-1 py-1 text-sm"
                                   name="days[{{ $day }}][{{ $slot }}][cycle_on_days]"
                                   value="{{ old('days.'.$day.'.'.$slot.'.cycle_on_days', $row?->cycle_on_days ?? 2) }}">
                            / нельзя
                            <input type="number" min="1" max="14" class="w-12 rounded-lg border border-gray-200 px-1 py-1 text-sm"
                                   name="days[{{ $day }}][{{ $slot }}][cycle_off_days]"
                                   value="{{ old('days.'.$day.'.'.$slot.'.cycle_off_days', $row?->cycle_off_days ?? 2) }}">
                            с
                            <input type="date" class="rounded-lg border border-gray-200 px-1 py-1 text-sm"
                                   name="days[{{ $day }}][{{ $slot }}][anchor_date]"
                                   value="{{ old('days.'.$day.'.'.$slot.'.anchor_date', $row?->anchor_date?->toDateString() ?? now()->toDateString()) }}">
                        </span>
                    </div>
                @endforeach
            @endforeach
        </div>

        <p class="text-xs text-gray-400 mt-3">Время: {{ $tzid }}</p>

        <button type="submit"
                class="mt-5 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold transition-colors">
            Сохранить
        </button>
    </form>

    <p class="mt-5 text-sm text-gray-500">
        Полная инструкция по кабинету: <a class="text-brand hover:underline" href="https://samskrte.ru/dvaram/help">«Как пользоваться»</a>.
    </p>
</div>
@endsection
