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

    // H344 — при включённом обогащении корпусные Sa→Ru значения дополняют
    // description; при выключенном ($enrichment === null) поведение как в Wave 0.
    $__enrichment = $enrichment ?? null;
    $__desc = trim(strip_tags((string) $primary->translation));
    if (! empty($__enrichment['glosses'])) {
        $__corpus = implode(', ', $__enrichment['glosses']);
        $__desc = $__desc !== ''
            ? $__desc.'; корпусные значения: '.$__corpus
            : 'Корпусные значения: '.$__corpus;
    }

    $__definedTerm = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'DefinedTerm',
        '@id' => $canonical.'#term',
        'name' => $primary->headword(),
        'inLanguage' => 'sa',
        'description' => \Illuminate\Support\Str::limit($__desc, 300),
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
