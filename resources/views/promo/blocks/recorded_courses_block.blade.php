@php
    $blockTitle    = $data['title']    ?? 'Курсы в записи';
    $blockSubtitle = $data['subtitle'] ?? 'Учитесь в своем темпе — записи лекций с пожизненным доступом.';
    $blockVariant  = $data['variant']  ?? 'light';
@endphp

<x-courses-recorded-mini
    :variant="$blockVariant"
    :courses="$recordedCoursesMini ?? null"
    :title="$blockTitle"
    :subtitle="$blockSubtitle"
    width="wide"
/>
