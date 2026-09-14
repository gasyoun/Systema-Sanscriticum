@extends('layouts.shop')

@section('title', 'Онлайн-группы грамматики санскрита с М. Ю. Гасунсом')

@section('content')
<main class="max-w-3xl mx-auto px-4 py-12">
    <p class="text-brand font-bold mb-3">Общество ревнителей санскрита</p>
    <h1 class="text-3xl font-bold text-white mb-5">Онлайн-группы грамматики санскрита с нуля</h1>
    <div class="text-slate-300 space-y-4 mb-8">
        <p>Суббота, 19 сентября 2026 года, 12:00 МСК. Второе время — вторник, 08:00 МСК. Занятия идут раз в неделю, обычно полтора часа, иногда до двух.</p>
        <p>Начинаем с санскритского письма — деванагари, затем занимаемся по «Учебнику санскрита» В. А. Кочергиной. к. ф. н. М. Ю. Гасунс преподает санскрит в университете с 2007 г.</p>
        <p>8 000 руб. для РФ; для оплаты из-за границы напишите <a class="text-brand underline" href="https://t.me/rusamskrtam">@rusamskrtam</a>.</p>
    </div>
    <section class="rounded-2xl bg-[#111622] border border-[#1F2636] p-6" aria-labelledby="lead-form">
        <h2 id="lead-form" class="text-xl font-bold text-white mb-2">Встать в список ожидания</h2>
        <p class="text-slate-400 mb-5">Оставьте Telegram, телефон или почту. Мы поможем выбрать группу.</p>
        <form action="{{ route('leads.store') }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}" method="POST" class="space-y-4">
            @csrf
            <input type="hidden" name="form_name" value="grammar_gasuns_autumn_2026">
            <label class="block text-sm text-slate-200" for="contact">Контакт</label>
            <input id="contact" name="contact" required autocomplete="email" class="w-full rounded-lg bg-slate-900 border border-slate-700 text-white p-3" placeholder="@telegram, телефон или почта">
            <label class="flex gap-2 text-sm text-slate-400"><input type="checkbox" name="is_promo_agreed" value="1"> Можно написать мне о наборе</label>
            <button class="w-full rounded-lg bg-brand hover:bg-brand-hover text-white font-bold p-3" type="submit">Встать в список ожидания</button>
        </form>
    </section>
</main>
@endsection
