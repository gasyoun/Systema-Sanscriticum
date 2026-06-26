{{-- Магазин праны (spend-sink): перки за прану. Каталог редактирует админ. --}}
@php
    $perks = $pranaPerks ?? collect();
    $balance = (int) (auth()->user()->prana_balance ?? 0);
@endphp

@if($perks->isNotEmpty())
    <div class="mb-6 rounded-2xl border border-violet-100 bg-white p-5 md:p-6 shadow-sm">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center shrink-0">
                <i class="fas fa-store"></i>
            </div>
            <div>
                <h3 class="text-lg font-extrabold text-[#101010]">Магазин праны</h3>
                <p class="text-gray-500 text-sm">Потратьте прану на перки. Ваш баланс: <span class="font-bold text-[#E85C24]">🪷 {{ number_format($balance, 0, '.', ' ') }}</span></p>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach($perks as $perk)
                @php
                    $available = $perk->isAvailable();
                    $afford = $balance >= $perk->cost;
                @endphp
                <div class="rounded-xl border border-gray-100 p-4 flex flex-col {{ $available ? '' : 'opacity-60' }}">
                    <div class="flex items-start gap-3 flex-1">
                        <div class="text-2xl shrink-0">{{ $perk->icon ?: '🎁' }}</div>
                        <div class="min-w-0">
                            <div class="font-extrabold text-gray-900">{{ $perk->name }}</div>
                            @if($perk->description)
                                <div class="text-xs text-gray-500 leading-snug mt-0.5">{{ $perk->description }}</div>
                            @endif
                            @if($perk->stock !== null)
                                <div class="text-[11px] text-gray-400 mt-1">Осталось: {{ $perk->stock }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between gap-2">
                        <span class="font-extrabold text-[#E85C24] tabular-nums">🪷 {{ number_format($perk->cost, 0, '.', ' ') }}</span>
                        @if(! $available)
                            <span class="text-xs font-bold text-gray-400">Недоступно</span>
                        @elseif(! $afford)
                            <span class="text-xs font-bold text-gray-400">Не хватает праны</span>
                        @else
                            <form method="POST" action="{{ route('student.prana.redeem', $perk) }}">
                                @csrf
                                <button type="submit"
                                        class="px-4 py-2 rounded-xl bg-violet-600 hover:bg-violet-700 text-white text-sm font-bold transition-colors">
                                    Обменять
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if(($pranaRedemptions ?? collect())->isNotEmpty())
            <div class="mt-4 pt-4 border-t border-gray-100">
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Мои покупки</p>
                <ul class="space-y-1.5">
                    @foreach($pranaRedemptions as $r)
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-gray-700">{{ $r->perk_name }}</span>
                            <span class="text-xs font-bold {{ $r->status === 'fulfilled' ? 'text-emerald-600' : ($r->status === 'cancelled' ? 'text-gray-400' : 'text-amber-600') }}">
                                {{ ['pending' => 'В обработке', 'fulfilled' => 'Выполнено', 'cancelled' => 'Отменено'][$r->status] ?? $r->status }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
