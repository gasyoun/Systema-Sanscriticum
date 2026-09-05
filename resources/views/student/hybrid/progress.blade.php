@extends('layouts.student')

@section('title', 'Прогресс')
@section('header', 'Прогресс')

@section('content')
@php
    use App\Services\Cabinet\GrammarLadder;
    $recovery = $recovery ?? \App\Services\Cabinet\RecoveryState::normal();
    $suppressOffers = $suppressOffers ?? $recovery->suppressOffers();
    $stations = $stations ?? [];
    $ladderOffer = $ladderOffer ?? null;
@endphp

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    <h2 class="text-3xl font-extrabold text-[#101010] mb-2">Ваш прогресс</h2>
    <p class="text-gray-500 mb-6">
        Главная лестница: письмо → грамматика I → грамматика II → тексты.
        Без серий и таймеров срочности.
    </p>

    @if ($recovery->active)
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-6">
            Режим восстановления: офферы лестницы скрыты (R29.2 / R29.7).
        </p>
    @endif

    {{-- R29.6 station map --}}
    <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Путь</div>
    <ol class="space-y-3 mb-8 list-none" data-grammar-ladder aria-label="Грамматическая лестница">
        @foreach ($stations as $i => $station)
            @php
                $status = $station->status ?? GrammarLadder::STATUS_LOCKED;
                $border = match ($status) {
                    GrammarLadder::STATUS_COMPLETED => 'border-emerald-200 bg-emerald-50/50',
                    GrammarLadder::STATUS_CURRENT => 'border-brand/40 bg-orange-50/40',
                    GrammarLadder::STATUS_AVAILABLE => 'border-gray-100 bg-white',
                    default => 'border-gray-100 bg-gray-50 opacity-80',
                };
                $badge = match ($status) {
                    GrammarLadder::STATUS_COMPLETED => ['lit', 'пройдена'],
                    GrammarLadder::STATUS_CURRENT => ['now', 'сейчас'],
                    GrammarLadder::STATUS_AVAILABLE => ['open', 'доступна'],
                    default => ['lock', 'ждёт'],
                };
            @endphp
            <li class="rounded-2xl border {{ $border }} p-5 shadow-sm"
                data-station="{{ $station->key }}"
                data-station-status="{{ $status }}">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1">
                            Станция {{ $i + 1 }}
                            <span class="ml-2 inline-flex px-2 py-0.5 rounded-full text-[10px] font-extrabold
                                {{ $status === GrammarLadder::STATUS_COMPLETED ? 'bg-emerald-100 text-emerald-800' : '' }}
                                {{ $status === GrammarLadder::STATUS_CURRENT ? 'bg-orange-100 text-brand' : '' }}
                                {{ $status === GrammarLadder::STATUS_LOCKED ? 'bg-gray-200 text-gray-600' : '' }}
                                {{ $status === GrammarLadder::STATUS_AVAILABLE ? 'bg-indigo-50 text-indigo-700' : '' }}">
                                {{ $badge[1] }}
                            </span>
                        </p>
                        <h3 class="text-lg font-extrabold text-[#101010]">{{ $station->title }}</h3>
                        <p class="text-sm text-gray-600 mt-1">{{ $station->description }}</p>
                        @if ($station->course)
                            <p class="text-xs text-gray-500 mt-2">
                                {{ $station->course->title }}
                                @if ($station->has_access && $station->total_lessons > 0)
                                    · {{ $station->completed_lessons }} из {{ $station->total_lessons }}
                                    ({{ $station->percent }}%)
                                @elseif (! $station->has_access)
                                    · курс на витрине — доступ откроется с записью
                                @endif
                            </p>
                            @if ($station->has_access && $station->total_lessons > 0)
                                <div class="mt-2 h-2 bg-white rounded-full overflow-hidden max-w-xs border border-gray-100">
                                    <div class="h-full bg-brand rounded-full" style="width: {{ $station->percent }}%"></div>
                                </div>
                            @endif
                        @else
                            <p class="text-xs text-gray-400 mt-2">Курс для этой станции ещё не привязан (паттерн «{{ $station->pattern }}»).</p>
                        @endif
                    </div>
                    @if ($station->course && $station->has_access)
                        <a href="{{ route('student.course', $station->course->slug) }}"
                           class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-bold hover:border-brand hover:text-brand">
                            Открыть
                        </a>
                    @elseif ($station->course)
                        <a href="{{ route('shop.course.show', $station->course->slug) }}"
                           class="shrink-0 inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-bold hover:border-brand hover:text-brand">
                            На витрине
                        </a>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    {{-- R29.7 ladder offer: only after station completed; «станция подождёт» --}}
    @if ($ladderOffer && ! $suppressOffers)
        <section class="mb-8 rounded-2xl border border-indigo-100 bg-indigo-50/50 p-5"
                 data-offer-kind="ladder"
                 data-track-impression="offer.impression"
                 data-track-kind="ladder"
                 data-track-to="{{ $ladderOffer->to_key }}">
            <p class="text-[10px] font-bold uppercase tracking-widest text-indigo-600 mb-1">Следующая станция</p>
            <h3 class="text-lg font-extrabold text-[#101010] mb-1">{{ $ladderOffer->headline }}</h3>
            <p class="text-sm text-gray-600 mb-4">{{ $ladderOffer->body }}</p>
            <a href="{{ $ladderOffer->cta_url }}"
               class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold"
               data-track-event="offer.click"
               data-track-kind="ladder">
                {{ $ladderOffer->cta_label }}
            </a>
        </section>
    @endif

    <div class="mb-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Сертификаты</div>
    <div class="space-y-3">
        @forelse ($certificates as $cert)
            <article class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h3 class="font-extrabold text-[#101010]">{{ $cert->course->title ?? 'Курс' }}</h3>
                    <p class="text-xs text-gray-500 mt-1">выдан {{ $cert->created_at?->format('d.m.Y') }}</p>
                </div>
                <a href="{{ route('student.certificate.download', $cert->id) }}"
                   class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-gray-200 text-sm font-bold hover:border-brand hover:text-brand">
                    Скачать PDF
                </a>
            </article>
        @empty
            <p class="text-sm text-gray-500">Сертификатов пока нет — они появятся после завершения курса.</p>
        @endforelse
    </div>
</div>

@endsection
