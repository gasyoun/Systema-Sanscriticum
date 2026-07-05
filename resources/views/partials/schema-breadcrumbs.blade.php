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
--}}
@php
    $__crumbs = collect($crumbs ?? [])
        ->filter(fn ($c) => !empty($c['name']))
        ->values();
@endphp
@if($__crumbs->isNotEmpty())
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $__crumbs->map(fn ($c, $i) => array_filter([
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => (string) $c['name'],
        'item' => $c['url'] ?? null,
    ], fn ($v) => $v !== null && $v !== ''))->all(),
]) !!}
</script>
@endif
