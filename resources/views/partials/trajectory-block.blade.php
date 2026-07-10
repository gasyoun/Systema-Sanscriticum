{{--
    Трёхшаговая траектория обучения (H431, Phase 1 п.1): «Письмо/чтение →
    Грамматика → Тексты/чанты». Каждый шаг резолвится в реальный курс
    (App\Support\TrajectoryPaths) — свободная первая ступень и дата
    ближайшего потока показываются только когда они реально есть.
--}}
@php
    $steps = $steps ?? [];
@endphp
@if(!empty($steps))
<section class="mb-16 lg:mb-20" data-analytics="trajectory-block">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        @foreach($steps as $i => $step)
            <div class="relative flex flex-col h-full bg-[#161b28] border border-gray-700/60 rounded-2xl p-6 hover:border-[#E85C24]/60 transition-all duration-300">
                <div class="flex items-center gap-3 mb-3">
                    <span class="w-8 h-8 shrink-0 rounded-full bg-[#E85C24]/15 text-[#E85C24] text-sm font-extrabold flex items-center justify-center">
                        {{ $i + 1 }}
                    </span>
                    <h3 class="text-lg font-bold text-white leading-tight">{{ $step['title'] }}</h3>
                </div>
                <p class="text-sm text-gray-400 leading-relaxed mb-4 flex-grow">{{ $step['description'] }}</p>

                @if($step['nextDateLabel'])
                    <p class="text-xs text-[#2AABEE] font-semibold mb-3">{{ $step['nextDateLabel'] }}</p>
                @endif

                <div class="mt-auto flex flex-col gap-2 pt-3 border-t border-gray-700/50">
                    @if($step['freeUrl'])
                        <a href="{{ $step['freeUrl'] }}" class="text-sm font-bold text-[#2AABEE] hover:text-white transition-colors">
                            Бесплатный первый шаг →
                        </a>
                    @endif
                    <a href="{{ $step['url'] }}" class="text-sm font-bold text-white hover:text-[#E85C24] transition-colors">
                        Перейти к курсу →
                    </a>
                </div>

                @if($i < count($steps) - 1)
                    <div class="hidden md:flex absolute top-1/2 -right-4 -translate-y-1/2 z-10 text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</section>
@endif
