{{-- Сворачиваемый заголовок секции витрины курса. Секция несет
     x-data="{ open: false }" и data-collapse-section; клик по шапке
     переключает `open`, шеврон показывает состояние, aria-expanded
     дает состояние скринридеру. $caption — необязательная подпись
     рядом с заголовком (как «ближайшие занятия» у «Расписания»). --}}
<h2 class="mb-8">
    <button type="button"
            @click="open = ! open"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="{{ $bodyId }}"
            class="w-full flex items-center justify-between gap-4 text-left group cursor-pointer">
        <span class="flex items-center gap-4 min-w-0 flex-wrap">
            <span class="text-3xl font-bold text-white group-hover:text-brand transition-colors">{{ $title }}</span>
            @if(! empty($caption))
                <span class="text-sm font-bold text-slate-500">{{ $caption }}</span>
            @endif
        </span>
        <i class="fas fa-chevron-down shrink-0 text-xl text-slate-500 group-hover:text-brand transition-transform duration-200"
           :class="open ? 'rotate-180' : ''" aria-hidden="true"></i>
    </button>
</h2>
