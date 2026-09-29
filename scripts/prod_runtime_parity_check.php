<?php

declare(strict_types=1);

use App\Jobs\TrackLessonViewJob;
use App\Services\Anons\PublicationManifest;
use App\Services\Support\MicShadowClassifier;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MessageClassifier\Loader as MicLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * H5060 — Production no-dev runtime parity gate.
 *
 * Plain-PHP script (NO PHPUnit — the no-dev profile has no dev packages,
 * including phpunit itself). Reproduces the production Composer profile:
 *
 *   composer install --no-dev → config:cache → boot → exercise the real
 *   JSON-backed MIC + anons loader seams with flags OFF and ON.
 *
 * Historical incidents pinned here:
 *  - PR #2577 / MIC H4847: a dev-only symfony/yaml (via laravel/sail) was
 *    read at runtime → every inbound support message died with
 *    "Class Symfony\Component\Yaml\Yaml not found". Production reads the
 *    JSON twins rules/v1/*.json (H4880-revert contract, PR #2648).
 *  - PR #2646 / #2648 (anons): manifests are JSON on prod; a YAML manifest
 *    in a no-dev environment must fail CLOSED with an actionable message,
 *    never silently degrade.
 *
 * Exit codes: 0 = pass, 1 = check failure, 2 = boot/dependency failure.
 * Failure output is actionable: the missing runtime class or unsupported
 * manifest format is named, with the composer-level cause.
 *
 * Run locally: php scripts/prod_runtime_parity_check.php
 * (with a dev vendor/ tree the script prints profile=dev and skips the
 * YAML-absence assertions; CI sets PARITY_REQUIRE_NODEV=1 to fail on that).
 *
 * H5095 — expanded runtime-entry matrix. The gate now also exercises one
 * representative seam per runtime entry surface that can load classes AFTER
 * the initial boot, selected from production code (all no-credentials,
 * no-network, deterministic):
 *
 *   Surface           | Selected seam                                | Rationale
 *   ------------------+----------------------------------------------+----------------------------------------------------
 *   HTTP route        | GET /sanskritorium                           | Public, flag-free page through routing → middleware →
 *                     | (TransliterateController)                    | controller → Blade; exercises the @vite manifest
 *                     |                                              | contract via a stub-manifest fixture (no node build).
 *   Queue worker      | TrackLessonViewJob on a temp-SQLite          | Real dispatch → payload serialization → worker pop →
 *                     | database queue + one queue:work pass         | container resolution → attempted handle(). handle()
 *                     |                                              | fails on the fixture DB BY DESIGN; the gate asserts
 *                     |                                              | the failure class is SQLSTATE (fixture), never
 *                     |                                              | "Class ... not found" (dev-only drift).
 *   Console/scheduler | artisan anons:validate + schedule:list       | Container-injected fail-closed validator runs over
 *                     |                                              | the section-4 JSON manifest; schedule:list resolves
 *                     |                                              | every scheduled command off the console kernel.
 *
 * Explicit exclusions (cannot boot deterministically without production
 * credentials/network, or belong to other gates): telegram/zoom/madelineproto
 * and openrouter commands and jobs, geo/ollama/n8n HTTP jobs, money/checkout
 * HTTP routes and DB-backed promo/article pages (migrated-DB feature tests),
 * backfill/audit commands shaped for a migrated production-like DB.
 */

require __DIR__.'/../vendor/autoload.php';

$failures = [];
$checks = [];

$fail = function (string $check, string $message) use (&$failures): void {
    $failures[] = [$check, $message];
    fwrite(STDERR, "PARITY FAIL [{$check}] {$message}\n");
    if (getenv('GITHUB_ACTIONS') !== false) {
        fwrite(STDERR, sprintf("::error title=prod-parity::%s\n", str_replace(["\r", "\n"], ' ', $message)));
    }
};

$pass = function (string $check, string $message) use (&$checks): void {
    $checks[] = $check;
    echo "PARITY PASS [{$check}] {$message}\n";
};

// ---------------------------------------------------------------------------
// 0. Profile detection (no-dev vs dev) BEFORE any boot.
// ---------------------------------------------------------------------------
$yamlPresent = class_exists(Yaml::class);
$phpunitPresent = class_exists(TestCase::class);
$noDev = ! $yamlPresent && ! $phpunitPresent;
$profile = $noDev ? 'no-dev' : 'dev';
echo 'PARITY profile='.$profile.($noDev ? '' : ' (yaml='.($yamlPresent ? 'present' : 'absent').', phpunit='.($phpunitPresent ? 'present' : 'absent').')')."\n";

if (! $noDev && getenv('PARITY_REQUIRE_NODEV') === '1') {
    fwrite(STDERR, "PARITY FAIL [profile] PARITY_REQUIRE_NODEV=1 but a dev profile was detected — the production job must run composer install --no-dev (symfony/yaml and phpunit must be ABSENT). A require-dev-only dependency has leaked into require, or the install step lost --no-dev.\n");
    echo "PARITY RESULT: FAIL (profile={$profile})\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. Cache configuration in a fresh process (production boot behavior),
//    then boot the application and verify it actually loaded cached config.
// ---------------------------------------------------------------------------
$artisan = __DIR__.'/../artisan';
$php = PHP_BINARY;
exec(sprintf('%s %s config:cache 2>&1', escapeshellarg($php), escapeshellarg($artisan)), $cacheOut, $cacheCode);
$configCached = is_file(__DIR__.'/../bootstrap/cache/config.php');
if ($cacheCode !== 0 || ! $configCached) {
    $fail('config:cache', 'php artisan config:cache failed (exit '.$cacheCode.'): '.implode(' | ', array_slice($cacheOut, -3)));
} else {
    $pass('config:cache', 'configuration cached via artisan (bootstrap/cache/config.php present)');
}

$app = null;
try {
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    $pass('boot', 'Laravel application booted under '.$profile.' profile (PHP '.PHP_VERSION.')');
} catch (Throwable $bootError) {
    // Actionable dependency diagnosis — the whole point of this gate.
    fwrite(STDERR, 'PARITY BOOT FAILURE: '.$bootError."\n");
    $msg = $bootError->getMessage();
    if (preg_match('/Class\s+"([^"]+)"\s+not found|Class \'([^\']+)\' not found/i', $msg, $m)) {
        $missing = $m[1] !== '' ? $m[1] : ($m[2] ?? $msg);
        fwrite(STDERR, "\nPARITY DIAGNOSIS (dependency): runtime references class [{$missing}] which is not installed.\n".
            "  Likely cause: a class used at runtime is provided by a require-dev-only package, or a package was removed\n".
            "  from require. Locate the owner: composer why-not <package> — then either move the package to require\n".
            "  (production) or remove the runtime usage. The no-dev production profile must boot without dev packages.\n");
    } elseif (preg_match("/loadClass\('([\w\\\\]+?)(\.\.\.)?'\)/", $msg.$bootError->getTraceAsString(), $m2)) {
        $missing = $m2[1];
        fwrite(STDERR, "\nPARITY DIAGNOSIS (dependency): boot needs class [{$missing}] whose package is NOT in the no-dev install\n".
            "  (composer's classmap points at a missing package file). A package used by a boot provider is require-dev-only\n".
            "  or was removed from require. Fix: move the package to composer.json require (production closure) or drop the\n".
            "  runtime usage; then composer update --no-dev. The no-dev production profile must boot without dev packages.\n");
    } else {
        fwrite(STDERR, "\nPARITY DIAGNOSIS: boot failed under the no-dev production profile — inspect the error above; the\n".
            "  production dependency closure (composer.json require) does not cover everything the app boot needs.\n");
    }
    exit(2);
}

if ($configCached && ! $app->configurationIsCached()) {
    $fail('config-loaded', 'bootstrapped application did not load the cached configuration (production boots with config:cache)');
} elseif ($configCached) {
    $pass('config-loaded', 'application booted against the CACHED configuration (production parity)');
}

// ---------------------------------------------------------------------------
// 2. MIC seam — flag OFF must be exactly zero work.
// ---------------------------------------------------------------------------
config(['features.mic_shadow_classify' => false]);
$off = MicShadowClassifier::instance();
if ($off !== null) {
    $fail('mic-off', 'features.mic_shadow_classify=false must yield a null MicShadowClassifier (zero work), got an instance.');
} else {
    $pass('mic-off', 'flag OFF → instance() returned null (zero classification work)');
}
if (class_exists(MicLoader::class, false)) {
    $fail('mic-off', 'flag OFF must NOT load the vendored MIC loader class, but MessageClassifier\Loader is already loaded.');
} else {
    $pass('mic-off', 'vendored MIC loader NOT loaded while flag is OFF');
}

// ---------------------------------------------------------------------------
// 3. MIC seam — flag ON must load the REAL JSON twins (never YAML) and
//    actually classify through the vendored loader.
// ---------------------------------------------------------------------------
config(['features.mic_shadow_classify' => true]);
try {
    $on = MicShadowClassifier::instance();
    if ($on === null) {
        $fail('mic-on', 'features.mic_shadow_classify=true must produce a classifier built from the JSON twins (instance() returned null — check tools/message-intent-classifier/rules/v1/*.json and php/MessageClassifier/Loader.php).');
    } else {
        $pass('mic-on', 'flag ON → classifier built from JSON twins via the vendored loader');
        foreach (MicLoader::PLANES as $plane) {
            $count = count($on->rulesFor($plane));
            if ($count === 0) {
                $fail('mic-on', "plane [{$plane}] has zero rules after loading JSON twins — rules/v1 document is missing or malformed.");
            }
        }
        $sample = MicLoader::normalizeText('  Когда ближайшее   занятие?  ');
        [$winner, $nearMiss] = $on->classifyPlaneWithNearMiss('topic', $sample);
        if (! is_array($nearMiss)) {
            $fail('mic-on', 'classifyPlaneWithNearMiss must return an array near-miss list, got '.gettype($nearMiss).'.');
        } else {
            $pass('mic-on', 'real classification ran through the JSON-backed seam (winner='.var_export($winner['category'] ?? null, true).', nearMiss='.count($nearMiss).')');
        }

        // JSON-at-runtime contract: the twins directory is the production source.
        $twins = glob(base_path('tools/message-intent-classifier/rules/v1/*.json')) ?: [];
        if ($twins === []) {
            $fail('json-contract', 'no rules/v1/*.json twins found — production must read JSON, not YAML (H4880-revert contract).');
        } else {
            $pass('json-contract', count($twins).' JSON rule twins present as the production rule source');
        }
    }
} catch (Throwable $micError) {
    $msg = $micError->getMessage();
    fwrite(STDERR, 'PARITY MIC FAILURE: '.$micError."\n");
    if (str_contains($msg, 'Symfony\Component\Yaml')) {
        $fail('mic-on', "YAML reached the MIC runtime path: {$msg}. Production reads JSON twins only (H4847/H4880-revert); a rule source or loader change reintroduced the dev-only symfony/yaml dependency.");
    } elseif (preg_match('/Class\s+"?([\w\\\\]+)"?\s+not found/i', $msg, $m)) {
        $fail('mic-on', "missing runtime class [{$m[1]}] in the MIC loader seam — the class is not in the no-dev closure. Move its package to require or revert to the vendored JSON loader.");
    } else {
        $fail('mic-on', "MIC JSON loader failed under no-dev: {$msg}");
    }
}

// ---------------------------------------------------------------------------
// 4. Anons seam — a real .json manifest loads, validates and hashes through
//    PublicationManifest (the production manifest format).
// ---------------------------------------------------------------------------
$tmpDir = sys_get_temp_dir().'/prod-parity-'.getmypid();
if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0700, true) && ! is_dir($tmpDir)) {
    fwrite(STDERR, "PARITY cannot create temp dir {$tmpDir}\n");
    exit(2);
}
$cleanup = static function () use ($tmpDir): void {
    foreach (glob($tmpDir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
};

try {
    $asset = $tmpDir.'/asset.png';
    file_put_contents($asset, (string) json_encode(['png-placeholder-bytes' => true]));
    $manifestJson = $tmpDir.'/manifest.json';
    file_put_contents($manifestJson, (string) json_encode([
        'version' => 1,
        'campaign' => 'm26',
        'creative' => 'parity-gate',
        'slot' => '2026-09-18T08:00',
        'frames' => [[
            'asset' => $asset,
            'caption' => 'Набор осенней группы: грамматика санскрита с нуля.',
            'alt_text' => 'Анонс осенней группы',
            'cta_text' => 'страница записи',
            'cta_url' => 'https://samskrte.ru/ga/m26-parity-20260918-01',
        ]],
        'destinations' => [['platform' => 'telegram_story', 'account' => 'rusamskrtam']],
        'deletion_policy' => 'retain',
    ], JSON_UNESCAPED_UNICODE));

    $manifest = PublicationManifest::fromFile($manifestJson);
    $errors = $manifest->validate();
    if ($errors !== []) {
        $fail('anons-json', 'representative JSON manifest failed fail-closed validation: '.implode(' | ', $errors));
    } else {
        $hash = $manifest->hash();
        if ($hash === '') {
            $fail('anons-json', 'manifest hash() returned an empty string.');
        } else {
            $pass('anons-json', 'representative JSON manifest loaded, validated (0 errors) and hashed through PublicationManifest (hash '.substr($hash, 0, 12).'…)');
        }
    }

    // 5. Anons YAML fail-closed contract — only meaningful without symfony/yaml.
    if (! $yamlPresent) {
        $manifestYaml = $tmpDir.'/manifest.yaml';
        file_put_contents($manifestYaml, "version: 1\ncampaign: m26\n");
        try {
            PublicationManifest::fromFile($manifestYaml);
            $fail('anons-yaml', 'a YAML manifest loaded in a no-dev environment — symfony/yaml leaked into the production closure or the fail-closed guard was removed (H4880-revert contract).');
        } catch (InvalidArgumentException $yamlError) {
            $msg = $yamlError->getMessage();
            if (str_contains($msg, 'symfony/yaml') && str_contains($msg, '.json')) {
                $pass('anons-yaml', 'YAML manifest without the package rejected fail-closed with the actionable diagnostic ("convert the manifest to .json")');
            } else {
                $fail('anons-yaml', "YAML rejection message lost its actionable diagnosis: {$msg}");
            }
        }
    } else {
        echo "PARITY SKIP [anons-yaml] symfony/yaml present (dev profile) — absence assertion meaningful only under no-dev.\n";
    }
} catch (Throwable $anonsError) {
    $msg = $anonsError->getMessage();
    if (preg_match('/Class\s+"?([\w\\\\]+)"?\s+not found/i', $msg, $m)) {
        $fail('anons-json', "missing runtime class [{$m[1]}] in the anons manifest seam — package not in the no-dev closure.");
    } else {
        $fail('anons-json', "anons manifest seam failed: {$msg}");
    }
}

// ---------------------------------------------------------------------------
// 5. Fixture DB — production boots against a MIGRATED database, and the
//    console/queue seams below genuinely read it (DB-backed settings load on
//    console boot; the queue job runs real queries inside handle()).
//    Recreate a fresh, disposable sqlite fixture at the cached-config path —
//    no credentials, no network (H5095).
// ---------------------------------------------------------------------------
try {
    $dbConfig = config('database.connections.'.config('database.default')) ?? [];
    if (($dbConfig['driver'] ?? null) !== 'sqlite') {
        $fail('fixture-db', 'the parity fixture expects the sqlite driver (the CI job seds DB_CONNECTION=sqlite), got '.($dbConfig['driver'] ?? 'null').'.');
    } else {
        $fixturePath = (string) $dbConfig['database'];
        if (is_file($fixturePath)) {
            @unlink($fixturePath);
        }
        if (! @touch($fixturePath)) {
            $fail('fixture-db', "cannot create the disposable sqlite fixture at [{$fixturePath}].");
        } else {
            $migrateExit = Artisan::call('migrate', ['--force' => true]);
            $migrateOut = trim(Artisan::output());
            if ($migrateExit !== 0) {
                $fail('fixture-db', 'php artisan migrate failed on the disposable sqlite fixture (exit '.$migrateExit.'): '.implode(' | ', array_slice(explode("\n", $migrateOut), -3)));
            } else {
                $pass('fixture-db', 'fresh migrated sqlite fixture ready at '.$fixturePath.' (console + queue seams run against real schema)');
            }
        }
    }
} catch (Throwable $fixtureError) {
    $msg = $fixtureError->getMessage();
    if (preg_match('/Class\s+"?([\w\\\\]+)"?\s+not found/i', $msg, $fm)) {
        $fail('fixture-db', "fixture migration hit missing runtime class [{$fm[1]}] — a migration or its dependency is require-dev-only (locate: composer why-not <package>).");
    } else {
        $fail('fixture-db', 'fixture DB preparation failed under no-dev: '.$msg);
    }
}

// ---------------------------------------------------------------------------
// 6. Console seam — run REAL artisan commands through the console kernel
//    (H5095): anons:validate re-runs the container-injected fail-closed
//    validator over the section-4 JSON manifest, and schedule:list resolves
//    every scheduled command off the console kernel.
// ---------------------------------------------------------------------------
try {
    $consoleExit = Artisan::call('anons:validate', ['manifest' => $manifestJson]);
    $consoleOut = Artisan::output();
    if ($consoleExit === 0 && str_contains($consoleOut, 'Manifest OK')) {
        $pass('console-anons-validate', 'artisan anons:validate ran the container-injected fail-closed validator over the JSON manifest (exit 0)');
    } else {
        $fail('console-anons-validate', "artisan anons:validate did not pass under no-dev (exit {$consoleExit}): ".implode(' | ', array_slice(explode("\n", trim($consoleOut)), -3)));
    }
} catch (Throwable $consoleError) {
    $msg = $consoleError->getMessage();
    if (preg_match('/Class\s+"?([\w\\\\]+)"?\s+not found/i', $msg, $m)) {
        $fail('console-anons-validate', "artisan command hit missing runtime class [{$m[1]}] at runtime — the class is require-dev-only (locate: composer why-not <package>) or the runtime usage must go. The no-dev production closure must run this console seam.");
    } else {
        $fail('console-anons-validate', "console seam failed under no-dev: {$msg}");
    }
}

try {
    $scheduleExit = Artisan::call('schedule:list');
    $scheduleOut = trim(Artisan::output());
    if ($scheduleExit === 0) {
        $scheduleEntries = count(array_filter(explode("\n", $scheduleOut)));
        $pass('console-schedule-list', "schedule:list resolved the console kernel schedule under no-dev ({$scheduleEntries} scheduled entries)");
    } else {
        $fail('console-schedule-list', "artisan schedule:list failed (exit {$scheduleExit}): ".implode(' | ', array_slice(explode("\n", $scheduleOut), -3)));
    }
} catch (Throwable $scheduleError) {
    $fail('console-schedule-list', 'scheduler seam failed under no-dev: '.$scheduleError->getMessage());
}

// ---------------------------------------------------------------------------
// 7. HTTP seam — route a real public GET (/sanskritorium) through the HTTP
//    kernel: routing → middleware → controller → Blade view chain, on the
//    cached config (H5095). Fixture: a stub Vite manifest (the parity job
//    never runs a node build, but the blade chain renders @vite entries);
//    an existing manifest is preserved byte-for-byte and restored after.
// ---------------------------------------------------------------------------
$viteManifest = public_path('build/manifest.json');
$viteBackup = is_file($viteManifest) ? (string) file_get_contents($viteManifest) : null;
if ($viteBackup === null) {
    @mkdir(dirname($viteManifest), 0777, true);
    file_put_contents($viteManifest, (string) json_encode([
        'resources/css/app.css' => ['file' => 'assets/app.css', 'src' => 'resources/css/app.css', 'isEntry' => true],
        'resources/js/app.js' => ['file' => 'assets/app.js', 'src' => 'resources/js/app.js', 'isEntry' => true],
        'resources/js/transliterate.js' => ['file' => 'assets/transliterate.js', 'src' => 'resources/js/transliterate.js', 'isEntry' => true],
    ]));
}
try {
    $httpKernel = $app->make(HttpKernel::class);
    $response = $httpKernel->handle(Request::create('/sanskritorium', 'GET'));
    $status = $response->getStatusCode();
    if ($status !== 200) {
        $fail('http-sanskritorium', "GET /sanskritorium returned HTTP {$status} under the no-dev profile — the public page seam must render (200) without dev packages.");
    } else {
        $pass('http-sanskritorium', 'GET /sanskritorium rendered through routing → middleware → controller → Blade (HTTP 200) under no-dev');
    }
} catch (Throwable $httpError) {
    $msg = $httpError->getMessage();
    if (preg_match('/Class\s+"?([\w\\\\]+)"?\s+not found/i', $msg, $m)) {
        $fail('http-sanskritorium', "HTTP seam hit missing runtime class [{$m[1]}] at runtime — the class is require-dev-only (locate: composer why-not <package>) or the runtime usage must go. The no-dev production closure must serve this public route.");
    } elseif (str_contains($msg, 'ManifestNotFoundException') || str_contains($msg, 'manifest')) {
        $fail('http-sanskritorium', "Vite manifest fixture problem at runtime: {$msg}");
    } else {
        $fail('http-sanskritorium', "HTTP seam failed under no-dev: {$msg}");
    }
} finally {
    if ($viteBackup === null) {
        @unlink($viteManifest);
    } else {
        file_put_contents($viteManifest, $viteBackup);
    }
}

// ---------------------------------------------------------------------------
// 8. Queue seam — dispatch a real queued job onto the migrated sqlite fixture
//    queue and run the REAL queue worker once: pop → payload decode →
//    container resolution → executed handle() (H5095). TrackLessonViewJob
//    pins itself to the "tracking" queue in its constructor; on the empty
//    fixture its handle() runs real user/lesson lookups that return no rows
//    and completes silently — a deterministic full worker pass. If anything
//    throws, the gate classifies it: fixture SQLSTATE is tolerated, a
//    "Class ... not found" (dev-only drift) is a hard fail.
// ---------------------------------------------------------------------------
try {
    config(['queue.default' => 'database']);

    $queueStarted = [];
    $queueCompleted = [];
    $queueExceptions = [];
    // getName() returns the wrapper ("...CallQueuedHandler@call"); the real
    // job class lives in the payload's data.commandName / displayName.
    $queueJobTag = static function ($event): string {
        $payload = $event->job->payload();

        return (string) ($payload['data']['commandName'] ?? $payload['displayName'] ?? $payload['job'] ?? '');
    };
    Queue::before(static function ($event) use (&$queueStarted, $queueJobTag): void {
        $queueStarted[] = $queueJobTag($event);
    });
    Queue::after(static function ($event) use (&$queueCompleted, $queueJobTag): void {
        $queueCompleted[] = $queueJobTag($event);
    });
    Queue::exceptionOccurred(static function ($event) use (&$queueExceptions): void {
        $queueExceptions[] = $event->exception->getMessage();
    });

    dispatch(new TrackLessonViewJob(999999, 999999, 999999));
    $pendingRow = DB::table('jobs')->first();
    if ($pendingRow === null) {
        $fail('queue-dispatch', 'dispatching TrackLessonViewJob left no pending row in the jobs table — the bus/queue payload serialization seam is broken.');
    } else {
        $pass('queue-dispatch', 'TrackLessonViewJob serialized and pushed onto the database queue "'.$pendingRow->queue.'" (payload '.strlen((string) $pendingRow->payload).' bytes)');
    }

    Artisan::call('queue:work', [
        '--once' => true,
        '--stop-when-empty' => true,
        '--queue' => (string) ($pendingRow->queue ?? 'tracking'),
        '--no-interaction' => true,
    ]);

    $remaining = DB::table('jobs')->count();
    if (! str_contains(implode('|', $queueStarted), 'TrackLessonViewJob')) {
        $fail('queue-worker', "the queue worker never started TrackLessonViewJob — the pop/payload-decode/resolution path did not run (remaining rows={$remaining}).");
    } elseif ($queueExceptions !== []) {
        $joined = implode(' | ', $queueExceptions);
        if (preg_match('/Class\s+"?([\w\\\\]+)"?\s+not found/i', $joined, $qm)) {
            $fail('queue-fixture', "queue seam hit missing runtime class [{$qm[1]}] at job runtime — the class is require-dev-only (locate: composer why-not <package>) or the runtime usage must go.");
        } elseif (! preg_match('/SQLSTATE|no such table|no such column/i', $joined)) {
            $fail('queue-fixture', 'queue seam failed with an unexpected error class (expected a clean pass or a fixture SQLSTATE error): '.$joined);
        } else {
            $pass('queue-fixture', 'worker attempted handle() on the fixture DB and failed with a fixture SQLSTATE error — failure class is fixture, never dev-only drift');
        }
    } elseif (! str_contains(implode('|', $queueCompleted), 'TrackLessonViewJob')) {
        $fail('queue-worker', 'worker started TrackLessonViewJob but it never completed and never threw — the probe is not meaningful.');
    } else {
        $pass('queue-worker', "queue worker popped → decoded → container-resolved → executed handle() to completion on the migrated fixture (real user/lesson lookups returned no rows; remaining rows={$remaining})");
    }
} catch (Throwable $queueError) {
    $fail('queue-worker', 'queue seam failed under no-dev: '.$queueError->getMessage());
}

// ---------------------------------------------------------------------------
// 9. Leave the tree clean: drop the cached config (CI container is ephemeral,
//    local dev suites must keep reading real env).
// ---------------------------------------------------------------------------
exec(sprintf('%s %s config:clear 2>&1', escapeshellarg($php), escapeshellarg($artisan)), $clearOut, $clearCode);
if ($clearCode !== 0) {
    echo 'PARITY note: config:clear exited '.$clearCode.' (harmless in CI, clean it locally: php artisan config:clear)'."\n";
}
$cleanup();

echo "\nPARITY SUMMARY: ".count($checks).' check(s) passed'.($failures === [] ? '' : ', '.count($failures).' FAILED')."\n";
if ($failures !== []) {
    foreach ($failures as [$check, $message]) {
        fwrite(STDERR, 'PARITY RESULT-FAIL ['.$check.'] '.$message."\n");
    }
    echo 'PARITY RESULT: FAIL (profile='.$profile.")\n";
    exit(1);
}

echo 'PARITY RESULT: PASS (profile='.$profile.")\n";
exit(0);
