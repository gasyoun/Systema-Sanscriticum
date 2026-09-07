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
    </style>

    <div class="space-y-6">
        @foreach($fullSchedulePosts as $post)
            <div class="full-schedule-block p-6 rounded-2xl bg-[#111622] border border-[#1F2636]">
                {!! $post->html() !!}
            </div>
        @endforeach
    </div>
</section>
@endif
