@extends('layouts.shop')
@section('title', 'Бесплатный мини-курс санскрита — записаться')

{{--
    Короткая запись на бесплатный мини-курс (ссылка VK-бота): имя + email +
    согласие → кабинет и курс сразу. MiniCourseQuickEnrollController.
--}}

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('mini-course-quick-form');
    if (form) {
        form.addEventListener('submit', function () {
            if (typeof window.shopReachGoal === 'function') {
                window.shopReachGoal('quick_enroll_submit');
            }
        });
    }
});
</script>
@endpush

@section('content')
<div class="min-h-[70vh] bg-gray-50 py-10 sm:py-16 px-4">
    <div class="max-w-lg mx-auto">
        <div class="text-center mb-8">
            <span class="inline-block mb-3 px-3 py-1 rounded-full bg-orange-100 text-orange-700 text-xs font-bold uppercase tracking-wide">Бесплатно</span>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900 leading-tight">{{ $course->title }}</h1>
            <p class="mt-3 text-gray-600">
                Уроки — в вашем личном кабинете. Первый урок откроется сразу, следующие — раз в {{ (int) config('mini_courses.drip_days', 3) }} дня.
            </p>
        </div>

        <div class="bg-white p-6 sm:p-8 rounded-3xl shadow-sm border border-gray-100">
            <form id="mini-course-quick-form" method="POST" action="{{ route('mini-course.quick.store') }}" class="space-y-5">
                @csrf

                @auth
                    <p class="text-gray-700">Вы вошли как <b>{{ auth()->user()->email }}</b>. Курс откроется в вашем кабинете.</p>
                @else
                    <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;">
                        <label>Оставьте это поле пустым
                            <input type="text" name="website" tabindex="-1" autocomplete="off" value="">
                        </label>
                    </div>
                    <input type="hidden" name="ff_ts" value="{{ encrypt((string) now()->timestamp) }}">

                    <div>
                        <label for="mcq-name" class="block text-sm font-medium text-gray-700 mb-1">Имя</label>
                        <input id="mcq-name" type="text" name="name" required maxlength="60" autocomplete="given-name"
                               placeholder="Как к вам обращаться" value="{{ old('name') }}"
                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-orange-500 focus:ring-orange-500 py-3 px-4">
                        @error('name')<p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="mcq-email" class="block text-sm font-medium text-gray-700 mb-1">Email <small class="text-gray-500">(для входа в кабинет)</small></label>
                        <input id="mcq-email" type="email" name="email" required autocomplete="email" inputmode="email"
                               placeholder="ivan@example.com" value="{{ old('email') }}"
                               class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-orange-500 focus:ring-orange-500 py-3 px-4">
                        @error('email')<p class="mt-1.5 text-xs text-red-500">{{ $message }}</p>@enderror
                    </div>

                    <x-consent.pd />
                @endauth

                <button type="submit"
                        class="w-full py-4 rounded-2xl bg-orange-600 hover:bg-orange-700 text-white text-lg font-extrabold transition">
                    Начать бесплатно
                </button>

                @guest
                    <p class="text-center text-xs text-gray-500">
                        Без оплаты и без пароля: кабинет создастся сам, данные для входа придут на почту.
                    </p>
                @endguest
            </form>
        </div>

        <p class="mt-6 text-center text-sm text-gray-500">
            <a href="{{ route('shop.course.show', $course->slug) }}" class="hover:underline">Подробнее о курсе</a>
        </p>
    </div>
</div>
@endsection
