{{-- Предпросмотр документа в форме вехи (CertificateMilestonesRelationManager).
     Повторяет пропорции PDF-вёрстки certificates/default.blade.php в масштабе
     0.45 от A4 landscape @96dpi (1122×793). Печатает всё равно dompdf, поэтому
     это приблизительный экран: без QR, с ФИО-заглушкой и прочерками баллов.
     Инлайновые стили: Tailwind-классы в кастомных вьюхах Filament ненадёжны. --}}
@php
    $templates = \App\Models\Certificate::TEMPLATES;
    $template = $templates[$templateKey ?? 'gasuns'] ?? $templates['gasuns'];
    $isSpravka = ($documentType ?? \App\Models\CertificateMilestone::DOC_CERTIFICATE) === \App\Models\CertificateMilestone::DOC_SPRAVKA;

    // Та же цепочка фолбэков, что Certificate::backgroundPath(): у справки свой
    // фон, если файл существует; дальше обычный фон шаблона.
    $bg = $template['background'];
    if ($isSpravka && ! empty($template['spravka_background'])
        && is_file(public_path($template['spravka_background']))) {
        $bg = $template['spravka_background'];
    }

    $title = filled(trim((string) $certificateTitle))
        ? trim((string) $certificateTitle)
        : ($course->title ?? 'Название курса');
@endphp

<div style="margin-top:4px">
    <div style="position:relative;width:505px;max-width:100%;height:357px;overflow:hidden;border:1px solid #d8cfc0;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.08);background:#fff">
        <div style="position:absolute;top:0;left:0;width:1122px;height:793px;transform:scale(0.45);transform-origin:top left;font-family:'DejaVu Serif','Times New Roman',serif;color:#2c2c2c">
            <img src="{{ asset($bg) }}" alt="" style="position:absolute;top:0;left:0;width:1122px;height:793px">

            <div style="position:absolute;top:320px;left:110px;width:650px;font-size:28px;font-weight:bold;color:#000">Фамилия Имя</div>

            <div style="position:absolute;top:385px;left:110px;font-size:16px;color:#555;font-style:italic">
                {{ $isSpravka ? 'проходит обучение и освоил(а) часть онлайн-курса' : 'успешно освоил(а) онлайн-курс' }}
            </div>

            <div style="position:absolute;top:435px;left:110px;width:600px">
                <div style="font-size:26px;font-weight:bold;color:#000;line-height:1.1;margin-bottom:20px">{!! str_replace('|', '<br>', e($title)) !!}</div>

                @unless($isSpravka)
                    {{-- Для справки объём всего курса неверен — как и в PDF. --}}
                    <div style="font-size:16px;color:#333">
                        в объеме {{ $course->lessons_count ?? 12 }} уроков ({{ $course->hours_count ?? 24 }} академических часов)
                    </div>
                @endunless
            </div>

            @if($templateKey === 'sanka')
                {{-- Баллы известны только при выдаче: в превью прочерки. --}}
                <div style="position:absolute;top:575px;left:110px;width:470px;font-size:11px;color:#444">
                    <div style="margin-bottom:4px">
                        Выразительность <b>— / 20</b><span style="color:#bbb;margin:0 6px">·</span>Дикция <b>— / 5</b><span style="color:#bbb;margin:0 6px">·</span>Плавность <b>— / 5</b>
                    </div>
                    <div style="font-size:13px;color:#222">Итого: <b>— / 30</b></div>
                </div>
            @endif

            <div style="position:absolute;bottom:80px;right:81px;width:200px;text-align:right;font-size:10px;color:#666;line-height:1.3">
                <b>№ ГГГГ-XXXXX</b><br>
                Дата: дд.мм.гггг
            </div>
        </div>
    </div>

    <p style="margin:6px 0 0;font-size:12px;line-height:1.5;color:#6b7280;max-width:520px">
        Предпросмотр приблизительный — печатает PDF при выдаче: добавятся ФИО студента
        (из профиля, «Фамилия Имя»), реальный номер, дата и QR-код на страницу проверки.
        @if($templateKey === 'sanka')
            Блок баллов печатается только тем, у кого они есть в exam_scores.
        @endif
    </p>
</div>
