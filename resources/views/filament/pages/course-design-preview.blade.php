{{-- Превью загруженных баннеров курса (модалка «Картинки» на странице
     «Дизайн курсов»). Открывается кликом по строке или кнопкой в строке.
     Стили инлайновые и с префиксом cdp-: Tailwind-билд Filament не сканирует
     апп-вьюхи, рассчитывать на наличие утилитарных классов здесь нельзя. --}}
@php
    /** @var \App\Models\Course $course */
    /** @var list<string> $formats */
@endphp
<div class="cdp">
    @if($course->designAssets->isEmpty())
        <p class="cdp-empty">У этого курса пока не загружено ни одного баннера.</p>
    @else
        <div class="cdp-grid">
            @foreach($formats as $format)
                @php $asset = $course->designAssets->firstWhere('format', $format); @endphp
                <div class="cdp-card">
                    <div class="cdp-format">{{ $format }}</div>

                    @if($asset && filled($asset->path))
                        <a href="{{ $asset->imageUrl() }}" target="_blank" rel="noopener noreferrer"
                           title="Открыть в новой вкладке">
                            <img src="{{ $asset->imageUrl() }}" alt="{{ $asset->original_name }}" class="cdp-img">
                        </a>
                        <div class="cdp-meta">
                            <div><b>Файл:</b> {{ $asset->original_name }}
                                ({{ \App\Services\Design\CourseDesignAssetService::formatBytes((int) $asset->size) }})</div>
                            @if($asset->width && $asset->height)
                                <div>
                                    <b>Размер:</b> {{ $asset->width }}×{{ $asset->height }}
                                    @unless($asset->ratioMatches())
                                        <em class="cdp-warn">— пропорция не {{ $format }}</em>
                                    @endunless
                                </div>
                            @endif
                            <div><b>Загрузил:</b> {{ $asset->uploader?->name ?? '—' }}
                                · {{ $asset->updated_at?->format('d.m.Y H:i') }}</div>
                            <div>
                                <b>Исходник:</b>
                                @if($asset->hasPsd())
                                    @if(filled($asset->psd_path))
                                        <a href="{{ route('course-design.psd', $asset) }}" target="_blank" rel="noopener noreferrer">файл PSD</a>
                                    @endif
                                    @if(filled($asset->psd_path) && filled($asset->psd_url)) · @endif
                                    @if(filled($asset->psd_url))
                                        <a href="{{ $asset->psd_url }}" target="_blank" rel="noopener noreferrer">облако</a>
                                    @endif
                                @else
                                    <span class="cdp-warn">не учтён</span>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="cdp-placeholder">не загружено</div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
<style>
    .cdp { color: #111827; font-size: 0.875rem; }
    .cdp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
    .cdp-card { border: 1px solid #e5e7eb; border-radius: 0.75rem; padding: 0.75rem; background: #fff; }
    .cdp-format { font-weight: 700; font-size: 0.75rem; letter-spacing: 0.06em; text-transform: uppercase; color: #6b7280; margin-bottom: 0.5rem; }
    .cdp-img { display: block; width: 100%; height: auto; border-radius: 0.5rem; border: 1px solid #e5e7eb; background: #f9fafb; }
    .cdp-placeholder { border: 1px dashed #d1d5db; border-radius: 0.5rem; padding: 2.5rem 1rem; text-align: center; color: #9ca3af; }
    .cdp-meta { margin-top: 0.5rem; line-height: 1.55; }
    .cdp-meta b { color: #374151; }
    .cdp-warn { color: #b45309; }
    .cdp-empty { color: #6b7280; }
</style>
