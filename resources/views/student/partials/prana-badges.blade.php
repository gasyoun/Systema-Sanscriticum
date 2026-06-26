{{-- Бейджи (достижения) — геймификация. Полученные цветные, остальные приглушены с прогрессом. --}}
@php($badges = $badges ?? collect())
@if($badges->isNotEmpty())
    @php($earned = $badges->where('earned', true)->count())
    <div class="mb-6 rounded-2xl border border-amber-100 bg-white p-5 md:p-6 shadow-sm">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                <i class="fas fa-medal"></i>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-[#101010]">Достижения</h3>
                <p class="text-gray-500 text-sm">Получено <span class="font-bold text-amber-600">{{ $earned }}</span> из {{ $badges->count() }}.</p>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            @foreach($badges as $b)
                <div class="rounded-xl border p-3 text-center transition-all
                            {{ $b['earned']
                                ? 'border-amber-200 bg-gradient-to-br from-amber-50 to-white'
                                : 'border-gray-100 bg-gray-50' }}"
                     title="{{ $b['desc'] }}">
                    <div class="text-3xl mb-1 {{ $b['earned'] ? '' : 'opacity-30 grayscale' }}">{{ $b['icon'] }}</div>
                    <div class="text-sm font-extrabold {{ $b['earned'] ? 'text-gray-900' : 'text-gray-400' }}">{{ $b['name'] }}</div>
                    <div class="text-[11px] text-gray-400 leading-snug mt-0.5">{{ $b['desc'] }}</div>

                    @if($b['earned'])
                        <div class="mt-1.5 text-[11px] font-bold text-amber-600"><i class="fas fa-check-circle"></i> Получено</div>
                    @else
                        <div class="mt-1.5">
                            <div class="h-1.5 rounded-full bg-gray-200 overflow-hidden">
                                <div class="h-1.5 rounded-full bg-amber-400" style="width: {{ $b['progress'] }}%"></div>
                            </div>
                            <div class="text-[10px] text-gray-400 mt-0.5 tabular-nums">{{ $b['value'] }} / {{ $b['threshold'] }}</div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
