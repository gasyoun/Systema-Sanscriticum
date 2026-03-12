@php
    $data = $block['data'];
    $textColor = $data['text_color'] ?? '#07191E';
    $accentColor = $data['accent_color'] ?? '#E85C24';
@endphp

<style>
    @import url('https://fonts.googleapis.com/css2?family=Charis+SIL:ital,wght@0,400;0,700;1,400;1,700&family=Nunito+Sans:ital,opsz,wght@0,6..12,200..1000;1,6..12,200..1000&display=swap');
    
    .font-charis { font-family: 'Charis SIL', serif; }
    .font-nunito { font-family: 'Nunito Sans', sans-serif; }
</style>

{{-- Главный контейнер на весь экран --}}
<div class="relative w-[100vw] min-h-[100vh] lg:h-[925px] left-1/2 right-1/2 -ml-[50vw] -mr-[50vw] bg-[#F4F1EA] overflow-hidden" x-data="{ isMobileFormOpen: false }">
    
    {{-- ========================================================= --}}
    {{-- 1. СЛОИ ФОНА (Абсолютно на весь экран)                    --}}
    {{-- ========================================================= --}}
    @if($data['bg_image'] ?? false)
        <img src="{{ asset('storage/' . $data['bg_image']) }}" alt="Background" class="absolute inset-0 w-full h-full object-cover z-0">
    @endif

    @if($data['clouds_image'] ?? false)
        <img src="{{ asset('storage/' . $data['clouds_image']) }}" alt="Clouds" class="absolute inset-0 w-full h-full object-cover mix-blend-multiply opacity-80 z-0">
    @endif

    {{-- Спикер: Сдвинут ЛЕВЕЕ (привязка к left-[40%] и left-[450px]) --}}
    @if($data['speaker_image'] ?? false)
        <img src="{{ asset('storage/' . $data['speaker_image']) }}" alt="Спикер" 
             class="absolute bottom-0 right-[-10%] lg:right-auto lg:left-[40%] xl:left-[450px] h-[80%] lg:h-[90%] max-h-[850px] w-auto object-contain object-bottom pointer-events-none z-10">
    @endif

    {{-- ========================================================= --}}
    {{-- 2. ЛЕВАЯ ЧАСТЬ (Текст и заголовки)                        --}}
    {{-- ========================================================= --}}
    <div class="relative z-20 w-full h-full flex flex-col justify-center px-5 sm:px-10 lg:pl-[90px] xl:pl-[120px] pt-[80px] pb-[120px] lg:py-0">
        
        <div class="w-full max-w-[600px] xl:max-w-[800px]">
            
            {{-- Логотип и Организатор --}}
            <div class="flex items-center gap-[15px] mb-8 lg:mb-12 mt-4">
                @if($data['logo_image'] ?? false)
                    <img src="{{ asset('storage/' . $data['logo_image']) }}" alt="Logo" class="w-[50px] lg:w-[66px] h-[47px] object-contain">
                @endif
                @if($data['super_title'] ?? false)
                    <div class="font-charis font-bold text-black text-xl lg:text-[28px] leading-tight mt-1">
                        {{ $data['super_title'] }}
                    </div>
                @endif
            </div>

            {{-- Главный заголовок --}}
            @if($data['title'] ?? false)
                <h1 class="font-charis font-bold text-4xl sm:text-5xl lg:text-[70px] xl:text-[85px] text-black leading-[1.05] mb-8 lg:mb-10">
                    {!! nl2br(e($data['title'])) !!}
                </h1>
            @endif

            {{-- Онлайн-курс в 2-х частях --}}
            <div class="mb-8 lg:mb-12">
                @if($data['badge_top'] ?? false)
                    <div class="font-nunito font-semibold text-black text-xl lg:text-[33px] tracking-wide mb-1">
                        {{ $data['badge_top'] }}
                    </div>
                @endif
                <div class="w-[150px] lg:w-[227px] h-[3px] bg-black my-2"></div>
                @if($data['badge_bottom'] ?? false)
                    <div class="font-nunito font-semibold text-black text-xl lg:text-[33px] tracking-wide">
                        {{ $data['badge_bottom'] }}
                    </div>
                @endif
            </div>

            {{-- Даты (ВЕРНУЛИ ОРАНЖЕВУЮ ПЛАШКУ) --}}
            @if($data['orange_badge'] ?? false)
                <div class="inline-block font-nunito font-bold text-white text-lg lg:text-[22px] px-6 py-3 mb-8 lg:mb-10 shadow-lg" 
                     style="background-color: {{ $accentColor }};">
                    {!! nl2br(e($data['orange_badge'])) !!}
                </div>
            @endif

            {{-- Описание --}}
            @if($data['description'] ?? false)
                <p class="font-nunito font-semibold text-lg lg:text-[30px] leading-snug lg:leading-[35px]" style="color: {{ $textColor }};">
                    {!! nl2br(e($data['description'])) !!}
                </p>
            @endif

        </div>
    </div>

    {{-- ========================================================= --}}
    {{-- 3. МОБИЛЬНАЯ КНОПКА (Видна только на телефонах)           --}}
    {{-- ========================================================= --}}
    <div class="lg:hidden fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 p-4 z-40 shadow-xl">
        <button @click="isMobileFormOpen = true" class="font-nunito w-full bg-[#1A1A1A] text-white font-extrabold text-[20px] tracking-[1px] py-4 rounded-2xl">
            ХОЧУ НА КУРС
        </button>
    </div>

    {{-- ========================================================= --}}
    {{-- 4. ФОРМА (Парит на ПК справа / Выезжает на мобилке)       --}}
    {{-- ========================================================= --}}
    <div x-show="isMobileFormOpen" x-transition.opacity @click="isMobileFormOpen = false" class="lg:hidden fixed inset-0 bg-black/60 z-50" style="display: none;"></div>

    <div class="
            fixed z-[100] font-nunito transition-transform duration-500 ease-[cubic-bezier(0.32,0.72,0,1)]
            /* --- ПК: Парит жестко справа, центрирована по вертикали --- */
            lg:top-1/2 lg:-translate-y-1/2 lg:right-[40px] xl:right-[90px] lg:w-[418px] lg:h-[653px] lg:translate-y-0 lg:bottom-auto lg:left-auto
            /* --- МОБИЛКА: Выезжает снизу --- */
            left-0 right-0 bottom-0 h-[85vh] 
         "
         :class="isMobileFormOpen ? 'translate-y-0' : 'translate-y-full lg:translate-y-0'">
        
        <div class="w-full h-full bg-[#FBF3E7] rounded-t-[2rem] lg:rounded-[32px] shadow-[0_20px_50px_rgba(0,0,0,0.15)] flex flex-col relative overflow-hidden border border-[#e8dcc8]">
            
            {{-- Индикатор свайпа для мобилок --}}
            <div class="lg:hidden flex justify-center pt-4 pb-2 shrink-0" @click="isMobileFormOpen = false">
                <div class="w-12 h-1.5 bg-[#4a4a4a] rounded-full"></div>
            </div>
            
            {{-- Декоративная линия (Figma) --}}
            <div class="hidden lg:block absolute top-6 left-1/2 -translate-x-1/2 w-[50px] h-1 bg-[#4a4a4a] rounded-[20px]"></div>

            <div class="flex-1 overflow-y-auto p-6 lg:p-8 flex flex-col justify-center" x-data="{ agreedForm: false, agreedPromo: false }">
                
                <div class="mb-6 lg:mb-8 mt-4">
                    <h3 class="text-[20px] lg:text-[23px] font-extrabold text-black uppercase tracking-[1px] leading-tight text-center mb-2">
                        {{ $data['form_title'] ?? 'ЗАПИСАТЬСЯ НА ОНЛАЙН-КУРС' }}
                    </h3>
                    <p class="text-black font-semibold text-[14px] text-center leading-snug">
                        Оставьте заявку и наши кураторы курсов с вами свяжутся
                    </p>
                </div>

                @if(session('success'))
                    <div class="p-3 mb-4 rounded-xl bg-green-100 border border-green-300 text-green-800 text-center font-bold text-sm">
                        {{ session('success') }}
                    </div>
                @endif

                <form action="{{ route('leads.store') }}" method="POST" class="space-y-[15px]">
                    @csrf
                    @php
                        $landingId = isset($page) && $page->id ? $page->id : (\App\Models\LandingPage::where('slug', request()->route('slug'))->value('id') ?? '');
                    @endphp
                    <input type="hidden" name="landing_page_id" value="{{ $landingId }}">
                    <input type="hidden" name="utm_source" class="analytics-field">
                    <input type="hidden" name="utm_medium" class="analytics-field">
                    <input type="hidden" name="utm_campaign" class="analytics-field">
                    <input type="hidden" name="referrer" class="analytics-field" value="{{ request()->headers->get('referer') }}">

                    {{-- Имя --}}
                    <div>
                        <input type="text" name="name" required placeholder="Имя и фамилия"
                               class="w-full h-[58px] bg-white rounded-[16px] border border-[#424242] px-4 font-semibold text-[18px] text-[#7e7e7e] placeholder-[#7e7e7e] focus:ring-2 focus:ring-[#E85C24] outline-none">
                    </div>
                    
                    {{-- Email --}}
                    <div>
                        <input type="email" name="email" required placeholder="Email"
                               class="w-full h-[58px] bg-white rounded-[16px] border border-[#424242] px-4 font-semibold text-[18px] text-[#7e7e7e] placeholder-[#7e7e7e] focus:ring-2 focus:ring-[#E85C24] outline-none">
                    </div>

                    {{-- Телефон --}}
                    <div class="flex gap-3">
                        <div class="w-[83px] h-[58px] bg-white rounded-[16px] border border-[#424242] flex items-center justify-center shrink-0">
                            <img src="https://flagcdn.com/w20/ru.png" alt="RU" class="w-[21px] h-4 border border-[#d4d4dd]">
                        </div>
                        <div class="flex-1 h-[58px] bg-white rounded-[16px] border border-[#424242] flex items-center px-4 focus-within:ring-2 focus-within:ring-[#E85C24] bg-white">
                            <span class="font-semibold text-[18px] text-[#7e7e7e] mr-2">+7</span>
                            <input type="tel" name="contact" required placeholder="952 562 23 87"
                                   class="w-full bg-transparent outline-none border-none font-semibold text-[18px] text-[#7e7e7e] placeholder-[#7e7e7e] p-0 focus:ring-0">
                        </div>
                    </div>

                    {{-- Чекбоксы --}}
                    <div class="pt-2 pb-2 space-y-3">
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <div class="shrink-0 mt-0.5">
                                <input type="checkbox" x-model="agreedForm" class="w-6 h-6 bg-[#f4f5fa] rounded border border-[#424242] text-[#424242] focus:ring-[#E85C24] cursor-pointer">
                            </div>
                            <div class="font-normal text-[12px] leading-[15px] text-black">
                                <span class="font-semibold">Я соглашаюсь на </span>
                                <span class="font-extrabold text-[#0e0e0e]">обработку персональных данных</span>
                            </div>
                        </label>
                        <label class="flex items-start gap-3 cursor-pointer group">
                            <div class="shrink-0 mt-0.5">
                                <input type="checkbox" name="is_promo_agreed" x-model="agreedPromo" class="w-6 h-6 bg-[#f4f5fa] rounded border border-[#424242] text-[#424242] focus:ring-[#E85C24] cursor-pointer">
                            </div>
                            <div class="font-normal text-[12px] leading-[15px] text-black">
                                <span class="font-semibold">Я даю согласие на </span>
                                <span class="font-extrabold text-[#0e0e0e]">получение рассылки</span>
                            </div>
                        </label>
                    </div>

                    {{-- Кнопка --}}
                    <button type="submit" 
                            :disabled="!agreedForm"
                            :class="agreedForm ? 'bg-[#1A1A1A] text-white hover:bg-black hover:-translate-y-1 shadow-lg' : 'bg-[#e1e1e9] text-[#767676] cursor-not-allowed opacity-70'"
                            class="w-full h-[74px] rounded-[16px] flex items-center justify-center font-extrabold text-[25px] tracking-[1px] uppercase transition-all duration-300">
                        {{ $data['button_text'] ?? 'ХОЧУ НА КУРС' }}
                    </button>

                    {{-- Оферта --}}
                    <p class="text-center text-[11px] leading-[14px] mt-4">
                        <span class="font-semibold text-black">Отправляя заявку, вы принимаете условия </span>
                        <a href="#" @click.prevent.stop="viewDocument('Публичная оферта', '/docs/oferta.pdf')" class="font-extrabold text-[#d67200] hover:text-black transition-colors">публичной оферты</a><br>
                        <span class="font-semibold text-black mt-1 inline-block">ИП Поликарпова Мария Михайловна</span>
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>