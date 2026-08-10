{{-- «Отзывы» — course->testimonials (пивот, по sort_order). Скрыт при пустом. --}}
@php $items = ($course->testimonials ?? collect())->where('is_visible', true)->values(); @endphp
@if($items->isNotEmpty())
<section class="mb-16 lg:mb-20" data-analytics="testimonials">
    <h2 class="text-3xl font-bold text-white mb-8">Отзывы учеников</h2>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
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
                @if($t->media_url)
                    <a href="{{ $t->media_url }}" target="_blank" rel="noopener"
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
</section>
@endif
