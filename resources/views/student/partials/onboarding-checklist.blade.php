{{-- P0-онбординг: чеклист первых шагов. Скрываем, когда все шаги выполнены. --}}
@if (! empty($onboarding) && ! $onboarding['allComplete'])
    @php($pct = (int) round($onboarding['completed'] / max($onboarding['total'], 1) * 100))
    {{-- Компактные кнопки привязки/отвязки ботов прямо в шаге онбординга.
         Большие карточки ниже на дашборде остаются для управления после онбординга. --}}
    @php($obSettings = \App\Models\MarketingSetting::cached())
    @php($obShowTg = (bool) $obSettings?->student_telegram_bot_enabled)
    @php($obShowVk = (bool) $obSettings?->student_vk_bot_enabled)
    @php($obTgConnected = filled(auth()->user()?->telegram_id))
    @php($obVkConnected = filled(auth()->user()?->vk_id))
    <div class="mb-6 rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50 to-white shadow-sm overflow-hidden">
        <div class="px-5 sm:px-6 pt-5 pb-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-lg font-extrabold text-[#101010] flex items-center gap-2">
                        <i class="fas fa-seedling text-brand"></i> С чего начать
                    </h3>
                    <p class="text-gray-500 text-sm mt-0.5">Несколько шагов — и вы освоитесь в кабинете.</p>
                </div>
                <span class="shrink-0 text-sm font-bold text-brand tabular-nums">
                    {{ $onboarding['completed'] }} / {{ $onboarding['total'] }}
                </span>
            </div>

            <div class="mt-3 h-2 w-full rounded-full bg-orange-100 overflow-hidden">
                <div class="h-2 rounded-full bg-brand transition-all" style="width: {{ $pct }}%"></div>
            </div>
        </div>

        <ul class="divide-y divide-orange-50">
            @foreach ($onboarding['steps'] as $step)
                @php($obIsMessenger = $step['key'] === 'link_messenger' && ($obShowTg || $obShowVk))
                <li @class([
                    'gap-3 px-5 sm:px-6 py-3',
                    'flex items-center' => ! $obIsMessenger,
                    'flex flex-wrap items-center' => $obIsMessenger,
                ])>
                    <span @class([
                        'shrink-0 w-9 h-9 rounded-full flex items-center justify-center text-base',
                        'bg-green-100 text-green-600' => $step['done'],
                        'bg-orange-100 text-brand' => ! $step['done'],
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

                    @if ($obIsMessenger)
                        {{-- Компактные кнопки привязки/отвязки ботов --}}
                        <div class="flex flex-wrap items-center gap-2 justify-end basis-full sm:basis-auto sm:shrink-0 mt-2 sm:mt-0 pl-12 sm:pl-0">
                            @if ($obShowTg)
                                @if ($obTgConnected)
                                    <span class="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold">
                                        <i class="fab fa-telegram-plane"></i> Telegram
                                        <form method="POST" action="{{ route('student.messenger.disconnect', 'telegram') }}"
                                              onsubmit="return confirm('Отвязать Telegram-бота? Уведомления по учебе перестанут приходить. Подключить заново можно в любой момент.');"
                                              class="inline-flex">
                                            @csrf
                                            <button type="submit" title="Отвязать Telegram" class="ml-0.5 w-5 h-5 inline-flex items-center justify-center rounded-md text-emerald-500 hover:text-white hover:bg-red-500 transition-colors">
                                                <i class="fas fa-times text-[11px]"></i>
                                            </button>
                                        </form>
                                    </span>
                                @else
                                    {{-- H3313: привязка через CSRF-защищённый POST --}}
                                    <form method="POST" action="{{ route('telegram.connect.start') }}" target="_blank" class="inline-flex">
                                        @csrf
                                        <button type="submit"
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#0088cc] hover:bg-[#0077b5] text-white text-xs font-bold transition-colors">
                                            <i class="fab fa-telegram-plane"></i> Telegram
                                        </button>
                                    </form>
                                @endif
                            @endif

                            @if ($obShowVk)
                                @if ($obVkConnected)
                                    <span class="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold">
                                        <i class="fab fa-vk"></i> VK
                                        <form method="POST" action="{{ route('student.messenger.disconnect', 'vk') }}"
                                              onsubmit="return confirm('Отвязать ВК-бота? Уведомления по учебе перестанут приходить. Подключить заново можно в любой момент.');"
                                              class="inline-flex">
                                            @csrf
                                            <button type="submit" title="Отвязать VK" class="ml-0.5 w-5 h-5 inline-flex items-center justify-center rounded-md text-emerald-500 hover:text-white hover:bg-red-500 transition-colors">
                                                <i class="fas fa-times text-[11px]"></i>
                                            </button>
                                        </form>
                                    </span>
                                @else
                                    {{-- H3313: привязка через CSRF-защищённый POST --}}
                                    <form method="POST" action="{{ route('vk.connect.start') }}" target="_blank" class="inline-flex">
                                        @csrf
                                        <button type="submit"
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-[#0077FF] hover:bg-[#005ce6] text-white text-xs font-bold transition-colors">
                                            <i class="fab fa-vk"></i> VK
                                        </button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    @elseif (! $step['done'] && $step['url'])
                        <a href="{{ $step['url'] }}"
                           class="shrink-0 inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-brand text-white text-xs font-bold hover:bg-[#d24e1a] transition-colors">
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
