@php
    $hwStatus = $homeworkSubmission->status ?? null;
    $hwEditable = ! $homeworkSubmission || $homeworkSubmission->isEditableByStudent();
    // Курс формата «приём есть, проверки нет» (H3081): обещать «На проверке»
    // здесь было бы неправдой — работу никто не разбирает и статус никогда не
    // сменится. Студенту говорим ровно то, что происходит.
    $hwUnreviewed = \App\Support\HomeworkReviewPolicy::isUnreviewedLesson($lesson);
    $hwBadge = match($hwStatus) {
        'submitted' => $hwUnreviewed
            ? ['bg-gray-100 text-gray-700 border-gray-200', 'fa-inbox', 'Сдано',
                'Работа сохранена. На этом курсе задания не разбирает преподаватель — сдача нужна вам самим, для ритма и самопроверки. Дополнить или переслать можно в любой момент.']
            : ['bg-blue-50 text-blue-700 border-blue-200', 'fa-hourglass-half', 'На проверке',
                'Работа у куратора. Можно дополнить или исправить — ниже появится новое сообщение в переписке.'],
        'needs_revision' => ['bg-red-50 text-red-700 border-red-200', 'fa-rotate-left', 'На доработку',
            'Куратор вернул работу с комментариями — прочитайте замечания и отправьте снова.'],
        'accepted' => ['bg-emerald-50 text-emerald-700 border-emerald-200', 'fa-circle-check', 'Принято',
            'Задание зачтено — можно двигаться дальше.'],
        'draft' => ['bg-gray-100 text-gray-600 border-gray-200', 'fa-pen', 'Черновик',
            'Черновик сохранен, но еще не отправлен — дополните и отправьте, когда будете готовы.'],
        default => null,
    };
    $hwRefFiles = is_array($lesson->homework_attachments) ? $lesson->homework_attachments : [];
    // ДЗ «включено», но преподаватель ещё не сформулировал условие и студент
    // ничего не сдавал → показываем «скоро», форму прячем (иначе студент видит
    // форму отправки без самого задания и не понимает, что делать).
    $awaitingPrompt = ! filled($lesson->homework_prompt) && ! $homeworkSubmission;

    // Лимиты загрузки берутся из config/homework.php, чтобы подпись формы,
    // фильтр выбора файлов и серверная валидация не разъезжались между собой.
    $hwMaxFiles = (int) config('homework.max_files', 10);
    $hwMaxFileMb = (int) round(config('homework.max_file_kb', 30720) / 1024);
    $hwTotalMaxMb = (int) round(config('homework.total_max_kb', 92160) / 1024);
    $hwAccept = collect(config('homework.allowed_extensions', []))
        ->map(fn ($ext) => '.'.$ext)
        ->implode(',');
    // H2142: куда можно перенести файл, если залито не в тот урок.
    $hwMoveTargets = collect($lessons ?? [])
        ->filter(fn ($l) => (int) $l->id !== (int) $lesson->id && $l->homework_enabled)
        ->values();
@endphp
<section class="font-nunito">
    <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-5 md:p-6">

        {{-- Шапка --}}
        <div class="flex items-center justify-between gap-4 mb-5">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-brand/10 text-brand flex items-center justify-center shrink-0">
                    <i class="fas fa-pen-nib"></i>
                </div>
                <div>
                    <h3 class="text-lg font-extrabold text-gray-900 leading-tight">Домашнее задание</h3>
                    <p class="hidden sm:block text-[13px] text-gray-500">
                        {{ $awaitingPrompt
                            ? 'Скоро здесь появится задание'
                            : ($hwUnreviewed
                                ? 'Выполните задание и сохраните — на этом курсе его не разбирает преподаватель'
                                : 'Выполните задание и отправьте на проверку') }}
                        ·
                        <a href="{{ route('faq.dz') }}" class="text-brand font-semibold hover:underline">как сдавать</a>
                    </p>
                </div>
            </div>
            @if($hwBadge)
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-bold whitespace-nowrap shrink-0 cursor-help {{ $hwBadge[0] }}"
                      title="{{ $hwBadge[3] }}">
                    <i class="fas {{ $hwBadge[1] }}"></i> {{ $hwBadge[2] }}
                </span>
            @endif
        </div>

        {{-- Условие задания --}}
        @if($lesson->homework_prompt)
            <div class="bg-orange-50/60 border border-orange-100 rounded-2xl p-5 mb-6">
                <p class="text-[11px] font-extrabold uppercase tracking-wider text-brand mb-2">Задание</p>
                <div class="prose prose-sm max-w-none text-gray-800 leading-relaxed">{!! nl2br(e($lesson->homework_prompt)) !!}</div>
                @if(count($hwRefFiles))
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach($hwRefFiles as $rf)
                            <a href="{{ route('student.lesson.homework-file', [$course->slug, $lesson->id, basename($rf)]) }}" target="_blank" download
                               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white border border-orange-200 text-sm font-semibold text-gray-700 hover:border-brand hover:text-brand transition-colors">
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
                        $isAudit = $c->type === 'message';
                        $who = $isAudit
                            ? ($isStudent ? 'Вы (отметка)' : 'Преподаватель (отметка)')
                            : ($isStudent ? 'Вы' : 'Преподаватель');
                        // Только сдачи: служебные отметки удаления нельзя стереть.
                        $canDeleteComment = $hwEditable
                            && $isStudent
                            && $c->type === 'submission';
                    @endphp
                    <div class="flex {{ $isAudit ? 'justify-center' : ($isStudent ? 'justify-end' : 'justify-start') }}">
                        <div class="max-w-[85%] rounded-2xl border p-4 {{ $isAudit ? 'bg-amber-50/70 border-amber-100 w-full sm:w-auto' : ($isStudent ? 'bg-gray-50 border-gray-200' : 'bg-blue-50/70 border-blue-200') }}">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="text-xs font-extrabold {{ $isAudit ? 'text-amber-800' : ($isStudent ? 'text-gray-700' : 'text-blue-700') }}">{{ $who }}</span>
                                @if($c->type === 'review')
                                    <span class="text-[10px] font-bold uppercase tracking-wide text-gray-400">проверка</span>
                                @elseif($isAudit)
                                    <span class="text-[10px] font-bold uppercase tracking-wide text-amber-600/80">удаление</span>
                                @endif
                                <span class="text-[11px] text-gray-400">{{ $c->created_at->format('d.m.Y H:i') }}</span>
                                @if($canDeleteComment)
                                    <form action="{{ route('homework.comment.destroy', $c) }}" method="POST" class="ml-auto"
                                          onsubmit="return confirm(@js('Удалить это сообщение и все его файлы? В треде останется отметка.'));">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="w-7 h-7 inline-flex items-center justify-center rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                                title="Удалить сообщение"
                                                aria-label="Удалить сообщение">
                                            <i class="fas fa-trash-can text-[11px]"></i>
                                        </button>
                                    </form>
                                @endif
                            </div>
                            @if($c->body)
                                <p class="text-sm {{ $isAudit ? 'text-amber-950/90' : 'text-gray-800' }} whitespace-pre-line leading-relaxed">{{ $c->body }}</p>
                            @endif
                            @if($c->files->isNotEmpty())
                                <div class="mt-2.5 flex flex-wrap gap-2">
                                    @foreach($c->files as $f)
                                        @php
                                            $canDeleteFile = $hwEditable
                                                && $isStudent
                                                && $c->author_role === 'student';
                                        @endphp
                                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-white border border-gray-200 text-xs font-semibold text-gray-700">
                                            <a href="{{ route('homework.file.download', $f) }}"
                                               class="inline-flex items-center gap-2 px-3 py-1.5 hover:text-brand transition-colors">
                                                <i class="fas {{ $f->isImage() ? 'fa-image' : 'fa-file-arrow-down' }} text-xs"></i>
                                                {{ \Illuminate\Support\Str::limit($f->original_name, 28) }}
                                                <span class="text-gray-400">{{ $f->humanSize() }}</span>
                                            </a>
                                            @if($canDeleteFile && $hwMoveTargets->isNotEmpty())
                                                <form action="{{ route('homework.file.move', $f) }}" method="POST"
                                                      class="inline-flex items-center gap-1 pr-1"
                                                      x-data="{ open: false }">
                                                    @csrf
                                                    <input type="hidden" name="go_to_target" value="1">
                                                    <button type="button"
                                                            class="w-7 h-7 inline-flex items-center justify-center rounded-md text-gray-400 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                                                            title="Перенести в другой урок"
                                                            aria-label="Перенести файл {{ $f->original_name }}"
                                                            x-on:click="open = !open">
                                                        <i class="fas fa-right-left text-[11px]"></i>
                                                    </button>
                                                    <span x-show="open" x-cloak class="inline-flex items-center gap-1">
                                                        <select name="lesson_id" required
                                                                class="text-[11px] rounded-md border border-gray-200 py-1 pl-1.5 pr-6 max-w-[10rem]">
                                                            <option value="">Урок…</option>
                                                            @foreach($hwMoveTargets as $mt)
                                                                <option value="{{ $mt->id }}">{{ \Illuminate\Support\Str::limit($mt->title, 28) }}</option>
                                                            @endforeach
                                                        </select>
                                                        <button type="submit"
                                                                class="px-2 py-1 rounded-md bg-blue-50 text-blue-700 text-[11px] font-bold hover:bg-blue-100"
                                                                title="Перенести">OK</button>
                                                    </span>
                                                </form>
                                            @endif
                                            @if($canDeleteFile)
                                                <form action="{{ route('homework.file.destroy', $f) }}" method="POST" class="pr-1.5"
                                                      onsubmit="return confirm(@js('Удалить файл «'.$f->original_name.'» из работы?'));">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="w-7 h-7 inline-flex items-center justify-center rounded-md text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                                            title="Удалить файл"
                                                            aria-label="Удалить файл {{ $f->original_name }}">
                                                        <i class="fas fa-trash-can text-[11px]"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Форма сдачи / статус --}}
        @if($awaitingPrompt)
            <div class="flex items-center gap-3 rounded-xl bg-amber-50 border border-amber-100 px-4 py-3">
                <i class="fas fa-hourglass-start text-amber-500 shrink-0"></i>
                <p class="text-[13px] text-amber-800"><span class="font-bold">Задание еще не задано.</span> Преподаватель скоро выложит условие — загляните позже.</p>
            </div>
        @elseif($hwEditable)
            @php
                $hwIsSubmitted = $hwStatus === 'submitted';
                $hwIsRework = $hwStatus === 'needs_revision';
                $lastStudentBody = old('body');
                if ($lastStudentBody === null && $homeworkSubmission) {
                    $lastStudentBody = optional(
                        $homeworkSubmission->comments
                            ->where('author_role', 'student')
                            ->last()
                    )->body;
                }
            @endphp
            <form action="{{ route('student.homework.store', [$course->slug, $lesson->id]) }}" method="POST" enctype="multipart/form-data"
                  x-data="{
                      files: [],
                      limit: {{ $hwMaxFiles }},
                      overflow: false,
                      // Браузер заменяет FileList целиком при каждом выборе, поэтому копим сами
                      // и переписываем input.files через DataTransfer — иначе на сервер уходит
                      // только последняя пачка (фото пропадают, когда следом выбирают аудио).
                      add(input) {
                          const merged = [...this.files, ...Array.from(input.files)]
                              .filter((f, i, all) => all.findIndex(g => g.name === f.name && g.size === f.size) === i);
                          this.overflow = merged.length > this.limit;
                          this.sync(merged.slice(0, this.limit), input);
                      },
                      remove(index, input) {
                          this.overflow = false;
                          this.sync(this.files.filter((f, i) => i !== index), input);
                      },
                      sync(list, input) {
                          const dt = new DataTransfer();
                          list.forEach(f => dt.items.add(f));
                          input.files = dt.files;
                          this.files = Array.from(dt.files);
                      },
                  }">
                @csrf
                @if($hwIsRework)
                    <p class="text-sm text-red-600 font-semibold mb-3"><i class="fas fa-rotate-left mr-1.5"></i>Преподаватель вернул работу — внесите правки и отправьте снова.</p>
                @elseif($hwIsSubmitted)
                    <p class="text-sm text-blue-700 font-semibold mb-3"><i class="fas fa-pen-to-square mr-1.5"></i>Работа уже на проверке — можно дополнить текст или файлы. Преподаватель увидит новое сообщение в переписке.</p>
                @endif

                <label class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-400 mb-1.5">Ваш ответ</label>
                <textarea name="body" rows="5"
                          class="w-full rounded-2xl border border-gray-200 bg-gray-50 p-4 focus:bg-white focus:border-brand focus:ring-1 focus:ring-brand outline-none transition text-sm resize-y"
                          placeholder="Опишите решение, добавьте комментарий для преподавателя...">{{ $lastStudentBody }}</textarea>

                <div class="mt-4">
                    <label class="flex flex-col items-center justify-center gap-2 w-full rounded-2xl border-2 border-dashed border-gray-200 bg-gray-50/50 hover:border-brand hover:bg-orange-50/30 transition-colors p-6 cursor-pointer">
                        <i class="fas fa-cloud-arrow-up text-2xl text-gray-400"></i>
                        <span class="text-sm font-semibold text-gray-600">Прикрепить файлы (фото, видео, аудио, PDF, документы)</span>
                        <span class="text-xs text-gray-400">До {{ $hwMaxFiles }} файлов, каждый до {{ $hwMaxFileMb }} МБ, всего до {{ $hwTotalMaxMb }} МБ — можно добавлять по одному, разными форматами</span>
                        <input type="file" name="files[]" multiple class="hidden" x-ref="picker"
                               accept="{{ $hwAccept }}"
                               x-on:change="add($event.target)">
                    </label>
                    <template x-if="overflow">
                        <p class="mt-2 text-xs text-amber-600"><i class="fas fa-triangle-exclamation mr-1"></i>Можно прикрепить не более {{ $hwMaxFiles }} файлов — лишние не добавлены.</p>
                    </template>
                    <template x-if="files.length">
                        <ul class="mt-2 space-y-1">
                            <template x-for="(file, index) in files" :key="file.name + file.size">
                                <li class="text-xs text-gray-600 flex items-center gap-1.5">
                                    <i class="fas fa-file text-gray-400"></i>
                                    <span x-text="file.name"></span>
                                    <button type="button" class="ml-auto text-gray-400 hover:text-red-500 transition-colors"
                                            x-on:click="remove(index, $refs.picker)"
                                            :aria-label="'Убрать ' + file.name"><i class="fas fa-xmark"></i></button>
                                </li>
                            </template>
                        </ul>
                    </template>
                    @error('files.*')<p class="text-red-500 text-xs mt-2">{{ $message }}</p>@enderror
                </div>

                <div class="mt-5 flex flex-col sm:flex-row gap-3">
                    <button type="submit" name="action" value="submit"
                            class="flex-1 bg-brand hover:bg-brand-hover text-white font-extrabold py-3.5 px-4 rounded-xl shadow-lg transition-all text-sm uppercase tracking-wider">
                        <i class="fas {{ $hwIsSubmitted || $hwIsRework ? 'fa-rotate' : 'fa-paper-plane' }} mr-2"></i>
                        {{ $hwIsSubmitted ? 'Обновить работу' : ($hwIsRework ? 'Отправить снова' : 'Отправить на проверку') }}
                    </button>
                    @unless($hwIsSubmitted)
                        <button type="submit" name="action" value="draft"
                                class="px-5 py-3.5 rounded-xl border border-gray-200 bg-white text-gray-600 font-bold text-sm hover:border-gray-300 transition-colors">
                            Сохранить черновик
                        </button>
                    @endunless
                </div>
            </form>
        @elseif($hwStatus === 'accepted')
            <div class="flex items-center gap-3 rounded-xl bg-emerald-50 border border-emerald-100 px-4 py-3">
                <i class="fas fa-circle-check text-emerald-500 shrink-0"></i>
                <p class="text-[13px] text-emerald-800"><span class="font-bold">Работа принята.</span> Поздравляем! 🎉</p>
            </div>
            @if(config('features.referral_loyalty_cta'))
                <div class="mt-4" data-testid="referral-loyalty-cta-homework">
                    @include('student.partials.referral')
                </div>
            @endif
        @endif

    </div>
</section>
