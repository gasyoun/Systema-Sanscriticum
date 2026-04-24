{{-- resources/views/partials/guest-purchase-warning.blade.php --}}
{{--
    Предупреждение для неавторизованных гостей перед покупкой.
    
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
            'link'        => 'text-[#38BDF8] underline decoration-[#38BDF8]/40 hover:text-white hover:decoration-white',
            'warn_wrap'   => 'bg-amber-500/10 border-amber-500/20',
            'warn_label'  => 'text-amber-400',
            'warn_text'   => 'text-slate-300',
            'divider'     => 'border-[#1F2636]',
            'caption'     => 'text-slate-500',
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
            'link'        => 'text-indigo-600 underline decoration-indigo-300 hover:text-indigo-800 hover:decoration-indigo-800',
            'warn_wrap'   => 'bg-amber-50 border-amber-200',
            'warn_label'  => 'text-amber-700',
            'warn_text'   => 'text-gray-600',
            'divider'     => 'border-gray-200',
            'caption'     => 'text-gray-400',
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
            {{-- Призыв войти --}}
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

            {{-- Важное предупреждение --}}
            <div class="rounded-xl border {{ $t['warn_wrap'] }} px-4 py-3 mb-4">
                <p class="text-[11px] font-extrabold uppercase tracking-widest {{ $t['warn_label'] }} mb-1.5">
                    <i class="fas fa-exclamation-triangle mr-1"></i> Важно
                </p>
                <p class="text-xs {{ $t['warn_text'] }} leading-relaxed">
                    Если Вы раньше покупали наши курсы — не регистрируйтесь самостоятельно,
                    напишите куратору. Он создаст аккаунт вручную, и все ваши доступы сохранятся.
                </p>
            </div>

            {{-- Контакты кураторов --}}
            <div class="pt-4 border-t {{ $t['divider'] }}">
                <p class="text-[10px] font-bold uppercase tracking-widest {{ $t['caption'] }} mb-2.5">
                    Связаться с куратором
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