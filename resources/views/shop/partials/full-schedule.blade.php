{{-- H4328: полное расписание курса (обзорное + занятия 1–N) тем же билдером, --}}
{{-- что и Telegram-пост. Принимает $fullSchedulePosts — list<FullSchedulePost>. --}}
@if(!empty($fullSchedulePosts))
<section id="full-schedule" class="mb-16 lg:mb-20" x-data="{ open: false }" data-collapse-section>
    @include('shop.partials.collapse-header', ['title' => 'Полное расписание курса', 'bodyId' => 'full-schedule-body'])

    <div id="full-schedule-body" x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-3"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0 -translate-y-3">

    <style>
        .full-schedule-block .fs-head { color: #fff; font-weight: 700; margin: 0 0 .75rem; }
        .full-schedule-block .fs-body { color: #cbd5e1; line-height: 1.7; }
        .full-schedule-block .fs-body strong { color: #fff; }

        /* H4387 + H4647: статус + иконка-кнопка 34×34 справа сверху (темная тема). */
        .full-schedule-block .fs-top { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin: 0 0 .75rem; }
        .full-schedule-block .fs-status { color: #94a3b8; font-size: .925rem; margin: 0; }
        .full-schedule-block .fs-toggle {
            flex: 0 0 auto;
            width: 34px; height: 34px;
            display: inline-flex; align-items: center; justify-content: center;
            font: inherit; cursor: pointer;
            background: #1F2636; color: #e2e8f0;
            border: 1px solid #2b3550; border-radius: 8px;
            padding: 0;
            transition: border-color .2s ease;
        }
        .full-schedule-block .fs-toggle:hover { border-color: #E85C24; }
        .full-schedule-block .fs-toggle svg { display: block; }
        .full-schedule-block .fs-toggle .ic-hide { display: none; }
        .full-schedule-block .fs-toggle[aria-expanded="true"] .ic-show { display: none; }
        .full-schedule-block .fs-toggle[aria-expanded="true"] .ic-hide { display: inline; }
        .full-schedule-block .fs-past { color: #8b96ab; }
        .full-schedule-block .fs-past strong { color: #b9c3d6; }
    </style>

    <div class="space-y-6">
        @foreach($fullSchedulePosts as $post)
            <div class="full-schedule-block p-6 rounded-2xl bg-[#111622] border border-[#1F2636]">
                {{-- H4434: client_tz — клиентская конверсия в зону устройства гостя --}}
                {!! $post->html(['client_tz' => true]) !!}
            </div>
        @endforeach
    </div>

    @include('partials.schedule-past-toggle')
    @include('partials.client-tz-convert')
    </div>
</section>
@endif
