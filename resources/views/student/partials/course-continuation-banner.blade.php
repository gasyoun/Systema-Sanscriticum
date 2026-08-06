{{-- H2333: self-service “where is lesson 1?” when this shell starts mid-programme --}}
@if (! empty($continuationBanner))
    @php
        $banner = $continuationBanner;
        $from = (int) ($banner['continues_from'] ?? 2);
        $enrolled = (bool) ($banner['student_enrolled'] ?? false);
    @endphp
    <aside class="mb-6 rounded-2xl border border-sky-200 bg-sky-50/80 p-5 shadow-sm"
           data-testid="course-continuation-banner"
           aria-label="Где начало программы">
        <p class="text-[10px] font-bold uppercase tracking-widest text-sky-700/80 mb-2">
            Продолжение программы
        </p>
        <p class="text-sm text-sky-950 leading-relaxed">
            В этом курсе в кабинете записи
            <strong>с {{ $from }}-го занятия</strong>.
            Начало (с 1-го) — в курсе
            <strong>«{{ $banner['predecessor_title'] }}»</strong>.
        </p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($enrolled && ! empty($banner['first_lesson_url']))
                <a href="{{ $banner['first_lesson_url'] }}"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-[#E85C24] text-white text-sm font-bold hover:bg-[#d04a15] transition-colors"
                   data-testid="continuation-first-lesson">
                    Открыть 1-е занятие
                </a>
            @endif
            <a href="{{ $banner['course_url'] }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl border border-sky-300 bg-white text-sky-900 text-sm font-bold hover:border-[#E85C24] hover:text-[#E85C24] transition-colors"
               data-testid="continuation-predecessor-course">
                @if ($enrolled)
                    К курсу с началом
                @else
                    Страница курса с началом
                @endif
            </a>
        </div>
        @unless ($enrolled)
            <p class="mt-3 text-xs text-sky-800/80">
                Если курса нет в «Мои курсы» — напишите куратору, откроем доступ к архиву начала.
            </p>
        @endunless
    </aside>
@endif
