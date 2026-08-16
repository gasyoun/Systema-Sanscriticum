<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SamskrtamRelated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * H2918 — fetch each allowlist URL once and commit HTTP status into the lockfile.
 *
 *   php artisan seo:lock-samskrtam-related database/data/seo/samskrtam_related.json
 *   php artisan seo:lock-samskrtam-related database/data/seo/samskrtam_related.json --lock=database/data/seo/samskrtam_related.lock.json
 *
 * Runtime never calls this. Exit 1 if any URL is not HTTP 200 or leaves samskrtam.ru.
 */
class LockSamskrtamRelated extends Command
{
    protected $signature = 'seo:lock-samskrtam-related
        {json : Path to samskrtam_related.json (UTF-8, no BOM)}
        {--lock= : Output lock path (default: sibling .lock.json)}
        {--ca= : CA bundle path (Windows PHP often lacks the system store)}';

    protected $description = 'Lock samskrtam.ru related-reading URLs at HTTP 200';

    public function handle(): int
    {
        $jsonPath = (string) $this->argument('json');
        if (! is_file($jsonPath) || ! is_readable($jsonPath)) {
            $this->error("Allowlist not found or unreadable: {$jsonPath}");

            return self::FAILURE;
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            $this->error("Could not read allowlist: {$jsonPath}");

            return self::FAILURE;
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $this->error('Allowlist must be UTF-8 without BOM.');

            return self::FAILURE;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error('Allowlist is not a JSON object.');

            return self::FAILURE;
        }

        $errors = $this->validateSchema($decoded);
        $urls = SamskrtamRelated::uniqueUrls($jsonPath);
        if ($urls === []) {
            $errors[] = 'Allowlist has no URLs after a real parse — escalate, do not ship a hollow block.';
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $rows = [];
        $failed = false;
        foreach ($urls as $url) {
            $probe = $this->probe($url);
            $rows[] = $probe;
            $mark = $probe['status'] === 200 && $probe['host_ok'] ? '200' : 'FAIL';
            $this->line("{$mark}\t{$url}");
            if ($probe['status'] !== 200 || ! $probe['host_ok']) {
                $failed = true;
            }
        }

        if ($failed) {
            $this->error('One or more URLs are not HTTP 200 on samskrtam.ru. Lock not written.');

            return self::FAILURE;
        }

        $lockPath = (string) $this->option('lock');
        if ($lockPath === '') {
            $lockPath = preg_replace('/\.json$/', '.lock.json', $jsonPath) ?: $jsonPath.'.lock.json';
        }

        $payload = [
            'fetched_at' => now()->utc()->toIso8601String(),
            'urls' => array_map(static fn (array $row): array => [
                'url' => $row['url'],
                'status' => $row['status'],
                'fetched_at' => $row['fetched_at'],
            ], $rows),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->error('Could not encode lock JSON.');

            return self::FAILURE;
        }
        if (file_put_contents($lockPath, $json."\n") === false) {
            $this->error("Could not write lock: {$lockPath}");

            return self::FAILURE;
        }

        $this->info('Wrote '.count($rows).' lock row(s) to '.$lockPath);

        return self::SUCCESS;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<string>
     */
    private function validateSchema(array $decoded): array
    {
        $errors = [];
        $home = $decoded[SamskrtamRelated::HOME_KEY] ?? null;
        if (! is_array($home) || count($home) < 3) {
            $errors[] = '_home must have at least 3 entries.';
        }
        if (isset($decoded[SamskrtamRelated::DONATE_SLUG])) {
            $errors[] = 'Donate slug must not be a course key.';
        }
        foreach ($decoded as $key => $entries) {
            if (! is_string($key)) {
                $errors[] = 'Allowlist keys must be strings.';

                continue;
            }
            if (! is_array($entries)) {
                $errors[] = "Key {$key} is not a list.";

                continue;
            }
            foreach ($entries as $i => $entry) {
                if (! is_array($entry)) {
                    $errors[] = "{$key}[{$i}] is not an object.";

                    continue;
                }
                $url = trim((string) ($entry['url'] ?? ''));
                $anchor = trim((string) ($entry['anchor'] ?? ''));
                if ($url === '' || $anchor === '') {
                    $errors[] = "{$key}[{$i}] needs url and anchor.";

                    continue;
                }
                if (! str_starts_with($url, 'https://samskrtam.ru/')) {
                    $errors[] = "{$key}[{$i}] is not https://samskrtam.ru/: {$url}";
                }
            }
        }

        return $errors;
    }

    /**
     * @return array{url: string, status: int, fetched_at: string, host_ok: bool}
     */
    private function probe(string $url): array
    {
        $fetchedAt = now()->utc()->toIso8601String();
        $hostOk = $this->hostIsSamskrtam($url);
        if (! $hostOk) {
            return ['url' => $url, 'status' => 0, 'fetched_at' => $fetchedAt, 'host_ok' => false];
        }

        try {
            $options = ['allow_redirects' => ['max' => 1]];
            $ca = (string) $this->option('ca');
            if ($ca !== '') {
                $options['verify'] = $ca;
            }

            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Systema-SEO-H2-W2-lock/1.0'])
                ->withOptions($options)
                ->get($url);
            $effective = (string) $response->effectiveUri();
            $hostOk = $this->hostIsSamskrtam($effective !== '' ? $effective : $url);

            return [
                'url' => $url,
                'status' => $response->status(),
                'fetched_at' => $fetchedAt,
                'host_ok' => $hostOk,
            ];
        } catch (Throwable $e) {
            $this->warn($url.' — '.$e->getMessage());

            return ['url' => $url, 'status' => 0, 'fetched_at' => $fetchedAt, 'host_ok' => false];
        }
    }

    private function hostIsSamskrtam(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'samskrtam.ru' || $host === 'www.samskrtam.ru';
    }
}
