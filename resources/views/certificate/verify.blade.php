@extends('layouts.shop')

@section('title', 'Проверка сертификата')

@section('content')
<div class="container mx-auto px-4 py-12 md:py-20 flex justify-center">
    <div class="w-full max-w-xl">

        @if($certificate)
            {{-- ═══ СЕРТИФИКАТ ПОДТВЕРЖДЁН ═══ --}}
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl overflow-hidden shadow-2xl">

                <div class="bg-emerald-500/10 border-b border-emerald-500/20 px-6 py-6 text-center">
                    <div class="w-16 h-16 mx-auto rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30">
                        <i class="fas fa-check text-2xl"></i>
                    </div>
                    <h1 class="mt-4 text-xl md:text-2xl font-extrabold text-white">Сертификат подтверждён</h1>
                    <p class="mt-1 text-sm text-emerald-300/80">Документ зарегистрирован в Обществе ревнителей санскрита</p>
                </div>

                <div class="p-6 md:p-8 space-y-5">
                    <div>
                        <div class="text-[11px] uppercase tracking-widest text-slate-500 font-bold mb-1">Выдан</div>
                        <div class="text-lg md:text-xl font-bold text-white">{{ $certificate->displayStudentName() }}</div>
                    </div>

                    <div>
                        <div class="text-[11px] uppercase tracking-widest text-slate-500 font-bold mb-1">Курс</div>
                        <div class="text-base md:text-lg font-semibold text-slate-200 leading-snug">
                            {!! str_replace('|', '<br>', e($certificate->displayCourseTitle())) !!}
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 pt-2 border-t border-[#1F2636]">
                        <div>
                            <div class="text-[11px] uppercase tracking-widest text-slate-500 font-bold mb-1">Дата выдачи</div>
                            <div class="text-sm font-semibold text-slate-200">
                                {{ \Carbon\Carbon::parse($certificate->issued_at)->format('d.m.Y') }}
                            </div>
                        </div>
                        <div>
                            <div class="text-[11px] uppercase tracking-widest text-slate-500 font-bold mb-1">Номер</div>
                            <div class="text-sm font-mono font-semibold text-[#E85C24]">{{ $certificate->number }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- ═══ НЕ НАЙДЕН ═══ --}}
            <div class="bg-[#111622] border border-[#1F2636] rounded-3xl overflow-hidden shadow-2xl">
                <div class="bg-red-500/10 border-b border-red-500/20 px-6 py-6 text-center">
                    <div class="w-16 h-16 mx-auto rounded-full bg-red-500/90 text-white flex items-center justify-center shadow-lg shadow-red-500/30">
                        <i class="fas fa-times text-2xl"></i>
                    </div>
                    <h1 class="mt-4 text-xl md:text-2xl font-extrabold text-white">Сертификат не найден</h1>
                </div>
                <div class="p-6 md:p-8 text-center">
                    <p class="text-slate-400">
                        Сертификат с номером
                        <span class="font-mono font-semibold text-slate-200">{{ $number }}</span>
                        не зарегистрирован. Проверьте правильность ссылки или QR-кода.
                    </p>
                </div>
            </div>
        @endif

        <div class="mt-6 text-center">
            <a href="{{ route('shop.index') }}" class="text-sm font-semibold text-slate-400 hover:text-[#E85C24] transition-colors">
                <i class="fas fa-arrow-left mr-1.5"></i> На главную
            </a>
        </div>

    </div>
</div>
@endsection
