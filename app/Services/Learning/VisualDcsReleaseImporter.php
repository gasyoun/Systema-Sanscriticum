<?php

declare(strict_types=1);

namespace App\Services\Learning;

use App\Models\VisualDcsRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * H2482 — pin a VisualDCS v1 learner-contract release locally.
 *
 * Never fetches VisualDCS main. The operator points at a directory that already
 * contains the H2481 manifest + payloads. Staging verifies version/hash/size,
 * then promotion swaps the live catalog atomically. A promoted tree is never
 * rewritten; rollback restores the previous promoted row.
 */
final class VisualDcsReleaseImporter
{
    /**
     * @return array{release: VisualDcsRelease, noop: bool}
     */
    public function import(string $sourceDir, bool $promote = true): array
    {
        $source = $this->absoluteDir($sourceDir);
        $manifest = $this->readManifest($source);
        $this->assertManifest($manifest, $source);

        $releaseId = (string) $manifest['releaseId'];
        $manifestHash = hash('sha256', $this->canonicalManifestBytes($manifest));

        $existing = VisualDcsRelease::query()->where('release_id', $releaseId)->first();
        if ($existing && $existing->manifest_hash === $manifestHash && $existing->status === VisualDcsRelease::STATUS_PROMOTED) {
            return ['release' => $existing, 'noop' => true];
        }

        $dest = $this->releasePath($releaseId);
        if (is_dir($dest)) {
            if (! $this->stagedMatches($dest, $manifest)) {
                throw new RuntimeException("Release directory already exists and must not be rewritten: {$dest}");
            }
        } else {
            $this->stageFiles($source, $dest, $manifest);
            $this->assertStaged($dest, $manifest);
        }

        $release = DB::transaction(function () use ($releaseId, $manifest, $manifestHash, $dest, $promote) {
            $release = VisualDcsRelease::query()->firstOrNew(['release_id' => $releaseId]);
            if ($release->exists && $release->status === VisualDcsRelease::STATUS_PROMOTED) {
                return $release;
            }

            $release->fill([
                'contract_version' => (string) $manifest['contractVersion'],
                'manifest_hash' => $manifestHash,
                'status' => VisualDcsRelease::STATUS_STAGED,
                'storage_path' => $dest,
            ]);
            $release->save();

            if ($promote) {
                $this->promote($release);
            }

            return $release->fresh();
        });

        return ['release' => $release, 'noop' => false];
    }

    public function promote(VisualDcsRelease $release): VisualDcsRelease
    {
        return DB::transaction(function () use ($release) {
            $previous = VisualDcsRelease::query()
                ->where('status', VisualDcsRelease::STATUS_PROMOTED)
                ->where('id', '!=', $release->id)
                ->lockForUpdate()
                ->get();

            foreach ($previous as $row) {
                $row->status = VisualDcsRelease::STATUS_ROLLED_BACK;
                $row->rolled_back_at = now();
                $row->save();
            }

            $release->status = VisualDcsRelease::STATUS_PROMOTED;
            $release->promoted_at = now();
            $release->rolled_back_at = null;
            $release->save();

            return $release->fresh();
        });
    }

    public function rollback(): ?VisualDcsRelease
    {
        return DB::transaction(function () {
            $current = VisualDcsRelease::query()
                ->where('status', VisualDcsRelease::STATUS_PROMOTED)
                ->lockForUpdate()
                ->first();
            if (! $current) {
                return null;
            }

            $previous = VisualDcsRelease::query()
                ->where('status', VisualDcsRelease::STATUS_ROLLED_BACK)
                ->where('id', '!=', $current->id)
                ->orderByDesc('promoted_at')
                ->lockForUpdate()
                ->first();
            if (! $previous) {
                throw new RuntimeException('No previous promoted release to restore.');
            }

            $current->status = VisualDcsRelease::STATUS_ROLLED_BACK;
            $current->rolled_back_at = now();
            $current->save();

            $previous->status = VisualDcsRelease::STATUS_PROMOTED;
            $previous->promoted_at = now();
            $previous->rolled_back_at = null;
            $previous->save();

            return $previous->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $source): array
    {
        $path = $this->findManifest($source);
        $raw = File::get($path);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('manifest.json is not valid JSON.');
        }

        return $decoded;
    }

    private function findManifest(string $source): string
    {
        foreach (['manifest.json', 'visual/contracts/v1/manifest.json'] as $rel) {
            $candidate = $source.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('manifest.json not found in '.$source);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertManifest(array $manifest, string $source): void
    {
        foreach (['contractVersion', 'releaseId', 'files', 'sha256', 'bytes', 'recordCount'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new RuntimeException("manifest missing required key: {$key}");
            }
        }

        $version = (string) $manifest['contractVersion'];
        $prefix = (string) config('visualdcs.contract_version_prefix', '1.');
        if (! str_starts_with($version, $prefix)) {
            throw new RuntimeException("Unsupported contractVersion {$version} (need {$prefix}*).");
        }

        $files = $manifest['files'];
        if (! is_array($files) || $files === []) {
            throw new RuntimeException('manifest.files must be a non-empty list.');
        }

        foreach ($files as $file) {
            $basename = basename((string) $file);
            $resolved = $this->resolvePayload($source, (string) $file);
            if (! is_file($resolved)) {
                throw new RuntimeException("Payload missing: {$file}");
            }

            $bytes = filesize($resolved);
            $expectedBytes = $manifest['bytes'][$basename] ?? $manifest['bytes'][$file] ?? null;
            if ($expectedBytes !== null && (int) $expectedBytes !== (int) $bytes) {
                throw new RuntimeException("Size mismatch for {$basename}: got {$bytes}, expected {$expectedBytes}.");
            }

            $hash = hash_file('sha256', $resolved);
            $expectedHash = $manifest['sha256'][$basename] ?? $manifest['sha256'][$file] ?? null;
            if (! is_string($expectedHash) || ! hash_equals($expectedHash, (string) $hash)) {
                throw new RuntimeException("SHA-256 mismatch for {$basename}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function stageFiles(string $source, string $dest, array $manifest): void
    {
        if (! is_dir($dest) && ! mkdir($dest, 0775, true) && ! is_dir($dest)) {
            throw new RuntimeException('Cannot create release directory '.$dest);
        }

        foreach ($manifest['files'] as $file) {
            $basename = basename((string) $file);
            $from = $this->resolvePayload($source, (string) $file);
            $to = $dest.DIRECTORY_SEPARATOR.$basename;
            if (! copy($from, $to)) {
                throw new RuntimeException("Failed to stage {$basename}");
            }
        }

        $manifestCopy = $manifest;
        $manifestCopy['files'] = array_map('basename', $manifest['files']);
        $sha = [];
        $bytes = [];
        foreach ($manifestCopy['files'] as $basename) {
            $sha[$basename] = $manifest['sha256'][$basename]
                ?? $manifest['sha256']['visual/contracts/v1/'.$basename]
                ?? null;
            $bytes[$basename] = $manifest['bytes'][$basename]
                ?? $manifest['bytes']['visual/contracts/v1/'.$basename]
                ?? null;
        }
        $manifestCopy['sha256'] = $sha;
        $manifestCopy['bytes'] = $bytes;
        File::put(
            $dest.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifestCopy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n"
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertStaged(string $dest, array $manifest): void
    {
        $this->assertManifest($this->readManifest($dest), $dest);
    }

    /**
     * True when dest already holds the same payload bytes as this manifest.
     * Lets tests (and a wiped-DB reimport) reuse the tree without rewriting it.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function stagedMatches(string $dest, array $manifest): bool
    {
        foreach ($manifest['files'] as $file) {
            $basename = basename((string) $file);
            $path = $dest.DIRECTORY_SEPARATOR.$basename;
            if (! is_file($path)) {
                return false;
            }
            $expected = $manifest['sha256'][$basename]
                ?? $manifest['sha256'][$file]
                ?? $manifest['sha256']['visual/contracts/v1/'.$basename]
                ?? null;
            if (! is_string($expected) || ! hash_equals($expected, (string) hash_file('sha256', $path))) {
                return false;
            }
        }

        return true;
    }

    private function resolvePayload(string $source, string $file): string
    {
        $rel = str_replace('/', DIRECTORY_SEPARATOR, $file);
        $direct = $source.DIRECTORY_SEPARATOR.$rel;
        if (is_file($direct)) {
            return $direct;
        }

        $flat = $source.DIRECTORY_SEPARATOR.basename($file);
        if (is_file($flat)) {
            return $flat;
        }

        return $direct;
    }

    private function releasePath(string $releaseId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $releaseId) ?: 'release';

        return storage_path('app/'.trim((string) config('visualdcs.storage_root', 'visualdcs'), '/').'/releases/'.$safe);
    }

    private function absoluteDir(string $sourceDir): string
    {
        $path = $sourceDir;
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:\\\\/', $path)) {
            $path = base_path($sourceDir);
        }
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Source directory not found: '.$sourceDir);
        }

        return $real;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function canonicalManifestBytes(array $manifest): string
    {
        return json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
