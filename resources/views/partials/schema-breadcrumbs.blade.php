{{--
    BreadcrumbList JSON-LD (schema.org) — общий партиал для страниц статьи, курса и лендинга.

    Использование:
        @include('partials.schema-breadcrumbs', ['crumbs' => [
            ['name' => 'Главная', 'url' => url('/')],
            ['name' => 'Статьи',  'url' => route('articles.index')],
            ['name' => $article->title],   // последний элемент — без url (текущая страница)
        ]])

    Помощь поисковикам (Яндекс + Google) строить «хлебные крошки» в выдаче.
    json_encode без флагов экранирует «/» → «\/», поэтому вставка внутрь <script> безопасна.

    H7875: payload собирается в PHP-блоке до вывода, а не массивом внутри сырого echo.
    Laravel 13 объявляет нативную Blade-директиву context(...) (CompilesContexts), и её
    регулярка ловит любой токен с собачкой в шаблоне — в том числе литеральный
    ключ-контекст в сыром echo: директива подменяла ключ своим PHP-исходником, и
    schema.org получал вместо "https://schema.org" строку с PHP-кодом (H7875).
    PHP-блок защищён storePhpBlocks() и директивами не сканируется — тот же приём,
    что в partials/schema-defined-term.blade.php и main.blade.php.
--}}
@php
    $__crumbs = collect($crumbs ?? [])
        ->filter(fn ($c) => !empty($c['name']))
        ->values();

    $__breadcrumbList = $__crumbs->isNotEmpty() ? [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $__crumbs->map(fn ($c, $i) => array_filter([
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => (string) $c['name'],
            'item' => $c['url'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''))->all(),
    ] : null;
@endphp
@if($__breadcrumbList !== null)
<script type="application/ld+json">
{!! json_encode($__breadcrumbList) !!}
</script>
@endif
