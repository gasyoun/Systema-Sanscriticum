{{-- H4340: публичная страница «Расписание» — все расписания всех курсов --}}
{{-- H4647 (MG 13-09-2026): сводка-оглавление над списком (количество курсов, --}}
{{-- преподаватели, якоря на места в списке), единицы пронумерованы и идут --}}
{{-- по дню недели ближайшего занятия с понедельника, списки занятий — --}}
{{-- гармошка (details/summary), кнопка прошедших — иконка 34×34 справа сверху --}}
{{-- H4649 (MG 13-09-2026): имя преподавателя — ссылка везде: «Ведут:», оглавление, --}}
{{-- заголовок единицы (было: простой текст вне строки «Ведут:»). --}}
@extends('layouts.shop')

@section('title', 'Расписание занятий')

@section('content')
@php
    // H4434: client_tz — даты обернуты в <time data-msk-timestamp>, JS ниже
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

    <section class="mb-8 rounded-2xl border border-[#E85C24] bg-[#111622] p-6" aria-labelledby="grammar-intake">
        <p class="text-[#E85C24] font-bold mb-2">Набор открыт</p>
        <h2 id="grammar-intake" class="text-2xl font-bold text-white mb-2">Новые онлайн-группы грамматики санскрита</h2>
        <p class="text-slate-300 mb-4">С М. Ю. Гасунсом: суббота в 12:00 или вторник в 08:00 МСК. Если оба времени не подходят, укажите это в заявке.</p>
        <div class="flex flex-wrap items-center gap-3">
            <a href="/ga/m26-schedule-c" class="inline-flex items-center gap-2 py-2.5 px-4 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold transition-all">Встать в список ожидания <i class="fas fa-arrow-right text-[10px]"></i></a>
            {{-- H5233: заметная ссылка на живые группы семейства Кочергиной --}}
            <a href="/raspisanie/kochergina" class="inline-flex items-center gap-2 py-2.5 px-4 rounded-xl border border-[#E85C24] text-[#E85C24] hover:bg-[#E85C24] hover:text-white text-sm font-bold transition-all">Группы по Кочергиной — канва и заявка <i class="fas fa-arrow-right text-[10px]"></i></a>
        </div>
    </section>

    @if(!$flagOn)
        <p class="text-slate-500">Расписание курсов скоро появится на этой странице.</p>
    @elseif($courses->isEmpty())
        <p class="text-slate-500">Сейчас нет курсов с предстоящими занятиями.</p>
    @else
        <style>
            .fs-head { color: #fff; font-weight: 700; margin: 0 0 .75rem; }
            .fs-body { color: #cbd5e1; line-height: 1.7; }
            .fs-body strong { color: #fff; }

            /* H4387 + H4647: статус + иконка-кнопка 34×34 справа сверху (темная тема). */
            .fs-top { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin: 0 0 .75rem; }
            .fs-status { color: #94a3b8; font-size: .925rem; margin: 0; }
            .fs-toggle {
                flex: 0 0 auto;
                width: 34px; height: 34px;
                display: inline-flex; align-items: center; justify-content: center;
                font: inherit; cursor: pointer;
                background: #1F2636; color: #e2e8f0;
                border: 1px solid #2b3550; border-radius: 8px;
                padding: 0;
                transition: border-color .2s ease;
            }
            .fs-toggle:hover { border-color: #E85C24; }
            .fs-toggle svg { display: block; }
            .fs-toggle .ic-hide { display: none; }
            .fs-toggle[aria-expanded="true"] .ic-show { display: none; }
            .fs-toggle[aria-expanded="true"] .ic-hide { display: inline; }
            .fs-past { color: #8b96ab; }
            .fs-past strong { color: #b9c3d6; }

            /* H4647: сводка-оглавление + гармошка курсов. */
            html { scroll-behavior: smooth; }
            .sch-index-line { color: #e2e8f0; margin: 0 0 .9rem; }
            .sch-index-teacher { color: #cbd5e1; text-decoration: underline; text-underline-offset: 3px; }
            .sch-index-teacher:hover { color: #E85C24; }
            .sch-toc { margin: 0; padding-left: 1.25rem; color: #94a3b8; }
            .sch-toc li { margin: .2rem 0; }
            .sch-toc-link { color: #e2e8f0; }
            .sch-toc-link:hover { color: #E85C24; }
            .sch-toc-teacher { color: #94a3b8; text-decoration: underline; text-underline-offset: 3px; }
            .sch-toc-teacher:hover { color: #E85C24; }
            .sch-acc { scroll-margin-top: 1rem; }
            .sch-sum {
                list-style: none; cursor: pointer;
                display: flex; align-items: baseline; flex-wrap: wrap;
                gap: .3rem .8rem; padding: 1.25rem 1.5rem; margin: 0;
            }
            .sch-sum::-webkit-details-marker { display: none; }
            .sch-no {
                flex: 0 0 auto; align-self: center;
                min-width: 34px; height: 34px; padding: 0 6px;
                display: inline-flex; align-items: center; justify-content: center;
                font-weight: 700; color: #E85C24;
                border: 1px solid #2b3550; border-radius: 8px;
            }
            .sch-main { display: flex; flex-direction: column; gap: .1rem; min-width: 0; }
            .sch-title { color: #fff; font-weight: 700; font-size: 1.15rem; }
            .sch-sum:hover .sch-title { color: #E85C24; }
            .sch-teacher { color: #94a3b8; font-size: .875rem; text-decoration: underline; text-underline-offset: 3px; }
            .sch-teacher:hover { color: #E85C24; }
            .sch-meta { color: #64748b; font-size: .875rem; align-self: center; margin-left: auto; }
            .sch-chev { color: #64748b; font-size: .8rem; align-self: center; transition: transform .2s ease; }
            .sch-acc[open] .sch-chev { transform: rotate(180deg); }
            .sch-body { color: #cbd5e1; border-top: 1px solid #1F2636; margin: 0 1.5rem; padding: 1.25rem 0 1.5rem; }
            .sch-cta { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; margin-top: 1.25rem; }
        </style>

        {{-- H4647: сводка — количество курсов, кто ведет, якорное оглавление --}}
        <nav class="p-5 mb-8 rounded-2xl bg-[#111622] border border-[#1F2636]" aria-label="Оглавление расписания">
            <p class="sch-index-line">
                Курсов: {{ $courses->count() }}@if($teachers->isNotEmpty()) · Ведут:
                @foreach($teachers as $t)<a href="{{ $t['url'] }}" class="sch-index-teacher">{{ $t['name'] }}</a>@if(!$loop->last), @endif@endforeach
                @endif
            </p>
            <ol class="sch-toc">
                @foreach($courses as $row)
                    <li>
                        <a href="#sch-{{ $row['no'] }}" class="sch-toc-link">{{ $row['no'] }}. {{ $row['course']->title }}</a>@if($row['course']->teacher) — <a href="/online/prepodavatel/{{ \App\Support\ShopCatalogUrl::encodeWords($row['course']->teacher->name) }}" class="sch-toc-teacher">{{ $row['course']->teacher->name }}</a>@endif
                    </li>
                @endforeach
            </ol>
        </nav>

        <div class="space-y-6" id="schedule-list">
            @foreach($courses as $row)
                @php $course = $row['course']; @endphp
                <details class="sch-acc rounded-2xl bg-[#111622] border border-[#1F2636]" id="sch-{{ $row['no'] }}">
                    <summary class="sch-sum">
                        <span class="sch-no">{{ $row['no'] }}</span>
                        <span class="sch-main">
                            <span class="sch-title">{{ $course->title }}</span>
                            @if($course->teacher)<a href="/online/prepodavatel/{{ \App\Support\ShopCatalogUrl::encodeWords($course->teacher->name) }}" class="sch-teacher">{{ $course->teacher->name }}</a>@endif
                        </span>
                        <span class="sch-meta">@if($row['weekdayRu']){{ $row['weekdayRu'] }} · @endifзанятий: {{ $row['lessonsCount'] }}</span>
                        <i class="fas fa-chevron-down sch-chev" aria-hidden="true"></i>
                    </summary>
                    <div class="sch-body">
                        @foreach($row['posts'] as $post)
                            <div class="mb-4 last:mb-0">{!! $post->html($clientTz) !!}</div>
                        @endforeach

                        <div class="sch-cta">
                            <a href="{{ route('shop.course.show', $course) }}"
                               class="inline-flex items-center gap-2 py-2.5 px-4 rounded-xl bg-brand hover:bg-brand-hover text-white text-xs font-bold transition-all">
                                Записаться на курс
                                <i class="fas fa-arrow-right text-[10px]"></i>
                            </a>
                            <a href="{{ route('shop.course.show', $course) }}"
                               class="text-sm text-slate-400 hover:text-brand transition-colors">Страница курса</a>
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    @endif

    @include('partials.schedule-past-toggle')
    @include('partials.client-tz-convert')
</div>
@endsection
