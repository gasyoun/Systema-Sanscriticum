{{--
    Карточка материала библиотеки курса (фаза 1 — только ссылка).
    Ожидает: $material (CourseMaterial), $kinds (карта подписей), $kindIcons.
    Ссылка ведёт наружу, поэтому target=_blank + rel="noopener noreferrer",
    и хост показан явно — студент видит, куда его отправляют, до клика.
--}}
<a href="{{ $material->effectiveUrl() }}"
   target="_blank"
   rel="noopener noreferrer"
   class="group bg-white rounded-2xl border border-gray-200 p-5 hover:border-[#E85C24]/40 hover:shadow-[0_8px_30px_rgba(232,92,36,0.08)] hover:-translate-y-0.5 transition-all duration-200 flex flex-col">

    <div class="flex items-start justify-between gap-3 mb-3">
        <span class="inline-flex items-center gap-1.5 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-md bg-gray-50 text-gray-600 border border-gray-200">
            <i class="fas {{ $kindIcons[$material->kind] ?? 'fa-link' }} text-[9px]"></i>
            {{ $kinds[$material->kind] ?? $material->kind }}
        </span>

        @if($material->year)
            <span class="text-xs text-gray-400 font-medium whitespace-nowrap">{{ $material->year }}</span>
        @endif
    </div>

    <h3 class="text-lg font-extrabold text-[#1A1A1A] leading-tight mb-2 group-hover:text-[#E85C24] transition-colors">
        {{ $material->title }}
    </h3>

    @if($material->author)
        <p class="text-sm text-gray-600 font-semibold mb-1">{{ $material->author }}</p>
    @endif

    @if($material->note)
        <p class="text-sm text-gray-500 leading-relaxed mb-4 line-clamp-3">{{ $material->note }}</p>
    @endif

    <div class="mt-auto pt-3 border-t border-gray-100 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-xs text-gray-500 min-w-0">
            <i class="fas fa-cloud text-gray-400 shrink-0"></i>
            <span class="truncate">{{ $material->host ?? 'внешняя ссылка' }}</span>
        </div>
        <span class="inline-flex items-center gap-1 text-xs font-bold text-[#E85C24] shrink-0">
            Открыть
            <i class="fas fa-external-link-alt text-[10px] group-hover:translate-x-0.5 transition-transform"></i>
        </span>
    </div>
</a>
