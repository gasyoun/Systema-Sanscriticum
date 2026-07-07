@if(!empty($continueLearningAction))
    @php($action = $continueLearningAction)
    <section class="mb-8 bg-white border border-gray-200 rounded-2xl shadow-[0_2px_14px_rgba(16,16,16,0.04)] p-5 md:p-6" aria-label="Продолжить обучение">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
            <div class="min-w-0 flex items-start gap-4">
                <div class="w-11 h-11 rounded-xl bg-orange-50 border border-orange-100 text-[#E85C24] flex items-center justify-center shrink-0">
                    <i class="fas {{ ($action['kind'] ?? '') === 'debt' ? 'fa-credit-card' : ((($action['kind'] ?? '') === 'homework') ? 'fa-pen-nib' : 'fa-play') }} text-sm"></i>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-extrabold uppercase tracking-widest text-gray-400 mb-1">Следующий шаг</p>
                    <h3 class="text-xl md:text-2xl font-extrabold text-gray-900 leading-tight">{{ $action['title'] }}</h3>
                    @if(!empty($action['body']))
                        <p class="mt-1 text-sm md:text-base font-semibold text-gray-700 leading-snug">{{ $action['body'] }}</p>
                    @endif
                    @if(!empty($action['meta']))
                        <p class="mt-1 text-sm text-gray-500 leading-snug">{{ $action['meta'] }}</p>
                    @endif
                    @if(array_key_exists('progress', $action))
                        <div class="mt-3 max-w-sm">
                            <div class="flex items-center justify-between text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">
                                <span>Прогресс</span>
                                <span>{{ $action['progress'] }}%</span>
                            </div>
                            <div class="h-1.5 rounded-full bg-gray-100 overflow-hidden">
                                <div class="h-full rounded-full bg-[#E85C24]" style="width: {{ $action['progress'] }}%"></div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @php($cta = $action['cta'] ?? null)
            @if($cta)
                <div class="shrink-0 w-full lg:w-auto">
                    @if(($cta['method'] ?? 'GET') === 'POST')
                        <form method="POST" action="{{ $cta['url'] }}">
                            @csrf
                            <button type="submit" class="w-full lg:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#E85C24] hover:bg-[#d34f1c] text-white text-sm font-extrabold transition-colors shadow-sm">
                                <span>{{ $cta['label'] }}</span>
                                <i class="fas fa-arrow-right text-xs opacity-80"></i>
                            </button>
                        </form>
                    @elseif(($cta['method'] ?? 'GET') === 'TAB')
                        <button type="button" @click="activeTab = 'debts'" class="w-full lg:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#E85C24] hover:bg-[#d34f1c] text-white text-sm font-extrabold transition-colors shadow-sm">
                            <span>{{ $cta['label'] }}</span>
                            <i class="fas fa-arrow-right text-xs opacity-80"></i>
                        </button>
                    @else
                        <a href="{{ $cta['url'] }}" class="w-full lg:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-[#E85C24] hover:bg-[#d34f1c] text-white text-sm font-extrabold transition-colors shadow-sm">
                            <span>{{ $cta['label'] }}</span>
                            <i class="fas fa-arrow-right text-xs opacity-80"></i>
                        </a>
                    @endif
                </div>
            @endif
        </div>
    </section>
@endif
