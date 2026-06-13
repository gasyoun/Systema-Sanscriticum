@php
    $hwStatus = $homeworkSubmission->status ?? null;
    $hwEditable = ! $homeworkSubmission || $homeworkSubmission->isEditableByStudent();
    $hwBadge = match($hwStatus) {
        'submitted' => ['bg-blue-50 text-blue-700 border-blue-200', 'fa-hourglass-half', 'На проверке'],
        'needs_revision' => ['bg-red-50 text-red-700 border-red-200', 'fa-rotate-left', 'На доработку'],
        'accepted' => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'fa-circle-check', 'Принято'],
        'draft' => ['bg-gray-100 text-gray-600 border-gray-200', 'fa-pen', 'Черновик'],
        default => null,
    };
    $hwRefFiles = is_array($lesson->homework_attachments) ? $lesson->homework_attachments : [];
@endphp
<section class="font-nunito">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">

        {{-- Шапка --}}
        <div class="flex items-center justify-between gap-4 mb-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-[#E85C24]/10 text-[#E85C24] flex items-center justify-center shrink-0">
                    <i class="fas fa-pen-nib"></i>
                </div>
                <div>
                    <h3 class="text-lg font-extrabold text-gray-900 leading-tight">Домашнее задание</h3>
                    <p class="hidden sm:block text-[13px] text-gray-500">Выполните задание и отправьте на проверку</p>
                </div>
            </div>
            @if($hwBadge)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-bold whitespace-nowrap shrink-0 {{ $hwBadge[0] }}">
                    <i class="fas {{ $hwBadge[1] }}"></i> {{ $hwBadge[2] }}
                </span>
            @endif
        </div>

        {{-- Условие задания --}}
        @if($lesson->homework_prompt)
            <div class="bg-orange-50/60 border border-orange-100 rounded-2xl p-5 mb-6">
                <p class="text-[11px] font-extrabold uppercase tracking-wider text-[#E85C24] mb-2">Задание</p>
                <div class="prose prose-sm max-w-none text-gray-800 leading-relaxed">{!! nl2br(e($lesson->homework_prompt)) !!}</div>
                @if(count($hwRefFiles))
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($hwRefFiles as $rf)
                            <a href="{{ asset('storage/'.$rf) }}" target="_blank" download
                               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-orange-200 text-sm font-semibold text-gray-700 hover:border-[#E85C24] hover:text-[#E85C24] transition-colors">
                                <i class="fas fa-paperclip text-xs"></i> {{ basename($rf) }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Флеш --}}
        @if(session('success'))
            <div class="mb-5 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-3">
                <i class="fas fa-check-circle mr-1.5"></i>{{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-5 bg-red-50 border border-red-200 text-red-700 text-sm rounded-xl px-4 py-3">
                <i class="fas fa-triangle-exclamation mr-1.5"></i>{{ session('error') }}
            </div>
        @endif

        {{-- Тред переписки --}}
        @if($homeworkSubmission && $homeworkSubmission->comments->isNotEmpty())
            <div class="space-y-4 mb-6">
                @foreach($homeworkSubmission->comments as $c)
                    @php
                        $isStudent = $c->author_role === 'student';
                        $who = $isStudent ? 'Вы' : 'Преподаватель';
                    @endphp
                    <div class="flex {{ $isStudent ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] rounded-2xl border p-4 {{ $isStudent ? 'bg-gray-50 border-gray-200' : 'bg-blue-50/70 border-blue-200' }}">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="text-xs font-extrabold {{ $isStudent ? 'text-gray-700' : 'text-blue-700' }}">{{ $who }}</span>
                                @if($c->type === 'review')
                                    <span class="text-[10px] font-bold uppercase tracking-wide text-gray-400">проверка</span>
                                @endif
                                <span class="text-[11px] text-gray-400">{{ $c->created_at->format('d.m.Y H:i') }}</span>
                            </div>
                            @if($c->body)
                                <p class="text-sm text-gray-800 whitespace-pre-line leading-relaxed">{{ $c->body }}</p>
                            @endif
                            @if($c->files->isNotEmpty())
                                <div class="mt-2.5 flex flex-wrap gap-2">
                                    @foreach($c->files as $f)
                                        <a href="{{ route('homework.file.download', $f) }}"
                                           class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-gray-200 text-xs font-semibold text-gray-700 hover:border-[#E85C24] hover:text-[#E85C24] transition-colors">
                                            <i class="fas {{ $f->isImage() ? 'fa-image' : 'fa-file-arrow-down' }} text-xs"></i>
                                            {{ \Illuminate\Support\Str::limit($f->original_name, 28) }}
                                            <span class="text-gray-400">{{ $f->humanSize() }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Форма сдачи / статус --}}
        @if($hwEditable)
            <form action="{{ route('student.homework.store', [$course->slug, $lesson->id]) }}" method="POST" enctype="multipart/form-data"
                  x-data="{ files: [] }">
                @csrf
                @if($hwStatus === 'needs_revision')
                    <p class="text-sm text-red-600 font-semibold mb-3"><i class="fas fa-rotate-left mr-1.5"></i>Преподаватель вернул работу — внесите правки и отправьте снова.</p>
                @endif

                <label class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-400 mb-1.5">Ваш ответ</label>
                <textarea name="body" rows="5"
                          class="w-full rounded-2xl border border-gray-200 bg-gray-50 p-4 focus:bg-white focus:border-[#E85C24] focus:ring-1 focus:ring-[#E85C24] outline-none transition text-sm resize-y"
                          placeholder="Опишите решение, добавьте комментарий для преподавателя...">{{ old('body') }}</textarea>

                <div class="mt-4">
                    <label class="flex flex-col items-center justify-center gap-2 w-full rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/50 hover:border-[#E85C24] hover:bg-orange-50/30 transition-colors p-6 cursor-pointer">
                        <i class="fas fa-cloud-arrow-up text-2xl text-gray-400"></i>
                        <span class="text-sm font-semibold text-gray-600">Прикрепить файлы (фото, PDF, аудио, документы)</span>
                        <span class="text-xs text-gray-400">До 10 файлов, каждый до 30 МБ</span>
                        <input type="file" name="files[]" multiple class="hidden"
                               accept=".pdf,.jpg,.jpeg,.png,.heic,.webp,.mp3,.m4a,.ogg,.wav,.doc,.docx,.txt"
                               x-on:change="files = Array.from($event.target.files).map(f => f.name)">
                    </label>
                    <template x-if="files.length">
                        <ul class="mt-2 space-y-1">
                            <template x-for="name in files" :key="name">
                                <li class="text-xs text-gray-600 flex items-center gap-1.5"><i class="fas fa-file text-gray-400"></i><span x-text="name"></span></li>
                            </template>
                        </ul>
                    </template>
                    @error('files.*')<p class="text-red-500 text-xs mt-2">{{ $message }}</p>@enderror
                </div>

                <div class="mt-5 flex flex-col sm:flex-row gap-3">
                    <button type="submit" name="action" value="submit"
                            class="flex-1 bg-[#E85C24] hover:bg-[#d04a15] text-white font-extrabold py-3.5 px-4 rounded-xl shadow-lg transition-all text-sm uppercase tracking-wider">
                        <i class="fas fa-paper-plane mr-2"></i>Отправить на проверку
                    </button>
                    <button type="submit" name="action" value="draft"
                            class="px-5 py-3.5 rounded-xl border border-gray-200 bg-white text-gray-600 font-bold text-sm hover:border-gray-300 transition-colors">
                        Сохранить черновик
                    </button>
                </div>
            </form>
        @elseif($hwStatus === 'submitted')
            <div class="flex items-center gap-3 rounded-xl bg-blue-50/70 border border-blue-100 px-4 py-3">
                <i class="fas fa-hourglass-half text-blue-500 shrink-0"></i>
                <p class="text-[13px] text-blue-800"><span class="font-bold">Работа на проверке.</span> Сообщим на почту, когда появится результат.</p>
            </div>
        @elseif($hwStatus === 'accepted')
            <div class="flex items-center gap-3 rounded-xl bg-emerald-50 border border-emerald-100 px-4 py-3">
                <i class="fas fa-circle-check text-emerald-500 shrink-0"></i>
                <p class="text-[13px] text-emerald-800"><span class="font-bold">Работа принята.</span> Поздравляем! 🎉</p>
            </div>
        @endif

    </div>
</section>
