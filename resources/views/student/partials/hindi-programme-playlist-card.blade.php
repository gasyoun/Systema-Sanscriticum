@if (! empty($hindiTeacherBrief))
    <a href="{{ $hindiTeacherBrief }}"
       target="_blank"
       rel="noopener"
       class="mb-4 flex items-center justify-between gap-4 bg-orange-50 rounded-2xl border border-orange-100 hover:border-brand/40 hover:shadow-lg p-5 md:p-6 transition-all"
       data-testid="hindi-teacher-brief">
        <div>
            <div class="text-[10px] font-extrabold uppercase tracking-widest text-brand mb-1">Для преподавателя</div>
            <div class="text-lg font-extrabold text-gray-900">Задача по хинди</div>
            <p class="text-sm text-gray-500 mt-1">
                Что открыть в кабинете и что написать в ответ
            </p>
        </div>
        <i class="fas fa-external-link-alt text-gray-300"></i>
    </a>
@endif
@if (! empty($hindiPlaylist['eligible']))
    <a href="{{ route('student.programme.hindi') }}"
       class="mb-6 flex items-center justify-between gap-4 bg-white rounded-2xl border border-gray-100 hover:border-brand/30 hover:shadow-lg p-5 md:p-6 transition-all"
       data-testid="hindi-programme-playlist-card">
        <div>
            <div class="text-[10px] font-extrabold uppercase tracking-widest text-brand mb-1">Программа</div>
            <div class="text-lg font-extrabold text-gray-900">Мой хинди</div>
            <p class="text-sm text-gray-500 mt-1">
                Все открытые занятия по потокам хинди — {{ $hindiPlaylist['count'] }}
            </p>
        </div>
        <i class="fas fa-chevron-right text-gray-300"></i>
    </a>
@endif
