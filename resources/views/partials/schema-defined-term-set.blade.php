{{--
    DefinedTermSet JSON-LD (schema.org) for the /slovar hub page — SEO P2 / H7876.

    Emits a DefinedTermSet that declares the dictionary collection to schema.org
    consumers. Each DefinedTerm on /slovar/{slug} references this set via
    inDefinedTermSet @id, so the graph is connected.

    H7876: payload assembled in a PHP block (protected from Blade directive
    compilation by storePhpBlocks), same pattern as schema-breadcrumbs.blade.php
    and schema-defined-term.blade.php.

    Expects: $dictionaries (Collection of Dictionary models with is_active=true).
--}}
@php
    $__sets = collect($dictionaries ?? [])
        ->filter(fn ($d) => $d->is_active)
        ->map(fn ($d) => array_filter([
            '@type' => 'DefinedTermSet',
            '@id' => url('/slovar') . '#dictset-' . $d->id,
            'name' => $d->name,
            'inLanguage' => 'sa',
            'url' => route('slovar.index', ['dict' => $d->id]),
        ], fn ($v) => $v !== null && $v !== ''))
        ->values()
        ->all();

    $__definedTermSet = ! empty($__sets) ? [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => 'Санскритско-русские словари',
        'inLanguage' => 'sa',
        'url' => route('slovar.index'),
        'itemListElement' => array_map(fn ($set, $i) => [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'item' => $set,
        ], $__sets, array_keys($__sets)),
    ] : null;
@endphp
@if($__definedTermSet !== null)
<script type="application/ld+json">
{!! json_encode($__definedTermSet) !!}
</script>
@endif
