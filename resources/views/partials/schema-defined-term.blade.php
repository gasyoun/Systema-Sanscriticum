{{--
    DefinedTerm JSON-LD (schema.org) для словарной entity-страницы — SEO P2 / H204.

    Concrete semantic triplets (roadmap §5.1, decision D4):
      • слово — inLanguage → «sa»
      • слово — inDefinedTermSet → словарь (@id-спайн)
      • слово — sameAs → Wikidata/DBpedia (только если маппинг существует; иначе опускаем)

    json_encode без флагов экранирует «/» → «\/», поэтому вставка внутрь <script> безопасна.
    Ожидает: $primary (DictionaryWord), $sameAs (Collection<string>), $canonical (string).
--}}
@php
    $__dict = $primary->dictionary;
    $__definedTerm = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'DefinedTerm',
        '@id' => $canonical.'#term',
        'name' => $primary->headword(),
        'inLanguage' => 'sa',
        'description' => \Illuminate\Support\Str::limit(trim(strip_tags((string) $primary->translation)), 300),
        'url' => $canonical,
        'sameAs' => $sameAs->isNotEmpty() ? $sameAs->values()->all() : null,
        'inDefinedTermSet' => $__dict ? array_filter([
            '@type' => 'DefinedTermSet',
            '@id' => url('/slovar').'#dictset-'.$__dict->id,
            'name' => $__dict->name,
            'inLanguage' => 'sa',
        ], fn ($v) => $v !== null) : null,
        'publisher' => ['@id' => 'https://samskrte.ru/#org'],
    ], fn ($v) => $v !== null && $v !== '' && $v !== []);
@endphp
<script type="application/ld+json">
{!! json_encode($__definedTerm) !!}
</script>
