{{-- H5066: публичная форма интереса на курс. Флаг course_interest_form (OFF = 404).
     Анти-бот honeypot + time-trap — та же схема, что в подписке на рассылку.
     Лэйаут shop — как у анкет /anketa/{slug} (SurveyPageController). --}}
@extends('layouts.shop')

@section('title', $courseTitle !== '' ? 'Интерес к курсу — '.$courseTitle : 'Интерес к курсу')

@section('content')
<div class="w-full max-w-2xl mx-auto flex flex-col gap-8 font-nunito pb-20">

    <div class="bg-gradient-to-r from-white to-gray-50 rounded-[2rem] p-8 md:p-10 shadow-sm border border-gray-100">
        <h1 class="text-3xl font-black text-[#1A1A1A] mb-2 tracking-tight">
            {{ $courseTitle !== '' ? '«'.$courseTitle.'»' : 'Интерес к курсу' }}
        </h1>
        <p class="text-gray-500 font-medium text-sm md:text-base">
            Большинство наших курсов не повторяются, но занятие можно возобновить, если соберётся
            группа. Оставьте заявку — куратор напишет вам, когда наберётся нужное количество желающих
            или откроется новый набор.
        </p>
        @if ($course !== null && $course->revive_threshold !== null)
            <p class="text-gray-400 font-medium text-xs mt-3">
                @if (($counts[\App\Models\CourseInterestRequest::INTENT_REVIVE] ?? 0) >= $course->revive_threshold)
                    Порог возобновления уже собран ({{ $counts[\App\Models\CourseInterestRequest::INTENT_REVIVE] ?? 0 }} из {{ $course->revive_threshold }}) — куратор решает дату запуска.
                @else
                    Для возобновления собираем {{ $course->revive_threshold }} заявок — уже есть {{ $counts[\App\Models\CourseInterestRequest::INTENT_REVIVE] ?? 0 }}.
                @endif
            </p>
        @endif
    </div>

    @if (session('course_interest_status'))
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-2xl p-4 font-semibold text-sm">
            {{ session('course_interest_status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-2xl p-4 font-semibold text-sm">
            <ul class="list-disc list-inside">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('course-interest.store', ['course' => $courseSlug]) }}"
          class="bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8 flex flex-col gap-5">
        @csrf
        {{-- Анти-бот honeypot: человек этого поля не видит и не заполняет; --}}
        {{-- заполнено → отправку тихо отбрасываем на сервере. --}}
        <div aria-hidden="true"
            style="position: absolute; left: -9999px; top: -9999px; width: 1px; height: 1px; overflow: hidden;">
            <label>Оставьте это поле пустым
                <input type="text" name="website" tabindex="-1" autocomplete="off" value="">
            </label>
        </div>
        {{-- Анти-бот time-trap: зашифрованная метка времени рендера формы. --}}
        <input type="hidden" name="ff_ts" value="{{ encrypt((string) now()->timestamp) }}">

        <fieldset>
            <legend class="block text-sm font-bold text-gray-700 mb-3">Что вы хотите?</legend>
            <div class="flex flex-col gap-3">
                @foreach ($intentLabels as $intentValue => $intentLabel)
                    <label class="flex items-start gap-3 rounded-xl border border-gray-200 px-4 py-3 cursor-pointer hover:border-gray-300
                                  {{ old('intent', \App\Models\CourseInterestRequest::INTENT_JOIN) === $intentValue ? 'border-[color:var(--brand,#6366f1)] ring-1 ring-[color:var(--brand,#6366f1)]' : '' }}">
                        <input type="radio" name="intent" value="{{ $intentValue }}" required
                               class="mt-1" @checked(old('intent', \App\Models\CourseInterestRequest::INTENT_JOIN) === $intentValue)>
                        <span class="text-sm font-medium text-gray-700">{{ $intentLabel }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <label for="ci-name" class="block text-sm font-bold text-gray-700 mb-2">Имя</label>
            <input id="ci-name" type="text" name="name" maxlength="255"
                   value="{{ old('name') }}"
                   class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand/40"
                   placeholder="Как к вам обращаться">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label for="ci-email" class="block text-sm font-bold text-gray-700 mb-2">Email</label>
                <input id="ci-email" type="email" name="email" maxlength="255"
                       value="{{ old('email') }}"
                       class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand/40"
                       placeholder="или Telegram — хоть что-то одно">
            </div>
            <div>
                <label for="ci-telegram" class="block text-sm font-bold text-gray-700 mb-2">Telegram</label>
                <input id="ci-telegram" type="text" name="telegram" maxlength="255"
                       value="{{ old('telegram') }}"
                       class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand/40"
                       placeholder="@username или телефон">
            </div>
        </div>

        <div>
            <label for="ci-comment" class="block text-sm font-bold text-gray-700 mb-2">Комментарий (необязательно)</label>
            <textarea id="ci-comment" name="comment" maxlength="1000" rows="3"
                      class="w-full rounded-xl border border-gray-200 px-4 py-3 text-sm font-medium focus:outline-none focus:ring-2 focus:ring-brand/40"
                      placeholder="Удобное время, вопросы по курсу">{{ old('comment') }}</textarea>
        </div>

        <button type="submit"
                class="self-start rounded-xl bg-[color:var(--brand,#6366f1)] px-6 py-3 text-sm font-bold text-white shadow-sm hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-brand/40">
            Оставить заявку
        </button>
    </form>
</div>
@endsection
