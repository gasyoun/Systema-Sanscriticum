{{-- P0-онбординг: чеклист первых шагов. Скрываем, когда все шаги выполнены. --}}
@if (! empty($onboarding) && ! $onboarding['allComplete'])
    @php($pct = (int) round($onboarding['completed'] / max($onboarding['total'], 1) * 100))
    <div class="mb-6 rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50 to-white shadow-sm overflow-hidden">
        <div class="px-5 sm:px-6 pt-5 pb-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-extrabold text-[#101010] flex items-center gap-2">
                        <i class="fas fa-seedling text-[#E85C24]"></i> С чего начать
                    </h3>
                    <p class="text-gray-500 text-sm mt-0.5">Несколько шагов — и вы освоитесь в кабинете.</p>
                </div>
                <span class="shrink-0 text-sm font-bold text-[#E85C24] tabular-nums">
                    {{ $onboarding['completed'] }} / {{ $onboarding['total'] }}
                </span>
            </div>

            <div class="mt-3 h-2 w-full rounded-full bg-orange-100 overflow-hidden">
                <div class="h-2 rounded-full bg-[#E85C24] transition-all" style="width: {{ $pct }}%"></div>
            </div>
        </div>

        <ul class="divide-y divide-orange-50">
            @foreach ($onboarding['steps'] as $step)
                <li class="flex items-center gap-3 px-5 sm:px-6 py-3">
                    <span @class([
                        'shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-base',
                        'bg-green-100 text-green-600' => $step['done'],
                        'bg-orange-100 text-[#E85C24]' => ! $step['done'],
                    ])>
                        <i class="fas {{ $step['done'] ? 'fa-check' : $step['icon'] }}"></i>
                    </span>

                    <div class="min-w-0 flex-1">
                        <p @class([
                            'font-bold text-sm',
                            'text-gray-400 line-through' => $step['done'],
                            'text-gray-800' => ! $step['done'],
                        ])>{{ $step['label'] }}</p>
                        @unless ($step['done'])
                            <p class="text-gray-500 text-xs mt-0.5">{{ $step['hint'] }}</p>
                        @endunless
                    </div>

                    @if (! $step['done'] && $step['url'])
                        <a href="{{ $step['url'] }}"
                           class="shrink-0 inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-[#E85C24] text-white text-xs font-bold hover:bg-[#d24e1a] transition-colors">
                            Перейти <i class="fas fa-arrow-right text-[10px]"></i>
                        </a>
                    @elseif ($step['done'])
                        <span class="shrink-0 text-green-600 text-xs font-bold">Готово</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
