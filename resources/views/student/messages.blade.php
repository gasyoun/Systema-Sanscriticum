@extends('layouts.student')

@section('title', 'Мои сообщения')
@section('header', 'Уведомления и рассылки')

@section('content')
{{-- Инициализируем компонент и передаем в него ID всех опубликованных сообщений --}}
<div x-data="messagesApp([{{ $messages->pluck('id')->join(',') }}])" class="w-full max-w-4xl mx-auto flex flex-col gap-8 font-nunito pb-20">

    {{-- ЗАГОЛОВОК СТРАНИЦЫ (Стильная плашка) --}}
    <div class="bg-gradient-to-r from-white to-gray-50 rounded-[2rem] p-8 md:p-10 shadow-sm border border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-6 relative overflow-hidden">
        {{-- Декорация --}}
        <div class="absolute -right-20 -top-20 w-64 h-64 bg-[#E85C24]/10 rounded-full blur-3xl pointer-events-none"></div>
        
        <div class="relative z-10">
            <h1 class="text-3xl font-black text-[#1A1A1A] mb-2 tracking-tight">Входящие сообщения</h1>
            <p class="text-gray-500 font-medium text-sm md:text-base">Важные новости, анонсы и материалы от кураторов.</p>
        </div>

        {{-- Динамический счетчик --}}
        <div class="relative z-10 flex items-center gap-3 bg-white px-5 py-3 rounded-2xl border border-gray-100 shadow-sm transition-all duration-300"
             :class="unreadCount > 0 ? 'ring-2 ring-[#E85C24]/20' : ''">
            
            {{-- Пульсирующая точка (исчезает, когда всё прочитано) --}}
            <div x-show="unreadCount > 0" x-transition class="w-2.5 h-2.5 rounded-full bg-[#E85C24] animate-pulse"></div>
            <div x-show="unreadCount === 0" class="w-2.5 h-2.5 rounded-full bg-green-500"></div>
            
            <span class="text-sm font-extrabold text-gray-700">
                <template x-if="unreadCount > 0">
                    <span>У вас <span class="text-[#E85C24]" x-text="unreadCount"></span> новых</span>
                </template>
                <template x-if="unreadCount === 0">
                    <span class="text-gray-500">Всё прочитано</span>
                </template>
            </span>
        </div>
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
                        <div x-show="!isRead(id)" x-transition class="w-2.5 h-2.5 bg-[#E85C24] rounded-full shadow-[0_0_8px_rgba(232,92,36,0.6)]"></div>
                    </div>

                    {{-- Иконка письма (Меняется при прочтении) --}}
                    <div class="shrink-0 w-12 h-12 rounded-full flex items-center justify-center transition-all duration-300 shadow-sm border"
                         :class="!isRead(id) ? 'bg-white border-orange-200 text-[#E85C24]' : 'bg-gray-50 border-gray-100 text-gray-400'">
                        <i class="text-lg transition-all" :class="expanded ? 'far fa-envelope-open' : 'fas fa-envelope'"></i>
                    </div>

                    {{-- Текст (Заголовок и превью) --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 sm:gap-4 mb-1.5">
                            <h3 class="font-extrabold text-lg truncate transition-colors duration-300"
                                :class="!isRead(id) ? 'text-[#1A1A1A] group-hover:text-[#E85C24]' : 'text-gray-600'">
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
                                        prose-a:text-[#E85C24] prose-a:font-bold prose-a:no-underline hover:prose-a:underline 
                                        prose-p:mb-4 prose-ul:list-disc prose-ul:pl-5 marker:text-[#E85C24]">
                                {!! $msg->content !!}
                            </div>

                            {{-- Кнопка действия (если заполнена) --}}
                            @if($msg->button_text && $msg->button_url)
                                <div class="mt-8 pt-6 border-t border-gray-100">
                                    <a href="{{ $msg->button_url }}" target="_blank" class="inline-flex items-center justify-center px-8 py-3.5 bg-[#E85C24] hover:bg-[#d04a15] text-white font-extrabold text-sm rounded-xl shadow-[0_5px_15px_rgba(232,92,36,0.3)] hover:-translate-y-0.5 active:translate-y-0 transition-all uppercase tracking-wide group">
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