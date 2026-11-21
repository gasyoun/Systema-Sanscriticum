{{-- ИИ-помощник занятия (features.lesson_ai) — вкладка «✦ ИИ» боковой колонки.
     Логика — public/js/lesson-ai.js; компонент вложен в x-data .lesson-layout
     (seekTo, activeTab). Ответы приходят уже отрендеренным безопасным HTML
     (LessonAiService::render), таймкоды — кнопки .lesson-ai-ts. --}}
<style>
    .lesson-ai-result { font-size: 14px; line-height: 1.6; color: #1f2937; }
    .lesson-ai-result h1, .lesson-ai-result h2, .lesson-ai-result h3 { font-weight: 800; color: #111827; margin: 1rem 0 .4rem; font-size: 15px; }
    .lesson-ai-result h2:first-child, .lesson-ai-result h3:first-child { margin-top: 0; }
    .lesson-ai-result p { margin: .4rem 0; }
    .lesson-ai-result ul, .lesson-ai-result ol { margin: .3rem 0 .6rem; padding-left: 1.2rem; }
    .lesson-ai-result ul { list-style: disc; }
    .lesson-ai-result ol { list-style: decimal; }
    .lesson-ai-result li { margin: .2rem 0; }
    .lesson-ai-result strong { font-weight: 800; }
    .lesson-ai-ts { display: inline-flex; align-items: center; gap: 4px; padding: 0 6px; margin: 0 2px; border-radius: 6px;
        background: rgba(232, 93, 4, .08); border: 1px solid rgba(232, 93, 4, .3); color: #c2410c;
        font: 700 12px/1.6 ui-monospace, SFMono-Regular, Menlo, monospace; vertical-align: baseline; cursor: pointer; }
    .lesson-ai-ts:hover { background: #ea580c; color: #fff; }
    .lesson-ai-ts i { font-size: 8px; opacity: .7; }
    .lesson-ai-dots i { display: inline-block; width: 7px; height: 7px; margin: 0 2px; border-radius: 50%; background: #8b5cf6; animation: lessonAiDot 1.2s infinite ease-in-out both; }
    .lesson-ai-dots i:nth-child(2) { animation-delay: .15s; }
    .lesson-ai-dots i:nth-child(3) { animation-delay: .3s; }
    @keyframes lessonAiDot { 0%, 80%, 100% { transform: scale(.5); opacity: .4; } 40% { transform: scale(1); opacity: 1; } }
</style>

<div x-show="activeTab === 'ai'" x-cloak
     data-lesson-ai-panel
     data-testid="lesson-ai-panel"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     class="bg-white rounded-3xl shadow-sm border border-violet-100 flex flex-col flex-1 min-h-[500px] overflow-hidden">

    {{-- Шапка + меню действий --}}
    <div class="bg-violet-50/60 p-4 border-b border-violet-100 shrink-0 flex flex-col gap-3">
        <div class="flex items-center justify-between gap-2">
            <p class="text-[11px] text-violet-700 font-bold uppercase tracking-wider">✦ ИИ-помощник</p>
            <button type="button" x-show="state !== 'idle'" @click="cancel()"
                    class="text-[11px] font-bold text-gray-400 hover:text-gray-600">
                <i class="fas fa-arrow-left mr-1"></i> К списку
            </button>
        </div>

        <div class="grid grid-cols-2 gap-2" x-show="state === 'idle'">
            <template x-if="hasTranscript">
                <button type="button" @click="summary()" data-testid="lesson-ai-summary"
                        class="col-span-2 text-left p-3 rounded-xl bg-white border border-violet-100 hover:border-violet-300 hover:shadow-sm transition">
                    <span class="block text-[13px] font-extrabold text-gray-900"><i class="fas fa-list-ul text-violet-500 mr-1.5"></i> Пересказ занятия</span>
                    <span class="block text-[11px] text-gray-500 mt-0.5">Кратко, оглавление с таймкодами, главные мысли</span>
                </button>
            </template>
            <button type="button" @click="quiz()" data-testid="lesson-ai-quiz"
                    class="text-left p-3 rounded-xl bg-white border border-violet-100 hover:border-violet-300 hover:shadow-sm transition">
                <span class="block text-[13px] font-extrabold text-gray-900"><i class="fas fa-question text-violet-500 mr-1.5"></i> Самопроверка</span>
                <span class="block text-[11px] text-gray-500 mt-0.5">Вопросы с ответами</span>
            </button>
            <button type="button" @click="outline()" data-testid="lesson-ai-outline"
                    class="text-left p-3 rounded-xl bg-white border border-violet-100 hover:border-violet-300 hover:shadow-sm transition">
                <span class="block text-[13px] font-extrabold text-gray-900"><i class="far fa-edit text-violet-500 mr-1.5"></i> Конспект</span>
                <span class="block text-[11px] text-gray-500 mt-0.5">Из моих заметок</span>
            </button>
            <button type="button" @click="explain()" data-testid="lesson-ai-explain"
                    class="col-span-2 text-left p-3 rounded-xl bg-white border border-violet-100 hover:border-violet-300 hover:shadow-sm transition">
                <span class="block text-[13px] font-extrabold text-gray-900"><i class="fas fa-lightbulb text-violet-500 mr-1.5"></i> Объяснить выделенное</span>
                <span class="block text-[11px] text-gray-500 mt-0.5" x-text="selection ? '«' + (selection.length > 60 ? selection.slice(0, 60) + '…' : selection) + '»' : 'Выделите фразу в тексте, стенограмме или заметках'"></span>
            </button>
        </div>

        <template x-if="hasTranscript && state === 'idle'">
            <form @submit.prevent="ask()" class="relative w-full" data-testid="lesson-ai-ask-form">
                <input type="text" x-model="question" maxlength="500" placeholder="Вопрос по занятию…"
                       class="w-full pl-4 pr-11 py-2.5 bg-white border border-violet-100 rounded-xl text-sm font-semibold focus:ring-2 focus:ring-violet-400 outline-none placeholder-gray-400">
                <button type="submit" class="absolute right-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-lg bg-violet-500 hover:bg-violet-600 text-white text-xs" title="Спросить">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </template>
    </div>

    {{-- Тело: пусто / загрузка / ошибка / результат --}}
    <div x-ref="aiResult" class="flex-1 overflow-y-auto p-4 pb-6 custom-scrollbar">
        <div x-show="state === 'idle'" class="text-[12px] text-gray-500 leading-relaxed space-y-2">
            <p>ИИ работает на собственном сервере школы: текст занятия и ваши заметки никуда не передаются и не сохраняются.</p>
            <p>Это автоматическая подсказка, а не слово преподавателя: проверяйте термины и формы по записи — нажмите на таймкод, и видео перейдёт к этому месту.</p>
        </div>

        <div x-show="state === 'loading'" class="flex flex-col items-center justify-center text-center gap-3 py-12">
            <span class="lesson-ai-dots"><i></i><i></i><i></i></span>
            <p class="text-[13px] text-gray-600 font-semibold max-w-[280px]" x-text="loadingText"></p>
            <button type="button" @click="cancel()" class="text-[12px] font-bold text-gray-400 hover:text-gray-600 underline">Отмена</button>
        </div>

        <div x-show="state === 'error'" class="py-6">
            <p class="text-[13px] text-red-700 bg-red-50 border border-red-100 rounded-xl p-3" x-text="errorText" data-testid="lesson-ai-error"></p>
            <div class="mt-3 flex gap-2">
                <button type="button" @click="retry()" class="px-3 py-2 rounded-xl bg-violet-500 hover:bg-violet-600 text-white text-[12px] font-extrabold">Повторить</button>
                <button type="button" @click="cancel()" class="px-3 py-2 rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-700 text-[12px] font-extrabold">Закрыть</button>
            </div>
        </div>

        <div x-show="state === 'done'" @click="onResultClick($event)" data-testid="lesson-ai-result">
            <h3 class="text-[13px] font-extrabold text-violet-700 uppercase tracking-wide mb-3" x-text="title"></h3>

            <template x-if="task === 'explain' && selection">
                <blockquote class="border-l-4 border-violet-200 pl-3 mb-3 text-[12px] text-gray-500 italic"
                            x-text="selection.length > 300 ? selection.slice(0, 300) + '…' : selection"></blockquote>
            </template>

            <template x-if="task === 'quiz' && questions.length">
                <ol class="space-y-3">
                    <template x-for="(q, i) in questions" :key="i">
                        <li class="rounded-xl border border-gray-100 p-3">
                            <p class="text-[13px] font-extrabold text-gray-900" x-text="(i + 1) + '. ' + q.q"></p>
                            <button type="button" @click="toggleAnswer(i)" class="mt-1.5 text-[11px] font-bold text-violet-600 hover:text-violet-800"
                                    x-text="opened[i] ? 'Скрыть ответ' : 'Показать ответ'"></button>
                            <div x-show="opened[i]" class="lesson-ai-result mt-1.5" x-html="q.a_html"></div>
                        </li>
                    </template>
                </ol>
            </template>

            <template x-if="task !== 'quiz'">
                <div class="lesson-ai-result" x-html="html"></div>
            </template>

            <p x-show="cached" class="mt-3 text-[11px] text-gray-400">Пересказ подготовлен заранее для всех слушателей занятия.</p>
        </div>
    </div>

    {{-- Действия с результатом --}}
    <div x-show="state === 'done'" class="shrink-0 border-t border-gray-100 p-3 flex flex-wrap gap-2 bg-gray-50/60">
        <template x-if="task === 'outline'">
            <button type="button" @click="replaceNotes()" class="px-3 py-2 rounded-xl bg-violet-500 hover:bg-violet-600 text-white text-[12px] font-extrabold">Заменить заметки</button>
        </template>
        <button type="button" @click="addToNotes()" class="px-3 py-2 rounded-xl text-[12px] font-extrabold"
                :class="task === 'outline' ? 'bg-white border border-gray-200 hover:bg-gray-100 text-gray-700' : 'bg-violet-500 hover:bg-violet-600 text-white'">В заметки</button>
        <button type="button" @click="copy()" class="px-3 py-2 rounded-xl bg-white border border-gray-200 hover:bg-gray-100 text-gray-700 text-[12px] font-extrabold">Копировать</button>
        <template x-if="task === 'quiz' || task === 'outline'">
            <button type="button" @click="retry()" class="px-3 py-2 rounded-xl bg-white border border-gray-200 hover:bg-gray-100 text-gray-700 text-[12px] font-extrabold">Ещё вариант</button>
        </template>
        <template x-if="notesBefore !== null">
            <button type="button" @click="undoNotes()" class="px-3 py-2 rounded-xl bg-white border border-gray-200 hover:bg-gray-100 text-gray-700 text-[12px] font-extrabold">Вернуть прежние</button>
        </template>
        <p x-show="flash" x-text="flash" class="basis-full text-[11px] font-semibold text-gray-500"></p>
    </div>
</div>

{{-- Плавающая кнопка у выделенного текста --}}
<button type="button" x-show="pick.show" x-cloak data-lesson-ai-pick
        @mousedown.prevent @click="explainPicked()"
        :style="'position:fixed;left:' + pick.x + 'px;top:' + pick.y + 'px;z-index:60'"
        class="px-3 py-1.5 rounded-full bg-violet-600 hover:bg-violet-700 text-white text-[12px] font-extrabold shadow-lg">
    ✦ Объяснить
</button>
