{{-- «Подключить уведомления» (H3339): deep-link кнопки всех настроенных каналов.
     По клику бот привязывает чат к заявке и первым сообщением присылает полный
     словарь статусов курса; других сообщений по этой подписке не будет. --}}
@php
    $statusMeta = \App\Support\ChannelMeta::all();
@endphp

<section class="mt-8 w-full max-w-xl mx-auto bg-white/10 border border-white/15 rounded-2xl p-6 text-center">
    <h2 class="text-lg md:text-xl font-extrabold mb-2">Подключить уведомления</h2>
    <p class="text-sm text-gray-300 leading-relaxed mb-5">
        Нажмите кнопку — бот сразу придёт со списком статусов этого курса.
        Рекламных рассылок школы в этой подписке нет.
    </p>

    <div class="flex flex-wrap justify-center gap-3">
        @foreach($links as $channel => $url)
            @php $meta = $statusMeta[$channel] ?? null; @endphp
            @if($meta)
                <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                   class="group inline-flex items-center gap-2 px-6 py-3 text-sm font-bold rounded-xl text-white
                          bg-gradient-to-r {{ $meta['gradient'] }} hover:brightness-110
                          transition-all duration-200 hover:scale-105 {{ $meta['shadow'] }}">
                    <span class="inline-flex items-center justify-center transition-transform group-hover:-translate-y-0.5">
                        {!! $meta['svg'] !!}
                    </span>
                    <span>{{ $meta['label'] }}</span>
                </a>
            @endif
        @endforeach
    </div>

    @if(isset($links['vk']))
        <p class="mt-4 text-xs text-gray-400 max-w-md mx-auto leading-relaxed">
            Если выбрали <strong class="text-gray-300">ВКонтакте</strong> — после открытия чата
            напишите боту любое сообщение, он ответит списком статусов.
        </p>
    @endif
</section>
