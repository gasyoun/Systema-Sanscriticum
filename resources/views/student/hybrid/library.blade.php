@extends('layouts.student')

@section('title', 'Записи')
@section('header', 'Записи')

@section('content')
@php
    use App\Services\Cabinet\RecordingsCatalog;
    $recovery = $recovery ?? \App\Services\Cabinet\RecoveryState::normal();
    $suppressOffers = $suppressOffers ?? $recovery->suppressOffers();
    $shelves = $shelves ?? [
        RecordingsCatalog::SHELF_WATCHING => collect(),
        RecordingsCatalog::SHELF_OWNED => collect(),
        RecordingsCatalog::SHELF_LAPSED => collect(),
        RecordingsCatalog::SHELF_COMPLETED => collect(),
    ];
    $shelfLabels = [
        RecordingsCatalog::SHELF_WATCHING => 'Идут сейчас',
        RecordingsCatalog::SHELF_OWNED => 'Мои записи',
        RecordingsCatalog::SHELF_LAPSED => 'Истёкшие · с продлением',
        RecordingsCatalog::SHELF_COMPLETED => 'Завершённые',
    ];
    $ownershipOffer = $ownershipOffer ?? null;
@endphp

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <h2 class="text-3xl font-extrabold text-[#101010] mb-2">Мои записи</h2>
    <p class="text-gray-500 mb-6">
        Всё, что вы купили в записи, и материалы доступных курсов — в одном месте.
        Купленное остаётся вашим.
    </p>

    @if ($recovery->active)
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-6">
            Режим восстановления: предложения о покупках скрыты, пока не решена проблема с оплатой.
            Уже открытые записи продолжают работать.
        </p>
    @endif

    @foreach ($shelfLabels as $key => $label)
        @php $cards = $shelves[$key] ?? collect(); @endphp
        @if ($cards->isNotEmpty())
            <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-gray-400" data-shelf="{{ $key }}">
                {{ $label }}
            </div>
            <div class="space-y-3 mb-8">
                @foreach ($cards as $card)
                    <article class="rounded-2xl border {{ $key === RecordingsCatalog::SHELF_LAPSED ? 'border-red-100 bg-red-50/40' : 'border-gray-100 bg-white' }} p-5 shadow-sm"
                             data-course-id="{{ $card->course->id }}"
                             data-shelf-card="{{ $key }}">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-extrabold text-[#101010] text-lg">
                                    <a href="{{ route('student.course', $card->course->slug) }}" class="hover:text-brand">
                                        {{ $card->course->title }}
                                    </a>
                                </h3>
                                <p class="text-xs text-gray-500 mt-1">
                                    @if ($card->is_recording_shape)
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-700 font-bold">записи · в своём темпе</span>
                                    @else
                                        <span class="inline-flex px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold">живой поток</span>
                                    @endif
                                    · {{ $card->completed_lessons }} из {{ $card->total_lessons }} уроков
                                    @if ($card->ribbon)
                                        · <span class="text-red-600 font-bold">{{ $card->ribbon }}</span>
                                    @endif
                                </p>

                                @if ($card->total_lessons > 0)
                                    <div class="mt-3 h-2 bg-gray-100 rounded-full overflow-hidden max-w-xs">
                                        <div class="h-full bg-brand rounded-full" style="width: {{ $card->percent }}%"></div>
                                    </div>
                                @endif

                                {{-- R29.4 progress rail for recording courses without homework --}}
                                @if ($card->show_rail)
                                    <nav class="mt-4 flex flex-wrap gap-1.5" aria-label="Рельса прогресса" data-progress-rail>
                                        @foreach ($card->lessons as $lesson)
                                            @php $done = in_array($lesson->id, $card->completed_ids, true); @endphp
                                            <a href="{{ route('student.lesson', [$card->course->slug, $lesson->id]) }}"
                                               class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-extrabold border
                                                    {{ $done ? 'bg-emerald-100 text-emerald-700 border-emerald-200' : 'bg-white text-gray-500 border-gray-200 hover:border-brand hover:text-brand' }}"
                                               title="{{ $lesson->title }}"
                                               data-track-event="library.rail.jump"
                                               data-track-course_id="{{ $card->course->id }}"
                                               data-track-lesson_id="{{ $lesson->id }}">
                                                {{ $done ? '✓' : $loop->iteration }}
                                            </a>
                                        @endforeach
                                    </nav>
                                @endif
                            </div>
                            <a href="{{ $card->cta_url }}"
                               class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold
                                    {{ $key === RecordingsCatalog::SHELF_LAPSED
                                        ? 'bg-brand text-white hover:bg-brand-hover'
                                        : 'border border-gray-200 text-gray-700 hover:border-brand hover:text-brand' }}">
                                {{ $card->cta_label }}
                            </a>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    @endforeach

    @if (collect($shelves)->every(fn ($c) => $c->isEmpty()))
        <div class="rounded-2xl border border-dashed border-gray-200 bg-white p-8 text-center mb-8">
            <p class="text-sm text-gray-500">Пока нет записей на полке. Курсы появятся здесь, когда откроется доступ.</p>
        </div>
    @endif

    {{-- R29.5 ownership offer — suppressed in recovery (offer precedence) --}}
    @if ($ownershipOffer && ! $suppressOffers)
        <section class="mb-8 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-5"
                 data-offer-kind="ownership"
                 data-track-impression="offer.impression"
                 data-track-kind="ownership"
                 data-track-course_id="{{ $ownershipOffer->course->id }}">
            <p class="text-[10px] font-bold uppercase tracking-widest text-indigo-600 mb-1">На вашей полке</p>
            <h3 class="text-lg font-extrabold text-[#101010] mb-1">{{ $ownershipOffer->headline }}</h3>
            <p class="text-sm text-gray-600 mb-4">{{ $ownershipOffer->body }}</p>
            <a href="{{ $ownershipOffer->cta_url }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold"
               data-track-event="offer.click"
               data-track-kind="ownership"
               data-track-course_id="{{ $ownershipOffer->course->id }}">
                {{ $ownershipOffer->cta_label }}
            </a>
        </section>
    @endif

    {{-- R8/R24 membership permanent slot on Записи --}}
    <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Членство «Самскрте+»</div>
    <section class="rounded-2xl border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-6 shadow-sm mb-6" aria-label="Членство Самскрте+">
        <p class="flex flex-wrap items-center gap-3 mb-2">
            <span class="inline-flex px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 text-xs font-extrabold">скоро · осень 2026</span>
            <span class="text-sm font-bold text-gray-700">2000 ₽/мес <span class="text-gray-400 font-normal">или 20 000 ₽/год</span></span>
        </p>
        <h3 class="text-xl font-extrabold text-[#101010] mb-2">Вся библиотека записей — по подписке</h3>
        <ul class="text-sm text-gray-600 space-y-1 mb-4 list-disc list-inside">
            <li>все записанные курсы и лекции, пока действует членство;</li>
            <li>свой темп — без расписания и дедлайнов;</li>
            <li>живые потоки и проверка домашних в членство не входят.</li>
        </ul>
        <p class="text-xs text-gray-400">Запуск осенью 2026 — оплата откроется письмом. Карточка здесь намеренно (R8/R24).</p>
    </section>
</div>

@endsection
