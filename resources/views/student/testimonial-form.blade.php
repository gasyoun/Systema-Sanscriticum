@extends('layouts.student')

@section('title', 'Оставить отзыв')
@section('header', 'Оставить отзыв')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito" data-analytics="student-testimonial-form">

    <a href="{{ route('student.dashboard') }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-brand transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> В кабинет
    </a>

    @if (session('status'))
        <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-sm font-bold text-green-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($pending)
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-xl font-extrabold text-[#101010] mb-2">Ваш отзыв на проверке</h2>
            <p class="text-gray-600 mb-4">
                Отправлен {{ $pending->submitted_at?->format('d.m.Y') }}. Когда мы его проверим, он появится
                на странице входа и на странице <a href="{{ route('shop.testimonials.library') }}" class="text-brand underline">отзывов</a>.
                Новый отзыв можно будет написать после проверки.
            </p>
            <blockquote class="border-l-2 border-brand pl-4 text-gray-700 whitespace-pre-line">{{ $pending->body }}</blockquote>
            <p class="mt-3 text-sm text-gray-500">— {{ $pending->author_name }}@if (filled($pending->city)), {{ $pending->city }}@endif</p>
        </div>
    @else
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-xl font-extrabold text-[#101010] mb-2">Расскажите о своих занятиях</h2>
            <p class="text-gray-600 mb-6">
                Что получилось, что было трудно, кому вы бы посоветовали курс. Отзыв появится на сайте после проверки —
                мы не переписываем текст, можем только поправить опечатки.
            </p>

            <form method="post" action="{{ route('student.testimonial.store') }}" class="space-y-5">
                @csrf

                <div>
                    <label for="body" class="block text-sm font-bold text-gray-700 mb-1">Отзыв</label>
                    <textarea id="body" name="body" rows="7" required minlength="30" maxlength="2000"
                              class="w-full rounded-xl border border-gray-300 px-4 py-3 focus:border-brand focus:ring-brand">{{ old('body') }}</textarea>
                    @error('body') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <span class="block text-sm font-bold text-gray-700 mb-1">Оценка <span class="font-normal text-gray-400">(необязательно)</span></span>
                    <div class="flex gap-4">
                        @for ($i = 1; $i <= 5; $i++)
                            <label class="inline-flex items-center gap-1 text-sm text-gray-700">
                                <input type="radio" name="rating" value="{{ $i }}" @checked((int) old('rating') === $i)
                                       class="text-brand focus:ring-brand">
                                {{ $i }} <i class="fas fa-star text-xs text-brand"></i>
                            </label>
                        @endfor
                    </div>
                    @error('rating') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="author_name" class="block text-sm font-bold text-gray-700 mb-1">Как вас подписать</label>
                        <input id="author_name" name="author_name" type="text" required maxlength="80"
                               value="{{ old('author_name', $defaultName) }}"
                               class="w-full rounded-xl border border-gray-300 px-4 py-2.5 focus:border-brand focus:ring-brand">
                        <p class="mt-1 text-xs text-gray-500">Можно сократить: «Анна К.»</p>
                        @error('author_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="city" class="block text-sm font-bold text-gray-700 mb-1">Город <span class="font-normal text-gray-400">(необязательно)</span></label>
                        <input id="city" name="city" type="text" maxlength="80"
                               value="{{ old('city', $defaultCity) }}"
                               class="w-full rounded-xl border border-gray-300 px-4 py-2.5 focus:border-brand focus:ring-brand">
                        @error('city') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="flex items-start gap-3 text-sm text-gray-700">
                        <input type="checkbox" name="consent" value="1" required @checked(old('consent'))
                               class="mt-0.5 rounded text-brand focus:ring-brand">
                        <span>Разрешаю школе опубликовать этот отзыв с указанной подписью на сайте samskrte.ru.</span>
                    </label>
                    @error('consent') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <button type="submit"
                        class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-brand text-white font-bold hover:opacity-90 transition-opacity">
                    <i class="fas fa-paper-plane"></i> Отправить отзыв
                </button>
            </form>
        </div>
    @endif
</div>
@endsection
