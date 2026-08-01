@extends('layouts.student')

@section('title', 'Как сдавать домашние задания')
@section('header', 'Помощь')

@section('content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 pb-12 font-nunito">

    <a href="{{ route('student.dashboard') }}"
       class="inline-flex items-center gap-2 text-sm font-bold text-gray-500 hover:text-[#E85C24] transition-colors mb-6">
        <i class="fas fa-arrow-left text-xs"></i> В кабинет
    </a>

    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 rounded-xl bg-orange-50 text-[#E85C24] flex items-center justify-center shrink-0">
                <i class="fas fa-pen-nib text-lg"></i>
            </div>
            <div>
                <h1 class="text-2xl font-extrabold text-gray-900 leading-tight">Как сдавать домашние задания</h1>
                <p class="text-sm text-gray-500 mt-0.5">Инструкция для учеников</p>
            </div>
        </div>

        <p class="text-gray-600 leading-relaxed mb-8">
            <span class="font-bold text-gray-900">Каждое ДЗ сдаётся на странице своего урока</span>
            (урок 1 → ДЗ-1, урок 2 → ДЗ-2). Проверяет живой куратор; статусы видны здесь же.
        </p>

        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-400 mb-3">Где сдавать</h2>
        <ol class="list-decimal list-inside space-y-2 text-gray-700 leading-relaxed mb-8">
            <li>Кабинет → свой <strong>курс</strong></li>
            <li>Откройте <strong>нужный урок</strong></li>
            <li>Ниже видео — блок <strong>«Домашнее задание»</strong></li>
        </ol>

        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-400 mb-3">Когда есть форма</h2>
        <ul class="space-y-3 mb-8">
            <li class="flex gap-3 text-sm text-gray-700 leading-relaxed">
                <span class="font-bold text-emerald-600 shrink-0">Форма</span>
                <span>Приём открыт — пишите ответ и/или прикрепляйте файлы, затем «Отправить на проверку».</span>
            </li>
            <li class="flex gap-3 text-sm text-gray-700 leading-relaxed">
                <span class="font-bold text-amber-600 shrink-0">«Ещё не задано»</span>
                <span>Условие скоро появится — загляните позже.</span>
            </li>
            <li class="flex gap-3 text-sm text-gray-700 leading-relaxed">
                <span class="font-bold text-gray-500 shrink-0">«ДЗ нет»</span>
                <span>Приём к этому уроку ещё не открыт — не ошибка с вашей стороны.</span>
            </li>
        </ul>

        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-400 mb-3">Файлы</h2>
        <p class="text-sm text-gray-700 leading-relaxed mb-2">
            До <strong>10 файлов</strong> за отправку, каждый до <strong>30 МБ</strong>, все вместе до ~90 МБ.
            Фото, PDF, Word, аудио, короткое видео.
        </p>
        <p class="text-sm text-gray-500 leading-relaxed mb-8">
            Можно выбрать файлы по одному (сначала фото, потом аудио) — они копятся в списке.
        </p>

        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-400 mb-3">Статусы</h2>
        <div class="overflow-hidden rounded-2xl border border-gray-100 mb-8">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    <tr class="bg-gray-50/50">
                        <td class="px-4 py-3 font-bold text-gray-800 whitespace-nowrap">Черновик</td>
                        <td class="px-4 py-3 text-gray-600">Сохранили, но ещё не отправили</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-3 font-bold text-blue-700 whitespace-nowrap">На проверке</td>
                        <td class="px-4 py-3 text-gray-600">У куратора; можно дописать или удалить свой ошибочный файл</td>
                    </tr>
                    <tr class="bg-gray-50/50">
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

        <h2 class="text-sm font-extrabold uppercase tracking-wider text-gray-400 mb-3">Ошиблись файлом</h2>
        <ol class="list-decimal list-inside space-y-2 text-sm text-gray-700 leading-relaxed mb-4">
            <li>Откройте <strong>тот урок</strong>, куда ушёл неверный файл</li>
            <li>Корзина рядом с файлом → удалить</li>
            <li>В переписке останется отметка «Удалён файл …» (с датой)</li>
            <li>Прикрепите правильный → снова «Отправить на проверку»</li>
        </ol>
        <p class="text-sm text-gray-500 leading-relaxed mb-8">
            После статуса «Принято» правка только через поддержку.
        </p>

        <div class="bg-orange-50/60 border border-orange-100 rounded-2xl p-5 mb-6">
            <p class="text-sm font-bold text-gray-900 mb-2">Чеклист перед отправкой</p>
            <ul class="text-sm text-gray-700 space-y-1.5">
                <li>✓ Открыт тот урок, к которому задание</li>
                <li>✓ Файлы именно этого урока (не копия предыдущего ДЗ)</li>
                <li>✓ Нажато «Отправить на проверку», не только «Черновик»</li>
            </ul>
        </div>

        <p class="text-sm text-gray-500 leading-relaxed">
            Не получается — напишите в <a href="{{ route('student.messages') }}" class="font-bold text-[#E85C24] hover:underline">сообщения / поддержку</a>
            или куратору в чате группы: email входа, номер урока, что видите на экране.
        </p>
    </div>

</div>
@endsection
