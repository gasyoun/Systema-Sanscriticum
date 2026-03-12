@php
    $data = $block['data'] ?? [];
    $blockId = 'hero-form-' . uniqid();
@endphp
 
{{-- ============================================================== --}}
{{-- HERO — ЕДИНЫЙ КОНТЕЙНЕР (управляет состоянием мобильной формы)  --}}
{{-- ============================================================== --}}
<div x-data="{ loaded: false, isMobileFormOpen: false }" 
     x-init="setTimeout(() => loaded = true, 100)"
     @open-order-form.window="
         if (window.innerWidth < 1024) { 
             isMobileFormOpen = true; 
         } else { 
             window.scrollTo({ top: 0, behavior: 'smooth' }); 
             setTimeout(() => document.getElementById('hero-name-input').focus(), 300); 
         }
     ">
 
<style>
    /* =========================================== */
    /* CSS ПЕРЕМЕННЫЕ — ЕДИНАЯ ПАЛИТРА ДЛЯ ОБОИХ  */
    /* БЛОКОВ (hero + price)                       */
    /* =========================================== */
    :root {
        --accent:       #E85C24;
        --accent-dark:  #d04a15;
        --accent-deep:  #E3122C;
        --accent-glow:  rgba(232, 92, 36, 0.18);
        --surface:      #FFFFFF;
        --surface-soft: #F9FAFB;
        --border:       #F0F0F0;
        --text-primary: #101010;
        --text-muted:   #6B7280;
        --radius-card:  2rem;
    }
 
    /* =========================================== */
    /* СДВИГ СЕКЦИЙ ПОД БОКОВУЮ ФОРМУ              */
    /* =========================================== */
    @media (min-width: 1024px) {
        .builder-sections > section > .container,
        .builder-sections > section > .max-w-7xl,
        .builder-sections > section > .max-w-6xl,
        .builder-sections > div > .container,
        .builder-sections > div > .max-w-7xl {
            padding-right: 430px !important;
            box-sizing: border-box !important;
        }
    }
    @media (min-width: 1280px) {
        .builder-sections > section > .container,
        .builder-sections > section > .max-w-7xl,
        .builder-sections > section > .max-w-6xl,
        .builder-sections > div > .container,
        .builder-sections > div > .max-w-7xl {
            padding-right: 460px !important;
        }
    }
    @media (max-width: 1023px) {
        body { padding-bottom: 90px !important; }
    }
 
    /* =========================================== */
    /* АНИМАЦИИ                                    */
    /* =========================================== */
    @keyframes float-slow  { 0%,100%{transform:translateY(0) scale(1)} 50%{transform:translateY(-22px) scale(1.04)} }
    @keyframes float-fast  { 0%,100%{transform:translateY(0) scale(1)} 50%{transform:translateY(-14px) scale(.96)} }
    @keyframes shimmer     { 100%{transform:translateX(100%)} }
    @keyframes grain-move  { 0%{transform:translate(0,0)} 25%{transform:translate(-5%,-4%)} 50%{transform:translate(3%,5%)} 75%{transform:translate(-4%,2%)} 100%{transform:translate(0,0)} }
    @keyframes pulse-ring  { 0%{transform:scale(.9);opacity:.6} 50%{transform:scale(1.15);opacity:.15} 100%{transform:scale(.9);opacity:.6} }
    @keyframes progress    { 0%{background-position:1rem 0} 100%{background-position:0 0} }
 
    .animate-float-slow { animation: float-slow 7s infinite ease-in-out; }
    .animate-float-fast { animation: float-fast 5s infinite ease-in-out; }
    .group:hover .animate-shimmer { animation: shimmer 1.5s infinite; }
 
    /* Зернистая текстура поверхности */
    .grain-overlay::after {
        content:'';
        position:absolute; inset:0;
        background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.035'/%3E%3C/svg%3E");
        animation: grain-move 8s steps(2) infinite;
        pointer-events:none;
        z-index:0;
        border-radius:inherit;
    }
 
    /* Точечная сетка */
    .dot-grid {
        background-image: radial-gradient(circle, rgba(232,92,36,0.10) 1px, transparent 1px);
        background-size: 28px 28px;
    }
 
    /* Градиентная полоса поверх карточки */
    .accent-border-top::before {
        content:'';
        position:absolute;
        top:0; left:0; right:0;
        height:3px;
        background: linear-gradient(90deg, transparent, var(--accent), transparent);
        border-radius: var(--radius-card) var(--radius-card) 0 0;
    }
 
    /* Скроллбар формы */
    .custom-scrollbar::-webkit-scrollbar { width: 4px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
 
    /* Кнопка — световой блик */
    .btn-hero { position:relative; overflow:hidden; }
    .btn-hero::after {
        content:'';
        position:absolute; inset:0;
        background: linear-gradient(135deg, rgba(255,255,255,.18) 0%, transparent 60%);
        pointer-events:none;
    }
 
    /* Бейдж "Хит продаж" — пульсирующее свечение */
    .popular-badge {
        box-shadow: 0 4px 20px rgba(232,92,36,.45), 0 0 0 0 rgba(232,92,36,.3);
        animation: pulse-ring 2.5s ease-in-out infinite;
    }
 
    /* Карточки тарифов */
    .tariff-card { transition: transform .3s ease, box-shadow .3s ease; }
    .tariff-card:hover { transform: translateY(-6px); }
    .tariff-card-popular { transform: translateY(-14px) !important; }
    .tariff-card-popular:hover { transform: translateY(-18px) !important; }
 
    /* Зелёная пульсирующая точка */
    .status-dot {
        display: inline-block;
        width:.5rem; height:.5rem; border-radius:50%; background:#22C55E;
        box-shadow: 0 0 0 4px rgba(34,197,94,.15);
        animation: pulse-ring 2s ease-in-out infinite;
    }
</style>
 
 
{{-- ========================================== --}}
{{-- 1. ГЛАВНЫЙ ЭКРАН                           --}}
{{-- ========================================== --}}
<section class="relative bg-[var(--surface-soft)] overflow-hidden pt-6 pb-14 lg:pt-14 lg:pb-20 grain-overlay">
 
    {{-- Фоновая точечная сетка --}}
    <div class="absolute inset-0 dot-grid opacity-60 pointer-events-none z-0"></div>
 
    {{-- Большой размытый blob --}}
    <div class="absolute top-1/2 left-1/4 -translate-y-1/2 w-[900px] h-[900px] rounded-full pointer-events-none -z-10 transition-all duration-[3s]"
         style="background: radial-gradient(circle, rgba(232,92,36,0.08) 0%, rgba(255,150,50,0.04) 50%, transparent 70%);"
         :class="loaded ? 'scale-110 opacity-100' : 'scale-90 opacity-0'"></div>
 
    {{-- Декоративные float-шары --}}
    <div class="absolute top-16 left-8 w-28 h-28 rounded-full blur-3xl -z-10 animate-float-slow"
         style="background: rgba(232,92,36,0.12);"></div>
    <div class="absolute bottom-16 right-16 w-44 h-44 rounded-full blur-3xl -z-10 animate-float-fast"
         style="background: rgba(227,18,44,0.07);"></div>
 
    <div class="container mx-auto px-4 relative z-10">
        <div class="flex flex-col items-start text-left w-full">
 
            {{-- Надзаголовок-пилюля --}}
            @if(!empty($data['subtitle']))
                <div class="transform transition-all duration-700 delay-100 translate-y-8 opacity-0"
                     :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                    <div class="inline-flex items-center gap-2.5 px-5 py-2 rounded-full mb-7 border"
                         style="background:rgba(232,92,36,0.06); border-color:rgba(232,92,36,0.18);">
                        <span class="status-dot shrink-0"></span>
                        <span class="font-black text-[11px] uppercase tracking-[0.22em]"
                              style="color:var(--accent);">{{ $data['subtitle'] }}</span>
                    </div>
                </div>
            @endif
 
            {{-- Заголовок --}}
            <div class="transform transition-all duration-700 delay-200 translate-y-8 opacity-0 w-full"
                 :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold mb-8 leading-[1.08] tracking-tight max-w-5xl"
                    style="color:var(--text-primary);">
                    {{ $data['title'] ?? 'Название курса' }}
                </h1>
            </div>
 
            {{-- Описание --}}
            @if(!empty($data['description']))
                <div class="transform transition-all duration-700 delay-300 translate-y-8 opacity-0"
                     :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                    <div class="text-lg md:text-xl leading-relaxed mb-10 max-w-3xl font-medium"
                         style="color:var(--text-muted);">
                        {!! nl2br(e($data['description'])) !!}
                    </div>
                </div>
            @endif
 
            {{-- CTA-кнопка --}}
            <div class="transform transition-all duration-700 delay-500 translate-y-8 opacity-0 w-full sm:w-auto"
                 :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                <button @click.prevent="window.innerWidth < 1024 ? isMobileFormOpen = true : document.querySelector('input[name=name]').focus()"
                        class="btn-hero group relative w-full sm:w-auto inline-flex items-center justify-center text-white font-black text-sm uppercase tracking-[0.18em] py-5 px-12 rounded-2xl transition-all duration-300 hover:-translate-y-1"
                        style="background: linear-gradient(135deg, var(--accent) 0%, #f0733b 100%); box-shadow: 0 12px 35px rgba(232,92,36,.35), 0 4px 12px rgba(232,92,36,.2);">
                    <div class="absolute inset-0 w-full h-full bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-shimmer rounded-2xl"></div>
                    <span class="relative z-10">{{ $data['button_text'] ?? 'Записаться сейчас' }}</span>
                    <svg class="ml-3 w-4 h-4 relative z-10 transition-transform group-hover:translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                </button>
            </div>
 
            {{-- Статус-полоска --}}
            <div class="transform transition-all duration-700 delay-700 translate-y-8 opacity-0"
                 :class="loaded ? '!translate-y-0 !opacity-100' : ''">
                <div class="mt-10 flex flex-wrap items-center gap-6 md:gap-10 text-xs font-black uppercase tracking-widest"
                     style="color:var(--text-muted);">
                    @foreach(['Старт потока скоро', 'Места ограничены', 'Онлайн формат'] as $badge)
                        <div class="flex items-center gap-2.5 group cursor-default transition-colors hover:text-gray-700">
                            <span class="status-dot shrink-0"></span>
                            <span>{{ $badge }}</span>
                        </div>
                        @if(!$loop->last)
                            <div class="hidden md:block w-1 h-1 rounded-full" style="background:var(--border);"></div>
                        @endif
                    @endforeach
                </div>
            </div>
 
        </div>
    </div>
</section>
 
 
{{-- ============================================================== --}}
{{-- 2. ГЛОБАЛЬНАЯ БОКОВАЯ ФОРМА (логика без изменений)             --}}
{{-- ============================================================== --}}
 
{{-- Затемнение — только мобилка --}}
<div x-show="isMobileFormOpen" x-transition.opacity @click="isMobileFormOpen = false"
     class="lg:hidden fixed inset-0 bg-black/80 z-40" style="display:none;"></div>
 
<div class="fixed z-[100] transition-transform duration-500 ease-[cubic-bezier(0.32,0.72,0,1)]
            lg:top-1/2 lg:-translate-y-1/2 lg:right-[30px] xl:right-[60px] lg:w-[400px] lg:h-auto lg:bottom-auto lg:left-auto
            left-0 right-0 bottom-0 h-[85vh] lg:h-auto"
     :class="window.innerWidth >= 1024 ? 'translate-y-0 lg:-translate-y-1/2' : (isMobileFormOpen ? 'translate-y-0' : 'translate-y-full')">
 
    <div class="bg-white border border-gray-200 rounded-t-[2rem] lg:rounded-[2rem] p-6 lg:p-8 shadow-2xl h-full lg:h-auto flex flex-col"
         x-data="{ agreedForm: false, agreedPromo: false }">
 
        <div class="lg:hidden flex justify-center pb-4 shrink-0 cursor-pointer" @click="isMobileFormOpen = false">
            <div class="w-12 h-1.5 bg-gray-200 rounded-full"></div>
        </div>
 
        <div class="flex-1 overflow-y-auto custom-scrollbar pr-1">
            <h3 class="text-xl lg:text-2xl font-extrabold text-gray-900 mb-1 text-center">Записаться на курс</h3>
            <p class="text-gray-500 font-medium text-xs lg:text-sm mb-6 text-center">Оставьте заявку, и мы свяжемся с вами в Telegram.</p>
 
            @if(session('success'))
                <div class="p-3 mb-5 rounded-xl bg-green-50 border border-green-200 text-green-700 text-center font-bold text-sm">{{ session('success') }}</div>
            @endif
 
            <form action="{{ route('leads.store') }}" method="POST" class="space-y-4">
                @csrf
                @php
                    $landingId = '';
                    if (isset($page) && $page->id) { $landingId = $page->id; }
                    elseif (request()->route('slug')) { $landingId = \App\Models\LandingPage::where('slug', request()->route('slug'))->value('id'); }
                @endphp
                <input type="hidden" name="landing_page_id" value="{{ $landingId }}">
                <input type="hidden" name="utm_source"   class="analytics-field">
                <input type="hidden" name="utm_medium"   class="analytics-field">
                <input type="hidden" name="utm_campaign" class="analytics-field">
                <input type="hidden" name="utm_content"  class="analytics-field">
                <input type="hidden" name="utm_term"     class="analytics-field">
                <input type="hidden" name="click_id"     class="analytics-field">
                <input type="hidden" name="referrer"     class="analytics-field" value="{{ request()->headers->get('referer') }}">
 
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1">Ваше имя</label>
                    <input type="text" id="hero-name-input" name="name" required placeholder="Имя и фамилия"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 placeholder-gray-400 focus:bg-white focus:border-[#E3122C] focus:ring-2 focus:ring-[#E3122C]/20 outline-none transition text-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1">Телефон / Telegram</label>
                    <input type="text" name="contact" required placeholder="+7 999 000-00-00"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 placeholder-gray-400 focus:bg-white focus:border-[#E3122C] focus:ring-2 focus:ring-[#E3122C]/20 outline-none transition text-sm">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5 pl-1">Email</label>
                    <input type="email" name="email" required placeholder="mail@example.com"
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 placeholder-gray-400 focus:bg-white focus:border-[#E3122C] focus:ring-2 focus:ring-[#E3122C]/20 outline-none transition text-sm">
                </div>
 
                <div class="space-y-2.5 pt-1">
                    <label class="flex items-start gap-3 text-left p-3 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                        <div class="flex items-center h-5 mt-px shrink-0">
                            <input type="checkbox" x-model="agreedForm" class="w-4 h-4 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                        </div>
                        <div class="text-xs text-gray-500 leading-relaxed select-none group-hover:text-gray-800 transition">
                            Я даю <span @click.prevent.stop="viewDocument('Согласие на обработку персональных данных', '/docs/soglasie-pd.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие</span> на обработку моих персональных данных в соответствии с <span @click.prevent.stop="viewDocument('Политика конфиденциальности', '/docs/privacy.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">политикой конфиденциальности</span>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 text-left p-3 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100 group">
                        <div class="flex items-center h-5 mt-px shrink-0">
                            <input type="checkbox" name="is_promo_agreed" x-model="agreedPromo" class="w-4 h-4 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer transition-colors">
                        </div>
                        <div class="text-xs text-gray-500 leading-relaxed select-none group-hover:text-gray-800 transition">
                            Я даю <span @click.prevent.stop="viewDocument('Рассылка', '/docs/soglasie-promo.pdf')" class="text-[#E85C24] hover:text-[#d04a15] hover:underline font-semibold cursor-pointer">согласие</span> на получение рассылки
                        </div>
                    </label>
                </div>
 
                <button type="submit"
                        :disabled="!agreedForm"
                        :class="agreedForm ? 'bg-[#E85C24] hover:bg-[#d04a15] transform hover:-translate-y-0.5 shadow-lg shadow-orange-900/20 text-white cursor-pointer' : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
                        class="w-full font-extrabold py-3.5 rounded-xl transition-all duration-300 text-sm uppercase tracking-wider mt-2">
                    {{ $data['button_text'] ?? 'ЗАПИСАТЬСЯ' }}
                </button>
            </form>
        </div>
    </div>
</div>
 
 
{{-- ============================================================== --}}
{{-- 3. МОБИЛЬНАЯ ЛИПКАЯ КНОПКА                                     --}}
{{-- ============================================================== --}}
<div class="lg:hidden fixed bottom-0 left-0 w-full bg-white/95 backdrop-blur-md border-t border-gray-200 p-4 z-[90] shadow-[0_-10px_30px_rgba(0,0,0,0.08)]">
    <button @click.prevent="isMobileFormOpen = true"
            class="btn-hero w-full text-white font-black text-sm uppercase tracking-[0.18em] py-4 rounded-xl transition-transform active:scale-95"
            style="background:linear-gradient(135deg,var(--accent) 0%,#f0733b 100%); box-shadow:0 8px 25px rgba(232,92,36,.35);">
        {{ $data['button_text'] ?? 'Записаться сейчас' }}
    </button>
</div>
 
</div>{{-- /x-data главного контейнера --}}