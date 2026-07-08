{{-- resources/views/partials/guest-purchase-warning.blade.php --}}
{{--
    Подсказка для неавторизованных гостей перед покупкой (H323 — смягчено).

    Раньше здесь был красный блок «ВАЖНО: не регистрируйтесь самостоятельно,
    напишите куратору» — он блокировал и НОВЫХ покупателей (главный убийца
    конверсии по бенчмарку Синхронизация/Arzamas). Теперь: новый покупатель
    свободно регистрируется и платит; спокойная строка «уже покупали раньше?»
    остаётся только для возвратных студентов, чтобы куратор связал их старые
    доступы с аккаунтом.

    Параметры:
    - $variant: 'dark' (для тёмной темы магазина) | 'light' (для checkout)
                по умолчанию 'dark'
--}}
@php
    $variant = $variant ?? 'dark';

    $themes = [
        'dark' => [
            'wrap'        => 'border-[#38BDF8]/30 bg-gradient-to-br from-[#38BDF8]/10 to-[#38BDF8]/5',
            'glow'        => 'bg-[#38BDF8]/20',
            'icon_wrap'   => 'bg-[#38BDF8]/20 border-[#38BDF8]/30',
            'icon'        => 'text-[#38BDF8]',
            'text'        => 'text-white',
            'muted'       => 'text-slate-400',
            'link'        => 'text-[#38BDF8] underline decoration-[#38BDF8]/40 hover:text-white hover:decoration-white',
            'divider'     => 'border-[#1F2636]',
            'btn_wrap'    => 'bg-[#0A0D14] border-[#1F2636] hover:border-[#38BDF8]/50 hover:bg-[#38BDF8]/5',
            'btn_text'    => 'text-slate-300 group-hover:text-white',
            'btn_icon'    => 'text-[#38BDF8]',
        ],
        'light' => [
            'wrap'        => 'border-indigo-200 bg-gradient-to-br from-indigo-50 to-white',
            'glow'        => 'bg-indigo-200/30',
            'icon_wrap'   => 'bg-indigo-100 border-indigo-200',
            'icon'        => 'text-indigo-600',
            'text'        => 'text-gray-900',
            'muted'       => 'text-gray-500',
            'link'        => 'text-indigo-600 underline decoration-indigo-300 hover:text-indigo-800 hover:decoration-indigo-800',
            'divider'     => 'border-gray-200',
            'btn_wrap'    => 'bg-white border-gray-200 hover:border-indigo-400 hover:bg-indigo-50',
            'btn_text'    => 'text-gray-700 group-hover:text-indigo-700',
            'btn_icon'    => 'text-indigo-500',
        ],
    ];

    $t = $themes[$variant] ?? $themes['dark'];
@endphp

@guest
    <div class="rounded-2xl border {{ $t['wrap'] }} p-5 relative overflow-hidden">
        {{-- Декоративное свечение --}}
        <div class="absolute -top-10 -right-10 w-32 h-32 {{ $t['glow'] }} rounded-full blur-3xl pointer-events-none"></div>

        <div class="relative z-10">
            {{-- Призыв войти (не блокирует покупку) --}}
            <div class="flex items-start gap-3 mb-4">
                <div class="shrink-0 w-9 h-9 rounded-lg {{ $t['icon_wrap'] }} border flex items-center justify-center">
                    <i class="fas fa-user-shield text-sm {{ $t['icon'] }}"></i>
                </div>
                <p class="text-sm {{ $t['text'] }} font-bold leading-snug">
                    <button type="button"
        onclick="window.dispatchEvent(new CustomEvent('open-shop-login'))"
        class="{{ $t['link'] }} underline-offset-2 transition-colors font-extrabold cursor-pointer">
    Войдите
</button>,
                    чтобы увидеть купленные курсы и персональные скидки.
                </p>
            </div>

            {{-- Спокойная строка для возвратных студентов (вместо красного «ВАЖНО») --}}
            <div class="pt-4 border-t {{ $t['divider'] }}">
                <p class="text-sm {{ $t['muted'] }} leading-relaxed mb-2.5">
                    Уже покупали наши курсы раньше? Напишите куратору — он свяжет прежние
                    доступы с вашим аккаунтом, ничего не потеряется.
                </p>
                <div class="flex flex-wrap gap-2">
                    <a href="https://t.me/rusamskrtam"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border {{ $t['btn_wrap'] }} transition-all group">
                        <i class="fab fa-telegram text-base {{ $t['btn_icon'] }}"></i>
                        <span class="text-xs font-bold {{ $t['btn_text'] }} transition-colors">
                            Telegram
                        </span>
                    </a>
                    <a href="https://vk.me/event89658969"
                       target="_blank"
                       rel="noopener noreferrer"
                       class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border {{ $t['btn_wrap'] }} transition-all group">
                        <i class="fab fa-vk text-base {{ $t['btn_icon'] }}"></i>
                        <span class="text-xs font-bold {{ $t['btn_text'] }} transition-colors">
                            ВКонтакте
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </div>
@endguest
