<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * H2215 — fixture-driven unit tests for public/js/paypal-claim-paste.js.
 * Runs the same pure parser Node loads (no browser, no PayPal network).
 */
final class PaypalClaimPasteParserTest extends TestCase
{
    private static function fixtureDir(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'paypal_claim_paste';
    }

    private static function parserJsPath(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'js'.DIRECTORY_SEPARATOR.'paypal-claim-paste.js';
    }

    /** @return array<string, mixed> */
    private static function loadExpected(): array
    {
        $path = self::fixtureDir().DIRECTORY_SEPARATOR.'expected.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Missing expected.json for paypal claim paste fixtures');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        $cases = [];
        foreach (array_keys(self::loadExpected()) as $file) {
            $cases[$file] = [$file];
        }

        return $cases;
    }

    #[DataProvider('fixtureProvider')]
    public function test_parser_matches_fixture_expectations(string $fixtureFile): void
    {
        if (! $this->nodeAvailable()) {
            $this->markTestSkipped('node is required to execute paypal-claim-paste.js fixtures');
        }

        $pastePath = self::fixtureDir().DIRECTORY_SEPARATOR.$fixtureFile;
        $this->assertFileExists($pastePath);
        $this->assertFileExists(self::parserJsPath());

        $paste = file_get_contents($pastePath);
        $this->assertNotFalse($paste);

        $parsed = $this->runParser($paste);
        $expected = self::loadExpected()[$fixtureFile];
        $this->assertIsArray($expected);

        foreach (['paypal_payer', 'paid_on', 'foreign_amount', 'foreign_currency', 'paypal_txn'] as $key) {
            if (! array_key_exists($key, $expected)) {
                continue;
            }
            $this->assertSame(
                $expected[$key],
                $parsed[$key] ?? null,
                "Field {$key} for fixture {$fixtureFile}"
            );
        }

        if (isset($expected['must_be_null']) && is_array($expected['must_be_null'])) {
            foreach ($expected['must_be_null'] as $key) {
                $this->assertTrue(
                    empty($parsed[$key]),
                    "Field {$key} must stay empty for partial fixture {$fixtureFile}, got: ".json_encode($parsed[$key] ?? null)
                );
            }
        }
    }

    public function test_empty_paste_invents_nothing(): void
    {
        if (! $this->nodeAvailable()) {
            $this->markTestSkipped('node is required to execute paypal-claim-paste.js fixtures');
        }

        $parsed = $this->runParser("   \n  ");
        $this->assertNull($parsed['paypal_payer'] ?? null);
        $this->assertNull($parsed['paid_on'] ?? null);
        $this->assertNull($parsed['foreign_amount'] ?? null);
        $this->assertNull($parsed['foreign_currency'] ?? null);
        $this->assertNull($parsed['paypal_txn'] ?? null);
        $this->assertSame([], $parsed['found'] ?? null);
    }

    public function test_does_not_treat_recipient_as_payer(): void
    {
        if (! $this->nodeAvailable()) {
            $this->markTestSkipped('node is required to execute paypal-claim-paste.js fixtures');
        }

        $text = "You sent \$20.00 USD to school@example.com\nDate: 2026-01-05\nTransaction ID: ABCDEFGHIJKLMNOP1\n";
        $parsed = $this->runParser($text);

        $this->assertNull($parsed['paypal_payer'] ?? null, 'To:/recipient must not become paypal_payer');
        $this->assertSame('20', $parsed['foreign_amount'] ?? null);
        $this->assertSame('USD', $parsed['foreign_currency'] ?? null);
        $this->assertSame('2026-01-05', $parsed['paid_on'] ?? null);
        $this->assertSame('ABCDEFGHIJKLMNOP1', $parsed['paypal_txn'] ?? null);
    }

    private function nodeAvailable(): bool
    {
        $out = [];
        $code = 1;
        @exec('node --version 2>&1', $out, $code);

        return $code === 0;
    }

    /** @return array<string, mixed> */
    private function runParser(string $paste): array
    {
        $jsPath = self::parserJsPath();
        $payload = json_encode(['paste' => $paste], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // Systema package.json is "type":"module" — load the browser UMD via vm.
        // Write a temp runner so Windows shell quoting cannot mangle the script.
        $jsPathJson = json_encode(str_replace('\\', '/', $jsPath), JSON_UNESCAPED_SLASHES);
        $runner = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paypal_claim_paste_runner_'.getmypid().'.cjs';
        $runnerSrc = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const jsPath = __JS_PATH__;
const code = fs.readFileSync(jsPath, 'utf8');
const sandbox = { module: { exports: {} }, exports: {}, console, globalThis: {} };
sandbox.module.exports = sandbox.exports;
sandbox.self = sandbox.globalThis;
vm.runInNewContext(code, sandbox, { filename: 'paypal-claim-paste.js' });
const parser = sandbox.module.exports && sandbox.module.exports.parse
  ? sandbox.module.exports
  : sandbox.globalThis.PaypalClaimPaste;
let raw = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (c) => { raw += c; });
process.stdin.on('end', () => {
  const input = JSON.parse(raw);
  process.stdout.write(JSON.stringify(parser.parse(input.paste || '')));
});
JS;
        $runnerSrc = str_replace('__JS_PATH__', $jsPathJson, $runnerSrc);
        file_put_contents($runner, $runnerSrc);

        try {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open(['node', $runner], $descriptors, $pipes, null, null);
            $this->assertIsResource($proc, 'Failed to start node parser process');

            fwrite($pipes[0], $payload);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            $this->assertSame(0, $code, "node parser failed: {$stderr}\nstdout={$stdout}");
            $this->assertNotFalse($stdout);
            $this->assertNotSame('', trim($stdout));

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } finally {
            if (is_file($runner)) {
                @unlink($runner);
            }
        }
    }
}
