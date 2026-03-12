@extends('layouts.student')

@section('title', $course->title)
@section('header', 'Содержание курса')

@section('content')

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 font-nunito">
    
    {{-- ========================================== --}}
    {{-- ШАПКА КУРСА И ПРОГРЕСС                     --}}
    {{-- ========================================== --}}
    <div class="relative bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8 md:p-10 mb-12 overflow-hidden">
        
        {{-- Декоративный фоновый элемент --}}
        <div class="absolute top-0 right-0 -mt-10 -mr-10 w-40 h-40 bg-orange-50 rounded-full blur-3xl opacity-50 pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row gap-8 items-start">
            <div class="flex-1 w-full">
                <div class="inline-flex items-center px-4 py-1.5 rounded-full text-xs font-extrabold uppercase tracking-widest bg-orange-50 text-[#E85C24] mb-5">
                    <i class="fas fa-graduation-cap mr-2"></i> Программа курса
                </div>
                
                <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[#101010] mb-4 tracking-tight leading-tight">
                    {{ $course->title }}
                </h1>
                
                <p class="text-gray-500 text-base md:text-lg leading-relaxed mb-8 max-w-3xl">
                    {{ $course->description }}
                </p>

                @php
                    $total = $lessons->count();
                    $completed = auth()->user()->completedLessons->whereIn('id', $lessons->pluck('id'))->count();
                    $percent = $total > 0 ? round(($completed / $total) * 100) : 0;
                @endphp
                
                {{-- Современный прогресс-бар --}}
                <div class="bg-gray-50 p-5 md:p-6 rounded-2xl border border-gray-100">
                    <div class="flex justify-between items-end mb-3">
                        <div>
                            <span class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">Ваш прогресс</span>
                            <span class="text-sm font-bold text-gray-800">{{ $completed }} из {{ $total }} уроков пройдено</span>
                        </div>
                        <div class="text-2xl font-extrabold text-[#E85C24]">{{ $percent }}%</div>
                    </div>
                    
                    <div class="bg-white rounded-full h-3 w-full overflow-hidden border border-gray-100 shadow-inner">
                        <div class="h-full bg-[#E85C24] rounded-full transition-all duration-1000 shadow-[0_0_10px_rgba(232,92,36,0.5)] relative overflow-hidden" style="width: {{ $percent }}%">
                            {{-- Блик на прогресс-баре --}}
                            <div class="absolute inset-0 bg-white/20 w-full h-full -skew-x-12 translate-x-full animate-[shimmer_2s_infinite]"></div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- ========================================== --}}
    {{-- СПИСОК УРОКОВ (СОВРЕМЕННЫЕ КАРТОЧКИ)       --}}
    {{-- ========================================== --}}
    <div class="mb-6">
        <h2 class="text-2xl font-extrabold text-gray-900 flex items-center">
            Уроки
            <span class="ml-3 px-3 py-1 bg-gray-100 text-gray-500 text-sm rounded-full">{{ $total }}</span>
        </h2>
    </div>
        
    <div class="space-y-4">
        @forelse($lessons as $index => $lesson)
            @php
                // Проверка доступа
                $isUnlocked = in_array('full', $unlockedTariffs) || in_array('block_' . $lesson->block_number, $unlockedTariffs);
                $isCompleted = auth()->user()->completedLessons->contains($lesson->id);
            @endphp

            <a href="{{ $isUnlocked ? route('student.lesson', [$course->slug, $lesson->id]) : '#' }}" 
               class="group block bg-white rounded-2xl border transition-all duration-300 relative overflow-hidden
                      {{ $isUnlocked 
                          ? 'border-gray-100 hover:border-[#E85C24]/30 hover:shadow-lg hover:-translate-y-1' 
                          : 'border-gray-50 bg-gray-50/50 cursor-not-allowed opacity-75' }}">
                
                {{-- Цветная полоска слева для пройденных уроков --}}
                @if($isCompleted)
                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-green-500"></div>
                @endif

                <div class="p-5 md:p-6 flex items-center gap-4 md:gap-6">
                    
                    {{-- ИКОНКА / СТАТУС СЛЕВА --}}
                    <div class="flex-shrink-0">
                        @if(!$isUnlocked)
                            <div class="w-12 h-12 rounded-2xl bg-gray-200 text-gray-400 flex items-center justify-center shadow-inner">
                                <i class="fas fa-lock text-lg"></i>
                            </div>
                        @elseif($isCompleted)
                            <div class="w-12 h-12 rounded-2xl bg-green-50 text-green-500 flex items-center justify-center border border-green-100">
                                <i class="fas fa-check text-xl"></i>
                            </div>
                        @else
                            <div class="w-12 h-12 rounded-2xl bg-orange-50 text-[#E85C24] flex items-center justify-center border border-orange-100 group-hover:bg-[#E85C24] group-hover:text-white transition-colors">
                                <i class="fas fa-play ml-1"></i>
                            </div>
                        @endif
                    </div>

                    {{-- ИНФОРМАЦИЯ ОБ УРОКЕ --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-1.5">
                            <span class="text-[10px] font-bold uppercase tracking-widest {{ $isUnlocked ? 'text-[#E85C24]' : 'text-gray-400' }}">
                                Урок {{ $index + 1 }}
                            </span>
                            @if(!$isUnlocked)
                                <span class="text-[10px] font-bold px-2 py-0.5 bg-gray-200 text-gray-500 rounded-md uppercase tracking-wider">
                                    Блок {{ $lesson->block_number }}
                                </span>
                            @endif
                        </div>
                        
                        <h3 class="text-lg md:text-xl font-bold truncate transition-colors leading-tight
                                   {{ $isUnlocked ? 'text-gray-900 group-hover:text-[#E85C24]' : 'text-gray-500' }}">
                            {{ $lesson->title }}
                        </h3>
                        
                        <div class="flex flex-wrap items-center mt-2 text-xs md:text-sm text-gray-500 gap-4 font-medium">
                            @if($lesson->duration)
                                <span class="flex items-center">
                                    <i class="far fa-clock mr-1.5 text-gray-400"></i> {{ $lesson->duration }} мин
                                </span>
                            @endif
                            @if($isUnlocked && $lesson->attachments && count($lesson->attachments) > 0)
                                <span class="flex items-center">
                                    <i class="fas fa-paperclip mr-1.5 text-gray-400"></i> Материалы
                                </span>
                            @endif
                            @if(!$isUnlocked)
                                <span class="text-red-500 flex items-center">
                                    <i class="fas fa-shopping-cart mr-1.5"></i> Нужна оплата
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- КНОПКА ДЕЙСТВИЯ СПРАВА --}}
                    <div class="hidden sm:flex flex-shrink-0 ml-4 transition-transform">
                        @if($isCompleted)
                            <span class="text-xs font-bold text-green-500 uppercase tracking-widest px-4 py-2 bg-green-50 rounded-xl">
                                Пройдено
                            </span>
                        @elseif($isUnlocked)
                            <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400 group-hover:bg-[#E85C24] group-hover:text-white transition-all group-hover:translate-x-1">
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        @else
                            <div class="w-10 h-10 rounded-full bg-transparent flex items-center justify-center text-gray-300">
                                <i class="fas fa-lock"></i>
                            </div>
                        @endif
                    </div>

                </div>
            </a>
        @empty
            <div class="p-16 text-center bg-white rounded-[2rem] border border-dashed border-gray-200">
                <div class="w-20 h-20 mx-auto bg-gray-50 rounded-full flex items-center justify-center mb-4">
                    <i class="fas fa-inbox text-3xl text-gray-300"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-1">Уроков пока нет</h3>
                <p class="text-gray-500">Автор еще не добавил уроки в этот курс.</p>
            </div>
        @endforelse
    </div>

</div>

@endsection