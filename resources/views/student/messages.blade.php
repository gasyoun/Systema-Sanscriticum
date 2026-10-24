@extends('layouts.student')

@section('title', 'Мои сообщения')
@section('header', 'Уведомления и рассылки')

@section('content')
{{-- Инициализируем компонент и передаем в него ID всех опубликованных сообщений --}}
<div x-data="messagesApp([{{ $messages->pluck('id')->join(',') }}])" class="w-full max-w-4xl mx-auto flex flex-col gap-8 font-nunito pb-20">

    {{-- ЗАГОЛОВОК СТРАНИЦЫ (Стильная плашка) --}}
    <div class="bg-gradient-to-r from-white to-gray-50 rounded-[2rem] p-8 md:p-10 shadow-sm border border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-6 relative overflow-hidden">
        {{-- Декорация --}}
        <div class="absolute -right-20 -top-20 w-64 h-64 bg-brand/10 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="relative z-10">
            <h1 class="text-3xl font-black text-[#1A1A1A] mb-2 tracking-tight">Входящие сообщения</h1>
            <p class="text-gray-500 font-medium text-sm md:text-base">Важные новости, анонсы и материалы от кураторов.</p>
        </div>

        {{-- Динамический счетчик --}}
        <div class="relative z-10 flex items-center gap-3 bg-white px-5 py-3 rounded-2xl border border-gray-100 shadow-sm transition-all duration-300"
             :class="unreadCount > 0 ? 'ring-2 ring-brand/20' : ''">
            
            {{-- Пульсирующая точка (исчезает, когда все прочитано) --}}
            <div x-show="unreadCount > 0" x-transition class="w-2.5 h-2.5 rounded-full bg-brand animate-pulse"></div>
            <div x-show="unreadCount === 0" class="w-2.5 h-2.5 rounded-full bg-green-500"></div>
            
            <span class="text-sm font-extrabold text-gray-700">
                <template x-if="unreadCount > 0">
                    <span>У вас <span class="text-brand" x-text="unreadCount"></span> новых</span>
                </template>
                <template x-if="unreadCount === 0">
                    <span class="text-gray-500">Все прочитано</span>
                </template>
            </span>
        </div>
    </div>

    {{-- 152-ФЗ: согласия в кабинете — рассылки и запрос на удаление данных --}}
    @php($privacyUser = auth()->user())
    <div id="privacy-settings" class="bg-white rounded-[2rem] shadow-sm border border-gray-100 p-6 md:p-8 flex flex-col gap-6">
        <div>
            <h2 class="text-xl font-black text-[#1A1A1A] mb-1">Рассылки и персональные данные</h2>
            <p class="text-sm text-gray-500">Анонсы курсов, новости и расписание. Письма об оплате, доступе к урокам и восстановлении пароля приходят всегда — они нужны для учёбы.</p>
        </div>

        @if(session('privacy_status'))
            <div class="rounded-xl bg-green-50 border border-green-100 text-green-800 text-sm font-semibold px-4 py-3">{{ session('privacy_status') }}</div>
        @endif

        <form method="POST" action="{{ route('student.notifications.update') }}" class="flex flex-col gap-3">
            @csrf
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="email_announcements" value="1" @checked($privacyUser->wants_email_announcements)
                       class="mt-0.5 h-5 w-5 rounded border-gray-300 text-brand focus:ring-brand">
                <span class="text-sm text-gray-700">Получать анонсы и новости на почту <span class="text-gray-400">({{ $privacyUser->email }})</span></span>
            </label>
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="messenger_announcements" value="1" @checked($privacyUser->wants_messenger_announcements)
                       class="mt-0.5 h-5 w-5 rounded border-gray-300 text-brand focus:ring-brand">
                <span class="text-sm text-gray-700">Получать анонсы в Telegram и VK</span>
            </label>
            <p class="text-xs text-gray-400">Отмечая пункты, вы даёте <a href="{{ route('docs.show', 'soglasie-promo') }}" target="_blank" class="text-brand hover:underline">согласие на рекламную рассылку</a>; сняв отметку — отзываете его.</p>
            <div>
                <button type="submit" class="px-5 py-2.5 rounded-xl bg-brand hover:bg-brand-hover text-white text-sm font-bold transition-colors">Сохранить</button>
            </div>
        </form>

        <details class="border-t border-gray-100 pt-5">
            <summary class="cursor-pointer text-sm font-bold text-gray-600 hover:text-gray-900">Запросить удаление персональных данных</summary>
            <form method="POST" action="{{ route('student.pd-deletion.request') }}" class="mt-4 flex flex-col gap-3">
                @csrf
                <p class="text-sm text-gray-500">Куратор свяжется с вами и удалит или обезличит ваши данные в течение 30 дней (ст. 21 152-ФЗ).
                    После удаления доступ к кабинету и записям занятий пропадёт. Документы об оплате мы обязаны хранить по закону о налоговом учёте.</p>
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="confirm" value="1" required class="mt-0.5 h-5 w-5 rounded border-gray-300 text-red-600 focus:ring-red-500">
                    <span class="text-sm text-gray-700">Понимаю, что потеряю доступ к кабинету и записям</span>
                </label>
                @error('confirm')<p class="text-xs text-red-500 font-medium">{{ $message }}</p>@enderror
                <div>
                    <button type="submit" class="px-5 py-2.5 rounded-xl border border-red-200 text-red-700 hover:bg-red-50 text-sm font-bold transition-colors">Отправить запрос на удаление</button>
                </div>
            </form>
        </details>
    </div>

    {{-- СПИСОК СООБЩЕНИЙ --}}
    <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden flex flex-col">
        
        @foreach($messages as $msg)
            {{-- Каждое сообщение. Меняем фон, если оно непрочитано --}}
            <div x-data="{ expanded: false, id: {{ $msg->id }} }" 
                 class="border-b border-gray-100 last:border-0 transition-colors duration-300"
                 :class="isRead(id) ? 'bg-white hover:bg-gray-50/50' : 'bg-[#FFF9F5] hover:bg-[#FFF4EC]'">
                
                {{-- Кликабельная шапка сообщения --}}
                <button @click="markRead(id); expanded = !expanded" class="w-full flex items-start sm:items-center gap-4 sm:gap-6 p-6 sm:px-8 sm:py-7 text-left focus:outline-none group">
                    
                    {{-- Индикатор непрочитанного (Красная точка) --}}
                    <div class="shrink-0 pt-1.5 sm:pt-0 w-3 flex justify-center">
                        <div x-show="!isRead(id)" x-transition class="w-2.5 h-2.5 bg-brand rounded-full shadow-[0_0_8px_rgba(232,92,36,0.6)]"></div>
                    </div>

                    {{-- Иконка письма (Меняется при прочтении) --}}
                    <div class="shrink-0 w-12 h-12 rounded-full flex items-center justify-center transition-all duration-300 shadow-sm border"
                         :class="!isRead(id) ? 'bg-white border-orange-200 text-brand' : 'bg-gray-50 border-gray-100 text-gray-400'">
                        <i class="text-lg transition-all" :class="expanded ? 'far fa-envelope-open' : 'fas fa-envelope'"></i>
                    </div>

                    {{-- Текст (Заголовок и превью) --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 sm:gap-4 mb-1.5">
                            <h3 class="font-extrabold text-lg truncate transition-colors duration-300"
                                :class="!isRead(id) ? 'text-[#1A1A1A] group-hover:text-brand' : 'text-gray-600'">
                                {{ $msg->title }}
                            </h3>
                            <span class="text-[11px] font-bold text-gray-400 shrink-0 uppercase tracking-widest bg-white/50 px-2 py-1 rounded-md border border-gray-100/50">
                                {{ $msg->created_at->locale('ru')->translatedFormat('d M Y, H:i') }}
                            </span>
                        </div>
                        <p class="text-sm truncate font-medium pr-8 transition-colors duration-300"
                           :class="!isRead(id) ? 'text-gray-600' : 'text-gray-400'">
                            {{ $msg->preview }}
                        </p>
                    </div>

                    {{-- Стрелочка --}}
                    <div class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-white border border-gray-100 text-gray-400 transition-all duration-300 group-hover:border-gray-200" 
                         :class="expanded ? 'rotate-180 bg-gray-50' : ''">
                        <i class="fas fa-chevron-down text-xs"></i>
                    </div>
                </button>

                {{-- Развернутое тело сообщения (ВОТ ЗДЕСЬ ВЕРНУЛИ АЛЬПАЙН!) --}}
                <div x-show="expanded" 
                     x-collapse 
                     class="px-6 sm:px-8 pb-8 pt-2"
                     x-cloak>
                    <div class="pl-0 sm:pl-[4.5rem]">
                        <div class="bg-white border border-gray-100 rounded-3xl p-6 sm:p-8 shadow-sm">
                            
                            {{-- Картинка обложки (если есть) --}}
                            @if($msg->image_path)
                                <div class="w-full mb-8 rounded-2xl overflow-hidden bg-gray-50 border border-gray-100">
                                    <img src="{{ asset('storage/' . $msg->image_path) }}" alt="{{ $msg->title }}" class="w-full max-h-[400px] object-cover hover:scale-105 transition-transform duration-700">
                                </div>
                            @endif

                            {{-- Заголовок внутри письма --}}
                            <h2 class="text-xl sm:text-2xl font-black text-[#1A1A1A] mb-6 pb-4 border-b border-gray-100">
                                {{ $msg->title }}
                            </h2>

                            {{-- Текст с красивыми стилями --}}
                            <div class="prose prose-base md:prose-lg max-w-none text-gray-700 leading-relaxed font-medium 
                                        prose-a:text-brand prose-a:font-bold prose-a:no-underline hover:prose-a:underline 
                                        prose-p:mb-4 prose-ul:list-disc prose-ul:pl-5 marker:text-brand">
                                {!! \App\Support\SanitizedHtml::render($msg->content) !!}
                            </div>

                            {{-- Кнопка действия (если заполнена) --}}
                            @if($msg->button_text && $msg->button_url)
                                <div class="mt-8 pt-6 border-t border-gray-100">
                                    <a href="{{ $msg->button_url }}" target="_blank" class="inline-flex items-center justify-center px-8 py-3.5 bg-brand hover:bg-brand-hover text-white font-extrabold text-sm rounded-xl shadow-[0_5px_15px_rgba(232,92,36,0.3)] hover:-translate-y-0.5 active:translate-y-0 transition-all uppercase tracking-wide group">
                                        {{ $msg->button_text }}
                                        <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform"></i>
                                    </a>
                                </div>
                            @endif
                            
                        </div>
                    </div>
                </div>

            </div>
        @endforeach

        {{-- Если сообщений вообще нет --}}
        @if($messages->isEmpty())
            <div class="p-16 text-center flex flex-col items-center">
                <div class="w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center text-gray-300 mb-6 shadow-inner">
                    <i class="far fa-bell-slash text-4xl"></i>
                </div>
                <h3 class="text-2xl font-black text-[#1A1A1A] mb-3">Уведомлений пока нет</h3>
                <p class="text-gray-500 font-medium max-w-sm">Здесь будут появляться важные анонсы, ссылки на новые уроки и материалы курса.</p>
            </div>
        @endif

    </div>
</div>

{{-- СКРИПТ ДЛЯ УМНОГО СЧЕТЧИКА --}}
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('messagesApp', (allMessageIds) => ({
            // Берем прочитанные ID из памяти браузера (или пустой массив)
            readIds: JSON.parse(localStorage.getItem('student_read_messages') || '[]'),
            
            // Считаем, сколько ID из базы отсутствуют в нашем прочитанном списке
            get unreadCount() {
                return allMessageIds.filter(id => !this.readIds.includes(id)).length;
            },

            // Проверка: прочитано ли конкретное сообщение
            isRead(id) {
                return this.readIds.includes(id);
            },

            // Отметить как прочитанное
            markRead(id) {
                if (!this.isRead(id)) {
                    this.readIds.push(id);
                    // Сохраняем обратно в память браузера
                    localStorage.setItem('student_read_messages', JSON.stringify(this.readIds));
                }
            }
        }));
    });
</script>

<style>
    /* Прячем открытые элементы до загрузки Alpine */
    [x-cloak] { display: none !important; }
</style>
@endsection