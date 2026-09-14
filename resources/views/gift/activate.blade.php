@extends('layouts.shop')

@section('title', 'Активация подарочного сертификата')

@section('content')
<div class="container mx-auto px-4 py-12 md:py-20 flex justify-center">
    <div class="w-full max-w-xl">

        <div class="bg-white border border-gray-100 rounded-3xl shadow-xl shadow-gray-100/40 p-8 md:p-10">
            <div class="text-center mb-8">
                <div class="w-16 h-16 mx-auto rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-gift text-2xl"></i>
                </div>
                <h1 class="mt-4 text-2xl font-extrabold text-gray-900 tracking-tight">Подарочный сертификат</h1>
                <p class="mt-2 text-sm text-gray-500">Введите код из письма — доступ к выбранному курсу откроется сразу.</p>
            </div>

            @if(session('success'))
                <div class="mb-6 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-2xl p-4 text-sm font-medium flex items-start gap-2">
                    <i class="fas fa-check-circle mt-0.5"></i>
                    <span>{{ session('success') }}</span>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 text-sm font-medium flex items-start gap-2">
                    <i class="fas fa-exclamation-circle mt-0.5"></i>
                    <ul class="space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $message ?? $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('gift.activate.attempt') }}" method="POST" class="space-y-5">
                @csrf
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Код активации</label>
                    <input type="text" id="code" name="code" required
                           placeholder="GIFT-XXXX-XXXX-XXXX-XXXX"
                           value="{{ old('code') }}"
                           autocomplete="off" spellcheck="false"
                           class="block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 py-3 px-4 font-mono uppercase tracking-wider transition">
                    <p class="mt-1.5 text-xs text-gray-400">Код одноразовый: активировать можно один раз, любым регистром.</p>
                </div>

                <button type="submit"
                        class="w-full inline-flex justify-center items-center px-8 py-4 text-base font-bold rounded-2xl text-white bg-indigo-600 hover:bg-indigo-700 transition-all duration-200 shadow-lg shadow-indigo-200">
                    Активировать сертификат
                </button>
            </form>
        </div>

        <div class="mt-6 text-center">
            <a href="{{ route('shop.index') }}" class="text-sm font-semibold text-slate-400 hover:text-indigo-600 transition-colors">
                <i class="fas fa-arrow-left mr-1.5"></i> На главную
            </a>
        </div>

    </div>
</div>
@endsection
