# H7875 — BreadcrumbList JSON-LD: подмена «@context» Blade-директивой

_Created: 09-11-2026 · Last updated: 09-11-2026_

## Симптом (живой прод, 08-11-2026)

`https://samskrte.ru/slovar/a` отдаёт два блока `application/ld+json`. DefinedTerm валиден,
а у BreadcrumbList ключ `@context` заменён PHP-исходником компилированного Blade:

```json
{"<?php $__contextArgs = []; if (context()->has($__contextArgs[0])) : ... ?>":"https://schema.org",
 "@type":"BreadcrumbList", ...}
```

Парсер видит JSON, но без `@context` граф крошек для schema.org непригоден. Частичный шаблон
`resources/views/partials/schema-breadcrumbs.blade.php` включён в 13+ вью (articles, shop, promo,
srs, teachers, slovar), то есть дефект был общесайтовым, а не словарным.

## Корневая причина

Laravel 13 объявляет нативную директиву `context(...)`
(`Illuminate\View\Compilers\Concerns\CompilesContexts`). `BladeCompiler::compileStatements()`
сканирует весь шаблон регуляркой `\B@(@?\w+...)` и не различает код и строковые литералы.
Литеральный ключ массива `'@context'` внутри сырого echo `{!! json_encode([...]) !!}`
матчился как директива, и `compileContext()` подставлял свой PHP-сниппет вместо ключа.

`@php ... @endphp`-блоки защищены `storePhpBlocks()` (raw blocks) и директивами не сканируются —
именно поэтому `partials/schema-defined-term.blade.php` и `main.blade.php`, где тот же ключ
лежит в PHP-блоке, отдавали корректный `@context`.

## Правка

Payload собирается в PHP-блоке до вывода, наружу уходит `{!! json_encode($__breadcrumbList) !!}`:

```
@php
    $__breadcrumbs = ...;
    $__breadcrumbList = [ '@context' => 'https://schema.org', ... ];
@endphp
<script type="application/ld+json">{!! json_encode($__breadcrumbList) !!}</script>
```

Содержимое массива (порядок ключей, `array_filter`, экранирование «/» → «\/») не менялось —
побайтно тот же JSON, кроме исправленного ключа.

**Осторожно с комментариями в шаблоне:** `storePhpBlocks()` вырезает блок до удаления
Blade-комментариев, поэтому упоминание `@php`/`@endphp` в тексте `{{-- --}}` сдвигает границы
raw-блока и ломает компиляцию (поймано на первой итерации правки). В комментарии H7875
директивы описаны словами, без токенов с собачкой.

## Доказательство (negative control)

До правки новый тест `tests/Feature/SchemaBreadcrumbsJsonLdTest.php` падал:

```
Failed asserting that '<!DOCTYPE html> ...' [UTF-8](length: 29243) does not contain "$__contextArgs"
Tests: 1, Assertions: 2, Failures: 1
```

Скомпилированный кэш до правки (`storage/framework/views/9679c26f…php`):

```php
<?php echo json_encode([
    '<?php $__contextArgs = [];
if (context()->has($__contextArgs[0])) :
...
$value = context()->get($__contextArgs[0]); ?>' => 'https://schema.org',
```

После правки:

```
php vendor/phpunit/phpunit/phpunit --filter "SchemaBreadcrumbsJsonLdTest|DictionaryPageTest"
OK (12 tests, 47 assertions)            exit 0

php vendor/phpunit/phpunit/phpunit --filter BreadcrumbsCoverageTest
OK (4 tests, 18 assertions)             exit 0
```

Окружение: `php artisan test` на этой машине падает до старта тестов
(`Class "ZipArchive" not found`, `config/backup.php:70`) и в дочерних процессах упирается в
`memory_limit=128M`; поэтому оба прогона сделаны прямым phpunit с явными расширениями:
`php -d extension=zip -d extension=pdo_sqlite -d extension=sqlite3 -d memory_limit=1G …`.

## Остаточный риск (вне scope H7875)

Тот же паттерн «литеральный `@context` в сыром echo» остался в
`resources/views/shop/partials/price-ladder-narrative.blade.php` (OfferCatalog) — там ключ
собирается массивом прямо в `{!! json_encode([...]) !!}`. Все остальные вхождения `@context`
в проекте лежат в PHP-блоках или записаны как `@@context` и безопасны.
