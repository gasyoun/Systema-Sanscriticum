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

            <form method="post" action="{{ route('student.testimonial.store') }}" class="space-y-5"
                  enctype="multipart/form-data" id="testimonial-form">
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

                {{-- Фото и видео — по желанию. Лимиты = TestimonialSubmissionController::*_MAX_KB;
                     проверяем ещё и в браузере, чтобы не гнать 300 МБ, которые сервер всё равно отобьёт. --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="avatar" class="block text-sm font-bold text-gray-700 mb-1">Ваше фото <span class="font-normal text-gray-400">(необязательно)</span></label>
                        <div class="flex items-center gap-3">
                            <img id="avatar-preview" src="" alt="" class="hidden w-14 h-14 rounded-full object-cover border border-gray-200">
                            <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp"
                                   data-max-kb="{{ \App\Http\Controllers\Student\TestimonialSubmissionController::AVATAR_MAX_KB }}"
                                   data-too-big="Фото больше 5 МБ — уменьшите, пожалуйста."
                                   class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:font-bold file:text-gray-700 hover:file:bg-gray-200">
                        </div>
                        <p class="mt-1 text-xs text-gray-500">JPG, PNG или WebP, до 5 МБ. Появится рядом с вашим именем.</p>
                        <p class="mt-1 text-sm text-red-600" data-error-for="avatar">@error('avatar'){{ $message }}@enderror</p>
                    </div>
                    <div>
                        <label for="video" class="block text-sm font-bold text-gray-700 mb-1">Видео-отзыв <span class="font-normal text-gray-400">(необязательно)</span></label>
                        <input id="video" name="video" type="file" accept="video/mp4,video/quicktime,video/webm"
                               data-max-kb="{{ \App\Http\Controllers\Student\TestimonialSubmissionController::VIDEO_MAX_KB }}"
                               data-too-big="Видео больше 100 МБ — снимите покороче или пришлите ссылку на ролик ниже."
                               class="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:font-bold file:text-gray-700 hover:file:bg-gray-200">
                        <video id="video-preview" class="hidden mt-2 w-full max-h-48 rounded-lg bg-black" controls muted playsinline></video>
                        <p class="mt-1 text-xs text-gray-500">MP4, MOV или WebM, до 100 МБ — это примерно 1–2 минуты с телефона.</p>
                        <p class="mt-1 text-sm text-red-600" data-error-for="video">@error('video'){{ $message }}@enderror</p>
                    </div>
                </div>

                <div>
                    <label for="media_url" class="block text-sm font-bold text-gray-700 mb-1">Или ссылка на видео <span class="font-normal text-gray-400">(VK, YouTube, Rutube — необязательно)</span></label>
                    <input id="media_url" name="media_url" type="url" maxlength="1024" inputmode="url"
                           value="{{ old('media_url') }}" placeholder="https://vk.com/video…"
                           class="w-full rounded-xl border border-gray-300 px-4 py-2.5 focus:border-brand focus:ring-brand">
                    @error('media_url') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="flex items-start gap-3 text-sm text-gray-700">
                        <input type="checkbox" name="consent" value="1" required @checked(old('consent'))
                               class="mt-0.5 rounded text-brand focus:ring-brand">
                        <span>Разрешаю школе опубликовать этот отзыв с указанной подписью на сайте samskrte.ru — вместе с фото и видео, если я их приложил(а).</span>
                    </label>
                    @error('consent') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <button type="submit" id="testimonial-submit"
                        class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-brand text-white font-bold hover:opacity-90 transition-opacity disabled:opacity-60">
                    <i class="fas fa-paper-plane"></i> <span data-label>Отправить отзыв</span>
                </button>
            </form>
        </div>
    @endif
</div>

<script>
    // Предпросмотр фото/видео + проверка размера в браузере (сервер проверяет ещё раз).
    // На время отправки кнопка говорит «Загружаем…» — видео на 100 МБ едет не мгновенно.
    (function () {
        const form = document.getElementById('testimonial-form');
        if (!form) return;

        function check(input) {
            const box = form.querySelector('[data-error-for="' + input.name + '"]');
            const file = input.files && input.files[0];
            if (file && file.size > Number(input.dataset.maxKb) * 1024) {
                box.textContent = input.dataset.tooBig;
                input.value = '';
                return null;
            }
            box.textContent = '';
            return file || null;
        }

        const avatar = document.getElementById('avatar');
        const avatarPreview = document.getElementById('avatar-preview');
        avatar.addEventListener('change', function () {
            const file = check(avatar);
            avatarPreview.classList.toggle('hidden', !file);
            if (file) avatarPreview.src = URL.createObjectURL(file);
        });

        const video = document.getElementById('video');
        const videoPreview = document.getElementById('video-preview');
        video.addEventListener('change', function () {
            const file = check(video);
            videoPreview.classList.toggle('hidden', !file);
            if (file) videoPreview.src = URL.createObjectURL(file);
            else videoPreview.removeAttribute('src');
        });

        form.addEventListener('submit', function () {
            const btn = document.getElementById('testimonial-submit');
            btn.disabled = true;
            btn.querySelector('[data-label]').textContent = video.files.length ? 'Загружаем видео…' : 'Отправляем…';
        });
    })();
</script>
@endsection
