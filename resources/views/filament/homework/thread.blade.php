@php
    $submission = $getRecord();
    $comments = $submission->comments()->with('files', 'author')->get();
    $canManageFiles = \App\Filament\Resources\HomeworkSubmissionResource::canReview($submission);
@endphp

<div class="space-y-4">
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
                            @if($canManageFiles)
                                <form action="{{ route('homework.file.destroy', $f) }}" method="POST" class="pr-1"
                                      onsubmit="return confirm('Удалить файл «{{ addslashes($f->original_name) }}»?');">
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
