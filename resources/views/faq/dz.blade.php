@extends('layouts.articles')

@section('title', 'Как сдавать домашние задания — FAQ')
@section('meta_description', 'Инструкция для учеников ОРС: где сдавать ДЗ в кабинете, лимиты файлов, статусы, как исправить ошибочный файл.')

@push('head')
    <meta name="robots" content="index, follow">
@endpush

@section('content')
<div class="container mx-auto px-4 max-w-3xl py-10 md:py-14 font-nunito">

    <nav class="mb-6 text-sm text-gray-500">
        <a href="{{ url('/') }}" class="hover:text-brand transition-colors">Главная</a>
        <span class="mx-2 text-gray-300">/</span>
        <a href="{{ route('student.dashboard') }}" class="hover:text-brand transition-colors">Кабинет</a>
        <span class="mx-2 text-gray-300">/</span>
        <span class="text-gray-700">Домашние задания</span>
    </nav>

    <div class="flex items-center gap-3 mb-6">
        <div class="w-12 h-12 rounded-xl bg-orange-50 text-brand flex items-center justify-center shrink-0">
            <i class="fas fa-pen-nib text-lg"></i>
        </div>
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-[#101010] leading-tight">
                Как сдавать домашние задания
            </h1>
            <p class="text-sm text-gray-500 mt-0.5">FAQ для учеников</p>
        </div>
    </div>

    <p class="text-gray-600 leading-relaxed mb-8">
        <span class="font-bold text-gray-900">Каждое ДЗ сдаётся на странице своего урока</span>
        (урок 1 → ДЗ-1, урок 2 → ДЗ-2). Проверяет живой куратор; статусы видны в кабинете.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Где сдавать</h2>
    <ol class="list-decimal list-inside space-y-2 text-gray-700 leading-relaxed mb-8">
        <li>Войти в <a href="{{ route('login') }}" class="text-brand underline hover:no-underline">личный кабинет</a></li>
        <li>Открыть свой <strong>курс</strong></li>
        <li>Открыть <strong>нужный урок</strong></li>
        <li>Ниже видео — блок <strong>«Домашнее задание»</strong></li>
    </ol>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Когда есть форма</h2>
    <ul class="space-y-3 mb-8 text-gray-700 leading-relaxed">
        <li><span class="font-bold text-emerald-700">Форма</span> — приём открыт: текст и/или файлы, затем «Отправить на проверку».</li>
        <li><span class="font-bold text-amber-700">«Ещё не задано»</span> — условие скоро появится.</li>
        <li><span class="font-bold text-gray-500">«ДЗ нет»</span> — приём к уроку ещё не открыт (не ошибка с вашей стороны).</li>
    </ul>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Файлы</h2>
    <p class="text-gray-700 leading-relaxed mb-2">
        До <strong>10 файлов</strong> за отправку, каждый до <strong>30 МБ</strong>, все вместе до ~90 МБ.
        Фото, PDF, Word, аудио, короткое видео.
    </p>
    <p class="text-gray-500 text-sm leading-relaxed mb-8">
        Можно выбирать файлы по одному (сначала фото, потом аудио) — они копятся в списке.
    </p>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Статусы</h2>
    <div class="overflow-hidden rounded-2xl border border-gray-200 mb-8 bg-white shadow-sm">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-100">
                <tr class="bg-gray-50/80">
                    <td class="px-4 py-3 font-bold text-gray-800 whitespace-nowrap">Черновик</td>
                    <td class="px-4 py-3 text-gray-600">Сохранили, но ещё не отправили</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-bold text-blue-700 whitespace-nowrap">На проверке</td>
                    <td class="px-4 py-3 text-gray-600">У куратора; можно дописать или удалить свой ошибочный файл</td>
                </tr>
                <tr class="bg-gray-50/80">
                    <td class="px-4 py-3 font-bold text-red-700 whitespace-nowrap">На доработку</td>
                    <td class="px-4 py-3 text-gray-600">Прочитайте замечания и отправьте снова</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-bold text-emerald-700 whitespace-nowrap">Принято</td>
                    <td class="px-4 py-3 text-gray-600">Зачтено — менять нельзя</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h2 class="text-xl font-bold text-gray-900 mb-3">Ошиблись файлом</h2>
    <ol class="list-decimal list-inside space-y-2 text-gray-700 leading-relaxed mb-4">
        <li>Откройте <strong>тот урок</strong>, куда ушёл неверный файл</li>
        <li>Корзина рядом с файлом → удалить</li>
        <li>В переписке останется отметка «Удалён файл …» (с датой)</li>
        <li>Прикрепите правильный → снова «Отправить на проверку»</li>
    </ol>
    <p class="text-gray-500 text-sm leading-relaxed mb-8">
        После статуса «Принято» правка только через поддержку.
    </p>

    <div class="bg-orange-50/80 border border-orange-100 rounded-2xl p-5 mb-8">
        <p class="font-bold text-gray-900 mb-2">Чеклист перед отправкой</p>
        <ul class="text-gray-700 space-y-1.5 text-sm">
            <li>✓ Открыт тот урок, к которому задание</li>
            <li>✓ Файлы именно этого урока (не копия предыдущего ДЗ)</li>
            <li>✓ Нажато «Отправить на проверку», не только «Черновик»</li>
        </ul>
    </div>

    <p class="text-gray-600 leading-relaxed text-sm">
        Не получается — напишите в поддержку в кабинете или куратору в чате группы:
        email входа, номер урока, что видите на экране.
    </p>

    <p class="mt-8">
        <a href="{{ route('login') }}"
           class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-brand text-white font-extrabold text-sm hover:bg-brand-hover transition-colors">
            Войти в кабинет
            <i class="fas fa-arrow-right text-xs"></i>
        </a>
    </p>
</div>
@endsection
