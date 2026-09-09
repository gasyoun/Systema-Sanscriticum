{{-- H4328: полное расписание курса (обзорное + занятия 1–N) тем же билдером, --}}
{{-- что и Telegram-пост. Принимает $fullSchedulePosts — list<FullSchedulePost>. --}}
@if(!empty($fullSchedulePosts))
<section id="full-schedule" class="mb-16 lg:mb-20">
    <div class="flex items-center gap-4 mb-8">
        <h2 class="text-3xl font-bold text-white">Полное расписание курса</h2>
    </div>

    <style>
        .full-schedule-block .fs-head { color: #fff; font-weight: 700; margin: 0 0 .75rem; }
        .full-schedule-block .fs-body { color: #cbd5e1; line-height: 1.7; }
        .full-schedule-block .fs-body strong { color: #fff; }

        /* H4387: скрытие прошедших занятий + кнопка-таб (тёмная тема). */
        .full-schedule-block .fs-status { color: #94a3b8; font-size: .925rem; margin: 0 0 .6rem; }
        .full-schedule-block .fs-toggle {
            font: inherit; font-size: .875rem; cursor: pointer;
            background: #1F2636; color: #e2e8f0;
            border: 1px solid #2b3550; border-radius: 8px;
            padding: 5px 12px; margin: 0 0 .75rem;
            transition: border-color .2s ease;
        }
        .full-schedule-block .fs-toggle:hover { border-color: #E85C24; }
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
</section>
@endif
