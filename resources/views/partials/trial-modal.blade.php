{{-- Глобальная модалка «Купить пробное занятие».
     Подключается один раз: @include('partials.trial-modal').
     Триггер — window-событие `open-trial-modal` с detail { action, title, amount }.
     При ошибке валидации (bag `trial`, напр. гость ввёл существующий email) модалка
     сама открывается заново с сообщением и сохранёнными полями — иначе редирект
     назад выглядел бы как «тихая перезагрузка». --}}
@php($trialErrors = $errors->getBag('trial'))
<div x-data="{
        open: {{ $trialErrors->isNotEmpty() ? 'true' : 'false' }},
        action: @js($trialErrors->isNotEmpty() && isset($course) ? route('trial.create', $course->slug) : ''),
        courseTitle: @js($trialErrors->isNotEmpty() && isset($course) ? $course->title : ''),
        amount: {{ $trialErrors->isNotEmpty() && isset($course) ? (float) $course->trial_price : 0 }},
        sessionDate: @js($trialErrors->isNotEmpty() && isset($course) ? (optional($course->trialSchedule?->start)->translatedFormat('d F, H:i') ?? '') : ''),
        isRecording: {{ ($trialErrors->isNotEmpty() && ! empty($trialIsRecording)) ? 'true' : 'false' }},
        get amountFormatted() {
            return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(this.amount);
        }
     }"
     x-show="open"
     x-cloak
     x-on:open-trial-modal.window="
        action = $event.detail.action;
        courseTitle = $event.detail.title;
        amount = Number($event.detail.amount) || 0;
        sessionDate = $event.detail.date || '';
        isRecording = $event.detail.isRecording || false;
        open = true;
     "
     x-on:keydown.escape.window="open = false"
     class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
     x-on:click.self="open = false"
     style="display: none;">

    <div class="bg-[#111622] text-white rounded-2xl shadow-2xl w-full max-w-md border border-[#1F2636] overflow-hidden"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">

        <div class="flex items-center justify-between px-6 py-4 border-b border-[#1F2636]">
            <h3 class="text-lg font-bold flex items-center gap-2">
                <i class="fas fa-graduation-cap text-[#38BDF8]"></i>
                Пробное занятие
            </h3>
            <button type="button" x-on:click="open = false"
                    class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-[#1F2636] transition-colors">
                <i class="fas fa-times text-slate-400"></i>
            </button>
        </div>

        <form :action="action" method="POST" class="p-6 space-y-4">
            @csrf

            @if($trialErrors->isNotEmpty())
                <div class="rounded-xl border border-red-500/40 bg-red-500/10 p-3.5 text-sm text-red-300 leading-relaxed">
                    <i class="fas fa-circle-exclamation mr-1.5"></i>{{ $trialErrors->first() }}
                </div>
            @endif

            <p class="text-sm text-slate-400 leading-relaxed">
                <span class="font-bold text-white" x-text="isRecording ? 'Запись занятия' : 'Живое занятие'"></span> курса
                <span class="font-bold text-white" x-text="courseTitle"></span><span x-show="sessionDate"> — <span class="text-[#38BDF8] font-semibold" x-text="sessionDate"></span></span>.
                Сумма <span class="font-bold text-[#38BDF8]"><span x-text="amountFormatted"></span> ₽</span>
                будет зачтена в стоимость тарифа при последующей оплате.
            </p>

            {{-- Живое занятие --}}
            <div x-show="!isRecording" class="rounded-xl border border-[#38BDF8]/30 bg-[#38BDF8]/10 p-3.5">
                <div class="text-[10px] font-black uppercase tracking-widest text-[#38BDF8] mb-1.5">
                    <i class="fas fa-broadcast-tower mr-1"></i> Это живой урок в Zoom
                </div>
                <p class="text-[13px] text-slate-300 leading-relaxed">
                    Вы подключаетесь к занятию <span class="font-semibold text-white">вживую</span> — после оплаты пришлём
                    ссылку на Zoom и время на email. <span class="font-semibold text-white">Запись</span> появится в личном
                    кабинете уже после занятия.
                </p>
            </div>

            {{-- Запись прошедшего занятия --}}
            <div x-show="isRecording" x-cloak class="rounded-xl border border-[#38BDF8]/30 bg-[#38BDF8]/10 p-3.5">
                <div class="text-[10px] font-black uppercase tracking-widest text-[#38BDF8] mb-1.5">
                    <i class="fas fa-circle-play mr-1"></i> Это запись занятия
                </div>
                <p class="text-[13px] text-slate-300 leading-relaxed">
                    После оплаты <span class="font-semibold text-white">сразу откроем доступ</span> к записи в личном
                    кабинете — смотрите в удобное время.
                </p>
            </div>

            @guest
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Фамилия</label>
                        <input type="text" name="surname" required minlength="2" maxlength="255"
                               value="{{ old('surname') }}"
                               placeholder="Например, Иванов"
                               class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#38BDF8] focus:ring-1 focus:ring-[#38BDF8] py-3 px-4 transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Имя</label>
                        <input type="text" name="name" required minlength="2" maxlength="255"
                               value="{{ old('name') }}"
                               placeholder="Например, Иван"
                               class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#38BDF8] focus:ring-1 focus:ring-[#38BDF8] py-3 px-4 transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Email <span class="text-slate-500 normal-case">(сюда придёт доступ)</span></label>
                    <input type="email" name="email" required maxlength="255"
                           value="{{ old('email') }}"
                           placeholder="you@example.com"
                           class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#38BDF8] focus:ring-1 focus:ring-[#38BDF8] py-3 px-4 transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Город</label>
                    <input type="text" name="city" required maxlength="255"
                           value="{{ old('city') }}"
                           placeholder="Например, Москва"
                           class="block w-full rounded-xl bg-[#0A0D14] border border-[#1F2636] text-white placeholder-slate-600 focus:border-[#38BDF8] focus:ring-1 focus:ring-[#38BDF8] py-3 px-4 transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5 uppercase tracking-wider">Откуда вы о нас узнали? <span class="text-slate-500 normal-case">(необязательно)</span></label>
                    @include('partials.signup-source-select', ['dark' => true])
                </div>

                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input type="checkbox" name="wants_announcements" value="1" @checked(old('wants_announcements', true))
                           class="mt-0.5 h-5 w-5 rounded border-[#1F2636] bg-[#0A0D14] text-[#38BDF8] focus:ring-[#38BDF8]">
                    <span class="text-[13px] text-slate-400">Получать анонсы, новости и расписание на email</span>
                </label>

                <p class="text-[11px] text-slate-500 leading-relaxed">
                    После оплаты пришлём пароль на email — войдёте в личный кабинет и откроете пробное занятие.
                </p>
            @endguest

            <button type="submit"
                    class="w-full flex justify-center items-center py-3.5 px-4 bg-[#38BDF8] hover:bg-[#2da4dd] text-white text-base font-bold rounded-xl transition-all shadow-lg shadow-[#38BDF8]/20">
                <i class="fas fa-credit-card mr-2"></i>
                Оплатить <span class="ml-1" x-text="amountFormatted"></span>&nbsp;₽
            </button>
        </form>
    </div>
</div>
