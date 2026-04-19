{{-- 
    Универсальная модалка записи на пробное занятие.
    Подключается в layout один раз. Открывается из любой точки страницы 
    через установку переменной isTrialModalOpen = true в Alpine-стейте на <body>.
    
    Параметры (можно передать через атрибуты компонента):
    - title       — заголовок модалки
    - description — текст под заголовком
    - form_name   — имя формы для аналитики (попадёт в lead.form_name)
--}}
@props([
    'title' => 'Запись на пробный урок',
    'description' => 'Оставьте контакты, и мы согласуем удобное время.',
    'formName' => 'Пробное занятие из статьи',
])

<template x-teleport="body">
    <div x-show="isTrialModalOpen" 
         style="display: none;" 
         class="fixed inset-0 z-[9999] overflow-y-auto" 
         aria-labelledby="trial-modal-title" role="dialog" aria-modal="true"
         @keydown.escape.window="isTrialModalOpen = false">
        
        {{-- Тёмный фон --}}
        <div x-show="isTrialModalOpen" 
             x-transition.opacity.duration.300ms
             @click="isTrialModalOpen = false"
             class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"></div>

        <div class="flex min-h-screen items-center justify-center p-4 text-center sm:p-0 relative z-10">
            <div x-show="isTrialModalOpen" 
                 x-transition:enter="ease-out duration-300" 
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" 
                 x-transition:leave="ease-in duration-200" 
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" 
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                 class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-2xl transition-all sm:my-8 w-full max-w-md">
                
                {{-- Кнопка закрытия --}}
                <div class="absolute right-0 top-0 pr-4 pt-4 z-20">
                    <button @click="isTrialModalOpen = false" type="button" 
                            class="rounded-md bg-white text-gray-400 hover:text-gray-600 focus:outline-none">
                        <span class="sr-only">Закрыть</span>
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <div class="p-8 mt-2">
                    <h3 class="text-2xl font-extrabold text-gray-900 mb-2 text-center" id="trial-modal-title">
                        {{ $title }}
                    </h3>
                    <p class="text-sm text-gray-500 text-center mb-6">
                        {{ $description }}
                    </p>

                    <form action="{{ route('leads.store') }}" method="POST" class="space-y-4">
                        @csrf
                        <input type="hidden" name="form_name" value="{{ $formName }}">
                        <input type="hidden" name="referrer" value="{{ request()->fullUrl() }}">
                        {{-- Если открыли модалку со страницы статьи — передадим её ID для аналитики --}}
                        @isset($article)
                            <input type="hidden" name="source_article_id" value="{{ $article->id }}">
                            <input type="hidden" name="source_article_slug" value="{{ $article->slug }}">
                        @endisset
                        
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 pl-1">Ваше имя</label>
                            <input type="text" name="name" required placeholder="Имя и фамилия"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 pl-1">Телефон / Telegram</label>
                            <input type="text" name="contact" required placeholder="+7 999 000-00-00"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1 pl-1">Email</label>
                            <input type="email" name="email" required placeholder="mail@example.com"
                                   class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 text-gray-900 focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition text-sm">
                        </div>
                        
                        <div class="space-y-2.5 pt-2">
                            <label class="flex items-start gap-3 p-3 bg-gray-50 rounded-xl cursor-pointer hover:bg-gray-100 transition-colors border border-gray-100">
                                <div class="flex items-center h-5 mt-px shrink-0">
                                    <input type="checkbox" x-model="agreedForm" class="w-4 h-4 rounded border-gray-300 text-[#E85C24] focus:ring-[#E85C24] cursor-pointer">
                                </div>
                                <div class="text-xs text-gray-500 leading-relaxed select-none">
                                    Я даю <a href="/docs/soglasie-pd.pdf" target="_blank" class="text-[#E85C24] hover:underline font-semibold">согласие</a> на обработку данных согласно <a href="/docs/privacy.pdf" target="_blank" class="text-[#E85C24] hover:underline font-semibold">политике конфиденциальности</a>
                                </div>
                            </label>
                        </div>
                        
                        <button type="submit"
        :disabled="!agreedForm"
        @click="if (typeof window.sendGoal === 'function') window.sendGoal('article_lead_form_submit')"
        :class="agreedForm ? 'bg-[#E85C24] hover:bg-[#d04a15] shadow-lg text-white' : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
        class="w-full font-extrabold py-3.5 rounded-xl transition-all duration-300 text-sm uppercase tracking-wider mt-4">
    Отправить заявку
</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
