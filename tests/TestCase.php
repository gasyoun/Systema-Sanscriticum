<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Views pull the bundle through @vite; without a built
        // public/build/manifest.json the directive throws
        // ManifestNotFoundException (→ 500), which locally turns every
        // view-rendering feature test into a false failure until the
        // frontend is built. Disable Vite for all tests — the standard
        // Laravel test stub — so the suite never depends on a build.
        $this->withoutVite();
    }

    /**
     * Percent-encode non-ASCII URI bytes before Symfony builds the request
     * (H7844, Y56 — триаж 4 локально-красных классов после H7632 / PR #3760).
     *
     * Symfony Request::create() routes the URI through PHP's parse_url(), and
     * PHP 8.5 substitutes C1-range bytes (0x80–0x9F) with '_' inside the URI.
     * On PHP 8.5.9 that corrupts raw UTF-8 Cyrillic BEFORE routing:
     * /online/prepodavatel/Иван-Петров became garbage and 404'd
     * (CatalogPrettyUrlsTest, VitrinaWaitlistPageTest), and ?q=гуна fed
     * mangled bytes into the search JSON — json_encode threw «Malformed
     * UTF-8» (GrammarLabImportSearchTest). PHP ≤ 8.4 parse_url() was
     * byte-transparent, which is why CI (PHP 8.3) stayed green while local
     * 8.5 runs were red. Real HTTP clients percent-encode non-ASCII on the
     * wire (RFC 3986 origin-form), so the encoded form is what production
     * actually receives; existing %XX escapes, ASCII and query structure
     * (%, &, =, +) pass through untouched, and on PHP ≤ 8.4 the decoded
     * path/query is byte-identical, so CI semantics do not change.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        return parent::call(
            $method,
            preg_replace_callback('/[\x80-\xff]/', fn (array $m): string => rawurlencode($m[0]), (string) $uri),
            $parameters,
            $cookies,
            $files,
            $server,
            $content,
        );
    }
}
