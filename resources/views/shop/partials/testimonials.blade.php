{{--
    «Отзывы» — course->testimonials (пивот, по sort_order). Скрыт при пустом.
    Правило «1–2 конкретных отзыва, не стена» (план «маркетинговые акценты»
    10-11-2026): на лендинге курса показываем максимум 2 карточки — по ним
    видно контекст (автор, город, медиа) — остальные доступны по ссылке в
    библиотеку /otzyvy. Соц-доказательство навалом измерено анти-рычагом
    (−6); внимательно читают отзывы 51% — поэтому прячем не отзывы, а стену.
--}}
@php
    $all = ($course->testimonials ?? collect())->where('is_visible', true)->values();
    $items = $all->take(2);
@endphp
@if($items->isNotEmpty())
<section class="mb-16 lg:mb-20" data-analytics="testimonials">
    <h2 class="text-3xl font-bold text-white mb-8">Отзывы учеников</h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        @foreach($items as $t)
            <figure class="flex flex-col rounded-2xl bg-[#111622] border border-[#1F2636] p-6">
                @if($t->rating)
                    <div class="flex gap-0.5 mb-3 text-brand">
                        @for($i = 1; $i <= 5; $i++)
                            <i class="fa{{ $i <= $t->rating ? 's' : 'r' }} fa-star text-xs"></i>
                        @endfor
                    </div>
                @endif
                <blockquote class="text-slate-300 leading-relaxed flex-1 whitespace-pre-line">{{ $t->body }}</blockquote>
                @if($t->mediaLink())
                    <a href="{{ $t->mediaLink() }}" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 text-sm font-bold text-[#38BDF8] hover:text-[#2da4dd] mt-4">
                        <i class="fas fa-play-circle"></i> Смотреть/слушать отзыв
                    </a>
                @endif
                <figcaption class="flex items-center gap-3 mt-5 pt-4 border-t border-[#1F2636]">
                    @if($t->avatar_path)
                        <img src="{{ Storage::url($t->avatar_path) }}" alt="{{ $t->author_name }}"
                             class="w-10 h-10 rounded-full object-cover shrink-0">
                    @else
                        <div class="w-10 h-10 rounded-full bg-[#1F2636] flex items-center justify-center text-sm font-bold text-slate-300 shrink-0">
                            {{ mb_strtoupper(mb_substr($t->author_name, 0, 1)) }}
                        </div>
                    @endif
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-white truncate">{{ $t->author_name }}</div>
                        @if(filled($t->city))
                            <div class="text-xs text-slate-500 truncate">{{ $t->city }}</div>
                        @endif
                    </div>
                </figcaption>
            </figure>
        @endforeach
    </div>

    @if($all->count() > $items->count())
        <p class="mt-6">
            <a href="{{ route('shop.testimonials.library') }}"
               class="inline-flex items-center gap-2 text-sm font-bold text-[#38BDF8] hover:text-[#2da4dd]">
                Все отзывы ({{ $all->count() }})
                <i class="fas fa-arrow-right text-xs"></i>
            </a>
        </p>
    @endif
</section>
@endif
