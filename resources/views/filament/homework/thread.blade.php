@php
    $submission = $getRecord();
    $comments = $submission->comments()->with('files', 'author')->get();
    $canManageFiles = \App\Filament\Resources\HomeworkSubmissionResource::canReview($submission);
    // H2142: другие уроки курса с ДЗ — цель переноса ошибочно залитого файла.
    $hwMoveTargets = \App\Models\Lesson::query()
        ->where('course_id', $submission->course_id)
        ->where('homework_enabled', true)
        ->where('id', '!=', $submission->lesson_id)
        ->orderBy('id')
        ->get(['id', 'title']);

    // H2455 follow-up: PDF картинок без кнопки — сразу в карточке проверки.
    $hwImagePdf = app(\App\Services\HomeworkImagePdfService::class);
    $hwHasImages = $hwImagePdf->studentImageFiles($submission)->isNotEmpty();
    if ($canManageFiles && $hwHasImages && ! $hwImagePdf->exists($submission)) {
        $hwImagePdf->rebuildQuietly($submission);
    }
    $hwImagesPdfReady = $canManageFiles && $hwHasImages && $hwImagePdf->exists($submission);
    $hwImagesPdfUrl = $hwImagesPdfReady
        ? route('homework.submission.images-pdf', $submission)
        : null;
    $hwImagesPdfDownloadUrl = $hwImagesPdfReady
        ? route('homework.submission.images-pdf', ['submission' => $submission, 'download' => 1])
        : null;
@endphp

<div class="space-y-4">
    @if($hwImagesPdfReady)
        <div class="rounded-xl border border-primary-200 dark:border-primary-500/30 bg-primary-50/60 dark:bg-primary-500/10 p-3 space-y-2">
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="font-semibold text-primary-800 dark:text-primary-200">
                    Все картинки — одним PDF (авто)
                </span>
                <a href="{{ $hwImagesPdfDownloadUrl }}"
                   class="text-xs font-semibold text-primary-700 dark:text-primary-300 underline underline-offset-2 hover:no-underline"
                   target="_blank" rel="noopener">
                    Скачать PDF
                </a>
            </div>
            <iframe
                src="{{ $hwImagesPdfUrl }}"
                title="Картинки домашней работы одним PDF"
                class="w-full rounded-lg border border-gray-200 dark:border-white/10 bg-white"
                style="min-height: 70vh; height: 70vh;"
            ></iframe>
        </div>
    @endif

    @forelse($comments as $c)
        @php
            $isStudent = $c->author_role === 'student';
            $who = $isStudent ? ($c->author->name ?? 'Студент') : 'Преподаватель';
        @endphp
        <div @class([
            'rounded-xl border p-4',
            'bg-gray-50 dark:bg-white/5 border-gray-200 dark:border-white/10' => $isStudent,
            'bg-primary-50 dark:bg-primary-500/10 border-primary-200 dark:border-primary-500/30' => ! $isStudent,
        ])>
            <div class="flex items-center gap-2 mb-1.5 text-xs">
                <span class="font-bold {{ $isStudent ? 'text-gray-700 dark:text-gray-200' : 'text-primary-700 dark:text-primary-300' }}">{{ $who }}</span>
                @if($c->type === 'review')
                    <span class="uppercase tracking-wide text-[10px] font-semibold text-gray-400">вердикт</span>
                @elseif($c->type === 'submission')
                    <span class="uppercase tracking-wide text-[10px] font-semibold text-gray-400">сдача</span>
                @elseif($c->type === 'message')
                    <span class="uppercase tracking-wide text-[10px] font-semibold text-gray-400">служебное</span>
                @endif
                <span class="text-gray-400">{{ $c->created_at->format('d.m.Y H:i') }}</span>
            </div>

            @if($c->body)
                <p class="text-sm text-gray-800 dark:text-gray-100 whitespace-pre-line leading-relaxed">{{ $c->body }}</p>
            @endif

            @if($c->files->isNotEmpty())
                <div class="mt-2.5 flex flex-wrap gap-2">
                    @foreach($c->files as $f)
                        <span class="inline-flex items-center gap-1 rounded-lg bg-white dark:bg-white/10 border border-gray-200 dark:border-white/10 text-xs font-semibold text-gray-700 dark:text-gray-200">
                            <a href="{{ route('homework.file.download', $f) }}"
                               class="inline-flex items-center gap-2 px-3 py-1.5 hover:text-primary-600 transition-colors">
                                <x-filament::icon :icon="$f->isImage() ? 'heroicon-o-photo' : 'heroicon-o-arrow-down-tray'" class="w-4 h-4" />
                                {{ \Illuminate\Support\Str::limit($f->original_name, 32) }}
                                <span class="text-gray-400">{{ $f->humanSize() }}</span>
                            </a>
                            @if($canManageFiles && $isStudent && $hwMoveTargets->isNotEmpty())
                                <form action="{{ route('homework.file.move', $f) }}" method="POST"
                                      class="inline-flex items-center gap-1 pr-1">
                                    @csrf
                                    <select name="lesson_id" required
                                            class="text-xs rounded-md border border-gray-200 dark:border-white/10 dark:bg-white/5 py-1 pl-1.5 pr-6 max-w-[11rem]"
                                            title="Перенести в урок"
                                            aria-label="Куда перенести {{ $f->original_name }}">
                                        <option value="">→ урок…</option>
                                        @foreach($hwMoveTargets as $mt)
                                            <option value="{{ $mt->id }}">{{ \Illuminate\Support\Str::limit($mt->title, 30) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="px-2 py-1.5 text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-500/10 rounded-md transition-colors"
                                            title="Перенести файл"
                                            aria-label="Перенести {{ $f->original_name }}">
                                        <x-filament::icon icon="heroicon-o-arrow-right-circle" class="w-4 h-4" />
                                    </button>
                                </form>
                            @endif
                            @if($canManageFiles)
                                <form action="{{ route('homework.file.destroy', $f) }}" method="POST" class="pr-1"
                                      onsubmit="return confirm(@js('Удалить файл «'.$f->original_name.'»?'));">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="px-2 py-1.5 text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-500/10 rounded-md transition-colors"
                                            title="Удалить файл"
                                            aria-label="Удалить {{ $f->original_name }}">
                                        <x-filament::icon icon="heroicon-o-trash" class="w-4 h-4" />
                                    </button>
                                </form>
                            @endif
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Пока нет сообщений.</p>
    @endforelse
</div>
