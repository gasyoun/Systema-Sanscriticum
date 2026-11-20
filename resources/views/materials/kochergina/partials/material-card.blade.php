{{-- Материал набора (H8210). Available → проверенная ссылка; blocked → причина, без ссылки. --}}
<div class="bg-white rounded-2xl border p-5 @if($item['status'] === 'blocked') border-amber-200 @else border-gray-100 shadow-sm @endif">
    <div class="flex items-start justify-between gap-3 mb-2">
        <h3 class="text-base font-extrabold text-gray-900 leading-snug">{{ $item['name'] }}</h3>
        @if($item['status'] === 'available')
            <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700">Открыто</span>
        @else
            <span class="shrink-0 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700">Пока недоступно</span>
        @endif
    </div>

    <p class="text-sm text-gray-600 leading-relaxed mb-3">{!! $item['desc'] !!}</p>

    @if($item['status'] === 'available' && !empty($item['url']))
        <a href="{{ $item['url'] }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-brand hover:underline mb-3">
            Открыть материал <i class="fas fa-arrow-right text-xs"></i>
        </a>
    @endif

    @if(!empty($item['anchors']))
        <div class="flex flex-wrap gap-2 mb-3">
            @foreach($item['anchors'] as $anchor)
                <a href="{{ $item['url'] }}{{ $anchor['href'] }}"
                   class="text-xs font-bold px-2.5 py-1 rounded-full bg-gray-100 text-gray-700 hover:bg-brand/10 hover:text-brand transition-colors">
                    {{ $anchor['label'] }}
                </a>
            @endforeach
        </div>
    @endif

    @if(($item['status'] ?? '') === 'blocked' && !empty($item['blocked_reason']))
        <p class="text-xs text-amber-800 leading-relaxed bg-amber-50 border border-amber-100 rounded-xl p-3">
            <span class="font-bold">Почему пока недоступно.</span> {{ $item['blocked_reason'] }}
        </p>
    @endif

    <p class="text-xs text-gray-400 mt-3">
        Версия: {{ $item['version'] }} · Формат: {{ $item['format'] }}
    </p>
</div>
