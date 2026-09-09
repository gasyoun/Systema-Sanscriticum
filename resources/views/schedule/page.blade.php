{{-- H4340: публичная страница «Расписание» — все расписания всех курсов --}}
@extends('layouts.shop')

@section('title', 'Расписание занятий')

@section('content')
@php
    // H4434: client_tz — даты обёрнуты в <time data-msk-timestamp>, JS ниже
    // конвертирует их в зону устройства гостя (MG 09-09-2026).
    $clientTz = ['client_tz' => true];
@endphp
<div class="max-w-4xl mx-auto px-4 py-12">
    <h1 class="text-3xl font-bold text-white mb-3">Расписание занятий</h1>
    <p class="text-slate-400 mb-10">
        Полное расписание идущих и набираемых курсов Общества ревнителей санскрита.
        Время — московское; если вы не в Москве, рядом появится ваше местное время.
        Запись на курс — на странице курса.
    </p>

    @if(!$flagOn)
        <p class="text-slate-500">Расписание курсов скоро появится на этой странице.</p>
    @elseif($courses->isEmpty())
        <p class="text-slate-500">Сейчас нет курсов с предстоящими занятиями.</p>
    @else
        <style>
            .fs-head { color: #fff; font-weight: 700; margin: 0 0 .75rem; }
            .fs-body { color: #cbd5e1; line-height: 1.7; }
            .fs-body strong { color: #fff; }

            /* H4387: скрытие прошедших занятий + кнопка-таб (тёмная тема). */
            .fs-status { color: #94a3b8; font-size: .925rem; margin: 0 0 .6rem; }
            .fs-toggle {
                font: inherit; font-size: .875rem; cursor: pointer;
                background: #1F2636; color: #e2e8f0;
                border: 1px solid #2b3550; border-radius: 8px;
                padding: 5px 12px; margin: 0 0 .75rem;
                transition: border-color .2s ease;
            }
            .fs-toggle:hover { border-color: #E85C24; }
            .fs-past { color: #8b96ab; }
            .fs-past strong { color: #b9c3d6; }
        </style>

        <div class="space-y-8">
            @foreach($courses as $row)
                @php $course = $row['course']; @endphp
                <section class="p-6 rounded-2xl bg-[#111622] border border-[#1F2636]">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 mb-4">
                        <a href="{{ route('shop.course.show', $course) }}"
                           class="text-xl font-bold text-white hover:text-brand transition-colors">
                            {{ $course->title }}
                        </a>
                        @if($course->teacher)
                            <a href="/online/prepodavatel/{{ \App\Support\ShopCatalogUrl::encodeWords($course->teacher->name) }}"
                               class="text-sm text-slate-500 hover:text-brand transition-colors">
                                {{ $course->teacher->name }}
                            </a>
                        @endif
                    </div>

                    @foreach($row['posts'] as $post)
                        <div class="mb-4 last:mb-0">{!! $post->html($clientTz) !!}</div>
                    @endforeach

                    <a href="{{ route('shop.course.show', $course) }}"
                       class="inline-flex items-center gap-2 mt-5 py-2.5 px-4 rounded-xl bg-brand hover:bg-brand-hover text-white text-xs font-bold transition-all">
                        Записаться на курс
                        <i class="fas fa-arrow-right text-[10px]"></i>
                    </a>
                </section>
            @endforeach
        </div>
    @endif

    @include('partials.schedule-past-toggle')
    @include('partials.client-tz-convert')
</div>
@endsection
