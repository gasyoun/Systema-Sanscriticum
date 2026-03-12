<nav class="space-y-1">
    <a href="{{ route('student.dashboard') }}" 
       class="{{ request()->routeIs('student.dashboard') ? 'bg-indigo-800 text-white' : 'text-indigo-100 hover:bg-indigo-600' }} group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors">
        <i class="fas fa-th-large mr-3 w-6 text-center text-indigo-300"></i> 
        Кабинет
    </a>
    
    <a href="{{ route('student.calendar') }}" 
       class="{{ request()->routeIs('student.calendar') ? 'bg-indigo-800 text-white' : 'text-indigo-100 hover:bg-indigo-600' }} group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-colors">
        <i class="fas fa-calendar-alt mr-3 w-6 text-center text-indigo-300"></i>
        Расписание
    </a>

    @if(isset($courses) && $courses->isNotEmpty())
        <div class="pt-6 mt-6 border-t border-indigo-500/50">
            <p class="px-3 text-xs font-semibold text-indigo-200 uppercase tracking-wider mb-2 opacity-70">
                Мои материалы
            </p>
            
            <div class="space-y-1">
                @foreach($courses as $c)
                    @php
                        $isActive = request()->is('course/' . $c->slug . '*');
                    @endphp

                    <a href="{{ route('student.course', $c->slug) }}" 
                       class="{{ $isActive ? 'bg-indigo-800 text-white shadow-sm' : 'text-indigo-100 hover:bg-indigo-600' }} group flex items-center px-2 py-2 text-sm font-medium rounded-md transition-all">
                        
                        <i class="{{ $isActive ? 'fas fa-folder-open text-yellow-400' : 'fas fa-folder text-indigo-300 group-hover:text-white' }} mr-3 w-6 text-center transition-colors"></i>
                        
                        <span class="truncate">{{ $c->title }}</span>
                        
                        @if($isActive)
                            <i class="fas fa-chevron-right ml-auto text-xs text-indigo-300"></i>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</nav>
