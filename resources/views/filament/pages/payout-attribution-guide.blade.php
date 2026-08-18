@php
    $money = fn ($v): string => number_format((float) $v, 2, ',', ' ');
@endphp

<x-filament-panels::page>

    {{-- Главное, одной врезкой: что вообще делает подтверждение. --}}
    <div class="rounded-xl bg-primary-50 p-4 text-sm ring-1 ring-primary-500/20 dark:bg-primary-500/10">
        <p class="font-semibold text-primary-900 dark:text-primary-200">Самое важное в двух строчках</p>
        <p class="mt-1 text-primary-900/80 dark:text-primary-200/80">
            Вы отвечаете на один вопрос по каждому платежу: <b>эти деньги ушли преподавателю
            или это обычный расход школы?</b> Ваш ответ <b>не переводит никаких денег</b> —
            он только помечает, чем платёж был.
        </p>
    </div>

    <section class="space-y-2">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Зачем это нужно</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Часть выплат преподавателям исторически проводили не через «Историю выплат», а
            обычным платежом с тарифом <b>«Расход»</b>, причём на служебного пользователя.
            В таком виде программа не может отличить их от аренды зала или рекламы — и не
            имеет права решать за вас. Пока такие платежи не размечены, остаток по
            преподавателю на экране «Потоки курса» показывается со словом
            <b>«предварительно»</b> и не является ответом на вопрос «сколько мы должны».
        </p>
    </section>

    {{-- Список строится из живой очереди: инструкция, разошедшаяся с экраном,
         хуже отсутствующей. --}}
    <section class="space-y-3">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Что сейчас ждёт вашего решения</h2>

        @if ($pending->isEmpty())
            <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                Неразмеченных платежей нет — делать сейчас ничего не нужно.
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ $pending->count() }} {{ $pendingWord }} на <b>{{ $money($pendingTotal) }} ₽</b>.
            </p>

            @foreach ($byTeacher as $teacherName => $rows)
                <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full text-sm">
                        <caption class="bg-gray-50 px-4 py-2 text-left font-semibold text-gray-900 dark:bg-white/5 dark:text-white">
                            {{ $teacherName }}
                        </caption>
                        <thead class="bg-gray-50/60 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">№ платежа</th>
                                <th class="px-4 py-2 text-left font-semibold">Дата</th>
                                <th class="px-4 py-2 text-left font-semibold">Курс</th>
                                <th class="px-4 py-2 text-left font-semibold">На кого заведён</th>
                                <th class="px-4 py-2 text-right font-semibold">Сумма, ₽</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach ($rows as $row)
                                <tr>
                                    <td class="px-4 py-2 font-medium text-gray-900 dark:text-white">{{ $row->payment_id }}</td>
                                    <td class="px-4 py-2">{{ $row->paid_on?->format('d.m.Y') ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->course?->title ?? '—' }}</td>
                                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $row->payment?->user?->name ?? '—' }}</td>
                                    <td class="px-4 py-2 text-right">{{ $money($row->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            <p class="text-xs text-gray-400">
                Список строится из самой очереди — он не может разойтись с тем, что вы увидите,
                когда её откроете.
            </p>
        @endif
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Как проверять</h2>

        <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>
                Откройте <a href="{{ $queueUrl }}" class="font-semibold text-primary-600 underline dark:text-primary-400">«Подтверждение выплат преподавателям»</a>
                — там те же строки, но с кнопками.
            </li>
            <li>
                По каждой строке найдите в банковской выписке <b>этот день и эту сумму</b>.
                Вопрос ровно один: <b>деньги ушли этому преподавателю или кому-то другому?</b>
            </li>
            <li>
                Ушли преподавателю → <b>«✓ Это выплата»</b>. Ушли на аренду, рекламу,
                подрядчику → <b>«✕ Не выплата»</b>.
            </li>
            <li>
                <b>Не нашли платёж или сомневаетесь — оставьте строку как есть.</b>
                Неразмеченная строка честнее размеченной наугад: от этой разметки зависит
                сумма, которую мы назовём преподавателю.
            </li>
        </ol>

        <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10">
            <b>Ошиблись?</b> У решённой строки появляется кнопка «Вернуть в очередь» —
            она снимает ваш ответ, и строка снова становится «Ожидает».
        </div>
    </section>

    <section class="space-y-3">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Чего делать не нужно</h2>
        <ul class="list-disc space-y-2 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>
                <b>Не искать в очереди платежи, заведённые прямо на преподавателя.</b>
                Они уже посчитаны и в список не попадают — подтверждать их ещё раз не нужно
                и не получится.
            </li>
            <li>
                <b>Не создавать строки вручную.</b> Кнопки «Создать» здесь нет: строки
                заводит программа, ваше дело — подтвердить или отклонить.
            </li>
            <li>
                <b>Не искать эти платежи в «Истории выплат».</b> Ваше подтверждение туда
                ничего не добавляет — это отдельное действие, и делает его не эта страница.
            </li>
        </ul>
    </section>

    <section class="space-y-2">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Где посмотреть результат</h2>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            На экране <a href="{{ $streamsUrl }}" class="font-semibold text-primary-600 underline dark:text-primary-400">«Потоки курса»</a>,
            блок «Начислено, выплачено, остаток». Когда неразмеченных платежей не останется,
            слово «предварительно» исчезнет само, и остаток станет подтверждённой цифрой.
            Кнопка <b>«Акт сверки (PDF)»</b> там же печатает одну страницу по преподавателю,
            с отдельной пустой строкой «решение о доплате сверх остатка».
        </p>
    </section>

    <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-500 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
        Непонятно что-то на этой странице — напишите администратору школы: этот текст
        поправят. Вопросы по конкретному платежу тоже к нему, а не в переписку наружу.
    </div>

</x-filament-panels::page>
