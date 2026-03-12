@extends('layouts.student')

@section('title', 'Моё расписание')
@section('header', 'Календарь занятий')

@section('content') 

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">

    {{-- Заголовок раздела --}}
    <div class="mb-8 md:mb-10">
        <h1 class="text-3xl md:text-4xl font-extrabold text-[#101010] tracking-tight mb-2">Расписание занятий</h1>
        <p class="text-gray-500 text-lg">Ваш личный календарь предстоящих вебинаров и онлайн-встреч.</p>
        <div class="w-16 h-1.5 bg-[#E85C24] rounded-full mt-4"></div>
    </div>

    <div class="space-y-10">
        @forelse($groupedEvents as $date => $events)
            
            {{-- БЛОК ОДНОГО ДНЯ --}}
            <div>
                {{-- Красивый компактный разделитель даты --}}
                <div class="flex items-center gap-4 mb-5">
                    <div class="bg-[#101010] text-white px-4 py-1.5 rounded-lg text-sm font-extrabold tracking-widest uppercase shadow-sm flex items-center">
                        <i class="far fa-calendar-alt text-[#E85C24] mr-2 text-base"></i>
                        {{ $date }}
                    </div>
                    <div class="flex-1 h-px bg-gray-200"></div>
                </div>
                
                {{-- СЕТКА КОМПАКТНЫХ КАРТОЧЕК (1 колонка на мобильном, 2 на планшете, 3 на ПК) --}}
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5">
                    @foreach($events as $event)
                        
                        {{-- Компактная премиум-карточка --}}
                        <div class="bg-white rounded-2xl border border-gray-100 p-4 md:p-5 hover:shadow-[0_10px_25px_rgba(0,0,0,0.06)] hover:border-[#E85C24]/30 transition-all duration-300 group flex flex-col h-full relative overflow-hidden">
                            
                            {{-- Оранжевая линия слева при наведении --}}
                            <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#E85C24] opacity-0 group-hover:opacity-100 transition-opacity duration-300"></div>

                            {{-- Верхний ряд: Время и Тег курса --}}
                            <div class="flex justify-between items-start gap-2 mb-3">
                                {{-- Время --}}
                                <div class="inline-flex items-center text-xs md:text-sm font-extrabold text-[#E85C24] bg-orange-50 px-2.5 py-1 rounded-md border border-orange-100/50 shrink-0">
                                    <i class="far fa-clock mr-1.5"></i>
                                    {{ \Carbon\Carbon::parse($event->start)->format('H:i') }} 
                                    <span class="opacity-50 mx-1">-</span> 
                                    {{ \Carbon\Carbon::parse($event->end)->format('H:i') }}
                                </div>
                                
                                {{-- Тег курса (обрезается, чтобы не ломать верстку) --}}
                                @if($event->course)
                                    <div class="text-[9px] md:text-[10px] font-bold uppercase tracking-widest text-gray-400 border border-gray-100 px-2 py-1 rounded bg-gray-50 truncate max-w-[50%]" title="{{ $event->course->title }}">
                                        {{ $event->course->title }}
                                    </div>
                                @endif
                            </div>

                            {{-- Название --}}
                            <h3 class="text-base md:text-lg font-bold text-[#101010] group-hover:text-[#E85C24] transition-colors leading-tight mb-2 line-clamp-2">
                                {{ $event->title ?? ($event->course->title ?? 'Событие') }}
                            </h3>
                            
                            {{-- Описание (очень коротко, в 1 строку) --}}
                            @if($event->description)
                                <p class="text-gray-500 text-xs md:text-sm line-clamp-1 mb-3">
                                    {{ $event->description }}
                                </p>
                            @endif

                            {{-- Кнопки действий (всегда внизу карточки) --}}
                            <div class="mt-auto pt-4 border-t border-gray-50">
                                @if($event->link)
                                    {{-- Компактная, но сочная кнопка Zoom --}}
                                    <a href="{{ $event->link }}" target="_blank" class="flex items-center justify-center w-full px-4 py-2.5 bg-[#E85C24] hover:bg-[#d6501f] text-white text-sm font-bold rounded-xl transition-all shadow-[0_4px_12px_rgba(232,92,36,0.2)] hover:shadow-[0_8px_16px_rgba(232,92,36,0.35)] hover:-translate-y-0.5">
                                        <span class="relative flex h-2 w-2 mr-2.5">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
                                        </span>
                                        Подключиться (Zoom)
                                    </a>
                                @elseif($event->course)
                                    {{-- Кнопка "К курсу" --}}
                                    <a href="{{ route('student.course', $event->course->slug) }}" class="flex items-center justify-center w-full px-4 py-2.5 bg-gray-50 hover:bg-gray-100 border border-gray-200 text-gray-700 hover:text-[#E85C24] text-sm font-bold rounded-xl transition-all">
                                        Материалы <i class="fas fa-arrow-right ml-1.5 text-xs opacity-70"></i>
                                    </a>
                                @endif
                            </div>

                        </div>
                    @endforeach
                </div>
            </div>

        @empty
            {{-- ПУСТОЕ СОСТОЯНИЕ --}}
            <div class="text-center py-16 bg-white rounded-[2rem] border border-dashed border-gray-200 shadow-sm mt-8 max-w-2xl mx-auto">
                <div class="bg-orange-50 rounded-full w-20 h-20 flex items-center justify-center mx-auto mb-5">
                    <i class="far fa-calendar-times text-3xl text-[#E85C24]"></i>
                </div>
                <h3 class="text-2xl font-extrabold text-[#101010] mb-2">Нет предстоящих занятий</h3>
                <p class="text-gray-500 text-base max-w-sm mx-auto">В ближайшее время в вашем расписании нет запланированных вебинаров или встреч.</p>
            </div>
        @endforelse
    </div>

</div>

@endsection